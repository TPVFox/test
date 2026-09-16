<?php

/**
 * Carga un fichero de TPVFox cuya primera linea es `include_once './../../inicial.php'`
 * —como todo fichero del producto— sin que ese include llegue a resolver.
 *
 * `inicial.php` abre una conexion real via `ClaseSession` y depende de `configuracion.php`.
 * Un test de Unit/PHP no debe tocar la base ni la configuracion del entorno: aqui se fuerza
 * el directorio de trabajo a un sitio donde esa ruta relativa nunca existe, para que el
 * `include_once` falle sin llegar a incluir nada, y el fichero termine de cargar con sus
 * funciones definidas. Dejar que falle porque el CWD de quien invoca phpunit no contenga
 * por casualidad ese fichero seria fragil: aqui se hace deliberado y no depende de desde
 * donde se lance la suite.
 *
 * El fallo en si solo emite E_WARNING —no detiene la ejecucion—, pero PHPUnit convierte los
 * warnings de PHP en error de test por defecto. Se instala un manejador de errores propio
 * solo mientras dura el `require_once`, para silenciar justo ese warning esperado sin
 * enmascarar ningun otro que la funcion bajo prueba pueda emitir de verdad.
 */

declare(strict_types=1);

namespace TPVFox\Test;

final class CargaAislada
{
    public static function requerir(string $rutaAbsoluta): void
    {
        self::conCwdNeutro(static function () use ($rutaAbsoluta): void {
            require_once $rutaAbsoluta;
        });
    }

    /**
     * Ejecuta la llamada bajo el mismo aislamiento de `requerir()`.
     *
     * Hace falta ademas de `requerir()` porque el `include_once` relativo no siempre esta
     * en la cabecera del fichero: `funciones.php::incidenciasAdjuntas()` tiene el suyo
     * propio dentro del cuerpo de la funcion (`include_once('../mod_incidencias/...')`),
     * y ese solo se ejecuta al llamarla, no al cargar `funciones.php`. Quien llama a este
     * metodo ya tuvo que cargar antes, por la via normal (`CasoIntegracion::incluirTPVFox()`),
     * la clase que ese include intenta traer — aqui solo se garantiza que el intento
     * relativo falla en vez de arriesgarse a redeclararla si el CWD de turno coincidiera
     * por casualidad.
     *
     * @template T
     * @param callable(): T $funcion
     * @return T
     */
    public static function llamar(callable $funcion): mixed
    {
        return self::conCwdNeutro($funcion);
    }

    /** @template T @param callable(): T $funcion @return T */
    private static function conCwdNeutro(callable $funcion): mixed
    {
        $cwdOriginal = getcwd();
        chdir(sys_get_temp_dir());
        set_error_handler(static fn (): bool => true);
        try {
            return $funcion();
        } finally {
            restore_error_handler();
            chdir($cwdOriginal);
        }
    }
}
