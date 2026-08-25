<?php
/**
 * Formación de lotes y stock justificado: lógica pura, sin base de datos. El
 * recorrido hacia atrás se detiene en el primer lote negativo sin incluirlo; una
 * devolución a proveedor no abre lote, se resta del balance del lote en curso.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModReorganizacion\Comprobacion;

use PHPUnit\Framework\TestCase;

final class ComprobacionMinimoTest extends TestCase
{
    private static function instancia(): \ClaseComprobacionMinimo
    {
        global $RutaServidor, $HostNombre, $URLCom;
        require_once RUTA_TPVFOX . '/modulos/mod_reorganizacion/clases/ClaseComprobacionMinimo.php';
        return new \ClaseComprobacionMinimo();
    }

    private function movimiento(string $fecha, float $delta, string $tipo): array
    {
        return ['fecha' => $fecha, 'delta' => $delta, 'tipo' => $tipo];
    }

    public function test_T1_elStockJustificadoSumaSoloLosLotesPosterioresAlUltimoNegativo(): void
    {
        $comprobacion = self::instancia();

        // Caso calculado a mano, igual que el ejemplo de la especificación: el lote
        // del 25-06 al 11-11 cierra en -1 (ancla); el del 12-11 al cierre, en +10.
        $movimientos = [
            $this->movimiento('2025-06-25', 24.0, 'recepcion'),
            $this->movimiento('2025-07-15', -25.0, 'venta'),
            $this->movimiento('2025-11-12', 24.0, 'recepcion'),
            $this->movimiento('2025-12-01', -14.0, 'venta'),
        ];

        $resultado = $comprobacion->justificar($movimientos, 'unidad');

        self::assertSame(10.0, $resultado['stockJustificado']);
    }

    public function test_T2_unaDevolucionAProveedorNoAbreLoteYSeRestaDelLoteEnCurso(): void
    {
        $comprobacion = self::instancia();

        // El caso que motivó la regla: 10, -2, 5, sin ninguna venta alrededor. Si la
        // devolución abriera lote, el balance -2 sería un lote negativo y cortaría el
        // recorrido en 5, en vez de sumar las tres entradas.
        $movimientos = [
            $this->movimiento('2025-03-01', 10.0, 'recepcion'),
            $this->movimiento('2025-03-05', -2.0, 'devolucion'),
            $this->movimiento('2025-03-10', 5.0, 'recepcion'),
        ];

        $resultado = $comprobacion->justificar($movimientos, 'unidad');

        self::assertSame(13.0, $resultado['stockJustificado']);
    }

    public function test_T3_sinNingunaRecepcionNoHayLoteYSaleSinStockJustificadoConLaCondicion(): void
    {
        $comprobacion = self::instancia();

        $movimientos = [
            $this->movimiento('2025-05-01', -3.0, 'venta'),
        ];

        $resultado = $comprobacion->justificar($movimientos, 'unidad');

        self::assertNull($resultado['stockJustificado']);
        self::assertContains('historico_incompleto', $resultado['condicionesConocidas']);
    }

    public function test_T4_elMargenSoloCuentaVentasDeLosLotesContadosYSoloEnProductoDePeso(): void
    {
        $comprobacion = self::instancia();

        // Lote ancla (negativo): tres ventas, no deben contar. Lote contado: dos
        // ventas, sí deben contar.
        $movimientos = [
            $this->movimiento('2025-01-10', 20.0, 'recepcion'),
            $this->movimiento('2025-01-11', -7.0, 'venta'),
            $this->movimiento('2025-01-12', -7.0, 'venta'),
            $this->movimiento('2025-01-13', -7.0, 'venta'),
            $this->movimiento('2025-06-01', 30.0, 'recepcion'),
            $this->movimiento('2025-06-02', -5.0, 'venta'),
            $this->movimiento('2025-06-03', -5.0, 'venta'),
        ];

        $peso = $comprobacion->justificar($movimientos, 'peso');
        $unidad = $comprobacion->justificar($movimientos, 'unidad');

        self::assertSame(20.0, $peso['stockJustificado']);
        self::assertSame(max(0.5, 0.010 * 2), $peso['margen']);
        self::assertSame(0.0, $unidad['margen']);
    }
}
