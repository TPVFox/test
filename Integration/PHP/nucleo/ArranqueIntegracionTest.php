<?php

/**
 * El arranque de una petición: `inicial.php`, que carga la configuración, abre la sesión y deja los
 * globales de los que vive el resto del producto, y `ClaseConexion`, que conecta con la base de la
 * configuración de la instalación.
 *
 * `inicial.php` se ejecuta en un PHP aparte, como lo haría una petición, sin el arranque de la
 * suite. Sin sesión deja los permisos del invitado sembrados en la base, como en cualquier
 * petición: el caso borra lo que haya añadido.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\Nucleo;

use TPVFox\Test\CasoIntegracion;

final class ArranqueIntegracionTest extends CasoIntegracion
{
    /** La sesión de `inicial.php` escribe por su propia conexión: este caso no aísla por transacción. */
    protected bool $aislarPorTransaccion = false;

    private int $ultimoPermisoAntes = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ultimoPermisoAntes = (int) $this->db->query('SELECT COALESCE(MAX(id), 0) FROM permisos')->fetch_row()[0];
    }

    protected function tearDown(): void
    {
        $this->db->query('DELETE FROM permisos WHERE id > ' . $this->ultimoPermisoAntes);
        parent::tearDown();
    }

    /**
     * @comportamiento Sin sesión, `inicial.php` deja los formatos de fecha, la ruta del producto,
     *   la sesión con su conexión a la base de la configuración, el usuario invitado, sus permisos
     *   y la tienda principal activa.
     */
    public function test_A1_inicialDejaLosGlobalesDeLosQueViveElProducto(): void
    {
        $globales = self::ejecutarInicial();

        self::assertSame(
            ['d-m-Y H:i:s', 'Y-m-d H:i:s', 'Y-m-d'],
            [$globales['FORMATO_FECHA_ES'], $globales['FORMATO_FECHA_MYSQL'], $globales['FORMATO_FECHA_INPUT']]
        );
        self::assertSame(realpath(RUTA_TPVFOX), realpath($globales['URLCom']));
        self::assertSame('ClaseSession', $globales['thisTpv']);
        self::assertSame(self::configuracion()['nombrebdMysql'], $globales['base']);
        self::assertSame(['id' => 0, 'group_id' => 0, 'login' => 'invitado'], $globales['Usuario']);
        self::assertSame('ClasePermisos', $globales['ClasePermisos']);
        self::assertSame($this->tiendaPrincipalActiva(), (int) $globales['idTienda']);
        self::assertSame('SinActivar', $globales['estadoTpv']);
    }

    /**
     * @comportamiento La conexión se abre con los datos de `configuracion.php`: la base sobre la
     *   que queda es la configurada.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_A2_laConexionQuedaSobreLaBaseConfigurada(): void
    {
        $conexion = self::conexionDelProducto()->getConexion();

        self::assertInstanceOf(\mysqli::class, $conexion);
        self::assertSame(self::configuracion()['nombrebdMysql'], $conexion->query('SELECT DATABASE()')->fetch_row()[0]);
    }

    /**
     * @comportamiento La ruta segura que devuelve la conexión es la de `configuracion.php`.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_A3_laRutaSeguraEsLaConfigurada(): void
    {
        self::assertSame(self::configuracion()['ruta_segura'], self::conexionDelProducto()->getRutaSegura());
    }

    /**
     * @estado rojo
     * @codigo-afectado clases/ClaseConexion.php:51-55
     *
     * @group esperado
     * @group arranque
     * @group medio
     *
     * @que-ocurre-hoy La ruta de subida de ficheros sale vacía, con un aviso de PHP por una
     *   variable sin definir, aunque esté configurada.
     * @que-deberia-ocurrir Que devuelva la ruta de `configuracion.php`, como la ruta segura.
     * @por-que-ocurre La lee incluyendo `configuracion.php` con `include_once`; la conexión ya lo
     *   incluyó al construirse, así que la segunda vez no se carga y la variable no existe.
     * @como-deberia-funcionar Incluirlo con `include`, como hace la ruta segura, o guardar la ruta
     *   al leer la configuración.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_A4_laRutaDeSubidaEsLaConfigurada(): void
    {
        $conexion = self::conexionDelProducto();

        set_error_handler(static fn (): bool => true);
        try {
            $ruta = $conexion->getRutaUpload();
        } finally {
            restore_error_handler();
        }

        self::assertNotNull($ruta, 'La ruta de subida está configurada: no puede salir vacía.');
        self::assertSame(self::configuracion()['ruta_upload'], $ruta);
    }

    /**
     * @comportamiento Sin `configuracion.php` junto a `inicial.php`, el arranque escribe el motivo y
     *   termina: no abre la sesión ni deja ninguno de sus globales.
     */
    public function test_A5_sinConfiguracionElArranqueTerminaConElMotivo(): void
    {
        $salida = self::ejecutarCopiaDeInicial(null);

        self::assertStringContainsString('No existe fichero de configuracion', $salida);
        self::assertStringNotContainsString('sesion abierta', $salida);
    }

    /**
     * @comportamiento Con una configuración cuya ruta del producto no existe, el arranque escribe el
     *   motivo y termina antes de cargar la sesión.
     */
    public function test_A6_conUnaRutaDelProductoQueNoExisteElArranqueTerminaConElMotivo(): void
    {
        $salida = self::ejecutarCopiaDeInicial('<?php $RutaServidor = "/ruta/que/no/existe"; $HostNombre = "/producto";');

        self::assertStringContainsString('Error en ruta completa URLCom', $salida);
        self::assertStringNotContainsString('sesion abierta', $salida);
    }

    /**
     * @estado rojo
     * @codigo-afectado inicial.php:15-33
     *
     * @group esperado
     * @group arranque
     * @group bajo
     *
     * @que-ocurre-hoy Cuando falta la configuración, o su ruta del producto no existe, el arranque
     *   escribe en la página la ruta completa del servidor.
     * @que-deberia-ocurrir Que diga que la instalación no está configurada sin enseñar rutas del
     *   servidor, y que el detalle quede en el registro de errores.
     * @por-que-ocurre El motivo se compone con la ruta y se escribe con `print_r()` antes de
     *   terminar.
     * @como-deberia-funcionar Escribir un mensaje sin rutas y mandar la ruta al registro de errores.
     */
    public function test_A7_elMotivoDeUnArranqueFallidoNoEnsenaRutasDelServidor(): void
    {
        $sinConfiguracion = self::ejecutarCopiaDeInicial(null, $carpeta);
        $rutaQueNoExiste = self::ejecutarCopiaDeInicial('<?php $RutaServidor = "/ruta/que/no/existe"; $HostNombre = "/producto";');

        self::assertStringNotContainsString($carpeta, $sinConfiguracion, 'Sin configuración, la página no enseña la carpeta de la instalación.');
        self::assertStringNotContainsString('/ruta/que/no/existe', $rutaQueNoExiste, 'Con la ruta mal, la página no enseña la ruta configurada.');
    }

    /**
     * @comportamiento Si la sesión ya está construida en la petición, cargar otra vez `inicial.php`
     *   no la vuelve a construir: se quedan la sesión y los globales que había.
     */
    public function test_A8_conLaSesionYaConstruidaInicialNoLaVuelveAConstruir(): void
    {
        $guion = '$_SESSION = []; $thisTpv = "la de antes"; require ' . var_export(RUTA_TPVFOX . '/inicial.php', true) . ';'
            . ' echo "\n", json_encode(["thisTpv" => $thisTpv, "Usuario" => isset($Usuario), "BDTpv" => isset($BDTpv)]);';
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -r ' . escapeshellarg($guion) . ' 2>/dev/null', $salida, $codigo);

        self::assertSame(0, $codigo);
        self::assertSame(['thisTpv' => 'la de antes', 'Usuario' => false, 'BDTpv' => false], json_decode((string) end($salida), true));
    }

    /**
     * Ejecuta en un PHP aparte una copia de `inicial.php` en una carpeta temporal, con la
     * configuración que se le dé —o sin ella— y devuelve lo que escribe. Si llega a abrir la sesión,
     * escribe además «sesion abierta».
     */
    private static function ejecutarCopiaDeInicial(?string $configuracion, ?string &$carpeta = null): string
    {
        $carpeta = sys_get_temp_dir() . '/tpvfox-arranque-' . bin2hex(random_bytes(4));
        mkdir($carpeta);
        copy(RUTA_TPVFOX . '/inicial.php', $carpeta . '/inicial.php');
        if ($configuracion !== null) {
            file_put_contents($carpeta . '/configuracion.php', $configuracion);
        }
        $guion = '$_SESSION = []; require ' . var_export($carpeta . '/inicial.php', true) . '; echo "sesion abierta";';
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -r ' . escapeshellarg($guion) . ' 2>/dev/null', $salida);
        array_map('unlink', glob($carpeta . '/*') ?: []);
        rmdir($carpeta);

        return implode("\n", $salida);
    }

    /**
     * Ejecuta `inicial.php` en un PHP aparte, sin sesión, y devuelve lo que deja definido.
     *
     * @return array<string,mixed>
     */
    private static function ejecutarInicial(): array
    {
        // Con $_SESSION ya definida no se llama a session_start(), que en línea de órdenes no
        // tiene petición a la que atar la cookie: el contenido es el de una sesión nueva.
        $guion = '$_SESSION = []; require ' . var_export(RUTA_TPVFOX . '/inicial.php', true) . ';'
            . ' echo "\n", json_encode(['
            . '"FORMATO_FECHA_ES" => FORMATO_FECHA_ES, "FORMATO_FECHA_MYSQL" => FORMATO_FECHA_MYSQL,'
            . ' "FORMATO_FECHA_INPUT" => FORMATO_FECHA_INPUT, "URLCom" => $URLCom,'
            . ' "thisTpv" => get_class($thisTpv), "base" => $BDTpv->query("SELECT DATABASE()")->fetch_row()[0],'
            . ' "Usuario" => $Usuario, "ClasePermisos" => get_class($ClasePermisos),'
            . ' "idTienda" => $Tienda["idTienda"] ?? null, "estadoTpv" => $_SESSION["estadoTpv"]]);';

        exec(
            escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -r ' . escapeshellarg($guion) . ' 2>/dev/null',
            $salida,
            $codigo
        );

        self::assertSame(0, $codigo, 'inicial.php tiene que terminar sin error.');
        $globales = json_decode((string) end($salida), true);
        self::assertIsArray($globales, 'inicial.php no dejó sus globales: ' . implode("\n", $salida));

        return $globales;
    }

    /** La conexión del producto, construida como la construye la sesión. */
    private static function conexionDelProducto(): \ClaseConexion
    {
        require_once RUTA_TPVFOX . '/clases/ClaseConexion.php';

        return new \ClaseConexion();
    }

    /** @return array<string,mixed> las variables que define `configuracion.php` de la instalación */
    private static function configuracion(): array
    {
        return (static function (): array {
            include RUTA_TPVFOX . '/configuracion.php';

            return get_defined_vars();
        })();
    }

    private function tiendaPrincipalActiva(): int
    {
        return (int) $this->db->query(
            "SELECT idTienda FROM tiendas WHERE tipoTienda = 'principal' AND estado = 'Activo' ORDER BY idTienda LIMIT 1"
        )->fetch_assoc()['idTienda'];
    }
}
