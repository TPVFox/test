<?php

/**
 * Lo que la sesión de TPVFox (`clases/ClaseSession.php`) decide sin tocar la base: si lo que hay en
 * `$_SESSION` basta para darla por correcta, cómo guarda sus avisos, el token de un usuario y el
 * nombre de la pantalla que se pide, que es el título de la página.
 *
 * Se construye sin constructor, que abre la conexión. Cada caso corre en un proceso aparte por el
 * mismo motivo que la integración de la sesión: `ClaseSession.php` incluye `ClaseConexion.php` con
 * `include`, y no admite que otra prueba la haya cargado antes.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\Nucleo;

use PHPUnit\Framework\TestCase;

final class SesionTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    /**
     * @comportamiento Con usuario —nombre y login— y tienda —razón social e identificador— la
     *   sesión es correcta, y el usuario de la sesión pasa a ser el suyo.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_conUsuarioYTiendaCompletosLaSesionEsCorrecta(): void
    {
        $_SESSION = [
            'usuarioTpv' => ['id' => 7, 'nombre' => 'Persona', 'login' => 'persona', 'group_id' => 1],
            'tiendaTpv' => ['idTienda' => 1, 'razonsocial' => 'Tienda'],
        ];
        $sesion = self::sesionSinConexion();

        self::assertSame('Correcto', $sesion->controlSession());
        self::assertSame($_SESSION['usuarioTpv'], $sesion->Usuario);
    }

    /**
     * @comportamiento Basta con que falte uno de los cuatro datos —login, nombre, razón social o
     *   identificador de la tienda— para que la sesión sea errónea.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_siFaltaUnoDeLosCuatroDatosLaSesionEsErronea(): void
    {
        $completa = [
            'usuarioTpv' => ['id' => 7, 'nombre' => 'Persona', 'login' => 'persona'],
            'tiendaTpv' => ['idTienda' => 1, 'razonsocial' => 'Tienda'],
        ];
        $faltas = [
            'login' => ['usuarioTpv', 'login'],
            'nombre' => ['usuarioTpv', 'nombre'],
            'razón social' => ['tiendaTpv', 'razonsocial'],
            'identificador de la tienda' => ['tiendaTpv', 'idTienda'],
        ];
        $sesion = self::sesionSinConexion();

        foreach ($faltas as $falta => [$grupo, $clave]) {
            $_SESSION = $completa;
            unset($_SESSION[$grupo][$clave]);
            self::assertSame('Erroneo', $sesion->controlSession(), 'Sin ' . $falta);
        }
    }

    /**
     * @comportamiento Sin usuario en la sesión, el de la sesión es el invitado y la sesión es
     *   errónea.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_sinUsuarioElDeLaSesionEsElInvitado(): void
    {
        $_SESSION = ['tiendaTpv' => ['idTienda' => 1, 'razonsocial' => 'Tienda']];
        $sesion = self::sesionSinConexion();

        self::assertSame('Erroneo', $sesion->controlSession());
        self::assertSame(['id' => 0, 'group_id' => 0, 'login' => 'invitado'], $sesion->Usuario);
    }

    /**
     * @comportamiento Un aviso se guarda si llega como array; cualquier otra cosa se descarta sin
     *   decir nada.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_soloSeGuardanLosAvisosQueLleganComoArray(): void
    {
        $sesion = self::sesionSinConexion();
        $sesion->comprobaciones = [];

        $sesion->SetComprobaciones(['tipo' => 'warning', 'mensaje' => 'Un aviso', 'dato' => '']);
        $sesion->SetComprobaciones('Un texto suelto');

        self::assertSame([['tipo' => 'warning', 'mensaje' => 'Un aviso', 'dato' => '']], $sesion->GetComprobaciones());
    }

    /**
     * @comportamiento El token de un usuario es un resumen sha256 de 64 caracteres hexadecimales,
     *   distinto para dos usuarios en el mismo segundo.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_elTokenDeUnUsuarioEsUnSha256DistintoParaCadaUsuario(): void
    {
        $sesion = self::sesionSinConexion();

        $uno = $sesion->getTokenUsuario(['id' => 1]);
        $otro = $sesion->getTokenUsuario(['id' => 2]);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $uno);
        self::assertNotSame($uno, $otro);
    }

    /**
     * @comportamiento El nombre de la pantalla es lo que va entre la última barra y el primer
     *   punto: sin extensión ni parámetros. Una dirección que acaba en barra da un nombre vacío.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_elNombreDeLaPantallaEsElFicheroSinExtensionNiParametros(): void
    {
        $sesion = self::sesionSinConexion();

        self::assertSame('albaran', $sesion->getNombreFichero('/tpvfox/modulos/mod_venta/albaran.php?id=3'));
        self::assertSame('index', $sesion->getNombreFichero('/tpvfox/index.php'));
        self::assertSame('', $sesion->getNombreFichero('/tpvfox/'));
    }

    /** La sesión sin pasar por su constructor, que abriría la conexión y leería la base. */
    private static function sesionSinConexion(): \ClaseSession
    {
        global $RutaServidor, $HostNombre;
        require_once RUTA_TPVFOX . '/clases/ClaseSession.php';

        return (new \ReflectionClass(\ClaseSession::class))->newInstanceWithoutConstructor();
    }
}
