<?php

/**
 * El correo electrónico común (`clases/CorreoElectronico.php`): lee de la tienda principal los datos
 * del servidor y envía un mensaje con un adjunto por SMTP, con PHPMailer.
 *
 * Los dos módulos que lo usan cargan antes `TFModelo`, y la clase cuenta con ello: cada caso lo deja
 * cargado igual. El envío se prueba contra un servidor que no responde, en esta misma máquina: lo que
 * se comprueba es cómo da cuenta del fallo. La copia en la carpeta de enviados, que se hace por IMAP
 * tras un envío correcto, necesita un servidor de correo real y queda fuera de estas pruebas.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\Nucleo;

use TPVFox\Test\CasoIntegracion;

final class CorreoIntegracionTest extends CasoIntegracion
{
    protected bool $compartirConexionConElProducto = true;

    private const SERVIDOR = [
        ['host' => '127.0.0.1', 'SMTPPort' => '1'],
        ['SMTPAuth' => true, 'SMTPUsuario' => 'usuario', 'SMTPPassword' => 'clave'],
        ['emailTienda' => 'tienda@ejemplo.invalid', 'nombreRemitente' => 'Tienda de pruebas'],
    ];

    private string $adjunto = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/app/helpers.php');
        $this->incluirTPVFox('/clases/ClaseTFModelo.php');
        $this->incluirTPVFox('/clases/CorreoElectronico.php');
        $servidor = json_encode(self::SERVIDOR);
        $sentencia = $this->db->prepare("UPDATE tiendas SET servidor_email = ? WHERE tipoTienda = 'principal'");
        $sentencia->bind_param('s', $servidor);
        $sentencia->execute();
        $this->adjunto = tempnam(sys_get_temp_dir(), 'tpvfox-adjunto-');
        file_put_contents($this->adjunto, '%PDF-1.4');
    }

    protected function tearDown(): void
    {
        if (is_file($this->adjunto)) {
            unlink($this->adjunto);
        }
        parent::tearDown();
    }

    /** @comportamiento Los datos del servidor de correo son los de la tienda principal, reunidos en un solo array. */
    public function test_losDatosDelServidorSonLosDeLaTiendaPrincipal(): void
    {
        [$configuracion] = self::recogiendoAvisos(static fn () => \CorreoElectronico::leerConfiguracion());

        self::assertSame(
            [
                'host' => '127.0.0.1', 'SMTPPort' => '1', 'SMTPAuth' => true, 'SMTPUsuario' => 'usuario',
                'SMTPPassword' => 'clave', 'emailTienda' => 'tienda@ejemplo.invalid', 'nombreRemitente' => 'Tienda de pruebas',
            ],
            $configuracion
        );
    }

    /**
     * @estado rojo
     * @codigo-afectado clases/CorreoElectronico.php:41-51
     *
     * @group esperado
     * @group correo
     * @group bajo
     *
     * @que-ocurre-hoy Leer los datos del correo deja avisos de PHP en cada envío, y solo funciona si
     *   quien llama ya cargó la clase base de acceso a datos: si no, termina con un error fatal.
     * @que-deberia-ocurrir Que lea los datos sin avisos, la haya cargado o no quien llama.
     * @por-que-ocurre Incluye la clase de la tienda desde dentro de un método, y esa clase incluye su
     *   base con la variable global de la ruta, que dentro del método no existe.
     * @como-deberia-funcionar Incluir la clase de la tienda fuera del método, o con rutas que no
     *   dependan de globales.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_leerLosDatosDelCorreoNoDejaAvisos(): void
    {
        [, $avisos] = self::recogiendoAvisos(static fn () => \CorreoElectronico::leerConfiguracion());

        self::assertSame([], $avisos, 'Sin avisos de PHP.');
    }

    /**
     * @comportamiento Si el servidor no responde, el envío se da por fallido, con el motivo que da
     *   PHPMailer, y no se intenta la copia en enviados.
     */
    public function test_sinServidorQueRespondaElEnvioSeDaPorFallido(): void
    {
        [$respuesta] = self::recogiendoAvisos(fn () => \CorreoElectronico::enviar('cliente@ejemplo.invalid', 'Le adjuntamos el albarán.', 'Albarán', $this->adjunto));

        self::assertSame('KO', $respuesta['envio_destinatario']);
        self::assertStringStartsWith('Error en Mailer (Exception=Respuesta de $mail->send():SMTP connect() failed.', $respuesta['error_envio']);
        self::assertArrayNotHasKey('subido_enviados', $respuesta);
    }

    /**
     * @estado rojo
     * @codigo-afectado clases/CorreoElectronico.php:41-51
     *
     * @group esperado
     * @group correo
     * @group bajo
     *
     * @que-ocurre-hoy Si quien llama no cargó antes la clase base de acceso a datos, leer los datos
     *   del correo termina la petición con un error fatal.
     * @que-deberia-ocurrir Que lea los datos sin depender de lo que haya cargado quien llama.
     * @por-que-ocurre La clase de la tienda incluye su base con la variable global de la ruta, que
     *   dentro del método no existe: la base no se carga y la clase de la tienda no puede declararse.
     * @como-deberia-funcionar Incluir la clase de la tienda fuera del método, o con rutas que no
     *   dependan de globales.
     */
    public function test_sinLaClaseBaseCargadaLeerLosDatosNoTerminaLaPeticion(): void
    {
        $salida = self::ejecutarConElCorreo('CorreoElectronico::leerConfiguracion(); echo "datos leidos";');

        self::assertStringContainsString('datos leidos', $salida, 'Leer los datos no puede terminar la petición.');
    }

    /**
     * @estado rojo
     * @codigo-afectado clases/CorreoElectronico.php:1
     *
     * @group esperado
     * @group correo
     * @group bajo
     *
     * @que-ocurre-hoy Incluir la clase del correo escribe un salto de línea en la salida de la
     *   petición, antes de cualquier cabecera o del JSON que vaya a devolver.
     * @que-deberia-ocurrir Que incluirla no escriba nada.
     * @por-que-ocurre El fichero empieza con una línea en blanco antes de `<?php`, y todo lo que queda
     *   fuera del bloque de PHP se escribe tal cual.
     * @como-deberia-funcionar Que el fichero empiece por `<?php`.
     */
    public function test_incluirLaClaseDelCorreoNoEscribeNada(): void
    {
        $salida = self::ejecutarConElCorreo('', false);

        self::assertSame('', $salida, 'Incluir la clase no puede escribir en la salida.');
    }

    /**
     * Ejecuta el guion en un PHP aparte después de incluir la clase del correo, sin cargar antes la
     * clase base de acceso a datos, y devuelve lo que escribe tal cual, saltos de línea incluidos.
     * Pedido sin errores, los manda fuera.
     */
    private static function ejecutarConElCorreo(string $guion, bool $conErrores = true): string
    {
        $codigo = '$URLCom = ' . var_export(RUTA_TPVFOX, true) . ';'
            . ' $RutaServidor = ' . var_export(dirname(RUTA_TPVFOX), true) . '; $HostNombre = ' . var_export('/' . basename(RUTA_TPVFOX), true) . ';'
            . ' include $URLCom . "/clases/CorreoElectronico.php"; ' . $guion;
        $errores = $conErrores ? ' 2>&1' : ' 2>/dev/null';
        return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -r ' . escapeshellarg($codigo) . $errores);
    }

    /**
     * Ejecuta la llamada recogiendo los avisos de PHP en lugar de dejar que la suite los convierta en
     * error.
     *
     * @return array{0:mixed, 1:list<string>}
     */
    private static function recogiendoAvisos(callable $llamada): array
    {
        $avisos = [];
        set_error_handler(static function (int $nivel, string $mensaje) use (&$avisos): bool {
            $avisos[] = $mensaje;

            return true;
        });
        try {
            $resultado = $llamada();
        } finally {
            restore_error_handler();
        }

        return [$resultado, $avisos];
    }
}
