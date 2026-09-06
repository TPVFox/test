<?php

/**
 * `PedidosVentas`, los metodos que solo leen: la cabecera del pedido, sus lineas, su
 * desglose de ivas, el albaran que lo sirve, sus temporales y los estados.
 *
 * A diferencia de `AlbaranesVentas`, esta clase no redefine `consulta()`: hereda la de
 * `ClaseVentas`. Lo que aqui se comprueba de la lectura vale por tanto para la clase base,
 * y no hace falta repetir sobre una copia.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVenta;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class PedidosVentasLecturaIntegracionTest extends CasoIntegracion
{
    private \PedidosVentas $pedidos;
    private Siembra $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_venta/clases/pedidosVentas.php');
        $this->pedidos = new \PedidosVentas($this->db);
        $this->siembra = new Siembra($this->db);
    }

    // --- Cabecera y contenido del pedido ------------------------------------

    public function test_datosPedido_devuelveLaCabeceraDelPedido(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo de lectura de pedido');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 2.0, '2026-01-10');

        $pedido = $this->pedidos->datosPedido($idPedido);

        self::assertSame('Guardado', $pedido['estado']);
    }

    public function test_datosPedido_sinCoincidenciaDevuelveArrayVacio(): void
    {
        self::assertSame([], $this->pedidos->datosPedido(999999999));
    }

    public function test_productosPedido_devuelveTodasSusLineas(): void
    {
        $primero = $this->siembra->articulo('Primer articulo del pedido');
        $segundo = $this->siembra->articulo('Segundo articulo del pedido');
        $idPedido = $this->siembra->pedidoVentaCliente($primero, 2.0, '2026-01-10');
        $this->siembra->lineaPedidoVentaCliente($idPedido, $segundo, 3.0);

        $lineas = $this->pedidos->ProductosPedido($idPedido);

        self::assertCount(2, $lineas);
    }

    public function test_productosPedido_sinLineasDevuelveListaVacia(): void
    {
        self::assertSame([], $this->pedidos->ProductosPedido(999999999));
    }

    public function test_ivasPedidos_devuelveElDesglosePorTipo(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo con iva propio', ['iva' => 21.0]);
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 2.0, '2026-01-10');

        $desglose = $this->pedidos->IvasPedidos($idPedido);

        self::assertCount(1, $desglose);
        self::assertSame('21', $desglose[0]['iva']);
    }

    public function test_ivasPedidos_conDosTiposDevuelveUnaFilaPorCadaUno(): void
    {
        $reducido = $this->siembra->articulo('Articulo al diez', ['iva' => 10.0]);
        $general = $this->siembra->articulo('Articulo al veintiuno', ['iva' => 21.0]);
        $idPedido = $this->siembra->pedidoVentaCliente($reducido, 1.0, '2026-01-10');
        $this->siembra->lineaPedidoVentaCliente($idPedido, $general, 1.0);

        self::assertCount(2, $this->pedidos->IvasPedidos($idPedido));
    }

    public function test_sumarIva_sumaElDesgloseDelPedido(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo para sumar iva', ['iva' => 21.0]);
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 2.0, '2026-01-10');
        $numero = (int) $this->pedidos->datosPedido($idPedido)['Numpedcli'];

        $suma = $this->pedidos->sumarIva($numero);

        $desglose = $this->pedidos->IvasPedidos($idPedido)[0];
        self::assertSame($desglose['importeIva'], $suma['importeIva']);
        self::assertSame($desglose['totalbase'], $suma['totalbase']);
    }

    public function test_sumarIva_sumaLosDosTiposEnUnSoloImporte(): void
    {
        $reducido = $this->siembra->articulo('Articulo al diez para sumar', ['iva' => 10.0]);
        $general = $this->siembra->articulo('Articulo al veintiuno para sumar', ['iva' => 21.0]);
        $idPedido = $this->siembra->pedidoVentaCliente($reducido, 1.0, '2026-01-10');
        $this->siembra->lineaPedidoVentaCliente($idPedido, $general, 1.0);
        $numero = (int) $this->pedidos->datosPedido($idPedido)['Numpedcli'];

        $esperado = 0.0;
        foreach ($this->pedidos->IvasPedidos($idPedido) as $fila) {
            $esperado += (float) $fila['importeIva'];
        }

        self::assertEqualsWithDelta($esperado, (float) $this->pedidos->sumarIva($numero)['importeIva'], 0.001);
    }

    /**
     * Un pedido sin desglose no devuelve ceros: `sumarIva` consulta con `sum()`, que
     * siempre trae una fila —con los dos campos a NULL cuando no hay nada que sumar—, de
     * modo que quien lo llame recibe NULL donde espera un importe. El listado lo usa para
     * pintar la columna de impuestos de cada fila.
     */
    public function test_defecto_sumarIvaDeUnPedidoSinDesgloseDevuelveNuloEnLugarDeCero(): void
    {
        $suma = $this->pedidos->sumarIva(999999999);

        self::assertNull($suma['importeIva'], 'Deberia poder sumarse como un numero.');
        self::assertNull($suma['totalbase']);
    }

    // --- Estados -------------------------------------------------------------

    public function test_getEstado_devuelveElEstadoDelPedido(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo con estado');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-10', ['estado' => 'Procesado']);

        self::assertSame('Procesado', $this->pedidos->getEstado($idPedido));
    }

    /**
     * Un pedido que no existe y un pedido sin estado no se distinguen: `getEstado` indexa
     * el array vacio que devuelve la lectura sin comprobar que trajo fila, de modo que la
     * ausencia del documento llega al llamante como un estado nulo.
     */
    public function test_defecto_getEstadoDeUnPedidoInexistenteNoSeDistingueDeUnEstadoVacio(): void
    {
        self::assertNull(@$this->pedidos->getEstado(999999999));
    }

    public function test_posiblesEstados_declaraLosTresEstadosDelDocumento(): void
    {
        $estados = array_column($this->pedidos->posiblesEstados(), 'estado');

        self::assertSame(['Guardado', 'Sin Guardar', 'Procesado'], $estados);
    }

    public function test_getEstadosPedidos_devuelveLosEstadosPresentesEnLaTabla(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo para el catalogo de estados');
        $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-10', ['estado' => 'Guardado']);
        $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-11', ['estado' => 'Procesado']);

        $estados = $this->pedidos->getEstadosPedidos();

        self::assertContains('Guardado', $estados);
        self::assertContains('Procesado', $estados);
    }

    /**
     * El catalogo de estados tiene dos origenes que no se contrastan: `posiblesEstados()`
     * lo declara en el codigo y `getEstadosPedidos()` lo deduce de lo que haya escrito en
     * la tabla. Un valor que nunca se declaro entra igual en el segundo, que es el que
     * alimenta el filtro del listado.
     */
    public function test_defecto_unEstadoFueraDeCatalogoEntraEnElFiltroDelListado(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo con estado inventado');
        $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-10', ['estado' => 'Pendiente']);

        $declarados = array_column($this->pedidos->posiblesEstados(), 'estado');

        self::assertNotContains('Pendiente', $declarados);
        self::assertContains('Pendiente', $this->pedidos->getEstadosPedidos());
    }

    // --- Relacion con el albaran que lo sirve --------------------------------

    public function test_numAlbaranDePedido_devuelveLaRelacionConSuAlbaran(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo servido');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-05');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $this->siembra->adjuntarPedidoAAlbaran($idAlbaran, $idPedido);

        $relacion = $this->pedidos->NumAlbaranDePedido($idPedido);

        self::assertSame($idAlbaran, (int) $relacion['idAlbaran']);
    }

    public function test_numAlbaranDePedido_sinServirDevuelveArrayVacio(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo sin servir');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-05');

        self::assertSame([], $this->pedidos->NumAlbaranDePedido($idPedido));
    }

    /**
     * El lado de lectura del pedido servido dos veces: la relacion admite tantas filas
     * como albaranes lo enlacen, pero `NumAlbaranDePedido` lee una sola. La pantalla del
     * pedido la usa para comprobar que un pedido Procesado tiene albaran, de modo que el
     * segundo albaran que lo sirve no llega a mostrarse en ningun sitio.
     */
    public function test_defecto_numAlbaranDePedidoSoloVeElPrimeroDeLosAlbaranesQueLoSirven(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo servido dos veces');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-05');
        $primero = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $segundo = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-11');
        $this->siembra->adjuntarPedidoAAlbaran($primero, $idPedido);
        $this->siembra->adjuntarPedidoAAlbaran($segundo, $idPedido);

        $relacion = $this->pedidos->NumAlbaranDePedido($idPedido);

        self::assertSame($primero, (int) $relacion['idAlbaran']);
        self::assertSame(
            2,
            (int) $this->db->query('SELECT COUNT(*) as n FROM pedcliAlb WHERE idPedido=' . $idPedido)
                ->fetch_assoc()['n'],
            'Hay dos albaranes sirviendo el mismo pedido y la lectura solo ofrece uno.'
        );
    }

    // --- Consultas por cliente ------------------------------------------------

    public function test_comprobarPedidos_cuentaLosPedidosDelClienteEnEseEstado(): void
    {
        $idCliente = $this->siembra->cliente('Cliente con pedidos guardados');
        $idArticulo = $this->siembra->articulo('Articulo para contar');
        $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-10', ['idCliente' => $idCliente]);
        $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-11', ['idCliente' => $idCliente]);

        self::assertSame(2, $this->pedidos->ComprobarPedidos($idCliente, 'Guardado')['NItems']);
    }

    public function test_comprobarPedidos_noCuentaLosDeOtroEstado(): void
    {
        $idCliente = $this->siembra->cliente('Cliente con pedido procesado');
        $idArticulo = $this->siembra->articulo('Articulo procesado para contar');
        $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-10', [
            'idCliente' => $idCliente,
            'estado'    => 'Procesado',
        ]);

        self::assertSame(0, $this->pedidos->ComprobarPedidos($idCliente, 'Guardado')['NItems']);
    }

    public function test_pedidosClienteGuardado_conNumeroDevuelveEsePedido(): void
    {
        $idCliente = $this->siembra->cliente('Cliente de busqueda por numero');
        $idArticulo = $this->siembra->articulo('Articulo de busqueda por numero');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-10', ['idCliente' => $idCliente]);
        $numero = (int) $this->pedidos->datosPedido($idPedido)['Numpedcli'];

        $respuesta = $this->pedidos->PedidosClienteGuardado($numero, $idCliente);

        self::assertSame(1, $respuesta['Nitems']);
        self::assertSame($idPedido, (int) $respuesta['datos'][0]['id']);
    }

    public function test_pedidosClienteGuardado_conNumeroDeOtroClienteNoDevuelveNada(): void
    {
        $suyo = $this->siembra->cliente('Cliente propietario del pedido');
        $ajeno = $this->siembra->cliente('Cliente que no lo hizo');
        $idArticulo = $this->siembra->articulo('Articulo de pedido ajeno');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-10', ['idCliente' => $suyo]);
        $numero = (int) $this->pedidos->datosPedido($idPedido)['Numpedcli'];

        self::assertSame(0, $this->pedidos->PedidosClienteGuardado($numero, $ajeno)['Nitems']);
    }

    public function test_pedidosClienteGuardado_sinNumeroDevuelveTodosLosGuardadosDelCliente(): void
    {
        $idCliente = $this->siembra->cliente('Cliente con varios guardados');
        $idArticulo = $this->siembra->articulo('Articulo de varios guardados');
        $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-10', ['idCliente' => $idCliente]);
        $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-11', ['idCliente' => $idCliente]);
        $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-12', [
            'idCliente' => $idCliente,
            'estado'    => 'Procesado',
        ]);

        $respuesta = $this->pedidos->PedidosClienteGuardado(0, $idCliente);

        self::assertSame(2, $respuesta['Nitems'], 'El pedido Procesado no debe ofrecerse para servir.');
    }

    // --- Listado ---------------------------------------------------------------

    public function test_todosPedidosFiltro_devuelveElPedidoConElNombreDeSuCliente(): void
    {
        $idCliente = $this->siembra->cliente('Cliente del listado de pedidos');
        $idArticulo = $this->siembra->articulo('Articulo del listado de pedidos');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-10', ['idCliente' => $idCliente]);

        $respuesta = $this->pedidos->TodosPedidosFiltro('WHERE a.id=' . $idPedido);

        self::assertCount(1, $respuesta['Items']);
        self::assertSame('Cliente del listado de pedidos', $respuesta['Items'][0]['Nombre']);
    }

    public function test_todosPedidosFiltro_aplicaElFragmentoDeWhereQueRecibe(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo para filtrar por estado');
        $guardado = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-10', ['estado' => 'Guardado']);
        $procesado = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-11', ['estado' => 'Procesado']);

        $respuesta = $this->pedidos->TodosPedidosFiltro("WHERE a.estado='Procesado'");

        $ids = array_map('intval', array_column($respuesta['Items'], 'id'));
        self::assertContains($procesado, $ids);
        self::assertNotContains($guardado, $ids);
    }

    public function test_todosPedidosFiltro_devuelveLaConsultaQueEjecuto(): void
    {
        $respuesta = $this->pedidos->TodosPedidosFiltro('WHERE a.id=0');

        self::assertStringContainsString('LEFT JOIN clientes', $respuesta['consulta']);
    }

    // --- Temporales -------------------------------------------------------------

    public function test_todosTemporal_devuelveLosBorradoresConElNombreDelCliente(): void
    {
        $idCliente = $this->siembra->cliente('Cliente con borrador de pedido');
        $idTemporal = $this->siembra->pedidoTemporal([], ['idCliente' => $idCliente]);

        $temporales = $this->pedidos->TodosTemporal();

        $nombres = array_column($temporales, 'Nombre', 'id');
        self::assertSame('Cliente con borrador de pedido', $nombres[$idTemporal]);
    }

    public function test_todosTemporal_conNumeroDePedidoDevuelveSoloElSuyo(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo de borrador con numero');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-10');
        $numero = (int) $this->pedidos->datosPedido($idPedido)['Numpedcli'];
        $this->siembra->pedidoTemporal([], ['Numpedcli' => $numero]);
        $this->siembra->pedidoTemporal();

        self::assertCount(1, $this->pedidos->TodosTemporal($numero));
    }

    /**
     * `pedidosListado.php` llama a `TodosTemporal()` sin argumento, y la consulta no acota
     * por tienda ni por usuario: el borrador que alguien esta componiendo en otra tienda
     * aparece en el listado de todos los demas, con el nombre de su cliente.
     */
    public function test_defecto_elListadoDeBorradoresNoSeAcotaPorTiendaNiPorUsuario(): void
    {
        $otraTienda = $this->siembra->tienda('2026', 'secundaria');
        $idCliente = $this->siembra->cliente('Cliente de otra tienda');
        $idTemporal = $this->siembra->pedidoTemporal([], [
            'idCliente' => $idCliente,
            'idTienda'  => $otraTienda,
        ]);

        $ids = array_map('intval', array_column($this->pedidos->TodosTemporal(), 'id'));

        self::assertContains($idTemporal, $ids, 'El borrador de otra tienda se ofrece a todo el mundo.');
    }

    public function test_buscarDatosTemporal_devuelveElBorrador(): void
    {
        $idTemporal = $this->siembra->pedidoTemporal([['idArticulo' => 1, 'ncant' => 2]]);

        $temporal = $this->pedidos->buscarDatosTemporal($idTemporal);

        self::assertSame($idTemporal, (int) $temporal['id']);
        self::assertStringContainsString('"ncant":2', $temporal['Productos']);
    }

    public function test_buscarDatosTemporal_sinCoincidenciaDevuelveArrayVacio(): void
    {
        self::assertSame([], $this->pedidos->buscarDatosTemporal(999999999));
    }

    // --- Construccion de la clase ------------------------------------------------

    public function test_constructor_dejaContadoElNumeroDePedidosExistentes(): void
    {
        $antes = (int) (new \PedidosVentas($this->db))->num_rows;
        $idArticulo = $this->siembra->articulo('Articulo para contar en el constructor');
        $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-10');

        self::assertSame($antes + 1, (int) (new \PedidosVentas($this->db))->num_rows);
    }
}
