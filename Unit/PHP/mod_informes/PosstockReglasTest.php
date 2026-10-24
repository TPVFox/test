<?php

/**
 * Las reglas de cada caso de POSStock que calculan sobre lo que reciben, sin la base
 * (`modulos/mod_informes/clases/`): la severidad del inventario en negativo (C1), la clasificación,
 * la acumulación y la tendencia de las entradas con stock alto (C2), las severidades del C3 y del C6,
 * las filas del stock inactivo (C4), la rotura por la media más tres desviaciones (C5), las cascadas y
 * las severidades del C7, los lotes, el reparto de los déficits, la merma y el plan mensual del C9, y
 * los parámetros de partida.
 *
 * Los detectores reciben la conexión al crearse, pero estas reglas no la usan: se crean sin
 * constructor. Las cascadas del C7 que remuestrean se prueban por los niveles que no lo hacen.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModInformes;

use PHPUnit\Framework\TestCase;
use PosstockC1Detector;
use PosstockC2Detector;
use PosstockC3Detector;
use PosstockC4Detector;
use PosstockC5Detector;
use PosstockC6Detector;
use PosstockC7aAnalyzer;
use PosstockC7bAnalyzer;
use PosstockC9Detector;
use PosstockParamsDTO;
use ReflectionClass;

final class PosstockReglasTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once RUTA_TPVFOX . '/modulos/mod_informes/clases/ClasePosstock.php';
    }

    /** Un detector sin la conexión, para sus reglas. */
    private function detector(string $clase): object
    {
        return (new ReflectionClass($clase))->newInstanceWithoutConstructor();
    }

    /**
     * @comportamiento El inventario en negativo es crítico; alto si ya venía negativo o si es un resto
     *   decimal pequeño —de pesaje—, que entonces se marca como causa.
     */
    public function test_laSeveridadDelInventarioEnNegativo(): void
    {
        $c1 = $this->detector(PosstockC1Detector::class);

        self::assertSame(['severidad' => 'CRITICA', 'fraccionado_es_causa' => false], $c1->calcularSignalesC1a(-3.0, -3.0, false));
        self::assertSame(['severidad' => 'ALTA', 'fraccionado_es_causa' => false], $c1->calcularSignalesC1a(-3.0, -3.0, true));
        self::assertSame(['severidad' => 'ALTA', 'fraccionado_es_causa' => true], $c1->calcularSignalesC1a(-0.2, -0.3, false));
        self::assertSame(['severidad' => 'CRITICA', 'fraccionado_es_causa' => false], $c1->calcularSignalesC1a(-1.2, -1.2, false));
    }

    /**
     * @comportamiento La entrada con stock alto se clasifica por la razón entre el stock previo y lo
     *   recibido: duplicado hasta 1, severo desde 6, elevado entre medias. Es acumulación con tres o más
     *   entradas y el 70 % sin ventas entre ellas; es tendencia si la cobertura crece al menos la mitad y
     *   acaba en 45 días o más.
     */
    public function test_lasReglasDeLaEntradaConStockAlto(): void
    {
        $c2 = $this->detector(PosstockC2Detector::class);
        $sinVenta = ['ventas_entre_recepciones' => 0.0, 'cobertura_dias' => 30];
        $conVenta = ['ventas_entre_recepciones' => 4.0, 'cobertura_dias' => 30];

        self::assertSame(['duplicado', 'elevado', 'severo'], [$c2->clasificarRatio(1.0, 1.0, 6.0)['categoria'], $c2->clasificarRatio(2.0, 1.0, 6.0)['categoria'], $c2->clasificarRatio(6.0, 1.0, 6.0)['categoria']]);
        self::assertTrue($c2->esAcumulacion([$sinVenta, $sinVenta, $sinVenta]));
        self::assertTrue($c2->esAcumulacion([$sinVenta, ['ventas_entre_recepciones' => null, 'cobertura_dias' => null], $sinVenta, $conVenta]));
        self::assertFalse($c2->esAcumulacion([$sinVenta, $conVenta, $conVenta]));
        self::assertFalse($c2->esAcumulacion([$sinVenta, $sinVenta]));
        self::assertTrue($c2->esTendenciaCreciente([30, 40, 50]));
        self::assertFalse($c2->esTendenciaCreciente([30, 40, 44]));
        self::assertFalse($c2->esTendenciaCreciente([40, 50, 59]));
        self::assertFalse($c2->esTendenciaCreciente([30, null, 50]));
    }

    /**
     * @comportamiento El riesgo de caducidad es alto con caída de rotación y medio sin ella; la entrada sin
     *   rotación es media con dos entradas, con devolución o al doble del umbral, y baja si no.
     */
    public function test_lasSeveridadesDelC3YDelC6(): void
    {
        $c3 = $this->detector(PosstockC3Detector::class);
        $c6 = $this->detector(PosstockC6Detector::class);

        self::assertSame(['ALTA', 'MEDIA'], [$c3->calcularSeveridadC3a(true), $c3->calcularSeveridadC3a(false)]);
        self::assertSame(['MEDIA', 'MEDIA', 'MEDIA', 'BAJA'], [$c3->calcularSeveridadC3b(2, false), $c3->calcularSeveridadC3b(1, true), $c3->calcularSeveridadC3b(1, false, 2.0), $c3->calcularSeveridadC3b(1, false, 1.9)]);
        self::assertSame(['CRITICA', 'ALTA', 'MEDIA'], [$c6->calcularSeveridadC6(5.0, 10.0, 20.0, 7), $c6->calcularSeveridadC6(8.0, 10.0, 20.0, 7), $c6->calcularSeveridadC6(8.0, 25.0, 20.0, 7)]);
    }

    /**
     * @comportamiento El stock inactivo sin compras es bajo hasta 30 días, medio hasta 120 y alto después;
     *   con compras sin ventas, medio hasta 30 y alto después, y su causa cuenta las recepciones.
     */
    public function test_lasFilasDelStockInactivo(): void
    {
        $c4 = $this->detector(PosstockC4Detector::class);
        $fila = static fn(string $tipo, int $dias) => ['saldo_acumulado' => 5.0, 'tipo_inactividad' => $tipo, 'n_recepciones' => 2, 'cantidad_recibida' => 12.5, 'ultima_recepcion' => '2025-03-10', 'dias_periodo' => $dias];

        $filas = $c4->formatearIncidencias([1 => $fila('sin_movimiento', 30), 2 => $fila('sin_movimiento', 31), 3 => $fila('sin_movimiento', 121), 4 => $fila('compras_sin_ventas', 30), 5 => $fila('compras_sin_ventas', 31)]);

        self::assertSame(['BAJA', 'MEDIA', 'ALTA', 'MEDIA', 'ALTA'], array_column($filas, 'severidad'));
        self::assertSame(['Stock Inactivo en Periodo', 'sin_movimiento', 5.0], [$filas[0]['tipo'], $filas[0]['subtipo'], $filas[0]['stock_actual']]);
        self::assertSame('2 recepciones (12,5 uds.) en 31 días sin ninguna venta — dinero inmovilizado, revisar proceso de pedido', $filas[4]['posible_causa']);
    }

    /** @comportamiento La rotura binomial declara el hueco que pasa de la media más tres desviaciones. */
    public function test_laRoturaPorLaMediaYLasDesviaciones(): void
    {
        $c5 = $this->detector(PosstockC5Detector::class);
        $fechas = [];
        for ($dia = 1; $dia <= 23; $dia += 2) {
            $fechas[sprintf('2025-03-%02d', $dia)] = 1;
        }
        $fechas['2025-04-04'] = 1;

        $roturas = $c5->calcularRoturas(7, $fechas, 4.0, strtotime('2025-04-05'), 4);

        self::assertSame([['Binomial', 11.5, 12, 'MEDIA', '2025-03-23']], array_map(static fn($r) => [$r['modelo_usado'], $r['umbral_dias'], $r['dias_rotura'], $r['severidad'], $r['ultima_venta']], $roturas));
        self::assertSame([], $c5->calcularRoturas(7, $fechas, 4.0, strtotime('2025-04-05'), 14));
    }

    /**
     * @comportamiento La cascada del C7a con suelos que crecen de forma sostenida confirma la merma en el
     *   primer nivel, con confianza alta; con menos de tres suelos o una variación por debajo del umbral,
     *   nada. Su severidad sale de la confianza y del periodo probado, y baja un nivel si la variación y la
     *   tendencia son pequeñas; el subcaso posible es siempre bajo.
     */
    public function test_laMermaAcumuladaDelC7a(): void
    {
        $c7a = new PosstockC7aAnalyzer();

        $sostenida = $c7a->analizarCascada([0.1, 0.2, 0.35, 0.5, 0.6, 0.8, 0.9, 1.1, 1.2, 1.4, 1.5, 1.7], 5.0);

        self::assertSame(['alta', 1], [$sostenida['confianza'], $sostenida['cascade_nivel']]);
        self::assertNull($c7a->analizarCascada([0.1, 0.2, 0.3], 1.0));
        self::assertNull($c7a->analizarCascada([0.1, 0.2], 5.0));
        self::assertSame(['ALTA', 'MEDIA', 'MEDIA', 'BAJA', 'BAJA'], [
            $c7a->calcularSeveridad('alta', 'both', 20.0, 3.0),
            $c7a->calcularSeveridad('alta', 'base', 20.0, 3.0),
            $c7a->calcularSeveridad('alta', null, 4.0, 1.0),
            $c7a->calcularSeveridad('posible', 'base', 20.0, 3.0),
            $c7a->calcularSeveridad('alta', 'both', 20.0, 3.0, true),
        ]);
    }

    /**
     * @comportamiento La cascada del C7b con suelos negativos y estables confirma la entrada no registrada
     *   por Wilcoxon, con confianza alta; con suelos positivos, nada. Su severidad sale de la confianza y la
     *   cobertura, y baja un nivel por magnitud pequeña y otro si menos de la mitad de los suelos son negativos.
     */
    public function test_laEntradaNoRegistradaDelC7b(): void
    {
        $c7b = new PosstockC7bAnalyzer();

        $estable = $c7b->analizarCascada([-3, -3.2, -2.9, -3.1, -3, -2.8], [7, 7, 7, 7, 7, 7], 3.0);

        self::assertSame(['wilcoxon_signed_rank', 'alta', false], [$estable['test_type'], $estable['confianza'], $estable['es_determinista']]);
        self::assertNull($c7b->analizarCascada([3, 2, 4, 3], [7, 7, 7, 7], 3.0));
        self::assertSame(['CRITICA', 'ALTA', 'MEDIA', 'BAJA'], [
            $c7b->calcularSeveridad('alta', 'A', 10.0, 80),
            $c7b->calcularSeveridad('alta', 'A', 3.0, 80),
            $c7b->calcularSeveridad('alta', 'A', 3.0, 40),
            $c7b->calcularSeveridad('posible', 'C', 3.0, 40),
        ]);
    }

    /**
     * @comportamiento Los lotes del C9 van de una recepción del periodo al día antes de la siguiente, con lo
     *   recibido, lo vendido y lo que sobra; el último se cierra con la primera recepción posterior y cuenta
     *   las ventas hasta ella. El déficit de un lote se reparte hacia el anterior, y la merma es lo que sobra
     *   en los lotes cerrados. Sin recepciones en el periodo, no hay lotes.
     */
    public function test_losLotesYLaMermaDelC9(): void
    {
        $c9 = $this->detector(PosstockC9Detector::class);
        $recepciones = [
            ['fecha' => '2025-03-05', 'cantidad' => 10, 'es_post_periodo' => false],
            ['fecha' => '2025-03-12', 'cantidad' => 10, 'es_post_periodo' => false],
            ['fecha' => '2025-03-20', 'cantidad' => 10, 'es_post_periodo' => false],
            ['fecha' => '2025-04-03', 'cantidad' => 10, 'es_post_periodo' => true],
        ];
        $salidas = [['fecha' => '2025-03-06', 'day_delta' => 3], ['fecha' => '2025-03-08', 'day_delta' => 4], ['fecha' => '2025-03-14', 'day_delta' => 12], ['fecha' => '2025-03-22', 'day_delta' => 5], ['fecha' => '2025-04-01', 'day_delta' => 2]];

        $lotes = $c9->calcularLotesC9($recepciones, $salidas, '2025-03-01', '2025-03-31');
        $repartidos = $c9->backstagingExponencial($lotes, 4, 0.15, 1.5, 'unidad');
        $merma = $c9->clasificarMermaC9($repartidos['lotes'], 3.0, 0.0, 'unidad', 1.0);

        self::assertSame([['2025-03-05', '2025-03-11', 10.0, 7.0, 3.0], ['2025-03-12', '2025-03-19', 10.0, 12.0, -2.0], ['2025-03-20', '2025-03-31', 10.0, 7.0, 3.0]], array_map(static fn($l) => [$l['fecha_ini'], $l['fecha_fin'], $l['E_t'], $l['V_t'], $l['S_t']], $lotes));
        self::assertEquals([[1], [0], [3]], array_map(static fn($l) => [$l['S_t']], $repartidos['lotes']));
        self::assertSame(1, $repartidos['n_deficit']);
        self::assertEquals([4, 2, 'BAJA'], [$merma['merma_total'], $merma['n_merma'], $merma['severidad_label']]);
        self::assertSame([], $c9->calcularLotesC9([['fecha' => '2025-04-03', 'cantidad' => 10, 'es_post_periodo' => true]], $salidas, '2025-03-01', '2025-03-31'));
    }

    /**
     * @comportamiento El plan mensual del C9 reparte la merma de cada lote cerrado por los días de cada mes,
     *   resta la ya declarada, aplica lo que el stock del mes admite y arrastra el resto; los lotes inciertos
     *   no entran.
     */
    public function test_elPlanMensualDeLaMerma(): void
    {
        $c9 = $this->detector(PosstockC9Detector::class);
        $lotes = [['fecha_ini' => '2025-03-20', 'fecha_fin' => '2025-04-08', 'merma_t' => 20.0, 'es_lote_incierto' => false], ['fecha_ini' => '2025-04-09', 'fecha_fin' => '2025-04-18', 'merma_t' => 5.0, 'es_lote_incierto' => true]];

        $limitado = $c9->calcularPlanMensual($lotes, ['2025-03' => 100.0, '2025-04' => 4.0]);
        $declarado = $c9->calcularPlanMensual($lotes, ['2025-03' => 100.0, '2025-04' => 100.0], 0.0, ['2025-04' => 3.0], 'unidad');

        self::assertEquals(['2025-03' => ['propuesto' => 12, 'aplicable' => 12, 'arrastre' => 0], '2025-04' => ['propuesto' => 8, 'aplicable' => 4, 'arrastre' => 4]], $limitado['plan']);
        self::assertEquals([20, 16, 4], [$limitado['total_propuesto'], $limitado['total_aplicable'], $limitado['arrastre_final']]);
        self::assertEquals([17, 17, 0], [$declarado['total_propuesto'], $declarado['total_aplicable'], $declarado['arrastre_final']]);
    }

    /**
     * @comportamiento Los parámetros de la revisión toman las fechas recibidas; sin el inicio del periodo,
     *   el de los movimientos, y sin la ventana estadística, del inicio del stock al final de los
     *   movimientos; las listas, vacías.
     */
    public function test_losParametrosDePartida(): void
    {
        $dto = PosstockParamsDTO::fromArray(['fecha_inicio_movimientos' => '2025-03-01', 'fecha_fin_movimientos' => '2025-03-31', 'fecha_inicio_stock' => '2025-01-01', 'fecha_fin_stock' => '2025-02-28', 'familias_incluir' => [3]]);

        self::assertSame(['2025-03-01', '2025-01-01', '2025-03-31', [3], [], [], false], [$dto->fecha_inicio_periodo, $dto->fecha_inicio_stats, $dto->fecha_fin_stats, $dto->familias_incluir, $dto->ids_filter, $dto->casos_incluir, $dto->proveedor_todos_productos]);
    }
}
