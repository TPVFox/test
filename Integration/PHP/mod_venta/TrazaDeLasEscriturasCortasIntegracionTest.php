<?php

/**
 * Lo que queda escrito de quién cambió un documento de venta, y cuándo, cuando el cambio no
 * pasa por el guardado.
 *
 * El guardado del pedido y de la factura escribe la fecha de modificación y conserva al creador.
 * Pero un documento también cambia por las escrituras cortas: el cambio de estado al servirlo,
 * al facturarlo, al empezar a editarlo o al cancelar. Esas escrituras tocan una sola columna y
 * no dejan rastro de cuándo ocurrieron ni de quién las pidió. Ninguna de las tres tablas de
 * cabecera tiene columna para quien modifica: solo para quien creó.
 *
 * El albarán no entra en estos casos porque su tabla no tiene columna de fecha de modificación
 * que medir (`AlbaranesVentasGuardadoIntegracionTest`).
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVenta;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class TrazaDeLasEscriturasCortasIntegracionTest extends CasoIntegracion
{
    private const FECHA_DE_MODIFICACION = '2026-01-15 10:00:00';

    private \PedidosVentas $pedidos;
    private \FacturasVentas $facturas;
    private Siembra $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_venta/clases/pedidosVentas.php');
        $this->incluirTPVFox('/modulos/mod_venta/clases/facturasVentas.php');
        $this->pedidos = new \PedidosVentas($this->db);
        $this->facturas = new \FacturasVentas($this->db);
        $this->siembra = new Siembra($this->db);
    }

    /**
     * Cambiar el estado de un pedido no cambia su fecha de modificación.
     *
     * @group defecto
     * @group pedido
     * @group estados
     * @group medio
     *
     * @que-ocurre-hoy Cambiar el estado de un pedido —al servirlo, al empezar a editarlo o al
     *   cancelar— no deja escrito cuándo ocurrió ni quién lo pidió.
     * @que-deberia-ocurrir Que todo cambio del documento deje constancia de cuándo se hizo y de
     *   quién lo hizo, no solo el guardado.
     * @por-que-ocurre La escritura del estado actualiza una sola columna. La fecha de modificación
     *   solo la escribe el guardado, y la tabla no tiene columna para quien modifica.
     * @como-deberia-funcionar Que el cambio de estado escriba la fecha de modificación y quién lo
     *   hizo, o que los cambios de estado queden en un registro propio.
     *
     * @codigo-afectado modulos/mod_venta/clases/pedidosVentas.php:157-170
     */
    public function test_defecto_cambiarElEstadoDeUnPedidoNoCambiaSuFechaDeModificacion(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de pedido con estado cambiado');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-15');
        $this->db->query("UPDATE pedclit SET fechaModificacion = '" . self::FECHA_DE_MODIFICACION . "' WHERE id = {$idPedido}");

        $this->pedidos->ModificarEstadoPedido($idPedido, 'Procesado');

        $fila = $this->db->query("SELECT estado, fechaModificacion FROM pedclit WHERE id = {$idPedido}")->fetch_assoc();
        self::assertSame('Procesado', $fila['estado'], 'El estado cambia...');
        self::assertSame(self::FECHA_DE_MODIFICACION, $fila['fechaModificacion'], '... y la fecha de modificación sigue siendo la anterior');
    }

    /**
     * Lo mismo en la factura: su cambio de estado no toca la fecha de modificación.
     *
     * @group defecto
     * @group factura
     * @group estados
     * @group medio
     *
     * @que-ocurre-hoy Cambiar el estado de una factura no deja escrito cuándo ocurrió ni quién lo
     *   pidió.
     * @que-deberia-ocurrir Que todo cambio del documento deje constancia de cuándo y de quién.
     * @por-que-ocurre La escritura del estado actualiza una sola columna; la fecha de modificación
     *   solo la escribe el guardado.
     * @como-deberia-funcionar Que el cambio de estado escriba la fecha de modificación y quién lo
     *   hizo, o que quede en un registro propio.
     *
     * @codigo-afectado modulos/mod_venta/clases/facturasVentas.php:440-452
     */
    public function test_defecto_cambiarElEstadoDeUnaFacturaNoCambiaSuFechaDeModificacion(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de factura con estado cambiado');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-15');
        $idFactura = $this->siembra->facturarAlbaranCliente($idAlbaran);
        $this->db->query("UPDATE facclit SET fechaModificacion = '" . self::FECHA_DE_MODIFICACION . "' WHERE id = {$idFactura}");

        $this->facturas->modificarEstado($idFactura, 'Sin guardar');

        $fila = $this->db->query("SELECT estado, fechaModificacion FROM facclit WHERE id = {$idFactura}")->fetch_assoc();
        self::assertSame('Sin guardar', $fila['estado'], 'El estado cambia...');
        self::assertSame(self::FECHA_DE_MODIFICACION, $fila['fechaModificacion'], '... y la fecha de modificación sigue siendo la anterior');
    }

    /**
     * Ninguna de las tres cabeceras tiene donde guardar quién modificó el documento: solo el
     * creador. Lo que el guardado conserva desde que dejó de sustituirlo.
     *
     * @group defecto
     * @group estados
     * @group medio
     *
     * @que-ocurre-hoy No se puede saber quién modificó un documento de venta, solo quién lo creó.
     * @que-deberia-ocurrir Que conste quién hizo la última modificación, además del creador.
     * @por-que-ocurre Las tres tablas tienen una sola columna de usuario, que guarda al creador.
     * @como-deberia-funcionar Una columna para quien modifica, o un registro de cambios del
     *   documento.
     */
    public function test_defecto_ningunaCabeceraTieneDondeGuardarQuienModificoElDocumento(): void
    {
        foreach (['pedclit', 'albclit', 'facclit'] as $tabla) {
            $usuarios = array_values(array_filter(
                array_column($this->db->query("SHOW COLUMNS FROM {$tabla}")->fetch_all(MYSQLI_ASSOC), 'Field'),
                static fn (string $columna): bool => stripos($columna, 'usuario') !== false
            ));
            self::assertSame(['idUsuario'], $usuarios, "{$tabla} tiene una sola columna de usuario: la del creador");
        }
    }
}
