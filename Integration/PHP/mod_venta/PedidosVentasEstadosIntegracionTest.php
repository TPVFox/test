<?php

/**
 * El ciclo de estados del pedido de venta: cuando queda marcado como servido, cuando
 * vuelve a estar disponible, y si eso concuerda con los albaranes que lo sirven.
 *
 * Ninguna de las escrituras que mueven ese estado vive en los ficheros del pedido:
 * `ModificarEstadoPedido()` se invoca desde `tareas.php` y desde `funciones.php`, y la
 * relacion con el albaran la escribe y la borra `albaranesVentas.php`. Lo que se comprueba
 * aqui es el efecto sobre el pedido, no el despacho que lo origina.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVenta;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class PedidosVentasEstadosIntegracionTest extends CasoIntegracion
{
    private \PedidosVentas $pedidos;
    private \AlbaranesVentas $albaranes;
    private Siembra $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_venta/clases/pedidosVentas.php');
        $this->incluirTPVFox('/modulos/mod_venta/clases/albaranesVentas.php');
        $this->pedidos = new \PedidosVentas($this->db);
        $this->albaranes = new \AlbaranesVentas($this->db);
        $this->siembra = new Siembra($this->db);
    }

    // --- La escritura del estado ---------------------------------------------

    public function test_modificarEstadoPedido_cambiaElEstadoDelPedido(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de cambio de estado');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-10');

        $this->pedidos->ModificarEstadoPedido($idPedido, 'Procesado');

        self::assertSame('Procesado', $this->pedidos->getEstado($idPedido));
    }

    /**
     * El estado se escribe sin contrastarlo con `posiblesEstados()`, igual que en el alta:
     * la via de actualizacion tampoco tiene catalogo.
     */
    public function test_defecto_modificarEstadoPedido_aceptaCualquierCadenaComoEstado(): void
    {
        $idArticulo = $this->siembra->articulo('Producto con estado arbitrario');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-10');

        $this->pedidos->ModificarEstadoPedido($idPedido, 'Lo que sea');

        self::assertSame('Lo que sea', $this->pedidos->getEstado($idPedido));
    }

    /**
     * Cambiar el estado de un pedido que no existe devuelve lo mismo que cambiarlo sobre
     * uno que si: nada. El `UPDATE` no comprueba cuantas filas afecto.
     */
    public function test_defecto_modificarEstadoPedido_noDistingueUnPedidoInexistente(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de estado existente');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-10');

        $sobreExistente = $this->pedidos->ModificarEstadoPedido($idPedido, 'Procesado');
        $sobreInexistente = $this->pedidos->ModificarEstadoPedido(999999999, 'Procesado');

        self::assertSame($sobreExistente, $sobreInexistente);
    }

    // --- Marcar un pedido como servido ----------------------------------------

    /**
     * Defecto: el pedido que queda marcado como servido no es el que se sirvio, sino aquel
     * cuyo identificador coincide con el numero del servido.
     *
     * Sintoma: al incorporar un pedido a un albaran, el navegador pide marcarlo como
     * `Procesado` enviando `NumAdjunto`, que `funciones.php` rellena con `Numpedcli` —el
     * numero del documento—; `ModificarEstadoPedido()` lo recibe y actualiza
     * `WHERE id=`. Mientras numero e identificador coinciden funciona por casualidad. En
     * cuanto divergen, el pedido servido se queda como estaba y otro distinto pasa a
     * `Procesado` sin que nadie lo haya servido. Causa raiz: el numero del documento y su
     * identificador se usan indistintamente a lo largo de todo el recorrido, sin que ningun
     * punto declare cual de los dos viaja. Correccion propuesta: que la peticion lleve el
     * identificador, o que el metodo consulte por el campo que realmente recibe. Evidencia:
     * este test.
     */
    public function test_defecto_marcarServidoAlcanzaAlPedidoCuyoIdentificadorCoincideConElNumero(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de pedido con numero desplazado');
        $servido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-10');
        $ajeno = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-11');
        $this->db->query("UPDATE pedclit SET Numpedcli={$ajeno} WHERE id={$servido}");

        $numeroDelServido = (int) $this->pedidos->datosPedido($servido)['Numpedcli'];
        $this->pedidos->ModificarEstadoPedido($numeroDelServido, 'Procesado');

        self::assertSame('Procesado', $this->pedidos->getEstado($ajeno), 'Se marca el pedido ajeno...');
        self::assertSame('Guardado', $this->pedidos->getEstado($servido), '... y el servido sigue disponible.');
    }

    /**
     * La consecuencia del caso anterior sobre lo que la pantalla de albaran ofrece: el
     * pedido que ya se sirvio sigue apareciendo entre los pedidos guardados del cliente, de
     * modo que puede incorporarse a un segundo albaran y su mercancia salir dos veces.
     */
    public function test_defecto_elPedidoServidoSigueOfreciendoseParaVolverASeServirse(): void
    {
        $idCliente = $this->siembra->cliente('Cliente de pedido servido dos veces');
        $idArticulo = $this->siembra->articulo('Producto que sale dos veces');
        $servido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-10', ['idCliente' => $idCliente]);
        $ajeno = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-11', ['idCliente' => $idCliente]);
        $this->db->query("UPDATE pedclit SET Numpedcli={$ajeno} WHERE id={$servido}");

        $this->pedidos->ModificarEstadoPedido(
            (int) $this->pedidos->datosPedido($servido)['Numpedcli'],
            'Procesado'
        );

        $disponibles = array_map(
            'intval',
            array_column($this->pedidos->PedidosClienteGuardado(0, $idCliente)['datos'], 'id')
        );
        self::assertContains($servido, $disponibles, 'El pedido ya servido se sigue ofreciendo.');
    }

    /**
     * Nada impide que dos albaranes sirvan el mismo pedido: la relacion admite las dos
     * filas y el estado del pedido no se consulta antes de incorporarlo.
     */
    public function test_defecto_dosAlbaranesPuedenServirElMismoPedido(): void
    {
        $idArticulo = $this->siembra->articulo('Producto servido por dos albaranes');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-05');
        $primero = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $segundo = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-11');

        $this->siembra->adjuntarPedidoAAlbaran($primero, $idPedido);
        $this->siembra->adjuntarPedidoAAlbaran($segundo, $idPedido);

        self::assertCount(1, $this->albaranes->obtenerPedidosAlbaran($primero)['Items']);
        self::assertCount(1, $this->albaranes->obtenerPedidosAlbaran($segundo)['Items']);
    }

    // --- Concordancia entre el estado y la relacion ----------------------------

    /**
     * Defecto: borrar el albaran que sirvio un pedido lo deja `Procesado` para siempre.
     *
     * Sintoma: `eliminarAlbaranTablas()` retira la fila de `pedcliAlb`, pero ninguna de sus
     * cuatro sentencias devuelve el pedido a `Guardado`. El pedido queda en un estado que la
     * propia pantalla reconoce como imposible —`pedido.php` avisa de que esta `Procesado` y
     * no tiene albaran—, no se puede editar, no se puede eliminar y no vuelve a ofrecerse
     * para servir. Causa raiz: el estado del documento y la relacion que lo justifica se
     * mantienen por separado, y solo la via del temporal (`cancelarAlbaran()`) revierte el
     * estado; la del albaran ya guardado no. Correccion propuesta: que retirar la relacion
     * devuelva el pedido a `Guardado` dentro de la misma operacion. Evidencia: este test.
     */
    public function test_defecto_borrarElAlbaranQueSirvioUnPedidoLoDejaProcesadoSinAlbaran(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de pedido huerfano');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-05', ['estado' => 'Procesado']);
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $this->siembra->adjuntarPedidoAAlbaran($idAlbaran, $idPedido);

        $this->albaranes->eliminarAlbaranTablas($idAlbaran);

        self::assertSame([], $this->pedidos->NumAlbaranDePedido($idPedido), 'La relacion desaparece...');
        self::assertSame('Procesado', $this->pedidos->getEstado($idPedido), '... y el pedido sigue procesado.');
    }

    /**
     * El otro sentido de la misma discrepancia: un pedido puede quedar enlazado a un albaran
     * y seguir en `Guardado`. La pantalla lo detecta y avisa, pero no lo impide, y el pedido
     * se sigue ofreciendo para servir aunque ya tenga albaran.
     */
    public function test_defecto_unPedidoEnlazadoAUnAlbaranPuedeSeguirEnGuardado(): void
    {
        $idCliente = $this->siembra->cliente('Cliente de pedido enlazado sin procesar');
        $idArticulo = $this->siembra->articulo('Producto enlazado sin procesar');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-05', ['idCliente' => $idCliente]);
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $this->siembra->adjuntarPedidoAAlbaran($idAlbaran, $idPedido);

        self::assertSame('Guardado', $this->pedidos->getEstado($idPedido));
        self::assertNotSame([], $this->pedidos->NumAlbaranDePedido($idPedido));

        $disponibles = array_map(
            'intval',
            array_column($this->pedidos->PedidosClienteGuardado(0, $idCliente)['datos'], 'id')
        );
        self::assertContains($idPedido, $disponibles, 'Y se sigue ofreciendo pese a tener albaran.');
    }
}
