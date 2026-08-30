<?php
/**
 * Regla de clasificación: sin correspondencia o sin stock justificado, no
 * comparable; si no, seguro, no seguro o dudoso según la diferencia entre la
 * existencia exigida y el stock justificado frente al margen. El marcado de
 * existencia negativa no se absorbe en el estado.
 *
 * La regla es ordenada y solo se observa en un cuadrante: una diferencia en contra
 * que cabe en el margen. Y la existencia exigida es la caída de la curva por debajo
 * de donde abrió, no el saldo de apertura declarado, que se cancela en la resta.
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

        // La existencia exigida se calcula fuera de la regla, y sale también aquí: es
        // una cantidad que el otro ejercicio estableció por sí solo, y no deja de
        // saberse porque en este no haya con qué compararla.
        self::assertSame(8.0, $resultado[0]['existenciaExigida']);
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

    public function test_T7_laDiferenciaEnContraQueCabeEnElMargenSigueSiendoSegura(): void
    {
        $clasificacion = self::instancia();

        // Existencia exigida = 8; justificado 8,3, margen 0,5: la diferencia va en
        // contra y cabe en el margen. Es el único cuadrante donde se ve el orden de la
        // regla: preguntar primero hacia qué lado cae la diferencia daría aquí «no
        // seguro» por tres décimas que el margen de pesaje existe justamente para
        // admitir.
        $resultado = $clasificacion->clasificar([$this->fila(['stockJustificado' => 8.3, 'margen' => 0.5])]);

        self::assertSame('seguro', $resultado[0]['estado']);
    }

    public function test_T8_laExistenciaExigidaEsLaCaidaDeLaCurvaYNoElSaldoDeAperturaDeclarado(): void
    {
        $clasificacion = self::instancia();

        // Dos productos que cayeron lo mismo —tres unidades por debajo de donde
        // abrieron— con saldos de apertura distintos. Lo que los movimientos exigen es
        // la caída, así que los dos han de exigir tres: el saldo declarado entra en los
        // dos términos de la resta y se cancela. El primero es el que lo demuestra,
        // porque su punto más bajo no llegó a negativo y sumarlo en valor absoluto
        // daría siete.
        $resultado = $clasificacion->clasificar([
            $this->fila(['idArticulo' => 1, 'minimoAlcanzado' => 2.0, 'saldoDeApertura' => 5.0]),
            $this->fila(['idArticulo' => 2, 'minimoAlcanzado' => -3.0, 'saldoDeApertura' => 0.0]),
        ]);

        self::assertSame(3.0, $resultado[0]['existenciaExigida']);
        self::assertSame(3.0, $resultado[1]['existenciaExigida']);
    }

    public function test_T9_elProductoQueNoSeRegistraPorPesoSeComparaPorIgualdadDeCantidad(): void
    {
        $clasificacion = self::instancia();

        // Sin registro por peso el margen es cero y la comparación es de igualdad, de
        // modo que la precisión con que llegan las dos cantidades decide el estado.
        // Abrió en 8,1 y bajó hasta 3,7: la caída es 4,4, que en coma flotante sale
        // 4,3999999999999995. Llevada a los decimales con que la cantidad existe es
        // 4,4, el mismo número que el justificado, y el producto sale seguro; sin ese
        // paso la diferencia sería una diezmilésima de billonésima y saldría dudoso.
        $resultado = $clasificacion->clasificar([
            $this->fila(['minimoAlcanzado' => 3.7, 'saldoDeApertura' => 8.1, 'stockJustificado' => 4.4, 'margen' => 0.0]),
        ]);

        self::assertSame(4.4, $resultado[0]['existenciaExigida']);
        self::assertSame('seguro', $resultado[0]['estado']);
    }
}
