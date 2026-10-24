<?php

/**
 * La hoja de conteo físico (`modulos/mod_informes/InformeConteoFisico.php` y, de `tareas/`, la lista de
 * productos del conteo, la hoja de conteo y la hoja rápida): los productos con su stock del sistema según
 * los filtros, las dos hojas en PDF con sus modos, sus rechazos y la página.
 *
 * Cada caso se ejecuta en un PHP aparte, con el arranque de la aplicación, la sesión del usuario de
 * pruebas y su permiso, en una transacción sobre su conexión que nunca se confirma; lo que necesita se
 * siembra en ella con la siembra de las pruebas. La carpeta de temporales de la aplicación se cambia por
 * una carpeta del caso, que se borra al terminar, y los PDF se escriben en ella.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModInformes;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class ConteoFisicoIntegracionTest extends CasoIntegracion
{
    private int $idUsuario;

    private int $idTienda;

    private string $carpeta;

    protected function setUp(): void
    {
        parent::setUp();
        $siembra = new Siembra($this->db);
        $this->idUsuario = $siembra->usuarioPorDefecto();
        $this->idTienda = $siembra->tiendaPorDefecto();
        $this->carpeta = sys_get_temp_dir() . '/conteo-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->carpeta, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->carpeta . '/*.pdf') ?: [] as $fichero) {
            unlink($fichero);
        }
        rmdir($this->carpeta);
        parent::tearDown();
    }

    /**
     * Una familia con una subfamilia; en la familia, dos productos activos con stock registrado y uno de
     * baja; en la subfamilia, otro activo.
     */
    private const FAMILIA = '$ids["f"] = $siembra->familia("Zz Verdura"); $ids["sf"] = $siembra->familia("Zz Hoja", $ids["f"]);'
        . ' $ids["a"] = $siembra->articulo("Zz Puerro", ["familia" => $ids["f"]]); $siembra->existenciaRegistrada($ids["a"], 12.5);'
        . ' $ids["b"] = $siembra->articulo("Zz Ajo", ["familia" => $ids["f"]]); $siembra->existenciaRegistrada($ids["b"], 3);'
        . ' $ids["c"] = $siembra->articulo("Zz Col de baja", ["familia" => $ids["f"], "estado" => "Baja"]);'
        . ' $ids["d"] = $siembra->articulo("Zz Lechuga", ["familia" => $ids["sf"]]);';

    /**
     * @comportamiento La lista del conteo da los productos pedidos, por nombre, con la suma de su stock
     *   registrado, solo los activos salvo que se pidan todos; con una familia sin subfamilias, sus productos. Unos
     *   identificadores que no lo son se rechazan.
     */
    public function test_losProductosDelConteo(): void
    {
        $porIds = $this->atender(['pulsado' => 'getArticulosConteoData', 'ids_articulos' => ':a, :b, :c'], self::FAMILIA);
        $todos = $this->atender(['pulsado' => 'getArticulosConteoData', 'ids_articulos' => ':a,:c', 'solo_activos' => '0'], self::FAMILIA);
        $familia = $this->atender(['pulsado' => 'getArticulosConteoData', 'id_familia' => ':sf'], self::FAMILIA);
        $invalidos = $this->atender(['pulsado' => 'getArticulosConteoData', 'ids_articulos' => 'x, 0'], self::FAMILIA);

        self::assertEquals([['idArticulo' => $porIds['ids']['b'], 'nombre' => 'Zz Ajo', 'stock_sistema' => 3], ['idArticulo' => $porIds['ids']['a'], 'nombre' => 'Zz Puerro', 'stock_sistema' => 12.5]], $porIds['respuesta']['filas']);
        self::assertSame(2, $porIds['respuesta']['total']);
        self::assertSame(['Zz Col de baja', 'Zz Puerro'], array_column($todos['respuesta']['filas'], 'nombre'));
        self::assertSame(['Zz Lechuga'], array_column($familia['respuesta']['filas'], 'nombre'));
        self::assertSame('IDs de artículos inválidos', $invalidos['respuesta']['error']);
    }

    /**
     * @comportamiento La hoja de conteo se genera en PDF en la carpeta de temporales, en modo normal o en
     *   modo ciego, y responde su dirección, el total de productos y el modo; un modo desconocido es el
     *   normal.
     */
    public function test_laHojaDeConteoNormalYCiega(): void
    {
        $normal = $this->atender(['pulsado' => 'imprimirInventarioConteoPDF', 'ids_articulos' => ':a,:b', 'titulo' => 'Zz <b>Recuento</b>', 'modo' => 'otro'], self::FAMILIA);
        $ciega = $this->atender(['pulsado' => 'imprimirInventarioConteoPDF', 'id_familia' => ':sf', 'modo' => 'ciega'], self::FAMILIA);

        self::assertMatchesRegularExpression('#/conteo_inventario_\d{8}_\d{6}\.pdf$#', (string) $normal['respuesta']['url']);
        self::assertSame([2, 'normal'], [$normal['respuesta']['total'], $normal['respuesta']['modo']]);
        self::assertSame([1, 'ciega'], [$ciega['respuesta']['total'], $ciega['respuesta']['modo']]);
        self::assertStringStartsWith('%PDF', (string) file_get_contents($this->carpeta . '/' . basename((string) $normal['respuesta']['url'])));
    }

    /**
     * @comportamiento La hoja rápida se genera en PDF en la carpeta de temporales, con el stock visible o en
     *   ciego, y responde su dirección y el total de productos.
     */
    public function test_laHojaRapida(): void
    {
        $rapida = $this->atender(['pulsado' => 'imprimirHojaRapidaConteoPDF', 'ids_articulos' => ':a,:b', 'mostrar_stock' => '0'], self::FAMILIA);

        self::assertMatchesRegularExpression('#/conteo_rapido_\d{8}_\d{6}\.pdf$#', (string) $rapida['respuesta']['url']);
        self::assertSame(2, $rapida['respuesta']['total']);
        self::assertStringStartsWith('%PDF', (string) file_get_contents($this->carpeta . '/' . basename((string) $rapida['respuesta']['url'])));
    }

    /**
     * @comportamiento Sin productos que cumplan los filtros, las dos hojas responden que no los hay; con
     *   identificadores que no lo son, que son inválidos; sin ningún filtro y con todos los estados, que
     *   hace falta uno.
     */
    public function test_losRechazosDeLasHojas(): void
    {
        $vacia = $this->atender(['pulsado' => 'imprimirInventarioConteoPDF', 'ids_articulos' => ':c'], self::FAMILIA);
        $invalida = $this->atender(['pulsado' => 'imprimirHojaRapidaConteoPDF', 'ids_articulos' => 'x'], self::FAMILIA);
        $sinFiltro = $this->atender(['pulsado' => 'imprimirHojaRapidaConteoPDF', 'solo_activos' => '0'], self::FAMILIA);

        self::assertSame('No se encontraron artículos con los filtros indicados.', $vacia['respuesta']['error']);
        self::assertSame('IDs de artículos inválidos', $invalida['respuesta']['error']);
        self::assertSame('Se requiere al menos un filtro (familia o lista de artículos).', $sinFiltro['respuesta']['error']);
        self::assertSame([], glob($this->carpeta . '/*.pdf'));
    }

    /**
     * @comportamiento La página ofrece el título, el filtro de familia o de identificadores, solo los
     *   activos, el modo normal o ciego, la hoja de conteo y la hoja rápida.
     */
    public function test_laPaginaDelConteo(): void
    {
        $pagina = $this->atender([], '', 'InformeConteoFisico.php');

        self::assertStringContainsString('Hoja de conteo f&iacute;sico', $pagina['escrito']);
        foreach (['id="cfTitulo"', 'id="cfIdsArticulos"', 'id="cfSoloActivos"', 'id="cfModoCiega"', 'id="cfBtnGenerar"', 'id="hrBtnGenerar"', "'imprimirInventarioConteoPDF'", "'imprimirHojaRapidaConteoPDF'"] as $elemento) {
            self::assertStringContainsString($elemento, $pagina['escrito']);
        }
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_informes/tareas/imprimirInventarioConteoPDF.php:48-52; modulos/mod_informes/tareas/imprimirHojaRapidaConteoPDF.php:43-47; modulos/mod_informes/tareas/getArticulosConteoData.php:29-35
     *
     * @group defecto
     * @group integridad
     * @group medio
     *
     * @que-ocurre-hoy Elegir una familia del catálogo trae solo los productos que cuelgan de ella misma: los
     *   de sus subfamilias no entran en la hoja de conteo.
     * @que-deberia-ocurrir Que la familia traiga también los productos de sus subfamilias.
     * @por-que-ocurre El filtro busca los productos de esa familia exacta, sin sus descendientes.
     * @como-deberia-funcionar Filtrar por la familia y todas sus descendientes.
     */
    public function test_unaFamiliaTraeLosProductosDeSusSubfamilias(): void
    {
        $hoja = $this->atender(['pulsado' => 'imprimirHojaRapidaConteoPDF', 'id_familia' => ':f'], self::FAMILIA);

        self::assertSame(3, $hoja['respuesta']['total'] ?? null);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_informes/tareas/imprimirInventarioConteoPDF.php:60-67; modulos/mod_informes/tareas/imprimirHojaRapidaConteoPDF.php:55-62
     *
     * @group defecto
     * @group integridad
     * @group bajo
     *
     * @que-ocurre-hoy Sin familia ni identificadores, la hoja se genera con todos los productos activos: el
     *   rechazo «Se requiere al menos un filtro» solo salta si además se piden todos los estados.
     * @que-deberia-ocurrir Que sin familia ni identificadores la hoja se rechace.
     * @por-que-ocurre El rechazo mira si no hay ninguna condición, y la de solo activos ya lo es.
     * @como-deberia-funcionar Exigir una familia o unos identificadores, aparte del estado.
     */
    public function test_sinFamiliaNiProductosLaHojaSeRechaza(): void
    {
        $hoja = $this->atender(['pulsado' => 'imprimirHojaRapidaConteoPDF'], self::FAMILIA);

        self::assertSame('Se requiere al menos un filtro (familia o lista de artículos).', $hoja['respuesta']['error'] ?? null);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_informes/InformeConteoFisico.php:8-11; modulos/mod_informes/acces.xml
     *
     * @group defecto
     * @group seguridad
     * @group alto
     *
     * @que-ocurre-hoy La página comprueba el permiso de ejecutar, pero no está declarada en el fichero de
     *   permisos del módulo: el permiso no existe, la comprobación compara vacío con cero y cualquier usuario
     *   con sesión la abre, también sin el permiso de los informes.
     * @que-deberia-ocurrir Que sin el permiso no se sirva la página.
     * @por-que-ocurre Sin declaración no hay permiso que leer, y lo vacío no es igual a cero.
     * @como-deberia-funcionar Declarar la página con su acción y negar si el permiso no es uno.
     */
    public function test_sinPermisoNoSeAbreLaPagina(): void
    {
        $pagina = $this->atender([], '', 'InformeConteoFisico.php', false);

        self::assertStringNotContainsString('Hoja de conteo f&iacute;sico', $pagina['escrito']);
    }

    /**
     * Atiende la página o una orden de `tareas.php` en un PHP aparte, con la sesión, el permiso de los
     * informes (o sin él) y la siembra, en una transacción que no se confirma, y con la carpeta de
     * temporales en la del caso. En el formulario, `:x` se cambia por lo sembrado en `$ids["x"]`. Devuelve
     * lo escrito, su respuesta, lo sembrado y los avisos.
     *
     * @param array<string, string> $formulario
     * @return array<string, mixed>
     */
    private function atender(array $formulario, string $siembra, string $fichero = 'tareas.php', bool $conPermiso = true): array
    {
        $sesion = [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => (string) $this->idUsuario, 'group_id' => '1', 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => (string) $this->idTienda, 'razonsocial' => 'Tienda de pruebas SL', 'NombreComercial' => 'Tienda de pruebas', 'nif' => 'B00000000', 'direccion' => 'Calle de pruebas 1', 'telefono' => '986000000', 'ano' => '2026'],
        ];
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["REQUEST_METHOD"] = "POST"; $_SERVER["DOCUMENT_ROOT"] = ' . var_export(dirname(RUTA_TPVFOX), true) . ';'
            . ' $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = ' . var_export('/TPVFox/modulos/mod_informes/' . $fichero, true) . ';'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_informes', true) . ');'
            . ' $_POST = ["pulsado" => ""]; require ' . var_export(RUTA_TPVFOX . '/inicial.php', true) . ';'
            . ' $rutatmp = str_repeat("/..", substr_count(rtrim($RutaServidor, "/"), "/")) . ' . var_export($this->carpeta, true) . ';'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ' new ClasePermisos($Usuario, $BDTpv);'
            . ' $BDTpv->query("UPDATE permisos SET permiso = ' . ($conPermiso ? 1 : 0) . ' WHERE idUsuario = ' . $this->idUsuario . ' AND modulo = \'mod_informes\'");'
            . ' $ClasePermisos = $thisTpv->permisos = new ClasePermisos($Usuario, $BDTpv);'
            . ' require ' . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . ';'
            . ' $siembra = new \TPVFox\Test\Siembra\Siembra($BDTpv); $ids = [];'
            . ' ' . $siembra
            . ' $marcas = []; foreach ($ids as $k => $v) { $marcas[":" . $k] = (string) $v; } uksort($marcas, static fn($x, $y) => strlen($y) <=> strlen($x));'
            . ' $_POST = json_decode(strtr(' . var_export((string) json_encode($formulario), true) . ', $marcas), true);'
            . ' register_shutdown_function(function () use (&$ids) { echo "\n@@DATOS@@" . json_encode(["ids" => $ids]); });'
            . ' include ' . var_export($fichero, true) . ';';

        $errores = (string) tempnam(sys_get_temp_dir(), 'conteo');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -d error_log=/dev/null -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$escrito, $datos] = array_pad(explode('@@DATOS@@', implode("\n", $salida), 2), 2, 'null');
        $ejecucion = ['escrito' => $escrito, 'respuesta' => json_decode(trim($escrito), true), 'errores' => (string) file_get_contents($errores)] + ((json_decode($datos, true) ?? []) + ['ids' => []]);
        unlink($errores);

        return $ejecucion;
    }
}
