<?php

/**
 * Las pantallas del albarán de proveedor: la del albarán (`modulos/mod_compras/albaran.php`) —nuevo, uno
 * guardado, su temporal, uno facturado y su guardado— y el listado (`albaranesListado.php`) —los
 * albaranes, sus temporales abiertos, sus opciones según los permisos y el enlace al recálculo—.
 *
 * Cada pantalla se pinta en un PHP aparte, desde la carpeta del módulo, con la sesión del usuario de
 * pruebas —su número como texto, como lo deja el inicio de sesión— o sin ninguna, y los permisos del
 * módulo concedidos salvo los que el caso quite, en una transacción sobre su conexión que nunca se
 * confirma; lo que necesita se siembra en ella, con un nombre (`:p1`, `:a1`, `:alb1`, `:talb1`, `:ped1`).
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModCompras;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class PantallasAlbaranCompraIntegracionTest extends CasoIntegracion
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

    /** @comportamiento Sin nada en la dirección, el albarán es nuevo y editable, sin proveedor, con el usuario de la sesión. */
    public function test_unAlbaranNuevo(): void
    {
        $pagina = $this->pintar('albaran.php', []);

        self::assertStringContainsString('<h3 class="text-center">Albarán de proveedor:', $pagina['html']);
        self::assertStringContainsString("cabecera['estado'] = 'Nuevo';", $pagina['html']);
        self::assertStringContainsString("cabecera['idProveedor'] = '';", $pagina['html']);
        self::assertStringContainsString("cabecera['idUsuario'] = " . $this->idUsuario . ';', $pagina['html']);
        self::assertStringContainsString('name="Guardar" id="bGuardar"', $pagina['html']);
    }

    /**
     * @comportamiento Un albarán guardado se ve con su proveedor, su número del proveedor, sus líneas en solo
     *   lectura y sus totales; sin el permiso de modificar, pedir editarlo lo enseña para ver.
     */
    public function test_unAlbaranGuardadoSeVe(): void
    {
        $preparacion = $this->albaranGuardado();

        $ver = $this->pintar('albaran.php', ['id' => ':alb1', 'accion' => 'ver'], $preparacion);
        $sinPermiso = $this->pintar('albaran.php', ['id' => ':alb1', 'accion' => 'editar'], $preparacion, true, ['Modificar']);

        self::assertStringContainsString("cabecera['estado'] = 'Guardado';", $ver['html']);
        self::assertStringContainsString("cabecera['suNumero'] = 'A-7';", $ver['html']);
        self::assertStringContainsString('value="Frutas compra"', $ver['html']);
        self::assertStringContainsString('<td class="detalle">Pera de compra</td>', $ver['html']);
        self::assertStringContainsString('readonly', $ver['html']);
        self::assertStringContainsString('10.40', $ver['html']);
        self::assertStringContainsString('<td class="detalle">Pera de compra</td>', $sinPermiso['html']);
        self::assertStringNotContainsString("eliminarFila(", $sinPermiso['html']);
    }

    /**
     * @comportamiento Un albarán guardado que tiene un temporal abierto se abre en su temporal; el temporal
     *   también se abre por su número, con sus productos y su estado.
     */
    public function test_unAlbaranConTemporalSeAbreEnSuTemporal(): void
    {
        $preparacion = array_merge($this->albaranGuardado(), [
            "INSERT INTO albproltemporales (Numalbpro, Su_numero, estadoAlbPro, idTienda, idUsuario, Fecha, fechaInicio, idProveedor, total, Productos, Pedidos) SELECT :alb1, 'A-8', 'Sin Guardar', :tienda, :usuario, '2026-02-04', NOW(), :p1, 5.2, CONCAT('[{\"idArticulo\":\"', :a1, '\",\"cref\":\"1001\",\"ccodbar\":\"\",\"cdetalle\":\"Pera del temporal\",\"ncant\":\"1\",\"nunidades\":\"1\",\"ultimoCoste\":\"5\",\"iva\":\"4\",\"nfila\":1,\"estado\":\"Activo\"}]'), '[]'",
        ]);

        $porAlbaran = $this->pintar('albaran.php', ['id' => ':alb1', 'accion' => 'editar'], $preparacion);
        $porTemporal = $this->pintar('albaran.php', ['tActual' => ':talb1'], $preparacion);

        self::assertStringContainsString("cabecera['idTemporal'] = '" . $porAlbaran['ids'][':talb1'] . "';", $porAlbaran['html']);
        self::assertStringContainsString('Pera del temporal', $porAlbaran['html']);
        self::assertStringContainsString("cabecera['estado'] = 'Sin Guardar';", $porTemporal['html']);
        self::assertStringContainsString("cabecera['suNumero'] = 'A-8';", $porTemporal['html']);
    }

    /** @comportamiento Un albarán facturado solo se ve, con su factura; un parámetro de más en la dirección se avisa. */
    public function test_unAlbaranFacturadoYUnParametroDeMas(): void
    {
        $preparacion = array_merge($this->albaranGuardado('Facturado'), [
            "INSERT INTO facprot (Numfacpro, Su_num_factura, Fecha, idTienda, idUsuario, idProveedor, estado, total_siniva, total) VALUES (31, 'F-31', '2026-02-10', :tienda, :usuario, :p1, 'Guardado', 0, 0)",
            "INSERT INTO albprofac (idFactura, numFactura, idAlbaran, numAlbaran) VALUES (:fac1, 31, :alb1, 901)",
        ]);

        $facturado = $this->pintar('albaran.php', ['id' => ':alb1', 'accion' => 'editar'], $preparacion);
        $deMas = $this->pintar('albaran.php', ['id' => ':alb1', 'otro' => '1'], $preparacion);

        self::assertStringContainsString("cabecera['estado'] = 'Facturado';", $facturado['html']);
        self::assertStringNotContainsString('id="bGuardar"', preg_replace('/style="display:none"[^>]*id="bGuardar"/', '', $facturado['html']) ?? '');
        self::assertStringContainsString('Hay parametro get,distinto a tActual,accion,id', $deMas['html']);
    }

    /** @comportamiento Los pedidos de un temporal se pintan como adjuntos: el «Facturado», activo y enlazado; el «Guardado», quitado. */
    public function test_losPedidosDeUnTemporalSePintanComoAdjuntos(): void
    {
        $pagina = $this->pintar('albaran.php', ['tActual' => ':talb1'], $this->temporalConPedidos(['Facturado', 'Guardado']));

        self::assertStringContainsString('<a href="./pedido.php?id=901&accion=ver">901</a>', $pagina['html']);
        self::assertStringContainsString('<tr id="lineaP2"  class="tachado" >', $pagina['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/albaran.php:241-250, :419-426
     *
     * @group defecto
     * @group robustez
     * @group medio
     *
     * @que-ocurre-hoy Un pedido del temporal que no está ni guardado ni facturado se avisa como error y la
     *   página se corta ahí: el temporal no se puede ver, ni guardar, ni cancelar desde la pantalla.
     * @que-deberia-ocurrir Que el aviso salga y la pantalla se pinte entera.
     * @por-que-ocurre Tras pintar un aviso de tipo error, la página termina.
     * @como-deberia-funcionar Pintar el aviso y seguir.
     */
    public function test_unPedidoEnOtroEstadoSeAvisaSinCortarLaPagina(): void
    {
        $pagina = $this->pintar('albaran.php', ['tActual' => ':talb1'], $this->temporalConPedidos(['Enviado']));

        self::assertStringContainsString('tiene estado Enviado', $pagina['html']);
        self::assertStringContainsString('id="bGuardar"', $pagina['html']);
    }

    /** @comportamiento Guardar desde la pantalla guarda el albarán de su temporal y quita el temporal; sin errores, no pinta avisos. */
    public function test_guardarDesdeLaPantalla(): void
    {
        $preparacion = [
            "INSERT INTO proveedores (nombrecomercial, razonsocial, nif, direccion, fecha_creado, estado) VALUES ('Frutas compra', 'Frutas compra SL', 'X1', 'Sin direccion', NOW(), 'Activo')",
            "INSERT INTO articulos (iva, articulo_name, estado, fecha_creado, ultimoCoste, tipo) VALUES (4, 'Pera de compra', 'Activo', NOW(), 2, 'unidad')",
            "INSERT INTO albproltemporales (Su_numero, estadoAlbPro, idTienda, idUsuario, Fecha, fechaInicio, idProveedor, total, Productos, Pedidos) SELECT 'A-7', 'Sin Guardar', :tienda, :usuario, '2026-02-04', NOW(), :p1, 0, CONCAT('[{\"idArticulo\":\"', :a1, '\",\"cref\":\"1001\",\"ccodbar\":\"\",\"cdetalle\":\"Pera de compra\",\"ncant\":\"5\",\"nunidades\":\"5\",\"ultimoCoste\":\"2\",\"iva\":\"4\",\"nfila\":1,\"estado\":\"Activo\"}]'), '[]'",
        ];

        $pagina = $this->pintar('albaran.php', ['tActual' => ':talb1'], $preparacion, true, [], ['Guardar' => 'Guardar', 'idTemporal' => ':talb1', 'fecha' => '04-02-2026', 'hora' => '', 'estado' => 'Sin Guardar', 'suNumero' => 'A-7', 'formaVenci' => '', 'fechaVenci' => '']);

        self::assertSame([['A-7', '10.40', 'Guardado']], $pagina['albaranes']);
        self::assertSame(0, $pagina['temporales']);
        self::assertStringNotContainsString('alert-danger', $pagina['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/albaran.php:356-365, :516-528
     *
     * @group defecto
     * @group seguridad
     * @group alto
     *
     * @que-ocurre-hoy El número del proveedor y el nombre del proveedor se pintan sin escapar en la cabecera
     *   del JavaScript y en las cajas: un número con una comilla simple cierra la cadena y lo que sigue se
     *   ejecuta.
     * @que-deberia-ocurrir Que se pinten como texto.
     * @por-que-ocurre Se escriben tal cual en la página.
     * @como-deberia-funcionar Escaparlos según donde se pinten.
     */
    public function test_elNumeroDelProveedorSePintaComoTexto(): void
    {
        $pagina = $this->pintar('albaran.php', ['id' => ':alb1', 'accion' => 'ver'], $this->albaranGuardado('Guardado', "A';alert(1);'"));

        self::assertStringNotContainsString("cabecera['suNumero'] = 'A';alert(1);'';", $pagina['html']);
    }

    /** @comportamiento Sin sesión, la pantalla del albarán no se pinta. */
    public function test_sinSesionElAlbaranNoSePinta(): void
    {
        $pagina = $this->pintar('albaran.php', [], [], false);

        self::assertStringNotContainsString('Albarán de proveedor', $pagina['html']);
    }

    /**
     * @comportamiento El listado enseña los albaranes con su número, su proveedor, su base, su IVA, su total
     *   y su estado, y los temporales abiertos; las opciones generales y las de cada albarán según los
     *   permisos, sin editar los facturados ni imprimir los que no están guardados.
     */
    public function test_elListadoDeAlbaranes(): void
    {
        $preparacion = array_merge($this->albaranGuardado(), [
            "INSERT INTO albproltemporales (Su_numero, estadoAlbPro, idTienda, idUsuario, Fecha, fechaInicio, idProveedor, total, Productos, Pedidos) VALUES ('', 'Sin Guardar', :tienda, :usuario, '2026-02-04', NOW(), :p1, 5.2, '[]', '[]')",
        ]);

        $todos = $this->pintar('albaranesListado.php', ['buscar' => 'Frutas compra'], $preparacion);
        $sinPermisos = $this->pintar('albaranesListado.php', ['buscar' => 'Frutas compra'], $preparacion, true, ['Crear', 'Modificar', 'CambiarEstado', 'Importar', 'EliminarTemporal']);

        self::assertMatchesRegularExpression('#<td>901</td>\s*<td>2026-02-03</td>\s*<td>Frutas compra</td>\s*<td>10\.00</td>\s*<td>0\.40</td>\s*<td>10\.40</td>#', $todos['html']);
        self::assertStringContainsString("redireccionA('albaran.php?tActual=", $todos['html']);
        self::assertStringContainsString('href="./albaran.php" accesskey="A"', $todos['html']);
        self::assertStringContainsString('class="glyphicon glyphicon-pencil" href="./albaran.php?id=' . $todos['ids'][':alb1'] . '&accion=editar"', $todos['html']);
        self::assertStringContainsString("onclick='imprimir(" . $todos['ids'][':alb1'], $todos['html']);
        self::assertStringContainsString("onclick=\"eliminarTemporal(", $todos['html']);
        self::assertStringNotContainsString('href="./albaran.php" accesskey="A"', $sinPermisos['html']);
        self::assertStringNotContainsString('glyphicon-pencil', $sinPermisos['html']);
        self::assertStringNotContainsString('exportarXML(', $sinPermisos['html']);
        self::assertStringNotContainsString('eliminarTemporal(', $sinPermisos['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/albaranesListado.php:215-218
     *
     * @group defecto
     * @group integridad
     * @group bajo
     *
     * @que-ocurre-hoy El listado busca los costes pendientes de recalcular de un albarán por su número, pero
     *   el guardado los deja con su id: en un albarán cuyo número no es su id, el enlace al recálculo no sale.
     *   El guardado numera cada albarán con su id, así que hoy solo le pasa a uno numerado de otra forma.
     * @que-deberia-ocurrir Que el enlace salga cuando el albarán tiene costes pendientes.
     * @por-que-ocurre La consulta del histórico usa `Numalbpro`, y el enlace y el histórico, el `id`.
     * @como-deberia-funcionar Buscar el histórico por el id del albarán.
     */
    public function test_elEnlaceAlRecalculoSaleConCostesPendientes(): void
    {
        $preparacion = array_merge($this->albaranGuardado(), [
            'UPDATE albprot SET Numalbpro = Numalbpro + 50000 WHERE id = :alb1',
            "INSERT INTO historico_precios (idArticulo, Antes, Nuevo, Fecha_Creacion, NumDoc, Dedonde, Tipo, estado, idUsuario) VALUES (:a1, 1.5, 2, NOW(), :alb1, 'albaran', 'compras', 'Pendiente', :usuario)",
        ]);

        $pagina = $this->pintar('albaranesListado.php', ['buscar' => 'Frutas compra'], $preparacion);

        self::assertStringContainsString('Recalculo_precios.php?id=' . $pagina['ids'][':alb1'], $pagina['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/albaranesListado.php:148, :247
     *
     * @group defecto
     * @group seguridad
     * @group alto
     *
     * @que-ocurre-hoy El nombre del proveedor se pinta sin escapar en el listado de albaranes y en el de sus
     *   temporales.
     * @que-deberia-ocurrir Que se pinte como texto.
     * @por-que-ocurre Se escribe tal cual.
     * @como-deberia-funcionar Escaparlo al pintarlo.
     */
    public function test_elProveedorSePintaComoTextoEnElListado(): void
    {
        $preparacion = $this->albaranGuardado();
        $preparacion[0] = str_replace("'Frutas compra'", "'Frutas <b>compra</b>'", $preparacion[0]);

        $pagina = $this->pintar('albaranesListado.php', ['buscar' => 'Frutas'], $preparacion);

        self::assertStringNotContainsString('<td>Frutas <b>compra</b></td>', $pagina['html']);
    }

    /**
     * Un proveedor con un albarán guardado —el 901, con una línea de pera de 10 € al 4 %— y su desglose.
     *
     * @return list<string>
     */
    private function albaranGuardado(string $estado = 'Guardado', string $suNumero = 'A-7'): array
    {
        return [
            "INSERT INTO proveedores (nombrecomercial, razonsocial, nif, direccion, fecha_creado, estado) VALUES ('Frutas compra', 'Frutas compra SL', 'X1', 'Sin direccion', NOW(), 'Activo')",
            "INSERT INTO articulos (iva, articulo_name, estado, fecha_creado, ultimoCoste, tipo) VALUES (4, 'Pera de compra', 'Activo', NOW(), 2, 'unidad')",
            "INSERT INTO albprot (Numalbpro, Su_numero, Fecha, idTienda, idUsuario, idProveedor, estado, total_siniva, total, FechaVencimiento) VALUES (901, '" . addslashes($suNumero) . "', '2026-02-03 10:00:00', :tienda, :usuario, :p1, '{$estado}', 10, 10.4, '0000-00-00')",
            "INSERT INTO albprolinea (idalbpro, Numalbpro, idArticulo, cref, ccodbar, cdetalle, ncant, nunidades, costeSiva, iva, nfila, estadoLinea, ref_prov, idpedpro) VALUES (:alb1, 901, :a1, '1001', '', 'Pera de compra', 5, 5, 2, 4, 1, 'Activo', '', 0)",
            "INSERT INTO albproIva (idalbpro, Numalbpro, iva, importeIva, totalbase) VALUES (:alb1, 901, 4, 0.4, 10)",
        ];
    }

    /**
     * Un proveedor con un pedido por estado —el primero, el 901— y un temporal de albarán que los lleva.
     *
     * @param list<string> $estados
     * @return list<string>
     */
    private function temporalConPedidos(array $estados): array
    {
        $preparacion = ["INSERT INTO proveedores (nombrecomercial, razonsocial, nif, direccion, fecha_creado, estado) VALUES ('Frutas compra', 'Frutas compra SL', 'X1', 'Sin direccion', NOW(), 'Activo')"];
        $adjuntos = [];
        foreach ($estados as $n => $estado) {
            $preparacion[] = 'INSERT INTO pedprot (Numpedpro, Numtemp_pedpro, Fecha, idTienda, idUsuario, idProveedor, estado, total_siniva, total) VALUES (' . (901 + $n) . ", 0, '2026-02-01', :tienda, :usuario, :p1, '{$estado}', 10, 10.4)";
            $adjuntos[] = "'{\"idAdjunto\":\"', :ped" . ($n + 1) . ", '\"}'";
        }
        $preparacion[] = "INSERT INTO albproltemporales (Su_numero, estadoAlbPro, idTienda, idUsuario, Fecha, fechaInicio, idProveedor, total, Productos, Pedidos) VALUES ('', 'Sin Guardar', :tienda, :usuario, '2026-02-04', NOW(), :p1, 0, '[]', CONCAT('[', " . implode(", ',', ", $adjuntos) . ", ']'))";

        return $preparacion;
    }

    /**
     * Pinta la pantalla en un PHP aparte y devuelve su HTML, sus avisos, los nombres de lo sembrado y lo que
     * quedó en la base de los albaranes del proveedor sembrado y de los temporales.
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
        $marcas = ['proveedores' => ':p', 'articulos' => ':a', 'albprot' => ':alb', 'pedprot' => ':ped', 'facprot' => ':fac', 'albproltemporales' => ':talb'];
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
            . ' "albaranes" => $BDTpv->query("SELECT Su_numero, total, estado FROM albprot WHERE idProveedor = " . $p . " ORDER BY id")->fetch_all(),'
            . ' "temporales" => (int) $BDTpv->query("SELECT COUNT(*) FROM albproltemporales WHERE idProveedor = " . $p)->fetch_row()[0]], JSON_INVALID_UTF8_SUBSTITUTE); });'
            . ' include ' . var_export($fichero, true) . ';';

        $errores = (string) tempnam(sys_get_temp_dir(), 'compras');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -d error_log=/dev/null -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$html, $datos] = array_pad(explode('@@DATOS@@', implode("\n", $salida), 2), 2, 'null');
        $pintada = ['html' => $html, 'errores' => (string) file_get_contents($errores)] + ((json_decode($datos, true) ?? []) + ['ids' => [], 'albaranes' => [], 'temporales' => 0]);
        unlink($errores);

        return $pintada;
    }
}
