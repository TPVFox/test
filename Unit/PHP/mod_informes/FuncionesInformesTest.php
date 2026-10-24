<?php

/**
 * Las funciones de los informes (`modulos/mod_informes/funciones.php`): el cálculo de un periodo de
 * POSStock —sus fechas de movimientos y de stock base, su etiqueta, su total de periodos y el mínimo de
 * ventas del caso C5— para cada tipo de periodo, y el título y el rango de fechas de un informe.
 *
 * El fichero no consulta la base ni incluye nada: se carga tal cual.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModInformes;

use PHPUnit\Framework\TestCase;

final class FuncionesInformesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once RUTA_TPVFOX . '/modulos/mod_informes/funciones.php';
    }

    /** @return list<string> Las fechas de movimientos y de stock base, y las etiquetas. */
    private function fechas(?array $periodo): array
    {
        return [$periodo['fecha_inicio_movimientos'], $periodo['fecha_fin_movimientos'], $periodo['fecha_inicio_stock'], $periodo['fecha_fin_stock'], $periodo['label_movimientos'], $periodo['label_stock']];
    }

    /**
     * @comportamiento La semana va anclada al 1 de enero: la primera, del 1 de enero al primer domingo; las
     *   demás, de lunes a domingo, y la última se corta el 31 de diciembre. En 2025, 53 semanas.
     */
    public function test_lasSemanasDelAnio(): void
    {
        $primera = calcularPeriodoPosstock('semana', 1, 2025);
        $segunda = calcularPeriodoPosstock('semana', 2, 2025);
        $ultima = calcularPeriodoPosstock('semana', 53, 2025);

        self::assertSame(['2025-01-02', '2025-01-05', '2024-12-31', '2025-01-01', 'Semana 1 (01 Ene – 05 Ene 2025)', 'Stock base: 31 Dic 2024 – 01 Ene 2025'], $this->fechas($primera));
        self::assertSame(['2025-01-06', '2025-01-12', '2025-01-01', '2025-01-05', 'Semana 2 (06 Ene – 12 Ene 2025)', 'Stock base: 01 Ene 2025 – 05 Ene 2025'], $this->fechas($segunda));
        self::assertSame(['2025-12-29', '2025-12-31'], [$ultima['fecha_inicio_movimientos'], $ultima['fecha_fin_movimientos']]);
        self::assertSame([53, 4, '2025-01-01'], [$primera['total_periodos'], $primera['min_ventas_c5'], $primera['fecha_inicio_periodo']]);
    }

    /** @comportamiento La quincena impar es del 1 al 15; la par, del 16 al último día del mes. */
    public function test_lasQuincenas(): void
    {
        self::assertSame(['2025-02-01', '2025-02-15', '2025-01-01', '2025-01-31', '1ª quincena Feb 2025', 'Stock base: 01 Ene 2025 – 31 Ene 2025'], $this->fechas(calcularPeriodoPosstock('quincena', 3, 2025)));
        self::assertSame(['2025-02-16', '2025-02-28'], array_slice($this->fechas(calcularPeriodoPosstock('quincena', 4, 2025)), 0, 2));
        self::assertSame(24, calcularPeriodoPosstock('quincena', 4, 2025)['total_periodos']);
    }

    /** @comportamiento El mes, el trimestre, el cuatrimestre y el semestre, con su último día; febrero de un año bisiesto, hasta el 29. */
    public function test_losMesesYSusAgrupaciones(): void
    {
        self::assertSame(['2024-02-01', '2024-02-29', 'Febrero 2024', 12, 7], [calcularPeriodoPosstock('mes', 2, 2024)['fecha_inicio_movimientos'], calcularPeriodoPosstock('mes', 2, 2024)['fecha_fin_movimientos'], calcularPeriodoPosstock('mes', 2, 2024)['label_movimientos'], calcularPeriodoPosstock('mes', 2, 2024)['total_periodos'], calcularPeriodoPosstock('mes', 2, 2024)['min_ventas_c5']]);
        self::assertSame(['2025-04-01', '2025-06-30', 'T2 2025', 4], [calcularPeriodoPosstock('trimestre', 2, 2025)['fecha_inicio_movimientos'], calcularPeriodoPosstock('trimestre', 2, 2025)['fecha_fin_movimientos'], calcularPeriodoPosstock('trimestre', 2, 2025)['label_movimientos'], calcularPeriodoPosstock('trimestre', 2, 2025)['total_periodos']]);
        self::assertSame(['2025-09-01', '2025-12-31', 'C3 2025 (Sep–Dic)', 3], [calcularPeriodoPosstock('cuatrimestre', 3, 2025)['fecha_inicio_movimientos'], calcularPeriodoPosstock('cuatrimestre', 3, 2025)['fecha_fin_movimientos'], calcularPeriodoPosstock('cuatrimestre', 3, 2025)['label_movimientos'], calcularPeriodoPosstock('cuatrimestre', 3, 2025)['total_periodos']]);
        self::assertSame(['2025-07-01', '2025-12-31', '2º semestre 2025 (Jul–Dic)', 2, '2025-06-30'], [calcularPeriodoPosstock('semestre', 2, 2025)['fecha_inicio_movimientos'], calcularPeriodoPosstock('semestre', 2, 2025)['fecha_fin_movimientos'], calcularPeriodoPosstock('semestre', 2, 2025)['label_movimientos'], calcularPeriodoPosstock('semestre', 2, 2025)['total_periodos'], calcularPeriodoPosstock('semestre', 2, 2025)['fecha_fin_stock']]);
    }

    /**
     * @comportamiento El año entero empieza los movimientos el 2 de enero: el stock base va del 31 de
     *   diciembre anterior al 1 de enero, por si la apertura se registró cualquiera de los dos días. Las
     *   cinco estaciones no cruzan el año.
     */
    public function test_elAnioYLasEstaciones(): void
    {
        self::assertSame(['2025-01-02', '2025-12-31', '2024-12-31', '2025-01-01', 'Año 2025', 'Stock base: 31 Dic 2024 – 01 Ene 2025'], $this->fechas(calcularPeriodoPosstock('anual', 1, 2025)));
        self::assertSame([1, 30], [calcularPeriodoPosstock('anual', 1, 2025)['total_periodos'], calcularPeriodoPosstock('anual', 1, 2025)['min_ventas_c5']]);
        self::assertSame(['2025-03-21', '2025-06-20', '2025-01-01', '2025-03-20', 'Primavera 2025'], array_slice($this->fechas(calcularPeriodoPosstock('estacional', 2, 2025)), 0, 5));
        self::assertSame(['2025-12-01', '2025-12-31', 'Navidad 2025', 5, 12], [calcularPeriodoPosstock('estacional', 5, 2025)['fecha_inicio_movimientos'], calcularPeriodoPosstock('estacional', 5, 2025)['fecha_fin_movimientos'], calcularPeriodoPosstock('estacional', 5, 2025)['label_movimientos'], calcularPeriodoPosstock('estacional', 5, 2025)['total_periodos'], calcularPeriodoPosstock('estacional', 5, 2025)['min_ventas_c5']]);
    }

    /** @comportamiento Un tipo de periodo que no existe, o una estación fuera de las cinco, no da periodo. */
    public function test_unTipoQueNoExisteNoDaPeriodo(): void
    {
        self::assertNull(calcularPeriodoPosstock('decada', 1, 2025));
        self::assertNull(calcularPeriodoPosstock('estacional', 6, 2025));
    }

    /** @comportamiento El rango de fechas de un informe lleva su inicio y su final. */
    public function test_elRangoDeFechasDeUnInforme(): void
    {
        self::assertSame('Listado entre las fechas :2025-01-01-----2025-01-31', getRangoFechas(['Fecha_Inicio' => '2025-01-01', 'Fecha_Final' => '2025-01-31']));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_informes/funciones.php:62-197
     *
     * @group defecto
     * @group robustez
     * @group medio
     *
     * @que-ocurre-hoy Un número de periodo fuera de los del tipo —el mes 13, el trimestre 5, la quincena 25—
     *   no se comprueba: la fecha que arma no existe y el cálculo termina en una excepción de PHP. Solo la
     *   estación fuera de rango devuelve que no hay periodo.
     * @que-deberia-ocurrir Que un número fuera de rango no dé periodo, como un tipo que no existe.
     * @por-que-ocurre Solo el tipo estacional comprueba el número.
     * @como-deberia-funcionar Comprobar el número contra el total de periodos del tipo.
     */
    public function test_unNumeroFueraDeRangoNoDaPeriodo(): void
    {
        $resultado = 'sin resultado';
        try {
            $resultado = calcularPeriodoPosstock('mes', 13, 2025);
        } catch (\Throwable) {
        }

        self::assertNull($resultado);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_informes/funciones.php:256-260
     *
     * @group defecto
     * @group robustez
     * @group bajo
     *
     * @que-ocurre-hoy El título de un informe abre su encabezado y, en lugar de cerrarlo, abre otro: el
     *   título queda sin cerrar, y lo que sigue en la página hereda su formato. El título se pinta sin
     *   escapar.
     * @que-deberia-ocurrir Que el título vaya en un encabezado cerrado, como texto.
     * @por-que-ocurre El cierre está escrito como apertura, y el título se concatena.
     * @como-deberia-funcionar Cerrar el encabezado y escapar el título.
     */
    public function test_elTituloDeUnInformeVaEnUnEncabezadoCerrado(): void
    {
        self::assertSame('<h1>Ventas &amp; compras</h1>', getHtmlTitulo(['titulo_informe' => 'Ventas & compras']));
    }
}
