<?php

/**
 * Las funciones de pintado de `modulos/mod_usuario/funciones.php` que no consultan la base: el panel
 * desplegable de la ficha, la tabla de las configuraciones de módulo, la de las incidencias sin
 * resolver, las casillas de los permisos con la copia de los de otro usuario, y quitar al administrador
 * de una lista.
 *
 * Otros módulos declaran funciones con el mismo nombre que las de este fichero: cada caso corre en su
 * propio proceso. La descripción de cada permiso la da un sustituto de la clase de permisos, con el
 * nombre del módulo, de la vista o de la acción.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModUsuario;

use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class FuncionesUsuarioTest extends TestCase
{
    private const SCRIPT = '<script>x=1</script>';

    protected function setUp(): void
    {
        parent::setUp();
        require_once RUTA_TPVFOX . '/modulos/mod_usuario/funciones.php';
    }

    /** @comportamiento El panel desplegable lleva su título como enlace al cuerpo, recogido, con el número que lo distingue. */
    public function test_elPanelDesplegable(): void
    {
        $html = \htmlPanelDesplegable(3, 'Permisos', '<p>cuerpo</p>');

        self::assertStringContainsString('<a data-toggle="collapse" href="#collapse3">Permisos</a>', $html);
        self::assertStringContainsString('<div id="collapse3" class="panel-collapse collapse"><div class="panel-body"><p>cuerpo</p></div>', $html);
    }

    /**
     * @comportamiento La tabla de configuraciones enseña hasta diez, cada una con su módulo, su fecha y una
     *   papelera que la quita; sin ninguna, lo dice.
     */
    public function test_laTablaDeConfiguraciones(): void
    {
        $datos = array_map(fn ($i) => ['idusuario' => 5, 'nombre_modulo' => "mod_{$i}", 'fecha' => '2026-10-01 10:00:00'], range(1, 12));

        $html = \htmlTablaGeneral($datos, '/tpv', 'configuracion');

        self::assertSame(10, substr_count($html, '<tr><td>'));
        self::assertStringContainsString("<td>mod_1</td><td>2026-10-01 10:00:00</td><td><a class=\"glyphicon glyphicon-trash\" onclick=\"eliminarConfiguracionModulo(5, 'mod_1')\"></a></td>", $html);
        self::assertStringNotContainsString('mod_11', $html);
        self::assertStringContainsString('Este Usuario no tiene configuración de modulos', \htmlTablaGeneral(0, '/tpv', 'configuracion'));
    }

    /** @comportamiento La tabla de incidencias enseña hasta diez, con su número, su fecha, de dónde vienen y su mensaje; sin ninguna, lo dice. */
    public function test_laTablaDeIncidencias(): void
    {
        $datos = array_map(fn ($i) => ['num_incidencia' => $i, 'fecha_creacion' => '2026-10-01', 'dedonde' => 'tpv', 'mensaje' => "Aviso {$i}"], range(1, 11));

        $html = \htmlTablaIncidencias($datos);

        self::assertSame(10, substr_count($html, '<tr>') - 1);
        self::assertStringContainsString('<td>Aviso 10</td>', $html);
        self::assertStringNotContainsString('Aviso 11', $html);
        self::assertStringContainsString('Este Usuario no tiene incidencias sin Revisar', \htmlTablaIncidencias([]));
    }

    /**
     * @comportamiento Los permisos se pintan anidados —el módulo, sus vistas y sus acciones— con una casilla
     *   numerada por su posición, marcada si está concedido. Con el permiso de cambiar permisos, encima va la
     *   copia de los de otro usuario.
     */
    public function test_lasCasillasDeLosPermisos(): void
    {
        $html = \htmlPermisosUsuario($this->permisos(), 1, $this->descripciones(), ['datos' => [['id' => 7, 'username' => 'luis']]]);

        self::assertStringContainsString('Copiar permisos de : <select id="usuario"><option value="7">luis</option></select>', $html);
        self::assertStringContainsString('<span class="lead">Modulo de mod_usuario</span><input type="checkbox" id="modulo_0" value=1 class="permiso_0" name="permiso_0" checked ><br>', $html);
        self::assertStringContainsString('<input type="checkbox" value=1 class="permiso_1" id="vista_1" name="permiso_1"  ><b>usuario.php</b>', $html);
        self::assertStringContainsString('<input type="checkbox" class="permiso_2" value=1 id="accion_2" name="permiso_2" checked >permiso', $html);
        self::assertStringContainsString('Este Usuario no tiene permisos', \htmlPermisosUsuario([], 1, $this->descripciones(), ['datos' => []]));
    }

    /** @comportamiento Sin el permiso de cambiar permisos, no se ofrece la copia y las casillas sin marcar no se pueden marcar; las marcadas sí se pueden desmarcar. */
    public function test_sinElPermisoDeCambiarPermisosSoloSeVen(): void
    {
        $html = \htmlPermisosUsuario($this->permisos(), 0, $this->descripciones(), ['datos' => [['id' => 7, 'username' => 'luis']]]);

        self::assertStringNotContainsString('Copiar permisos de', $html);
        self::assertStringContainsString('name="permiso_1"  readonly="readonly" disabled>', $html);
        self::assertStringContainsString('name="permiso_2" checked >', $html);
    }

    /** @comportamiento Quitar al administrador de una lista quita el usuario 1, llegue como número o como texto, y deja los demás. */
    public function test_quitarAlAdministradorDeUnaLista(): void
    {
        self::assertSame([1 => '5', 2 => '7'], \eliminarIdAdmin(['1', '5', '7']));
        self::assertSame([0 => 5], \eliminarIdAdmin([5, 1]));
        self::assertSame(['5'], \eliminarIdAdmin(['5']));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_usuario/funciones.php:203-209
     *
     * @group defecto
     * @group salida
     * @group alto
     *
     * @que-ocurre-hoy La ficha del usuario escribe el mensaje de cada incidencia tal cual: un mensaje con una
     *   etiqueta de script la ejecuta al abrir la ficha.
     * @que-deberia-ocurrir Que el mensaje, y los demás datos de la incidencia, se escriban como texto.
     * @por-que-ocurre La tabla de incidencias escribe lo leído sin escaparlo.
     * @como-deberia-funcionar Escapar cada dato al escribirlo.
     */
    public function test_laTablaDeIncidenciasEscribeElMensajeComoTexto(): void
    {
        $html = \htmlTablaIncidencias([['num_incidencia' => 1, 'fecha_creacion' => '2026-10-01', 'dedonde' => 'tpv', 'mensaje' => self::SCRIPT]]);

        self::assertStringNotContainsString(self::SCRIPT, $html);
        self::assertStringContainsString(htmlspecialchars(self::SCRIPT), $html);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_usuario/funciones.php:233-236
     *
     * @group defecto
     * @group salida
     * @group medio
     *
     * @que-ocurre-hoy La copia de permisos escribe el nombre de acceso de cada usuario tal cual: uno con una
     *   etiqueta de script la ejecuta al abrir la ficha de cualquier usuario.
     * @que-deberia-ocurrir Que el nombre de acceso se escriba como texto.
     * @por-que-ocurre El desplegable de la copia escribe lo leído sin escaparlo.
     * @como-deberia-funcionar Escapar el nombre al escribirlo.
     */
    public function test_laCopiaDePermisosEscribeElNombreComoTexto(): void
    {
        $html = \htmlPermisosUsuario($this->permisos(), 1, $this->descripciones(), ['datos' => [['id' => 7, 'username' => self::SCRIPT]]]);

        self::assertStringNotContainsString(self::SCRIPT, $html);
        self::assertStringContainsString(htmlspecialchars(self::SCRIPT), $html);
    }

    /** @return list<array<string, mixed>> */
    private function permisos(): array
    {
        return [
            ['modulo' => 'mod_usuario', 'vista' => null, 'accion' => null, 'permiso' => 1],
            ['modulo' => 'mod_usuario', 'vista' => 'usuario.php', 'accion' => null, 'permiso' => 0],
            ['modulo' => 'mod_usuario', 'vista' => 'usuario.php', 'accion' => 'permiso', 'permiso' => 1],
        ];
    }

    /** Sustituto de la clase de permisos: describe cada permiso con el nombre recibido. */
    private function descripciones(): object
    {
        return new class () {
            public function ObtenerDescripcion($nombre, $permiso): string
            {
                return (string) $nombre;
            }
        };
    }
}
