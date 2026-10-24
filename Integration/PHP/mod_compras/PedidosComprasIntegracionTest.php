<?php

/**
 * `PedidosCompras`, la clase del pedido a proveedor: su temporal, su guardado —la cabecera, las líneas
 * activas y el desglose—, la modificación de uno guardado, el borrado de sus tablas, sus lecturas y
 * listados, sus estados y la comprobación de temporales.
 *
 * La clase incluye la común con una ruta relativa a la carpeta del módulo: se carga desde ella. Recibe
 * la conexión del caso, en su transacción. `guardarPedido()` lee la sesión y el formulario: el caso los
 * pone y los quita.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModCompras;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class PedidosComprasIntegracionTest extends CasoIntegracion
{
    private \PedidosCompras $pedidos;

    private Siembra $siembra;

    private int $proveedor;

    private int $tienda;

    private int $usuario;

    /** @var array<int, int> */
    private array $articulos = [];

    protected function setUp(): void
    {
        parent::setUp();
        $cwd = (string) getcwd();
        chdir(RUTA_TPVFOX . '/modulos/mod_compras');
        try {
            $this->incluirTPVFox('/modulos/mod_compras/clases/pedidosCompras.php');
        } finally {
            chdir($cwd);
        }
        $this->pedidos = new \PedidosCompras($this->db);
        $this->siembra = new Siembra($this->db);
        $this->proveedor = $this->siembra->proveedor('Proveedor del pedido SL');
        $this->db->query("UPDATE proveedores SET nombrecomercial = 'Proveedor del pedido' WHERE idProveedor = {$this->proveedor}");
        $this->tienda = $this->siembra->tiendaPorDefecto();
        $this->usuario = $this->siembra->usuarioPorDefecto();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        parent::tearDown();
    }

    /**
     * @comportamiento Un temporal nuevo guarda su cabecera y sus productos como texto JSON; modificarlo los
     *   cambia; sus totales se guardan aparte, y se relaciona con su pedido guardado.
     */
    public function test_elTemporalSeGuardaYSeModifica(): void
    {
        $id = (int) $this->pedidos->insertarDatosPedidoTemporal($this->usuario, $this->tienda, 'Nuevo', '2026-02-03', [$this->linea(5)], $this->proveedor)['id'];
        $antes = $this->pedidos->buscarPedidoTemporal($id);
        $this->pedidos->modificarDatosPedidoTemporal($this->usuario, $this->tienda, 'Sin Guardar', '2026-02-04', $id, [$this->linea(5), $this->linea(6, ['nfila' => 2])]);
        $this->pedidos->modTotales($id, 8.32, '0.32');
        $pedido = $this->pedidoGuardado();
        $this->pedidos->addNumRealTemporal($id, $pedido);
        $despues = $this->pedidos->DatosTemporal($id);

        self::assertSame(['Nuevo', '2026-02-03 00:00:00', (string) $this->proveedor, null], [$antes['estadoPedPro'], $antes['Fecha'], $antes['idProveedor'], $antes['idPedpro']]);
        self::assertCount(1, json_decode($antes['Productos'], true));
        self::assertSame(['Sin Guardar', '2026-02-04 00:00:00', '8.320000', '0.32', (string) $pedido], [$despues['estadoPedPro'], $despues['Fecha'], $despues['total'], $despues['total_ivas'], $despues['idPedpro']]);
        self::assertCount(2, json_decode($despues['Productos'], true));
    }

    /**
     * @comportamiento Los temporales se listan —todos, o los de un pedido— con el nombre del proveedor y el
     *   número del pedido; uno que no está, el error; se eliminan por su id o por su pedido.
     */
    public function test_elTemporalSeListaSeBuscaYSeElimina(): void
    {
        $pedido = $this->pedidoGuardado();
        $suyo = $this->temporal($pedido);
        $otro = $this->temporal();

        $todos = array_column($this->pedidos->TodosTemporal(), null, 'id');
        $delPedido = $this->pedidos->TodosTemporal($pedido);
        $noEsta = $this->pedidos->buscarPedidoTemporal($otro + 1000);
        $this->pedidos->eliminarTemporal($otro);
        $this->pedidos->eliminarTemporal(0, $pedido);

        self::assertSame(['Proveedor del pedido', '901'], [$todos[(string) $suyo]['nombrecomercial'], $todos[(string) $suyo]['Numpedpro']]);
        self::assertArrayHasKey((string) $otro, $todos);
        self::assertSame([(string) $suyo], array_column($delPedido, 'id'));
        self::assertStringStartsWith('No se encontro temporal', $noEsta['error']);
        self::assertSame(0, $this->cuantos('pedprotemporales', "id IN ({$suyo}, {$otro})"));
    }

    /** @comportamiento Comprobar los temporales de un pedido: sin ninguno, nada; con uno, su id; con dos, o con uno distinto del que se edita, el aviso de duplicidad. */
    public function test_comprobarLosTemporalesDeUnPedido(): void
    {
        $pedido = $this->pedidoGuardado();
        $sinNinguno = $this->pedidos->comprobarTemporalIdPedpro($pedido);
        $uno = $this->temporal($pedido);
        $conUno = $this->pedidos->comprobarTemporalIdPedpro($pedido);
        $distinto = $this->pedidos->comprobarTemporalIdPedpro($pedido, $uno + 1);
        $this->temporal($pedido);
        $conDos = $this->pedidos->comprobarTemporalIdPedpro($pedido);

        self::assertSame([], $sinNinguno);
        self::assertSame(['idTemporal' => (string) $uno], $conUno);
        self::assertStringContainsString('no coincide el idtemporal', $distinto[0]['mensaje']);
        self::assertStringContainsString('Hay mas de un temporal con el mismo numero pedido', $conDos[0]['mensaje']);
    }

    /**
     * @comportamiento Guardar el temporal de un pedido nuevo graba su cabecera «Guardado» con su número igual
     *   a su id y el temporal del que viene, sus líneas activas numeradas, su desglose y sus totales, y
     *   quita el temporal.
     */
    public function test_guardarUnPedidoNuevo(): void
    {
        $temporal = $this->temporal(null, [$this->linea(5, ['nunidades' => 4, 'ncant' => 4, 'ultimoCoste' => 2]), $this->linea(6, ['estado' => 'Eliminado', 'nfila' => 2])]);

        $respuesta = $this->guardar($temporal, 'Nuevo');

        $id = (int) $respuesta['id_guardo'];
        self::assertArrayNotHasKey('errores', $respuesta);
        self::assertSame([(string) $id, (string) $temporal, 'Guardado', '8.000000', '8.32', (string) $this->usuario], array_values($this->fila("SELECT Numpedpro, Numtemp_pedpro, estado, total_siniva, total, idUsuario FROM pedprot WHERE id = {$id}")));
        self::assertSame([[(string) $this->articulo(5), '1', 'Activo', '4.000000', '2.0000']], $this->db->query("SELECT idArticulo, nfila, estadoLinea, nunidades, costeSiva FROM pedprolinea WHERE idpedpro = {$id}")->fetch_all());
        self::assertSame([['4', '0.32', '8.00']], $this->db->query("SELECT iva, importeIva, totalbase FROM pedproIva WHERE idpedpro = {$id}")->fetch_all());
        self::assertSame(0, $this->cuantos('pedprotemporales', "id = {$temporal}"));
    }

    /**
     * @comportamiento Guardar el temporal de un pedido guardado cambia su cabecera —fecha, estado «Guardado»,
     *   totales, quién y cuándo—, sustituye sus líneas y su desglose, y quita el temporal.
     */
    public function test_guardarUnPedidoModificado(): void
    {
        $pedido = $this->pedidoGuardado();
        $this->pedidos->AddRegistrosPedidosLineasIvas($this->datosPedido([$this->linea(5)]), $pedido);
        $this->pedidos->modEstadoPedido($pedido, 'Sin Guardar');
        $temporal = $this->temporal($pedido, [$this->linea(7, ['nunidades' => 2, 'ncant' => 2, 'ultimoCoste' => 3])]);

        $respuesta = $this->guardar($temporal, 'Sin Guardar');

        self::assertArrayNotHasKey('errores', $respuesta);
        self::assertSame(['2026-02-05 00:00:00', 'Guardado', '6.24', (string) $this->usuario], array_values($this->fila("SELECT Fecha, estado, total, modify_by FROM pedprot WHERE id = {$pedido}")));
        self::assertNotNull($this->fila("SELECT fechaModificacion FROM pedprot WHERE id = {$pedido}")['fechaModificacion']);
        self::assertSame([[(string) $this->articulo(7)]], $this->db->query("SELECT idArticulo FROM pedprolinea WHERE idpedpro = {$pedido}")->fetch_all());
        self::assertSame([['4', '6.00']], $this->db->query("SELECT iva, totalbase FROM pedproIva WHERE idpedpro = {$pedido}")->fetch_all());
        self::assertSame(0, $this->cuantos('pedprotemporales', "id = {$temporal}"));
    }

    /**
     * @comportamiento Sin la tienda o el usuario de la sesión, con el pedido facturado o con el temporal de la
     *   dirección distinto del del formulario, guardar avisa y no graba; sin temporal, el aviso de que no hay
     *   productos.
     */
    public function test_guardarSinSesionFacturadoOConOtroTemporalAvisa(): void
    {
        $temporal = $this->temporal();

        $sinSesion = $this->guardar($temporal, 'Nuevo', false);
        $facturado = $this->guardar($temporal, 'Facturado');
        $otro = $this->guardar($temporal, 'Nuevo', true, ['temporal' => (string) ($temporal + 1)]);
        $sinTemporal = $this->guardar($temporal + 1000, 'Nuevo');

        self::assertSame('ERROR NO HAY DATOS DE SESIÓN!', $sinSesion['errores'][0]['mensaje']);
        self::assertSame('No se permite modificar un pedido en estado Facturado', $facturado['errores'][0]['mensaje']);
        self::assertStringContainsString('no coincide get con post', $otro['errores'][0]['mensaje']);
        self::assertSame('No existen productos para el recalculo de precios!', end($sinTemporal['errores'])['mensaje']);
        self::assertSame(0, $this->cuantos('pedprot', "idProveedor = {$this->proveedor}"));
        self::assertSame(1, $this->cuantos('pedprotemporales', "id = {$temporal}"));
    }

    /**
     * @comportamiento Un pedido se lee con sus productos —con los nombres del formulario— y su desglose; su
     *   suma da la base y la cuota; el albarán que lo sirve se lee de su relación; se busca por su número.
     */
    public function test_leerUnPedidoConSusProductos(): void
    {
        $pedido = $this->pedidoGuardado();
        $this->pedidos->AddRegistrosPedidosLineasIvas($this->datosPedido([$this->linea(5, ['ncant' => 3, 'nunidades' => 3, 'ultimoCoste' => 2])]), $pedido);
        $this->db->query("INSERT INTO albprot (Numalbpro, Su_numero, Fecha, idTienda, idUsuario, idProveedor, estado, total_siniva, total) VALUES (31, 'A-31', '2026-02-02', {$this->tienda}, {$this->usuario}, {$this->proveedor}, 'Guardado', 6, 6.24)");
        $albaran = (int) $this->db->insert_id;
        $this->db->query("INSERT INTO pedproAlb (idAlbaran, numAlbaran, idPedido, numPedido) VALUES ({$albaran}, 31, {$pedido}, 901)");

        $leido = $this->pedidos->GetPedido($pedido);
        $suma = $this->pedidos->sumarIva($pedido);
        $servido = $this->pedidos->NumAlbaranDePedido($pedido);
        $porNumero = $this->pedidos->buscarPedidoNumero(901);

        self::assertSame(['901', 'Guardado'], [$leido['Numpedpro'], $leido['estado']]);
        self::assertSame([(string) $this->articulo(5), '2.0000', 'Activo', '3.000000'], [$leido['Productos'][0]['idArticulo'], $leido['Productos'][0]['ultimoCoste'], $leido['Productos'][0]['estado'], $leido['Productos'][0]['ncant']]);
        self::assertCount(1, $this->pedidos->IvasPedidos($pedido));
        self::assertEqualsWithDelta([0.24, 6.0], [(float) $suma['importeIva'], (float) $suma['totalbase']], 0.001);
        self::assertSame('31', $servido['numAlbaran']);
        self::assertSame((string) $pedido, $porNumero['id']);
    }

    /**
     * @comportamiento Los pedidos se listan con su proveedor y su correo según la condición que llega; los de
     *   un proveedor se buscan por estado —todos, uno por su número, o ninguno—; se cambian su estado y su
     *   fecha, y los estados se listan de los que hay.
     */
    public function test_listarBuscarYCambiarLosPedidos(): void
    {
        $primero = $this->pedidoGuardado(901);
        $segundo = $this->pedidoGuardado(902);
        $this->pedidos->modEstadoPedido($segundo, 'Enviado');
        $this->pedidos->modFechaPedido('2026-03-01', $primero);

        $listado = $this->pedidos->TodosPedidosLimite("WHERE a.idProveedor = {$this->proveedor} ORDER BY a.id");
        $guardados = $this->pedidos->pedidosProveedorGuardado($this->proveedor, 'Guardado');
        $todos = $this->pedidos->buscarPedidoProveedorPorEstado($this->proveedor, 0, 'Enviado');
        $uno = $this->pedidos->buscarPedidoProveedorPorEstado($this->proveedor, 901, 'Guardado');
        $ninguno = $this->pedidos->buscarPedidoProveedorPorEstado($this->proveedor, 0, 'Facturado');

        self::assertSame([[(string) $primero, '2026-03-01 00:00:00', 'Proveedor del pedido', 'Guardado'], [(string) $segundo, '2026-02-01 00:00:00', 'Proveedor del pedido', 'Enviado']], array_map(static fn (array $p): array => [$p['id'], $p['Fecha'], $p['nombrecomercial'], $p['estado']], $listado['Items']));
        self::assertArrayHasKey('email', $listado['Items'][0]);
        self::assertSame([(string) $primero], array_column($guardados, 'id'));
        self::assertSame([1, '902'], [$todos['Nitems'], $todos['datos'][0]['Numpedpro']]);
        self::assertSame([1, (string) $primero], [$uno['Nitems'], $uno['datos'][0]['id']]);
        self::assertSame(['Nitems' => 0], $ninguno);
        self::assertContains('Enviado', $this->pedidos->getEstadosPedidos());
    }

    /** @comportamiento Borrar una tabla de un pedido —sus líneas o su desglose— quita solo esa; sin id, nada. */
    public function test_borrarUnaTablaDeUnPedido(): void
    {
        $pedido = $this->pedidoGuardado();
        $this->pedidos->AddRegistrosPedidosLineasIvas($this->datosPedido([$this->linea(5)]), $pedido);

        $this->pedidos->eliminarPedidoTablas($pedido, 'pedprolinea');
        $sinId = $this->pedidos->eliminarPedidoTablas(0);

        self::assertSame([1, 0, 1], [$this->cuantos('pedprot', "id = {$pedido}"), $this->cuantos('pedprolinea', "idpedpro = {$pedido}"), $this->cuantos('pedproIva', "idpedpro = {$pedido}")]);
        self::assertSame([], $sinId);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/clases/pedidosCompras.php:256-295
     *
     * @group defecto
     * @group seguridad
     * @group alto
     *
     * @que-ocurre-hoy Una línea cuya descripción lleva comillas dobles rompe la sentencia de las líneas: el
     *   guardado termina en una excepción de la base y el pedido queda sin líneas.
     * @que-deberia-ocurrir Que el pedido se guarde con la descripción tal como se escribió.
     * @por-que-ocurre Los datos de las líneas se pegan a la sentencia.
     * @como-deberia-funcionar Pasarlos como parámetros.
     */
    public function test_unaDescripcionConComillasSeGuarda(): void
    {
        $temporal = $this->temporal(null, [$this->linea(5, ['cdetalle' => 'Pera "de agua"'])]);

        try {
            $this->guardar($temporal, 'Nuevo');
        } catch (\mysqli_sql_exception) {
        }

        self::assertSame('Pera "de agua"', $this->fila("SELECT l.cdetalle FROM pedprolinea l JOIN pedprot c ON c.id = l.idpedpro WHERE c.idProveedor = {$this->proveedor}")['cdetalle'] ?? null);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/clases/pedidosCompras.php:185-317
     *
     * @group defecto
     * @group integridad
     * @group alto
     *
     * @que-ocurre-hoy Si una parte del guardado falla —aquí, el desglose—, la cabecera y las líneas ya
     *   escritas se quedan: el pedido queda a medias.
     * @que-deberia-ocurrir Que un guardado que falla no deje nada.
     * @por-que-ocurre Cada sentencia se ejecuta por su cuenta, sin transacción.
     * @como-deberia-funcionar Guardar la cabecera, las líneas y el desglose en una transacción.
     */
    public function test_unGuardadoQueFallaNoDejaElPedidoAMedias(): void
    {
        $datos = $this->datosPedido([$this->linea(5)]);
        $datos['DatosTotales']['desglose'] = ['x' => ['iva' => 0.04, 'base' => 1]];

        try {
            $this->pedidos->AddPedidoGuardado($datos);
        } catch (\mysqli_sql_exception) {
        }

        self::assertSame(0, $this->cuantos('pedprot', "idProveedor = {$this->proveedor}"));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/clases/pedidosCompras.php:256-295, :670-680
     *
     * @group defecto
     * @group robustez
     * @group medio
     *
     * @que-ocurre-hoy Un temporal con productos, pero todos eliminados, pasa la comprobación del guardado: la
     *   sentencia de las líneas sale sin valores, la base la rechaza y el pedido queda guardado sin líneas.
     * @que-deberia-ocurrir Que guardar avise de que no hay productos y no grabe nada.
     * @por-que-ocurre El guardado solo comprueba que el temporal tenga productos, no que alguno esté activo.
     * @como-deberia-funcionar Comprobar que haya líneas activas antes de grabar.
     */
    public function test_unPedidoSinLineasActivasNoSeGuarda(): void
    {
        $temporal = $this->temporal(null, [$this->linea(5, ['estado' => 'Eliminado'])]);

        try {
            $this->guardar($temporal, 'Nuevo');
        } catch (\mysqli_sql_exception) {
        }

        self::assertSame(0, $this->cuantos('pedprot', "idProveedor = {$this->proveedor}"));
    }

    /** El artículo sembrado del caso con ese número, creado la primera vez que se pide. */
    private function articulo(int $n): int
    {
        return $this->articulos[$n] ??= $this->siembra->articulo('Producto ' . $n);
    }

    /** @param array<string, mixed> $cambios */
    private function linea(int $n, array $cambios = []): array
    {
        return $cambios + ['idArticulo' => $this->articulo($n), 'cref' => 'REF-' . $n, 'ccodbar' => '', 'cdetalle' => 'Producto ' . $n, 'ref_prov' => '', 'ncant' => 1, 'nunidades' => 1, 'ultimoCoste' => 1, 'iva' => 4, 'nfila' => 1, 'estado' => 'Activo'];
    }

    /**
     * Los datos que el guardado arma para grabar, con los totales de las líneas.
     *
     * @param list<array<string, mixed>> $lineas
     * @return array<string, mixed>
     */
    private function datosPedido(array $lineas): array
    {
        $totales = $this->pedidos->recalculoTotales($lineas);

        return ['Numtemp_pedpro' => 0, 'Fecha' => '2026-02-03', 'idTienda' => $this->tienda, 'idUsuario' => $this->usuario, 'idProveedor' => $this->proveedor, 'estado' => 'Guardado',
            'total_siniva' => (float) $totales['total'] - (float) $totales['subivas'], 'total' => $totales['total'], 'fechaCreacion' => '2026-02-03 10:00:00', 'Productos' => json_encode($lineas), 'DatosTotales' => $totales];
    }

    private function pedidoGuardado(int $numero = 901): int
    {
        $this->db->query("INSERT INTO pedprot (Numpedpro, Numtemp_pedpro, Fecha, idTienda, idUsuario, idProveedor, estado, total_siniva, total) VALUES ({$numero}, 0, '2026-02-01', {$this->tienda}, {$this->usuario}, {$this->proveedor}, 'Guardado', 1, 1.04)");

        return (int) $this->db->insert_id;
    }

    /** @param list<array<string, mixed>>|null $lineas */
    private function temporal(?int $pedido = null, ?array $lineas = null): int
    {
        $id = (int) $this->pedidos->insertarDatosPedidoTemporal($this->usuario, $this->tienda, $pedido === null ? 'Nuevo' : 'Sin Guardar', '2026-02-03', $lineas ?? [$this->linea(5)], $this->proveedor)['id'];
        if ($pedido !== null) {
            $this->pedidos->addNumRealTemporal($id, $pedido);
        }

        return $id;
    }

    /**
     * Lo que responde `guardarPedido()` con la sesión y el formulario del caso.
     *
     * @param array<string, string> $get
     * @return array<string, mixed>
     */
    private function guardar(int $temporal, string $estado, bool $conSesion = true, array $get = []): array
    {
        $_SESSION = $conSesion ? ['tiendaTpv' => ['idTienda' => $this->tienda], 'usuarioTpv' => ['id' => $this->usuario]] : ['tiendaTpv' => [], 'usuarioTpv' => []];
        $_POST = ['fecha' => '05-02-2026', 'estado' => $estado, 'idTemporal' => (string) $temporal];
        $_GET = $get;

        return $this->pedidos->guardarPedido();
    }

    private function cuantos(string $tabla, string $donde): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM {$tabla} WHERE {$donde}")->fetch_row()[0];
    }

    /** @return array<string, string|null>|null */
    private function fila(string $sql): ?array
    {
        return $this->db->query($sql)->fetch_assoc();
    }
}
