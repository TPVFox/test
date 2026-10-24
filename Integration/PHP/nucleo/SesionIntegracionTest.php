<?php

/**
 * La sesión de TPVFox (`clases/ClaseSession.php`): quién entra, en qué tienda y qué queda en
 * `$_SESSION` en cada petición.
 *
 * La sesión abre su propia conexión con los datos de `configuracion.php`. Para que vea lo que
 * siembra el caso sin que nada quede escrito, cada caso la construye con una subclase que solo
 * cambia esa conexión por la del caso; el resto del recorrido es el del producto, también la
 * siembra de permisos, que queda dentro de la transacción del caso.
 *
 * Cada caso corre en un proceso aparte: `ClaseSession.php` incluye `ClaseConexion.php` con
 * `include`, no con `include_once`, y si otra prueba del mismo proceso ya la hubiera cargado, la
 * declararía dos veces.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\Nucleo;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class SesionIntegracionTest extends CasoIntegracion
{
    /** La conexión del caso, que la sesión de prueba usa en lugar de abrir la suya. */
    public static ?\mysqli $conexionDelCaso = null;

    private Siembra $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        self::$conexionDelCaso = $this->db;
        $this->siembra = new Siembra($this->db);
        $this->siembra->tiendaPorDefecto();
        // Con $_SESSION ya definida la sesión no llama a session_start(), que en línea de
        // órdenes falla en cuanto se ha escrito algo: el contenido es el de una sesión nueva.
        $_SESSION = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        self::$conexionDelCaso = null;
        $_SESSION = [];
        $_POST = [];
        parent::tearDown();
    }

    /**
     * @comportamiento Un usuario activo que teclea su contraseña entra: la sesión queda correcta,
     *   con su identificador, su nombre, su grupo y el nombre con que entró, sin la contraseña, y
     *   con la tienda principal activa.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_T1_unUsuarioActivoConSuContrasenaEntraEnLaTiendaPrincipal(): void
    {
        $nombre = $this->nombreNuevo();
        $id = $this->usuario($nombre, 'clave-correcta', ['grupo' => 1, 'nombre' => 'Persona de pruebas']);

        $sesion = $this->peticion(['usr' => $nombre, 'pwd' => 'clave-correcta']);

        self::assertSame('Correcto', $_SESSION['estadoTpv']);
        self::assertSame(
            ['nombre' => 'Persona de pruebas', 'id' => (string) $id, 'group_id' => '1', 'login' => $nombre],
            $_SESSION['usuarioTpv']
        );
        self::assertSame($this->tiendaPrincipalActiva(), (int) $_SESSION['tiendaTpv']['idTienda']);
        self::assertSame($_SESSION['tiendaTpv'], $sesion->GetTienda(), 'La tienda de la sesión es la principal.');
        self::assertSame('Correcto', $sesion->GetSession()['estadoTpv'], 'Guarda la sesión tal como quedó.');
    }

    /**
     * @comportamiento Con una contraseña que no es la suya, la sesión no entra: queda en error, sin
     *   usuario, y avisa de que el usuario o la contraseña son incorrectos.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_T2_conOtraContrasenaNoEntraYAvisa(): void
    {
        $nombre = $this->nombreNuevo();
        $this->usuario($nombre, 'clave-correcta');

        $sesion = $this->peticion(['usr' => $nombre, 'pwd' => 'otra-clave']);

        self::assertSame('Error', $_SESSION['estadoTpv']);
        self::assertArrayNotHasKey('usuarioTpv', $_SESSION);
        self::assertSame(['Usuario o contraseña incorrecta'], self::mensajes($sesion));
    }

    /**
     * @comportamiento Un usuario de baja no entra aunque teclee su contraseña: la consulta solo
     *   busca usuarios activos, y el aviso es el mismo que con una contraseña equivocada. El aviso
     *   de PHP que deja por el camino lo mira el caso T11.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_T3_unUsuarioDeBajaNoEntra(): void
    {
        $nombre = $this->nombreNuevo();
        $this->usuario($nombre, 'clave-correcta', ['estado' => 'inactivo']);

        [$sesion] = $this->peticionRecogiendoAvisos(['usr' => $nombre, 'pwd' => 'clave-correcta']);

        self::assertSame('Error', $_SESSION['estadoTpv']);
        self::assertArrayNotHasKey('usuarioTpv', $_SESSION);
        self::assertSame(['Usuario o contraseña incorrecta'], self::mensajes($sesion));
    }

    /**
     * @comportamiento Un usuario sin su fila de índices no entra aunque la contraseña sea la suya:
     *   la sesión queda en error y avisa de que no se pudo obtener su índice.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_T4_unUsuarioSinFilaDeIndicesNoEntra(): void
    {
        $nombre = $this->nombreNuevo();
        $this->usuario($nombre, 'clave-correcta', ['conIndice' => false]);

        $sesion = $this->peticion(['usr' => $nombre, 'pwd' => 'clave-correcta']);

        self::assertSame('Error', $_SESSION['estadoTpv']);
        self::assertArrayNotHasKey('usuarioTpv', $_SESSION);
        self::assertSame(['Error al obtener indice de usuario:0'], self::mensajes($sesion));
    }

    /**
     * @comportamiento Sin formulario, la petición sigue sin sesión: el estado queda sin activar, el
     *   usuario es el invitado y la tienda principal activa ya queda en la sesión.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_T5_sinFormularioLaPeticionSigueComoInvitado(): void
    {
        $sesion = $this->peticion();

        self::assertSame('SinActivar', $_SESSION['estadoTpv']);
        self::assertSame(['id' => 0, 'group_id' => 0, 'login' => 'invitado'], $sesion->Usuario);
        self::assertArrayNotHasKey('usuarioTpv', $_SESSION);
        self::assertSame($this->tiendaPrincipalActiva(), (int) $_SESSION['tiendaTpv']['idTienda']);
    }

    /**
     * @estado rojo
     * @codigo-afectado clases/ClaseSession.php:96-97
     *
     * @group esperado
     * @group sesion
     * @group critico
     * @group directo
     *
     * @que-ocurre-hoy Tecleando en el nombre de usuario un texto con comillas que completa la
     *   consulta, se entra sin conocer ninguna contraseña, con el identificador y el grupo que se
     *   elijan: el del administrador, por ejemplo.
     * @que-deberia-ocurrir Que el nombre tecleado se busque como nombre, sin cambiar la consulta,
     *   y que no se entre.
     * @por-que-ocurre La consulta del usuario se compone pegando el nombre del formulario entre
     *   comillas; unas comillas en el nombre cierran las de la consulta y lo que sigue se ejecuta
     *   como parte de ella.
     * @como-deberia-funcionar Pasar el nombre como parámetro de una sentencia preparada.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_T6_unNombreConComillasNoAbreLaSesionSinContrasena(): void
    {
        $administrador = $this->usuario($this->nombreNuevo(), 'clave-que-nadie-conoce', ['grupo' => 9]);
        $nombre = 'nadie" UNION SELECT MD5("inventada"), "Intruso", ' . $administrador . ', 9 #';

        $this->peticion(['usr' => $nombre, 'pwd' => 'inventada']);

        self::assertNotSame('Correcto', $_SESSION['estadoTpv'], 'Sin contraseña no se entra.');
        self::assertArrayNotHasKey('usuarioTpv', $_SESSION);
    }

    /**
     * @estado rojo
     * @codigo-afectado clases/ClaseSession.php:96
     * @codigo-afectado clases/ClaseSession.php:112
     *
     * @group esperado
     * @group sesion
     * @group alto
     *
     * @que-ocurre-hoy Un usuario cuya contraseña está guardada con el cifrado de contraseñas de
     *   PHP no entra con su clave: la sesión solo reconoce contraseñas guardadas como md5 sin sal,
     *   que se pueden recuperar con tablas ya calculadas.
     * @que-deberia-ocurrir Que una contraseña guardada con un cifrado de un solo sentido y con sal
     *   valga para entrar.
     * @por-que-ocurre La sesión calcula el md5 de lo tecleado y lo compara, como texto, con lo
     *   guardado.
     * @como-deberia-funcionar Comprobar la clave con `password_verify()` sobre lo guardado con
     *   `password_hash()`, y migrar las contraseñas que hoy están en md5.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_T7_unaContrasenaGuardadaConSalValeParaEntrar(): void
    {
        $nombre = $this->nombreNuevo();
        $this->usuario($nombre, 'clave-correcta', ['password' => password_hash('clave-correcta', PASSWORD_DEFAULT)]);

        $this->peticion(['usr' => $nombre, 'pwd' => 'clave-correcta']);

        self::assertSame('Correcto', $_SESSION['estadoTpv'], 'La clave correcta tiene que valer para entrar.');
    }

    /**
     * @estado rojo
     * @codigo-afectado clases/ClaseSession.php:74-78
     *
     * @group esperado
     * @group sesion
     * @group alto
     *
     * @que-ocurre-hoy Con dos tiendas principales activas la sesión avisa de que hay más de una,
     *   pero el usuario entra igual, en la primera que devuelve la base.
     * @que-deberia-ocurrir Que no se entre mientras haya más de una: no se sabe en qué tienda ni en
     *   qué ejercicio se trabaja.
     * @por-que-ocurre La comprobación de la tienda deja la sesión en error, y la del usuario, que
     *   viene después, la pone en correcta sin mirar el error anterior.
     * @como-deberia-funcionar No comprobar el usuario, o no darlo por bueno, si la tienda ya dejó la
     *   sesión en error.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_T8_conDosTiendasPrincipalesActivasNoSeEntra(): void
    {
        $this->siembra->tienda($this->ano(), 'principal');
        $nombre = $this->nombreNuevo();
        $this->usuario($nombre, 'clave-correcta');

        $sesion = $this->peticion(['usr' => $nombre, 'pwd' => 'clave-correcta']);

        self::assertContains(
            'Hay mas de una tienda principal como activo ponte en contacto con administrador',
            self::mensajes($sesion),
            'El aviso de las dos tiendas sí se da.'
        );
        self::assertNotSame('Correcto', $_SESSION['estadoTpv'], 'Con dos tiendas principales no se entra.');
    }

    /**
     * @estado rojo
     * @codigo-afectado clases/ClaseSession.php:82-86
     *
     * @group esperado
     * @group sesion
     * @group alto
     *
     * @que-ocurre-hoy Sin ninguna tienda principal activa el usuario entra, y la sesión queda sin
     *   tienda: sin razón social, sin NIF y sin ejercicio.
     * @que-deberia-ocurrir Que no se entre sin tienda principal activa.
     * @por-que-ocurre La comprobación final de la sesión, que exige tienda, se calcula pero solo se
     *   aplica si un contador de intentos es mayor que cero, y ese contador no lo escribe nadie.
     * @como-deberia-funcionar Aplicar siempre la comprobación final, o rechazar el acceso cuando
     *   falta la tienda.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_T9_sinTiendaPrincipalActivaNoSeEntra(): void
    {
        $nombre = $this->nombreNuevo();
        $this->usuario($nombre, 'clave-correcta');
        $this->db->query("UPDATE tiendas SET estado = 'Inactivo' WHERE tipoTienda = 'principal' AND estado = 'Activo'");

        $this->peticion(['usr' => $nombre, 'pwd' => 'clave-correcta']);

        self::assertArrayNotHasKey('tiendaTpv', $_SESSION);
        self::assertNotSame('Correcto', $_SESSION['estadoTpv'], 'Sin tienda principal no se entra.');
    }

    /**
     * @estado rojo
     * @codigo-afectado clases/ClaseSession.php:65-66
     *
     * @group esperado
     * @group sesion
     * @group medio
     *
     * @que-ocurre-hoy Un usuario al que se da de baja mientras tiene la sesión abierta sigue
     *   trabajando: la sesión ya correcta no vuelve a mirar al usuario en ninguna petición.
     * @que-deberia-ocurrir Que en su siguiente petición deje de tener acceso.
     * @por-que-ocurre Con la sesión en correcto no se consulta ni el usuario ni la tienda; la única
     *   forma de volver a hacerlo depende de un contador de intentos que nadie escribe.
     * @como-deberia-funcionar Comprobar en cada petición que el usuario de la sesión sigue activo.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_T10_unUsuarioDadoDeBajaPierdeElAccesoEnSuSiguientePeticion(): void
    {
        $nombre = $this->nombreNuevo();
        $id = $this->usuario($nombre, 'clave-correcta');
        $this->peticion(['usr' => $nombre, 'pwd' => 'clave-correcta']);
        self::assertSame('Correcto', $_SESSION['estadoTpv'], 'Primero entra.');

        $this->db->query("UPDATE usuarios SET estado = 'inactivo' WHERE id = $id");
        $this->peticion();

        self::assertNotSame('Correcto', $_SESSION['estadoTpv'], 'Dado de baja, ya no tiene acceso.');
    }

    /**
     * @estado rojo
     * @codigo-afectado clases/ClaseSession.php:110-112
     *
     * @group esperado
     * @group sesion
     * @group bajo
     *
     * @que-ocurre-hoy Un nombre de usuario que no existe, o que está de baja, deja un aviso de PHP
     *   por una clave que falta, que acaba en el registro del servidor o en la pantalla, según cómo
     *   esté configurado.
     * @que-deberia-ocurrir Que se rechace el acceso sin avisos de PHP.
     * @por-que-ocurre Sin fila, la lectura del usuario devuelve null; se le añade el nombre y se
     *   compara su contraseña, que no existe.
     * @como-deberia-funcionar Comprobar que la consulta devolvió un usuario antes de comparar su
     *   contraseña.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_T11_unNombreQueNoExisteSeRechazaSinAvisosDePhp(): void
    {
        [, $avisos] = $this->peticionRecogiendoAvisos(['usr' => $this->nombreNuevo(), 'pwd' => 'cualquiera']);

        self::assertSame('Error', $_SESSION['estadoTpv']);
        self::assertSame([], $avisos, 'Rechazar un nombre que no existe no tiene que dejar avisos de PHP.');
    }

    /**
     * @estado rojo
     * @codigo-afectado clases/ClaseSession.php:97-98
     *
     * @group esperado
     * @group sesion
     * @group medio
     *
     * @que-ocurre-hoy Un nombre de usuario con una comilla doble suelta hace fallar la consulta, y
     *   el fallo no se recoge: la petición termina con un error del servidor en lugar de avisar de
     *   que el usuario o la contraseña son incorrectos.
     * @que-deberia-ocurrir Que se rechace el acceso con el aviso de siempre.
     * @por-que-ocurre La comilla rompe la consulta compuesta con el nombre. PHP lanza el error de la
     *   base como excepción, y la sesión lo espera como valor de `mysqli_error()`: la rama que lo
     *   trataría no se alcanza.
     * @como-deberia-funcionar Pasar el nombre como parámetro de una sentencia preparada, y recoger
     *   el error de la base donde se produce.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_T12_unNombreConUnaComillaSueltaSeRechazaSinErrorDeServidor(): void
    {
        $fallo = null;
        try {
            [$sesion] = $this->peticionRecogiendoAvisos(['usr' => 'nombre"suelto', 'pwd' => 'cualquiera']);
        } catch (\mysqli_sql_exception $excepcion) {
            $fallo = $excepcion->getMessage();
        }

        self::assertNull($fallo, 'Un nombre con comillas no puede tumbar la petición.');
        self::assertSame(['Usuario o contraseña incorrecta'], self::mensajes($sesion));
    }

    /**
     * @comportamiento Con su contraseña pero con dos filas de índices, el usuario no entra: la
     *   sesión exige exactamente una y avisa con el número que encuentra.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_T13_unUsuarioConDosFilasDeIndicesNoEntra(): void
    {
        $nombre = $this->nombreNuevo();
        $id = $this->usuario($nombre, 'clave-correcta');
        $tienda = $this->tiendaPrincipalActiva();
        $this->db->query("INSERT INTO indices (idTienda, idUsuario, numticket, tempticket) VALUES ($tienda, $id, 0, 0)");

        $sesion = $this->peticion(['usr' => $nombre, 'pwd' => 'clave-correcta']);

        self::assertSame('Error', $_SESSION['estadoTpv']);
        self::assertArrayNotHasKey('usuarioTpv', $_SESSION);
        self::assertSame(['Error al obtener indice de usuario:2'], self::mensajes($sesion));
    }

    /**
     * @comportamiento Si el contador de intentos de la sesión vale más que cero, la petición vuelve a
     *   comprobar la tienda y aplica el control final: una sesión que ha perdido su tienda, sin
     *   ninguna principal activa, pasa a errónea. Nada del producto escribe ese contador.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_T14_conElContadorDeIntentosEscritoElControlFinalSeAplica(): void
    {
        $nombre = $this->nombreNuevo();
        $this->usuario($nombre, 'clave-correcta');
        $this->peticion(['usr' => $nombre, 'pwd' => 'clave-correcta']);
        self::assertSame('Correcto', $_SESSION['estadoTpv'], 'Primero entra.');

        $this->db->query("UPDATE tiendas SET estado = 'Inactivo' WHERE tipoTienda = 'principal'");
        unset($_SESSION['tiendaTpv']);
        $_SESSION['N_intentos_acceso'] = 1;
        $this->peticion();

        self::assertSame('Erroneo', $_SESSION['estadoTpv']);
    }

    /**
     * @comportamiento Un usuario con la contraseña guardada en md5 entra con ella, y desde ese
     *   momento queda guardada con `password_hash()`: la siguiente vez entra igual.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_T15_unaContrasenaEnMd5SeGuardaConPasswordHashAlEntrar(): void
    {
        $nombre = $this->nombreNuevo();
        $id = $this->usuario($nombre, 'clave-correcta');

        $this->peticion(['usr' => $nombre, 'pwd' => 'clave-correcta']);
        $guardada = $this->db->query("SELECT password FROM usuarios WHERE id = $id")->fetch_assoc()['password'];
        $_SESSION = [];
        $this->peticion(['usr' => $nombre, 'pwd' => 'clave-correcta']);

        self::assertNotSame(md5('clave-correcta'), $guardada);
        self::assertTrue(password_verify('clave-correcta', $guardada));
        self::assertSame('Correcto', $_SESSION['estadoTpv'], 'Vuelve a entrar con la contraseña ya convertida.');
    }

    /**
     * Construye la sesión de una petición, con el formulario de acceso que se le pase, sobre la
     * conexión del caso.
     *
     * @param array<string,string> $formulario
     */
    private function peticion(array $formulario = []): \ClaseSession
    {
        $_POST = $formulario;
        $this->incluirTPVFox('/clases/ClaseSession.php');

        return new class extends \ClaseSession {
            public function conectar()
            {
                return SesionIntegracionTest::$conexionDelCaso;
            }
        };
    }

    /**
     * Como `peticion()`, pero recogiendo los avisos de PHP que deje en lugar de dejar que la
     * suite los convierta en error, para que el caso mire lo que hace la sesión.
     *
     * @param array<string,string> $formulario
     * @return array{0:\ClaseSession, 1:list<string>}
     */
    private function peticionRecogiendoAvisos(array $formulario): array
    {
        $avisos = [];
        set_error_handler(static function (int $nivel, string $mensaje) use (&$avisos): bool {
            $avisos[] = $mensaje;

            return true;
        });
        try {
            $sesion = $this->peticion($formulario);
        } finally {
            restore_error_handler();
        }

        return [$sesion, $avisos];
    }

    /**
     * Da de alta un usuario y, salvo que se pida lo contrario, su fila de índices en la tienda
     * principal.
     *
     * @param array{grupo?:int, estado?:string, nombre?:string, password?:string, conIndice?:bool} $opciones
     */
    private function usuario(string $nombre, string $clave, array $opciones = []): int
    {
        $password = $opciones['password'] ?? md5($clave);
        $grupo = $opciones['grupo'] ?? 1;
        $estado = $opciones['estado'] ?? 'activo';
        $nombreVisible = $opciones['nombre'] ?? 'Persona de pruebas';

        $sentencia = $this->db->prepare(
            'INSERT INTO usuarios (username, password, fecha, group_id, estado, nombre) VALUES (?, ?, CURDATE(), ?, ?, ?)'
        );
        $sentencia->bind_param('ssiss', $nombre, $password, $grupo, $estado, $nombreVisible);
        $sentencia->execute();
        $id = (int) $this->db->insert_id;

        if ($opciones['conIndice'] ?? true) {
            $tienda = $this->tiendaPrincipalActiva();
            $this->db->query("INSERT INTO indices (idTienda, idUsuario, numticket, tempticket) VALUES ($tienda, $id, 0, 0)");
        }

        return $id;
    }

    private function tiendaPrincipalActiva(): int
    {
        return (int) $this->db->query(
            "SELECT idTienda FROM tiendas WHERE tipoTienda = 'principal' AND estado = 'Activo' ORDER BY idTienda LIMIT 1"
        )->fetch_assoc()['idTienda'];
    }

    private function nombreNuevo(): string
    {
        return 'prueba_sesion_' . bin2hex(random_bytes(3));
    }

    /** @return list<string> los avisos que la sesión dejó en sus comprobaciones */
    private static function mensajes(\ClaseSession $sesion): array
    {
        return array_column($sesion->GetComprobaciones(), 'mensaje');
    }
}
