<?php

/**
 * Las funciones de `modulos/mod_usuario/funciones.php` que consultan la base: listar los usuarios, leer
 * uno con su índice de tickets, darlo de alta con su índice en la tienda, modificarlo, y pintar a quién
 * alcanzan las acciones sobre varios usuarios, que leen el nombre de cada uno con la clase de usuarios.
 *
 * Otros módulos declaran funciones con el mismo nombre que las de este fichero: cada caso corre en su
 * propio proceso, con la conexión del caso, dentro de su transacción. La clase de usuarios se carga
 * antes, como la carga la petición de fondo. El administrador, el usuario 1, no está en la base de
 * pruebas: el caso que lo necesita lo crea con ese número.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModUsuario;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class FuncionesUsuarioIntegracionTest extends CasoIntegracion
{
    protected bool $compartirConexionConElProducto = true;

    private const SCRIPT = '<script>x=1</script>';

    private int $idTienda;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_usuario/clases/claseUsuarios.php');
        $this->incluirTPVFox('/modulos/mod_usuario/funciones.php');
        $this->idTienda = (new Siembra($this->db))->tiendaPorDefecto();
    }

    /** @comportamiento La lista de usuarios trae cada uno con su número, su nombre de acceso, su nombre, su fecha, su grupo y su estado, y cuántos son. */
    public function test_laListaDeUsuariosLosTraeTodos(): void
    {
        $ana = $this->usuario('ana', 'Ana Pérez');

        $lista = \obtenerUsuarios($this->db);
        $fila = array_values(array_filter($lista['items'], fn ($u) => (int) $u['id'] === $ana))[0];

        self::assertSame((int) $this->valor('SELECT COUNT(*) FROM usuarios'), $lista['NItems']);
        self::assertSame(['ana', 'Ana Pérez', '2026-01-01', 'activo'], [$fila['username'], $fila['nombre'], $fila['fecha'], $fila['block']]);
    }

    /** @comportamiento Un usuario se lee con los números de ticket de su índice; uno sin índice se da por no encontrado. */
    public function test_unUsuarioSeLeeConSuIndice(): void
    {
        $ana = $this->usuario('ana');
        $luis = $this->usuario('luis');
        $this->db->query("INSERT INTO indices (idTienda, idUsuario, numticket, tempticket) VALUES ({$this->idTienda}, {$ana}, 7, 3)");

        $leido = \verSelec($this->db, $ana, 'usuarios');
        $sinIndice = \verSelec($this->db, $luis, 'usuarios');

        self::assertSame(['ana', '7', '3'], [$leido['username'], (string) $leido['numticket'], (string) $leido['tempticket']]);
        self::assertSame(' No se a encontrado usuario', $sinIndice['error']);
    }

    /** @comportamiento Un usuario con índices en dos tiendas se lee igual, con los números de uno de ellos. */
    public function test_unUsuarioConIndicesEnDosTiendasSeLeeConUnoDeEllos(): void
    {
        $ana = $this->usuario('ana');
        $otra = $this->idTienda + 1;
        $this->db->query("INSERT INTO indices (idTienda, idUsuario, numticket, tempticket) VALUES ({$this->idTienda}, {$ana}, 7, 3), ({$otra}, {$ana}, 40, 2)");

        $leido = \verSelec($this->db, $ana, 'usuarios');

        self::assertSame('ana', $leido['username']);
        self::assertContains($leido['numticket'] . '|' . $leido['tempticket'], ['7|3', '40|2']);
    }

    /**
     * @comportamiento El alta guarda el usuario con su contraseña en md5, y crea su índice a cero en la
     *   tienda indicada.
     */
    public function test_elAltaGuardaElUsuarioYSuIndice(): void
    {
        $alta = \insertarUsuario($this->datos('marta'), $this->db, $this->idTienda, 'usuarios');

        self::assertArrayNotHasKey('error', $alta);
        $guardado = $this->db->query("SELECT username, password, fecha, group_id, estado, nombre FROM usuarios WHERE id = {$alta['id']}")->fetch_assoc();
        self::assertSame(['marta', md5('Clave-1'), '2026-10-23', '1', 'activo', 'Marta Ruiz'], array_map('strval', array_values($guardado)));
        self::assertSame("{$this->idTienda}|0|0", $this->valor("SELECT CONCAT(idTienda, '|', numticket, '|', tempticket) FROM indices WHERE idUsuario = {$alta['id']}"));
    }

    /** @comportamiento El alta no guarda un nombre de acceso que ya existe ni uno vacío. */
    public function test_elAltaRechazaUnNombreRepetidoOVacio(): void
    {
        $this->usuario('ana');
        $antes = $this->valor('SELECT COUNT(*) FROM usuarios');

        $repetido = \insertarUsuario($this->datos('ana'), $this->db, $this->idTienda, 'usuarios');
        $vacio = \insertarUsuario($this->datos(''), $this->db, $this->idTienda, 'usuarios');

        self::assertSame('error', $repetido['error']);
        self::assertSame('error', $vacio['error']);
        self::assertSame($antes, $this->valor('SELECT COUNT(*) FROM usuarios'));
    }

    /**
     * @comportamiento Modificar cambia el nombre del empleado, el grupo y el estado; la contraseña solo si
     *   no llega la palabra «password», y el nombre de acceso nunca.
     */
    public function test_modificarCambiaLoDelEmpleadoYLaContrasenaSoloSiSeEscribe(): void
    {
        $ana = $this->usuario('ana');

        \modificarUsuario(['idUsuario' => $ana, 'username' => 'otra', 'nombreEmpleado' => 'Ana Gil', 'fecha' => '2026-01-01', 'password' => 'password', 'grupo' => '9', 'estado' => 'inactivo'], $this->db, 'usuarios');
        $sinClave = $this->db->query("SELECT username, password, group_id, estado, nombre FROM usuarios WHERE id = {$ana}")->fetch_assoc();
        \modificarUsuario(['idUsuario' => $ana, 'username' => 'ana', 'nombreEmpleado' => 'Ana Gil', 'fecha' => '2026-01-01', 'password' => 'Nueva-1', 'grupo' => '9', 'estado' => 'activo'], $this->db, 'usuarios');

        self::assertSame(['ana', md5('anterior'), '9', 'inactivo', 'Ana Gil'], array_map('strval', array_values($sinClave)));
        self::assertSame(md5('Nueva-1'), $this->valor("SELECT password FROM usuarios WHERE id = {$ana}"));
    }

    /**
     * @comportamiento Inactivar, borrar y activar enseñan antes a quién alcanzan, por su nombre: las dos
     *   primeras sin el administrador; el cambio de año, con el administrador delante.
     */
    public function test_lasAccionesEnsenanAQuienAlcanzan(): void
    {
        $this->usuario('admin', 'Administrador', 1);
        $ana = $this->usuario('ana', 'Ana Pérez');

        $inactivar = \htmlInactivarUsuarios(['1', (string) $ana]);
        $borrar = \htmlEliminarUsuarios(['1', (string) $ana]);
        $activar = \htmlActivarUsuarios(['1', (string) $ana]);
        $cambioDeAno = \htmlCambiarAnoUsuarios([(string) $ana]);

        foreach ([$inactivar, $borrar] as $html) {
            self::assertStringContainsString('Usuarios seleccionados: 1</p>', $html);
            self::assertStringNotContainsString('<li>Administrador</li>', $html);
        }
        self::assertStringContainsString('Usuarios seleccionados: 2</p>', $activar);
        self::assertStringContainsString('<ul><li>Administrador</li><li>Ana Pérez</li></ul>', $cambioDeAno);
        self::assertStringContainsString('onclick="confirmarCambiarAnoUsuarios()"', $cambioDeAno);
        self::assertStringContainsString('onclick="confirmarEliminarUsuarios()"', $borrar);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_usuario/funciones.php:47-90
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Un nombre de acceso con unas comillas dobles no se da de alta: la comprobación de que
     *   no existe termina con un error de la base.
     * @que-deberia-ocurrir Que el alta guarde el nombre de acceso tal como se escribió.
     * @por-que-ocurre La comprobación y el alta pegan lo tecleado entre comillas a la sentencia, sin
     *   escaparlo.
     * @como-deberia-funcionar Pasar lo tecleado como dato, con una sentencia preparada.
     */
    public function test_elAltaGuardaElNombreTalComoSeEscribio(): void
    {
        try {
            $alta = \insertarUsuario($this->datos('ana"maria'), $this->db, $this->idTienda, 'usuarios');
        } catch (\Throwable $error) {
            self::fail('El alta termina con un error: ' . get_class($error));
        }

        self::assertSame('ana"maria', $this->valor("SELECT username FROM usuarios WHERE id = {$alta['id']}"));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_usuario/funciones.php:113-120
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy La contraseña «password» no se puede poner: al modificar un usuario, esa palabra se
     *   toma como que no se cambia.
     * @que-deberia-ocurrir Que la contraseña solo se deje como estaba cuando no se escribe ninguna.
     * @por-que-ocurre La ficha rellena la caja de la contraseña con la palabra «password», y el guardado
     *   la usa como señal de no cambiarla.
     * @como-deberia-funcionar Dejar la caja vacía en la ficha y no cambiar la contraseña si llega vacía.
     */
    public function test_cualquierContrasenaEscritaSeGuarda(): void
    {
        $ana = $this->usuario('ana');

        \modificarUsuario(['idUsuario' => $ana, 'username' => 'ana', 'nombreEmpleado' => 'Ana', 'fecha' => '2026-01-01', 'password' => 'password', 'grupo' => '1', 'estado' => 'activo'], $this->db, 'usuarios');

        self::assertSame(md5('password'), $this->valor("SELECT password FROM usuarios WHERE id = {$ana}"));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_usuario/funciones.php:310-316
     * @codigo-afectado modulos/mod_usuario/funciones.php:354-360
     * @codigo-afectado modulos/mod_usuario/funciones.php:390-396
     * @codigo-afectado modulos/mod_usuario/funciones.php:421-427
     *
     * @group defecto
     * @group salida
     * @group alto
     *
     * @que-ocurre-hoy Las confirmaciones de las acciones sobre varios usuarios escriben el nombre del
     *   empleado tal cual: un nombre con una etiqueta de script la ejecuta al abrirlas.
     * @que-deberia-ocurrir Que el nombre se escriba como texto.
     * @por-que-ocurre Las cuatro confirmaciones escriben el nombre leído sin escaparlo.
     * @como-deberia-funcionar Escapar el nombre al escribirlo.
     */
    public function test_lasAccionesEscribenElNombreComoTexto(): void
    {
        $ana = $this->usuario('ana', self::SCRIPT);

        $html = \htmlInactivarUsuarios([(string) $ana]);

        self::assertStringNotContainsString(self::SCRIPT, $html);
        self::assertStringContainsString(htmlspecialchars(self::SCRIPT), $html);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_usuario/funciones.php:27-45
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy Un número de usuario con una condición añadida trae a otro usuario: la ficha de
     *   modificar lo enseña como si fuera el pedido.
     * @que-deberia-ocurrir Que el número se tome como número, y uno que no es de ningún usuario no traiga
     *   ninguno.
     * @por-que-ocurre La lectura pega el número a la consulta sin convertirlo.
     * @como-deberia-funcionar Convertir el número en entero antes de componer la consulta, o pasarlo como
     *   dato.
     */
    public function test_unNumeroConUnaCondicionNoTraeNingunUsuario(): void
    {
        $ana = $this->usuario('ana');
        $this->db->query("INSERT INTO indices (idTienda, idUsuario, numticket, tempticket) VALUES ({$this->idTienda}, {$ana}, 0, 0)");

        $leido = \verSelec($this->db, '0 OR 1=1', 'usuarios');

        self::assertSame(' No se a encontrado usuario', $leido['error'] ?? null);
    }

    /** @return array<string, string> */
    private function datos(string $acceso): array
    {
        return ['username' => $acceso, 'nombreEmpleado' => 'Marta Ruiz', 'fecha' => '2026-10-23', 'password' => 'Clave-1', 'idUsuario' => '', 'grupo' => '1', 'estado' => 'activo'];
    }

    /** Da de alta un usuario activo, con la contraseña «anterior» guardada como la guarda el producto; con número, si se indica. */
    private function usuario(string $acceso, string $nombre = 'Empleado de pruebas', ?int $id = null): int
    {
        $sentencia = $this->db->prepare("INSERT INTO usuarios (id, username, password, fecha, group_id, estado, nombre) VALUES (?, ?, ?, '2026-01-01', 1, 'activo', ?)");
        $clave = md5('anterior');
        $sentencia->bind_param('isss', $id, $acceso, $clave, $nombre);
        $sentencia->execute();

        return (int) $this->db->insert_id;
    }

    private function valor(string $sql): ?string
    {
        $fila = $this->db->query($sql)->fetch_row();

        return $fila === null ? null : (string) $fila[0];
    }
}
