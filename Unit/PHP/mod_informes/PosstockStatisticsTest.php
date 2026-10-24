<?php

/**
 * La estadística de POSStock (`modulos/mod_informes/clases/PosstockStatistics.php`): el cuantil de la
 * normal y de la gamma, los tres modelos de rotura por días sin venta y la elección automática entre
 * ellos, y la estadística descriptiva —suelos, correlación, regresión y parecido de nombres—.
 *
 * Son funciones estáticas sin base ni estado: se cargan tal cual. Las roturas se calculan con un final
 * de periodo pasado, para que la hora actual no cuente.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModInformes;

use PHPUnit\Framework\TestCase;
use PosstockStatistics;

final class PosstockStatisticsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once RUTA_TPVFOX . '/modulos/mod_informes/clases/PosstockStatistics.php';
    }

    /** @return array<string, int> Un día con venta por cada fecha, del inicio al fin, cada tantos días. */
    private function dias(string $inicio, string $fin, int $paso = 1): array
    {
        $fechas = [];
        for ($t = strtotime($inicio); $t <= strtotime($fin); $t += $paso * 86400) {
            $fechas[date('Y-m-d', $t)] = 1;
        }

        return $fechas;
    }

    /** @return list<array{0: string, 1: float, 2: int, 3: string}> El modelo, el umbral, los días y la severidad de cada rotura. */
    private function resumen(array $roturas): array
    {
        return array_map(static fn($r) => [$r['modelo_usado'], $r['umbral_dias'], $r['dias_rotura'], $r['severidad']], $roturas);
    }

    /** @comportamiento El cuantil de la normal estándar: 0 en la mediana, ±1,96 y −2,33 en las colas, y finito en los extremos. */
    public function test_elCuantilDeLaNormal(): void
    {
        self::assertEqualsWithDelta(0.0, PosstockStatistics::normalQuantile(0.5), 1e-12);
        self::assertEqualsWithDelta(1.959964, PosstockStatistics::normalQuantile(0.975), 1e-6);
        self::assertEqualsWithDelta(-1.959964, PosstockStatistics::normalQuantile(0.025), 1e-6);
        self::assertEqualsWithDelta(-2.326348, PosstockStatistics::normalQuantile(0.01), 1e-6);
        self::assertEqualsWithDelta(-7.94, PosstockStatistics::normalQuantile(0.0), 0.01);
        self::assertEqualsWithDelta(7.94, PosstockStatistics::normalQuantile(1.0), 0.01);
    }

    /** @comportamiento El cuantil de la gamma por Wilson-Hilferty, a menos del 1 % del real; con forma o escala nulas, cero. */
    public function test_elCuantilDeLaGamma(): void
    {
        self::assertEqualsWithDelta(8 * (35 / 36) ** 3, PosstockStatistics::gammaQuantileWH(4.0, 2.0, 0.5), 1e-9);
        self::assertEqualsWithDelta(7.3441, PosstockStatistics::gammaQuantileWH(4.0, 2.0, 0.5), 7.3441 * 0.01);
        self::assertSame(0.0, PosstockStatistics::gammaQuantileWH(0.0, 2.0, 0.5));
        self::assertSame(0.0, PosstockStatistics::gammaQuantileWH(4.0, 0.0, 0.5));
    }

    /** @comportamiento La media, la desviación típica muestral y el tamaño de unos suelos; vacíos, ceros. */
    public function test_lasEstadisticasDeLosSuelos(): void
    {
        self::assertEquals([4, 2.0, 3], PosstockStatistics::statsFloors([2, 4, 6]));
        self::assertEquals([5, 0.0, 1], PosstockStatistics::statsFloors([5]));
        self::assertSame([0.0, 0.0, 0], PosstockStatistics::statsFloors([]));
    }

    /** @comportamiento La correlación de Pearson: 1 y −1 en las rectas; 0 con una serie constante, con menos de dos puntos o con longitudes distintas. */
    public function test_laCorrelacion(): void
    {
        self::assertEqualsWithDelta(1.0, PosstockStatistics::pearsonCorr([1, 2, 3, 4], [2, 4, 6, 8]), 1e-12);
        self::assertEqualsWithDelta(-1.0, PosstockStatistics::pearsonCorr([1, 2, 3, 4], [8, 6, 4, 2]), 1e-12);
        self::assertSame(0.0, PosstockStatistics::pearsonCorr([1, 2, 3], [5, 5, 5]));
        self::assertSame(0.0, PosstockStatistics::pearsonCorr([1], [1]));
        self::assertSame(0.0, PosstockStatistics::pearsonCorr([1, 2, 3], [1, 2]));
    }

    /**
     * @comportamiento La regresión sobre la posición: la recta exacta creciente con p 0,01 y sin error; una
     *   tendencia clara con p 0,05; una sin tendencia con p 0,15; con menos de tres puntos, nada.
     */
    public function test_laRegresion(): void
    {
        self::assertEquals(['slope' => 2.0, 'r2' => 1.0, 'p_value' => 0.01, 'se' => 0.0], PosstockStatistics::regressionStats([1, 3, 5, 7]));
        $tendencia = PosstockStatistics::regressionStats([1, 2, 2, 4, 5, 5, 7, 8]);
        self::assertEqualsWithDelta(1.0, $tendencia['slope'], 1e-12);
        self::assertEqualsWithDelta(0.965517, $tendencia['r2'], 1e-6);
        self::assertSame(0.05, $tendencia['p_value']);
        self::assertEqualsWithDelta(0.077152, $tendencia['se'], 1e-6);
        self::assertSame(0.15, PosstockStatistics::regressionStats([5, 1, 5, 1, 5, 1])['p_value']);
        self::assertSame(1.0, PosstockStatistics::regressionStats([7, 5, 3, 1])['p_value']);
        self::assertSame(['slope' => 0.0, 'r2' => 0.0, 'p_value' => 1.0, 'se' => 0.0], PosstockStatistics::regressionStats([1, 2]));
    }

    /** @comportamiento El parecido de dos nombres por sus palabras, sin mayúsculas: las comunes entre todas; vacío, cero. */
    public function test_elParecidoDeDosNombres(): void
    {
        self::assertEqualsWithDelta(1 / 3, PosstockStatistics::jaccardNombre('Pan Blanco', 'pan integral'), 1e-12);
        self::assertSame(1.0, PosstockStatistics::jaccardNombre('Leche Entera', 'leche  entera'));
        self::assertSame(0.0, PosstockStatistics::jaccardNombre('', 'pan'));
    }

    /**
     * @comportamiento El modelo gamma ajusta los huecos entre ventas y declara rotura el que pasa del cuantil:
     *   aquí, el de diez días, con su umbral y su inicio; con menos ventas que el mínimo, nada; con huecos
     *   iguales, la media más tres desviaciones, que es la normal.
     */
    public function test_elModeloGamma(): void
    {
        $fechas = array_fill_keys(['2025-03-01', '2025-03-03', '2025-03-05', '2025-03-08', '2025-03-10', '2025-03-12', '2025-03-22', '2025-03-24'], 1);
        $roturas = PosstockStatistics::roturaGamma(7, $fechas, 5.0, strtotime('2025-03-31'), 4, 0.05);

        self::assertSame([['Gamma', 9.1, 10, 'MEDIA']], $this->resumen($roturas));
        self::assertSame(['2025-03-12', '2025-03-22', 3.3, false], [$roturas[0]['ultima_venta'], $roturas[0]['fecha_inicio_rotura'], $roturas[0]['avg_dias_entre_ventas'], $roturas[0]['ko']]);
        self::assertSame([], PosstockStatistics::roturaGamma(7, $fechas, 5.0, strtotime('2025-03-31'), 9, 0.05));
        self::assertSame('Normal', PosstockStatistics::roturaGamma(7, $this->dias('2025-03-01', '2025-03-21', 2), 5.0, strtotime('2025-03-31'), 4, 0.05)[0]['modelo_usado']);
    }

    /**
     * @comportamiento La rotura en curso va desde la última venta hasta el final del periodo: sin stock,
     *   crítica y agotada; con stock, alta si los días confirmados pasan del umbral.
     */
    public function test_laRoturaEnCurso(): void
    {
        $fechas = array_fill_keys(['2025-03-01', '2025-03-03', '2025-03-05', '2025-03-08', '2025-03-10', '2025-03-12', '2025-03-22', '2025-03-24'], 1);

        $sinStock = PosstockStatistics::roturaGamma(7, $fechas, 0.0, strtotime('2025-04-20'), 4, 0.05);
        $conStock = PosstockStatistics::roturaGamma(7, $fechas, 3.0, strtotime('2025-04-20'), 4, 0.05);

        self::assertSame(['2025-04-03', null, 27, 'CRITICA', true, true], [$sinStock[1]['fecha_inicio_rotura'], $sinStock[1]['fecha_fin_rotura'], $sinStock[1]['dias_rotura'], $sinStock[1]['severidad'], $sinStock[1]['cr'], $sinStock[1]['ko']]);
        self::assertSame(['ALTA', false], [$conStock[1]['severidad'], $conStock[1]['ko']]);
    }

    /**
     * @comportamiento El modelo de Poisson declara rotura el hueco que pasa de −ln(p)/λ; con las ventas en
     *   rachas, la binomial negativa.
     */
    public function test_elModeloDePoissonYLaBinomialNegativa(): void
    {
        $esporadicas = $this->dias('2025-01-01', '2025-03-31', 10);
        $rachas = $this->dias('2025-01-05', '2025-01-10') + $this->dias('2025-02-05', '2025-02-10') + $this->dias('2025-03-05', '2025-03-10');

        $poisson = PosstockStatistics::roturaPoisson(2, $esporadicas, 0.0, strtotime('2025-06-30'), 4, 0.05, 181);

        self::assertSame('Poisson', $poisson[0]['modelo_usado']);
        self::assertEqualsWithDelta(-log(0.05) / (count($esporadicas) / 181), $poisson[0]['umbral_dias'], 0.05);
        self::assertSame([['BN', 76.1, 112, 'CRITICA']], $this->resumen(PosstockStatistics::roturaPoisson(3, $rachas, 0.0, strtotime('2025-06-30'), 4, 0.05, 181)));
        self::assertSame([], PosstockStatistics::roturaPoisson(3, $rachas, 0.0, strtotime('2025-06-30'), 30, 0.05, 181));
    }

    /**
     * @comportamiento La elección automática: con venta casi diaria y huecos irregulares, Poisson; con
     *   rachas, la binomial negativa; esporádica y regular, Poisson; el resto, gamma.
     */
    public function test_laEleccionAutomaticaDelModelo(): void
    {
        $diaria = $this->dias('2025-03-01', '2025-03-31');
        foreach (['2025-03-15', '2025-03-16', '2025-03-17', '2025-03-18', '2025-03-19'] as $hueco) {
            unset($diaria[$hueco]);
        }
        $rachas = $this->dias('2025-01-05', '2025-01-10') + $this->dias('2025-02-05', '2025-02-10') + $this->dias('2025-03-05', '2025-03-10');
        $cadaTres = array_fill_keys(['2025-02-01', '2025-02-04', '2025-02-07', '2025-02-11', '2025-02-14', '2025-02-17', '2025-02-20', '2025-02-24', '2025-02-27', '2025-03-02', '2025-03-05', '2025-03-09', '2025-03-12', '2025-03-15', '2025-03-18', '2025-03-22', '2025-03-25', '2025-03-28'], 1);

        self::assertSame([['Poisson', 3.6, 6, 'MEDIA']], $this->resumen(PosstockStatistics::autoDispatch(1, $diaria, 3.0, strtotime('2025-03-31'), 4, 0.05, 31, false)));
        self::assertSame('BN', PosstockStatistics::autoDispatch(3, $rachas, 0.0, strtotime('2025-06-30'), 4, 0.05, 181, false)[0]['modelo_usado']);
        self::assertSame('Poisson', PosstockStatistics::autoDispatch(2, $this->dias('2025-01-01', '2025-03-31', 10), 0.0, strtotime('2025-06-30'), 4, 0.05, 181, false)[0]['modelo_usado']);
        self::assertSame('Gamma', PosstockStatistics::autoDispatch(4, $cadaTres, 3.0, strtotime('2025-03-31'), 4, 0.05, 59, false)[0]['modelo_usado']);
        self::assertSame([], PosstockStatistics::autoDispatch(4, $cadaTres, 3.0, strtotime('2025-03-31'), 30, 0.05, 59, false));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_informes/clases/PosstockStatistics.php:665-673
     *
     * @group defecto
     * @group robustez
     * @group bajo
     *
     * @que-ocurre-hoy Una palabra repetida en el primer nombre se cuenta como común tantas veces como
     *   aparece: «Pan pan» frente a «pan» da 2, el doble del máximo.
     * @que-deberia-ocurrir Que el parecido esté entre 0 y 1, y que dos nombres con las mismas palabras den 1.
     * @por-que-ocurre La intersección se cuenta sobre la lista con repetidas, y la unión sin ellas.
     * @como-deberia-funcionar Contar las dos sobre las palabras sin repetir.
     */
    public function test_elParecidoNoPasaDeUno(): void
    {
        self::assertLessThanOrEqual(1.0, PosstockStatistics::jaccardNombre('Pan pan', 'pan'));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_informes/clases/PosstockStatistics.php:134, :171, :197, :311, :335, :398
     *
     * @group defecto
     * @group robustez
     * @group bajo
     *
     * @que-ocurre-hoy Con la hora de España, un hueco entre ventas que cruza el cambio de hora de marzo
     *   cuenta un día menos en los tres modelos: del 14 de marzo al 3 de abril salen 19 días, no 20. Los
     *   días se sacan dividiendo los segundos entre 86.400 y truncando, y ese día tiene 23 horas.
     * @que-deberia-ocurrir Que los días entre dos fechas sean los del calendario, sea cual sea la zona.
     * @por-que-ocurre Se restan marcas de tiempo locales y se trunca la división.
     * @como-deberia-funcionar Contar los días con la diferencia de fechas.
     */
    public function test_unHuecoQueCruzaElCambioDeHoraCuentaSusDias(): void
    {
        $zona = date_default_timezone_get();
        date_default_timezone_set('Europe/Madrid');
        try {
            $fechas = array_fill_keys(['2025-03-01', '2025-03-03', '2025-03-05', '2025-03-08', '2025-03-10', '2025-03-12', '2025-03-14', '2025-04-03'], 1);
            $dias = array_column(PosstockStatistics::roturaGamma(7, $fechas, 5.0, strtotime('2025-04-05'), 4, 0.05), 'dias_rotura');
        } finally {
            date_default_timezone_set($zona);
        }

        self::assertSame([20], $dias);
    }
}
