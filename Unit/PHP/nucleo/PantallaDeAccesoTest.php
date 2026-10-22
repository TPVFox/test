<?php

/**
 * La pantalla de acceso y de cierre de sesión (`plugins/controlUser/modalUsuario.php`), la que pinta
 * la cabecera común cuando la sesión no es correcta.
 *
 * Cada caso ejecuta la pantalla real en un PHP aparte, porque termina la petición con `exit`. La
 * sesión, los permisos y el menú que necesita se le dan hechos: aquí solo se mira qué pinta la
 * pantalla con lo que la sesión le deja.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\Nucleo;

use PHPUnit\Framework\TestCase;

final class PantallaDeAccesoTest extends TestCase
{
    /**
     * @comportamiento Con un aviso de tipo peligro —por ejemplo, dos tiendas principales activas—,
     *   la pantalla de acceso lo enseña y termina sin pintar el formulario.
     */
    public function test_conUnAvisoDePeligroEnsenaElAvisoYNoPintaElFormulario(): void
    {
        $html = self::pintar('Error', [['tipo' => 'danger', 'mensaje' => 'Hay mas de una tienda principal como activo']]);

        self::assertStringContainsString('alert-danger', $html);
        self::assertStringContainsString('Hay mas de una tienda principal como activo', $html);
        self::assertStringNotContainsString('id="usr"', $html, 'Con un aviso de peligro no se pinta el formulario.');
    }

    /**
     * @comportamiento Con un aviso de advertencia —una contraseña equivocada—, la pantalla lo enseña
     *   y vuelve a pintar el formulario.
     */
    public function test_conUnAvisoDeAdvertenciaEnsenaElAvisoYElFormulario(): void
    {
        $html = self::pintar('Error', [['tipo' => 'warning', 'mensaje' => 'Usuario o contraseña incorrecta']]);

        self::assertStringContainsString('alert-warning', $html);
        self::assertStringContainsString('id="usr"', $html);
        self::assertStringContainsString('id="pwd"', $html);
    }

    /**
     * @comportamiento Con la sesión correcta y la orden de cerrar en la dirección, la pantalla
     *   despide al usuario por su nombre y cierra la sesión.
     */
    public function test_conLaSesionCorrectaYLaOrdenDeCerrarDespideYCierra(): void
    {
        $html = self::pintar('Correcto', [], ['tipo' => 'cerrar']);

        self::assertStringContainsString('Cerramos session,Persona de pruebas', $html);
        self::assertStringContainsString('[sesion cerrada]', $html);
    }

    /**
     * @comportamiento Con la orden de cerrar pero sin sesión correcta, la pantalla no cierra nada:
     *   se titula «Cierre sesion» y pinta el formulario de acceso.
     */
    public function test_sinSesionCorrectaLaOrdenDeCerrarPintaElFormulario(): void
    {
        $html = self::pintar('SinActivar', [], ['tipo' => 'cerrar']);

        self::assertStringContainsString('<h1>Cierre sesion</h1>', $html);
        self::assertStringContainsString('id="usr"', $html);
        self::assertStringNotContainsString('[sesion cerrada]', $html);
    }

    /**
     * @estado rojo
     * @codigo-afectado plugins/controlUser/modalUsuario.php:63-77
     *
     * @group esperado
     * @group sesion
     * @group bajo
     *
     * @que-ocurre-hoy Basta con que el navegador cargue la dirección de cierre para que la sesión se
     *   cierre: cualquier página puede hacerlo cargar esa dirección.
     * @que-deberia-ocurrir Que la sesión solo se cierre con una petición que venga de la propia
     *   aplicación.
     * @por-que-ocurre La pantalla cierra la sesión en cuanto recibe `tipo=cerrar` en la dirección,
     *   sin nada que pruebe de dónde viene.
     * @como-deberia-funcionar Cerrar con una petición que lleve una prueba de origen, como un dato
     *   de la sesión que solo conoce la página de la aplicación.
     */
    public function test_laDireccionDeCierreSolaNoCierraLaSesion(): void
    {
        $html = self::pintar('Correcto', [], ['tipo' => 'cerrar']);

        self::assertStringNotContainsString('[sesion cerrada]', $html, 'Una dirección cargada desde fuera no debería cerrar la sesión.');
    }

    /**
     * Ejecuta la pantalla de acceso con el estado de la sesión, los avisos y la dirección que se le
     * den, y devuelve lo que pinta. El cierre de sesión se anota como «[sesion cerrada]».
     *
     * @param list<array{tipo:string, mensaje:string}> $avisos
     * @param array<string,string> $direccion
     */
    private static function pintar(string $estado, array $avisos, array $direccion = []): string
    {
        $guion = 'class ClaseSession {}'
            . ' $URLCom = ' . var_export(RUTA_TPVFOX, true) . '; $HostNombre = "/TPVFox";'
            . ' $_SESSION = ["estadoTpv" => ' . var_export($estado, true) . ', "N_Pagina_Abiertas" => 0, "usuarioTpv" => ["nombre" => "Persona de pruebas"]];'
            . ' $_GET = ' . var_export($direccion, true) . ';'
            . ' $Usuario = ["id" => 0, "login" => "invitado"]; $Tienda = ["razonsocial" => ""];'
            . ' $ClasePermisos = new class { public function comprobarPermisos($p, $m, $v) { return ["permiso" => "No Ok"]; } };'
            . ' $thisTpv = new class { public $permisos;'
            . '   public function __construct() { $this->permisos = (object) ["permisos" => ["resultado" => []]]; }'
            . '   public function GetComprobaciones() { return ' . var_export($avisos, true) . '; }'
            . '   public function cerrarSession() { echo "[sesion cerrada]"; } };'
            . ' include ' . var_export(RUTA_TPVFOX . '/plugins/controlUser/modalUsuario.php', true) . ';';

        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -r ' . escapeshellarg($guion) . ' 2>/dev/null', $salida);

        return implode("\n", $salida);
    }
}
