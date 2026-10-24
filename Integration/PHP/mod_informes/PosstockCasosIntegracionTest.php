<?php

/**
 * Los casos de POSStock contra movimientos sembrados (`modulos/mod_informes/clases/ClasePosstock.php`
 * y sus detectores): para cada caso, un producto que lo produce y, donde la regla lo distingue, otro
 * que no; los casos que se piden sin decir cuáles, el orden de las incidencias y la revisión por lotes.
 *
 * La clase recibe la conexión de la prueba y lee lo sembrado en su transacción, que no se confirma. Los
 * movimientos van en 2019, un ejercicio sin datos en la base, y la revisión se acota a los productos
 * sembrados o a un proveedor sembrado. Las cascadas del C7 remuestrean al azar: el escenario del C7b
 * está lejos de los umbrales para que el resultado no dependa de ello.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModInformes;

use ClasePosstock;
use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class PosstockCasosIntegracionTest extends CasoIntegracion
{
    private Siembra $siembra;

    private ClasePosstock $posstock;

    protected function setUp(): void
    {
        parent::setUp();
        require_once RUTA_TPVFOX . '/modulos/mod_informes/clases/ClasePosstock.php';
        $this->siembra = new Siembra($this->db);
        $this->posstock = new ClasePosstock($this->db);
    }

    /**
     * La revisión de marzo de 2019, con el stock base de enero y febrero.
     *
     * @return array<string, mixed>
     */
    private function marzo(array $casos, array $otros = []): array
    {
        return ['fecha_inicio_movimientos' => '2019-03-01', 'fecha_fin_movimientos' => '2019-03-31', 'fecha_inicio_stock' => '2019-01-01', 'fecha_fin_stock' => '2019-02-28', 'casos_incluir' => $casos] + $otros;
    }

    /** @return list<array{0: int, 1: string, 2: string}> El producto, el tipo y la severidad de cada incidencia. */
    private function resumen(array $incidencias): array
    {
        return array_map(static fn($i) => [(int) $i['idArticulo'], $i['tipo'], $i['severidad']], $incidencias);
    }

    /** Un producto vendido a diario en marzo salvo del 15 al 24, con el stock que se le dé. */
    private function ventasConHueco(string $nombre, float $stock): int
    {
        $id = $this->siembra->articulo($nombre);
        for ($dia = 1; $dia <= 31; $dia++) {
            if ($dia < 15 || $dia > 24) {
                $this->siembra->ventaTicket($id, 1, sprintf('2019-03-%02d', $dia));
            }
        }
        $this->siembra->existenciaRegistrada($id, $stock);

        return $id;
    }

    /**
     * @comportamiento El C1 declara inventario en negativo, crítico, el producto que acaba el periodo por
     *   debajo de cero —sin recepciones, con esa causa—, y desajuste puntual, alto, el que baja de cero y se
     *   recupera; si la recepción llega al día siguiente del mínimo, la causa es el orden de registro.
     */
    public function test_elInventarioEnNegativoYElDesajustePuntual(): void
    {
        $negativo = $this->siembra->articulo('Zz Negativo');
        $this->siembra->entradaProveedor($negativo, 5, '2019-01-10');
        $this->siembra->ventaTicket($negativo, 4, '2019-03-05');
        $this->siembra->ventaTicket($negativo, 4, '2019-03-12');
        $puntual = $this->siembra->articulo('Zz Puntual');
        $this->siembra->ventaTicket($puntual, 3, '2019-03-05');
        $this->siembra->entradaProveedor($puntual, 10, '2019-03-06');

        $incidencias = $this->posstock->getIncidencias($this->marzo(['caso1'], ['ids_filter' => [$negativo, $puntual]]));

        self::assertSame([[$negativo, 'Inventario en negativo', 'CRITICA'], [$puntual, 'Desajuste Puntual de Stock', 'ALTA']], $this->resumen($incidencias));
        self::assertSame([-3.0, 'Ventas registradas sin ninguna recepción en el periodo: comprobar si falta dar entrada de mercancía', 'Zz Negativo'], [$incidencias[0]['stock_actual'], $incidencias[0]['posible_causa'], $incidencias[0]['nombre']]);
        self::assertSame([-3.0, '2019-03-05', true, 'Probable venta registrada antes que la recepción (timing de entrada)'], [$incidencias[1]['min_balance'], $incidencias[1]['fecha_minimo'], $incidencias[1]['timing_proximo'], $incidencias[1]['posible_causa']]);
    }

    /**
     * @comportamiento El C2 declara la entrada que llega con un stock previo de al menos la mitad de lo
     *   recibido; sin ventas, alta y sin cobertura. Si las ventas dan una cobertura de 21 días o menos, no.
     */
    public function test_laEntradaConStockAlto(): void
    {
        $parado = $this->siembra->articulo('Zz Parado');
        $vendido = $this->siembra->articulo('Zz Vendido');
        foreach ([$parado, $vendido] as $id) {
            $this->siembra->entradaProveedor($id, 20, '2019-01-10');
            $this->siembra->entradaProveedor($id, 10, '2019-03-10');
        }
        for ($dia = 1; $dia <= 30; $dia++) {
            $this->siembra->ventaTicket($vendido, 1, sprintf('2019-03-%02d', $dia));
        }

        $incidencias = $this->posstock->getIncidencias($this->marzo(['caso2'], ['ids_filter' => [$parado, $vendido]]));

        self::assertSame([[$parado, 'Entrada con stock alto', 'ALTA']], $this->resumen($incidencias));
        self::assertSame([20.0, 2.0, 'elevado', null, 'Sobrestock sin salida: el artículo no registra ventas en el periodo analizado'], [$incidencias[0]['stock_previo'], $incidencias[0]['ratio'], $incidencias[0]['c2_categoria'], $incidencias[0]['cobertura_dias'], $incidencias[0]['posible_causa']]);
    }

    /**
     * @comportamiento El C3 declara, en el año, el riesgo de caducidad del stock que lleva 24 semanas o más
     *   en almacén y la entrada sin rotación del que nunca se vendió.
     */
    public function test_laCaducidadTeoricaYLaEntradaSinRotacion(): void
    {
        $id = $this->siembra->articulo('Zz Conserva');
        $this->siembra->entradaProveedor($id, 10, '2019-02-01');
        $this->siembra->existenciaRegistrada($id, 10);

        $incidencias = $this->posstock->getIncidencias(['fecha_inicio_movimientos' => '2019-01-02', 'fecha_fin_movimientos' => '2019-12-31', 'fecha_inicio_stock' => '2018-12-31', 'fecha_fin_stock' => '2019-01-01', 'casos_incluir' => ['caso3a', 'caso3b'], 'ids_filter' => [$id]]);

        self::assertSame([[$id, 'Caducidad teórica', 'MEDIA'], [$id, 'Entrada sin rotación previa', 'BAJA']], $this->resumen($incidencias));
        self::assertSame([47.6, null], [$incidencias[0]['semanas_en_almacen'], $incidencias[0]['ultima_venta']]);
    }

    /**
     * @comportamiento El C4, solo si se pide, declara el stock sin ventas en el periodo: sin compras, por los
     *   días del periodo; con compras, como compras sin ventas. Un producto sin stock ni compras, no.
     */
    public function test_elStockInactivo(): void
    {
        $quieto = $this->siembra->articulo('Zz Quieto');
        $comprado = $this->siembra->articulo('Zz Comprado');
        $agotado = $this->siembra->articulo('Zz Agotado');
        $this->siembra->existenciaRegistrada($quieto, 5);
        $this->siembra->entradaProveedor($comprado, 6, '2019-03-10');

        $incidencias = $this->posstock->getIncidencias($this->marzo(['caso4'], ['ids_filter' => [$quieto, $comprado, $agotado]]));

        self::assertSame([[$comprado, 'Stock Inactivo en Periodo', 'ALTA'], [$quieto, 'Stock Inactivo en Periodo', 'MEDIA']], $this->resumen($incidencias));
        self::assertSame(['compras_sin_ventas', 'sin_movimiento', 31], [$incidencias[0]['subtipo'], $incidencias[1]['subtipo'], $incidencias[1]['dias_periodo']]);
        self::assertSame([], $this->posstock->getIncidencias($this->marzo([], ['ids_filter' => [$quieto]])));
    }

    /** @comportamiento El C5 declara la rotura del producto que deja de venderse más días de lo que su ritmo explica. */
    public function test_laVentaCero(): void
    {
        $id = $this->ventasConHueco('Zz Diario', 20);

        $incidencias = $this->posstock->getIncidencias($this->marzo(['caso5'], ['ids_filter' => [$id], 'fecha_inicio_stats' => '2019-03-01']));

        self::assertSame([[$id, 'Venta Cero (Posible Rotura Física)', 'MEDIA']], $this->resumen($incidencias));
        self::assertSame(['2019-03-14', '2019-03-25', 11], [$incidencias[0]['ultima_venta'], $incidencias[0]['fecha_fin_rotura'], $incidencias[0]['dias_rotura']]);
    }

    /** @comportamiento El C6a declara el agotamiento del producto cuyo stock no cubre la demanda hasta el próximo pedido. */
    public function test_elAgotamientoEstimado(): void
    {
        $id = $this->siembra->articulo('Zz Rápido');
        $this->siembra->existenciaRegistrada($id, 3);
        for ($dia = 1; $dia <= 31; $dia++) {
            $this->siembra->ventaTicket($id, 2, sprintf('2019-03-%02d', $dia));
        }

        $incidencias = $this->posstock->getIncidencias($this->marzo(['caso6a'], ['ids_filter' => [$id], 'fecha_inicio_stats' => '2019-03-01']));

        self::assertSame([[$id, 'Agotamiento Estimado', 'CRITICA']], $this->resumen($incidencias));
        self::assertSame([14, 'defecto', 'Agotamiento estimado antes del próximo pedido'], [$incidencias[0]['lead_time_dias'], $incidencias[0]['lead_time_fuente'], $incidencias[0]['posible_causa']]);
    }

    /**
     * @comportamiento El C7b declara la entrada no registrada del producto cuyo stock cae por debajo de
     *   cero antes de cada recepción, de forma repetida.
     */
    public function test_laEntradaNoRegistrada(): void
    {
        $id = $this->siembra->articulo('Zz Semanal');
        $this->siembra->ventaTicket($id, 3, '2019-01-02');
        foreach ([10, 11, 9, 10, 12, 9, 10, 11, 9, 10, 10, 11] as $semana => $vendido) {
            $lunes = strtotime('2019-01-07') + $semana * 7 * 86400;
            $this->siembra->entradaProveedor($id, 10, date('Y-m-d', $lunes));
            $this->siembra->ventaTicket($id, $vendido, date('Y-m-d', $lunes + 3 * 86400));
        }

        $incidencias = $this->posstock->getIncidencias($this->marzo(['caso7a', 'caso7b'], ['ids_filter' => [$id]]));

        self::assertSame([[$id, 'Entrada no registrada', 'MEDIA']], $this->resumen($incidencias));
        self::assertSame('C7b', $incidencias[0]['c7_subcaso']);
    }

    /** @comportamiento El C9 declara la merma de un producto de peso que recibe más de lo que vende entre recepciones. */
    public function test_laMermaPorLotes(): void
    {
        $id = $this->siembra->articulo('Zz Queso', ['tipo' => 'peso']);
        foreach (['2019-03-04', '2019-03-11', '2019-03-18', '2019-04-02'] as $fecha) {
            $this->siembra->entradaProveedor($id, 10, $fecha);
        }
        foreach (['2019-03-06' => 6, '2019-03-13' => 6, '2019-03-20' => 6, '2019-03-27' => 2] as $fecha => $vendido) {
            $this->siembra->ventaTicket($id, $vendido, $fecha);
        }
        $this->siembra->existenciaRegistrada($id, 10);

        $incidencias = $this->posstock->getIncidencias($this->marzo(['caso9'], ['ids_filter' => [$id]]));

        self::assertSame([[$id, 'Merma backstaging', 'ALTA']], $this->resumen($incidencias));
        self::assertEquals(10, $incidencias[0]['merma_total_kg']);
    }

    /**
     * @comportamiento Sin decir los casos se revisan todos menos el C4 —aquí, el negativo, la entrada con
     *   stock alto y su entrada sin rotación—, y las incidencias salen de la más grave a la menos, cada
     *   una con su clave de orden empezando por su severidad.
     */
    public function test_losCasosDePartidaYElOrden(): void
    {
        $negativo = $this->siembra->articulo('Zz Negativo');
        $this->siembra->ventaTicket($negativo, 4, '2019-03-05');
        $parado = $this->siembra->articulo('Zz Parado');
        $this->siembra->entradaProveedor($parado, 20, '2019-01-10');
        $this->siembra->entradaProveedor($parado, 10, '2019-03-10');
        $quieto = $this->siembra->articulo('Zz Quieto');
        $this->siembra->existenciaRegistrada($quieto, 5);

        $incidencias = $this->posstock->getIncidencias($this->marzo([], ['ids_filter' => [$negativo, $parado, $quieto]]));

        self::assertSame([[$negativo, 'Inventario en negativo', 'CRITICA'], [$parado, 'Entrada con stock alto', 'ALTA'], [$parado, 'Entrada sin rotación previa', 'BAJA']], $this->resumen($incidencias));
        self::assertSame(['0', '1', '3'], [$incidencias[0]['orden_clave'][0], $incidencias[1]['orden_clave'][0], $incidencias[2]['orden_clave'][0]]);
    }

    /**
     * @comportamiento La revisión por lotes de los productos de un proveedor da, lote a lote, las mismas
     *   incidencias que la revisión entera, sin repetir ni perder; un proveedor sin productos, nada.
     */
    public function test_laRevisionPorLotes(): void
    {
        $proveedor = $this->siembra->proveedor('Zz Frutas SL');
        $ids = [];
        foreach (['Zz Uno', 'Zz Dos', 'Zz Tres'] as $nombre) {
            $ids[] = $id = $this->siembra->articulo($nombre);
            $this->siembra->articuloProveedor($id, $proveedor, 1.0);
            $this->siembra->ventaTicket($id, 2, '2019-03-05');
        }
        $parametros = $this->marzo(['caso1'], ['proveedores_incluir' => [$proveedor]]);

        $entera = array_column($this->posstock->getIncidencias($parametros), 'idArticulo');
        $porLotes = [];
        $lotes = [];
        for ($inicial = 0; ; $inicial = $lote['actual']) {
            $lote = $this->posstock->getIncidenciasBatch($parametros, $inicial, 2);
            $lotes[] = $lote['elementos'];
            if ($lote['elementos'] === 0) {
                break;
            }
            array_push($porLotes, ...array_column($lote['filas'], 'idArticulo'));
        }

        self::assertEqualsCanonicalizing($ids, $entera);
        self::assertEqualsCanonicalizing($entera, $porLotes);
        self::assertSame([2, 1, 0], $lotes);
        self::assertSame([], $this->posstock->getIncidencias($this->marzo(['caso1'], ['proveedores_incluir' => [$this->siembra->proveedor('Zz Sin productos')]])));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_informes/clases/PosstockQueryRepository.php:930-995; modulos/mod_informes/clases/PosstockC1Detector.php:86-89
     *
     * @group defecto
     * @group integridad
     * @group alto
     *
     * @que-ocurre-hoy Un producto que empieza el periodo en negativo y recibe menos de lo que le falta
     *   acaba el periodo en negativo, pero no sale como inventario en negativo: la consulta solo trae los
     *   productos cuyo saldo del periodo, sin el de partida, baja de cero.
     * @que-deberia-ocurrir Que todo producto que acaba el periodo en negativo salga en el C1a, como «stock
     *   ya negativo al inicio».
     * @por-que-ocurre El filtro de candidatos mira los movimientos del periodo sin sumar el stock base.
     * @como-deberia-funcionar Elegir los candidatos con el stock base sumado.
     */
    public function test_unProductoQueEmpiezaEnNegativoSigueEnNegativo(): void
    {
        $id = $this->siembra->articulo('Zz Previo');
        $this->siembra->ventaTicket($id, 10, '2019-01-15');
        $this->siembra->entradaProveedor($id, 3, '2019-03-10');

        $incidencias = $this->posstock->getIncidencias($this->marzo(['caso1'], ['ids_filter' => [$id]]));

        self::assertSame([[$id, 'Inventario en negativo', 'ALTA']], $this->resumen($incidencias));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_informes/clases/PosstockQueryRepository.php:219-229; modulos/mod_informes/clases/PosstockC4Detector.php:56-69
     *
     * @group defecto
     * @group robustez
     * @group medio
     *
     * @que-ocurre-hoy El stock inactivo con un filtro de familias termina en un error de SQL: la consulta
     *   de los productos pega el filtro, que empieza por AND, justo después de la tabla, sin WHERE.
     * @que-deberia-ocurrir Que el C4 se filtre por familias como los demás casos.
     * @por-que-ocurre El filtro se escribe para ir detrás de otra condición, y aquí no la hay.
     * @como-deberia-funcionar Poner la condición de partida antes del filtro.
     */
    public function test_elStockInactivoSeFiltraPorFamilias(): void
    {
        $familia = $this->siembra->familia('Zz Despensa');
        $id = $this->siembra->articulo('Zz Arroz', ['familia' => $familia]);
        $this->siembra->existenciaRegistrada($id, 5);

        $incidencias = [];
        try {
            $incidencias = $this->posstock->getIncidencias($this->marzo(['caso4'], ['familias_incluir' => [$familia]]));
        } catch (\Throwable) {
        }

        self::assertSame([[$id, 'Stock Inactivo en Periodo', 'MEDIA']], $this->resumen($incidencias));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_informes/clases/ClasePosstock.php:650-661
     *
     * @group defecto
     * @group integridad
     * @group medio
     *
     * @que-ocurre-hoy La rotura de un producto con inventario en negativo no se marca como de stock no
     *   fiable: la marca busca el tipo «Rotura de Stock», y la rotura se llama «Venta Cero (Posible Rotura
     *   Física)».
     * @que-deberia-ocurrir Que las roturas y los agotamientos de un producto en negativo lleven la marca.
     * @por-que-ocurre El nombre del tipo de la lista no es el que pone el C5.
     * @como-deberia-funcionar Usar el nombre del tipo que pone el detector.
     */
    public function test_laRoturaDeUnProductoEnNegativoNoEsFiable(): void
    {
        $id = $this->ventasConHueco('Zz En negativo', -5);

        $incidencias = $this->posstock->getIncidencias($this->marzo(['caso1', 'caso5'], ['ids_filter' => [$id], 'fecha_inicio_stats' => '2019-03-01', 'c5_incluir_stock_negativo' => true]));
        $roturas = array_values(array_filter($incidencias, static fn($i) => $i['tipo'] === 'Venta Cero (Posible Rotura Física)'));

        self::assertCount(1, $roturas);
        self::assertTrue($roturas[0]['stock_no_fiable'] ?? false);
    }
}
