<?php

/**
 * La petición de fondo de `mod_usuario` (`modulos/mod_usuario/tareas.php`) y la tarea que incluye
 * (`tareas/AccionesMultiplesUsuarios.php`): las acciones sobre varios usuarios —enseñar a quién alcanzan
 * y confirmarlas: activar, inactivar, borrar y el cambio de año—, quitar una configuración de módulo y
 * leer los permisos de otro usuario para copiarlos.
 *
 * Cada petición se ejecuta en un PHP aparte, desde la carpeta del módulo, con la sesión del usuario de
 * pruebas o sin ninguna, los permisos del módulo concedidos salvo los que el caso quite, y una
 * transacción que se deshace al terminar. Los usuarios que siembra el caso quedan con un nombre —`:u1`,
 * `:u2`…— para el formulario; el administrador, el usuario 1, no está en la base de pruebas, y el caso
 * que lo necesita lo crea con ese número. Al terminar, el proceso responde lo que dan las consultas del
 * caso, también si la petición acaba con un error.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModUsuario;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class TareasUsuariosIntegracionTest extends CasoIntegracion
{
    private int $idUsuario;

    private int $idTienda;

    protected function setUp(): void
    {
        parent::setUp();
        $siembra = new Siembra($this->db);
        $this->idUsuario = $siembra->usuarioPorDefecto();
        $this->idTienda = $siembra->tiendaPorDefecto();
    }

    /**
     * @comportamiento Las acciones sobre varios usuarios dicen cuántos hay marcados y ofrecen el cambio de
     *   año, activar, inactivar, borrar y asignar grupo; sin ninguno marcado, solo el cambio de año.
     */
    public function test_lasAccionesSobreVariosUsuarios(): void
    {
        $marcados = $this->ejecutar(['pulsado' => 'accionesMultiplesUsuarios', 'idsSeleccionados' => ['5', '6']]);
        $ninguno = $this->ejecutar(['pulsado' => 'accionesMultiplesUsuarios']);

        self::assertStringContainsString('<p>Usuarios seleccionados: 2</p>', $marcados['respuesta']['html']);
        self::assertSame(5, substr_count($marcados['respuesta']['html'], '<button'));
        self::assertStringContainsString('onclick="asignarGrupoUsuarios()"', $marcados['respuesta']['html']);
        self::assertStringContainsString('<p>Usuarios seleccionados: 0 </p>', $ninguno['respuesta']['html']);
        self::assertSame(1, substr_count($ninguno['respuesta']['html'], '<button'));
        self::assertStringContainsString('<div id="submenuAccionesMultiplesUsuarios"></div>', $ninguno['respuesta']['html']);
    }

    /** @comportamiento Cada acción enseña antes a quién alcanza, por su nombre. */
    public function test_cadaAccionEnsenaAQuienAlcanza(): void
    {
        $preparacion = [$this->usuario('ana', 'Ana Pérez')];

        foreach (['inactivarUsuarios' => 'Inactivar Usuarios', 'eliminarUsuarios' => 'Eliminar Usuarios', 'activarUsuarios' => 'Activar Usuarios', 'cambiarAnoUsuarios' => 'Cambiar Año Usuarios'] as $orden => $titulo) {
            $html = $this->ejecutar(['pulsado' => $orden, 'idsSeleccionados' => [':u1']], $preparacion)['respuesta']['html'];

            self::assertStringContainsString("<h4>{$titulo} Seleccionados</h4>", $html, $orden);
            self::assertStringContainsString('<li>Ana Pérez</li>', $html, $orden);
        }
    }

    /** @comportamiento Confirmar inactivar inactiva a los marcados salvo al administrador; confirmar activar los activa. */
    public function test_inactivarYActivarLosMarcados(): void
    {
        $preparacion = [$this->administrador(), $this->usuario('ana')];
        $consultas = ['admin' => 'SELECT estado FROM usuarios WHERE id = 1', 'ana' => 'SELECT estado FROM usuarios WHERE id = :u1'];

        $inactivar = $this->ejecutar(['pulsado' => 'confirmarInactivarUsuarios', 'idsSeleccionados' => ['1', ':u1']], $preparacion, true, $consultas);
        $activar = $this->ejecutar(['pulsado' => 'confirmarActivarUsuarios', 'idsSeleccionados' => [':u1']], [...$preparacion, "UPDATE usuarios SET estado = 'inactivo' WHERE id = :u1"], true, $consultas);

        self::assertSame('Operación realizada con éxito.', $inactivar['respuesta']['mensaje']);
        self::assertSame(['admin' => 'activo', 'ana' => 'inactivo'], $inactivar['consultas']);
        self::assertSame('activo', $activar['consultas']['ana']);
    }

    /** @comportamiento Confirmar borrar borra a los marcados salvo al administrador. */
    public function test_borrarLosMarcadosSalvoAlAdministrador(): void
    {
        $borrar = $this->ejecutar(['pulsado' => 'confirmarEliminarUsuarios', 'idsSeleccionados' => ['1', ':u1']], [$this->administrador(), $this->usuario('ana')], true, [
            'admin' => 'SELECT COUNT(*) FROM usuarios WHERE id = 1',
            'ana' => 'SELECT COUNT(*) FROM usuarios WHERE id = :u1',
        ]);

        self::assertSame('Operación realizada con éxito.', $borrar['respuesta']['mensaje']);
        self::assertSame(['admin' => '1', 'ana' => '0'], $borrar['consultas']);
    }

    /**
     * @comportamiento El cambio de año, con el administrador en la lista como lo envía la pantalla, da la
     *   contraseña nueva a los marcados y los deja activos, e inactiva a todos los demás.
     */
    public function test_elCambioDeAno(): void
    {
        $cambio = $this->ejecutar(['pulsado' => 'confirmarCambiarAnoUsuarios', 'idsSeleccionados' => ['1', ':u1'], 'nuevaContrasena' => 'Ano-2027'], [$this->administrador(), $this->usuario('ana'), $this->usuario('luis')], true, [
            'admin' => "SELECT CONCAT(estado, '|', password) FROM usuarios WHERE id = 1",
            'ana' => "SELECT CONCAT(estado, '|', password) FROM usuarios WHERE id = :u1",
            'luis' => "SELECT CONCAT(estado, '|', password) FROM usuarios WHERE id = :u2",
            'activos' => "SELECT COUNT(*) FROM usuarios WHERE estado <> 'inactivo'",
        ]);

        self::assertSame('Operación realizada con éxito.', $cambio['respuesta']['mensaje']);
        self::assertSame('activo|' . md5('Ano-2027'), $cambio['consultas']['admin']);
        self::assertSame('activo|' . md5('Ano-2027'), $cambio['consultas']['ana']);
        self::assertSame('inactivo|' . md5('anterior'), $cambio['consultas']['luis']);
        self::assertSame('2', $cambio['consultas']['activos']);
    }

    /** @comportamiento Quitar una configuración de módulo quita la de ese usuario y ese módulo, y responde vacío. */
    public function test_quitarUnaConfiguracionDeModulo(): void
    {
        $quitar = $this->ejecutar(['pulsado' => 'eliminarConfigModulo', 'idUsuario' => ':u1', 'modulo' => 'mod_producto'], [
            $this->usuario('ana'),
            ...$this->configuraciones(':u1'),
        ], true, ['ana' => 'SELECT GROUP_CONCAT(nombre_modulo) FROM modulos_configuracion WHERE idusuario = :u1']);

        self::assertSame([], $quitar['respuesta']);
        self::assertSame('mod_compras', $quitar['consultas']['ana']);
    }

    /** @comportamiento Copiar permisos lee los permisos de otro usuario, uno por fila y en el orden de las casillas. */
    public function test_copiarLosPermisosDeOtroUsuario(): void
    {
        $copia = $this->ejecutar(['pulsado' => 'copiarPermisosUsuario', 'usuarioNuevo' => ':u1'], [
            $this->usuario('ana'),
            "INSERT INTO permisos (idUsuario, modulo, vista, accion, permiso) VALUES (:u1, 'mod_usuario', NULL, NULL, 1)",
        ]);
        $propios = array_values(array_filter($copia['respuesta']['permisosUsuario']['resultado'], fn ($p) => $p['modulo'] === 'mod_usuario' && $p['vista'] === null));

        self::assertSame((string) $copia['ids'][':u1'], (string) $propios[0]['idUsuario']);
        self::assertSame('1', (string) $propios[0]['permiso']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_usuario/tareas.php:1-11
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy Una petición sin sesión borra usuarios.
     * @que-deberia-ocurrir Que sin sesión la petición no haga nada.
     * @por-que-ocurre La petición carga el arranque común sin la cabecera, que es la que pide la sesión, y no
     *   la comprueba; la causa común es la del núcleo.
     * @como-deberia-funcionar Comprobar la sesión antes de atender la orden.
     */
    public function test_sinSesionNoSeBorranUsuarios(): void
    {
        $borrar = $this->ejecutar(['pulsado' => 'confirmarEliminarUsuarios', 'idsSeleccionados' => [':u1']], [$this->usuario('ana')], false, ['ana' => 'SELECT COUNT(*) FROM usuarios WHERE id = :u1']);

        self::assertSame('1', $borrar['consultas']['ana']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_usuario/tareas.php:11-130
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy Un usuario con sesión pero sin permiso en el módulo de usuarios ni en su listado borra
     *   usuarios por la petición de fondo.
     * @que-deberia-ocurrir Que sin el permiso del listado de usuarios no se ejecute ninguna acción sobre
     *   ellos.
     * @por-que-ocurre La petición no consulta ningún permiso.
     * @como-deberia-funcionar Consultar el permiso del listado, o el de una acción propia de cada orden,
     *   antes de atenderla.
     */
    public function test_sinPermisoNoSeBorranUsuarios(): void
    {
        $borrar = $this->ejecutar(['pulsado' => 'confirmarEliminarUsuarios', 'idsSeleccionados' => [':u1']], [
            $this->usuario('ana'),
            "UPDATE permisos SET permiso = 0 WHERE idUsuario = :usuario AND modulo = 'mod_usuario'",
        ], true, ['ana' => 'SELECT COUNT(*) FROM usuarios WHERE id = :u1']);

        self::assertSame('1', $borrar['consultas']['ana']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_usuario/tareas.php:100-109
     * @codigo-afectado modulos/mod_usuario/clases/claseUsuarios.php:95-105
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy Un número de la lista con una condición añadida borra también a otros usuarios.
     * @que-deberia-ocurrir Que solo se borren los usuarios cuyo número llega.
     * @por-que-ocurre La lista se pega a la sentencia sin convertir cada elemento en un número.
     * @como-deberia-funcionar Convertir cada elemento en un número antes de componer la sentencia, o pasarlos
     *   como datos.
     */
    public function test_unaListaConUnaCondicionNoBorraAOtros(): void
    {
        $borrar = $this->ejecutar(['pulsado' => 'confirmarEliminarUsuarios', 'idsSeleccionados' => [':u1) OR (id = :u2']], [$this->usuario('ana'), $this->usuario('luis')], true, ['luis' => 'SELECT COUNT(*) FROM usuarios WHERE id = :u2']);

        self::assertSame('1', $borrar['consultas']['luis']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_usuario/tareas.php:57-69
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy El cambio de año pedido sin el administrador en la lista lo deja inactivo: nadie puede
     *   entrar a gestionar los usuarios.
     * @que-deberia-ocurrir Que el administrador quede siempre activo, como en inactivar y borrar.
     * @por-que-ocurre Solo la pantalla añade al administrador a la lista; la petición confirma con la lista
     *   que recibe.
     * @como-deberia-funcionar Añadir al administrador a la lista en la petición, como hace la pantalla.
     */
    public function test_elCambioDeAnoNoInactivaAlAdministrador(): void
    {
        $cambio = $this->ejecutar(['pulsado' => 'confirmarCambiarAnoUsuarios', 'idsSeleccionados' => [':u1'], 'nuevaContrasena' => 'Ano-2027'], [$this->administrador(), $this->usuario('ana')], true, ['admin' => 'SELECT estado FROM usuarios WHERE id = 1']);

        self::assertSame('activo', $cambio['consultas']['admin']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_usuario/tareas.php:57-69
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Un cambio de año que la base rechaza a mitad —con una contraseña con comillas— deja
     *   inactivos a todos los demás usuarios sin haber cambiado la contraseña de los marcados.
     * @que-deberia-ocurrir Que el cambio de año se haga entero o no se haga.
     * @por-que-ocurre Las tres escrituras —inactivar al resto, cambiar la contraseña y activar a los
     *   marcados— se hacen una tras otra, fuera de una transacción.
     * @como-deberia-funcionar Hacer las tres en una transacción, y deshacerla si una falla.
     */
    public function test_unCambioDeAnoQueFallaNoDejaANadieInactivo(): void
    {
        $cambio = $this->ejecutar(['pulsado' => 'confirmarCambiarAnoUsuarios', 'idsSeleccionados' => ['1', ':u1'], 'nuevaContrasena' => 'Ano"2027'], [$this->administrador(), $this->usuario('ana'), $this->usuario('luis')], true, ['luis' => 'SELECT estado FROM usuarios WHERE id = :u2']);

        self::assertSame('activo', $cambio['consultas']['luis']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_usuario/tareas.php:80-89
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Confirmar la inactivación con solo el administrador marcado termina la petición con un
     *   error de la base, sin mensaje.
     * @que-deberia-ocurrir Que diga que no hay usuarios que inactivar.
     * @por-que-ocurre Al quitar al administrador, la lista queda vacía y la sentencia no es válida.
     * @como-deberia-funcionar No componer la sentencia con la lista vacía, y responder con un mensaje.
     */
    public function test_inactivarSoloAlAdministradorResponde(): void
    {
        $inactivar = $this->ejecutar(['pulsado' => 'confirmarInactivarUsuarios', 'idsSeleccionados' => ['1']], [$this->administrador()]);

        self::assertIsArray($inactivar['respuesta']);
        self::assertArrayHasKey('mensaje', $inactivar['respuesta']);
        self::assertStringNotContainsString('Fatal error', $inactivar['errores']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_usuario/tareas.php:100-109
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Borrar un usuario que ya tiene documentos termina la petición con un error de la base,
     *   sin mensaje: la pantalla se queda esperando.
     * @que-deberia-ocurrir Que no se borre y se diga por qué, o que se ofrezca inactivarlo.
     * @por-que-ocurre Los documentos tienen una clave ajena hacia el usuario, y el error de la base llega
     *   como excepción que nadie recoge.
     * @como-deberia-funcionar Comprobar antes si el usuario tiene documentos, o recoger el error y
     *   responderlo.
     */
    public function test_borrarUnUsuarioConDocumentosResponde(): void
    {
        $borrar = $this->ejecutar(['pulsado' => 'confirmarEliminarUsuarios', 'idsSeleccionados' => [':u1']], [
            $this->usuario('ana'),
            "INSERT INTO pedprotemporales (Fecha, idUsuario) VALUES ('2026-10-01', :u1)",
        ], true, ['ana' => 'SELECT COUNT(*) FROM usuarios WHERE id = :u1']);

        self::assertIsArray($borrar['respuesta']);
        self::assertArrayHasKey('mensaje', $borrar['respuesta']);
        self::assertSame('1', $borrar['consultas']['ana']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_usuario/tareas.php:26-36
     * @codigo-afectado modulos/mod_usuario/clases/claseUsuarios.php:15-26
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Un número de usuario con una condición añadida quita la configuración de módulo de
     *   otros usuarios.
     * @que-deberia-ocurrir Que solo se quite la del usuario y el módulo indicados.
     * @por-que-ocurre El usuario y el módulo se pegan a la sentencia sin convertirlos ni escaparlos.
     * @como-deberia-funcionar Tomar el usuario como un número y pasar el módulo como dato.
     */
    public function test_quitarUnaConfiguracionNoQuitaLaDeOtros(): void
    {
        $quitar = $this->ejecutar(['pulsado' => 'eliminarConfigModulo', 'idUsuario' => ':u1 OR 1=1', 'modulo' => 'mod_producto'], [
            $this->usuario('ana'),
            $this->usuario('luis'),
            ...$this->configuraciones(':u2'),
        ], true, ['luis' => 'SELECT COUNT(*) FROM modulos_configuracion WHERE idusuario = :u2']);

        self::assertSame('2', $quitar['consultas']['luis']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_usuario/tareas.php:15-25
     * @codigo-afectado modulos/mod_usuario/tareas.php:130-131
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy La orden «CopiarDescripcion» termina la petición con un error de PHP, y una orden que
     *   la petición no conoce responde vacío con avisos de PHP.
     * @que-deberia-ocurrir Que una orden que no se puede atender responda un error, sin avisos.
     * @por-que-ocurre «CopiarDescripcion» llama a una función que no existe en el producto; una orden
     *   desconocida no da valor a la respuesta.
     * @como-deberia-funcionar Quitar la orden que no tiene función, y dar una respuesta de error a la
     *   desconocida.
     */
    public function test_lasOrdenesQueNoSeAtiendenRespondenUnError(): void
    {
        $copiar = $this->ejecutar(['pulsado' => 'CopiarDescripcion', 'id' => '1', 'DatosRefCruzadas' => '']);
        $otra = $this->ejecutar(['pulsado' => 'ordenQueNoExiste']);

        foreach ([$copiar, $otra] as $ejecucion) {
            self::assertSame('', $ejecucion['errores']);
            self::assertArrayHasKey('error', (array) $ejecucion['respuesta']);
        }
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_usuario/tareas.php:120-129
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Confirmar una acción sin ningún usuario en la lista termina la petición con un error
     *   de PHP, sin mensaje.
     * @que-deberia-ocurrir Que diga que no hay usuarios a los que aplicarla.
     * @por-que-ocurre La confirmación toma la lista sin comprobar que llegue, y la clase la une a la
     *   sentencia aunque no sea una lista.
     * @como-deberia-funcionar Comprobar que llega una lista con algún usuario, y responder con un mensaje si
     *   no.
     */
    public function test_confirmarSinUsuariosMarcadosResponde(): void
    {
        $activar = $this->ejecutar(['pulsado' => 'confirmarActivarUsuarios']);

        self::assertIsArray($activar['respuesta']);
        self::assertArrayHasKey('mensaje', $activar['respuesta']);
        self::assertStringNotContainsString('Fatal error', $activar['errores']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_usuario/tareas.php:57-69
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Un cambio de año pedido con la contraseña vacía la guarda vacía a los marcados, e
     *   inactiva al resto.
     * @que-deberia-ocurrir Que la petición rechace una contraseña vacía y no cambie nada.
     * @por-que-ocurre Solo la pantalla exige la contraseña; la petición confirma con la que recibe.
     * @como-deberia-funcionar Comprobar en la petición que la contraseña nueva no está vacía antes de
     *   escribir.
     */
    public function test_elCambioDeAnoSinContrasenaNoLaCambia(): void
    {
        $cambio = $this->ejecutar(['pulsado' => 'confirmarCambiarAnoUsuarios', 'idsSeleccionados' => ['1', ':u1'], 'nuevaContrasena' => ''], [$this->administrador(), $this->usuario('ana')], true, ['ana' => 'SELECT password FROM usuarios WHERE id = :u1']);

        self::assertSame(md5('anterior'), $cambio['consultas']['ana']);
    }

    private function usuario(string $acceso, string $nombre = 'Empleado de pruebas'): string
    {
        return "INSERT INTO usuarios (username, password, fecha, group_id, estado, nombre) VALUES ('{$acceso}', MD5('anterior'), '2026-01-01', 1, 'activo', '{$nombre}')";
    }

    private function administrador(): string
    {
        return "INSERT INTO usuarios (id, username, password, fecha, group_id, estado, nombre) VALUES (1, 'admin', MD5('anterior'), '2026-01-01', 9, 'activo', 'Administrador')";
    }

    /** @return list<string> */
    private function configuraciones(string $usuario): array
    {
        return array_map(fn ($modulo) => "INSERT INTO modulos_configuracion (idusuario, nombre_modulo, configuracion, fecha) VALUES ({$usuario}, '{$modulo}', '{}', '2026-10-01 10:00:00')", ['mod_producto', 'mod_compras']);
    }

    /**
     * Ejecuta la petición de fondo en un PHP aparte y devuelve su respuesta, sus avisos, los nombres de lo
     * sembrado y lo que responden las consultas del caso al terminar.
     *
     * @param array<string, mixed> $formulario
     * @param list<string> $preparacion
     * @param array<string, string> $consultas
     * @return array{respuesta:mixed, errores:string, ids:array<string, string>, consultas:array<string, ?string>}
     */
    private function ejecutar(array $formulario, array $preparacion = [], bool $conSesion = true, array $consultas = []): array
    {
        $sesion = $conSesion ? [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => $this->idUsuario, 'group_id' => 1, 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => $this->idTienda, 'razonsocial' => 'Tienda de pruebas'],
        ] : [];
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["DOCUMENT_ROOT"] = ""; $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = "/TPVFox/modulos/mod_usuario/tareas.php";'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_usuario', true) . ');'
            . ' require "./../../inicial.php";'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ($conSesion ? ' new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' $BDTpv->query("UPDATE permisos SET permiso = 1 WHERE idUsuario = ' . $this->idUsuario . ' AND modulo = \'mod_usuario\'");'
            . ' $sembrados = [":usuario" => "' . $this->idUsuario . '", ":tienda" => "' . $this->idTienda . '"]; $usuarios = 0;'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->query(strtr($sql, $sembrados));'
            . ' if (str_starts_with($sql, "INSERT INTO usuarios (username")) { $sembrados[":u" . ++$usuarios] = (string) $BDTpv->insert_id; } }'
            . ' $_POST = ' . var_export($formulario, true) . ';'
            . ' array_walk_recursive($_POST, function (&$valor) use ($sembrados) { $valor = strtr((string) $valor, $sembrados); });'
            . ($conSesion ? ' $ClasePermisos = $thisTpv->permisos = new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' register_shutdown_function(function () use ($BDTpv, &$sembrados) { $resultados = [];'
            . ' foreach (' . var_export($consultas, true) . ' as $nombre => $sql) { $fila = $BDTpv->query(strtr($sql, $sembrados))->fetch_row(); $resultados[$nombre] = $fila === null ? null : (string) $fila[0]; }'
            . ' echo "\n@@ESTADO@@" . json_encode(["ids" => $sembrados, "consultas" => $resultados]); });'
            . ' include "tareas.php";';

        $errores = (string) tempnam(sys_get_temp_dir(), 'usuarios');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$escrito, $estado] = array_pad(explode('@@ESTADO@@', implode("\n", $salida), 2), 2, 'null');
        $estado = json_decode($estado, true) ?? ['ids' => [], 'consultas' => []];
        $ejecucion = ['respuesta' => json_decode(trim($escrito), true), 'errores' => (string) file_get_contents($errores)] + $estado;
        unlink($errores);

        return $ejecucion;
    }
}
