<?php

/**
 * `montarHTMLimprimir()` de `funciones.php` compone el documento imprimible (pedido,
 * albaran o factura) que `datosImprimir` envia a TCPDF. Es la unica funcion Integration/PHP
 * de `funciones.php` que consume las tres clases hermanas (`PedidosVentas`,
 * `AlbaranesVentas`, `FacturasVentas`) ademas de `Cliente`.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVenta;

use TPVFox\Test\CargaAislada;
use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class MontarHtmlImprimirIntegracionTest extends CasoIntegracion
{
    private Siembra $siembra;

    /** @var array<string,string> */
    private array $datosTienda;

    protected function setUp(): void
    {
        parent::setUp();
        CargaAislada::requerir(RUTA_TPVFOX . '/modulos/mod_venta/funciones.php');
        $this->incluirTPVFox('/clases/cliente.php');
        $this->incluirTPVFox('/modulos/mod_venta/clases/pedidosVentas.php');
        $this->incluirTPVFox('/modulos/mod_venta/clases/albaranesVentas.php');
        $this->incluirTPVFox('/modulos/mod_venta/clases/facturasVentas.php');

        $this->siembra = new Siembra($this->db);
        $this->datosTienda = [
            'NombreComercial' => 'Tienda',
            'razonsocial'     => 'Tienda SL',
            'direccion'       => 'Calle 1',
            'nif'             => 'B1',
            'telefono'        => '900',
        ];
    }

    public function test_pedido_componeCabeceraYDetalle(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo de pedido a imprimir');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 2.0, '2026-01-10');

        $resultado = \montarHTMLimprimir($idPedido, $this->db, 'pedido', $this->datosTienda);

        self::assertStringContainsString('Pedido de cliente', $resultado['cabecera']);
        self::assertStringContainsString('Articulo de pedido a imprimir', $resultado['html']);
    }

    public function test_albaran_componeCabeceraYDetalle(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo de albaran a imprimir');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 2.0, '2026-01-10');

        $resultado = \montarHTMLimprimir($idAlbaran, $this->db, 'albaran', $this->datosTienda);

        self::assertStringContainsString('Albarán de Cliente', $resultado['cabecera']);
        self::assertStringContainsString('Articulo de albaran a imprimir', $resultado['html']);
    }

    public function test_factura_componeCabeceraYDesglosaLosAlbaranesQueLaOrigina(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo de factura a imprimir');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 2.0, '2026-01-10');
        $idFactura = $this->siembra->facturarAlbaranCliente($idAlbaran, '2026-01-12');

        $resultado = \montarHTMLimprimir($idFactura, $this->db, 'factura', $this->datosTienda);

        self::assertStringContainsString('Factura de Cliente', $resultado['cabecera']);
        self::assertStringContainsString('Nun Alb', $resultado['html'], 'La factura debe declarar de que albaran viene cada bloque');
    }
}
