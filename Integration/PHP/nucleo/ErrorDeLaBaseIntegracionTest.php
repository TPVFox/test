<?php

/**
 * Lo que pasa cuando la base no responde o rechaza una sentencia, visto desde fuera de la petición:
 * la conexión que no se abre (`clases/ClaseConexion.php` y `modulos/claseModeloP.php`) y lo que
 * enseña la página con los errores de PHP visibles.
 *
 * Cada caso se ejecuta en un PHP aparte, porque la petición termina con el error. Para la conexión
 * que no se abre se usa una copia de `ClaseConexion.php` en una carpeta temporal, con una
 * configuración cuyo usuario no existe: la de la instalación no se toca.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\Nucleo;

use TPVFox\Test\CasoIntegracion;

final class ErrorDeLaBaseIntegracionTest extends CasoIntegracion
{
    /**
     * @estado rojo
     * @codigo-afectado clases/ClaseConexion.php:32-36
     *
     * @group esperado
     * @group datos
     * @group bajo
     *
     * @que-ocurre-hoy Si la base no acepta la conexión, la petición termina con un error de PHP
     *   sin recoger, y no con el aviso «Error de Conexión» que prevé la clase.
     * @que-deberia-ocurrir Que la petición termine con el aviso y el número del error.
     * @por-que-ocurre PHP lanza el fallo de la conexión como excepción antes de que la clase mire el
     *   error; el aviso, además, lee el error de una variable que no existe en el método.
     * @como-deberia-funcionar Recoger la excepción de la conexión y avisar con sus datos.
     */
    public function test_C1_unaConexionQueNoSeAbreTerminaConSuAviso(): void
    {
        $salida = self::ejecutarConConexionFallida('new ClaseConexion(); echo "conectado";');

        self::assertStringNotContainsString('Uncaught', $salida, 'El fallo de la conexión no puede escapar sin recoger.');
        self::assertStringContainsString('Error de Conexión (1045)', $salida);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/claseModeloP.php:45-49
     *
     * @group esperado
     * @group datos
     * @group bajo
     *
     * @que-ocurre-hoy Si la base no acepta la conexión, la primera operación de una clase de datos
     *   termina con un error sin recoger, y no deja el error para consultarlo.
     * @que-deberia-ocurrir Que la operación no termine la petición y que el error de la conexión
     *   quede para `hayErrorConsulta()`.
     * @por-que-ocurre La conexión se abre sin recoger su excepción, y la comprobación que dejaría el
     *   error mira si el objeto de la conexión es falso, cosa que un objeto nunca es; llamaría
     *   además a un método que la conexión no tiene.
     * @como-deberia-funcionar Recoger el fallo de la conexión y dejarlo en el resultado.
     */
    public function test_C2_sinConexionLasClasesDeDatosDejanElErrorParaConsultarlo(): void
    {
        $salida = self::ejecutarConConexionFallida(
            'require ' . var_export(RUTA_TPVFOX . '/modulos/claseModeloP.php', true) . ';'
            . ' try { ModeloP::getDbo(); $excepcion = false; } catch (Throwable $e) { $excepcion = true; }'
            . ' echo "\n", json_encode(["excepcion" => $excepcion, "hayError" => ModeloP::hayErrorConsulta()]);'
        );

        self::assertSame(
            ['excepcion' => false, 'hayError' => true],
            json_decode(self::ultimaLinea($salida), true),
            'Sin conexión, la operación tiene que dejar el error en lugar de terminar la petición.'
        );
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/claseModeloP.php:81
     *
     * @group esperado
     * @group datos
     * @group medio
     *
     * @que-ocurre-hoy En una instalación con los errores de PHP visibles, una sentencia que la base
     *   rechaza escribe en la página el nombre de la base y la ruta completa del producto en el
     *   servidor.
     * @que-deberia-ocurrir Que la página dé un aviso sin nombres ni rutas internas, y que el detalle
     *   vaya al registro de errores.
     * @por-que-ocurre El rechazo llega como excepción y nadie la recoge: PHP la escribe entera, con
     *   el mensaje de la base y la traza.
     * @como-deberia-funcionar Recoger el error de la base donde ocurre y responder con un aviso.
     */
    public function test_C3_conLosErroresVisiblesUnRechazoDeLaBaseNoEnsenaDatosInternos(): void
    {
        $guion = '$RutaServidor = ' . var_export(dirname(RUTA_TPVFOX), true) . ';'
            . ' $HostNombre = ' . var_export('/' . basename(RUTA_TPVFOX), true) . ';'
            . ' require ' . var_export(RUTA_TPVFOX . '/modulos/claseModeloP.php', true) . ';'
            . ' class Consulta extends ModeloP { public static function hacer($sql) { return self::_consulta($sql); } }'
            . ' Consulta::hacer("SELECT * FROM tabla_que_no_existe");';
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d html_errors=0 -d log_errors=0 -r ' . escapeshellarg($guion) . ' 2>&1', $salida);
        $pagina = implode("\n", $salida);

        self::assertStringContainsString('tabla_que_no_existe', $pagina, 'La sentencia tiene que haber llegado a la base.');
        self::assertStringNotContainsString(self::configuracion()['nombrebdMysql'], $pagina, 'La página no puede enseñar el nombre de la base.');
        self::assertStringNotContainsString(RUTA_TPVFOX, $pagina, 'La página no puede enseñar la ruta del producto en el servidor.');
    }

    /**
     * Ejecuta el guion en un PHP aparte con una copia de `ClaseConexion.php` cuya configuración
     * apunta al servidor de la instalación con un usuario que no existe, y devuelve lo que escribe,
     * errores incluidos. La copia es la que cargan las clases de datos.
     */
    private static function ejecutarConConexionFallida(string $guion): string
    {
        $carpeta = sys_get_temp_dir() . '/tpvfox-conexion-' . bin2hex(random_bytes(4));
        mkdir($carpeta . '/clases', 0777, true);
        copy(RUTA_TPVFOX . '/clases/ClaseConexion.php', $carpeta . '/clases/ClaseConexion.php');
        file_put_contents($carpeta . '/configuracion.php', '<?php'
            . ' $servidorMysql = ' . var_export(self::configuracion()['servidorMysql'], true) . ';'
            . ' $nombrebdMysql = ' . var_export(self::configuracion()['nombrebdMysql'], true) . ';'
            . " \$usuarioMysql = 'usuario_que_no_existe'; \$passwordMysql = 'x';");

        $guion = '$RutaServidor = ' . var_export($carpeta, true) . '; $HostNombre = "";'
            . ' require_once ' . var_export($carpeta . '/clases/ClaseConexion.php', true) . '; ' . $guion;
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d html_errors=0 -r ' . escapeshellarg($guion) . ' 2>&1', $salida);

        unlink($carpeta . '/configuracion.php');
        unlink($carpeta . '/clases/ClaseConexion.php');
        rmdir($carpeta . '/clases');
        rmdir($carpeta);

        return implode("\n", $salida);
    }

    private static function ultimaLinea(string $salida): string
    {
        $lineas = explode("\n", $salida);

        return (string) end($lineas);
    }

    /** @return array<string,mixed> las variables que define `configuracion.php` de la instalación */
    private static function configuracion(): array
    {
        return (static function (): array {
            include RUTA_TPVFOX . '/configuracion.php';

            return get_defined_vars();
        })();
    }
}
