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
