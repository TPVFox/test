<?php

/**
 * `cancelarAlbaran()`, `cancelarFactura()` y `cancelarPedido()` de `funciones.php`:
 * eliminan el registro temporal de un documento sin guardar, y con un albaran o factura
 * devuelven los adjuntos (pedidos o albaranes) que llevaba a estado 'Guardado'.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVenta;

use TPVFox\Test\CargaAislada;
use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class CancelarDocumentosIntegracionTest extends CasoIntegracion
{
    private Siembra $siembra;
    private int $idArticulo;

    protected function setUp(): void
    {
        parent::setUp();
        CargaAislada::requerir(RUTA_TPVFOX . '/modulos/mod_venta/funciones.php');
        $this->incluirTPVFox('/modulos/mod_venta/clases/albaranesVentas.php');
        $this->incluirTPVFox('/modulos/mod_venta/clases/pedidosVentas.php');
        $this->incluirTPVFox('/modulos/mod_venta/clases/facturasVentas.php');

        $this->siembra = new Siembra($this->db);
        $this->idArticulo = $this->siembra->articulo('Articulo de cancelacion');
    }

    public function test_cancelarAlbaran_conIdTemporalCeroAvisaQueSoloSeCancelaLoTemporal(): void
    {
        $respuesta = \cancelarAlbaran(0, $this->db);

        self::assertSame('Info!', $respuesta['tipo']);
    }

    public function test_cancelarAlbaran_conTemporalRealLoElimina(): void
    {
        $idTemp = $this->siembra->albaranTemporal([['idArticulo' => $this->idArticulo, 'nunidades' => 1]]);

        $respuesta = \cancelarAlbaran($idTemp, $this->db);

        self::assertSame([], $respuesta, 'Sin error, cancelarAlbaran devuelve el array vacio con el que empezo');
        $fila = $this->db->query("SELECT COUNT(*) c FROM albcliltemporales WHERE id=$idTemp")->fetch_assoc();
        self::assertSame('0', $fila['c']);
    }

    public function test_cancelarFactura_conIdTemporalCeroAvisaQueSoloSeCancelaLoTemporal(): void
    {
        $respuesta = \cancelarFactura(0, $this->db);

        self::assertSame('Info!', $respuesta['tipo']);
    }

    public function test_cancelarFactura_conTemporalRealLoElimina(): void
    {
        $idTemp = $this->siembra->facturaTemporal([['idArticulo' => $this->idArticulo, 'nunidades' => 1]]);

        $respuesta = \cancelarFactura($idTemp, $this->db);

        self::assertSame([], $respuesta);
        $fila = $this->db->query("SELECT COUNT(*) c FROM faccliltemporales WHERE id=$idTemp")->fetch_assoc();
        self::assertSame('0', $fila['c']);
    }

    public function test_cancelarPedido_conIdTemporalCeroAvisaQueSoloSeCancelaLoTemporal(): void
    {
        $respuesta = \cancelarPedido(0, $this->db);

        self::assertSame('Info!', $respuesta['tipo']);
    }

    public function test_cancelarPedido_conTemporalRealLoElimina(): void
    {
        $idTemp = $this->siembra->pedidoTemporal([['idArticulo' => $this->idArticulo, 'nunidades' => 1]]);

        $respuesta = \cancelarPedido($idTemp, $this->db);

        self::assertSame([], $respuesta);
        $fila = $this->db->query("SELECT COUNT(*) c FROM pedcliltemporales WHERE id=$idTemp")->fetch_assoc();
        self::assertSame('0', $fila['c']);
    }
}
