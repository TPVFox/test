<?php

/**
 * La portada de TPVFox (`index.php`): sus accesos directos, enlazados o atenuados según el permiso
 * del usuario, junto al menú que pinta la misma página.
 *
 * La portada se ejecuta en un PHP aparte con la sesión ya correcta: la sesión, el usuario y la tienda
 * se le dan hechos, y los permisos los lee de la base con su propia conexión. Por eso el caso no aísla
 * por transacción: crea su usuario con sus permisos y los borra al acabar.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\Nucleo;

use TPVFox\Test\CasoIntegracion;

final class PortadaIntegracionTest extends CasoIntegracion
{
    /** La portada lee los permisos por su propia conexión: este caso no aísla por transacción. */
    protected bool $aislarPorTransaccion = false;

    private const CLIENTES = 'modulos/mod_cliente/ListaClientes.php';

    private int $usuario = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $nombre = 'prueba_portada_' . bin2hex(random_bytes(3));
        $this->db->query("INSERT INTO usuarios (username, password, fecha, group_id, estado, nombre) VALUES ('$nombre', MD5('x'), CURDATE(), 9, 'activo', 'Prueba')");
        $this->usuario = (int) $this->db->insert_id;
    }

    protected function tearDown(): void
    {
        $this->db->query("DELETE FROM permisos WHERE idUsuario = {$this->usuario}");
        $this->db->query("DELETE FROM usuarios WHERE id = {$this->usuario}");
        parent::tearDown();
    }

    /**
     * @comportamiento Sin permiso sobre la vista de una pantalla, la portada pinta su acceso directo
     *   atenuado y sin enlace, y el menú tampoco la enlaza.
     */
    public function test_P1_sinPermisoDeVistaElAccesoSaleAtenuadoYSinEnlace(): void
    {
        $html = $this->pintarPortada("modulo = 'mod_cliente' AND vista = 'ListaClientes.php' AND accion IS NULL");

        self::assertStringNotContainsString('title="Clientes" href="' . self::CLIENTES . '"', $html);
        self::assertStringContainsString('<img  style="opacity:0.2" src="css/img/clientesP.png" alt="Clientes" />', $html);
        self::assertStringNotContainsString('/TPVFox/' . self::CLIENTES, $html, 'El menú no la enlaza.');
        self::assertStringContainsString('title="Proveedores" href="modulos/mod_proveedor/ListaProveedores.php"', $html);
    }

    /**
     * @estado rojo
     * @codigo-afectado index.php:172-180
     *
     * @group esperado
     * @group permisos
     * @group bajo
     *
     * @que-ocurre-hoy Con el permiso de la vista y sin el de su módulo, la portada enlaza el acceso
     *   directo de una pantalla que el menú de la misma página no enlaza.
     * @que-deberia-ocurrir Que la portada y el menú decidan igual: sin permiso del módulo, el acceso
     *   sale atenuado y sin enlace.
     * @por-que-ocurre La portada mira solo el permiso de la vista; el menú mira el del módulo y el de
     *   la vista.
     * @como-deberia-funcionar Que la portada decida con la misma consulta de permisos que el menú.
     */
    public function test_P2_sinPermisoDelModuloLaPortadaNoEnlazaLoQueElMenuNiega(): void
    {
        $html = $this->pintarPortada("modulo = 'mod_cliente' AND vista IS NULL");

        self::assertStringContainsString('title="Proveedores" href="modulos/mod_proveedor/ListaProveedores.php"', $html, 'La portada tiene que haberse pintado.');
        self::assertStringNotContainsString('/TPVFox/' . self::CLIENTES, $html, 'El menú no la enlaza.');
        self::assertStringNotContainsString('title="Clientes" href="' . self::CLIENTES . '"', $html, 'La portada no puede enlazar lo que el menú niega.');
    }

    /**
     * Siembra los permisos del usuario del caso, le quita el que señala la condición y pinta la
     * portada con su sesión. Devuelve el HTML.
     */
    private function pintarPortada(string $permisoQuitado): string
    {
        $usuario = ['id' => $this->usuario, 'group_id' => 9, 'login' => 'prueba_portada'];
        $guion = '$_SESSION = ["estadoTpv" => "Correcto"]; $_SERVER["REQUEST_URI"] = "/TPVFox/index.php";'
            . ' $_SERVER["DOCUMENT_ROOT"] = ""; chdir(' . var_export(RUTA_TPVFOX, true) . ');'
            . ' include "configuracion.php"; require "clases/ClaseSession.php";'
            . ' $db = (new ClaseConexion())->getConexion();'
            . ' $usuario = ' . var_export($usuario, true) . ';'
            . ' new ClasePermisos($usuario, $db);'
            . ' $db->query("UPDATE permisos SET permiso = 0 WHERE idUsuario = ' . $this->usuario . ' AND ' . addcslashes($permisoQuitado, '"') . '");'
            . ' $ClasePermisos = new ClasePermisos($usuario, $db);'
            . ' $thisTpv = new class($ClasePermisos) { public $permisos;'
            . '   public function __construct($permisos) { $this->permisos = $permisos; }'
            . '   public function getNombreFichero($direccion) { return "index"; } };'
            . ' $Usuario = $usuario; $Tienda = ["razonsocial" => "Tienda de pruebas"];'
            . ' include "index.php";';

        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -r ' . escapeshellarg($guion) . ' 2>/dev/null', $salida);

        return implode("\n", $salida);
    }
}
