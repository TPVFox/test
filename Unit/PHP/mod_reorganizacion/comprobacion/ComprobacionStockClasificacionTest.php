<?php
/**
 * Regla de clasificación: sin correspondencia o sin stock justificado, no
 * comparable; si no, seguro, no seguro o dudoso según la diferencia entre la
 * existencia exigida y el stock justificado frente al margen. El marcado de
 * existencia negativa no se absorbe en el estado.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModReorganizacion\Comprobacion;

use PHPUnit\Framework\TestCase;

final class ComprobacionStockClasificacionTest extends TestCase
{
    private static function instancia(): \ClaseComprobacionStockClasificacion
    {
        global $RutaServidor, $HostNombre, $URLCom;
        require_once RUTA_TPVFOX . '/modulos/mod_reorganizacion/clases/ClaseComprobacionStockClasificacion.php';
        return new \ClaseComprobacionStockClasificacion();
    }

    private function fila(array $cambios = []): array
    {
        return array_merge([
            'idArticulo' => 1,
            'comparable' => true,
            'minimoAlcanzado' => -3.0,
            'saldoDeApertura' => 5.0,
            'marcado' => false,
            'condicionesConocidas' => [],
            'stockJustificado' => 8.0,
            'margen' => 0.5,
        ], $cambios);
    }

    public function test_T1_sinCorrespondenciaSaleNoComparable(): void
    {
        $clasificacion = self::instancia();

        $resultado = $clasificacion->clasificar([$this->fila(['comparable' => false])]);

        self::assertSame('no_comparable', $resultado[0]['estado']);
    }

    public function test_T2_sinStockJustificadoEstablecidoSaleNoComparable(): void
    {
        $clasificacion = self::instancia();

        $resultado = $clasificacion->clasificar([$this->fila(['stockJustificado' => null])]);

        self::assertSame('no_comparable', $resultado[0]['estado']);
    }

    public function test_T3_laDiferenciaEnElLimiteExactoDelMargenSaleSegura(): void
    {
        $clasificacion = self::instancia();

        // Existencia exigida = |-3| + 5 = 8; justificado 7.5, margen 0.5: la
        // diferencia es exactamente el margen, y «no supera» incluye el límite.
        $resultado = $clasificacion->clasificar([$this->fila(['stockJustificado' => 7.5, 'margen' => 0.5])]);

        self::assertSame('seguro', $resultado[0]['estado']);
        self::assertSame(8.0, $resultado[0]['existenciaExigida']);
    }

    public function test_T4_laExigidaMenorQueElJustificadoFueraDeMargenSaleNoSegura(): void
    {
        $clasificacion = self::instancia();

        // Existencia exigida = 8; justificado 12, margen 1: exigida menor, fuera.
        $resultado = $clasificacion->clasificar([$this->fila(['stockJustificado' => 12.0, 'margen' => 1.0])]);

        self::assertSame('no_seguro', $resultado[0]['estado']);
    }

    public function test_T5_laExigidaMayorQueElJustificadoFueraDeMargenSaleDudosa(): void
    {
        $clasificacion = self::instancia();

        // Existencia exigida = 8; justificado 3, margen 1: exigida mayor, fuera.
        $resultado = $clasificacion->clasificar([$this->fila(['stockJustificado' => 3.0, 'margen' => 1.0])]);

        self::assertSame('dudoso', $resultado[0]['estado']);
    }

    public function test_T6_elMarcadoViajaIntactoConIndependenciaDelEstado(): void
    {
        $clasificacion = self::instancia();

        $resultado = $clasificacion->clasificar([$this->fila(['stockJustificado' => 7.5, 'margen' => 0.5, 'marcado' => true])]);

        self::assertSame('seguro', $resultado[0]['estado']);
        self::assertTrue($resultado[0]['marcado']);
    }
}
