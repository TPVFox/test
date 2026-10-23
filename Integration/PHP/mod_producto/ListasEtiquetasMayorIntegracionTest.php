<?php

/**
 * Las listas de los productos seleccionados de `mod_producto` —la de imprimir sus etiquetas
 * (`ListaEtiquetas.php`) y la de su mayor (`ListaMayor.php`), con su plantilla común, su tabla y su
 * columna— y la impresión de las etiquetas (`tareas/imprimirEtiquetas.php`), que piden las peticiones
 * de fondo de los dos módulos.
 *
 * Cada pantalla o petición se ejecuta en un PHP aparte, desde la carpeta de su módulo, con la sesión del
 * usuario de pruebas, su tienda y los permisos del módulo concedidos, y una transacción que se deshace al
 * terminar. La selección de `mod_producto` vive en la sesión: el caso la pone tras sembrar sus productos.
 * Los productos que siembra el caso quedan con un nombre —`:p1`, `:p2`…— y las balanzas, `:b1`, `:b2`…
 *
 * La impresión escribe su fichero en la carpeta temporal de la configuración: el caso la cambia por una
 * carpeta temporal propia, que se borra al terminar.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModProducto;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class ListasEtiquetasMayorIntegracionTest extends CasoIntegracion
{
    private const SCRIPT = '<script>x=1</script>';

    private int $idUsuario;

    private int $idTienda;

    private string $carpeta = '';

    protected function setUp(): void
    {
        parent::setUp();
        $siembra = new Siembra($this->db);
        $this->idUsuario = $siembra->usuarioPorDefecto();
        $this->idTienda = $siembra->tiendaPorDefecto();
        $this->carpeta = sys_get_temp_dir() . '/tpvfox-etiquetas-' . bin2hex(random_bytes(4));
        mkdir($this->carpeta);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->carpeta . '/*') ?: [] as $fichero) {
            unlink($fichero);
        }
        rmdir($this->carpeta);
        parent::tearDown();
    }

    /**
     * @comportamiento La lista de etiquetas enseña cada seleccionado marcado con su número, su nombre, su
     *   precio y su coste con dos decimales, su tipo, su existencia —con tres decimales si es al peso— y una
     *   caja con una etiqueta; al lado, el tamaño, la referencia o tecla y el botón de imprimir.
     */
    public function test_laListaDeEtiquetasEnsenaCadaSeleccionadoConUnaEtiqueta(): void
    {
        $pantalla = $this->pintar('mod_producto', 'ListaEtiquetas.php', [], [
            ...$this->producto('Kiwi'), $this->existencias(5),
            ...$this->producto('Queso', 'peso'), $this->existencias(2.5),
        ], [':p1', ':p2']);
        $id = $pantalla['ids'][':p1'];

        self::assertStringContainsString('Etiquetas: Imprimir etiquetas del producto', $pantalla['html']);
        self::assertStringContainsString('class="checkSelect" name="checkNameSelect" value="' . $id . '" checked>', $pantalla['html']);
        self::assertStringContainsString('<td>Kiwi</td>', $pantalla['html']);
        self::assertStringContainsString('<td>1.21€</td>', $pantalla['html']);
        self::assertStringContainsString('<td>1.00€</td>', $pantalla['html']);
        self::assertStringContainsString('<td>unidad</td>', $pantalla['html']);
        self::assertMatchesRegularExpression('/<td>\s*5\s*<\/td>/', $pantalla['html']);
        self::assertMatchesRegularExpression('/<td>\s*2\.500\s*<\/td>/', $pantalla['html']);
        self::assertStringContainsString("selecionarItemProducto({$id},'ListaEtiquetas')", $pantalla['html']);
        self::assertStringContainsString('value="1" style="text-align: right" class="cantidadEtiquetas" data-idarticulo="' . $id . '">', $pantalla['html']);
        self::assertStringContainsString('<select id="tamanhos" name="tamanhos">', $pantalla['html']);
        self::assertStringContainsString('<option value="2">Con Tecla</option>', $pantalla['html']);
        self::assertStringContainsString('imprimirEtiquetas("ListaEtiquetas")', $pantalla['html']);
        self::assertSame('', $pantalla['errores']);
    }

    /**
     * @comportamiento La lista del mayor enseña las fechas de los quince últimos días y, en cada
     *   seleccionado, el botón de quitarlo, el de ver su mayor y una caja con la existencia inicial a cero.
     */
    public function test_laListaDelMayorEnsenaLasFechasYLaExistenciaInicial(): void
    {
        $pantalla = $this->pintar('mod_producto', 'ListaMayor.php', [], $this->producto('Kiwi'), [':p1']);
        $id = $pantalla['ids'][':p1'];

        self::assertStringContainsString('Mayor del productos seleccionados', $pantalla['html']);
        self::assertStringContainsString('id="fecha_inicio" value="' . date('Y-m-d', strtotime('-15 days')) . '"', $pantalla['html']);
        self::assertStringContainsString('id="fecha_final" value="' . date('Y-m-d') . '"', $pantalla['html']);
        self::assertStringContainsString("selecionarItemProducto({$id},'ListaMayor')", $pantalla['html']);
        self::assertStringContainsString("redirecionarMayor({$id},'DetalleMayor')", $pantalla['html']);
        self::assertStringContainsString('value="0" style="text-align: right" id="stkini' . $id . '">', $pantalla['html']);
        self::assertSame('', $pantalla['errores']);
    }

    /**
     * @comportamiento Imprimir las etiquetas, desde la petición de fondo de cualquiera de los dos módulos,
     *   escribe el fichero en la carpeta temporal y responde su dirección, las etiquetas y cada producto
     *   con su número de etiquetas.
     */
    public function test_imprimirLasEtiquetasDesdeLosDosModulosEscribeElFichero(): void
    {
        $formulario = ['pulsado' => 'imprimirEtiquetas', 'tamano' => 'A7', 'teclaOReferencia' => '1', 'productos' => '[{"idArticulo":":p1","numEtiquetas":"2"}]'];

        foreach (['mod_producto', 'mod_productos'] as $modulo) {
            $impresion = $this->pintar($modulo, 'tareas.php', $formulario, $this->producto('Kiwi'));
            $respuesta = json_decode($impresion['html'], true);

            self::assertSame($this->carpeta . '/Etiquetaetiquetas.pdf', realpath(dirname($respuesta['fichero'])) . '/' . basename($respuesta['fichero']), $modulo);
            self::assertStringStartsWith('%PDF', (string) file_get_contents($this->carpeta . '/Etiquetaetiquetas.pdf'), $modulo);
            self::assertSame('2', $respuesta['productos'][0]['numEtiquetas'], $modulo);
            self::assertSame($impresion['ids'][':p1'], (string) $respuesta['productos'][0]['idArticulo'], $modulo);
            self::assertStringContainsString('Kiwi', $respuesta['html'], $modulo);
            unlink($this->carpeta . '/Etiquetaetiquetas.pdf');
        }
    }

    /**
     * @comportamiento Con el módulo de la balanza concedido, cada etiqueta lleva la tecla del producto en la
     *   primera balanza en que está: con su sección delante si esa balanza usa secciones. Sin el módulo, o
     *   sin tecla, no lleva ninguna.
     */
    public function test_conLaBalanzaLaEtiquetaLlevaSuTecla(): void
    {
        $formulario = ['pulsado' => 'imprimirEtiquetas', 'tamano' => 'A7', 'teclaOReferencia' => '2',
            'productos' => '[{"idArticulo":":p1","numEtiquetas":"1"},{"idArticulo":":p2","numEtiquetas":"1"},{"idArticulo":":p3","numEtiquetas":"1"}]'];
        $preparacion = [
            $this->balanza('Si'), $this->balanza('No'),
            ...$this->producto('Queso', 'peso'), 'INSERT INTO modulo_balanza_plus (idBalanza, plu, seccion, idArticulo) VALUES (:b1, 12, 3, :pN)',
            ...$this->producto('Jamón', 'peso'), 'INSERT INTO modulo_balanza_plus (idBalanza, plu, seccion, idArticulo) VALUES (:b2, 14, 0, :pN)',
            ...$this->producto('Kiwi'),
        ];

        $conBalanza = json_decode($this->pintar('mod_producto', 'tareas.php', $formulario, [...$preparacion, $this->moduloBalanza(1)])['html'], true);
        $sinBalanza = json_decode($this->pintar('mod_producto', 'tareas.php', $formulario, [...$preparacion, $this->moduloBalanza(0)])['html'], true);

        self::assertSame('3-12', $conBalanza['productos'][0]['plu']);
        self::assertSame('14', (string) $conBalanza['productos'][1]['plu']);
        self::assertArrayNotHasKey('plu', $conBalanza['productos'][2]);
        self::assertArrayNotHasKey('plu', $sinBalanza['productos'][0]);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/template/view_columna_etiquetas.php:6
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy En la lista de etiquetas de `mod_producto`, elegir el tamaño A9 imprime las etiquetas
     *   en A8.
     * @que-deberia-ocurrir Que cada tamaño imprima el suyo.
     * @por-que-ocurre La opción que dice A9 lleva el valor A8.
     * @como-deberia-funcionar Dar a la opción A9 el valor A9.
     */
    public function test_elTamanoA9DeLaListaDeEtiquetasEsElA9(): void
    {
        $pantalla = $this->pintar('mod_producto', 'ListaEtiquetas.php', [], $this->producto('Kiwi'), [':p1']);

        self::assertStringContainsString('<option value="A9">A9</option>', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/template/view_tabla.php:22
     * @codigo-afectado modulos/mod_producto/template/view_tabla.php:25
     *
     * @group defecto
     * @group salida
     * @group alto
     *
     * @que-ocurre-hoy Las listas de etiquetas y del mayor de `mod_producto` escriben el nombre del producto
     *   tal cual: un nombre con una etiqueta de script la ejecuta al abrirlas.
     * @que-deberia-ocurrir Que el nombre se escriba como texto.
     * @por-que-ocurre La tabla de las dos listas escribe el nombre, y el tipo, sin escaparlos.
     * @como-deberia-funcionar Escapar el nombre y el tipo al escribirlos.
     */
    public function test_lasListasEscribenElNombreComoTexto(): void
    {
        foreach (['ListaEtiquetas.php', 'ListaMayor.php'] as $lista) {
            $pantalla = $this->pintar('mod_producto', $lista, [], $this->producto(self::SCRIPT), [':p1']);

            self::assertStringNotContainsString('<td>' . self::SCRIPT . '</td>', $pantalla['html'], $lista);
            self::assertStringContainsString('<td>' . htmlspecialchars(self::SCRIPT) . '</td>', $pantalla['html'], $lista);
        }
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/ListaEtiquetas.php:11-27
     * @codigo-afectado modulos/mod_producto/ListaMayor.php:11-31
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Un producto seleccionado que ya no existe sale en las listas de etiquetas y del mayor
     *   como una fila vacía, con avisos de PHP.
     * @que-deberia-ocurrir Que las listas solo enseñen los productos que existen.
     * @por-que-ocurre Las dos listas pintan lo que devuelve la lectura de cada número de la selección sin
     *   mirar si el producto existe, y la lectura de un producto que no existe devuelve uno vacío.
     * @como-deberia-funcionar Saltar, o quitar de la selección, el número de un producto que no existe.
     */
    public function test_unSeleccionadoQueYaNoExisteNoSePintaEnLasListas(): void
    {
        foreach (['ListaEtiquetas.php', 'ListaMayor.php'] as $lista) {
            $pantalla = $this->pintar('mod_producto', $lista, [], $this->producto('Kiwi'), [':p1', '999999']);

            self::assertSame(1, substr_count($pantalla['html'], 'class="checkSelect"'), $lista);
            self::assertSame('', $pantalla['errores'], $lista);
        }
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/ListaEtiquetas.php:11
     * @codigo-afectado modulos/mod_producto/ListaMayor.php:11
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Pedidas por su dirección sin haber seleccionado nunca un producto, las listas de
     *   etiquetas y del mayor se pintan vacías con avisos de PHP.
     * @que-deberia-ocurrir Que, sin selección, las listas no den avisos, como las de `mod_productos`, que
     *   vuelven al listado.
     * @por-que-ocurre Las dos listas recorren la selección de la sesión sin mirar si existe.
     * @como-deberia-funcionar Tomar la selección como vacía si la sesión no la tiene, o volver al listado.
     */
    public function test_sinSeleccionEnLaSesionLasListasNoDanAvisos(): void
    {
        foreach (['ListaEtiquetas.php', 'ListaMayor.php'] as $lista) {
            $pantalla = $this->pintar('mod_producto', $lista);

            self::assertSame('', $pantalla['errores'], $lista);
        }
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/ListaMayor.php:28
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy El icono de imprimir de cada producto de la lista del mayor no hace nada al pulsarlo,
     *   aunque la cabecera dice que crea el PDF para imprimir el mayor.
     * @que-deberia-ocurrir Que imprima el mayor de su producto.
     * @por-que-ocurre La lista pinta el icono suelto, sin enlace ni acción.
     * @como-deberia-funcionar Ponerle la acción que imprime el mayor del producto, o quitarlo.
     */
    public function test_elIconoDeImprimirDeCadaProductoDelMayorTieneAccion(): void
    {
        $pantalla = $this->pintar('mod_producto', 'ListaMayor.php', [], $this->producto('Kiwi'), [':p1']);

        self::assertMatchesRegularExpression('/<a[^>]*onclick="[^"]+"[^>]*>\s*<span class="glyphicon glyphicon-print"><\/span>/', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/tareas/imprimirEtiquetas.php:25-26
     * @codigo-afectado modulos/mod_producto/tareas/imprimirEtiquetas.php:34
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Cada impresión de etiquetas, de cualquier usuario y desde cualquiera de los dos
     *   módulos, se escribe en el mismo fichero: una impresión que coincide con otra puede abrir las
     *   etiquetas de la otra.
     * @que-deberia-ocurrir Que cada impresión tenga su fichero.
     * @por-que-ocurre El nombre del fichero es fijo.
     * @como-deberia-funcionar Dar a cada impresión un nombre propio, con el usuario y el momento.
     */
    public function test_cadaImpresionDeEtiquetasTieneSuFichero(): void
    {
        $formulario = ['pulsado' => 'imprimirEtiquetas', 'tamano' => 'A7', 'teclaOReferencia' => '1', 'productos' => '[{"idArticulo":":p1","numEtiquetas":"1"}]'];

        $una = json_decode($this->pintar('mod_producto', 'tareas.php', $formulario, $this->producto('Kiwi'))['html'], true);
        $otra = json_decode($this->pintar('mod_productos', 'tareas.php', $formulario, $this->producto('Pera'))['html'], true);

        self::assertNotSame(basename($una['fichero']), basename($otra['fichero']));
    }

    /** @return list<string> */
    private function producto(string $nombre, string $tipo = 'unidad'): array
    {
        return [
            "INSERT INTO articulos (articulo_name, estado, fecha_creado, ultimoCoste, iva, beneficio, tipo) VALUES ('{$nombre}', 'Activo', '2026-01-01', 1, 21, 0, '{$tipo}')",
            'INSERT INTO articulosPrecios (idArticulo, pvpCiva, pvpSiva, idTienda) VALUES (:pN, 1.21, 1, :tienda)',
        ];
    }

    private function existencias(float $unidades): string
    {
        return "INSERT INTO articulosStocks (idArticulo, idTienda, stockOn, stockMin, stockMax) VALUES (:pN, :tienda, {$unidades}, 0, 0)";
    }

    private function balanza(string $conSeccion): string
    {
        return "INSERT INTO modulo_balanza (nombreBalanza, modelo, conSeccion, Grupo, `Dirección`, IP, soloPLUS) VALUES ('Balanza de pruebas', 'Prueba', '{$conSeccion}', 0, 0, '127.0.0.1', 1)";
    }

    private function moduloBalanza(int $permiso): string
    {
        return "UPDATE permisos SET permiso = {$permiso} WHERE idUsuario = :usuario AND modulo = 'mod_balanza' AND vista IS NULL AND accion IS NULL";
    }

    /**
     * Pinta una pantalla, o pide una orden a la petición de fondo, del módulo indicado, en un PHP aparte.
     * Tras sembrar la preparación pone en la sesión la selección de `mod_producto`, con los productos
     * indicados por su nombre; sin ella, la sesión no la tiene.
     *
     * @param array<string, string> $formulario
     * @param list<string> $preparacion
     * @param ?list<string> $seleccion
     * @return array{html: string, errores: string, ids: array<string, string>}
     */
    private function pintar(string $modulo, string $fichero, array $formulario = [], array $preparacion = [], ?array $seleccion = null): array
    {
        $sesion = [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => $this->idUsuario, 'group_id' => 1, 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => $this->idTienda, 'razonsocial' => 'Tienda de pruebas'],
        ];
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["DOCUMENT_ROOT"] = ""; $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = ' . var_export("/TPVFox/modulos/{$modulo}/{$fichero}", true) . ';'
            . ' chdir(' . var_export(RUTA_TPVFOX . "/modulos/{$modulo}", true) . ');'
            . ' require "./../../inicial.php";'
            . ' $rutatmp = str_repeat("/..", substr_count(rtrim($RutaServidor, "/"), "/")) . ' . var_export($this->carpeta, true) . ';'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ' new ClasePermisos($Usuario, $BDTpv);'
            . ' $BDTpv->query("UPDATE permisos SET permiso = 1 WHERE idUsuario = ' . $this->idUsuario . ' AND modulo = ' . var_export($modulo, true) . '");'
            . ' $sembrados = [":usuario" => "' . $this->idUsuario . '", ":tienda" => "' . $this->idTienda . '"]; $productos = 0; $balanzas = 0;'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->query(strtr($sql, $sembrados));'
            . ' if (str_starts_with($sql, "INSERT INTO articulos ")) { $sembrados[":p" . ++$productos] = $sembrados[":pN"] = (string) $BDTpv->insert_id; }'
            . ' if (str_starts_with($sql, "INSERT INTO modulo_balanza ")) { $sembrados[":b" . ++$balanzas] = (string) $BDTpv->insert_id; } }'
            . ($seleccion !== null ? ' $_SESSION["productos_seleccionados"] = array_map(fn ($nombre) => strtr($nombre, $sembrados), ' . var_export($seleccion, true) . ');' : '')
            . ' $_POST = array_map(fn ($valor) => strtr((string) $valor, $sembrados), ' . var_export($formulario, true) . ');'
            . ' $ClasePermisos = $thisTpv->permisos = new ClasePermisos($Usuario, $BDTpv);'
            . ' register_shutdown_function(function () use (&$sembrados) { echo "\n@@IDS@@" . json_encode($sembrados); });'
            . ' include ' . var_export($fichero, true) . ';';

        $errores = (string) tempnam(sys_get_temp_dir(), 'etiquetas');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$html, $ids] = array_pad(explode('@@IDS@@', implode("\n", $salida), 2), 2, 'null');
        $pintada = ['html' => trim($html), 'errores' => (string) file_get_contents($errores), 'ids' => json_decode($ids, true) ?? []];
        unlink($errores);

        return $pintada;
    }
}
