<?php
/**
 * Lo que anotan las tareas de prueba al ejecutarse y los ficheros de prueba al incluirse.
 *
 * Las tareas de prueba viven en `support/raiz-tareas-cron/`, una raiz que imita la de TPVFox
 * para que el planificador y la ejecucion manual las encuentren donde buscan las suyas. Anotan
 * aqui cada ejecucion, y el caso lo lee despues.
 */

declare(strict_types=1);

namespace TPVFox\Test;

final class RegistroDeTareas
{
    /** @var list<int> Identificador de la tarea, una vez por cada ejecucion. */
    private static array $ejecuciones = [];

    private static bool $relanzado = false;

    /** @var list<string> Ficheros de prueba que alguien incluyo. */
    private static array $incluidos = [];

    /** @var array<string,int> Ejecuciones de cada clase de prueba que estan en curso ahora. */
    private static array $enCurso = [];

    /** @var array<string,int> Cuantas ejecuciones de cada clase de prueba llego a haber a la vez. */
    private static array $maximoALaVez = [];

    public static function anotar(int $idTarea): void
    {
        self::$ejecuciones[] = $idTarea;
    }

    public static function anotarInclusion(string $fichero): void
    {
        self::$incluidos[] = $fichero;
    }

    /** Una ejecucion de esa clase de prueba empieza. */
    public static function empezar(string $clase): void
    {
        self::$enCurso[$clase] = (self::$enCurso[$clase] ?? 0) + 1;
        self::$maximoALaVez[$clase] = max(self::$maximoALaVez[$clase] ?? 0, self::$enCurso[$clase]);
    }

    /** Una ejecucion de esa clase de prueba termina. */
    public static function terminar(string $clase): void
    {
        self::$enCurso[$clase] = (self::$enCurso[$clase] ?? 1) - 1;
    }

    /** Cuantas ejecuciones de esa clase de prueba llego a haber en curso a la vez. */
    public static function maximoALaVez(string $clase): int
    {
        return self::$maximoALaVez[$clase] ?? 0;
    }

    /** Si alguien incluyo el fichero de prueba de ese nombre. */
    public static function seIncluyo(string $fichero): bool
    {
        return in_array($fichero, self::$incluidos, true);
    }

    /** Cuantas veces se ejecuto la tarea de ese identificador. */
    public static function vecesQueSeEjecuto(int $idTarea): int
    {
        $veces = 0;
        foreach (self::$ejecuciones as $ejecutada) {
            if ($ejecutada === $idTarea) {
                $veces++;
            }
        }

        return $veces;
    }

    /**
     * Si la tarea que relanza el planificador puede hacerlo todavia: solo una vez por caso, para
     * que la pasada de dentro no vuelva a abrir otra.
     */
    public static function puedeRelanzar(): bool
    {
        if (self::$relanzado) {
            return false;
        }
        self::$relanzado = true;

        return true;
    }

    public static function vaciar(): void
    {
        self::$ejecuciones = [];
        self::$relanzado = false;
        self::$incluidos = [];
        self::$enCurso = [];
        self::$maximoALaVez = [];
    }

    /** La raiz que imita la de TPVFox, con las tareas de prueba en su carpeta de tareas. */
    public static function raiz(): string
    {
        return (string) realpath(__DIR__ . '/../raiz-tareas-cron');
    }
}
