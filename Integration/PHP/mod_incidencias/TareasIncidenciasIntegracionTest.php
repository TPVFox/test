<?php

/**
 * Las peticiones de fondo de las incidencias (`modulos/mod_incidencias/tareas.php`) en un PHP
 * aparte, desde la carpeta del módulo, como las sirve el servidor: lo que escriben en la salida de
 * errores no lo ve un recorrido.
 *
 * La sesión y lo que responden con ella y sin ella se prueban en los recorridos del módulo.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModIncidencias;

use TPVFox\Test\CasoIntegracion;

final class TareasIncidenciasIntegracionTest extends CasoIntegracion
{
    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_incidencias/tareas.php:31-39
     *
     * @group defecto
     * @group guardado
     * @group bajo
     *
     * @que-ocurre-hoy Guardar una incidencia sin elegir usuario —la ventana sin selector, o sin
     *   usuarios que elegir— deja un aviso de PHP por una variable sin definir en cada alta.
     * @que-deberia-ocurrir Que guardar sin usuario elegido no deje avisos.
     * @por-que-ocurre El usuario elegido solo se define si llega en la petición, pero se compara
     *   siempre.
     * @como-deberia-funcionar Darle un valor de partida antes de comprobar si llegó.
     */
    public function test_guardarSinElegirUsuarioNoDejaAvisos(): void
    {
        $errores = self::ejecutarTareas([
            'pulsado' => 'nuevaIncidencia', 'datos' => '{}', 'dedonde' => 'mod_incidencia', 'estado' => '0', 'mensaje' => '',
        ]);

        self::assertStringNotContainsString('Undefined variable $usuarioSelect', $errores);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_incidencias/tareas.php:12
     * @codigo-afectado modulos/mod_incidencias/clases/ClaseIncidencia.php:160
     *
     * @group defecto
     * @group entrada
     * @group bajo
     *
     * @que-ocurre-hoy Abrir la ventana desde una pantalla sin configuración de incidencias —el
     *   navegador no envía una lista vacía— deja dos avisos de PHP: uno por el dato que no llega y
     *   otro al recorrerlo.
     * @que-deberia-ocurrir Que sin configuración se abra la ventana con los valores por defecto, sin
     *   avisos.
     * @por-que-ocurre Las tareas leen la configuración sin comprobar que llegó, y la ventana la
     *   recorre sin comprobar que sea una lista.
     * @como-deberia-funcionar Tomar una lista vacía cuando no llega.
     */
    public function test_abrirLaVentanaSinConfiguracionNoDejaAvisos(): void
    {
        $errores = self::ejecutarTareas(['pulsado' => 'abririncidencia', 'numIncidencia' => '0', 'dedonde' => 'listado Incidencias']);

        self::assertStringNotContainsString('Undefined array key "configuracion"', $errores);
        self::assertStringNotContainsString('foreach() argument must be of type array|object', $errores);
    }

    /**
     * Ejecuta `tareas.php` en un PHP aparte, desde la carpeta del módulo y con la petición que se le
     * dé, y devuelve lo que escribe en la salida de errores.
     *
     * @param array<string,string> $peticion
     */
    private static function ejecutarTareas(array $peticion): string
    {
        $guion = '$_SESSION = []; $_POST = ' . var_export($peticion, true) . ';'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_incidencias', true) . ');'
            . ' require "tareas.php";';
        $orden = escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -r ' . escapeshellarg($guion) . ' 2>&1 >/dev/null';

        return (string) shell_exec($orden);
    }
}
