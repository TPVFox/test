<?php

/**
 * Las copias de seguridad: la pantalla (`CopiaSeguridad.php`) y hacer una copia (`exportarBD_backup.php`,
 * con `ClaseBackup.php`).
 *
 * Cada una se ejecuta en un PHP aparte, desde la carpeta del módulo, con la sesión de un usuario de
 * pruebas —el de pruebas, sin permisos en el módulo, o el de los recorridos, con todos— o sin ninguna.
 * Hacer una copia escribe un fichero en una carpeta fuera del producto, donde vive lo de una
 * instalación: el caso define antes esa carpeta como una suya, que se borra al terminar, y la copia ya
 * no la cambia. La copia lee la base con su propia conexión, de modo que solo ve lo guardado, no lo que
 * el caso sembrara en una transacción. La restauración (`importarBD.php`) no se ejecuta nunca: restaura
 * al pedir su dirección, con una base, un usuario y un fichero escritos en el código.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModCopiaSeguridad;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class PantallasCopiaSeguridadIntegracionTest extends CasoIntegracion
{
    private const SIN_PERMISOS = 83;
    private const CON_PERMISOS = 84;

    private int $idTienda;

    private string $carpeta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->idTienda = (new Siembra($this->db))->tiendaPorDefecto();
        $this->carpeta = sys_get_temp_dir() . '/copia-seguridad-' . getmypid() . '-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->carpeta . '/*') ?: [] as $fichero) {
            unlink($fichero);
        }
        if (is_dir($this->carpeta)) {
            rmdir($this->carpeta);
        }
        parent::tearDown();
    }

    /**
     * Ejecuta un fichero del módulo en un PHP aparte, con la carpeta de las copias llevada a la del caso,
     * y devuelve su HTML y su stderr. Sin `$usuario`, sin sesión.
     *
     * @return array{html: string, errores: string}
     */
    private function ejecutar(string $fichero, ?int $usuario): array
    {
        $sesion = $usuario !== null ? [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => (string) $usuario, 'group_id' => $usuario === self::CON_PERMISOS ? '9' : '1', 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => (string) $this->idTienda, 'razonsocial' => 'Tienda de pruebas'],
        ] : [];
        $guion = 'define("BACKUP_DIR", ' . var_export($this->carpeta, true) . ');'
            . ' $_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["DOCUMENT_ROOT"] = "";'
            . ' $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = ' . var_export("/TPVFox/modulos/mod_copia_seguridad/{$fichero}", true) . ';'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_copia_seguridad', true) . ');'
            . ' include ' . var_export($fichero, true) . ';';

        $errores = (string) tempnam(sys_get_temp_dir(), 'copia');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        $pintada = ['html' => implode("\n", $salida), 'errores' => (string) file_get_contents($errores)];
        unlink($errores);

        return $pintada;
    }

    /** @return list<string> Los ficheros que la copia dejó en la carpeta del caso. */
    private function copias(): array
    {
        return glob($this->carpeta . '/*-backup-*.sql') ?: [];
    }

    /** @comportamiento Con permiso, la pantalla ofrece hacer una copia y restaurar. */
    public function test_laPantallaOfreceHacerUnaCopiaYRestaurar(): void
    {
        $pantalla = $this->ejecutar('CopiaSeguridad.php', self::CON_PERMISOS);

        self::assertStringContainsString('<h2> Copias de Seguridad </h2>', $pantalla['html']);
        self::assertStringContainsString('href="./exportarBD_backup.php"', $pantalla['html']);
        self::assertStringContainsString("metodoClick('restaurar')", $pantalla['html']);
    }

    /** @comportamiento Sin sesión, la pantalla pide entrar y no se pinta. */
    public function test_sinSesionLaPantallaNoSePinta(): void
    {
        $pantalla = $this->ejecutar('CopiaSeguridad.php', null);

        self::assertStringNotContainsString('Copias de Seguridad', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_copia_seguridad/CopiaSeguridad.php:48-49
     *
     * @group defecto
     * @group copia
     * @group alto
     *
     * @que-ocurre-hoy Sin los permisos de hacer copias y de restaurar, la pantalla ofrece igual las dos
     *   opciones.
     * @que-deberia-ocurrir Que cada opción solo se ofrezca con su permiso.
     * @por-que-ocurre El módulo declara los permisos, y la pantalla no los consulta.
     * @como-deberia-funcionar Consultar cada permiso antes de pintar su opción.
     */
    public function test_sinPermisosNoSeOfreceHacerNiRestaurar(): void
    {
        $pantalla = $this->ejecutar('CopiaSeguridad.php', self::SIN_PERMISOS);

        self::assertStringContainsString('<h2> Copias de Seguridad </h2>', $pantalla['html']);
        self::assertStringNotContainsString('href="./exportarBD_backup.php"', $pantalla['html']);
        self::assertStringNotContainsString("metodoClick('restaurar')", $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_copia_seguridad/CopiaSeguridad.php:21,44,49
     *
     * @group defecto
     * @group copia
     * @group medio
     *
     * @que-ocurre-hoy La pantalla carga un JavaScript del módulo que no existe, y la opción de restaurar
     *   llama a una función suya: no hace nada. El enlace «Volver Atrás» lleva a una pantalla que tampoco
     *   existe en el módulo.
     * @que-deberia-ocurrir Que cada opción y cada enlace lleven a algo que existe.
     * @por-que-ocurre El módulo no tiene `funciones.js` ni `tpv.php`.
     * @como-deberia-funcionar Quitar lo que no existe o darle su fichero.
     */
    public function test_loQueLaPantallaCargaYEnlazaExiste(): void
    {
        $pantalla = $this->ejecutar('CopiaSeguridad.php', self::CON_PERMISOS);

        self::assertStringContainsString('/modulos/mod_copia_seguridad/funciones.js', $pantalla['html']);
        self::assertStringContainsString('href="./tpv.php"', $pantalla['html']);
        self::assertFileExists(RUTA_TPVFOX . '/modulos/mod_copia_seguridad/funciones.js');
        self::assertFileExists(RUTA_TPVFOX . '/modulos/mod_copia_seguridad/tpv.php');
    }

    /**
     * @comportamiento Hacer una copia escribe en su carpeta un fichero con el nombre de la base y, por cada
     *   tabla, su borrado, su creación y sus filas, y lo dice.
     */
    public function test_hacerUnaCopiaGuardaUnFicheroConSusTablas(): void
    {
        $pantalla = $this->ejecutar('exportarBD_backup.php', self::CON_PERMISOS);

        $copias = $this->copias();
        self::assertCount(1, $copias);
        $copia = (string) file_get_contents($copias[0]);
        self::assertStringContainsString('DROP TABLE IF EXISTS `usuarios`;', $copia);
        self::assertStringContainsString('CREATE TABLE `usuarios`', $copia);
        self::assertStringContainsString('INSERT INTO `tiendas` VALUES', $copia);
        self::assertStringContainsString('Backup result: OK', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_copia_seguridad/ClaseBackup.php:75
     *
     * @group defecto
     * @group copia
     * @group bajo
     *
     * @que-ocurre-hoy Cada copia deja un aviso de PHP, al añadir la primera tabla a un texto que no se
     *   empezó.
     * @que-deberia-ocurrir Que la copia se haga sin avisos.
     * @por-que-ocurre El texto de las sentencias se concatena sin haberlo puesto antes a vacío.
     * @como-deberia-funcionar Empezar el texto vacío.
     */
    public function test_hacerUnaCopiaNoDejaAvisos(): void
    {
        $pantalla = $this->ejecutar('exportarBD_backup.php', self::CON_PERMISOS);

        self::assertCount(1, $this->copias());
        self::assertStringNotContainsString('Undefined variable', $pantalla['errores']);
    }

    /** @comportamiento Sin sesión, la copia no se hace. */
    public function test_sinSesionNoSeHaceLaCopia(): void
    {
        $this->ejecutar('exportarBD_backup.php', null);

        self::assertSame([], $this->copias());
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_copia_seguridad/exportarBD_backup.php:41-42
     *
     * @group defecto
     * @group copia
     * @group alto
     *
     * @que-ocurre-hoy Hacer una copia guarda solo 11 tablas —usuarios, tiendas, tickets y cierres—: los
     *   productos, los clientes, los proveedores, las compras y las ventas no están en la copia.
     * @que-deberia-ocurrir Que la copia guarde todas las tablas de la base.
     * @por-que-ocurre La lista de tablas está escrita en la pantalla, y la copia completa está comentada.
     * @como-deberia-funcionar Copiar todas las tablas, o las que fije la configuración.
     */
    public function test_laCopiaGuardaTodasLasTablasDeLaBase(): void
    {
        $this->ejecutar('exportarBD_backup.php', self::CON_PERMISOS);

        $copias = $this->copias();
        self::assertCount(1, $copias);
        $copia = (string) file_get_contents($copias[0]);
        foreach (['articulos', 'clientes', 'proveedores', 'albprot', 'facclit'] as $tabla) {
            self::assertStringContainsString("CREATE TABLE `$tabla`", $copia);
        }
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_copia_seguridad/exportarBD_backup.php:1-72
     *
     * @group defecto
     * @group copia
     * @group alto
     *
     * @que-ocurre-hoy Sin el permiso de hacer copias, pedir la página de la copia la hace igual.
     * @que-deberia-ocurrir Que sin el permiso no se haga.
     * @por-que-ocurre La página no consulta el permiso que declara el módulo.
     * @como-deberia-funcionar Consultar el permiso al empezar.
     */
    public function test_sinPermisoNoSeHaceLaCopia(): void
    {
        $this->ejecutar('exportarBD_backup.php', self::SIN_PERMISOS);

        self::assertSame([], $this->copias());
    }
}
