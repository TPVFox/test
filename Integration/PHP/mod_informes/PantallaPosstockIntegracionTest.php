<?php

/**
 * La pantalla de POSStock y sus órdenes (`modulos/mod_informes/POSStock.php` y, de `tareas/`, la
 * configuración, los datos, la tabla, los cruces, las familias y la exportación a CSV y a PDF).
 *
 * Cada petición se ejecuta en un PHP aparte, con la sesión del usuario de pruebas y su permiso, en una
 * transacción sobre su conexión que nunca se confirma; lo que necesita se siembra en ella con la siembra
 * de las pruebas, en 2019. La configuración de POSStock se lee y se guarda en la carpeta desde la que se
 * atiende la petición: aquí, una carpeta del caso con una copia del fichero de parámetros del módulo,
 * que se borra al terminar, como el PDF, que se escribe en ella.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModInformes;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class PantallaPosstockIntegracionTest extends CasoIntegracion
{
    private int $idUsuario;

    private int $idTienda;

    private string $carpeta;

    private string $modulo;

    protected function setUp(): void
    {
        parent::setUp();
        $siembra = new Siembra($this->db);
        $this->idUsuario = $siembra->usuarioPorDefecto();
        $this->idTienda = $siembra->tiendaPorDefecto();
        $this->carpeta = sys_get_temp_dir() . '/posstock-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $this->modulo = $this->carpeta . '/modulos/mod_informes';
        mkdir($this->modulo, 0755, true);
        copy(RUTA_TPVFOX . '/modulos/mod_informes/parametros.xml', $this->modulo . '/parametros.xml');
        file_put_contents($this->carpeta . '/inicial.php', "<?php\n");
    }

    protected function tearDown(): void
    {
        foreach ([...(glob($this->modulo . '/cache/*') ?: []), ...(glob($this->carpeta . '/*.pdf') ?: [])] as $fichero) {
            unlink($fichero);
        }
        if (is_dir($this->modulo . '/cache')) {
            rmdir($this->modulo . '/cache');
        }
        unlink($this->modulo . '/parametros.xml');
        rmdir($this->modulo);
        rmdir($this->carpeta . '/modulos');
        unlink($this->carpeta . '/inicial.php');
        rmdir($this->carpeta);
        parent::tearDown();
    }

    /** @return array<string, string> Los campos del formulario de la configuración, con sus valores. */
    private function camposDeLaConfiguracion(): array
    {
        $html = (string) $this->atender('tareas.php', ['pulsado' => 'abrirModalConfigPosstock'])['respuesta']['html'];
        preg_match_all('/<(?:input|select)[^>]*name="([^"]+)"[^>]*>/', $html, $etiquetas, PREG_SET_ORDER);
        $campos = [];
        foreach ($etiquetas as [$etiqueta, $nombre]) {
            if (str_starts_with($etiqueta, '<select')) {
                $resto = substr($html, strpos($html, $etiqueta));
                preg_match('/<option value="([^"]*)"[^>]*selected/', substr($resto, 0, (int) strpos($resto, '</select>')), $elegida);
                $campos[$nombre] = $elegida[1] ?? '';
            } elseif (str_contains($etiqueta, 'type="checkbox"')) {
                if (str_contains($etiqueta, 'checked')) {
                    $campos[$nombre] = '1';
                }
            } else {
                $campos[$nombre] = preg_match('/value="([^"]*)"/', $etiqueta, $valor) ? html_entity_decode($valor[1]) : '';
            }
        }

        return $campos;
    }

    /** @return array<string, string> Una revisión de marzo de 2019 de los casos pedidos. */
    private function marzo(string $casos): array
    {
        return ['fecha_inicio_movimientos' => '2019-03-01', 'fecha_fin_movimientos' => '2019-03-31', 'fecha_inicio_stock' => '2019-01-01', 'fecha_fin_stock' => '2019-02-28', 'casos_incluir' => $casos, 'tipo_periodo' => 'mes'];
    }

    /** Un producto que acaba marzo de 2019 en −3 y otro que recibe con stock alto, sembrados en la petición. */
    private function negativoYStockAlto(): string
    {
        return '$ids[":a1"] = $siembra->articulo("Zz Negativo"); $siembra->entradaProveedor($ids[":a1"], 5, "2019-01-10");'
            . ' $siembra->ventaTicket($ids[":a1"], 4, "2019-03-05"); $siembra->ventaTicket($ids[":a1"], 4, "2019-03-12");'
            . ' $ids[":a2"] = $siembra->articulo("Zz Parado"); $siembra->entradaProveedor($ids[":a2"], 20, "2019-01-10"); $siembra->entradaProveedor($ids[":a2"], 10, "2019-03-10");';
    }

    /**
     * @comportamiento La pantalla se pinta con la ventana de la configuración, el año en curso, los casos
     *   con el C1 marcado y los ficheros del JavaScript; sin el permiso de ejecutar, no se pinta.
     */
    public function test_laPantalla(): void
    {
        $con = $this->atender('POSStock.php', []);
        $sin = $this->atender('POSStock.php', [], '', true, false);

        self::assertStringContainsString('POSStock — Revisión de stock', $con['escrito']);
        self::assertStringContainsString('var POSSTOCK_VENTANA_DIAS = 7;', $con['escrito']);
        self::assertStringContainsString('value="' . date('Y') . '"', $con['escrito']);
        self::assertStringContainsString('id="posstockChk_caso1" value="caso1" checked', $con['escrito']);
        self::assertStringContainsString('/modulos/mod_informes/funciones.js?v=0431-171" type="module"', $con['escrito']);
        self::assertStringNotContainsString('POSStock — Revisión de stock', $sin['escrito']);
    }

    /** @comportamiento La ventana de configuración enseña los valores de partida del fichero de parámetros. */
    public function test_laVentanaDeConfiguracion(): void
    {
        $campos = $this->camposDeLaConfiguracion();

        self::assertSame(['7', '50', '24', '12', 'automatico', '0.95'], [$campos['inputVentanaDias'], $campos['inputUmbralSobrestock'], $campos['inputUmbralCaducidadSemanas'], $campos['inputUmbralSinRotacionSemanas'], $campos['inputModeloEstadistico'], $campos['inputModeloSignificancia']]);
    }

    /**
     * @comportamiento Guardar una configuración válida la escribe en la carpeta `cache/` de donde se
     *   atiende la petición, con el resto del fichero intacto y el multiplicador de cobertura del C6b, que
     *   el fichero de partida no trae, añadido; responde los valores guardados. Una no válida se rechaza
     *   con su motivo y no escribe nada.
     */
    public function test_guardarLaConfiguracion(): void
    {
        $campos = $this->camposDeLaConfiguracion();
        $formulario = static fn(array $c) => json_encode(array_map(static fn($n, $v) => ['name' => $n, 'value' => $v], array_keys($c), array_values($c)));

        $mala = $this->atender('tareas.php', ['pulsado' => 'guardarConfigPosstock', 'datosFormulario' => $formulario(['inputVentanaDias' => '5'] + $campos)]);
        self::assertFileDoesNotExist($this->modulo . '/cache/parametros.xml');
        self::assertStringContainsString('Ventana de días debe ser 0 (sin restricción), 7 o 14.', (string) $mala['respuesta']['error']);

        $buena = $this->atender('tareas.php', ['pulsado' => 'guardarConfigPosstock', 'datosFormulario' => $formulario(['inputVentanaDias' => '14', 'inputModeloEstadistico' => 'gamma'] + $campos)]);
        $guardado = simplexml_load_file($this->modulo . '/cache/parametros.xml');

        self::assertSame(['Configuración POSStock guardada correctamente.', 14, 'gamma'], [$buena['respuesta']['mensaje'], $buena['respuesta']['ventana_dias'], $buena['respuesta']['modelo_estadistico']]);
        self::assertSame(['14', 'gamma', '50'], [(string) $guardado->configuracion->posstock->ventana_dias, (string) $guardado->configuracion->posstock->modelo_estadistico, (string) $guardado->configuracion->posstock->umbral_sobrestock]);
        $nombres = static fn(\SimpleXMLElement $xml) => array_map(static fn($e) => $e->getName(), $xml->xpath('//*'));
        self::assertSame([], array_diff($nombres(simplexml_load_file($this->modulo . '/parametros.xml')), $nombres($guardado)));
        self::assertSame(['c6b_cobertura_max_mult'], array_values(array_diff($nombres($guardado), $nombres(simplexml_load_file($this->modulo . '/parametros.xml')))));
    }

    /**
     * @comportamiento Los datos del periodo son las incidencias del motor con el nombre de cada producto y
     *   el periodo pedido; por lotes, con la posición siguiente y el tamaño efectivo, que no pasa de 500.
     *   Con fechas que no son fechas, el error.
     */
    public function test_losDatosDelPeriodo(): void
    {
        $datos = $this->atender('tareas.php', ['pulsado' => 'getPOSStockData'] + $this->marzo('caso1'), $this->negativoYStockAlto());
        $lote = $this->atender('tareas.php', ['pulsado' => 'getPOSStockBatch', 'inicial' => '0', 'pagina' => '900', 'proveedores_incluir' => ''] + $this->marzo('caso1'), $this->negativoYStockAlto());
        $malas = $this->atender('tareas.php', ['pulsado' => 'getPOSStockData', 'fecha_inicio_movimientos' => '1/3/2019'] + $this->marzo('caso1'));

        $negativos = array_values(array_filter($datos['respuesta']['filas'], static fn($f) => $f['nombre'] === 'Zz Negativo'));
        self::assertSame(['Inventario en negativo', 'CRITICA'], [$negativos[0]['tipo'], $negativos[0]['severidad']]);
        self::assertSame(['2019-03-01', '2019-03-31'], [$datos['respuesta']['periodo']['fecha_inicio_movimientos'], $datos['respuesta']['periodo']['fecha_fin_movimientos']]);
        self::assertSame(500, $lote['respuesta']['pagina_efectiva']);
        self::assertContains('Zz Negativo', array_column($lote['respuesta']['filas'], 'nombre'));
        self::assertSame(['error' => 'Fechas no válidas o incompletas.'], $malas['respuesta']);
    }

    /**
     * @comportamiento La tabla se pinta en el servidor desde las filas que manda la página, entera o solo
     *   las filas; un JSON que no es una lista, el error. Sin productos del C7, los cruces vacíos; las
     *   familias, con su ventana.
     */
    public function test_laTablaLosCrucesYLasFamilias(): void
    {
        $filas = [['idArticulo' => 7, 'nombre' => 'Zz <b>Negativo</b>', 'tipo' => 'Inventario en negativo', 'severidad' => 'CRITICA', 'stock_actual' => -3.0, 'min_balance' => -3.0, 'posible_causa' => 'Ventas sin recepción', 'orden_clave' => '00']];
        $tabla = $this->atender('tareas.php', ['pulsado' => 'renderizarTablaPosstock', 'filas_json' => json_encode($filas), 'fecha_fin_movimientos' => '2019-03-31', 'fecha_inicio_stock' => '2019-01-01', 'anio' => '2019']);
        $mala = $this->atender('tareas.php', ['pulsado' => 'renderizarTablaPosstock', 'filas_json' => 'no es json']);
        $cruces = $this->atender('tareas.php', ['pulsado' => 'resolverPOSStockC7cde', 'ids_c7a' => '', 'ids_c7b' => ''] + $this->marzo(''));
        $familias = $this->atender('tareas.php', ['pulsado' => 'getFamiliasPosstock'], '$siembra->familia("Zz Despensa");');

        self::assertStringContainsString('Zz &lt;b&gt;Negativo&lt;/b&gt;', $tabla['respuesta']['html']);
        self::assertStringContainsString('Inventario en negativo', $tabla['respuesta']['html']);
        self::assertSame(['error' => 'JSON de incidencias no válido.'], $mala['respuesta']);
        self::assertSame(['cruces' => []], $cruces['respuesta']);
        self::assertContains('Zz Despensa', array_column($familias['respuesta']['familias'], 'nombre'));
        self::assertStringContainsString('posstockFamiliasDatalist', $familias['respuesta']['html']);
    }

    /**
     * @comportamiento Las incidencias de cada caso que da el motor se pintan en la tabla con su tipo y su
     *   severidad, una fila por incidencia y sin avisos de PHP: el negativo, el desajuste puntual, el stock
     *   alto, el inactivo, el agotamiento y la merma por lotes de productos de un proveedor sembrado.
     */
    public function test_laTablaDeCadaCaso(): void
    {
        $siembra = '$ids[":p1"] = $siembra->proveedor("Zz Frutas SL"); $nuevo = function ($nombre, $opciones = []) use ($siembra, &$ids) { $id = $siembra->articulo($nombre, $opciones); $siembra->articuloProveedor($id, $ids[":p1"], 1.0); return $id; };'
            . ' $a = $nuevo("Zz Negativo"); $siembra->entradaProveedor($a, 5, "2019-01-10"); $siembra->ventaTicket($a, 4, "2019-03-05"); $siembra->ventaTicket($a, 4, "2019-03-12");'
            . ' $a = $nuevo("Zz Puntual"); $siembra->ventaTicket($a, 3, "2019-03-05"); $siembra->entradaProveedor($a, 10, "2019-03-06");'
            . ' $a = $nuevo("Zz Parado"); $siembra->entradaProveedor($a, 20, "2019-01-10"); $siembra->entradaProveedor($a, 10, "2019-03-10");'
            . ' $a = $nuevo("Zz Quieto"); $siembra->existenciaRegistrada($a, 5);'
            . ' $a = $nuevo("Zz Rapido"); $siembra->existenciaRegistrada($a, 3); for ($d = 1; $d <= 31; $d++) { $siembra->ventaTicket($a, 2, sprintf("2019-03-%02d", $d)); }'
            . ' $a = $nuevo("Zz Queso", ["tipo" => "peso"]); foreach (["2019-03-04", "2019-03-11", "2019-03-18", "2019-04-02"] as $f) { $siembra->entradaProveedor($a, 10, $f); } foreach (["2019-03-06" => 6, "2019-03-13" => 6, "2019-03-20" => 6, "2019-03-27" => 2] as $f => $v) { $siembra->ventaTicket($a, $v, $f); } $siembra->existenciaRegistrada($a, 10);';
        $datos = $this->atender('tareas.php', ['pulsado' => 'getPOSStockData', 'proveedores_incluir' => ':p1'] + $this->marzo('caso1,caso2,caso4,caso5,caso6a,caso9'), $siembra);
        $tabla = $this->atender('tareas.php', ['pulsado' => 'renderizarTablaPosstock', 'filas_json' => json_encode($datos['respuesta']['filas']), 'fecha_fin_movimientos' => '2019-03-31', 'fecha_inicio_stock' => '2019-01-01', 'anio' => '2019']);

        $tipos = [];
        foreach ($datos['respuesta']['filas'] as $fila) {
            $tipos[$fila['nombre']][] = $fila['tipo'];
        }
        foreach (['Zz Negativo' => 'Inventario en negativo', 'Zz Puntual' => 'Desajuste Puntual de Stock', 'Zz Parado' => 'Entrada con stock alto', 'Zz Quieto' => 'Stock Inactivo en Periodo', 'Zz Rapido' => 'Agotamiento Estimado', 'Zz Queso' => 'Merma backstaging'] as $nombre => $tipo) {
            self::assertContains($tipo, $tipos[$nombre] ?? [], $nombre);
        }
        self::assertSame(count($datos['respuesta']['filas']), substr_count($tabla['respuesta']['html'], '<tr data-tipo=') + substr_count($tabla['respuesta']['html'], '<tr class="articulo-compra-activa" data-tipo='));
        self::assertSame('', $datos['errores'] . $tabla['errores']);
    }

    /**
     * @comportamiento El CSV lleva la marca de UTF-8, el periodo, la cabecera y una línea por incidencia
     *   con su producto, nombre, tipo, severidad, detalle y causa; con productos filtrados, solo esos. El
     *   PDF se escribe en la carpeta de temporales y se responde su dirección.
     */
    public function test_laExportacionACsvYPdf(): void
    {
        $csv = $this->atender('tareas.php', ['pulsado' => 'exportarPOSStockCSV'] + $this->marzo('caso1,caso2'), $this->negativoYStockAlto());
        $filtrado = $this->atender('tareas.php', ['pulsado' => 'exportarPOSStockCSV', 'articulos_filtrados' => ':a2'] + $this->marzo('caso1,caso2'), $this->negativoYStockAlto());
        $pdf = $this->atender('tareas.php', ['pulsado' => 'imprimirPOSStockPDF'] + $this->marzo('caso1,caso2'), $this->negativoYStockAlto());

        self::assertStringStartsWith("\xEF\xBB\xBF\"Periodo movimientos\";\"2019-03-01 - 2019-03-31\"", $csv['escrito']);
        self::assertStringContainsString('Articulo;Nombre;"Tipo incidencia";Severidad;Detalle;"Posible causa"', $csv['escrito']);
        self::assertStringContainsString(';"Zz Negativo";"Inventario en negativo";CRITICA;', $csv['escrito']);
        self::assertStringNotContainsString('Zz Negativo', $filtrado['escrito']);
        self::assertStringContainsString('Zz Parado', $filtrado['escrito']);
        self::assertMatchesRegularExpression('#/posstock_\d{8}_\d{6}\.pdf$#', (string) $pdf['respuesta']['url']);
        self::assertStringStartsWith('%PDF', (string) file_get_contents((string) (glob($this->carpeta . '/posstock_*.pdf')[0] ?? '')));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_informes/tareas/exportarPOSStockCSV.php:66-84; modulos/mod_informes/tareas/imprimirPOSStockPDF.php:77-94
     *
     * @group defecto
     * @group integridad
     * @group medio
     *
     * @que-ocurre-hoy El detalle del CSV y del PDF busca los tipos «Stock Negativo» y «Riesgo de
     *   caducidad teórica», que el motor no usa: el inventario en negativo y la caducidad teórica salen sin
     *   detalle. El de la entrada con stock alto lee la cantidad de un campo que no existe y pone 0.
     * @que-deberia-ocurrir Que el inventario en negativo lleve su stock, y la entrada con stock alto, lo
     *   recibido.
     * @por-que-ocurre Los nombres de los tipos y del campo son de una versión anterior del motor.
     * @como-deberia-funcionar Usar los tipos y los campos que pone el motor.
     */
    public function test_elDetalleDelCsvEsElDeCadaIncidencia(): void
    {
        $csv = $this->atender('tareas.php', ['pulsado' => 'exportarPOSStockCSV'] + $this->marzo('caso1,caso2'), $this->negativoYStockAlto());

        self::assertStringContainsString('"Stock actual: -3.00"', $csv['escrito']);
        self::assertStringContainsString('Entrada: 10.00', $csv['escrito']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_informes/tareas.php:1-12; modulos/mod_informes/tareas/guardarConfigPosstock.php:1-6
     *
     * @group defecto
     * @group seguridad
     * @group alto
     *
     * @que-ocurre-hoy Sin sesión, guardar la configuración de POSStock se atiende y la escribe: cualquiera
     *   que alcance el servidor cambia los umbrales con los que la tienda revisa su stock.
     * @que-deberia-ocurrir Que sin sesión no se guarde nada, y que guardar exija su permiso.
     * @por-que-ocurre La petición de fondo no mira la sesión ni el permiso.
     * @como-deberia-funcionar Comprobar la sesión y el permiso de configurar al empezar.
     */
    public function test_sinSesionNoSeGuardaLaConfiguracion(): void
    {
        $campos = $this->camposDeLaConfiguracion();
        $formulario = json_encode(array_map(static fn($n, $v) => ['name' => $n, 'value' => $v], array_keys($campos), array_values($campos)));

        $this->atender('tareas.php', ['pulsado' => 'guardarConfigPosstock', 'datosFormulario' => $formulario], '', false);

        self::assertFileDoesNotExist($this->modulo . '/cache/parametros.xml');
    }

    /**
     * Atiende la petición o pinta la pantalla en un PHP aparte, desde la carpeta del caso, en una
     * transacción que no se confirma, y devuelve lo escrito, la respuesta y los avisos.
     *
     * @param array<string, string> $formulario
     * @return array<string, mixed>
     */
    private function atender(string $fichero, array $formulario, string $preparacion = '', bool $conSesion = true, bool $conPermiso = true): array
    {
        $sesion = $conSesion ? [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => (string) $this->idUsuario, 'group_id' => '1', 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => (string) $this->idTienda, 'razonsocial' => 'Tienda de pruebas SL', 'NombreComercial' => 'Tienda de pruebas', 'nif' => 'B00000000', 'direccion' => 'Calle de pruebas 1', 'telefono' => '986000000', 'ano' => '2026'],
        ] : [];
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["REQUEST_METHOD"] = "POST"; $_SERVER["DOCUMENT_ROOT"] = ' . var_export(dirname(RUTA_TPVFOX), true) . ';'
            . ' $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = ' . var_export('/TPVFox/modulos/mod_informes/' . $fichero, true) . ';'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_informes', true) . ');'
            . ' $_POST = ["pulsado" => ""]; require ' . var_export(RUTA_TPVFOX . '/inicial.php', true) . ';'
            . ' $rutatmp = str_repeat("/..", substr_count(rtrim($RutaServidor, "/"), "/")) . ' . var_export($this->carpeta, true) . ';'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ($conSesion ? ' new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' $BDTpv->query("UPDATE permisos SET permiso = ' . ($conPermiso ? 1 : 0) . ' WHERE idUsuario = ' . $this->idUsuario . ' AND modulo = \'mod_informes\'");'
            . ($conSesion ? ' $ClasePermisos = $thisTpv->permisos = new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' require ' . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . ';'
            . ' $siembra = new \TPVFox\Test\Siembra\Siembra($BDTpv); $ids = [];'
            . ' ' . $preparacion
            . ' $_POST = json_decode(strtr(' . var_export((string) json_encode($formulario), true) . ', array_map("strval", $ids)), true);'
            . ' chdir(' . var_export($this->modulo, true) . ');'
            . ' include ' . var_export(RUTA_TPVFOX . '/modulos/mod_informes/' . $fichero, true) . ';';

        $errores = (string) tempnam(sys_get_temp_dir(), 'posstock');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -d error_log=/dev/null -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        $escrito = implode("\n", $salida);
        $ejecucion = ['escrito' => $escrito, 'respuesta' => json_decode(trim($escrito), true), 'errores' => (string) file_get_contents($errores)];
        unlink($errores);

        return $ejecucion;
    }
}
