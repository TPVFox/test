<?php

/**
 * El menú superior de TPVFox (`modulos/mod_menu/menu.php`, con `ClaseMenu`): las entradas que declara
 * `parametrosMenu.xml` y las que se pintan según los permisos del usuario, el usuario y la tienda de
 * la sesión y el enlace de cierre.
 *
 * El menú se pinta con los globales que deja el arranque de una petición y con los permisos sembrados
 * de un usuario creado por el caso, dentro de su transacción.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\Nucleo;

use TPVFox\Test\CasoIntegracion;

final class MenuIntegracionTest extends CasoIntegracion
{
    private const HOST = '/TPVFox';

    private ?string $raizDelServidor = null;

    protected function setUp(): void
    {
        parent::setUp();
        require_once RUTA_TPVFOX . '/clases/ClasePermisos.php';
        // Los permisos y el menú deducen la ruta del producto quitando la raíz del servidor. Otra
        // prueba de la suite carga la librería de PDF, que la deja en «/»: se fija la de línea de
        // órdenes, vacía, y se devuelve al acabar.
        $this->raizDelServidor = $_SERVER['DOCUMENT_ROOT'] ?? null;
        $_SERVER['DOCUMENT_ROOT'] = '';
    }

    protected function tearDown(): void
    {
        unset($_SESSION['estadoTpv']);
        $_SERVER['DOCUMENT_ROOT'] = $this->raizDelServidor;
        parent::tearDown();
    }

    /**
     * @comportamiento A un administrador el menú le enlaza todas las pantallas que declara
     *   `parametrosMenu.xml`, agrupadas bajo sus títulos, y a la derecha pinta su nombre de acceso, el
     *   enlace de cierre y la razón social de la tienda.
     */
    public function test_M1_aUnAdministradorElMenuLeEnlazaTodasLasPantallas(): void
    {
        $html = $this->pintarMenu($this->usuario(9));

        foreach (self::entradas() as [$titulo, $modulo, $vista]) {
            self::assertStringContainsString('href="' . self::HOST . '/modulos/' . $modulo . '/' . $vista . '">' . $titulo . '</a>', $html, $titulo);
        }
        foreach (self::grupos() as $grupo) {
            self::assertStringContainsString('data-toggle="dropdown" href="#">' . $grupo, $html, 'Grupo ' . $grupo);
        }
        self::assertStringContainsString('</span>administrador_de_prueba</a>', $html);
        self::assertStringContainsString('href="' . self::HOST . '/plugins/controlUser/modalUsuario.php?tipo=cerrar">Cerrar</a>', $html);
        self::assertStringContainsString('<div id="tienda">Tienda de pruebas</div>', $html);
    }

    /**
     * @comportamiento Una pantalla sin permiso de vista, o de un módulo sin permiso, no se enlaza; las
     *   demás, sí. Sin la sesión correcta no se pinta el enlace de cierre.
     */
    public function test_M2_sinPermisoDeVistaODeModuloLaPantallaNoSeEnlaza(): void
    {
        $usuario = $this->usuario(9);
        new \ClasePermisos($usuario, $this->db);
        $this->db->query("UPDATE permisos SET permiso = 0 WHERE idUsuario = {$usuario['id']} AND modulo = 'mod_cliente' AND vista = 'ListaClientes.php' AND accion IS NULL");
        $this->db->query("UPDATE permisos SET permiso = 0 WHERE idUsuario = {$usuario['id']} AND modulo = 'mod_compras' AND vista IS NULL");

        $html = $this->pintarMenu($usuario, 'SinActivar');

        self::assertStringNotContainsString('/modulos/mod_cliente/ListaClientes.php', $html, 'Vista sin permiso');
        self::assertStringNotContainsString('/modulos/mod_compras/', $html, 'Módulo sin permiso');
        self::assertStringContainsString('/modulos/mod_proveedor/ListaProveedores.php', $html);
        self::assertStringNotContainsString('tipo=cerrar', $html);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_menu/menu.php:32-38
     *
     * @group esperado
     * @group permisos
     * @group bajo
     *
     * @que-ocurre-hoy Un grupo del menú en el que el usuario no tiene ninguna pantalla se pinta igual,
     *   con su título y una lista vacía al desplegarlo.
     * @que-deberia-ocurrir Que un grupo sin ninguna entrada permitida no se pinte.
     * @por-que-ocurre El título del grupo se escribe antes de comprobar sus entradas, sin mirar si
     *   alguna se pinta.
     * @como-deberia-funcionar Comprobar las entradas del grupo antes de escribir su título.
     */
    public function test_M3_unGrupoSinNingunaPantallaPermitidaNoSePinta(): void
    {
        $usuario = $this->usuario(9);
        new \ClasePermisos($usuario, $this->db);
        $this->db->query("UPDATE permisos SET permiso = 0 WHERE idUsuario = {$usuario['id']} AND modulo = 'mod_compras' AND vista IS NULL");

        $html = $this->pintarMenu($usuario);

        self::assertStringNotContainsString('data-toggle="dropdown" href="#">Compras', $html, 'Compras no tiene ninguna entrada permitida.');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_menu/menu.php:64
     * @codigo-afectado modulos/mod_menu/menu.php:72
     *
     * @group esperado
     * @group pantalla
     * @group medio
     *
     * @que-ocurre-hoy El nombre de acceso del usuario y la razón social de la tienda se escriben en el
     *   menú tal como están guardados: un script guardado en cualquiera de los dos se ejecuta en todas
     *   las pantallas.
     * @que-deberia-ocurrir Que se escriban como texto.
     * @por-que-ocurre Se insertan en el HTML sin escapar.
     * @como-deberia-funcionar Escaparlos con `htmlspecialchars()`.
     */
    public function test_M4_elUsuarioYLaTiendaSeEscribenComoTexto(): void
    {
        $usuario = $this->usuario(9);
        $usuario['login'] = 'pepe<script>window.marca = 1</script>';

        $html = $this->pintarMenu($usuario, 'Correcto', ['razonsocial' => 'Tienda<script>window.marca = 2</script>']);

        self::assertStringNotContainsString('<script>window.marca = 1</script>', $html, 'El nombre de acceso no puede salir como script.');
        self::assertStringNotContainsString('<script>window.marca = 2</script>', $html, 'La razón social no puede salir como script.');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_menu/menu.php:55-58
     *
     * @group esperado
     * @group pantalla
     * @group bajo
     *
     * @que-ocurre-hoy Si el usuario no tiene la última pantalla de un grupo del menú, el grupo queda
     *   sin cerrar en el HTML, y el navegador lo cierra por su cuenta.
     * @que-deberia-ocurrir Que cada grupo que se pinta quede cerrado, tenga o no la última pantalla.
     * @por-que-ocurre El cierre del grupo se escribe solo si la última comprobación de permiso fue
     *   buena, y en un grupo esa es la de su última pantalla.
     * @como-deberia-funcionar Cerrar el grupo siempre que se haya abierto.
     */
    public function test_M5_unGrupoSinSuUltimaPantallaQuedaCerrado(): void
    {
        $usuario = $this->usuario(9);
        new \ClasePermisos($usuario, $this->db);
        $this->db->query("UPDATE permisos SET permiso = 0 WHERE idUsuario = {$usuario['id']} AND modulo = 'mod_informes' AND vista = 'POSStock.php' AND accion IS NULL");

        $html = $this->pintarMenu($usuario);
        $izquierda = substr($html, (int) strpos($html, 'navbar-left'), (int) strpos($html, 'navbar-right') - (int) strpos($html, 'navbar-left'));

        self::assertStringContainsString('/modulos/mod_informes/ListaInformes.php', $izquierda, 'La primera pantalla del grupo se enlaza.');
        self::assertSame(substr_count($izquierda, '<li'), substr_count($izquierda, '</li>'), 'Cada grupo que se abre tiene que cerrarse.');
    }

    /**
     * Pinta el menú con los globales que deja `inicial.php`: la sesión con los permisos del usuario,
     * el usuario, la tienda y la ruta del producto.
     *
     * @param array<string,mixed> $usuario
     * @param array<string,string> $tienda
     */
    private function pintarMenu(array $usuario, string $estado = 'Correcto', array $tienda = ['razonsocial' => 'Tienda de pruebas']): string
    {
        global $URLCom;
        $ClasePermisos = new \ClasePermisos($usuario, $this->db);
        $thisTpv = (object) ['permisos' => $ClasePermisos];
        $HostNombre = self::HOST;
        $Usuario = $usuario;
        $Tienda = $tienda;
        $_SESSION['estadoTpv'] = $estado;

        ob_start();
        include RUTA_TPVFOX . '/modulos/mod_menu/menu.php';

        return (string) ob_get_clean();
    }

    /** @return array{id:int, group_id:int, login:string} */
    private function usuario(int $grupo): array
    {
        $nombre = 'prueba_menu_' . bin2hex(random_bytes(3));
        $this->db->query("INSERT INTO usuarios (username, password, fecha, group_id, estado, nombre) VALUES ('$nombre', MD5('x'), CURDATE(), $grupo, 'activo', 'Prueba')");

        return ['id' => (int) $this->db->insert_id, 'group_id' => $grupo, 'login' => 'administrador_de_prueba'];
    }

    /** @return list<array{0:string,1:string,2:string}> título, módulo y vista de cada entrada con pantalla */
    private static function entradas(): array
    {
        $entradas = [];
        foreach (simplexml_load_file(RUTA_TPVFOX . '/modulos/mod_menu/parametrosMenu.xml')->xpath('//*[@vista]') as $entrada) {
            $entradas[] = [(string) $entrada['descripcion'], (string) $entrada['modulo'], (string) $entrada['vista']];
        }

        return $entradas;
    }

    /** @return list<string> los títulos de los grupos */
    private static function grupos(): array
    {
        return array_map('strval', simplexml_load_file(RUTA_TPVFOX . '/modulos/mod_menu/parametrosMenu.xml')->xpath('/items/item_nivel_1[not(@vista)]/@descripcion'));
    }
}
