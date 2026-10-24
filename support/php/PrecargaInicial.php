<?php
/**
 * Carga `inicial.php` una sola vez en el proceso de la suite, sin que abra sesion ni conexion.
 *
 * Algunos ficheros de TPVFox lo incluyen por su ruta absoluta (`__DIR__ . '/../../../inicial.php'`),
 * de modo que no hay directorio de trabajo que lo neutralice, como hace `CargaAislada` con las
 * rutas relativas. Una vez cargado aqui, esos `include_once` ya no lo vuelven a ejecutar.
 *
 * Tres cosas lo dejan sin efecto sobre la suite:
 *
 *  - `$thisTpv` ya existe, y `inicial.php` solo crea la sesion si no existe.
 *  - La raiz de la que toma la sesion y las funciones de ayuda es `support/raiz-inicial/`. La
 *    sesion de verdad vuelve a incluir `ClaseConexion.php` sin `_once`, y la suite ya la tiene
 *    cargada. `inicial.php` saca esa raiz de `configuracion.php`, y solo lo incluye si nadie lo
 *    hizo antes en el proceso: se carga aqui primero, y despues se fija la raiz.
 *  - Se silencian los avisos de las tres constantes de fecha, que `bootstrap.php` ya definio con
 *    el mismo valor. Cualquier otro aviso llega a PHPUnit.
 *
 * Las variables que `inicial.php` asigna quedan en el ambito de este metodo y no tocan las que fija
 * `bootstrap.php`.
 */

declare(strict_types=1);

namespace TPVFox\Test;

final class PrecargaInicial
{
    private static bool $hecha = false;

    public static function hacer(): void
    {
        if (self::$hecha) {
            return;
        }

        global $thisTpv;
        if (!isset($thisTpv)) {
            $thisTpv = new \stdClass();
        }

        require_once RUTA_TPVFOX . '/configuracion.php';
        $RutaServidor = '';
        $HostNombre = (string) realpath(__DIR__ . '/../raiz-inicial');

        $anterior = null;
        $anterior = set_error_handler(
            static function (int $nivel, string $mensaje, string $fichero = '', int $linea = 0) use (&$anterior): bool {
                $constanteRepetida = $nivel === E_WARNING
                    && str_starts_with($mensaje, 'Constant FORMATO_FECHA_')
                    && str_ends_with($mensaje, 'already defined');
                if ($constanteRepetida) {
                    return true;
                }

                return $anterior !== null ? (bool) $anterior($nivel, $mensaje, $fichero, $linea) : false;
            }
        );
        try {
            require_once RUTA_TPVFOX . '/inicial.php';
        } finally {
            restore_error_handler();
        }

        self::$hecha = true;
    }
}
