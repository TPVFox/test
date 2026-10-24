<?php

/**
 * Las órdenes de la petición de fondo de las compras (`modulos/mod_compras/tareas.php`) propias de la
 * factura de proveedor: guardar lo que se escribe en su temporal (`tareas/addFacturaTemporal.php`) y
 * añadirle un importe pagado (`tareas/insertarImporte.php`).
 *
 * Cada petición se ejecuta en un PHP aparte, desde la carpeta del módulo, con la sesión del usuario de
 * pruebas, en una transacción sobre su conexión que nunca se confirma; lo que necesita se siembra en ella.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModCompras;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class TareasFacturaComprasIntegracionTest extends CasoIntegracion
{
    private int $idUsuario;

    private int $idTienda;

    protected function setUp(): void
    {
        parent::setUp();
        $siembra = new Siembra($this->db);
        $this->idUsuario = $siembra->usuarioPorDefecto();
        $this->idTienda = $siembra->tiendaPorDefecto();
    }

    /** @comportamiento Lo escrito en una factura nueva crea su temporal con el proveedor, su número de factura y las líneas, y responde los totales. */
    public function test_loEscritoEnUnaFacturaNuevaCreaSuTemporal(): void
    {
        $peticion = $this->ejecutar($this->temporal(['idTemporal' => '0', 'estado' => 'Nuevo']), $this->proveedorYArticulo());

        self::assertSame(10.4, $peticion['respuesta']['total']);
        self::assertStringContainsString('10.40', $peticion['respuesta']['htmlTabla']);
        self::assertSame((string) $peticion['respuesta']['id'], (string) $peticion['temporales'][0]['id']);
        self::assertSame(['Nuevo', 'F-7', 10.4, '0.40'], [$peticion['temporales'][0]['estadoFacPro'], $peticion['temporales'][0]['Su_num_factura'], (float) $peticion['temporales'][0]['total'], $peticion['temporales'][0]['total_ivas']]);
        self::assertSame('Pera de compra', json_decode($peticion['temporales'][0]['Productos'], true)[0]['cdetalle']);
    }

    /** @comportamiento Lo escrito en una factura guardada crea su temporal «Sin Guardar» relacionado con ella, y deja la factura «Sin Guardar» mientras se edita. */
    public function test_loEscritoEnUnaFacturaGuardadaLaDejaSinGuardar(): void
    {
        $peticion = $this->ejecutar($this->temporal(['idTemporal' => '0', 'estado' => 'Guardado', 'idReal' => ':fac1']), $this->facturaGuardada());

        self::assertSame(['Sin Guardar', $peticion['ids'][':fac1']], [$peticion['temporales'][0]['estadoFacPro'], $peticion['temporales'][0]['numfacpro']]);
        self::assertSame('Sin Guardar', $peticion['facturas'][0]['estado']);
    }

    /** @comportamiento Una fecha que no existe se responde como error y no crea el temporal. */
    public function test_unaFechaQueNoExisteNoCreaElTemporal(): void
    {
        $peticion = $this->ejecutar($this->temporal(['idTemporal' => '0', 'estado' => 'Nuevo', 'fecha' => '31-02-2026']), $this->proveedorYArticulo());

        self::assertStringContainsString('La fecha es erronea : 31-02-2026', json_encode($peticion['respuesta']['error'], JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame([], $peticion['temporales']);
    }

    /** @comportamiento Añadir un importe a una factura sin importes lo escribe en su temporal con lo que queda pendiente y responde su fila. */
    public function test_anadirUnImporteLoEscribeEnElTemporal(): void
    {
        $peticion = $this->ejecutar($this->importe('4'), array_merge($this->facturaGuardada(), $this->temporalDeLaFactura()));

        $importes = json_decode((string) $peticion['temporales'][0]['FacCobros'], true);
        self::assertSame([['4', '2026-02-10', 'T-1', 6.4]], array_map(static fn (array $i): array => [$i['importe'], $i['fecha'], $i['referencia'], $i['pendiente']], $importes));
        self::assertStringContainsString('<tr><td>4</td><td>2026-02-10</td>', $peticion['respuesta']['html']);
    }

    /** @comportamiento Un importe que, con los anteriores, supera el total de la factura no se añade y se responde el aviso. */
    public function test_unImporteQueSuperaElTotalNoSeAnade(): void
    {
        $preparacion = array_merge($this->facturaGuardada(), $this->temporalDeLaFactura("[{\"importe\":\"8\",\"fecha\":\"2026-02-09\",\"forma\":\"0\",\"referencia\":\"T-0\",\"pendiente\":\"2.40\"}]"));

        $peticion = $this->ejecutar($this->importe('4'), $preparacion);

        self::assertSame(1, $peticion['respuesta']['mensaje']);
        self::assertCount(1, json_decode((string) $peticion['temporales'][0]['FacCobros'], true));
    }

    /** @comportamiento Añadir un importe a una factura con importes guardados los pasa a su temporal, con el nuevo, y los quita de la factura. */
    public function test_anadirUnImporteConImportesGuardadosLosPasaAlTemporal(): void
    {
        $preparacion = array_merge($this->facturaGuardada(), $this->temporalDeLaFactura(), [
            "INSERT INTO formasPago (descripcion) VALUES ('Transferencia')",
            "INSERT INTO facProCobros (idFactura, idFormasPago, FechaPago, importe, Referencia) SELECT :fac1, MAX(id), '2026-02-09', 3, 'T-0' FROM formasPago",
        ]);

        $peticion = $this->ejecutar($this->importe('4'), $preparacion);

        self::assertSame(0, $peticion['cobros']);
        self::assertSame(['3', '4'], array_column(json_decode((string) $peticion['temporales'][0]['FacCobros'], true), 'importe'));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/tareas/addFacturaTemporal.php:124
     *
     * @group defecto
     * @group seguridad
     * @group medio
     *
     * @que-ocurre-hoy La respuesta de lo escrito en la factura lleva la sentencia con la que se guardan los
     *   totales del temporal.
     * @que-deberia-ocurrir Que la respuesta no enseñe sentencias de la base.
     * @por-que-ocurre La orden copia la sentencia en la respuesta.
     * @como-deberia-funcionar Quitarla de la respuesta.
     */
    public function test_laRespuestaNoEnsenaLaSentencia(): void
    {
        $peticion = $this->ejecutar($this->temporal(['idTemporal' => '0', 'estado' => 'Nuevo']), $this->proveedorYArticulo());

        self::assertArrayNotHasKey('sqlmodtotal', $peticion['respuesta']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/clases/facturasCompras.php:460-470
     *
     * @group defecto
     * @group seguridad
     * @group alto
     *
     * @que-ocurre-hoy Una referencia de pago con una comilla simple rompe la sentencia que guarda los importes
     *   del temporal: la petición termina en una excepción y el importe no se añade.
     * @que-deberia-ocurrir Que el importe se añada con su referencia tal como se escribió.
     * @por-que-ocurre Los importes se pegan a la sentencia entre comillas simples.
     * @como-deberia-funcionar Pasarlos como valor de la sentencia.
     */
    public function test_unaReferenciaConComillaSeGuarda(): void
    {
        $peticion = $this->ejecutar(['referencia' => "O'Neill"] + $this->importe('4'), array_merge($this->facturaGuardada(), $this->temporalDeLaFactura()));

        self::assertSame("O'Neill", json_decode((string) ($peticion['temporales'][0]['FacCobros'] ?? '[]'), true)[0]['referencia'] ?? null);
    }

    /** @return list<string> */
    private function proveedorYArticulo(): array
    {
        return [
            "INSERT INTO proveedores (nombrecomercial, razonsocial, nif, direccion, fecha_creado, estado) VALUES ('Frutas compra', 'Frutas compra SL', 'X1', 'Sin direccion', NOW(), 'Activo')",
            "INSERT INTO articulos (iva, articulo_name, estado, fecha_creado, ultimoCoste, tipo) VALUES (4, 'Pera de compra', 'Activo', NOW(), 2, 'unidad')",
        ];
    }

    /** @return list<string> */
    private function facturaGuardada(): array
    {
        return array_merge($this->proveedorYArticulo(), [
            "INSERT INTO facprot (Numfacpro, Su_num_factura, Fecha, idTienda, idUsuario, idProveedor, estado, total_siniva, total) VALUES (901, 'F-7', '2026-02-03', :tienda, :usuario, :p1, 'Guardado', 10, 10.4)",
        ]);
    }

    /**
     * El temporal de la factura sembrada, con los importes que se le den.
     *
     * @return list<string>
     */
    private function temporalDeLaFactura(string $importes = ''): array
    {
        return ["INSERT INTO facproltemporales (numfacpro, estadoFacPro, idTienda, idUsuario, Fecha, fechaInicio, idProveedor, total, Productos, Albaranes, Su_num_factura, FacCobros) VALUES (:fac1, 'Sin Guardar', :tienda, :usuario, '2026-02-04', NOW(), :p1, 10.4, '[]', '[]', 'F-7', '{$importes}')"];
    }

    /**
     * Lo que envía la pantalla al escribir en la factura: una línea de pera de 5 unidades a 2 € al 4 %.
     *
     * @param array<string, string> $cambios
     * @return array<string, string>
     */
    private function temporal(array $cambios): array
    {
        return $cambios + [
            'pulsado' => 'addFacturaTemporal', 'idUsuario' => ':usuario', 'idTienda' => ':tienda', 'idReal' => '0', 'fecha' => '04-02-2026', 'idProveedor' => ':p1', 'suNumero' => 'F-7',
            'productos' => (string) json_encode([['idArticulo' => ':a1', 'cref' => '1001', 'ccodbar' => '', 'ref_prov' => '', 'cdetalle' => 'Pera de compra', 'ncant' => '5', 'nunidades' => '5', 'ultimoCoste' => '2', 'iva' => '4', 'nfila' => 1, 'estado' => 'Activo']]),
        ];
    }

    /**
     * Lo que envía la pantalla al añadir un importe a la factura sembrada, de 10,40 € en total.
     *
     * @return array<string, string>
     */
    private function importe(string $importe): array
    {
        return ['pulsado' => 'insertarImporte', 'importe' => $importe, 'fecha' => '2026-02-10', 'idTemporal' => ':tfac1', 'forma' => '0', 'referencia' => 'T-1', 'total' => '10.40', 'idReal' => ':fac1'];
    }

    /**
     * Ejecuta la petición en un PHP aparte, en una transacción que no se confirma, y devuelve su respuesta,
     * lo que escribió, sus avisos y lo que quedó en la base de las facturas, sus importes y los temporales
     * del proveedor sembrado. Cada fila sembrada lleva su marcador —`:p1`, `:a1`, `:fac1`, `:tfac1`—.
     *
     * @param array<string, string> $formulario
     * @param list<string> $preparacion
     * @return array<string, mixed>
     */
    private function ejecutar(array $formulario, array $preparacion = []): array
    {
        $sesion = [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => (string) $this->idUsuario, 'group_id' => '1', 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => (string) $this->idTienda, 'razonsocial' => 'Tienda de pruebas SL', 'NombreComercial' => 'Tienda de pruebas'],
        ];
        $marcas = ['proveedores' => ':p', 'articulos' => ':a', 'facprot' => ':fac', 'facproltemporales' => ':tfac'];
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["DOCUMENT_ROOT"] = ' . var_export(dirname(RUTA_TPVFOX), true) . '; $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = "/TPVFox/modulos/mod_compras/tareas.php";'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_compras', true) . ');'
            . ' $_POST = ["pulsado" => ""]; require "./../../inicial.php";'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ' $ids = [":usuario" => "' . $this->idUsuario . '", ":tienda" => "' . $this->idTienda . '"]; $cuentas = []; $marcas = ' . var_export($marcas, true) . ';'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->query(strtr($sql, $ids)); $id = $BDTpv->insert_id;'
            . ' if (preg_match("/^INSERT INTO (\\\\w+) /", $sql, $t) && isset($marcas[$t[1]])) { $cuentas[$t[1]] = ($cuentas[$t[1]] ?? 0) + 1; $ids[$marcas[$t[1]] . $cuentas[$t[1]]] = (string) $id; } }'
            . ' $_POST = json_decode(strtr(' . var_export((string) json_encode($formulario), true) . ', $ids), true);'
            . ' $p = (int) ($ids[":p1"] ?? 0); $f = (int) ($ids[":fac1"] ?? 0);'
            . ' register_shutdown_function(function () use ($BDTpv, &$ids, $p, $f) { echo "\n@@DATOS@@" . json_encode(["ids" => $ids,'
            . ' "facturas" => $BDTpv->query("SELECT id, estado FROM facprot WHERE idProveedor = " . $p . " ORDER BY id")->fetch_all(MYSQLI_ASSOC),'
            . ' "cobros" => (int) $BDTpv->query("SELECT COUNT(*) FROM facProCobros WHERE idFactura = " . $f)->fetch_row()[0],'
            . ' "temporales" => $BDTpv->query("SELECT id, numfacpro, estadoFacPro, Su_num_factura, total, total_ivas, Productos, FacCobros FROM facproltemporales WHERE idProveedor = " . $p . " ORDER BY id")->fetch_all(MYSQLI_ASSOC)], JSON_INVALID_UTF8_SUBSTITUTE); });'
            . ' include "tareas.php";';

        $errores = (string) tempnam(sys_get_temp_dir(), 'factura');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -d error_log=/dev/null -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$escrito, $estado] = array_pad(explode('@@DATOS@@', implode("\n", $salida), 2), 2, 'null');
        $estado = (json_decode($estado, true) ?? []) + ['ids' => [], 'facturas' => [], 'cobros' => 0, 'temporales' => []];
        $ejecucion = ['respuesta' => json_decode(trim($escrito), true), 'escrito' => $escrito, 'errores' => (string) file_get_contents($errores)] + $estado;
        unlink($errores);

        return $ejecucion;
    }
}
