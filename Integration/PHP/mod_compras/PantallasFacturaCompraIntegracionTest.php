<?php

/**
 * Las pantallas de la factura de proveedor: la de la factura (`modulos/mod_compras/factura.php`) —nueva,
 * una guardada con su navegación, su temporal con sus albaranes y su guardado— y el listado
 * (`facturasListado.php`) —las facturas, sus temporales abiertos y sus opciones según los permisos—.
 *
 * Cada pantalla se pinta en un PHP aparte, desde la carpeta del módulo, con la sesión del usuario de
 * pruebas —su número como texto, como lo deja el inicio de sesión— o sin ninguna, y los permisos del
 * módulo concedidos salvo los que el caso quite, en una transacción sobre su conexión que nunca se
 * confirma; lo que necesita se siembra en ella, con un nombre (`:p1`, `:a1`, `:fac1`, `:alb1`, `:tfac1`).
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModCompras;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class PantallasFacturaCompraIntegracionTest extends CasoIntegracion
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

    /** @comportamiento Sin nada en la dirección, la factura es nueva y editable, sin proveedor, con el usuario de la sesión. */
    public function test_unaFacturaNueva(): void
    {
        $pagina = $this->pintar('factura.php', []);

        self::assertStringContainsString('Factura De Proveedor', $pagina['html']);
        self::assertStringContainsString("cabecera['estado'] = 'Nuevo';", $pagina['html']);
        self::assertStringContainsString("cabecera['idProveedor'] = '';", $pagina['html']);
        self::assertStringContainsString("cabecera['idUsuario'] = " . $this->idUsuario . ';', $pagina['html']);
        self::assertStringContainsString('name="Guardar" id="bGuardar"', $pagina['html']);
    }

    /**
     * @comportamiento Una factura guardada se ve con su proveedor, su número de factura, sus líneas en solo
     *   lectura y sus totales, y los botones de ir a la anterior y a la siguiente, de todas y del proveedor.
     */
    public function test_unaFacturaGuardadaSeVe(): void
    {
        $pagina = $this->pintar('factura.php', ['id' => ':fac1', 'accion' => 'ver'], $this->facturaGuardada());

        self::assertStringContainsString("cabecera['estado'] = 'Guardado';", $pagina['html']);
        self::assertStringContainsString("cabecera['suNumero'] = 'F-7';", $pagina['html']);
        self::assertStringContainsString('value="Frutas compra"', $pagina['html']);
        self::assertStringContainsString('Pera de compra', $pagina['html']);
        self::assertStringContainsString('10.40', $pagina['html']);
        self::assertStringContainsString(' Global ', $pagina['html']);
        self::assertStringContainsString(' Proveedor ', $pagina['html']);
        self::assertStringNotContainsString('eliminarFila(', $pagina['html']);
    }

    /** @comportamiento Una factura guardada con un temporal abierto se abre en su temporal, con sus albaranes como adjuntos. */
    public function test_unaFacturaConTemporalSeAbreEnSuTemporal(): void
    {
        $preparacion = array_merge($this->facturaGuardada(), [$this->albaran(), $this->temporal(':fac1', true)]);

        $pagina = $this->pintar('factura.php', ['id' => ':fac1', 'accion' => 'editar'], $preparacion);

        self::assertStringContainsString("cabecera['idTemporal'] = '" . $pagina['ids'][':tfac1'] . "';", $pagina['html']);
        self::assertStringContainsString('Pera del temporal', $pagina['html']);
        self::assertStringContainsString('albaranes.push(datos);', $pagina['html']);
        self::assertStringContainsString('"Su_numero":"A-31"', $pagina['html']);
    }

    /** @comportamiento Un parámetro de más en la dirección se avisa; sin sesión, la pantalla no se pinta. */
    public function test_unParametroDeMasYSinSesion(): void
    {
        $deMas = $this->pintar('factura.php', ['id' => ':fac1', 'otro' => '1'], $this->facturaGuardada());
        $sinSesion = $this->pintar('factura.php', [], [], false);

        self::assertStringContainsString('Hay parametro get,distinto a tActual,accion,id', $deMas['html']);
        self::assertStringNotContainsString('Factura De Proveedor', $sinSesion['html']);
    }

    /** @comportamiento Guardar desde la pantalla guarda la factura de su temporal y quita el temporal. */
    public function test_guardarDesdeLaPantalla(): void
    {
        $preparacion = [$this->proveedor(), $this->articulo(), $this->temporal(null, false)];

        $pagina = $this->pintar('factura.php', ['tActual' => ':tfac1'], $preparacion, true, [], ['Guardar' => 'Guardar', 'fecha' => '04-02-2026', 'suNumero' => 'F-8', 'formaVenci' => '', 'fechaVenci' => '']);

        self::assertSame([['F-8', 'Guardado', '5.20']], $pagina['facturas']);
        self::assertSame(0, $pagina['temporales']);
    }

    /**
     * @comportamiento El listado enseña las facturas con su número, su fecha, su proveedor, su base, su IVA,
     *   su total y su estado, y los temporales abiertos; las opciones generales y las de cada factura según
     *   los permisos, e imprimir salvo las que están sin guardar.
     */
    public function test_elListadoDeFacturas(): void
    {
        $preparacion = array_merge($this->facturaGuardada(), [$this->temporal(null, false)]);

        $todos = $this->pintar('facturasListado.php', ['buscar' => 'Frutas compra'], $preparacion);
        $sinPermisos = $this->pintar('facturasListado.php', ['buscar' => 'Frutas compra'], $preparacion, true, ['Crear', 'Modificar']);

        self::assertMatchesRegularExpression('#<td>901</td>\s*<td>2026-02-03</td>\s*<td>Frutas compra</td>\s*<td>10\.00</td>\s*<td>0\.40</td>\s*<td>10\.40</td>#', $todos['html']);
        self::assertStringContainsString("redireccionA('factura.php?tActual=" . $todos['ids'][':tfac1'] . "')", $todos['html']);
        self::assertStringContainsString('href="./factura.php" accesskey="A"', $todos['html']);
        self::assertStringContainsString('class="glyphicon glyphicon-pencil" href="./factura.php?id=' . $todos['ids'][':fac1'] . '&accion=editar"', $todos['html']);
        self::assertStringContainsString("onclick='imprimir(" . $todos['ids'][':fac1'], $todos['html']);
        self::assertStringNotContainsString('href="./factura.php" accesskey="A"', $sinPermisos['html']);
        self::assertStringNotContainsString('glyphicon-pencil', $sinPermisos['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/factura.php:20-140
     *
     * @group defecto
     * @group seguridad
     * @group medio
     *
     * @que-ocurre-hoy Sin el permiso de modificar facturas, pedir editar una guardada por su dirección la
     *   enseña editable.
     * @que-deberia-ocurrir Que sin el permiso se enseñe para ver, como el albarán.
     * @por-que-ocurre La pantalla no consulta el permiso de modificar; solo el listado esconde el lápiz.
     * @como-deberia-funcionar Pasar a «ver» si no hay permiso de modificar.
     */
    public function test_sinPermisoDeModificarLaFacturaSeVe(): void
    {
        $pagina = $this->pintar('factura.php', ['id' => ':fac1', 'accion' => 'editar'], $this->facturaGuardada(), true, ['Modificar']);

        self::assertStringContainsString('Pera de compra', $pagina['html']);
        self::assertStringNotContainsString('id="bGuardar"', $pagina['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/factura.php:343, :549
     *
     * @group defecto
     * @group seguridad
     * @group alto
     *
     * @que-ocurre-hoy El número de factura del proveedor se pinta sin escapar en la cabecera del JavaScript y
     *   en su caja: una comilla simple cierra la cadena y lo que sigue se ejecuta.
     * @que-deberia-ocurrir Que se pinte como texto.
     * @por-que-ocurre Se escribe tal cual en la página.
     * @como-deberia-funcionar Escaparlo según donde se pinte.
     */
    public function test_elNumeroDeFacturaSePintaComoTexto(): void
    {
        $preparacion = $this->facturaGuardada();
        $preparacion[2] = str_replace("'F-7'", "'F'';alert(1);'''", $preparacion[2]);

        $pagina = $this->pintar('factura.php', ['id' => ':fac1', 'accion' => 'ver'], $preparacion);

        self::assertStringNotContainsString("cabecera['suNumero'] = 'F';alert(1);'';", $pagina['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/facturasListado.php:133, :219
     *
     * @group defecto
     * @group seguridad
     * @group alto
     *
     * @que-ocurre-hoy El nombre del proveedor se pinta sin escapar en el listado de facturas y en el de sus
     *   temporales.
     * @que-deberia-ocurrir Que se pinte como texto.
     * @por-que-ocurre Se escribe tal cual.
     * @como-deberia-funcionar Escaparlo al pintarlo.
     */
    public function test_elProveedorSePintaComoTextoEnElListado(): void
    {
        $preparacion = $this->facturaGuardada();
        $preparacion[0] = str_replace("'Frutas compra'", "'Frutas <b>compra</b>'", $preparacion[0]);

        $pagina = $this->pintar('facturasListado.php', ['buscar' => 'Frutas'], $preparacion);

        self::assertStringNotContainsString('<td>Frutas <b>compra</b></td>', $pagina['html']);
    }

    /** @comportamiento Un listado sin facturas que cumplan la búsqueda lo avisa. */
    public function test_unListadoSinFacturasLoAvisa(): void
    {
        $pagina = $this->pintar('facturasListado.php', ['buscar' => 'Proveedor que no existe']);

        self::assertStringContainsString('No tienes facturas guardados!', $pagina['html']);
    }

    /** @comportamiento Una factura nueva que llega desde los albaranes de un proveedor deja al JavaScript el proveedor y los albaranes con que empezar. */
    public function test_unaFacturaDesdeLosAlbaranesDeUnProveedor(): void
    {
        $pagina = $this->pintar('factura.php', [], [$this->proveedor(), $this->albaran()], true, [], ['action' => 'crearDesdeAlbaranes', 'idProveedor' => ':p1', 'albaranes' => [':alb1']]);

        self::assertStringContainsString('window.crearDesdeAlbaranes = true;', $pagina['html']);
        self::assertStringContainsString('window.proveedorInicial = ' . $pagina['ids'][':p1'] . ';', $pagina['html']);
        self::assertStringContainsString('window.albaranesIniciales = ["' . $pagina['ids'][':alb1'] . '"];', $pagina['html']);
    }

    private function proveedor(): string
    {
        return "INSERT INTO proveedores (nombrecomercial, razonsocial, nif, direccion, fecha_creado, estado) VALUES ('Frutas compra', 'Frutas compra SL', 'X1', 'Sin direccion', NOW(), 'Activo')";
    }

    private function articulo(): string
    {
        return "INSERT INTO articulos (iva, articulo_name, estado, fecha_creado, ultimoCoste, tipo) VALUES (4, 'Pera de compra', 'Activo', NOW(), 2, 'unidad')";
    }

    private function albaran(): string
    {
        return "INSERT INTO albprot (Numalbpro, Su_numero, Fecha, idTienda, idUsuario, idProveedor, estado, total_siniva, total) VALUES (31, 'A-31', '2026-02-02', :tienda, :usuario, :p1, 'Facturado', 5, 5.2)";
    }

    /**
     * Un proveedor con una factura —la 901, con una línea de pera de 10 € al 4 %— y su desglose.
     *
     * @return list<string>
     */
    private function facturaGuardada(): array
    {
        return [
            $this->proveedor(),
            $this->articulo(),
            "INSERT INTO facprot (Numfacpro, Su_num_factura, Fecha, idTienda, idUsuario, idProveedor, estado, total_siniva, total) VALUES (901, 'F-7', '2026-02-03 10:00:00', :tienda, :usuario, :p1, 'Guardado', 10, 10.4)",
            "INSERT INTO facprolinea (idfacpro, Numfacpro, idArticulo, cref, ccodbar, cdetalle, ncant, nunidades, costeSiva, iva, nfila, estadoLinea, ref_prov, idalbpro) VALUES (:fac1, 901, :a1, '1001', '', 'Pera de compra', 5, 5, 2, 4, 1, 'Activo', '', 0)",
            "INSERT INTO facproIva (idfacpro, Numfacpro, iva, importeIva, totalbase) VALUES (:fac1, 901, 4, 0.4, 10)",
        ];
    }

    /** Un temporal del proveedor sembrado, de una factura guardada o nueva, con una línea de pera de 5,20 € y, si se pide, el albarán sembrado. */
    private function temporal(?string $factura, bool $conAlbaran): string
    {
        $productos = "CONCAT('[{\"idArticulo\":\"', :a1, '\",\"cref\":\"1001\",\"ccodbar\":\"\",\"ref_prov\":\"\",\"cdetalle\":\"Pera del temporal\",\"ncant\":\"1\",\"nunidades\":\"1\",\"ultimoCoste\":\"5\",\"iva\":\"4\",\"nfila\":1,\"estado\":\"Activo\"}]')";
        $albaranes = $conAlbaran ? "CONCAT('[{\"idAdjunto\":\"', :alb1, '\",\"NumAdjunto\":\"31\",\"estado\":\"activo\"}]')" : "'[]'";

        return 'INSERT INTO facproltemporales (numfacpro, estadoFacPro, idTienda, idUsuario, Fecha, fechaInicio, idProveedor, total, Productos, Albaranes, Su_num_factura) SELECT '
            . ($factura ?? 'NULL') . ', ' . ($factura === null ? "'Nuevo'" : "'Sin Guardar'") . ", :tienda, :usuario, '2026-02-04', NOW(), :p1, 5.2, {$productos}, {$albaranes}, 'F-8'";
    }

    /**
     * Pinta la pantalla en un PHP aparte y devuelve su HTML, sus avisos, los nombres de lo sembrado y lo que
     * quedó en la base de las facturas del proveedor sembrado y de los temporales.
     *
     * @param array<string, string> $consulta
     * @param list<string> $preparacion
     * @param list<string> $sinPermiso
     * @param array<string, string> $formulario
     * @return array<string, mixed>
     */
    private function pintar(string $fichero, array $consulta, array $preparacion = [], bool $conSesion = true, array $sinPermiso = [], array $formulario = []): array
    {
        $sesion = $conSesion ? [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => (string) $this->idUsuario, 'group_id' => '1', 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => (string) $this->idTienda, 'razonsocial' => 'Tienda de pruebas SL', 'NombreComercial' => 'Tienda de pruebas', 'nif' => 'B00000000', 'direccion' => 'Calle de pruebas 1', 'telefono' => '986000000', 'ano' => '2026'],
        ] : [];
        $quitar = $sinPermiso === [] ? 'FALSE' : 'accion IN (' . implode(', ', array_map(static fn (string $a): string => "'{$a}'", $sinPermiso)) . ')';
        $marcas = ['proveedores' => ':p', 'articulos' => ':a', 'facprot' => ':fac', 'albprot' => ':alb', 'facproltemporales' => ':tfac'];
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["REQUEST_METHOD"] = ' . var_export($formulario === [] ? 'GET' : 'POST', true) . '; $_SERVER["DOCUMENT_ROOT"] = ' . var_export(dirname(RUTA_TPVFOX), true) . ';'
            . ' $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = ' . var_export("/TPVFox/modulos/mod_compras/{$fichero}", true) . ';'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_compras', true) . ');'
            . ' require ' . var_export(RUTA_TPVFOX . '/inicial.php', true) . ';'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ($conSesion ? ' new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' $BDTpv->query("UPDATE permisos SET permiso = IF(' . $quitar . ', 0, 1) WHERE idUsuario = ' . $this->idUsuario . ' AND modulo = \'mod_compras\'");'
            . ' $ids = [":usuario" => "' . $this->idUsuario . '", ":tienda" => "' . $this->idTienda . '"]; $cuentas = []; $marcas = ' . var_export($marcas, true) . ';'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->query(strtr($sql, $ids)); $id = $BDTpv->insert_id;'
            . ' if (preg_match("/^INSERT INTO (\\\\w+) /", $sql, $t) && isset($marcas[$t[1]])) { $cuentas[$t[1]] = ($cuentas[$t[1]] ?? 0) + 1; $ids[$marcas[$t[1]] . $cuentas[$t[1]]] = (string) $id; } }'
            . ' $_GET = json_decode(strtr(' . var_export((string) json_encode($consulta), true) . ', $ids), true);'
            . ' $_POST = json_decode(strtr(' . var_export((string) json_encode($formulario), true) . ', $ids), true);'
            . ($conSesion ? ' $ClasePermisos = $thisTpv->permisos = new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' register_shutdown_function(function () use ($BDTpv, &$ids) { $p = (int) ($ids[":p1"] ?? 0); echo "\n@@DATOS@@" . json_encode(["ids" => $ids,'
            . ' "facturas" => $BDTpv->query("SELECT Su_num_factura, estado, total FROM facprot WHERE idProveedor = " . $p . " ORDER BY id")->fetch_all(),'
            . ' "temporales" => (int) $BDTpv->query("SELECT COUNT(*) FROM facproltemporales WHERE idProveedor = " . $p)->fetch_row()[0]], JSON_INVALID_UTF8_SUBSTITUTE); });'
            . ' include ' . var_export($fichero, true) . ';';

        $errores = (string) tempnam(sys_get_temp_dir(), 'compras');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -d error_log=/dev/null -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$html, $datos] = array_pad(explode('@@DATOS@@', implode("\n", $salida), 2), 2, 'null');
        $pintada = ['html' => $html, 'errores' => (string) file_get_contents($errores)] + ((json_decode($datos, true) ?? []) + ['ids' => [], 'facturas' => [], 'temporales' => 0]);
        unlink($errores);

        return $pintada;
    }
}
