<?php

/**
 * El listado de productos de `mod_producto` (`ListaProductos.php`), el que enlaza la portada del
 * programa: los productos con las columnas de la configuración del usuario, filtrados por estado,
 * búsqueda y selección, paginados, con sus acciones según los permisos.
 *
 * Cada listado se pinta en un PHP aparte, desde la carpeta del módulo, con la sesión del usuario de
 * pruebas —y los productos seleccionados en ella, si el caso los da— o sin ninguna, los permisos del
 * módulo concedidos salvo los que el caso quite, y una transacción que se deshace al terminar. El caso
 * siembra sus productos con un nombre que empieza por `Zqx` y los busca por él, para no mezclarlos con
 * los de la base; la configuración del usuario, si la da el caso, se guarda en la misma transacción.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModProducto;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class ListadoProductoIntegracionTest extends CasoIntegracion
{
    private const SCRIPT = '<script>x=1</script>';

    private const ETIQUETA = '<b>x</b>';

    private int $idUsuario;

    private int $idTienda;

    protected function setUp(): void
    {
        parent::setUp();
        $siembra = new Siembra($this->db);
        $this->idUsuario = $siembra->usuarioPorDefecto();
        $this->idTienda = $siembra->tiendaPorDefecto();
    }

    /**
     * @comportamiento El listado enseña cada producto buscado con su número, su nombre, sus familias, sus
     *   códigos de barras, su referencia en la tienda, su coste, sus precios y su existencia, con el enlace
     *   a su ficha.
     */
    public function test_elListadoEnsenaCadaProductoConSusDatos(): void
    {
        $pantalla = $this->pintar(['buscar' => 'Zqx'], [
            ...$this->producto('Zqx Kiwi'), $this->codigo('8400000000017'), $this->referencia('KIW-1'), ...$this->familia('Frutas'),
        ]);

        self::assertSame(['Zqx Kiwi'], $this->nombres($pantalla['html']));
        self::assertStringContainsString("UnProductoClick('" . $pantalla['ids'][':p1'] . "');", $pantalla['html']);
        self::assertStringContainsString('<SUB> Frutas</SUB>', $pantalla['html']);
        self::assertStringContainsString('<small>8400000000017</small>', $pantalla['html']);
        self::assertStringContainsString('KIW-1', $pantalla['html']);
        self::assertStringContainsString('2.42<small>€</small>', $pantalla['html']);
        self::assertSame('', $pantalla['errores']);
    }

    /** @comportamiento Con un filtro de estado en la configuración, solo salen los productos de ese estado. */
    public function test_elFiltroDeEstadoEnsenaSoloEsosProductos(): void
    {
        $pantalla = $this->pintar(['buscar' => 'Zqx'], [
            ...$this->producto('Zqx Kiwi'), ...$this->producto('Zqx Pera', 'Baja'), $this->configuracion(['estado_filtro' => 'Baja']),
        ]);

        self::assertSame(['Zqx Pera'], $this->nombres($pantalla['html']));
    }

    /** @comportamiento Con el filtro de la selección activo, solo salen los productos seleccionados en la sesión. */
    public function test_elFiltroDeLaSeleccionEnsenaSoloLosSeleccionados(): void
    {
        $pantalla = $this->pintar(['buscar' => 'Zqx'], [
            ...$this->producto('Zqx Kiwi'), ...$this->producto('Zqx Pera'), $this->configuracion(['filtro' => ['valor' => 'Si']]),
        ], [':p2']);

        self::assertSame(['Zqx Pera'], $this->nombres($pantalla['html']));
    }

    /** @comportamiento Las acciones del listado salen según los permisos del usuario. */
    public function test_lasAccionesSalenSegunLosPermisos(): void
    {
        $pantalla = $this->pintar(['buscar' => 'Zqx'], [...$this->producto('Zqx Kiwi'), $this->quitarPermiso('modificar')]);

        self::assertStringNotContainsString("metodoClick('VerProducto', 'producto')", $pantalla['html']);
    }

    /** @comportamiento Sin sesión, la cabecera pide el acceso y no se pinta el listado. */
    public function test_sinSesionPideElAccesoSinPintarElListado(): void
    {
        $pantalla = $this->pintar(['buscar' => 'Zqx'], [...$this->producto('Zqx Kiwi')], [], false);

        self::assertSame([], $this->nombres($pantalla['html']));
        self::assertStringContainsString('name="usr"', $pantalla['html']);
        self::assertSame('', $pantalla['errores']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/ListaProductos.php:76
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Un filtro de estado guardado con una condición añadida enseña los productos de todos
     *   los estados.
     * @que-deberia-ocurrir Que el estado se compare como dato.
     * @por-que-ocurre El estado de la configuración del usuario se pega a la consulta.
     * @como-deberia-funcionar Comparar el estado con los posibles o pasarlo como dato.
     */
    public function test_elFiltroDeEstadoGuardadoSeTomaComoDato(): void
    {
        $pantalla = $this->pintar(['buscar' => 'Zqx'], [
            ...$this->producto('Zqx Kiwi'), ...$this->producto('Zqx Pera', 'Baja'), $this->configuracion(['estado_filtro' => 'Baja" OR "1"="1']),
        ]);

        self::assertSame([], $this->nombres($pantalla['html']));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/ListaProductos.php:423
     * @codigo-afectado modulos/mod_producto/ListaProductos.php:428
     * @codigo-afectado modulos/mod_producto/ListaProductos.php:438
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy El listado escribe tal cual el nombre, las familias, los códigos de barras y la
     *   referencia de cada producto: un nombre con un script lo ejecuta al abrir el listado.
     * @que-deberia-ocurrir Que los escriba como texto.
     * @por-que-ocurre La fila pinta los datos del producto sin escaparlos.
     * @como-deberia-funcionar Escapar cada dato al pintarlo.
     */
    public function test_losDatosDeCadaProductoSeEscribenComoTexto(): void
    {
        $pantalla = $this->pintar(['buscar' => 'Zqx'], [
            ...$this->producto('Zqx ' . self::SCRIPT), $this->codigo(self::ETIQUETA), $this->referencia(self::ETIQUETA),
        ]);

        self::assertStringNotContainsString(self::SCRIPT, $pantalla['html']);
        self::assertStringNotContainsString(self::ETIQUETA, $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/ListaProductos.php:304
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy El texto buscado vuelve a la caja de búsqueda tal cual: un enlace con un script en la
     *   búsqueda lo ejecuta al abrirlo.
     * @que-deberia-ocurrir Que el texto buscado se escriba como texto.
     * @por-que-ocurre La caja pinta la búsqueda sin escaparla.
     * @como-deberia-funcionar Escapar la búsqueda al pintarla.
     */
    public function test_laBusquedaSeEscribeComoTexto(): void
    {
        $pantalla = $this->pintar(['buscar' => 'Zqx"><b>x</b>'], [...$this->producto('Zqx Kiwi')]);

        self::assertStringNotContainsString('"><b>x</b>', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/ListaProductos.php:316
     * @codigo-afectado modulos/mod_producto/ListaProductos.php:330
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy Los filtros de familias y de proveedores escriben tal cual sus nombres: uno con un
     *   script lo ejecuta al abrir el listado.
     * @que-deberia-ocurrir Que escriban los nombres como texto.
     * @por-que-ocurre Las opciones de los dos filtros pintan el nombre sin escaparlo.
     * @como-deberia-funcionar Escapar el nombre al pintar cada opción.
     */
    public function test_losNombresDeLosFiltrosSeEscribenComoTexto(): void
    {
        $pantalla = $this->pintar(['buscar' => 'Zqx'], [
            ...$this->producto('Zqx Kiwi'), ...$this->familia(self::SCRIPT),
            "INSERT INTO proveedores (nombrecomercial, razonsocial, nif, direccion, fecha_creado, estado) VALUES ('<i>p</i>', 'Prov', 'B00000000', 'Calle Mayor 1', '2026-01-01', 'activo')",
        ]);

        self::assertStringNotContainsString(self::SCRIPT, $pantalla['html']);
        self::assertStringNotContainsString('<i>p</i>', $pantalla['html']);
    }

    /** @comportamiento La existencia de un producto al peso sale con tres decimales. */
    public function test_unProductoAlPesoEnsenaSuExistenciaConTresDecimales(): void
    {
        $alPeso = array_map(static fn ($sql) => str_replace("'unidad'", "'peso'", $sql), $this->producto('Zqx Kiwi'));
        $pantalla = $this->pintar(['buscar' => 'Zqx'], $alPeso);

        self::assertStringContainsString('5.000', $pantalla['html']);
    }

    /**
     * @comportamiento Sin buscar, el listado enseña la primera página de todos los productos de la tienda,
     *   por su nombre. El producto del caso empieza por signos para ser el primero.
     */
    public function test_sinBuscarElListadoEnsenaLaPrimeraPaginaDeTodos(): void
    {
        $pantalla = $this->pintar([], $this->producto('!!!Zqx Kiwi'));

        self::assertStringContainsString("UnProductoClick('" . $pantalla['ids'][':p1'] . "');", $pantalla['html']);
    }

    /** @comportamiento Sin buscar, el filtro de estado de la configuración se aplica igual. */
    public function test_sinBuscarElFiltroDeEstadoSeAplica(): void
    {
        $pantalla = $this->pintar([], [
            ...$this->producto('!!!Zqx Kiwi'), ...$this->producto('!!!Zqx Pera', 'Baja'), $this->configuracion(['estado_filtro' => 'Baja']),
        ]);

        self::assertStringContainsString("UnProductoClick('" . $pantalla['ids'][':p2'] . "');", $pantalla['html']);
        self::assertStringNotContainsString("UnProductoClick('" . $pantalla['ids'][':p1'] . "');", $pantalla['html']);
    }

    /** @comportamiento Sin buscar y con el filtro de la selección activo, solo salen los productos seleccionados. */
    public function test_sinBuscarLaSeleccionEnsenaSoloLosSeleccionados(): void
    {
        $pantalla = $this->pintar([], [
            ...$this->producto('!!!Zqx Kiwi'), ...$this->producto('!!!Zqx Pera'), $this->configuracion(['filtro' => ['valor' => 'Si']]),
        ], [':p2']);

        preg_match_all('#onclick="UnProductoClick\(\'(\d+)\'\);">#', $pantalla['html'], $m);
        self::assertSame([$pantalla['ids'][':p2']], array_values(array_unique($m[1])));
    }

    /** @return list<string> El nombre de cada producto del listado, en orden. */
    private function nombres(string $html): array
    {
        preg_match_all('#onclick="UnProductoClick\(\'\d+\'\);">(Zqx[^<]*)#', $html, $m);

        return array_map('trim', $m[1]);
    }

    /** @return list<string> */
    private function producto(string $nombre, string $estado = 'Activo'): array
    {
        return [
            "INSERT INTO articulos (articulo_name, estado, fecha_creado, ultimoCoste, iva, beneficio, tipo) VALUES ('{$nombre}', '{$estado}', '2026-01-01', 1, 21, 10, 'unidad')",
            'INSERT INTO articulosPrecios (idArticulo, pvpCiva, pvpSiva, idTienda) VALUES (:pN, 2.42, 2, :tienda)',
            'INSERT INTO articulosStocks (idArticulo, idTienda, stockOn, stockMin, stockMax) VALUES (:pN, :tienda, 5, 0, 0)',
        ];
    }

    private function codigo(string $codigo): string
    {
        return "INSERT INTO articulosCodigoBarras (idArticulo, codBarras) VALUES (:pN, '{$codigo}')";
    }

    private function referencia(string $referencia): string
    {
        return "INSERT INTO articulosTiendas (idArticulo, idTienda, crefTienda, idVirtuemart, estado) VALUES (:pN, :tienda, '{$referencia}', 0, 'Activo')";
    }

    /** @return list<string> La familia, con el último producto sembrado en ella. */
    private function familia(string $nombre): array
    {
        return [
            "INSERT INTO familias (familiaNombre, familiaPadre, beneficiomedio, mostrar_tpv) VALUES ('{$nombre}', 0, 0, 0)",
            'INSERT INTO articulosFamilias (idArticulo, idFamilia) VALUES (:pN, :familia)',
        ];
    }

    /** @param array<string, mixed> $valores */
    private function configuracion(array $valores): string
    {
        return "INSERT INTO modulos_configuracion (idusuario, nombre_modulo, configuracion, fecha) VALUES (:usuario, 'mod_productos', '"
            . str_replace("'", "''", (string) json_encode($valores)) . "', NOW())";
    }

    private function quitarPermiso(string $accion): string
    {
        return "UPDATE permisos SET permiso = 0 WHERE idUsuario = :usuario AND modulo = 'mod_producto' AND vista = 'ListaProductos.php' AND accion = '{$accion}'";
    }

    /**
     * Pinta el listado de `mod_producto` en un PHP aparte, con los productos indicados seleccionados en la
     * sesión.
     *
     * @param array<string, string> $consulta
     * @param list<string> $preparacion
     * @param list<string> $seleccion
     * @return array{html: string, errores: string, ids: array<string, string>}
     */
    private function pintar(array $consulta, array $preparacion = [], array $seleccion = [], bool $conSesion = true): array
    {
        $sesion = $conSesion ? [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => $this->idUsuario, 'group_id' => 1, 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => $this->idTienda, 'razonsocial' => 'Tienda de pruebas'],
        ] : [];
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["DOCUMENT_ROOT"] = ""; $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = "/TPVFox/modulos/mod_producto/ListaProductos.php";'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_producto', true) . ');'
            . ' require "./../../inicial.php";'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ' $BDTpv->query("UPDATE permisos SET permiso = 1 WHERE idUsuario = ' . $this->idUsuario . ' AND modulo = \'mod_producto\'");'
            . ' $sembrados = [":usuario" => "' . $this->idUsuario . '", ":tienda" => "' . $this->idTienda . '"]; $productos = 0;'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->query(strtr($sql, $sembrados));'
            . ' if (str_starts_with($sql, "INSERT INTO articulos ")) { $sembrados[":p" . ++$productos] = $sembrados[":pN"] = (string) $BDTpv->insert_id; }'
            . ' elseif (str_starts_with($sql, "INSERT INTO familias ")) { $sembrados[":familia"] = (string) $BDTpv->insert_id; } }'
            . ($seleccion !== [] ? ' $_SESSION["productos_seleccionados"] = array_map(fn ($p) => strtr($p, $sembrados), ' . var_export($seleccion, true) . ');' : '')
            . ' $_GET = array_map(fn ($valor) => strtr((string) $valor, $sembrados), ' . var_export($consulta, true) . ');'
            . ($conSesion ? ' $ClasePermisos = $thisTpv->permisos = new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' register_shutdown_function(function () use (&$sembrados) { echo "\n@@IDS@@" . json_encode($sembrados); });'
            . ' include "ListaProductos.php";';

        $errores = (string) tempnam(sys_get_temp_dir(), 'listado');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$html, $ids] = array_pad(explode('@@IDS@@', implode("\n", $salida), 2), 2, 'null');
        $pintada = ['html' => trim($html), 'errores' => (string) file_get_contents($errores), 'ids' => json_decode($ids, true) ?? []];
        unlink($errores);

        return $pintada;
    }
}
