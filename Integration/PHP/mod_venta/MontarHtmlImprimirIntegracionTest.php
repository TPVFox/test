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

    // --- El alto de la cabecera frente a donde empieza el cuerpo ----------------

    /**
     * Donde empieza el cuerpo del impreso: el margen superior que `tareas.php` fija en el caso
     * `datosImprimir` (`$margen_top_caja_texto`), en milimetros.
     */
    private const INICIO_DEL_CUERPO_MM = 56;

    /**
     * Con datos de longitud normal, la cabecera termina antes de donde empieza el cuerpo.
     *
     * Por muy poco: medido, termina a 55,9 mm, una decima antes de los 56 del cuerpo. El margen
     * esta ajustado a una cabecera en la que no se parte ninguna linea.
     *
     * @group control
     * @group pedido
     * @group impreso
     *
     * @para-que-sirve Acompana al caso de la direccion larga: demuestra que la medida da margen
     *   con datos normales, de modo que lo que aquel caso mide es solo el efecto de la direccion.
     */
    public function test_conUnaDireccionNormalLaCabeceraCabeAntesDelCuerpo(): void
    {
        $cabecera = $this->cabeceraDeUnPedidoConDireccion('Calle Mayor 1');

        self::assertLessThan(self::INICIO_DEL_CUERPO_MM, $this->finDeLaCabeceraMm($cabecera));
    }

    /**
     * Una direccion de cliente larga hace que la cabecera se monte sobre las lineas del impreso.
     *
     * La cabecera se pinta en la cabecera de pagina de TCPDF desde 3 mm, con el alto que le de
     * su contenido. El cuerpo empieza siempre a 56 mm, un margen fijo (`tareas.php:169`) que no
     * mira cuanto ha ocupado la cabecera. Una direccion que ocupa varias lineas la alarga por debajo de esos
     * 56 mm, y las primeras lineas del documento se escriben encima. Lo describe el issue
     * TPVFox #116, punto 5, para los tres documentos.
     *
     * Medido: una direccion de 85 caracteres lleva el final de la cabecera a 59,9 mm, y con nombre,
     * razon social y direccion a su longitud maxima llega a 74,5 mm. La cabecera es la misma para
     * pedido, albaran y factura, de modo que el caso se mide sobre un pedido.
     *
     * @group defecto
     * @group pedido
     * @group impreso
     * @group medio
     *
     * @que-ocurre-hoy Si la direccion del cliente es larga, la cabecera del impreso se monta sobre
     *   las primeras lineas del documento.
     * @que-deberia-ocurrir Que el cuerpo empiece siempre debajo de la cabecera, ocupe lo que ocupe.
     * @por-que-ocurre El cuerpo empieza a un margen fijo de 56 mm, y la cabecera tiene el alto que
     *   le dan sus datos.
     * @como-deberia-funcionar Calcular el margen superior a partir del alto real de la cabecera, o
     *   acotar el alto de la cabecera para que nunca pase de ese margen.
     *
     * @codigo-afectado modulos/mod_venta/funciones.php:754-782
     */
    public function test_defecto_conUnaDireccionLargaLaCabeceraSeMontaSobreElCuerpo(): void
    {
        // 85 caracteres: cabe de sobra en la columna, que admite 100.
        $cabecera = $this->cabeceraDeUnPedidoConDireccion(
            'Poligono Industrial A Granxa, Parcela 47, Nave 12, Calle Transportistas s/n, Porrino'
        );

        self::assertGreaterThan(
            self::INICIO_DEL_CUERPO_MM,
            $this->finDeLaCabeceraMm($cabecera),
            'La cabecera termina por debajo de donde empieza el cuerpo'
        );
    }

    /** La cabecera impresa de un pedido cuyo cliente tiene esa direccion. */
    private function cabeceraDeUnPedidoConDireccion(string $direccion): string
    {
        $idCliente = $this->siembra->cliente('Cliente de impreso con direccion');
        $sentencia = $this->db->prepare('UPDATE clientes SET direccion = ? WHERE idClientes = ?');
        $sentencia->bind_param('si', $direccion, $idCliente);
        $sentencia->execute();

        $idArticulo = $this->siembra->articulo('Articulo de impreso con direccion');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-10', ['idCliente' => $idCliente]);

        return \montarHTMLimprimir($idPedido, $this->db, 'pedido', $this->datosTienda)['cabecera'];
    }

    /**
     * Hasta donde llega la cabecera en la pagina, en milimetros: se pinta como la pinta
     * `clases/imprimir.php` —una celda HTML desde 3 mm, en A4 vertical— y se lee donde queda.
     */
    private function finDeLaCabeceraMm(string $cabecera): float
    {
        require_once RUTA_TPVFOX . '/lib/tcpdf/tcpdf.php';
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(20, 3, 20);
        $pdf->AddPage();
        $pdf->writeHTMLCell(0, 0, '', 3, $cabecera, 0, 1);

        return $pdf->GetY();
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
