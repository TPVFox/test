<?php

/**
 * Las órdenes de la petición de fondo de las compras (`modulos/mod_compras/tareas.php`) propias del albarán
 * de proveedor: guardar lo que se escribe en su temporal (`tareas/addAlbaranTemporal.php`), exportarlo a
 * XML (`tareas/exportarAlbaran.php`) e importar el albarán de cierre del año anterior como el de apertura
 * del actual (`tareas/importarAlbaranCierreAno.php`).
 *
 * Cada petición se ejecuta en un PHP aparte, desde la carpeta del módulo, con la sesión del usuario de
 * pruebas, en una transacción sobre su conexión que nunca se confirma; lo que necesita se siembra en ella.
 * Los ficheros se escriben y se leen en una carpeta del caso, que se borra al terminar. El fichero que se
 * importa lo deja el caso en esa carpeta, donde lo dejaría la subida.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModCompras;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class TareasAlbaranComprasIntegracionTest extends CasoIntegracion
{
    private int $idUsuario;

    private int $idTienda;

    private string $carpeta;

    protected function setUp(): void
    {
        parent::setUp();
        $siembra = new Siembra($this->db);
        $this->idUsuario = $siembra->usuarioPorDefecto();
        $this->idTienda = $siembra->tiendaPorDefecto();
        $this->carpeta = sys_get_temp_dir() . '/albaran-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->carpeta, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->carpeta . '/*') ?: [] as $fichero) {
            unlink($fichero);
        }
        rmdir($this->carpeta);
        parent::tearDown();
    }

    /** @comportamiento Lo escrito en un albarán nuevo crea su temporal con el proveedor, su número, la fecha y las líneas, y responde los totales. */
    public function test_loEscritoEnUnAlbaranNuevoCreaSuTemporal(): void
    {
        $peticion = $this->ejecutar($this->temporal(['idTemporal' => '0', 'estado' => 'Nuevo']), $this->proveedorYArticulo());

        self::assertSame(10.4, $peticion['respuesta']['total']);
        self::assertStringContainsString('10.40', $peticion['respuesta']['htmlTabla']);
        self::assertSame((string) $peticion['respuesta']['id'], (string) $peticion['temporales'][0]['id']);
        self::assertSame(['A-7', 'Nuevo', '2026-02-04 00:00:00', 10.4, '0.40'], [$peticion['temporales'][0]['Su_numero'], $peticion['temporales'][0]['estadoAlbPro'], $peticion['temporales'][0]['Fecha'], (float) $peticion['temporales'][0]['total'], $peticion['temporales'][0]['total_ivas']]);
        self::assertSame('Pera de compra', json_decode($peticion['temporales'][0]['Productos'], true)[0]['cdetalle']);
    }

    /** @comportamiento Lo escrito en un temporal abierto lo modifica: el número del proveedor, la fecha con su hora, las líneas y los totales. */
    public function test_loEscritoEnUnTemporalAbiertoLoModifica(): void
    {
        $preparacion = array_merge($this->proveedorYArticulo(), [
            "INSERT INTO albproltemporales (Su_numero, estadoAlbPro, idTienda, idUsuario, Fecha, fechaInicio, idProveedor, total, Productos, Pedidos) VALUES ('A-1', 'Sin Guardar', :tienda, :usuario, '2026-02-01', NOW(), :p1, 0, '[]', '[]')",
        ]);

        $peticion = $this->ejecutar($this->temporal(['idTemporal' => ':talb1', 'estado' => 'Sin Guardar', 'hora' => '09:30', 'suNumero' => 'A-9']), $preparacion);

        self::assertSame($peticion['ids'][':talb1'], (string) $peticion['respuesta']['id']);
        self::assertCount(1, $peticion['temporales']);
        self::assertSame(['A-9', '2026-02-04 09:30:00', 10.4], [$peticion['temporales'][0]['Su_numero'], $peticion['temporales'][0]['Fecha'], (float) $peticion['temporales'][0]['total']]);
    }

    /**
     * @comportamiento Lo escrito en un albarán guardado crea su temporal «Sin Guardar» con el número del
     *   albarán, y deja el albarán guardado también «Sin Guardar» mientras se edita.
     */
    public function test_loEscritoEnUnAlbaranGuardadoLoDejaSinGuardar(): void
    {
        $preparacion = array_merge($this->proveedorYArticulo(), [
            "INSERT INTO albprot (Numalbpro, Su_numero, Fecha, idTienda, idUsuario, idProveedor, estado, total_siniva, total) VALUES (901, 'A-7', '2026-02-03', :tienda, :usuario, :p1, 'Guardado', 10, 10.4)",
        ]);

        $peticion = $this->ejecutar($this->temporal(['idTemporal' => '0', 'estado' => 'Guardado', 'idReal' => ':alb1']), $preparacion);

        self::assertSame(['Sin Guardar', $peticion['ids'][':alb1']], [$peticion['temporales'][0]['estadoAlbPro'], $peticion['temporales'][0]['Numalbpro']]);
        self::assertSame('Sin Guardar', $peticion['albaranes'][0]['estado']);
    }

    /** @comportamiento Una fecha que no existe se responde como error y no crea el temporal. */
    public function test_unaFechaQueNoExisteNoCreaElTemporal(): void
    {
        $peticion = $this->ejecutar($this->temporal(['idTemporal' => '0', 'estado' => 'Nuevo', 'fecha' => '31-02-2026']), $this->proveedorYArticulo());

        self::assertStringContainsString('La fecha es erronea : 31-02-2026', json_encode($peticion['respuesta']['error'], JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame([], $peticion['temporales']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/clases/albaranesCompras.php:39-42, :63-67
     *
     * @group defecto
     * @group seguridad
     * @group alto
     *
     * @que-ocurre-hoy Un número del proveedor con comillas dobles rompe la sentencia que crea o modifica el
     *   temporal: la petición termina con una excepción de la base sin capturar y el temporal no se crea.
     * @que-deberia-ocurrir Que el temporal se cree con el número tal como se escribió.
     * @por-que-ocurre El número del proveedor se pega en la sentencia sin escapar; las líneas sí se escapan.
     * @como-deberia-funcionar Pasar el número como valor de la sentencia.
     */
    public function test_unNumeroDelProveedorConComillasSeGuardaEnElTemporal(): void
    {
        $peticion = $this->ejecutar($this->temporal(['idTemporal' => '0', 'estado' => 'Nuevo', 'suNumero' => 'A"7']), $this->proveedorYArticulo());

        self::assertSame('A"7', $peticion['temporales'][0]['Su_numero'] ?? null);
    }

    /**
     * @comportamiento Exportar un albarán guardado escribe su XML en la carpeta temporal —con su número del
     *   proveedor y sus líneas, conforme al esquema—, responde su ruta y lo deja «Exportado».
     */
    public function test_exportarUnAlbaranGuardado(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'datosExportarXML', 'id' => ':alb1', 'dedonde' => 'albaran', 'idTienda' => ':tienda'], $this->albaranGuardado('Guardado'));
        $fichero = $this->carpeta . '/albaran_compra_' . $peticion['ids'][':alb1'] . '.xml';

        self::assertStringEndsWith('/albaran_compra_' . $peticion['ids'][':alb1'] . '.xml', (string) $peticion['respuesta']);
        self::assertFileExists($fichero);
        $xml = simplexml_load_file($fichero);
        self::assertSame('A-7', (string) $xml->Cabecera->SuNumero);
        self::assertSame('Pera de compra', (string) $xml->Lineas->Linea->Descripcion);
        self::assertSame('10.40', (string) $xml->Totales->TotalDocumento);
        self::assertSame('Exportado', $peticion['albaranes'][0]['estado']);
    }

    /** @comportamiento Exportar un albarán facturado escribe su XML y lo deja «Facturado». */
    public function test_exportarUnAlbaranFacturadoNoCambiaSuEstado(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'datosExportarXML', 'id' => ':alb1', 'dedonde' => 'albaran', 'idTienda' => ':tienda'], $this->albaranGuardado('Facturado'));

        self::assertFileExists($this->carpeta . '/albaran_compra_' . $peticion['ids'][':alb1'] . '.xml');
        self::assertSame('Facturado', $peticion['albaranes'][0]['estado']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/tareas/exportarAlbaran.php:49-51
     *
     * @group defecto
     * @group robustez
     * @group medio
     *
     * @que-ocurre-hoy Un albarán que no se puede exportar —una línea al IVA del cero, que el esquema no
     *   admite— termina la petición con una excepción sin capturar: no hay respuesta ni aviso.
     * @que-deberia-ocurrir Que la petición responda que el albarán no se ha exportado y por qué.
     * @por-que-ocurre La exportación no captura la excepción de la validación.
     * @como-deberia-funcionar Capturarla y responderla como error.
     */
    public function test_unAlbaranQueNoSeExportaSeResponde(): void
    {
        $preparacion = str_replace([', 4, 1, \'Activo\'', '(:alb1, 901, 4, 0.4, 10)'], [', 0, 1, \'Activo\'', '(:alb1, 901, 0, 0, 10)'], $this->albaranGuardado('Guardado'));

        $peticion = $this->ejecutar(['pulsado' => 'datosExportarXML', 'id' => ':alb1', 'dedonde' => 'albaran', 'idTienda' => ':tienda'], $preparacion);

        self::assertSame('Guardado', $peticion['albaranes'][0]['estado']);
        self::assertNotNull($peticion['respuesta']);
    }

    /** @comportamiento Sin fichero, importar el albarán de cierre responde el error, sin crear nada. */
    public function test_importarSinFicheroRespondeElError(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'importarAlbaranCierreAno'], $this->proveedorYArticulo());

        self::assertSame(['ok' => false, 'message' => "No se ha subido ningún archivo válido con el input 'inputAlbaranCierreAno'.", 'code' => 'IMPORT_XML_ERROR'], $peticion['respuesta']);
        self::assertSame([], $peticion['albaranes']);
    }

    /**
     * @comportamiento Importar el albarán de cierre crea el de apertura: «Importado», a 1 de enero, con «#A»
     *   en su número del proveedor, sus líneas en positivo y su desglose, y suma sus unidades a las existencias.
     */
    public function test_importarElAlbaranDeCierreCreaElDeApertura(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'importarAlbaranCierreAno'], $this->proveedorYArticulo(), $this->cierre('P#C7'));

        self::assertSame(['ok' => true, 'message' => 'Albarán importado correctamente'], $peticion['respuesta']);
        self::assertCount(1, $peticion['albaranes']);
        self::assertSame(['P#A7', 'Importado', date('Y') . '-01-01 00:00:00', 10.0, 10.4], [$peticion['albaranes'][0]['Su_numero'], $peticion['albaranes'][0]['estado'], $peticion['albaranes'][0]['Fecha'], (float) $peticion['albaranes'][0]['total_siniva'], (float) $peticion['albaranes'][0]['total']]);
        self::assertSame([['APERT. Pera de compra', '5.000000', '5.000000']], $peticion['lineas']);
        self::assertSame(1, $peticion['desgloses']);
        self::assertEqualsWithDelta(5.0, $peticion['existencias'] - $peticion['existenciasAntes'], 0.0001);
    }

    /** @comportamiento Un albarán de apertura con el mismo número del proveedor no se importa dos veces. */
    public function test_unAlbaranDeAperturaRepetidoNoSeImporta(): void
    {
        $preparacion = array_merge($this->proveedorYArticulo(), [
            "INSERT INTO albprot (Numalbpro, Su_numero, Fecha, idTienda, idUsuario, idProveedor, estado, total_siniva, total) VALUES (901, 'P#A7', '2026-01-01', :tienda, :usuario, :p1, 'Importado', 10, 10.4)",
        ]);

        $peticion = $this->ejecutar(['pulsado' => 'importarAlbaranCierreAno'], $preparacion, $this->cierre('P#C7'));

        self::assertSame('Ya existe un albarán de apertura con el mismo número para este proveedor.', $peticion['respuesta']['message']);
        self::assertCount(1, $peticion['albaranes']);
    }

    /**
     * @comportamiento Un albarán de cierre que no se puede guardar —un número del proveedor más largo de lo
     *   que admite la base— responde el error de la base y no crea nada.
     */
    public function test_unAlbaranQueNoSeGuardaRespondeElError(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'importarAlbaranCierreAno'], $this->proveedorYArticulo(), $this->cierre('P#C' . str_repeat('7', 30)));

        self::assertSame(['ok' => false, 'message' => "Data too long for column 'Su_numero' at row 1", 'code' => 'IMPORT_XML_ERROR'], $peticion['respuesta']);
        self::assertSame([], $peticion['albaranes']);
    }

    /** @return list<string> */
    private function proveedorYArticulo(): array
    {
        return [
            "INSERT INTO proveedores (nombrecomercial, razonsocial, nif, direccion, fecha_creado, estado) VALUES ('Frutas compra', 'Frutas compra SL', 'X1', 'Sin direccion', NOW(), 'Activo')",
            "INSERT INTO articulos (iva, articulo_name, estado, fecha_creado, ultimoCoste, tipo) VALUES (4, 'Pera de compra', 'Activo', NOW(), 2, 'unidad')",
        ];
    }

    /**
     * Un proveedor con un albarán —el 901, con una línea de pera de 10 € al 4 %— y su desglose.
     *
     * @return list<string>
     */
    private function albaranGuardado(string $estado): array
    {
        return array_merge($this->proveedorYArticulo(), [
            "INSERT INTO albprot (Numalbpro, Su_numero, Fecha, idTienda, idUsuario, idProveedor, estado, total_siniva, total, FechaVencimiento) VALUES (901, 'A-7', '2026-02-03 10:00:00', :tienda, :usuario, :p1, '{$estado}', 10, 10.4, '0000-00-00')",
            "INSERT INTO albprolinea (idalbpro, Numalbpro, idArticulo, cref, ccodbar, cdetalle, ncant, nunidades, costeSiva, iva, nfila, estadoLinea, ref_prov, idpedpro) VALUES (:alb1, 901, :a1, '1001', '', 'Pera de compra', 5, 5, 2, 4, 1, 'Activo', '', 0)",
            "INSERT INTO albproIva (idalbpro, Numalbpro, iva, importeIva, totalbase) VALUES (:alb1, 901, 4, 0.4, 10)",
        ]);
    }

    /**
     * Lo que envía la pantalla al escribir en el albarán: una línea de pera de 5 unidades a 2 € al 4 %.
     *
     * @param array<string, string> $cambios
     * @return array<string, string>
     */
    private function temporal(array $cambios): array
    {
        return $cambios + [
            'pulsado' => 'addAlbaranTemporal', 'idUsuario' => ':usuario', 'idTienda' => ':tienda', 'idReal' => '0', 'fecha' => '04-02-2026', 'hora' => '',
            'idProveedor' => ':p1', 'suNumero' => 'A-7',
            'productos' => (string) json_encode([['idArticulo' => ':a1', 'cref' => '1001', 'ccodbar' => '', 'cdetalle' => 'Pera de compra', 'ncant' => '5', 'nunidades' => '5', 'ultimoCoste' => '2', 'iva' => '4', 'nfila' => 1, 'estado' => 'Activo']]),
        ];
    }

    /** El albarán de cierre del proveedor sembrado: 5 peras a 2 € al 4 %, en negativo. */
    private function cierre(string $suNumero): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><AlbaranCompra idOrigen="' . $suNumero . '"><Meta><Version>1.0</Version><Tipo>COMPRA</Tipo><FechaExportacion>2025-12-31T20:00:00+01:00</FechaExportacion></Meta>'
            . '<Cabecera><Numero>2025#C1</Numero><SuNumero>' . $suNumero . '</SuNumero><Fecha>2025-12-31T20:00:00+01:00</Fecha><IdTienda>:tienda</IdTienda><IdProveedor>:p1</IdProveedor><Estado>Guardado</Estado></Cabecera>'
            . '<Lineas><Linea idInterno="1"><IdArticulo>:a1</IdArticulo><Descripcion>CIERRE Pera de compra</Descripcion><Cantidad>-5.000</Cantidad><Unidades>-5.000</Unidades><PrecioUnitario>2.0000</PrecioUnitario><IVA>4.00</IVA><Fila>1</Fila></Linea></Lineas>'
            . '<Totales><DesgloseIVA><Tipo>4.00</Tipo><Base>-10.00</Base><Cuota>-0.40</Cuota><Total>-10.40</Total></DesgloseIVA><TotalIVA>-0.40</TotalIVA><TotalDocumento>-10.40</TotalDocumento></Totales></AlbaranCompra>';
    }

    /**
     * Ejecuta la petición en un PHP aparte, en una transacción que no se confirma, y devuelve su respuesta,
     * lo que escribió, sus avisos y lo que quedó en la base del proveedor y el artículo sembrados.
     *
     * Los marcadores `:usuario` y `:tienda` son los del caso; cada fila sembrada lleva el suyo —`:p1`, `:a1`,
     * `:alb1`, `:talb1`—, que el formulario y el fichero que se importa pueden usar. El fichero se deja en la
     * carpeta del caso como `cierre.xml`.
     *
     * @param array<string, string> $formulario
     * @param list<string> $preparacion
     * @return array<string, mixed>
     */
    private function ejecutar(array $formulario, array $preparacion = [], ?string $importado = null): array
    {
        $sesion = [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => (string) $this->idUsuario, 'group_id' => '1', 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => (string) $this->idTienda, 'razonsocial' => 'Tienda de pruebas SL', 'NombreComercial' => 'Tienda de pruebas'],
        ];
        $marcas = ['proveedores' => ':p', 'articulos' => ':a', 'albprot' => ':alb', 'albproltemporales' => ':talb'];
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["DOCUMENT_ROOT"] = ' . var_export(dirname(RUTA_TPVFOX), true) . '; $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = "/TPVFox/modulos/mod_compras/tareas.php";'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_compras', true) . ');'
            . ' $_POST = ["pulsado" => ""]; require "./../../inicial.php";'
            . ' $rutatmp = str_repeat("/..", substr_count(rtrim($RutaServidor, "/"), "/")) . ' . var_export($this->carpeta, true) . ';'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ' $ids = [":usuario" => "' . $this->idUsuario . '", ":tienda" => "' . $this->idTienda . '"]; $cuentas = []; $marcas = ' . var_export($marcas, true) . ';'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->query(strtr($sql, $ids)); $id = $BDTpv->insert_id;'
            . ' if (preg_match("/^INSERT INTO (\\\\w+) /", $sql, $t) && isset($marcas[$t[1]])) { $cuentas[$t[1]] = ($cuentas[$t[1]] ?? 0) + 1; $ids[$marcas[$t[1]] . $cuentas[$t[1]]] = (string) $id; } }'
            . ' $_POST = json_decode(strtr(' . var_export((string) json_encode($formulario), true) . ', $ids), true);'
            . ($importado === null ? '' : ' file_put_contents(' . var_export($this->carpeta . '/cierre.xml', true) . ', strtr(' . var_export($importado, true) . ', $ids));'
                . ' $_FILES = ["inputAlbaranCierreAno" => ["name" => "cierre.xml", "type" => "text/xml", "tmp_name" => ' . var_export($this->carpeta . '/subida.tmp', true) . ', "error" => UPLOAD_ERR_OK, "size" => 1]];')
            . ' $p = (int) ($ids[":p1"] ?? 0); $a = (int) ($ids[":a1"] ?? 0); $tienda = ' . $this->idTienda . ';'
            . ' $existencias = function () use ($BDTpv, $a, $tienda) { return (float) ($BDTpv->query("SELECT stockOn FROM articulosStocks WHERE idArticulo = " . $a . " AND idTienda = " . $tienda)->fetch_row()[0] ?? 0); };'
            . ' $antes = $existencias();'
            . ' register_shutdown_function(function () use ($BDTpv, &$ids, $p, $existencias, $antes) { echo "\n@@DATOS@@" . json_encode(["ids" => $ids,'
            . ' "albaranes" => $BDTpv->query("SELECT id, Su_numero, estado, Fecha, total_siniva, total FROM albprot WHERE idProveedor = " . $p . " ORDER BY id")->fetch_all(MYSQLI_ASSOC),'
            . ' "lineas" => $BDTpv->query("SELECT l.cdetalle, l.ncant, l.nunidades FROM albprolinea l JOIN albprot c ON c.id = l.idalbpro WHERE c.idProveedor = " . $p . " ORDER BY l.id")->fetch_all(),'
            . ' "desgloses" => (int) $BDTpv->query("SELECT COUNT(*) FROM albproIva i JOIN albprot c ON c.id = i.idalbpro WHERE c.idProveedor = " . $p)->fetch_row()[0],'
            . ' "temporales" => $BDTpv->query("SELECT id, Numalbpro, Su_numero, estadoAlbPro, Fecha, total, total_ivas, Productos FROM albproltemporales WHERE idProveedor = " . $p . " ORDER BY id")->fetch_all(MYSQLI_ASSOC),'
            . ' "existenciasAntes" => $antes, "existencias" => $existencias()], JSON_INVALID_UTF8_SUBSTITUTE); });'
            . ' include "tareas.php";';

        $errores = (string) tempnam(sys_get_temp_dir(), 'albaran');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -d error_log=/dev/null -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$escrito, $estado] = array_pad(explode('@@DATOS@@', implode("\n", $salida), 2), 2, 'null');
        $estado = (json_decode($estado, true) ?? []) + ['ids' => [], 'albaranes' => [], 'lineas' => [], 'desgloses' => 0, 'temporales' => [], 'existenciasAntes' => 0.0, 'existencias' => 0.0];
        $ejecucion = ['respuesta' => json_decode(trim($escrito), true), 'escrito' => $escrito, 'errores' => (string) file_get_contents($errores)] + $estado;
        unlink($errores);

        return $ejecucion;
    }
}
