<?php
/**
 * Lectura de la configuracion de la suite.
 *
 * El orden es: variable de entorno primero, fichero .env despues. Asi una ejecucion en
 * integracion continua no necesita fichero, y una en local no necesita exportar nada.
 * El .env no se versiona.
 */

declare(strict_types=1);

namespace TPVFox\Test;

final class Entorno
{
    /** @var array<string,string>|null */
    private static ?array $fichero = null;

    public static function valor(string $clave, string $porDefecto = ''): string
    {
        $delEntorno = getenv($clave);
        if ($delEntorno !== false && $delEntorno !== '') {
            return $delEntorno;
        }

        $fichero = self::fichero();

        return $fichero[$clave] ?? $porDefecto;
    }

    /** @return array<string,string> */
    private static function fichero(): array
    {
        if (self::$fichero !== null) {
            return self::$fichero;
        }

        self::$fichero = [];
        $ruta = __DIR__ . '/../../.env';

        if (!is_file($ruta)) {
            return self::$fichero;
        }

        foreach (file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
            $linea = trim($linea);
            if ($linea === '' || $linea[0] === '#' || strpos($linea, '=') === false) {
                continue;
            }
            [$clave, $valor] = explode('=', $linea, 2);
            self::$fichero[trim($clave)] = trim($valor, " \t\"'");
        }

        return self::$fichero;
    }
}
