<?php

/**
 * Lo que la pantalla de POSStock calcula sin la base: los parámetros que arma desde la petición y la
 * configuración (`modulos/mod_informes/tareas/helpers/parsearParamsPosstock.php`) y la tabla de
 * incidencias (`tareas/vistas/vistaTablaPosstock.php`).
 *
 * La configuración se lee de `parametros.xml` relativo a la carpeta de trabajo: cada caso trabaja en una
 * carpeta suya con una copia del fichero del módulo —cambiada si el caso lo pide—, que se borra al
 * terminar; la carpeta de trabajo de la suite se devuelve.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModInformes;

use PHPUnit\Framework\TestCase;

final class PosstockPantallaTest extends TestCase
{
    private string $carpeta;

    private string $antes;

    protected function setUp(): void
    {
        parent::setUp();
        require_once RUTA_TPVFOX . '/controllers/parametros.php';
        require_once RUTA_TPVFOX . '/modulos/mod_informes/tareas/helpers/parsearParamsPosstock.php';
        require_once RUTA_TPVFOX . '/modulos/mod_informes/tareas/vistas/vistaTablaPosstock.php';
        $this->carpeta = sys_get_temp_dir() . '/posstock-params-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->carpeta);
        copy(RUTA_TPVFOX . '/modulos/mod_informes/parametros.xml', $this->carpeta . '/parametros.xml');
        $this->antes = (string) getcwd();
        chdir($this->carpeta);
    }

    protected function tearDown(): void
    {
        chdir($this->antes);
        $_POST = [];
        unlink($this->carpeta . '/parametros.xml');
        if (is_dir($this->carpeta . '/cache')) {
            rmdir($this->carpeta . '/cache');
        }
        rmdir($this->carpeta);
        parent::tearDown();
    }

    /** Lee los parámetros de una petición de marzo de 2019 con lo que se le añada. */
    private function parametros(array $otros = []): ?array
    {
        $_POST = $otros + ['fecha_inicio_movimientos' => '2019-03-01', 'fecha_fin_movimientos' => '2019-03-31', 'fecha_inicio_stock' => '2019-01-01', 'fecha_fin_stock' => '2019-02-28'];
        $respuesta = [];
        $parametros = parsearParamsPosstock($respuesta);

        return $parametros ?? $respuesta;
    }

    /** Cambia un valor de la configuración de la copia. */
    private function configurar(string $nodo, string $valor): void
    {
        $xml = (string) file_get_contents('parametros.xml');
        file_put_contents('parametros.xml', (string) preg_replace("#<{$nodo}>[^<]*</{$nodo}>#", "<{$nodo}>{$valor}</{$nodo}>", $xml));
    }

    /**
     * @comportamiento Los parámetros llevan las fechas, los casos válidos, las familias y los proveedores
     *   como números positivos, el mínimo de ventas del tipo de periodo y la ventana estadística: en un mes,
     *   la duración del periodo antes y después —31 días—, dentro del año.
     */
    public function test_losParametrosDeUnMes(): void
    {
        $p = $this->parametros(['casos_incluir' => 'caso1, caso9,cosa', 'familias_incluir' => '3,x,-2,5', 'proveedores_incluir' => '7', 'proveedor_todos_productos' => '1', 'tipo_periodo' => 'mes']);

        self::assertSame(['caso1', 'caso9'], $p['casos_incluir']);
        self::assertSame([[3, 5], [], [7], true], [$p['familias_incluir'], $p['familias_excluir'], $p['proveedores_incluir'], $p['proveedor_todos_productos']]);
        self::assertSame([7, '2019-01-29', '2019-05-01'], [$p['min_ventas_c5'], $p['fecha_inicio_stats'], $p['fecha_fin_stats']]);
    }

    /**
     * @comportamiento En una estación, la ventana estadística es la estación; en el resto, del inicio del
     *   stock al final del periodo. Sin tipo, el mínimo de ventas que llegue, y nunca menos de 3.
     */
    public function test_laVentanaSegunElTipoDePeriodo(): void
    {
        $estacion = $this->parametros(['tipo_periodo' => 'estacional']);
        $anio = $this->parametros(['tipo_periodo' => 'anual']);
        $sinTipo = $this->parametros(['min_ventas_c5' => '1']);

        self::assertSame(['2019-03-01', '2019-03-31', 12], [$estacion['fecha_inicio_stats'], $estacion['fecha_fin_stats'], $estacion['min_ventas_c5']]);
        self::assertSame(['2019-01-01', '2019-03-31', 30], [$anio['fecha_inicio_stats'], $anio['fecha_fin_stats'], $anio['min_ventas_c5']]);
        self::assertSame(3, $sinTipo['min_ventas_c5']);
    }

    /**
     * @comportamiento Los umbrales salen de la configuración: el sobrestock de porcentaje a factor, con sus
     *   dos derivados del C2; la significancia da la confianza y el nivel de servicio; un modelo que no
     *   existe vuelve al automático, y un valor fuera de su rango, a su límite.
     */
    public function test_losUmbralesDeLaConfiguracion(): void
    {
        $partida = $this->parametros();
        $this->configurar('modelo_estadistico', 'cuantico');
        $this->configurar('modelo_significancia', '0.99');
        $this->configurar('c3b_dias_post_periodo', '100');
        $cambiada = $this->parametros();

        self::assertSame([0.5, 1.0, 3.0, 24, 12, 'automatico', 0.95, 0.05], [$partida['umbral_sobrestock'], $partida['c2_umbral_duplicado'], $partida['c2_umbral_sobrestock_severo'], $partida['umbral_caducidad_semanas'], $partida['umbral_sin_rotacion_semanas'], $partida['modelo_estadistico'], $partida['c6_nivel_servicio'], $partida['umbral_confianza_poisson']]);
        self::assertSame(['automatico', 0.99, 0.01, 30], [$cambiada['modelo_rotura_c5'], $cambiada['c6_nivel_servicio'], $cambiada['umbral_confianza_poisson'], $cambiada['c3b_dias_post_periodo']]);
    }

    /** @comportamiento Con una fecha que no es AAAA-MM-DD, el error y ningún parámetro. */
    public function test_unaFechaQueNoEsFecha(): void
    {
        self::assertSame(['error' => 'Fechas no válidas o incompletas.'], $this->parametros(['fecha_fin_stock' => '28/02/2019']));
    }

    /**
     * @comportamiento La tabla pinta una fila por incidencia con el producto, el nombre y la causa como
     *   texto, la severidad con su etiqueta, el tipo, la clave de orden y el enlace al mayor del año; sin
     *   incidencias, el aviso. Las filas sueltas, sin la cabecera.
     */
    public function test_laTablaDeIncidencias(): void
    {
        $fila = ['idArticulo' => 7, 'nombre' => 'Zz <b>Negativo</b>', 'tipo' => 'Inventario en negativo', 'severidad' => 'CRITICA', 'stock_actual' => -3.0, 'min_balance' => -3.0, 'posible_causa' => 'Ventas "sin" recepción', 'orden_clave' => '00'];
        $cfg = ['fecha_fin_movimientos' => '2019-03-31', 'fecha_inicio_stock' => '2019-01-01', 'anio' => 2019];

        $tabla = renderTablaPosstock([$fila], $cfg);
        $filas = renderFilasTablaPosstock([$fila], $cfg);

        self::assertStringContainsString('<table class="table table-condensed table-hover table-bordered" id="posstockTabla">', $tabla);
        self::assertStringContainsString('<td>7</td><td>Zz &lt;b&gt;Negativo&lt;/b&gt;</td>', $tabla);
        self::assertStringContainsString('<span class="label label-danger">Crítica</span>', $tabla);
        self::assertStringContainsString('Ventas &quot;sin&quot; recepción', $tabla);
        self::assertStringContainsString('data-tipo="Inventario en negativo"', $tabla);
        self::assertStringContainsString('data-orden="00"', $tabla);
        self::assertMatchesRegularExpression('/fecha_final=2019-12-31/', $tabla);
        self::assertStringStartsWith('<tr', $filas);
        self::assertStringNotContainsString('<thead>', $filas);
        self::assertSame('<div class="alert alert-success">Sin incidencias detectadas para este periodo.</div>', renderTablaPosstock([], $cfg));
    }
}
