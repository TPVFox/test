<?php

/**
 * El listado de productos de `mod_productos` (`ListaProductos.php`, con `template/view_lista.php`), el
 * que enlaza el menú: los productos con las columnas de la configuración del usuario, filtrados por
 * estado, familia, búsqueda y selección, paginados, con sus acciones según los permisos.
 *
 * Cada listado se pinta en un PHP aparte, desde la carpeta del módulo, con la sesión del usuario de
 * pruebas o sin ninguna, los permisos del módulo concedidos salvo los que el caso quite, y una
 * transacción que se deshace al terminar. El caso siembra sus productos con un nombre que empieza por
 * `Zqx` y los busca por él, para no mezclarlos con los de la base; la configuración del usuario, si la
 * da el caso, se guarda en la misma transacción. El fichero de selección del usuario se guarda antes de
 * cada caso y se repone al terminar.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModProducto;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class ListadoProductosIntegracionTest extends CasoIntegracion
{
    private const SCRIPT = '<script>x=1</script>';

    private const ETIQUETA = '<b>x</b>';

    private int $idUsuario;

    private int $idTienda;

    private ?string $seleccionAntes = null;

    protected function setUp(): void
    {
        parent::setUp();
        $siembra = new Siembra($this->db);
        $this->idUsuario = $siembra->usuarioPorDefecto();
        $this->idTienda = $siembra->tiendaPorDefecto();
        $fichero = $this->ficheroDeSeleccion();
        $this->seleccionAntes = is_file($fichero) ? (string) file_get_contents($fichero) : null;
        if (is_file($fichero)) {
            unlink($fichero);
        }
    }

    protected function tearDown(): void
    {
        $fichero = $this->ficheroDeSeleccion();
        if ($this->seleccionAntes === null) {
            if (is_file($fichero)) {
                unlink($fichero);
            }
        } else {
            file_put_contents($fichero, $this->seleccionAntes);
        }
        parent::tearDown();
    }

    /**
     * @comportamiento El listado enseña cada producto buscado con su número, su nombre, sus familias, sus
     *   códigos de barras, su referencia en la tienda, su coste, su beneficio, sus precios, su IVA, su
     *   existencia y su estado, con el enlace a su ficha y a su mayor.
     */
    public function test_elListadoEnsenaCadaProductoConSusDatos(): void
    {
        $pantalla = $this->pintar(['buscar' => 'Zqx'], [
            ...$this->producto('Zqx Kiwi'), $this->codigo('8400000000017'), $this->referencia('KIW-1'),
            ...$this->familia('Frutas'),
        ]);

        self::assertSame(['Zqx Kiwi'], $this->nombres($pantalla['html']));
        self::assertStringContainsString("UnProductoClick('" . $pantalla['ids'][':p1'] . "')", $pantalla['html']);
        self::assertStringContainsString('<sub>Frutas</sub>', $pantalla['html']);
        self::assertStringContainsString('<small>8400000000017</small>', $pantalla['html']);
        self::assertStringContainsString('KIW-1', $pantalla['html']);
        self::assertStringContainsString('<td class="text-right">2.42 <small>€</small></td>', $pantalla['html']);
        self::assertStringContainsString('DetalleMayor.php?idArticulo=' . $pantalla['ids'][':p1'], $pantalla['html']);
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

    /** @comportamiento Con una familia en la dirección, solo salen sus productos. */
    public function test_elFiltroDeFamiliaEnsenaSusProductos(): void
    {
        $pantalla = $this->pintar(['buscar' => 'Zqx', 'familia' => ':familia'], [
            ...$this->producto('Zqx Kiwi'), ...$this->familia('Frutas'), ...$this->producto('Zqx Pan'),
        ]);

        self::assertSame(['Zqx Kiwi'], $this->nombres($pantalla['html']));
    }

    /** @comportamiento Con el filtro de la selección activo, solo salen los productos seleccionados. */
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
        $pantalla = $this->pintar(['buscar' => 'Zqx'], [...$this->producto('Zqx Kiwi'), $this->quitarPermiso('crear')]);

        self::assertStringNotContainsString("metodoClick('AgregarProducto')", $pantalla['html']);
        self::assertStringContainsString("metodoClick('VerProducto', 'producto')", $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/template/view_lista.php:171
     * @codigo-afectado modulos/mod_productos/ListaProductos.php:68
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Elegir «Sin familia» en el filtro de familias enseña todos los productos.
     * @que-deberia-ocurrir Que enseñe solo los productos sin familia.
     * @por-que-ocurre La opción «Sin familia» lleva el valor cero, que el listado toma como sin filtro; el
     *   listado busca los productos sin familia con un valor negativo.
     * @como-deberia-funcionar Dar a la opción el valor que el listado toma como sin familia.
     */
    public function test_elegirSinFamiliaEnsenaLosProductosSinFamilia(): void
    {
        $preparacion = [...$this->producto('Zqx Kiwi'), ...$this->familia('Frutas'), ...$this->producto('Zqx Pan')];
        preg_match('#<option value="([^"]*)">Sin familia</option>#', $this->pintar(['buscar' => 'Zqx'], $preparacion)['html'], $opcion);
        $pantalla = $this->pintar(['buscar' => 'Zqx', 'familia' => $opcion[1] ?? ''], $preparacion);

        self::assertSame(['Zqx Pan'], $this->nombres($pantalla['html']));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/ListaProductos.php:62
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
     * @codigo-afectado modulos/mod_productos/template/view_lista.php:258
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy El listado escribe tal cual los códigos de barras: uno con una etiqueta la pinta.
     * @que-deberia-ocurrir Que los escriba como texto.
     * @por-que-ocurre La columna pinta cada código sin escaparlo.
     * @como-deberia-funcionar Escapar cada código al pintarlo.
     */
    public function test_losCodigosDeBarrasSeEscribenComoTexto(): void
    {
        $pantalla = $this->pintar(['buscar' => 'Zqx'], [...$this->producto('Zqx Kiwi'), $this->codigo(self::ETIQUETA)]);

        self::assertStringNotContainsString(self::ETIQUETA, $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/template/view_lista.php:267
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy El listado escribe tal cual la referencia del producto en la tienda.
     * @que-deberia-ocurrir Que la escriba como texto.
     * @por-que-ocurre La columna pinta la referencia sin escaparla.
     * @como-deberia-funcionar Escapar la referencia al pintarla.
     */
    public function test_laReferenciaDeLaTiendaSeEscribeComoTexto(): void
    {
        $pantalla = $this->pintar(['buscar' => 'Zqx'], [...$this->producto('Zqx Kiwi'), $this->referencia(self::ETIQUETA)]);

        self::assertStringNotContainsString(self::ETIQUETA, $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/template/view_lista.php:173
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy El filtro de familias escribe tal cual el nombre de cada familia: uno con un script lo
     *   ejecuta al abrir el listado.
     * @que-deberia-ocurrir Que escriba los nombres como texto.
     * @por-que-ocurre Las opciones del filtro pintan el nombre sin escaparlo.
     * @como-deberia-funcionar Escapar el nombre al pintar cada opción.
     */
    public function test_losNombresDeLasFamiliasDelFiltroSeEscribenComoTexto(): void
    {
        $pantalla = $this->pintar(['buscar' => 'Zqx'], [...$this->producto('Zqx Kiwi'), ...$this->familia(self::SCRIPT)]);

        self::assertStringNotContainsString(self::SCRIPT, $pantalla['html']);
    }

    /** @comportamiento Sin sesión, la cabecera pide el acceso y no se pinta el listado. */
    public function test_sinSesionPideElAccesoSinPintarElListado(): void
    {
        $pantalla = $this->pintar(['buscar' => 'Zqx'], [...$this->producto('Zqx Kiwi')], [], false);

        self::assertSame([], $this->nombres($pantalla['html']));
        self::assertStringContainsString('name="usr"', $pantalla['html']);
        self::assertSame('', $pantalla['errores']);
    }

    /** @return list<string> El nombre de cada producto del listado, en orden. */
    private function nombres(string $html): array
    {
        preg_match_all('#onclick="UnProductoClick\(\'\d+\'\)">(Zqx[^<]*)#', $html, $m);

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
        return "UPDATE permisos SET permiso = 0 WHERE idUsuario = :usuario AND modulo = 'mod_productos' AND vista = 'ListaProductos.php' AND accion = '{$accion}'";
    }

    private function ficheroDeSeleccion(): string
    {
        return RUTA_TPVFOX . '/modulos/mod_productos/cache/' . $this->idUsuario . '_seleccion.xml';
    }

    /**
     * Pinta el listado de `mod_productos` en un PHP aparte. Tras sembrar la preparación, vacía el fichero
     * de selección del usuario y guarda en él los productos indicados.
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
        $cache = RUTA_TPVFOX . '/modulos/mod_productos/cache';
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["DOCUMENT_ROOT"] = ""; $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = "/TPVFox/modulos/mod_productos/ListaProductos.php";'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_productos', true) . ');'
            . ' require "./../../inicial.php";'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ' $BDTpv->query("UPDATE permisos SET permiso = 1 WHERE idUsuario = ' . $this->idUsuario . ' AND modulo = \'mod_productos\'");'
            . ' $sembrados = [":usuario" => "' . $this->idUsuario . '", ":tienda" => "' . $this->idTienda . '"]; $productos = 0;'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->query(strtr($sql, $sembrados));'
            . ' if (str_starts_with($sql, "INSERT INTO articulos ")) { $sembrados[":p" . ++$productos] = $sembrados[":pN"] = (string) $BDTpv->insert_id; }'
            . ' elseif (str_starts_with($sql, "INSERT INTO familias ")) { $sembrados[":familia"] = (string) $BDTpv->insert_id; } }'
            . ' require_once $URLCom . "/modulos/mod_productos/clases/ClaseSeleccionProductos.php";'
            . ' $marcados = new ClaseSeleccionProductos(' . $this->idUsuario . ', ' . var_export($cache, true) . '); $marcados->limpiar();'
            . ' foreach (' . var_export($seleccion, true) . ' as $marcado) { $marcados->agregar((int) strtr($marcado, $sembrados)); }'
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
