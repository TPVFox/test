<?php

/**
 * Los permisos de TPVFox contra la base (`clases/ClasePermisos.php`): la siembra que se hace en
 * cada petición, al construir los permisos del usuario de la sesión, y las escrituras con que las
 * pantallas de usuarios y la reorganización de permisos los cambian.
 *
 * Cada módulo declara sus permisos en su `acces.xml`, en tres niveles —módulo, vista y acción—, y
 * un complemento puede traer el suyo. La siembra da de alta, para el usuario, las filas que le
 * falten con el valor declarado; las del usuario 0 son los permisos por defecto.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\Nucleo;

use TPVFox\Test\CasoIntegracion;

final class PermisosIntegracionTest extends CasoIntegracion
{
    private ?string $raizDelServidor = null;

    protected function setUp(): void
    {
        parent::setUp();
        require_once RUTA_TPVFOX . '/clases/ClasePermisos.php';
        // Los permisos deducen la ruta del producto quitando la raíz del servidor. Otra prueba de la
        // suite carga la librería de PDF, que la deja en «/»: se fija la de línea de órdenes, vacía,
        // y se devuelve al acabar.
        $this->raizDelServidor = $_SERVER['DOCUMENT_ROOT'] ?? null;
        $_SERVER['DOCUMENT_ROOT'] = '';
    }

    protected function tearDown(): void
    {
        $_SERVER['DOCUMENT_ROOT'] = $this->raizDelServidor;
        parent::tearDown();
    }

    /**
     * @comportamiento Un usuario nuevo recibe una fila por cada módulo, vista y acción que declaran
     *   los `acces.xml` de los módulos, con el permiso declarado, y ninguna repetida.
     */
    public function test_S1_unUsuarioNuevoRecibeLosPermisosDeclaradosEnLosModulos(): void
    {
        $usuario = $this->usuario(1);

        new \ClasePermisos($usuario, $this->db);

        $filas = $this->filas($usuario['id']);
        foreach (self::declaradosEnLosModulos() as $clave => $permiso) {
            self::assertArrayHasKey($clave, $filas, 'Falta el permiso ' . $clave);
            self::assertSame($permiso, $filas[$clave], 'Valor de ' . $clave);
        }
        self::assertSame(0, $this->repetidas($usuario['id']));
    }

    /** @comportamiento A un usuario del grupo 9, el de los administradores, todo lo sembrado le llega a 1. */
    public function test_S2_aUnAdministradorTodoLoSembradoLeLlegaAUno(): void
    {
        $usuario = $this->usuario(9);

        new \ClasePermisos($usuario, $this->db);

        $filas = $this->filas($usuario['id']);
        self::assertNotEmpty($filas);
        self::assertSame([1], array_values(array_unique($filas)));
    }

    /**
     * @comportamiento La siembra solo añade lo que falta: un permiso que el usuario ya tiene no
     *   vuelve al valor declarado, aunque sea distinto.
     */
    public function test_S3_laSiembraNoCambiaUnPermisoQueElUsuarioYaTiene(): void
    {
        $usuario = $this->usuario(1);
        new \ClasePermisos($usuario, $this->db);
        $this->db->query("UPDATE permisos SET permiso = 0 WHERE idUsuario = {$usuario['id']} AND modulo = 'mod_producto' AND vista = 'producto.php' AND accion IS NULL");

        new \ClasePermisos($usuario, $this->db);

        self::assertSame(0, $this->filas($usuario['id'])['mod_producto|producto.php|']);
    }

    /**
     * @comportamiento Que el usuario sea administrador no cambia lo que ya tiene: un permiso suyo
     *   puesto a 0 sigue a 0 en la siembra siguiente; el grupo 9 solo decide el valor de lo que se
     *   da de alta.
     */
    public function test_S8_aUnAdministradorLaSiembraNoLeCambiaUnPermisoQueYaTiene(): void
    {
        $usuario = $this->usuario(9);
        new \ClasePermisos($usuario, $this->db);
        $this->db->query("UPDATE permisos SET permiso = 0 WHERE idUsuario = {$usuario['id']} AND modulo = 'mod_producto' AND vista = 'producto.php' AND accion IS NULL");

        new \ClasePermisos($usuario, $this->db);

        self::assertSame(0, $this->filas($usuario['id'])['mod_producto|producto.php|']);
    }

    /**
     * @estado rojo
     * @codigo-afectado clases/ClasePermisos.php:117-125
     *
     * @group esperado
     * @group permisos
     * @group medio
     *
     * @que-ocurre-hoy Las acciones que declara un complemento para una vista que su módulo ya tiene
     *   no se dan de alta nunca, ni siquiera a un administrador: las del complemento de la tienda
     *   web en la ficha de producto no llegan a la tabla de permisos.
     * @que-deberia-ocurrir Que cada acción declarada tenga su fila para cada usuario.
     * @por-que-ocurre La consulta que mira si la acción ya existe se compone pero no se ejecuta, y
     *   la decisión se toma con el resultado de la consulta de la vista: si la vista existe, no se
     *   da de alta ninguna de sus acciones.
     * @como-deberia-funcionar Ejecutar la consulta de cada acción y decidir con su resultado.
     */
    public function test_S4_lasAccionesDeUnComplementoSobreUnaVistaDelModuloSeSiembran(): void
    {
        $usuario = $this->usuario(9);

        new \ClasePermisos($usuario, $this->db);

        $filas = $this->filas($usuario['id']);
        self::assertArrayHasKey('mod_producto|producto.php|CrearProductoWeb', $filas, 'Falta la acción del complemento.');
        self::assertArrayHasKey('mod_producto|producto.php|VerProductoWeb', $filas, 'Falta la acción del complemento.');
    }

    /**
     * @estado rojo
     * @codigo-afectado clases/ClasePermisos.php:117-125
     *
     * @group esperado
     * @group permisos
     * @group medio
     *
     * @que-ocurre-hoy Una acción que se añade al `acces.xml` de una vista que el usuario ya tiene no
     *   le llega nunca: la siembra no la da de alta, y la acción queda sin permiso para él.
     * @que-deberia-ocurrir Que en su siguiente petición la acción nueva le llegue con su valor
     *   declarado.
     * @por-que-ocurre La misma que la del caso S4: la existencia de la acción se decide con la
     *   consulta de su vista.
     * @como-deberia-funcionar Ejecutar la consulta de cada acción y decidir con su resultado.
     */
    public function test_S5_unaAccionQueLeFaltaAUnUsuarioConLaVistaSeVuelveASembrar(): void
    {
        $usuario = $this->usuario(1);
        new \ClasePermisos($usuario, $this->db);
        $this->db->query("DELETE FROM permisos WHERE idUsuario = {$usuario['id']} AND modulo = 'mod_venta' AND vista = 'albaranesListado.php' AND accion = 'Crear'");

        new \ClasePermisos($usuario, $this->db);

        self::assertSame(1, $this->filas($usuario['id'])['mod_venta|albaranesListado.php|Crear'] ?? null, 'La acción tiene que volver con su valor declarado.');
    }

    /**
     * @comportamiento Sin sesión, la siembra se hace para el usuario invitado, el 0: sus filas son
     *   los permisos por defecto, con el valor declarado, que la reorganización de permisos toma
     *   como referencia.
     */
    public function test_S6_sinSesionSeSembranLosPermisosPorDefectoDelUsuarioCero(): void
    {
        $this->db->query('DELETE FROM permisos WHERE idUsuario = 0');

        new \ClasePermisos(['id' => 0, 'group_id' => 0, 'login' => 'invitado'], $this->db);

        $filas = $this->filas(0);
        foreach (self::declaradosEnLosModulos() as $clave => $permiso) {
            self::assertSame($permiso, $filas[$clave] ?? null, 'Valor por defecto de ' . $clave);
        }
    }

    /**
     * @comportamiento Con todo ya sembrado, construir los permisos de un usuario —lo que hace cada
     *   petición— cuesta una consulta por cada `acces.xml`, otra por cada vista declarada y la que
     *   los lee: el coste crece con los módulos instalados.
     */
    public function test_S7_cadaPeticionConsultaUnaVezPorCadaModuloYCadaVista(): void
    {
        $usuario = $this->usuario(1);
        new \ClasePermisos($usuario, $this->db);

        $antes = $this->consultasHechas();
        new \ClasePermisos($usuario, $this->db);
        $hechas = $this->consultasHechas() - $antes - 1;

        self::assertSame(self::consultasDeLaSiembra(), $hechas);
    }

    /**
     * @comportamiento Cambiar un permiso de un usuario escribe el valor en su fila del módulo, la
     *   vista o la acción, y devuelve la sentencia que ejecutó, no su resultado.
     */
    public function test_E1_cambiarUnPermisoEscribeSuValorYDevuelveLaSentencia(): void
    {
        $usuario = $this->usuario(1);
        $permisos = new \ClasePermisos($usuario, $this->db);

        $devuelto = $permisos->modificarPermisoUsuario(
            ['modulo' => 'mod_venta', 'vista' => 'albaranesListado.php', 'accion' => 'Crear'],
            0,
            $usuario['id']
        );

        self::assertSame(0, $this->filas($usuario['id'])['mod_venta|albaranesListado.php|Crear']);
        self::assertStringStartsWith('UPDATE `permisos` SET permiso=0', $devuelto);

        $permisos->modificarPermisoUsuario(['modulo' => 'mod_venta', 'vista' => '', 'accion' => ''], 0, $usuario['id']);

        self::assertSame(0, $this->filas($usuario['id'])['mod_venta||'], 'Sin vista ni acción, cambia el permiso del módulo.');
    }

    /** @comportamiento Se borra una fila de un usuario por su identificador, o todas las suyas; devuelve cuántas. */
    public function test_E2_borrarPermisosQuitaUnaFilaOTodasLasDelUsuario(): void
    {
        $usuario = $this->usuario(1);
        new \ClasePermisos($usuario, $this->db);
        $permisos = new \ClasePermisos($usuario, $this->db);
        $total = count($this->filas($usuario['id']));
        $una = (int) $this->db->query("SELECT id FROM permisos WHERE idUsuario = {$usuario['id']} ORDER BY id LIMIT 1")->fetch_row()[0];

        self::assertSame(1, $permisos->borrarPermisosUsuario($usuario['id'], $una));
        self::assertSame($total - 1, $permisos->borrarPermisosUsuario($usuario['id']));
        self::assertSame([], $this->filas($usuario['id']));
    }

    /** @comportamiento Crear un permiso da de alta la fila con los campos que traen valor; los vacíos quedan nulos. */
    public function test_E3_crearUnPermisoDaDeAltaSoloLosCamposConValor(): void
    {
        $usuario = $this->usuario(1);
        $permisos = new \ClasePermisos($usuario, $this->db);
        $permisos->borrarPermisosUsuario($usuario['id']);

        $creados = $permisos->crearUnPermisoUsuario(
            ['idUsuario' => $usuario['id'], 'modulo' => 'mod_venta', 'vista' => 'albaran.php', 'accion' => '', 'permiso' => 1]
        );

        self::assertSame(1, $creados);
        self::assertSame(['mod_venta|albaran.php|' => 1], $this->filas($usuario['id']));
    }

    /** @return array{id:int, group_id:int} un usuario nuevo del grupo pedido, sin permisos */
    private function usuario(int $grupo): array
    {
        $nombre = 'prueba_permisos_' . bin2hex(random_bytes(3));
        $this->db->query(
            "INSERT INTO usuarios (username, password, fecha, group_id, estado, nombre) VALUES ('$nombre', MD5('x'), CURDATE(), $grupo, 'activo', 'Prueba')"
        );

        return ['id' => (int) $this->db->insert_id, 'group_id' => $grupo];
    }

    /** @return array<string,int> los permisos del usuario, por «módulo|vista|acción» */
    private function filas(int $idUsuario): array
    {
        $filas = [];
        $resultado = $this->db->query(
            "SELECT modulo, COALESCE(vista, ''), COALESCE(accion, ''), permiso FROM permisos WHERE idUsuario = $idUsuario"
        );
        while ([$modulo, $vista, $accion, $permiso] = $resultado->fetch_row()) {
            $filas[$modulo . '|' . $vista . '|' . $accion] = (int) $permiso;
        }

        return $filas;
    }

    private function repetidas(int $idUsuario): int
    {
        return (int) $this->db->query(
            "SELECT COUNT(*) FROM (SELECT 1 FROM permisos WHERE idUsuario = $idUsuario GROUP BY modulo, vista, accion HAVING COUNT(*) > 1) r"
        )->fetch_row()[0];
    }

    private function consultasHechas(): int
    {
        return (int) $this->db->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_row()[1];
    }

    /** @return list<\SimpleXMLElement> los `acces.xml` de los módulos, en el orden en que la siembra los recorre */
    private static function accesDeLosModulos(): array
    {
        $ficheros = [];
        foreach (scandir(RUTA_TPVFOX . '/modulos') as $modulo) {
            $fichero = RUTA_TPVFOX . '/modulos/' . $modulo . '/acces.xml';
            if ($modulo[0] !== '.' && is_file($fichero)) {
                $ficheros[] = simplexml_load_file($fichero);
            }
        }

        return $ficheros;
    }

    /** @return list<\SimpleXMLElement> los `acces.xml` de los complementos */
    private static function accesDeLosComplementos(): array
    {
        return array_map('simplexml_load_file', glob(RUTA_TPVFOX . '/plugins/*/*/acces.xml') ?: []);
    }

    /** @return array<string,int> lo que declaran los módulos, por «módulo|vista|acción» */
    private static function declaradosEnLosModulos(): array
    {
        $declarados = [];
        foreach (self::accesDeLosModulos() as $xml) {
            $modulo = (string) $xml['nombre'];
            $declarados[$modulo . '||'] = (int) $xml['permiso'];
            foreach ($xml->vista as $vista) {
                $declarados[$modulo . '|' . $vista['nombre'] . '|'] = (int) $vista['permiso'];
                foreach ($vista->accion as $accion) {
                    $declarados[$modulo . '|' . $vista['nombre'] . '|' . $accion['nombre']] = (int) $accion['permiso'];
                }
            }
        }

        return $declarados;
    }

    /** Una consulta por `acces.xml` —de módulo o de complemento—, otra por vista, y la que lee los permisos. */
    private static function consultasDeLaSiembra(): int
    {
        $consultas = 1;
        foreach (array_merge(self::accesDeLosModulos(), self::accesDeLosComplementos()) as $xml) {
            $consultas += 1 + count($xml->vista);
        }

        return $consultas;
    }
}
