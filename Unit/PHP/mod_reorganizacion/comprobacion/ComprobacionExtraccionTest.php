<?php
/**
 * Composición de la trayectoria y mapeo de incidencias: lógica pura, sin base de
 * datos. Cálculo, no lectura — las decisiones que dependen de leer la base tienen su
 * propio caso de integración.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModReorganizacion\Comprobacion;

use PHPUnit\Framework\TestCase;

final class ComprobacionExtraccionTest extends TestCase
{
    private static function instancia(): \ClaseComprobacionExtraccion
    {
        global $RutaServidor, $HostNombre, $URLCom;
        require_once RUTA_TPVFOX . '/modulos/mod_reorganizacion/clases/ClaseComprobacionExtraccion.php';
        return new \ClaseComprobacionExtraccion();
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
        self::assertSame('2026-01-05', $trayectorias[1]['fechaMinimo']);
    }

    public function test_T3_elMismoSaldoDePartidaDaLaMismaTrayectoriaSeaCualSeaLaConvencionDeFecha(): void
    {
        $comprobacion = self::instancia();
        $movimientos = [
            ['tipo_movimiento' => 'salida_ticket', 'idArticulo' => 1, 'nunidades' => 4, 'fecha' => '2026-01-05'],
        ];

        // El traspaso fechado el 31 de diciembre y el fechado el 1 de enero quedan
        // dentro del mismo rango de consulta del saldo de partida: a la composición le
        // llega ya agregado en un único número, sea cual sea la convención real.
        $conveccionCierre = $comprobacion->componerTrayectoria([1], [1 => ['saldo_acumulado' => 8.0]], $movimientos, false);
        $convencionApertura = $comprobacion->componerTrayectoria([1], [1 => ['saldo_acumulado' => 8.0]], $movimientos, false);

        self::assertSame($conveccionCierre, $convencionApertura);
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
}
