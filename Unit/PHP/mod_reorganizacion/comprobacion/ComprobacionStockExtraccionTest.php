<?php
/**
 * Composición de la trayectoria, mapeo de incidencias y las tres decisiones que
 * anotan condiciones: lógica pura, sin base de datos. Cálculo, no lectura — lo que
 * depende de leer la base tiene su propio caso de integración, y aquí se prueban los
 * límites que allí saldrían caros de sembrar.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModReorganizacion\Comprobacion;

use PHPUnit\Framework\TestCase;

final class ComprobacionStockExtraccionTest extends TestCase
{
    private static function instancia(): \ClaseComprobacionStockExtraccion
    {
        global $RutaServidor, $HostNombre, $URLCom;
        require_once RUTA_TPVFOX . '/modulos/mod_reorganizacion/clases/ClaseComprobacionStockExtraccion.php';
        return new \ClaseComprobacionStockExtraccion();
    }

    public function test_T1_sinMovimientosArrancaEnElSaldoDePartida(): void
    {
        $comprobacion = self::instancia();

        $trayectorias = $comprobacion->componerTrayectoria(
            [1],
            [1 => ['saldo_acumulado' => 5.0]],
            [],
            false
        );

        self::assertSame(5.0, $trayectorias[1]['saldoAlCorte']);
        self::assertSame(5.0, $trayectorias[1]['minimoAlcanzado']);
        self::assertNull($trayectorias[1]['fechaMinimo']);
    }

    public function test_T2_conMovimientosSumaPartidaMasDeltaYPartidaMasMinimo(): void
    {
        $comprobacion = self::instancia();

        $movimientos = [
            ['tipo_movimiento' => 'salida_ticket', 'idArticulo' => 1, 'nunidades' => 15, 'fecha' => '2026-01-05'],
            ['tipo_movimiento' => 'entrada_proveedor', 'idArticulo' => 1, 'nunidades' => 3, 'fecha' => '2026-01-10'],
        ];

        $trayectorias = $comprobacion->componerTrayectoria(
            [1],
            [1 => ['saldo_acumulado' => 10.0]],
            $movimientos,
            false
        );

        self::assertSame(-2.0, $trayectorias[1]['saldoAlCorte']);
        self::assertSame(-5.0, $trayectorias[1]['minimoAlcanzado']);
        self::assertSame(10.0, $trayectorias[1]['saldoDeApertura']);
        self::assertSame('2026-01-05', $trayectorias[1]['fechaMinimo']);
    }

    public function test_T3_unProductoSinSaldoDePartidaArrancaEnCeroYNoQuedaFuera(): void
    {
        $comprobacion = self::instancia();
        $movimientos = [
            ['tipo_movimiento' => 'salida_ticket', 'idArticulo' => 1, 'nunidades' => 4, 'fecha' => '2026-01-05'],
        ];

        // Que no haya fila de saldo de partida significa que no hubo movimiento en el
        // borde del ejercicio, no que el producto no se examine: arranca en cero y su
        // trayectoria se compone igual que la de los demás.
        $trayectorias = $comprobacion->componerTrayectoria([1], [], $movimientos, false);

        self::assertArrayHasKey(1, $trayectorias);
        self::assertSame(0.0, $trayectorias[1]['saldoDeApertura']);
        self::assertSame(-4.0, $trayectorias[1]['saldoAlCorte']);
        self::assertSame(-4.0, $trayectorias[1]['minimoAlcanzado']);
    }

    public function test_T4_modoEstrictoTruncaElSaldoDePartidaACero(): void
    {
        $comprobacion = self::instancia();
        $movimientos = [
            ['tipo_movimiento' => 'salida_ticket', 'idArticulo' => 1, 'nunidades' => 10, 'fecha' => '2026-01-05'],
        ];
        $stockBase = [1 => ['saldo_acumulado' => 50.0]];

        $modoNormal = $comprobacion->componerTrayectoria([1], $stockBase, $movimientos, false);
        $modoEstricto = $comprobacion->componerTrayectoria([1], $stockBase, $movimientos, true);

        self::assertSame(40.0, $modoNormal[1]['saldoAlCorte']);
        self::assertSame(-10.0, $modoEstricto[1]['saldoAlCorte']);
    }

    public function test_T5_elMapeoSoloConservaArticuloYTipo(): void
    {
        $comprobacion = self::instancia();
        $incidencias = [
            [
                'idArticulo' => 7,
                'tipo' => 'Inventario en negativo',
                'severidad' => 'CRITICA',
                'fraccionado_es_causa' => true,
                'posible_causa' => 'algo',
            ],
        ];

        $mapeado = $comprobacion->mapearIncidencias($incidencias);

        self::assertSame(['7' => 'Inventario en negativo'], $mapeado);
    }

    public function test_T6_soloSalenLasTrayectoriasQueLlegaronAValorNegativo(): void
    {
        $comprobacion = self::instancia();

        // El cero no es negativo: un producto que se quedó justo a cero no llegó a
        // deber existencias, y no es lo que se examina.
        $enNegativo = $comprobacion->conTrayectoriaEnNegativo([
            10 => ['minimoAlcanzado' => -0.5],
            11 => ['minimoAlcanzado' => 0.0],
            12 => ['minimoAlcanzado' => 3.0],
        ]);

        self::assertSame([10], $enNegativo);
    }

    public function test_T7_sinNingunaTrayectoriaEnNegativoNoSaleNingunProducto(): void
    {
        $comprobacion = self::instancia();

        $enNegativo = $comprobacion->conTrayectoriaEnNegativo([
            10 => ['minimoAlcanzado' => 2.0],
            11 => ['minimoAlcanzado' => 0.0],
        ]);

        self::assertSame([], $enNegativo);
    }

    public function test_T8_nuncaIncluidosEnElCierreSonLosQueElCierreNoHabriaTomado(): void
    {
        $comprobacion = self::instancia();

        $nunca = $comprobacion->nuncaIncluidosEnElCierre([10, 11, 12], [11]);

        self::assertSame([10, 12], $nunca);
    }

    public function test_T9_siElCierreNoHabriaTomadoNingunoTodosQuedanAnotados(): void
    {
        $comprobacion = self::instancia();

        // La lista vacía no significa «no se pudo mirar»: significa que ninguno tenía
        // existencias positivas, y entonces la condición aplica a todos.
        $nunca = $comprobacion->nuncaIncluidosEnElCierre([10, 11], []);

        self::assertSame([10, 11], $nunca);
    }

    public function test_T10_elMinimoDentroDeLaVentanaMarcaElPeriodoComoNoConsolidado(): void
    {
        $comprobacion = self::instancia();

        // Ventana de 7 días contada hacia atrás desde el corte: el día 16 es el borde y
        // entra; el 15 ya queda fuera.
        self::assertTrue($comprobacion->periodoNoConsolidado('2026-01-20', 7, '2026-01-23'));
        self::assertTrue($comprobacion->periodoNoConsolidado('2026-01-16', 7, '2026-01-23'));
        self::assertFalse($comprobacion->periodoNoConsolidado('2026-01-15', 7, '2026-01-23'));
    }

    public function test_T11_sinVentanaOSinMinimoElPeriodoNuncaSeMarca(): void
    {
        $comprobacion = self::instancia();

        // ventana_dias = 0 es «sin restricción», no «ventana de cero días».
        self::assertFalse($comprobacion->periodoNoConsolidado('2026-01-23', 0, '2026-01-23'));
        self::assertFalse($comprobacion->periodoNoConsolidado(null, 7, '2026-01-23'));
    }

    public function test_T12_elMinimoIncluyeElPuntoDePartidaYNoSoloElRecorrido(): void
    {
        $comprobacion = self::instancia();

        // Solo entradas: la curva nunca baja de donde empezó, así que el mínimo es el
        // propio saldo de partida. Tomándolo del primer movimiento saldría por encima de
        // la apertura, y un producto que abre debiendo existencias se quedaría fuera.
        $trayectorias = $comprobacion->componerTrayectoria(
            [1],
            [1 => ['saldo_acumulado' => -3.0]],
            [['tipo_movimiento' => 'entrada_proveedor', 'idArticulo' => 1, 'nunidades' => 10, 'fecha' => '2026-03-01']],
            false
        );

        self::assertSame(-3.0, $trayectorias[1]['minimoAlcanzado']);
        self::assertSame(7.0, $trayectorias[1]['saldoAlCorte']);
        self::assertNull($trayectorias[1]['fechaMinimo'], 'Ningún movimiento del periodo explica el mínimo');
    }
}
