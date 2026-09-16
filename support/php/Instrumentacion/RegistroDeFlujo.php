<?php

/**
 * Arranca y para una traza de ejecucion por cada caso, para que el informe pueda contar la
 * cadena de llamadas.
 *
 * Complementa a la conexion observada, no la sustituye: el SQL cuenta el flujo del dato en
 * los casos que escriben en la base, pero hay clases que son calculo puro y no consultan
 * nada. Ahi la unica traza posible es la de llamadas, con sus argumentos y sus retornos.
 *
 * **Sin la variable de entorno, todo metodo retorna en el acto.** La ejecucion normal de la
 * suite no puede pagar el precio de una instrumentacion que solo sirve para generar el
 * informe. Medido sin filtrar, un solo fichero de pruebas produce 84 MB de traza: el filtro
 * de rutas no es una optimizacion, es la condicion para que esto sea viable.
 *
 * Las rutas de la propia suite entran en el filtro a proposito. Sin ellas se pierde la
 * ascendencia que permite distinguir lo que hizo la preparacion de lo que hizo el codigo
 * bajo prueba; los marcos de la suite se descartan despues, al analizar.
 */

declare(strict_types=1);

namespace TPVFox\Test\Instrumentacion;

use PHPUnit\Runner\AfterTestHook;
use PHPUnit\Runner\BeforeFirstTestHook;
use PHPUnit\Runner\BeforeTestHook;

final class RegistroDeFlujo implements BeforeFirstTestHook, BeforeTestHook, AfterTestHook
{
    /** La variable de entorno que lleva el directorio de trazas; sin ella no se traza nada. */
    public const VARIABLE = 'TPVFOX_TRAZA';

    private ?string $directorio = null;
    private bool $disponible = false;
    private bool $trazando = false;

    public function executeBeforeFirstTest(): void
    {
        $destino = getenv(self::VARIABLE);
        if (!is_string($destino) || $destino === '') {
            return;
        }

        // Existir no es funcionar: las funciones de traza estan declaradas siempre, pero solo
        // hacen algo con el modo de traza habilitado. Si no lo esta, se calla y el informe
        // sale sin cadena de llamadas en vez de fallar la suite entera.
        if (!function_exists('xdebug_start_trace') || !$this->modoDeTrazaActivo()) {
            return;
        }

        $this->directorio = rtrim($destino, '/');
        if (!is_dir($this->directorio)) {
            @mkdir($this->directorio, 0777, true);
        }

        $this->disponible = is_dir($this->directorio);

        if ($this->disponible) {
            xdebug_set_filter(
                XDEBUG_FILTER_TRACING,
                XDEBUG_PATH_INCLUDE,
                [rtrim((string) constant('RUTA_TPVFOX'), '/') . '/', dirname(__DIR__, 2) . '/']
            );
        }
    }

    public function executeBeforeTest(string $test): void
    {
        if (!$this->disponible) {
            return;
        }

        xdebug_start_trace($this->directorio . '/' . Identidad::hash($test), XDEBUG_TRACE_COMPUTERIZED);
        $this->trazando = true;
    }

    public function executeAfterTest(string $test, float $time): void
    {
        if (!$this->trazando) {
            return;
        }

        xdebug_stop_trace();
        $this->trazando = false;
    }

    /**
     * Si el modo de traza esta habilitado en esta ejecucion.
     *
     * `xdebug_info()` no da el modo como cadena, de modo que se pregunta por la directiva,
     * que es lo que el propio Xdebug lee.
     */
    private function modoDeTrazaActivo(): bool
    {
        $modo = (string) ini_get('xdebug.mode');

        return $modo !== '' && in_array('trace', array_map('trim', explode(',', $modo)), true);
    }
}
