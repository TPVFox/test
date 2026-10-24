<?php

/**
 * Las pantallas del pedido a proveedor: la del pedido (`modulos/mod_compras/pedido.php`) —nuevo, uno
 * guardado, su temporal, uno facturado y su guardado— y el listado (`pedidosListado.php`) —los pedidos,
 * sus temporales abiertos y sus opciones según los permisos—.
 *
 * Cada pantalla se pinta en un PHP aparte, desde la carpeta del módulo, con la sesión del usuario de
 * pruebas —su número como texto, como lo deja el inicio de sesión— o sin ninguna, y los permisos del
 * módulo concedidos salvo los que el caso quite, en una transacción sobre su conexión que nunca se
 * confirma; lo que necesita se siembra en ella, con un nombre (`:p1`, `:a1`, `:ped1`, `:tped1`, `:alb1`).
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModCompras;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class PantallasPedidoCompraIntegracionTest extends CasoIntegracion
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

    /** @comportamiento Sin nada en la dirección, el pedido es nuevo y editable, sin proveedor, con el usuario de la sesión. */
    public function test_unPedidoNuevo(): void
    {
        $pagina = $this->pintar('pedido.php', []);

        self::assertStringContainsString('<h3 class="text-center">Pedido de Proveedor:', $pagina['html']);
        self::assertStringContainsString("cabecera['estado'] = 'Nuevo';", $pagina['html']);
        self::assertStringContainsString("cabecera['idProveedor'] = '';", $pagina['html']);
        self::assertStringContainsString("cabecera['idUsuario'] = " . $this->idUsuario . ';', $pagina['html']);
        self::assertStringContainsString('name="Guardar" id="bGuardar"', $pagina['html']);
        self::assertStringContainsString('id="bCancelar"', $pagina['html']);
    }

    /** @comportamiento Un pedido guardado se ve con su proveedor, sus líneas en solo lectura y sus totales. */
    public function test_unPedidoGuardadoSeVe(): void
    {
        $pagina = $this->pintar('pedido.php', ['id' => ':ped1', 'accion' => 'ver'], $this->pedidoGuardado());

        self::assertStringContainsString("cabecera['estado'] = 'Guardado';", $pagina['html']);
        self::assertStringContainsString('value="Frutas compra"', $pagina['html']);
        self::assertMatchesRegularExpression('#id="Descripcion_Fila_1"[^>]*value="Pera de compra"[^>]*readonly#', $pagina['html']);
        self::assertStringContainsString('10.40', $pagina['html']);
        self::assertStringNotContainsString('eliminarFila(', $pagina['html']);
        self::assertStringNotContainsString('id="bGuardar"', $pagina['html']);
    }

    /** @comportamiento Un temporal se abre por su número, editable, con su estado y sus productos. */
    public function test_unTemporalSeAbrePorSuNumero(): void
    {
        $pagina = $this->pintar('pedido.php', ['tActual' => ':tped1'], array_merge($this->pedidoGuardado(), [$this->temporal(':ped1')]));

        self::assertStringContainsString("cabecera['estado'] = 'Sin Guardar';", $pagina['html']);
        self::assertStringContainsString("cabecera['idTemporal'] = '" . $pagina['ids'][':tped1'] . "';", $pagina['html']);
        self::assertStringContainsString("cabecera['idReal'] = '" . $pagina['ids'][':ped1'] . "';", $pagina['html']);
        self::assertStringContainsString('Pera del temporal', $pagina['html']);
    }

    /** @comportamiento Un pedido facturado solo se ve, con el albarán que lo sirve; un parámetro de más en la dirección se avisa. */
    public function test_unPedidoFacturadoYUnParametroDeMas(): void
    {
        $preparacion = array_merge($this->pedidoGuardado('Facturado'), [
            "INSERT INTO albprot (Numalbpro, Su_numero, Fecha, idTienda, idUsuario, idProveedor, estado, total_siniva, total) VALUES (31, 'A-31', '2026-02-02', :tienda, :usuario, :p1, 'Guardado', 10, 10.4)",
            "INSERT INTO pedproAlb (idAlbaran, numAlbaran, idPedido, numPedido) VALUES (:alb1, 31, :ped1, 901)",
        ]);

        $facturado = $this->pintar('pedido.php', ['id' => ':ped1', 'accion' => 'editar'], $preparacion);
        $deMas = $this->pintar('pedido.php', ['id' => ':ped1', 'otro' => '1'], $preparacion);

        self::assertStringContainsString("cabecera['estado'] = 'Facturado';", $facturado['html']);
        self::assertStringContainsString('albaran:31', $facturado['html']);
        self::assertStringNotContainsString('id="bGuardar"', $facturado['html']);
        self::assertStringContainsString('Hay parametro get,distinto a tActual,accion,id', $deMas['html']);
    }

    /** @comportamiento Guardar desde la pantalla guarda el pedido de su temporal y quita el temporal. */
    public function test_guardarDesdeLaPantalla(): void
    {
        $preparacion = [$this->proveedor(), $this->articulo(), $this->temporal(null)];

        $pagina = $this->pintar('pedido.php', ['tActual' => ':tped1'], $preparacion, true, [], ['Guardar' => 'Guardar', 'idTemporal' => ':tped1', 'fecha' => '04-02-2026', 'estado' => 'Nuevo']);

        self::assertSame([['Guardado', '5.20']], array_map(static fn (array $p): array => [$p[1], $p[2]], $pagina['pedidos']));
        self::assertSame(0, $pagina['temporales']);
    }

    /** @comportamiento Sin sesión, la pantalla del pedido no se pinta. */
    public function test_sinSesionElPedidoNoSePinta(): void
    {
        $pagina = $this->pintar('pedido.php', [], [], false);

        self::assertStringNotContainsString('Pedido de Proveedor', $pagina['html']);
    }

    /**
     * @comportamiento El listado enseña los pedidos con su número, su proveedor, su base, su IVA, su total y
     *   su estado, y los temporales abiertos; las opciones generales y las de cada pedido según los
     *   permisos —el correo, si el proveedor lo tiene—, sin imprimir los que están sin guardar.
     */
    public function test_elListadoDePedidos(): void
    {
        $preparacion = array_merge($this->pedidoGuardado(), [
            "UPDATE proveedores SET email = 'compras@frutas.test' WHERE idProveedor = :p1",
            $this->temporal(null),
        ]);

        $todos = $this->pintar('pedidosListado.php', ['buscar' => 'Frutas compra'], $preparacion);
        $sinPermisos = $this->pintar('pedidosListado.php', ['buscar' => 'Frutas compra'], $preparacion, true, ['Crear', 'Modificar', 'CambiarEstado', 'EliminarTemporal', 'EnviarPedidoEmail']);

        self::assertMatchesRegularExpression('#<td>901</td>\s*<td>2026-02-03 10:00:00</td>\s*<td>Frutas compra</td>\s*<td>10\.00</td>\s*<td>0\.40</td>\s*<td>10\.40</td>#', $todos['html']);
        self::assertStringContainsString("redireccionA('pedido.php?tActual=" . $todos['ids'][':tped1'], $todos['html']);
        self::assertStringContainsString('href="./pedido.php">Añadir</a>', $todos['html']);
        self::assertStringContainsString('class="glyphicon glyphicon-pencil" href="./pedido.php?id=' . $todos['ids'][':ped1'] . '&accion=editar"', $todos['html']);
        self::assertStringContainsString("onclick='imprimir(" . $todos['ids'][':ped1'], $todos['html']);
        self::assertStringContainsString("onclick='formularioEnvioEmail(" . $todos['ids'][':ped1'], $todos['html']);
        self::assertStringContainsString('eliminarTemporal(', $todos['html']);
        self::assertStringNotContainsString('href="./pedido.php">Añadir</a>', $sinPermisos['html']);
        self::assertStringNotContainsString('glyphicon-pencil', $sinPermisos['html']);
        self::assertStringNotContainsString('formularioEnvioEmail(', $sinPermisos['html']);
        self::assertStringNotContainsString('eliminarTemporal(', $sinPermisos['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/pedido.php:80-98
     *
     * @group defecto
     * @group robustez
     * @group alto
     *
     * @que-ocurre-hoy Un pedido guardado con un temporal abierto no se abre en su temporal: la pantalla pinta
     *   un aviso de error con el resultado de la comprobación y se corta.
     * @que-deberia-ocurrir Que se abra en su temporal, como el albarán.
     * @por-que-ocurre La condición mira una variable mal escrita (`$temporale`), que no existe, y el
     *   resultado de la comprobación —con el número del temporal— acaba como mensaje de error.
     * @como-deberia-funcionar Mirar el resultado de la comprobación.
     */
    public function test_unPedidoConTemporalSeAbreEnSuTemporal(): void
    {
        $pagina = $this->pintar('pedido.php', ['id' => ':ped1', 'accion' => 'editar'], array_merge($this->pedidoGuardado(), [$this->temporal(':ped1')]));

        self::assertStringContainsString("cabecera['idTemporal'] = '" . $pagina['ids'][':tped1'] . "';", $pagina['html']);
        self::assertStringContainsString('Pera del temporal', $pagina['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/pedido.php:20-98
     *
     * @group defecto
     * @group seguridad
     * @group medio
     *
     * @que-ocurre-hoy Sin el permiso de modificar pedidos, pedir editar uno guardado por su dirección lo
     *   enseña editable.
     * @que-deberia-ocurrir Que sin el permiso se enseñe para ver, como el albarán.
     * @por-que-ocurre La pantalla no consulta el permiso de modificar; solo el listado esconde el lápiz.
     * @como-deberia-funcionar Pasar a «ver» si no hay permiso de modificar.
     */
    public function test_sinPermisoDeModificarElPedidoSeVe(): void
    {
        $pagina = $this->pintar('pedido.php', ['id' => ':ped1', 'accion' => 'editar'], $this->pedidoGuardado(), true, ['Modificar']);

        self::assertMatchesRegularExpression('#id="Descripcion_Fila_1"[^>]*value="Pera de compra"[^>]*readonly#', $pagina['html']);
        self::assertStringNotContainsString('eliminarFila(', $pagina['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/pedido.php:434-436
     *
     * @group defecto
     * @group seguridad
     * @group alto
     *
     * @que-ocurre-hoy El nombre del proveedor se escribe sin escapar en la caja del pedido: unas comillas
     *   cierran el atributo y lo que sigue entra en la página.
     * @que-deberia-ocurrir Que se pinte como texto.
     * @por-que-ocurre Se concatena tal cual.
     * @como-deberia-funcionar Escaparlo al pintarlo.
     */
    public function test_elProveedorSePintaComoTextoEnElPedido(): void
    {
        $preparacion = $this->pedidoGuardado();
        $preparacion[0] = str_replace("'Frutas compra'", "'Frutas \"><b>compra</b>'", $preparacion[0]);

        $pagina = $this->pintar('pedido.php', ['id' => ':ped1', 'accion' => 'ver'], $preparacion);

        self::assertStringNotContainsString('"><b>compra</b>', $pagina['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/pedidosListado.php:143, :232, :245-247
     *
     * @group defecto
     * @group seguridad
     * @group alto
     *
     * @que-ocurre-hoy El nombre del proveedor se pinta sin escapar en el listado de pedidos y en el de sus
     *   temporales, y su correo dentro del atributo y de la orden de enviar.
     * @que-deberia-ocurrir Que se pinten como texto.
     * @por-que-ocurre Se escriben tal cual.
     * @como-deberia-funcionar Escaparlos según donde se pinten.
     */
    public function test_elProveedorSePintaComoTextoEnElListado(): void
    {
        $preparacion = $this->pedidoGuardado();
        $preparacion[0] = str_replace("'Frutas compra'", "'Frutas <b>compra</b>'", $preparacion[0]);

        $pagina = $this->pintar('pedidosListado.php', ['buscar' => 'Frutas'], $preparacion);

        self::assertStringNotContainsString('<td>Frutas <b>compra</b></td>', $pagina['html']);
    }

    private function proveedor(): string
    {
        return "INSERT INTO proveedores (nombrecomercial, razonsocial, nif, direccion, fecha_creado, estado) VALUES ('Frutas compra', 'Frutas compra SL', 'X1', 'Sin direccion', NOW(), 'Activo')";
    }

    private function articulo(): string
    {
        return "INSERT INTO articulos (iva, articulo_name, estado, fecha_creado, ultimoCoste, tipo) VALUES (4, 'Pera de compra', 'Activo', NOW(), 2, 'unidad')";
    }

    /**
     * Un proveedor con un pedido —el 901, con una línea de pera de 10 € al 4 %— y su desglose.
     *
     * @return list<string>
     */
    private function pedidoGuardado(string $estado = 'Guardado'): array
    {
        return [
            $this->proveedor(),
            $this->articulo(),
            "INSERT INTO pedprot (Numpedpro, Numtemp_pedpro, Fecha, idTienda, idUsuario, idProveedor, estado, total_siniva, total) VALUES (901, 0, '2026-02-03 10:00:00', :tienda, :usuario, :p1, '{$estado}', 10, 10.4)",
            "INSERT INTO pedprolinea (idpedpro, idArticulo, cref, ref_prov, ccodbar, cdetalle, ncant, nunidades, costeSiva, iva, nfila, estadoLinea) VALUES (:ped1, :a1, '1001', '', '', 'Pera de compra', 5, 5, 2, 4, 1, 'Activo')",
            "INSERT INTO pedproIva (idpedpro, iva, importeIva, totalbase) VALUES (:ped1, 4, 0.4, 10)",
        ];
    }

    /** Un temporal del proveedor sembrado, de un pedido guardado o nuevo, con una línea de pera de 5,20 €. */
    private function temporal(?string $pedido): string
    {
        $productos = "CONCAT('[{\"idArticulo\":\"', :a1, '\",\"cref\":\"1001\",\"ccodbar\":\"\",\"ref_prov\":\"\",\"cdetalle\":\"Pera del temporal\",\"ncant\":\"1\",\"nunidades\":\"1\",\"ultimoCoste\":\"5\",\"iva\":\"4\",\"nfila\":1,\"estado\":\"Activo\"}]')";

        return 'INSERT INTO pedprotemporales (estadoPedPro, idTienda, idUsuario, Fecha, fechaInicio, idProveedor, total, Productos, idPedpro) SELECT '
            . ($pedido === null ? "'Nuevo'" : "'Sin Guardar'") . ", :tienda, :usuario, '2026-02-04', NOW(), :p1, 5.2, {$productos}, " . ($pedido ?? 'NULL');
    }

    /**
     * Pinta la pantalla en un PHP aparte y devuelve su HTML, sus avisos, los nombres de lo sembrado y lo que
     * quedó en la base de los pedidos del proveedor sembrado y de los temporales.
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
        $marcas = ['proveedores' => ':p', 'articulos' => ':a', 'pedprot' => ':ped', 'albprot' => ':alb', 'pedprotemporales' => ':tped'];
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
            . ' "pedidos" => $BDTpv->query("SELECT Numpedpro, estado, total FROM pedprot WHERE idProveedor = " . $p . " ORDER BY id")->fetch_all(),'
            . ' "temporales" => (int) $BDTpv->query("SELECT COUNT(*) FROM pedprotemporales WHERE idProveedor = " . $p)->fetch_row()[0]], JSON_INVALID_UTF8_SUBSTITUTE); });'
            . ' include ' . var_export($fichero, true) . ';';

        $errores = (string) tempnam(sys_get_temp_dir(), 'compras');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -d error_log=/dev/null -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$html, $datos] = array_pad(explode('@@DATOS@@', implode("\n", $salida), 2), 2, 'null');
        $pintada = ['html' => $html, 'errores' => (string) file_get_contents($errores)] + ((json_decode($datos, true) ?? []) + ['ids' => [], 'pedidos' => [], 'temporales' => 0]);
        unlink($errores);

        return $pintada;
    }
}
