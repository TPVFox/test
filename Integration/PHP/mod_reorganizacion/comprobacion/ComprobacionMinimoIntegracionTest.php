<?php
/**
 * Reconstrucción del ejercicio anterior: los dos traspasos —identificados por el
 * proveedor que declara el fichero admitido— quedan fuera de la ventana; sin ninguna
 * recepción no hay lote que reconstruir; el margen solo cuenta ventas de los lotes
 * contados en productos que se registran por peso.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModReorganizacion\Comprobacion;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra;

final class ComprobacionMinimoIntegracionTest extends CasoIntegracion
{
    protected string $ejercicio = 'anterior';

    protected bool $compartirConexionConElProducto = true;

    private Siembra $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siembra = new Siembra($this->db);
        $this->incluirTPVFox('/modulos/mod_reorganizacion/clases/ClaseComprobacionMinimo.php');
    }

    private function contexto(array $cambios = []): array
    {
        return array_merge([
            'ano' => '2025',
            'idTienda' => $this->siembra->tiendaPorDefecto(),
        ], $cambios);
    }

    private function filaDe(int $idArticulo): array
    {
        return ['idArticulo' => $idArticulo, 'condicionesConocidas' => []];
    }

    public function test_T1_elStockJustificadoExcluyeLosDosTraspasosYCortaEnElUltimoLoteNegativo(): void
    {
        $proveedorTraspaso = $this->siembra->proveedor('Proveedor de cierre');
        $proveedorHabitual = $this->siembra->proveedor('Proveedor habitual');
        $idArticulo = $this->siembra->articulo('Producto con lote negativo intermedio');

        // Los dos traspasos: si no quedaran fuera, el stock justificado saldría de
        // otra magnitud por completo, o el cierre lo dejaría en cero o negativo.
        $this->siembra->entradaProveedor($idArticulo, 999.0, '2025-01-01', ['idProveedor' => $proveedorTraspaso, 'estado' => 'Importado']);
        $this->siembra->entradaProveedor($idArticulo, -999.0, '2025-12-31', ['idProveedor' => $proveedorTraspaso, 'estado' => 'Guardado']);

        // Lote ancla: entra 24, sale 25 -> balance -1.
        $this->siembra->entradaProveedor($idArticulo, 24.0, '2025-06-25', ['idProveedor' => $proveedorHabitual]);
        $this->siembra->ventaTicket($idArticulo, 25.0, '2025-07-15');

        // Lote contado: entra 24, sale 14 -> balance +10.
        $this->siembra->entradaProveedor($idArticulo, 24.0, '2025-11-12', ['idProveedor' => $proveedorHabitual]);
        $this->siembra->ventaTicket($idArticulo, 14.0, '2025-12-01');

        $comprobacion = new \ClaseComprobacionMinimo();
        $resultado = $comprobacion->calcular([$this->filaDe($idArticulo)], $this->contexto(), $proveedorTraspaso);

        self::assertSame(10.0, $resultado[0]['stockJustificado']);
    }

    public function test_T2_sinNingunaRecepcionSaleSinStockJustificadoConLaCondicion(): void
    {
        $proveedorTraspaso = $this->siembra->proveedor('Proveedor de cierre');
        $idArticulo = $this->siembra->articulo('Producto sin recepciones en el anterior');

        $this->siembra->ventaTicket($idArticulo, 3.0, '2025-04-01');

        $comprobacion = new \ClaseComprobacionMinimo();
        $resultado = $comprobacion->calcular([$this->filaDe($idArticulo)], $this->contexto(), $proveedorTraspaso);

        self::assertNull($resultado[0]['stockJustificado']);
        self::assertContains('historico_incompleto', $resultado[0]['condicionesConocidas']);
    }

    public function test_T3_elMargenDeUnProductoDePesoCuentaLasVentasDelPeriodoReconstruido(): void
    {
        $proveedorTraspaso = $this->siembra->proveedor('Proveedor de cierre');
        $proveedorHabitual = $this->siembra->proveedor('Proveedor habitual');
        $idArticulo = $this->siembra->articulo('Producto de peso', ['tipo' => 'peso']);

        $this->siembra->entradaProveedor($idArticulo, 30.0, '2025-03-01', ['idProveedor' => $proveedorHabitual]);
        $this->siembra->ventaTicket($idArticulo, 5.0, '2025-03-02');
        $this->siembra->ventaTicket($idArticulo, 5.0, '2025-03-03');
        $this->siembra->ventaTicket($idArticulo, 5.0, '2025-03-04');

        $comprobacion = new \ClaseComprobacionMinimo();
        $resultado = $comprobacion->calcular([$this->filaDe($idArticulo)], $this->contexto(), $proveedorTraspaso);

        self::assertSame(max(0.5, 0.010 * 3), $resultado[0]['margen']);
    }

    public function test_T4_elMargenDeUnProductoQueNoSeRegistraPorPesoEsSiempreCero(): void
    {
        $proveedorTraspaso = $this->siembra->proveedor('Proveedor de cierre');
        $proveedorHabitual = $this->siembra->proveedor('Proveedor habitual');
        $idArticulo = $this->siembra->articulo('Producto por unidad', ['tipo' => 'unidad']);

        $this->siembra->entradaProveedor($idArticulo, 10.0, '2025-03-01', ['idProveedor' => $proveedorHabitual]);
        $this->siembra->ventaTicket($idArticulo, 5.0, '2025-03-02');

        $comprobacion = new \ClaseComprobacionMinimo();
        $resultado = $comprobacion->calcular([$this->filaDe($idArticulo)], $this->contexto(), $proveedorTraspaso);

        self::assertSame(0.0, $resultado[0]['margen']);
    }

    public function test_T5_unaSalidaPorAlbaranDeClienteSeRestaDelBalanceDelLote(): void
    {
        $proveedorTraspaso = $this->siembra->proveedor('Proveedor de cierre');
        $proveedorHabitual = $this->siembra->proveedor('Proveedor habitual');
        $idArticulo = $this->siembra->articulo('Producto con salida por albaran de cliente');

        $this->siembra->entradaProveedor($idArticulo, 20.0, '2025-03-01', ['idProveedor' => $proveedorHabitual]);
        $this->siembra->ventaAlbaranCliente($idArticulo, 5.0, '2025-03-05');

        $comprobacion = new \ClaseComprobacionMinimo();
        $resultado = $comprobacion->calcular([$this->filaDe($idArticulo)], $this->contexto(), $proveedorTraspaso);

        self::assertSame(15.0, $resultado[0]['stockJustificado']);
    }

    public function test_T6_unProductoSinFilaEnArticulosSaleHistoricoIncompletoConMargenPorDefecto(): void
    {
        // Es el caso de un producto no comparable: no existe en el catálogo de este
        // ejercicio, así que tampoco tiene movimientos ni tipo que consultar.
        $proveedorTraspaso = $this->siembra->proveedor('Proveedor de cierre');

        $comprobacion = new \ClaseComprobacionMinimo();
        $resultado = $comprobacion->calcular([$this->filaDe(999999)], $this->contexto(), $proveedorTraspaso);

        self::assertNull($resultado[0]['stockJustificado']);
        self::assertContains('historico_incompleto', $resultado[0]['condicionesConocidas']);
    }
}
