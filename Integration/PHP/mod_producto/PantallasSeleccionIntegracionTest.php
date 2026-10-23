<?php

/**
 * Las pantallas de la selección de `mod_productos`: la lista de los productos seleccionados para
 * imprimir sus etiquetas o ver su mayor (`ListaSeleccion.php`, con `template/view_seleccion.php`) y la
 * exportación de la selección a CSV (`ExportarCsvProductos.php`).
 *
 * Cada una se pinta en un PHP aparte, desde la carpeta del módulo, con la sesión del usuario de pruebas
 * o sin ninguna, los permisos del módulo concedidos salvo los que el caso quite, y una transacción que
 * se deshace al terminar. La selección del caso se guarda en el fichero del usuario dentro del mismo
 * proceso, tras sembrar sus productos; el fichero de selección del usuario y el del invitado se guardan
 * antes de cada caso y se reponen al terminar.
 *
 * Sin selección, las dos pantallas mandan al listado y terminan: el proceso no recoge la cabecera de la
 * redirección, y el caso comprueba que no pintan nada.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModProducto;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class PantallasSeleccionIntegracionTest extends CasoIntegracion
{
    private const SCRIPT = '<script>x=1</script>';

    private const BOM = "\xEF\xBB\xBF";

    private int $idUsuario;

    private int $idTienda;

    /** @var array<string, ?string> */
    private array $seleccionesAntes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $siembra = new Siembra($this->db);
        $this->idUsuario = $siembra->usuarioPorDefecto();
        $this->idTienda = $siembra->tiendaPorDefecto();
        foreach ([$this->ficheroDeSeleccion($this->idUsuario), $this->ficheroDeSeleccion(0)] as $fichero) {
            $this->seleccionesAntes[$fichero] = is_file($fichero) ? (string) file_get_contents($fichero) : null;
            if (is_file($fichero)) {
                unlink($fichero);
            }
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->seleccionesAntes as $fichero => $contenido) {
            if ($contenido === null) {
                if (is_file($fichero)) {
                    unlink($fichero);
                }
            } else {
                file_put_contents($fichero, $contenido);
            }
        }
        parent::tearDown();
    }

    /**
     * @comportamiento La lista de etiquetas, la de partida, enseña cada seleccionado marcado con su número,
     *   su nombre, su precio y su coste con dos decimales, su tipo, su existencia y una caja con una
     *   etiqueta; encima, el tamaño, la referencia o tecla y el botón de imprimir.
     */
    public function test_laListaDeEtiquetasEnsenaCadaSeleccionadoConSuCantidad(): void
    {
        $pantalla = $this->pintar('ListaSeleccion.php', [], [...$this->producto('Kiwi'), $this->existencias(5)], [':p1']);
        $id = $pantalla['ids'][':p1'];

        self::assertStringContainsString('Etiquetas: Imprimir etiquetas', $pantalla['html']);
        self::assertStringContainsString('class="checkSelect"' . "\n" . '                                           value="' . $id . '" checked>', $pantalla['html']);
        self::assertStringContainsString('<td>Kiwi</td>', $pantalla['html']);
        self::assertStringContainsString('<td>1.21€</td>', $pantalla['html']);
        self::assertStringContainsString('<td>1.00€</td>', $pantalla['html']);
        self::assertMatchesRegularExpression('/<td>\s*unidad\s*<\/td>/', $pantalla['html']);
        self::assertMatchesRegularExpression('/<td>\s*5\s*<\/td>/', $pantalla['html']);
        self::assertStringContainsString('class="cantidadEtiquetas" data-idarticulo="' . $id . '">', $pantalla['html']);
        self::assertStringContainsString('<option value="A9">A9</option>', $pantalla['html']);
        self::assertStringContainsString('<option value="3">Con Referencia</option>', $pantalla['html']);
        self::assertStringContainsString("onclick=\"imprimirEtiquetas('ListaEtiquetas')\"", $pantalla['html']);
        self::assertStringContainsString('onclick="eliminarDeSeleccion(' . $id . ', this)"', $pantalla['html']);
        self::assertStringNotContainsString('redirecionarMayor(' . $id, $pantalla['html']);
        self::assertSame('', $pantalla['errores']);
    }

    /**
     * @comportamiento La lista del mayor enseña las fechas de los quince últimos días, el botón de ver el
     *   mayor de los marcados y, en cada seleccionado, el botón de su mayor y una caja con la existencia
     *   inicial a cero.
     */
    public function test_laListaDelMayorEnsenaLasFechasYLaExistenciaInicial(): void
    {
        $pantalla = $this->pintar('ListaSeleccion.php', ['modo' => 'mayor'], $this->producto('Kiwi'), [':p1']);
        $id = $pantalla['ids'][':p1'];

        self::assertStringContainsString('Mayor de productos seleccionados', $pantalla['html']);
        self::assertStringContainsString('value="' . date('Y-m-d', strtotime('-15 days')) . '">', $pantalla['html']);
        self::assertStringContainsString('id="fecha_final" class="form-control"' . "\n" . '                                       value="' . date('Y-m-d') . '">', $pantalla['html']);
        self::assertStringContainsString('onclick="verMayorSeleccionados()"', $pantalla['html']);
        self::assertStringContainsString("onclick=\"redirecionarMayor({$id}, 'DetalleMayor')\"", $pantalla['html']);
        self::assertStringContainsString('id="stkini' . $id . '">', $pantalla['html']);
        self::assertStringNotContainsString('class="cantidadEtiquetas"', $pantalla['html']);
        self::assertSame('', $pantalla['errores']);
    }

    /** @comportamiento Un producto al peso enseña su existencia con tres decimales; uno por unidades, sin decimales. */
    public function test_laExistenciaSeEnsenaSegunElTipoDelProducto(): void
    {
        $pantalla = $this->pintar('ListaSeleccion.php', [], [
            ...$this->producto('Queso', 'peso'), $this->existencias(2.5),
            ...$this->producto('Kiwi'), $this->existencias(2.5),
        ], [':p1', ':p2']);

        self::assertStringContainsString('glyphicon-peso', $pantalla['html']);
        self::assertMatchesRegularExpression('/<td>\s*2\.500\s*<\/td>/', $pantalla['html']);
        self::assertMatchesRegularExpression('/<td>\s*3\s*<\/td>/', $pantalla['html']);
    }

    /** @comportamiento Sin el permiso de imprimir etiquetas o el de imprimir el mayor, la lista no enseña sus controles. */
    public function test_sinPermisoLaListaNoEnsenaSusControles(): void
    {
        $etiquetas = $this->pintar('ListaSeleccion.php', [], [...$this->producto('Kiwi'), $this->quitarPermiso('ListaSeleccion.php', 'imprimirEtiquetas')], [':p1']);
        $mayor = $this->pintar('ListaSeleccion.php', ['modo' => 'mayor'], [...$this->producto('Kiwi'), $this->quitarPermiso('ListaSeleccion.php', 'imprimirMayor')], [':p1']);

        self::assertStringNotContainsString("onclick=\"imprimirEtiquetas('ListaEtiquetas')\"", $etiquetas['html']);
        self::assertStringNotContainsString('verMayorSeleccionados()"', $mayor['html']);
        self::assertStringContainsString('<td>Kiwi</td>', $etiquetas['html']);
        self::assertStringContainsString('<td>Kiwi</td>', $mayor['html']);
    }

    /** @comportamiento El nombre y el tipo del producto se escriben en la lista como texto. */
    public function test_laListaEscribeElNombreComoTexto(): void
    {
        $pantalla = $this->pintar('ListaSeleccion.php', [], $this->producto(self::SCRIPT), [':p1']);

        self::assertStringNotContainsString(self::SCRIPT, $pantalla['html']);
        self::assertStringContainsString('<td>' . htmlspecialchars(self::SCRIPT) . '</td>', $pantalla['html']);
    }

    /** @comportamiento Sin selección, la lista y la exportación no pintan nada: mandan al listado. */
    public function test_sinSeleccionNoSePintaNada(): void
    {
        $lista = $this->pintar('ListaSeleccion.php');
        $exportacion = $this->pintar('ExportarCsvProductos.php');

        self::assertSame('', $lista['html']);
        self::assertSame('', $exportacion['html']);
        self::assertSame('', $lista['errores'] . $exportacion['errores']);
    }

    /**
     * @comportamiento La exportación da los seleccionados en un CSV para la hoja de cálculo: la marca de
     *   UTF-8, una cabecera y una línea por producto con su número, su nombre, sus códigos de barras y sus
     *   familias separados por barras, el coste, el beneficio, el precio sin IVA, el IVA, el precio con IVA,
     *   la existencia y el estado, separados por punto y coma.
     */
    public function test_laExportacionDaLosSeleccionadosEnUnCsv(): void
    {
        $exportacion = $this->pintar('ExportarCsvProductos.php', [], [
            ...$this->producto('Kiwi'),
            $this->existencias(5),
            "INSERT INTO articulosCodigoBarras (idArticulo, codBarras) VALUES (:p1, '8400000000011'), (:p1, '8400000000028')",
            "INSERT INTO familias (familiaNombre, familiaPadre, beneficiomedio, mostrar_tpv) VALUES ('Fruta', 0, 0, 1)",
            'INSERT INTO articulosFamilias (idArticulo, idFamilia) VALUES (:p1, LAST_INSERT_ID())',
        ], [':p1']);
        $lineas = explode("\n", $exportacion['html']);

        self::assertStringStartsWith(self::BOM . 'ID;Nombre;"Código Barras";Familias;"Último Coste";"Beneficio %";"P.V.P. sin IVA";"IVA %";"P.V.P. con IVA";Stock;Estado', $lineas[0]);
        self::assertSame($exportacion['ids'][':p1'] . ';Kiwi;"8400000000011 | 8400000000028";Fruta;1.00;0.00;1.00;21.00;1.21;5.00;Activo', $lineas[1]);
        self::assertCount(2, $lineas);
        self::assertSame('', $exportacion['errores']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/ListaSeleccion.php:21-29
     * @codigo-afectado modulos/mod_productos/ExportarCsvProductos.php:41-67
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Un producto seleccionado que ya no existe sale en la lista como una fila vacía y en
     *   la exportación como una línea sin datos, con avisos de PHP.
     * @que-deberia-ocurrir Que la lista y la exportación solo enseñen los productos que existen.
     * @por-que-ocurre Las dos pantallas pintan lo que devuelve la lectura de cada número de la selección
     *   sin mirar si el producto existe, y la lectura de un producto que no existe devuelve uno vacío.
     * @como-deberia-funcionar Saltar, o quitar de la selección, el número de un producto que no existe.
     */
    public function test_unSeleccionadoQueYaNoExisteNoSePinta(): void
    {
        $preparacion = [...$this->producto('Kiwi'), $this->existencias(5)];
        $lista = $this->pintar('ListaSeleccion.php', [], $preparacion, [':p1', '999999']);
        $exportacion = $this->pintar('ExportarCsvProductos.php', [], $preparacion, [':p1', '999999']);

        self::assertSame(1, substr_count($lista['html'], 'class="checkSelect"'));
        self::assertCount(2, explode("\n", $exportacion['html']));
        self::assertSame('', $lista['errores'] . $exportacion['errores']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/ListaSeleccion.php:12-14
     * @codigo-afectado modulos/mod_productos/clases/ClaseSeleccionProductos.php:22-33
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Con el fichero de la selección dañado, la lista termina con un error de PHP y no se
     *   pinta.
     * @que-deberia-ocurrir Que la lista se pinte y diga que no pudo leer la selección.
     * @por-que-ocurre La lista lee la selección sin recoger el fallo, y la lectura de un fichero que no es
     *   XML bien formado termina con un error de tipo.
     * @como-deberia-funcionar Recoger el fallo de la lectura y comunicarlo en la pantalla.
     */
    public function test_unaSeleccionDanadaNoTerminaLaLista(): void
    {
        $pantalla = $this->pintar('ListaSeleccion.php', [], $this->producto('Kiwi'), [], '<seleccion><item idArticulo="1">');

        self::assertStringNotContainsString('Fatal error', $pantalla['errores']);
        self::assertStringContainsString('</html>', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/ExportarCsvProductos.php:55-67
     *
     * @group defecto
     * @group salida
     * @group medio
     *
     * @que-ocurre-hoy Un nombre que empieza por un signo igual se exporta tal cual: la hoja de cálculo lo
     *   toma como una fórmula al abrir el fichero.
     * @que-deberia-ocurrir Que cada valor se exporte como dato.
     * @por-que-ocurre La exportación escribe cada valor sin mirar si empieza por un carácter que la hoja
     *   de cálculo lee como el comienzo de una fórmula.
     * @como-deberia-funcionar Anteponer un apóstrofo, o neutralizar de otro modo, el valor que empieza por
     *   igual, más, menos o arroba.
     */
    public function test_laExportacionEscribeUnaFormulaComoDato(): void
    {
        $exportacion = $this->pintar('ExportarCsvProductos.php', [], $this->producto('=1+2'), [':p1']);

        self::assertStringNotContainsString(';=1+2;', $exportacion['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/ExportarCsvProductos.php:1-21
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Sin el permiso de exportar la selección, la exportación pedida por su dirección da el
     *   fichero igual: el permiso solo esconde el enlace del listado.
     * @que-deberia-ocurrir Que sin el permiso no se exporte nada.
     * @por-que-ocurre La exportación no consulta ningún permiso; el de exportar lo consulta el listado para
     *   enseñar el enlace.
     * @como-deberia-funcionar Consultar el permiso de exportar antes de escribir el fichero.
     */
    public function test_sinElPermisoDeExportarNoSeExporta(): void
    {
        $exportacion = $this->pintar('ExportarCsvProductos.php', [], [...$this->producto('Kiwi'), $this->quitarPermiso('ListaProductos.php', 'exportarCsv')], [':p1']);

        self::assertStringNotContainsString(';Kiwi;', $exportacion['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/ExportarCsvProductos.php:1-7
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Sin sesión, la exportación da los productos que tenga seleccionados el invitado, con
     *   su coste y su beneficio.
     * @que-deberia-ocurrir Que sin sesión no se exporte nada.
     * @por-que-ocurre La exportación carga el arranque común sin la cabecera, que es la que pide la sesión,
     *   y lee la selección del invitado.
     * @como-deberia-funcionar Comprobar la sesión antes de leer la selección.
     */
    public function test_sinSesionNoSeExporta(): void
    {
        $exportacion = $this->pintar('ExportarCsvProductos.php', [], $this->producto('Kiwi'), [':p1'], null, false);

        self::assertStringNotContainsString(';Kiwi;', $exportacion['html']);
    }

    /**
     * @comportamiento Un seleccionado que ya no existe, leído antes que otro, no cambia la fila del
     *   siguiente: su nombre, su precio, su coste y su existencia son los suyos.
     */
    public function test_unSeleccionadoQueNoExisteNoCambiaLaFilaDelSiguiente(): void
    {
        $pantalla = $this->pintar('ListaSeleccion.php', [], [...$this->producto('Kiwi'), $this->existencias(5)], ['999999', ':p1']);

        self::assertStringContainsString('<td>Kiwi</td>', $pantalla['html']);
        self::assertStringContainsString('<td>1.21€</td>', $pantalla['html']);
        self::assertStringContainsString('<td>1.00€</td>', $pantalla['html']);
        self::assertMatchesRegularExpression('/<td>\s*5\s*<\/td>/', $pantalla['html']);
    }

    /** @comportamiento Sin sesión, la lista pide el acceso y no enseña los productos que tenga seleccionados el invitado. */
    public function test_sinSesionLaListaNoEnsenaLaSeleccion(): void
    {
        $pantalla = $this->pintar('ListaSeleccion.php', [], $this->producto('Kiwi'), [':p1'], null, false);

        self::assertStringNotContainsString('<td>Kiwi</td>', $pantalla['html']);
        self::assertStringNotContainsString('class="checkSelect"', $pantalla['html']);
    }

    /**
     * @comportamiento Un seleccionado sin códigos de barras ni familias, exportado tras otro que los
     *   tiene, sale con esas columnas vacías: no toma los del anterior.
     */
    public function test_unSeleccionadoSinCodigosNiFamiliasNoTomaLosDelAnterior(): void
    {
        $exportacion = $this->pintar('ExportarCsvProductos.php', [], [
            ...$this->producto('Kiwi'),
            "INSERT INTO articulosCodigoBarras (idArticulo, codBarras) VALUES (:p1, '8400000000011')",
            "INSERT INTO familias (familiaNombre, familiaPadre, beneficiomedio, mostrar_tpv) VALUES ('Fruta', 0, 0, 1)",
            'INSERT INTO articulosFamilias (idArticulo, idFamilia) VALUES (:p1, LAST_INSERT_ID())',
            ...$this->producto('Pera'),
        ], [':p1', ':p2']);

        self::assertStringContainsString("\n" . $exportacion['ids'][':p2'] . ';Pera;;;1.00;', $exportacion['html']);
    }

    /** @comportamiento Un nombre con el separador o con comillas sale entre comillas, sin partir la línea en más columnas. */
    public function test_unNombreConElSeparadorSaleEntreComillas(): void
    {
        $exportacion = $this->pintar('ExportarCsvProductos.php', [], $this->producto('Kiwi; verde'), [':p1']);

        self::assertStringContainsString(';"Kiwi; verde";', $exportacion['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/ExportarCsvProductos.php:6-7
     * @codigo-afectado modulos/mod_productos/clases/ClaseSeleccionProductos.php:22-33
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Con el fichero de la selección dañado, la exportación termina con un error de PHP y
     *   no da ningún fichero.
     * @que-deberia-ocurrir Que la exportación diga que no pudo leer la selección.
     * @por-que-ocurre La exportación lee la selección sin recoger el fallo, y la lectura de un fichero que
     *   no es XML bien formado termina con un error de tipo.
     * @como-deberia-funcionar Recoger el fallo de la lectura y comunicarlo.
     */
    public function test_unaSeleccionDanadaNoTerminaLaExportacion(): void
    {
        $exportacion = $this->pintar('ExportarCsvProductos.php', [], $this->producto('Kiwi'), [], '<seleccion><item idArticulo="1">');

        self::assertStringNotContainsString('Fatal error', $exportacion['errores']);
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

    private function quitarPermiso(string $vista, string $accion): string
    {
        return "UPDATE permisos SET permiso = 0 WHERE idUsuario = :usuario AND modulo = 'mod_productos' AND vista = '{$vista}' AND accion = '{$accion}'";
    }

    private function ficheroDeSeleccion(int $idUsuario): string
    {
        return RUTA_TPVFOX . '/modulos/mod_productos/cache/' . $idUsuario . '_seleccion.xml';
    }

    /**
     * Pinta una pantalla de `mod_productos` en un PHP aparte. Tras sembrar la preparación, vacía el
     * fichero de selección de quien la pide —el usuario de pruebas o, sin sesión, el invitado— y guarda en
     * él los productos indicados por su nombre, o el contenido dañado que dé el caso.
     *
     * @param array<string, string> $consulta
     * @param list<string> $preparacion
     * @param list<string> $seleccion
     * @return array{html: string, errores: string, ids: array<string, string>}
     */
    private function pintar(string $fichero, array $consulta = [], array $preparacion = [], array $seleccion = [], ?string $danada = null, bool $conSesion = true): array
    {
        $sesion = $conSesion ? [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => $this->idUsuario, 'group_id' => 1, 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => $this->idTienda, 'razonsocial' => 'Tienda de pruebas'],
        ] : [];
        $cache = RUTA_TPVFOX . '/modulos/mod_productos/cache';
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["DOCUMENT_ROOT"] = ""; $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = ' . var_export("/TPVFox/modulos/mod_productos/{$fichero}", true) . ';'
            . ' $_GET = ' . var_export($consulta, true) . ';'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_productos', true) . ');'
            . ' require "./../../inicial.php";'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ($conSesion ? ' new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' $BDTpv->query("UPDATE permisos SET permiso = 1 WHERE idUsuario = ' . $this->idUsuario . ' AND modulo = \'mod_productos\'");'
            . ' $sembrados = [":usuario" => "' . $this->idUsuario . '", ":tienda" => "' . $this->idTienda . '"]; $productos = 0;'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->query(strtr($sql, $sembrados));'
            . ' if (str_starts_with($sql, "INSERT INTO articulos ")) { $sembrados[":p" . ++$productos] = $sembrados[":pN"] = (string) $BDTpv->insert_id; } }'
            . ' require_once $URLCom . "/modulos/mod_productos/clases/ClaseSeleccionProductos.php";'
            . ' $marcados = new ClaseSeleccionProductos((int) $Usuario["id"], ' . var_export($cache, true) . '); $marcados->limpiar();'
            . ' foreach (' . var_export($seleccion, true) . ' as $marcado) { $marcados->agregar((int) strtr($marcado, $sembrados)); }'
            . ($danada !== null ? ' file_put_contents($marcados->getRutaArchivo(), ' . var_export($danada, true) . ');' : '')
            . ($conSesion ? ' $ClasePermisos = $thisTpv->permisos = new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' register_shutdown_function(function () use (&$sembrados) { echo "\n@@IDS@@" . json_encode($sembrados); });'
            . ' include ' . var_export($fichero, true) . ';';

        $errores = (string) tempnam(sys_get_temp_dir(), 'seleccion');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$html, $ids] = array_pad(explode('@@IDS@@', implode("\n", $salida), 2), 2, 'null');
        $pintada = ['html' => trim($html), 'errores' => (string) file_get_contents($errores), 'ids' => json_decode($ids, true) ?? []];
        unlink($errores);

        return $pintada;
    }
}
