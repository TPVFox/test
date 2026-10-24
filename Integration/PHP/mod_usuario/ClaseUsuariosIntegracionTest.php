<?php

/**
 * La clase de usuarios de `modulos/mod_usuario/clases/claseUsuarios.php` (`ClaseUsuarios`): leer un
 * usuario, su nombre y todos; activar, inactivar o borrar los usuarios de una lista; inactivar a todos
 * los que no están en ella; cambiar la contraseña de los de una lista; y leer y quitar la configuración
 * de módulo guardada de un usuario.
 *
 * La clase hereda de la clase base de acceso a datos: el caso le presta su conexión, dentro de su
 * transacción. Los usuarios que necesita los da de alta el propio caso; el administrador, el usuario 1,
 * no está en la base de pruebas, y el caso que lo necesita lo crea con ese número.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModUsuario;

use TPVFox\Test\CasoIntegracion;

final class ClaseUsuariosIntegracionTest extends CasoIntegracion
{
    protected bool $compartirConexionConElProducto = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_usuario/clases/claseUsuarios.php');
    }

    /**
     * @comportamiento Un usuario se lee por su número, entero o solo su nombre de empleado; un número que
     *   no existe no trae datos.
     */
    public function test_unUsuarioSeLeePorSuNumero(): void
    {
        $id = $this->usuario('ana', 'Ana Pérez');
        $usuarios = new \ClaseUsuarios();

        self::assertSame('ana', $usuarios->getUsuarioPorId($id)['datos'][0]['username']);
        self::assertSame([['nombre' => 'Ana Pérez']], $usuarios->getUsuarioNombrePorId($id)['datos']);
        self::assertArrayNotHasKey('datos', $usuarios->getUsuarioPorId(999999));
    }

    /** @comportamiento La lista de todos los usuarios trae su número, su nombre de acceso, su grupo, su estado y su nombre, sin la contraseña. */
    public function test_todosLosUsuariosSinLaContrasena(): void
    {
        $id = $this->usuario('ana', 'Ana Pérez');

        $todos = (new \ClaseUsuarios())->todosUsuarios()['datos'];
        $ana = array_values(array_filter($todos, fn ($u) => (int) $u['id'] === $id))[0];

        self::assertSame(['id', 'username', 'group_id', 'estado', 'nombre'], array_keys($ana));
        self::assertSame(['ana', '1', 'activo', 'Ana Pérez'], [$ana['username'], (string) $ana['group_id'], $ana['estado'], $ana['nombre']]);
    }

    /** @comportamiento Inactivar y activar alcanzan solo a los usuarios de la lista, y responden sin error. */
    public function test_activarEInactivarAlcanzanSoloALosDeLaLista(): void
    {
        $ana = $this->usuario('ana');
        $luis = $this->usuario('luis');
        $usuarios = new \ClaseUsuarios();

        self::assertSame('0', $usuarios->inactivarUsuarios([$ana])['error']);
        self::assertSame(['inactivo', 'activo'], [$this->estado($ana), $this->estado($luis)]);
        self::assertSame('0', $usuarios->activarUsuarios([$ana])['error']);
        self::assertSame('activo', $this->estado($ana));
    }

    /** @comportamiento Inactivar al resto deja como estaban los usuarios de la lista e inactiva a todos los demás. */
    public function test_inactivarAlRestoInactivaATodosLosDemas(): void
    {
        $ana = $this->usuario('ana');
        $luis = $this->usuario('luis');

        self::assertSame('0', (new \ClaseUsuarios())->inactivarRestoUsuarios([$ana])['error']);

        self::assertSame('activo', $this->estado($ana));
        self::assertSame('inactivo', $this->estado($luis));
        self::assertSame('0', $this->valor("SELECT COUNT(*) FROM usuarios WHERE id <> {$ana} AND estado <> 'inactivo'"));
    }

    /** @comportamiento Cambiar la contraseña alcanza a todos los usuarios de la lista y a ninguno más; la guarda con `password_hash()`. */
    public function test_cambiarLaContrasenaAlcanzaALosDeLaLista(): void
    {
        $ana = $this->usuario('ana');
        $luis = $this->usuario('luis');
        $eva = $this->usuario('eva');

        self::assertSame('0', (new \ClaseUsuarios())->cambiarContraseñaUsuarios([$ana, $luis], 'Nueva-2027')['error']);

        self::assertTrue(password_verify('Nueva-2027', $this->valor("SELECT password FROM usuarios WHERE id = {$ana}")));
        self::assertTrue(password_verify('Nueva-2027', $this->valor("SELECT password FROM usuarios WHERE id = {$luis}")));
        self::assertSame(md5('anterior'), $this->valor("SELECT password FROM usuarios WHERE id = {$eva}"));
    }

    /** @comportamiento Borrar alcanza solo a los usuarios de la lista. */
    public function test_borrarAlcanzaSoloALosDeLaLista(): void
    {
        $ana = $this->usuario('ana');
        $luis = $this->usuario('luis');

        self::assertSame('0', (new \ClaseUsuarios())->eliminarUsuarios([$ana])['error']);

        self::assertSame('0', $this->valor("SELECT COUNT(*) FROM usuarios WHERE id = {$ana}"));
        self::assertSame('1', $this->valor("SELECT COUNT(*) FROM usuarios WHERE id = {$luis}"));
    }

    /** @comportamiento La configuración guardada de un usuario se lee entera, y quitar la de un módulo deja la de los demás. */
    public function test_laConfiguracionDeUnModuloSeLeeYSeQuita(): void
    {
        $ana = $this->usuario('ana');
        foreach (['mod_producto', 'mod_compras'] as $modulo) {
            $this->db->query("INSERT INTO modulos_configuracion (idusuario, nombre_modulo, configuracion, fecha) VALUES ({$ana}, '{$modulo}', '{}', '2026-10-01 10:00:00')");
        }
        $usuarios = new \ClaseUsuarios();

        self::assertCount(2, $usuarios->getConfiguracionModulo($ana)['datos']);
        self::assertSame('0', $usuarios->eliminarConfiguracionUsuario($ana, 'mod_producto')['error']);
        self::assertSame(['mod_compras'], array_column($usuarios->getConfiguracionModulo($ana)['datos'], 'nombre_modulo'));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_usuario/clases/claseUsuarios.php:48-59
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy La contraseña nueva se guarda como md5 sin sal: quien obtenga la tabla de usuarios la
     *   recupera con tablas ya calculadas.
     * @que-deberia-ocurrir Que la contraseña se guarde de forma que no pueda recuperarse.
     * @por-que-ocurre El cambio la guarda con la función md5 de la base, la misma con la que la compara la
     *   entrada al programa.
     * @como-deberia-funcionar Guardarla con `password_hash()` y comprobarla con `password_verify()`, a la vez
     *   en el cambio y en la entrada.
     */
    public function test_laContrasenaNuevaNoSeGuardaComoMd5(): void
    {
        $ana = $this->usuario('ana');

        (new \ClaseUsuarios())->cambiarContraseñaUsuarios([$ana], 'Nueva-2027');
        $guardada = (string) $this->valor("SELECT password FROM usuarios WHERE id = {$ana}");

        self::assertNotSame(md5('Nueva-2027'), $guardada);
        self::assertTrue(password_verify('Nueva-2027', $guardada));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_usuario/clases/claseUsuarios.php:48-59
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Una contraseña nueva con unas comillas dobles termina con un error de la base, y la
     *   contraseña no cambia.
     * @que-deberia-ocurrir Que cualquier contraseña se pueda poner, comillas incluidas.
     * @por-que-ocurre La contraseña se pega entre comillas a la sentencia, sin escaparla.
     * @como-deberia-funcionar Pasar la contraseña como dato, con una sentencia preparada.
     */
    public function test_unaContrasenaConComillasSePuedePoner(): void
    {
        $ana = $this->usuario('ana');

        try {
            (new \ClaseUsuarios())->cambiarContraseñaUsuarios([$ana], 'Mi"Clave');
        } catch (\Throwable $error) {
            self::fail('La contraseña con comillas termina con un error: ' . get_class($error));
        }

        self::assertNotSame(md5('anterior'), $this->valor("SELECT password FROM usuarios WHERE id = {$ana}"));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_usuario/clases/claseUsuarios.php:95-105
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Borrar un usuario deja su índice de tickets, sus permisos y su configuración de
     *   módulo en la base, sin usuario.
     * @que-deberia-ocurrir Que borrar un usuario no deje filas que lo nombran.
     * @por-que-ocurre El borrado solo quita la fila del usuario; las tablas que lo nombran no tienen clave
     *   ajena que las borre con él.
     * @como-deberia-funcionar Borrar en la misma transacción su índice, sus permisos y su configuración, o
     *   inactivar el usuario en lugar de borrarlo.
     */
    public function test_borrarUnUsuarioNoDejaLoQueLoNombra(): void
    {
        $ana = $this->usuario('ana');
        $tienda = (int) $this->valor('SELECT MIN(idTienda) FROM tiendas');
        $this->db->query("INSERT INTO indices (idTienda, idUsuario, numticket, tempticket) VALUES ({$tienda}, {$ana}, 0, 0)");
        $this->db->query("INSERT INTO permisos (idUsuario, modulo, vista, accion, permiso) VALUES ({$ana}, 'mod_usuario', NULL, NULL, 1)");
        $this->db->query("INSERT INTO modulos_configuracion (idusuario, nombre_modulo, configuracion, fecha) VALUES ({$ana}, 'mod_producto', '{}', '2026-10-01 10:00:00')");

        (new \ClaseUsuarios())->eliminarUsuarios([$ana]);

        self::assertSame('0', $this->valor("SELECT COUNT(*) FROM indices WHERE idUsuario = {$ana}"));
        self::assertSame('0', $this->valor("SELECT COUNT(*) FROM permisos WHERE idUsuario = {$ana}"));
        self::assertSame('0', $this->valor("SELECT COUNT(*) FROM modulos_configuracion WHERE idusuario = {$ana}"));
    }

    /** Da de alta un usuario activo, con la contraseña «anterior» guardada como la guarda el producto. */
    private function usuario(string $acceso, string $nombre = 'Empleado de pruebas'): int
    {
        $sentencia = $this->db->prepare("INSERT INTO usuarios (username, password, fecha, group_id, estado, nombre) VALUES (?, ?, '2026-01-01', 1, 'activo', ?)");
        $clave = md5('anterior');
        $sentencia->bind_param('sss', $acceso, $clave, $nombre);
        $sentencia->execute();

        return (int) $this->db->insert_id;
    }

    private function estado(int $id): ?string
    {
        return $this->valor("SELECT estado FROM usuarios WHERE id = {$id}");
    }

    private function valor(string $sql): ?string
    {
        $fila = $this->db->query($sql)->fetch_row();

        return $fila === null ? null : (string) $fila[0];
    }
}
