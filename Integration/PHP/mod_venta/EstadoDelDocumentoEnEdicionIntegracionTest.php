<?php

/**
 * El estado de un albaran o de un pedido ya guardado mientras alguien lo edita.
 *
 * Editar un documento guardado no lo toca hasta que se pulsa Guardar: lo que se compone vive
 * en un borrador. Pero el primer cambio hace dos escrituras sobre el documento real, las dos
 * pedidas por el navegador: crear el borrador atado a el (`tareas/AddTemporal.php`) y pasar
 * su estado a «Sin guardar» (`funciones.js:133-136`, caso `modificarEstadoDocumento`). Lo que
 * estos casos miden es que ninguna de las dos mira en que estado estaba el documento, y que
 * nada lo devuelve a su estado si la edicion se abandona.
 *
 * Cada caso recorre la misma secuencia de peticiones que la pantalla, por el despacho real de
 * `tareas.php` y con los parametros que envia el navegador.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVenta;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\DespachoTareas;
use TPVFox\Test\Siembra\Siembra;

final class EstadoDelDocumentoEnEdicionIntegracionTest extends CasoIntegracion
{
    private const RUTA_TAREAS = RUTA_TPVFOX . '/modulos/mod_venta/tareas.php';

    private \AlbaranesVentas $albaranes;
    private \PedidosVentas $pedidos;
    private Siembra $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_venta/clases/albaranesVentas.php');
        $this->incluirTPVFox('/modulos/mod_venta/clases/pedidosVentas.php');
        $this->albaranes = new \AlbaranesVentas($this->db);
        $this->pedidos = new \PedidosVentas($this->db);
        $this->siembra = new Siembra($this->db);
    }

    // --- Abandonar la edicion -------------------------------------------------

    /**
     * Cancelar la edicion de un albaran guardado lo deja fuera de la facturacion.
     *
     * Al primer cambio el navegador pasa el albaran a «Sin guardar». Cancelar borra el
     * borrador y no devuelve el estado (`funciones.php`, `cancelarAlbaran()`): el albaran se
     * queda en «Sin guardar» sin borrador ninguno. La factura solo ofrece para incorporar los
     * albaranes en «Guardado» (`albaranesVentas.php:142`), de modo que ese albaran deja de
     * aparecer al facturar al cliente, sin aviso. Vuelve al circuito solo si alguien lo abre,
     * lo cambia y lo guarda.
     *
     * @group defecto
     * @group albaran
     * @group borrador
     * @group estados
     * @group alto
     *
     * @que-ocurre-hoy Abrir un albaran guardado para editarlo, cambiar algo y cancelar lo deja
     *   en «Sin guardar», y desde ese momento no se ofrece al facturar al cliente.
     * @que-deberia-ocurrir Que cancelar deje el albaran como estaba antes de editarlo.
     * @por-que-ocurre El estado del albaran lo cambia el navegador al primer cambio, y la
     *   cancelacion solo borra el borrador: ninguna de las dos vias guarda ni restituye el estado
     *   que tenia.
     * @como-deberia-funcionar Que la cancelacion devuelva el albaran a su estado anterior, o que
     *   el estado del documento real no cambie mientras la edicion no se guarde.
     *
     * @codigo-afectado modulos/mod_venta/funciones.php:96-146
     */
    public function test_defecto_cancelarLaEdicionDeUnAlbaranGuardadoLoDejaFueraDeLaFacturacion(): void
    {
        $idCliente = $this->siembra->cliente('Cliente de albaran con edicion cancelada');
        $idArticulo = $this->siembra->articulo('Producto de albaran con edicion cancelada');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10', ['idCliente' => $idCliente]);
        self::assertContains($idAlbaran, $this->albaranesFacturables($idCliente), 'Punto de partida: se ofrece al facturar.');

        $idTemporal = $this->editar('albaran', $idAlbaran, $idArticulo, $idCliente);
        $this->despachar(['pulsado' => 'cancelarTemporal', 'dedonde' => 'albaran', 'idTemporal' => $idTemporal]);

        self::assertSame([], $this->albaranes->TodosTemporal($idAlbaran), 'El borrador ya no existe...');
        self::assertSame('Sin guardar', $this->albaranes->getEstado($idAlbaran), '... pero el albaran sigue en «Sin guardar»...');
        self::assertNotContains($idAlbaran, $this->albaranesFacturables($idCliente), '... y ya no se ofrece al facturar.');
    }

    /**
     * Lo mismo en el pedido: cancelar su edicion lo deja en «Sin guardar», y el albaran solo
     * ofrece para servir los pedidos en «Guardado» (`pedidosVentas.php:190`). El pedido deja de
     * poder servirse.
     *
     * @group defecto
     * @group pedido
     * @group borrador
     * @group estados
     * @group alto
     *
     * @que-ocurre-hoy Abrir un pedido guardado para editarlo, cambiar algo y cancelar lo deja en
     *   «Sin guardar», y desde ese momento no se ofrece al hacer el albaran del cliente.
     * @que-deberia-ocurrir Que cancelar deje el pedido como estaba antes de editarlo.
     * @por-que-ocurre El estado del pedido lo cambia el navegador al primer cambio, y la
     *   cancelacion solo borra el borrador.
     * @como-deberia-funcionar Que la cancelacion devuelva el pedido a su estado anterior, o que el
     *   estado del documento real no cambie mientras la edicion no se guarde.
     *
     * @codigo-afectado modulos/mod_venta/funciones.php:198-222
     */
    public function test_defecto_cancelarLaEdicionDeUnPedidoGuardadoLoDejaSinPoderServirse(): void
    {
        $idCliente = $this->siembra->cliente('Cliente de pedido con edicion cancelada');
        $idArticulo = $this->siembra->articulo('Producto de pedido con edicion cancelada');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-05', ['idCliente' => $idCliente]);
        self::assertContains($idPedido, $this->pedidosServibles($idCliente), 'Punto de partida: se ofrece para servir.');

        $idTemporal = $this->editar('pedido', $idPedido, $idArticulo, $idCliente);
        $this->despachar(['pulsado' => 'cancelarTemporal', 'dedonde' => 'pedido', 'idTemporal' => $idTemporal]);

        self::assertSame([], $this->pedidos->TodosTemporal($idPedido), 'El borrador ya no existe...');
        self::assertSame('Sin guardar', $this->pedidos->getEstado($idPedido), '... pero el pedido sigue en «Sin guardar»...');
        self::assertNotContains($idPedido, $this->pedidosServibles($idCliente), '... y ya no se ofrece para servir.');
    }

    // --- Editar desde una pantalla abierta antes ------------------------------

    /**
     * Un albaran facturado pierde su estado si se edita desde una pantalla abierta antes de
     * facturarlo.
     *
     * La pantalla no deja editar un albaran «Procesado», pero lo comprueba al cargarse y solo
     * entonces (`albaran.php:300-308`). Si la pantalla de edicion ya estaba abierta cuando el
     * albaran se facturo —otra pestana, otro puesto—, el primer cambio crea el borrador sin que
     * el servidor mire el estado, y el navegador escribe «Sin guardar» encima de «Procesado».
     * Guardar ya no llega a borrar nada: la pantalla encuentra la factura y lo impide. Pero el
     * albaran se queda en «Sin guardar» con su factura, en un estado que nada deshace.
     *
     * @group defecto
     * @group albaran
     * @group borrador
     * @group estados
     * @group medio
     *
     * @que-ocurre-hoy Un albaran ya facturado se puede seguir editando desde una pantalla abierta
     *   antes de facturarlo, y al primer cambio deja de constar como facturado.
     * @que-deberia-ocurrir Que el servidor no admita el borrador de un albaran facturado, y que
     *   nada le quite la marca de facturado mientras su factura exista.
     * @por-que-ocurre La unica comprobacion de que el albaran no esta facturado la hace la
     *   pantalla al cargarse. Ni la creacion del borrador ni el cambio de estado consultan el
     *   estado del albaran.
     * @como-deberia-funcionar Comprobar el estado en el servidor al crear el borrador y al
     *   cambiar el estado.
     *
     * @codigo-afectado modulos/mod_venta/clases/albaranesVentas.php:306-319
     * @codigo-afectado modulos/mod_venta/tareas.php:136-155
     */
    public function test_defecto_unAlbaranFacturadoEditadoDesdeUnaPantallaAnteriorDejaDeConstarComoFacturado(): void
    {
        $idCliente = $this->siembra->cliente('Cliente de albaran facturado reabierto');
        $idArticulo = $this->siembra->articulo('Producto de albaran facturado reabierto');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10', ['idCliente' => $idCliente]);
        $idFactura = $this->siembra->facturarAlbaranCliente($idAlbaran);
        self::assertSame('Procesado', $this->albaranes->getEstado($idAlbaran), 'Punto de partida: facturado.');

        $this->editar('albaran', $idAlbaran, $idArticulo, $idCliente);

        self::assertCount(1, $this->albaranes->TodosTemporal($idAlbaran), 'El servidor admite el borrador del albaran facturado...');
        self::assertSame('Sin guardar', $this->albaranes->getEstado($idAlbaran), '... que deja de constar como facturado...');
        self::assertSame($idFactura, $this->facturaQueIncluye($idAlbaran), '... aunque su factura lo siga incluyendo.');
    }

    /**
     * Lo mismo en el pedido ya servido: editado desde una pantalla abierta antes de servirlo,
     * deja de constar como servido y se queda en «Sin guardar» con su albaran.
     *
     * @group defecto
     * @group pedido
     * @group borrador
     * @group estados
     * @group medio
     *
     * @que-ocurre-hoy Un pedido ya servido se puede seguir editando desde una pantalla abierta
     *   antes de servirlo, y al primer cambio deja de constar como servido.
     * @que-deberia-ocurrir Que el servidor no admita el borrador de un pedido servido.
     * @por-que-ocurre La unica comprobacion de que el pedido no esta servido la hace la pantalla
     *   al cargarse.
     * @como-deberia-funcionar Comprobar el estado en el servidor al crear el borrador y al
     *   cambiar el estado.
     *
     * @codigo-afectado modulos/mod_venta/clases/pedidosVentas.php:283-296
     * @codigo-afectado modulos/mod_venta/tareas.php:136-155
     */
    public function test_defecto_unPedidoServidoEditadoDesdeUnaPantallaAnteriorDejaDeConstarComoServido(): void
    {
        $idCliente = $this->siembra->cliente('Cliente de pedido servido reabierto');
        $idArticulo = $this->siembra->articulo('Producto de pedido servido reabierto');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-05', [
            'idCliente' => $idCliente, 'estado' => 'Procesado',
        ]);
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10', ['idCliente' => $idCliente]);
        $this->siembra->adjuntarPedidoAAlbaran($idAlbaran, $idPedido);

        $this->editar('pedido', $idPedido, $idArticulo, $idCliente);

        self::assertCount(1, $this->pedidos->TodosTemporal($idPedido), 'El servidor admite el borrador del pedido servido...');
        self::assertSame('Sin guardar', $this->pedidos->getEstado($idPedido), '... que deja de constar como servido...');
        self::assertNotSame([], $this->pedidos->NumAlbaranDePedido($idPedido), '... aunque su albaran lo siga sirviendo.');
    }

    // --- Apoyos ----------------------------------------------------------------

    private function despachar(array $post): mixed
    {
        return DespachoTareas::invocar(self::RUTA_TAREAS, $this->db, $post);
    }

    /**
     * Las dos peticiones del primer cambio sobre un documento guardado, como las hace el
     * navegador: el borrador atado al documento y el paso de su estado a «Sin guardar».
     *
     * El borrador se crea con los dos metodos que `tareas/AddTemporal.php` invoca, en su orden,
     * y no despachando `anhadirTemporal`: `tareas.php` carga ese fichero con `include_once`, de
     * modo que en un mismo proceso solo se ejecuta la primera vez que se pide. El cambio de
     * estado si va por el despacho real, porque su caso esta escrito dentro de `tareas.php`.
     */
    private function editar(string $dedonde, int $idDocumento, int $idArticulo, int $idCliente): int
    {
        $clase = $dedonde === 'albaran' ? $this->albaranes : $this->pedidos;
        $productos = json_decode(json_encode([[
            'idArticulo' => $idArticulo, 'cref' => 'REF', 'ccodbar' => '', 'cdetalle' => 'Linea editada',
            'ncant' => 1, 'nunidades' => 1, 'precioCiva' => 1.21, 'iva' => 21, 'pvpSiva' => 1.0,
            'estadoLinea' => 'Activo', 'nfila' => 1,
        ]]));

        $idTemporal = (int) $clase->insertarDatosTemporal(
            $this->siembra->usuarioPorDefecto(),
            $this->siembra->tiendaPorDefecto(),
            '2026-02-15',
            [],
            $productos,
            $idCliente
        )['id'];
        $clase->addNumRealTemporal($idTemporal, $idDocumento);

        $this->despachar([
            'pulsado' => 'modificarEstadoDocumento', 'dedonde' => $dedonde, 'idModificar' => $idDocumento, 'estado' => 'Sin guardar',
        ]);

        return $idTemporal;
    }

    /** La factura que incluye al albaran, leida por su identificador y no por su numero. */
    private function facturaQueIncluye(int $idAlbaran): int
    {
        return (int) $this->db->query("SELECT idFactura FROM albclifac WHERE idAlbaran = {$idAlbaran}")->fetch_row()[0];
    }

    /** @return int[] Los albaranes que la factura ofrece para incorporar a ese cliente. */
    private function albaranesFacturables(int $idCliente): array
    {
        return array_map('intval', array_column($this->albaranes->AlbaranClienteGuardado(0, $idCliente)['datos'] ?? [], 'id'));
    }

    /** @return int[] Los pedidos que el albaran ofrece para servir a ese cliente. */
    private function pedidosServibles(int $idCliente): array
    {
        return array_map('intval', array_column($this->pedidos->PedidosClienteGuardado(0, $idCliente)['datos'] ?? [], 'id'));
    }
}
