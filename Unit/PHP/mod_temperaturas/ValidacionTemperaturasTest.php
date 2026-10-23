<?php

/**
 * Las reglas de control de las lecturas de un dispositivo (`modulos/mod_temperaturas/clases/ClaseValidacion.php`):
 * la media y la desviación de la serie y, lectura a lectura, su desviación en unidades de la
 * desviación y la regla de Levey-Jennings que cumple, con su tipo y su acción.
 *
 * La clase no toca la base. Los casos que comprueban una regla fijan la media y la desviación de
 * referencia con sus dos métodos, como hace el análisis en su modo del límite, para que la regla no
 * dependa de cómo las lecturas del caso mueven la media de la serie.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModTemperaturas;

use PHPUnit\Framework\TestCase;

final class ValidacionTemperaturasTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once RUTA_TPVFOX . '/modulos/mod_temperaturas/clases/ClaseValidacion.php';
    }

    /** @comportamiento La media y la desviación son las de toda la serie: la desviación, la de la población. */
    public function test_laMediaYLaDesviacionSonLasDeLaSerie(): void
    {
        $validacion = $this->validacion([3, 5, 7]);

        self::assertEqualsWithDelta(5.0, $validacion->getMedia(), 0.0001);
        self::assertEqualsWithDelta(sqrt(8 / 3), $validacion->getDesviacionEstandar(), 0.0001);
    }

    /** @comportamiento Sin lecturas, la media y la desviación son cero; con todas iguales, la desviación es casi cero, no cero. */
    public function test_sinLecturasOConTodasIgualesNoSeDivideEntreCero(): void
    {
        $vacia = $this->validacion([]);
        $iguales = $this->validacion([4, 4, 4]);

        self::assertSame(0, $vacia->getMedia());
        self::assertSame(0, $vacia->getDesviacionEstandar());
        self::assertSame(0.00001, $iguales->getDesviacionEstandar());
        self::assertSame(['Normal', 'Normal', 'Normal'], $iguales->getResultados()['reglas']);
    }

    /**
     * @comportamiento Cada lectura lleva su desviación en unidades de la desviación; sin regla que cumplir,
     *   es normal y no pide ninguna acción.
     */
    public function test_cadaLecturaLlevaSuDesviacionYSinReglaEsNormal(): void
    {
        $resultados = $this->conReferencia([4, 5, 3], 4, 1);

        self::assertSame([0.0, 1.0, -1.0], $resultados['desviacion']);
        self::assertSame(['Normal', 'Normal', 'Normal'], $resultados['reglas']);
        self::assertSame('Ninguna acción requerida', $resultados['acciones'][0]);
    }

    /** @comportamiento Una lectura a más de dos desviaciones es una advertencia; a más de tres, crítica. */
    public function test_masDeDosDesviacionesEsAdvertenciaYMasDeTresCritica(): void
    {
        $resultados = $this->conReferencia([6.5, 4, 0.5], 4, 1);

        self::assertSame(['1:2s', 'Normal', '1:3s'], $resultados['reglas']);
        self::assertSame(['Advertencia', 'Normal', 'Crítica'], $resultados['tipo']);
        self::assertSame('Acción inmediata: verificar cierre y sondas', $resultados['acciones'][2]);
    }

    /**
     * @comportamiento Dos lecturas seguidas a más de dos desviaciones del mismo lado son una tendencia;
     *   de lados opuestos, a más de cuatro desviaciones una de otra, un uso incorrecto.
     */
    public function test_dosSeguidasDelMismoLadoSonTendenciaYDeLadosOpuestosUsoIncorrecto(): void
    {
        $tendencia = $this->conReferencia([6.5, 6.5], 4, 1);
        $usoIncorrecto = $this->conReferencia([6.5, 1.5], 4, 1);

        self::assertSame(['1:2s', '2:2s'], $tendencia['reglas']);
        self::assertSame('Tendencia', $tendencia['tipo'][1]);
        self::assertSame(['1:2s', 'R:4s'], $usoIncorrecto['reglas']);
        self::assertSame('Uso incorrecto', $usoIncorrecto['tipo'][1]);
    }

    /**
     * @comportamiento Cuatro lecturas seguidas a más de una desviación del mismo lado son un cambio
     *   progresivo, y diez seguidas del mismo lado, un sesgo; cambiar de lado empieza la cuenta.
     */
    public function test_cuatroSeguidasSonCambioProgresivoYDiezSesgo(): void
    {
        $progresivo = $this->conReferencia([5.5, 5.5, 5.5, 5.5], 4, 1);
        $sesgo = $this->conReferencia(array_fill(0, 10, 4.1), 4, 1);
        $cambiaDeLado = $this->conReferencia([5.5, 5.5, 5.5, 2.5, 5.5], 4, 1);

        self::assertSame('4:1s', $progresivo['reglas'][3]);
        self::assertSame('Cambio progresivo', $progresivo['tipo'][3]);
        self::assertSame('10:x', $sesgo['reglas'][9]);
        self::assertSame('Normal', $sesgo['reglas'][8]);
        self::assertNotContains('4:1s', $cambiaDeLado['reglas']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_temperaturas/clases/ClaseValidacion.php:79-155
     *
     * @group defecto
     * @group validacion
     * @group alto
     *
     * @que-ocurre-hoy Una lectura a más de tres desviaciones que además cumple otra regla —la segunda de
     *   una tendencia, o la cuarta de un cambio progresivo— se enseña con esa otra regla, de tipo
     *   tendencia o cambio progresivo, y deja de verse como crítica.
     * @que-deberia-ocurrir Que una lectura crítica se enseñe siempre como crítica.
     * @por-que-ocurre Las reglas se comprueban una tras otra y cada una que se cumple sustituye a la
     *   anterior, sin mirar cuál es más grave.
     * @como-deberia-funcionar Quedarse con la regla más grave de las que se cumplen.
     */
    public function test_unaLecturaCriticaSeEnsenaSiempreComoCritica(): void
    {
        $resultados = $this->conReferencia([4, 6, 6], 4, 0.5);

        self::assertSame('Crítica', $resultados['tipo'][1]);
        self::assertSame('Crítica', $resultados['tipo'][2]);
    }

    /** @param list<float|int> $valores */
    private function validacion(array $valores): \ClaseValidacion
    {
        return new \ClaseValidacion(['fechas' => [], 'valores' => $valores, 'usuario' => []]);
    }

    /**
     * Las reglas de las lecturas sobre una media y una desviación de referencia.
     *
     * @param list<float|int> $valores
     * @return array<string, mixed>
     */
    private function conReferencia(array $valores, float $media, float $desviacion): array
    {
        $validacion = $this->validacion($valores);
        $validacion->setMedia($media);
        $validacion->setDesviacionEstandar($desviacion);

        return $validacion->getResultados();
    }
}
