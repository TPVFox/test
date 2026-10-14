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

    public static function anotar(int $idTarea): void
    {
        self::$ejecuciones[] = $idTarea;
    }

    public static function anotarInclusion(string $fichero): void
    {
        self::$incluidos[] = $fichero;
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
    }

    /** La raiz que imita la de TPVFox, con las tareas de prueba en su carpeta de tareas. */
    public static function raiz(): string
    {
        return (string) realpath(__DIR__ . '/../raiz-tareas-cron');
    }
}
