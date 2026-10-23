<?php

/**
 * La petición de fondo de `mod_producto` (`modulos/mod_producto/tareas.php`): comprobar un producto,
 * una referencia o un código de barras; poner productos en una familia; buscar los productos de una
 * familia o de un proveedor; cambiar el estado de varios productos; preparar y grabar una
 * regularización; quitar un histórico, una referencia de tienda o de balanza o un proveedor; borrar los
 * productos seleccionados; la selección en la sesión; los modales de familias y de estados; la
 * configuración del listado; y el estado de los productos en la tienda web.
 *
 * Cada petición se ejecuta en un PHP aparte, desde la carpeta del módulo, con la sesión del usuario de
 * pruebas o sin ninguna y los permisos del módulo concedidos, salvo el que el caso quite. Tras el
 * arranque común se abre una transacción en su conexión, y las clases escriben por esa misma conexión:
 * lo que siembra el caso y lo que guarda la petición se deshace al terminar el proceso. Antes de
 * terminar, el proceso responde las consultas que el caso le pide.
 *
 * La preparación es una lista de sentencias; cada alta de un producto queda con un nombre —`:p1`,
 * `:p2`…— y cada familia con el suyo —`:f1`…—, que las sentencias siguientes, el formulario, la
 * selección y las consultas pueden usar. Las órdenes que imprimen se prueban con su pantalla.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModProducto;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class TareasProductoIntegracionTest extends CasoIntegracion
{
    private int $idUsuario;

    private int $idTienda;

    protected function setUp(): void
    {
        parent::setUp();
        $siembra = new Siembra($this->db);
        $this->idUsuario = $siembra->usuarioPorDefecto();
        $this->idTienda = $siembra->tiendaPorDefecto();
    }

    /** @comportamiento Comprobar si existe un producto responde verdadero para uno que existe y falso para uno que no. */
    public function test_comprobarSiExisteUnProducto(): void
    {
        $existe = $this->ejecutar(['pulsado' => 'idExiste', 'id' => ':p1'], $this->producto('Kiwi'));
        $noExiste = $this->ejecutar(['pulsado' => 'idExiste', 'id' => '999999']);

        self::assertTrue($existe['respuesta']);
        self::assertFalse($noExiste['respuesta']);
    }

    /** @comportamiento Desde la ficha, poner un producto en una familia devuelve la fila de la familia; si ya estaba, un error. */
    public function test_ponerUnProductoEnUnaFamiliaDesdeLaFicha(): void
    {
        $preparacion = [...$this->producto('Kiwi'), $this->familia('Frutas')];

        $nueva = $this->ejecutar(['pulsado' => 'guardarFamiliaProductos', 'idfamilia' => ':f1', 'idProducto' => ':p1', 'dedonde' => 'producto'], $preparacion);
        $repetida = $this->ejecutar(['pulsado' => 'guardarFamiliaProductos', 'idfamilia' => ':f1', 'idProducto' => ':p1', 'dedonde' => 'producto'],
            [...$preparacion, 'INSERT INTO articulosFamilias (idArticulo, idFamilia) VALUES (:p1, :f1)']);

        self::assertStringContainsString('<td>Frutas</td>', $nueva['respuesta']['html']);
        self::assertSame(1, $repetida['respuesta']['error']);
    }

    /** @comportamiento Desde el listado, la familia se pone a los productos seleccionados que no la tienen, y se cuentan. */
    public function test_ponerLosSeleccionadosEnUnaFamiliaDesdeElListado(): void
    {
        $preparacion = [...$this->producto('Kiwi'), ...$this->producto('Pera'), $this->familia('Frutas'),
            'INSERT INTO articulosFamilias (idArticulo, idFamilia) VALUES (:p2, :f1)'];

        $peticion = $this->ejecutar(['pulsado' => 'guardarFamiliaProductos', 'idfamilia' => ':f1', 'idProducto' => '0', 'dedonde' => 'ListadoProductos'],
            $preparacion, true, [':p1', ':p2'], ['enFamilia' => 'SELECT COUNT(*) FROM articulosFamilias WHERE idFamilia = :f1']);

        self::assertSame(1, $peticion['respuesta']['contadorProductos']);
        self::assertSame([$peticion['ids'][':p2']], array_map('strval', $peticion['respuesta']['productosEnFamilia']));
        self::assertSame('2', $peticion['consultas']['enFamilia']);
    }

    /** @comportamiento Los productos de una familia, o los que no tienen ninguna, se devuelven por su número; también los de un proveedor. */
    public function test_losProductosDeUnaFamiliaYDeUnProveedor(): void
    {
        $preparacion = [...$this->producto('Kiwi'), $this->familia('Frutas'), 'INSERT INTO articulosFamilias (idArticulo, idFamilia) VALUES (:p1, :f1)',
            "INSERT INTO articulosProveedores (idArticulo, idProveedor, crefProveedor, coste, fechaActualizacion, estado) SELECT :p1, MIN(idProveedor), 'K', 1, '2026-01-01', 'Activo' FROM proveedores"];

        $deFamilia = $this->ejecutar(['pulsado' => 'buscarProductosDeFamilia', 'idfamilia' => ':f1'], $preparacion);
        $sinFamilia = $this->ejecutar(['pulsado' => 'buscarProductosDeFamilia', 'idfamilia' => '0'], $preparacion);
        $deProveedor = $this->ejecutar(['pulsado' => 'buscarProductosProveedor', 'idProveedor' => ':proveedor'], $preparacion);

        self::assertSame([$deFamilia['ids'][':p1']], array_map('strval', $deFamilia['respuesta']['Productos']));
        self::assertNotContains($sinFamilia['ids'][':p1'], array_map('strval', $sinFamilia['respuesta']['Productos']));
        self::assertContains($deProveedor['ids'][':p1'], array_map('strval', $deProveedor['respuesta']['Productos']));
    }

    /** @comportamiento El estado de varios productos se cambia de una vez. */
    public function test_seCambiaElEstadoDeVariosProductos(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'cambiarEstadoProductos', 'productos' => ':p1,:p2', 'estado' => 'Baja'],
            [...$this->producto('Kiwi'), ...$this->producto('Pera')], true, [], ['bajas' => "SELECT COUNT(*) FROM articulos WHERE idArticulo IN (:p1, :p2) AND estado = 'Baja'"]);

        self::assertSame('2', $peticion['consultas']['bajas']);
        self::assertSame('Baja', $peticion['respuesta']['estado']);
    }

    /** @comportamiento Una referencia de tienda y un código de barras se comprueban por los productos que los tienen. */
    public function test_seCompruebanUnaReferenciaYUnCodigoDeBarras(): void
    {
        $preparacion = [...$this->producto('Kiwi'), "INSERT INTO articulosCodigoBarras (idArticulo, codBarras) VALUES (:p1, '8419999999116')"];

        $referencia = $this->ejecutar(['pulsado' => 'comprobarReferencia', 'referencia' => 'REF-:p1'], $preparacion);
        $codigo = $this->ejecutar(['pulsado' => 'ComprobarSiExisteCodbarras', 'codBarras' => '8419999999116'], $preparacion);

        self::assertSame([$referencia['ids'][':p1']], array_column($referencia['respuesta'], 'idArticulo'));
        self::assertSame($codigo['ids'][':p1'], $codigo['respuesta']['Items'][0]['idArticulo']);
    }

    /** @comportamiento La regularización se prepara con la existencia del producto y se graba: la existencia pasa a la real y la regularización queda anotada. */
    public function test_laRegularizacionSePreparaYSeGraba(): void
    {
        $preparacion = [...$this->producto('Kiwi'), 'INSERT INTO articulosStocks (idArticulo, idTienda, stockOn, stockMin, stockMax) VALUES (:p1, :tienda, 5, 0, 0)'];

        $modal = $this->ejecutar(['pulsado' => 'datosRegularizar', 'idarticulo' => ':p1'], $preparacion);
        $graba = $this->ejecutar(['pulsado' => 'grabarRegularizacion', 'idarticulo' => ':p1', 'stockReal' => '8'], $preparacion, true, [], [
            'stock' => 'SELECT stockOn FROM articulosStocks WHERE idArticulo = :p1',
            'regularizacion' => 'SELECT CONCAT(stockActual, "/", stockModif, "/", stockFinal) FROM stocksRegularizacion WHERE idArticulo = :p1',
        ]);

        self::assertStringContainsString('id="stockactual" value="5.00"', $modal['respuesta']['html']);
        self::assertSame(8.0, (float) $graba['consultas']['stock']);
        self::assertSame('5.000000/3.000000/8.000000', $graba['consultas']['regularizacion']);
    }

    /** @comportamiento Se quitan una línea del histórico, una referencia de tienda y un proveedor del producto. */
    public function test_seQuitanHistoricoReferenciaYProveedor(): void
    {
        $preparacion = [...$this->producto('Kiwi'),
            "INSERT INTO historico_precios (idArticulo, Antes, Nuevo, Fecha_Creacion, NumDoc, Dedonde, Tipo, idUsuario, estado) VALUES (:p1, 1, 2, NOW(), 0, 'producto', 'Productos', :usuario, 'A mano')",
            "INSERT INTO articulosProveedores (idArticulo, idProveedor, crefProveedor, coste, fechaActualizacion, estado) SELECT :p1, MIN(idProveedor), 'K', 1, '2026-01-01', 'Activo' FROM proveedores"];
        $consultas = ['historico' => 'SELECT COUNT(*) FROM historico_precios WHERE idArticulo = :p1', 'tiendas' => 'SELECT COUNT(*) FROM articulosTiendas WHERE idArticulo = :p1',
            'proveedores' => 'SELECT COUNT(*) FROM articulosProveedores WHERE idArticulo = :p1'];

        $historico = $this->ejecutar(['pulsado' => 'eliminarHistorico', 'idHistorico' => ':historico'], $preparacion, true, [], $consultas);
        $tienda = $this->ejecutar(['pulsado' => 'eliminarReferenciaTienda', 'idCruce' => ':cruce'], $preparacion, true, [], $consultas);
        $proveedor = $this->ejecutar(['pulsado' => 'eliminarRefProveedor', 'idArticulo' => ':p1', 'idProveedor' => ':proveedor'], $preparacion, true, [], $consultas);
        $balanza = $this->ejecutar(['pulsado' => 'eliminarReferenciaBalanza', 'id' => '999999']);

        self::assertSame('0', $historico['consultas']['historico']);
        self::assertSame('0', $tienda['consultas']['tiendas']);
        self::assertSame('0', $proveedor['consultas']['proveedores']);
        self::assertSame(0, $balanza['respuesta']['NAfectados']);
    }

    /** @comportamiento Borrar los seleccionados quita los de baja sin uso, deja los demás con su motivo y los saca de la selección. */
    public function test_borrarLosSeleccionados(): void
    {
        $preparacion = [...$this->producto('Kiwi', 'Baja'), ...$this->producto('Pera')];

        $peticion = $this->ejecutar(['pulsado' => 'eliminarProductos', 'idTiendaWeb' => '999999'], $preparacion, true, [':p1', ':p2'],
            ['quedan' => 'SELECT COUNT(*) FROM articulos WHERE idArticulo IN (:p1, :p2)']);

        self::assertSame(['Kiwi'], array_column($peticion['respuesta']['Eliminados'], 'nombre'));
        self::assertSame('Revisa estado producto, tiene que estar: baja', $peticion['respuesta']['NoEliminados'][0]['comprobaciones'][0]['mensaje']);
        self::assertSame('1', $peticion['consultas']['quedan']);
        self::assertStringContainsString('<td>Kiwi</td>', $peticion['respuesta']['html']);
    }

    /** @comportamiento La selección de la sesión se marca y se desmarca producto a producto, y se vacía entera. */
    public function test_laSeleccionDeLaSesion(): void
    {
        $marca = $this->ejecutar(['pulsado' => 'productosSesion', 'id' => '7', 'seleccionar' => 'seleccionar']);
        $vacia = $this->ejecutar(['pulsado' => 'eliminarSeleccion'], [], true, ['7']);

        self::assertSame(['7'], $marca['respuesta']['productos_seleccionados']);
        self::assertSame(' Se deselecciono ', $vacia['respuesta']['deseleccionado']);
    }

    /** @comportamiento Los modales de familias y de estados, y la línea de un código de barras nuevo, se piden a la petición. */
    public function test_losModalesYLaLineaDeCodigo(): void
    {
        $familias = $this->ejecutar(['pulsado' => 'modalFamiliaProducto', 'idProducto' => '', 'dedonde' => 'ListadoProductos'], [$this->familia('Frutas')]);
        $estados = $this->ejecutar(['pulsado' => 'modalEstadoProductos'], [], true, ['4', '5']);
        $linea = $this->ejecutar(['pulsado' => 'HtmlLineaCodigoBarras', 'fila' => '3']);

        self::assertStringContainsString('>Frutas</option>', $familias['respuesta']['html']);
        self::assertStringContainsString('value="4,5" id="idProductosModal"', $estados['respuesta']['html']);
        self::assertStringContainsString('id="codBarras_3"', $linea['respuesta']['html']);
    }

    /** @comportamiento Los costes de un proveedor nuevo para el producto y el buscador de proveedores se piden a la petición. */
    public function test_losCostesDeUnProveedorYElBuscador(): void
    {
        $costes = $this->ejecutar(['pulsado' => 'obtenerCostesProveedor', 'idProveedor' => ':proveedor', 'idProducto' => ':p1'], $this->producto('Kiwi'));
        $buscador = $this->ejecutar(['pulsado' => 'HtmlCajaBuscarProveedor', 'busqueda' => '', 'dedonde' => 'producto', 'idcaja' => 'cajaBusquedaproveedor']);

        self::assertStringContainsString('id="idProveedor_' . $costes['ids'][':proveedor'] . '"', $costes['respuesta']['htmlFilaProveedor'] ?? json_encode($costes['respuesta']));
        self::assertStringContainsString('cajaBusquedaproveedor', json_encode($buscador['respuesta']));
    }

    /** @comportamiento Un recálculo de precio se marca «Sin Cambios» al descartarlo y «Pendiente» al volver a él. */
    public function test_elEstadoDeUnRecalculoSeCambia(): void
    {
        $preparacion = [...$this->producto('Kiwi'),
            "INSERT INTO historico_precios (idArticulo, Antes, Nuevo, Fecha_Creacion, NumDoc, Dedonde, Tipo, idUsuario, estado) VALUES (:p1, 1, 2, NOW(), 12, 'albaran', 'compras', :usuario, 'Pendiente')"];
        $formulario = ['pulsado' => 'cambiarEstadoRecalculo', 'idArticulo' => ':p1', 'dedonde' => 'albaran', 'id' => '12', 'tipo' => 'compras'];

        $descartado = $this->ejecutar($formulario + ['accion' => 'eliminar'], $preparacion, true, [], ['estado' => 'SELECT estado FROM historico_precios WHERE idArticulo = :p1']);
        $vuelto = $this->ejecutar($formulario + ['accion' => 'retorno'], [...$preparacion, "UPDATE historico_precios SET estado = 'Sin Cambios' WHERE idArticulo = :p1"],
            true, [], ['estado' => 'SELECT estado FROM historico_precios WHERE idArticulo = :p1']);

        self::assertSame('Sin Cambios', $descartado['consultas']['estado']);
        self::assertSame('Pendiente', $vuelto['consultas']['estado']);
    }

    /** @comportamiento La configuración del listado de un usuario se graba. */
    public function test_laConfiguracionDelListadoSeGraba(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'Grabar_configuracion', 'configuracion' => ['nombre_modulo' => 'mod_producto', 'idUsuario' => ':usuario', 'tipo_configuracion' => 'Modulo', 'mostrar_lista' => 'x']],
            ["DELETE FROM modulos_configuracion WHERE idusuario = :usuario AND nombre_modulo = 'mod_producto'"], true, [],
            ['configuracion' => "SELECT configuracion FROM modulos_configuracion WHERE idusuario = :usuario AND nombre_modulo = 'mod_producto'"]);

        self::assertSame('{"mostrar_lista":"x"}', $peticion['consultas']['configuracion']);
        self::assertSame('', $peticion['errores']);
    }

    /** @comportamiento Sin relación con la tienda web, el estado web de un producto es «NoExiste», sin preguntar a la web. */
    public function test_sinRelacionConLaWebElEstadoEsNoExiste(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'obtenerEstadoProductoWeb', 'ids_productos' => [':p1'], 'id_tiendaWeb' => '0'], $this->producto('Kiwi'));

        self::assertSame('NoExiste', $peticion['respuesta'][0]['estado']);
        self::assertSame('', $peticion['errores']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/tareas.php:41-388
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Una orden que la petición no conoce responde una lista vacía, como si hubiera ido
     *   bien.
     * @que-deberia-ocurrir Que una orden desconocida responda un error.
     * @por-que-ocurre El despacho no tiene caso por defecto, y la respuesta empieza vacía.
     * @como-deberia-funcionar Un caso por defecto que responda el error.
     */
    public function test_unaOrdenDesconocidaRespondeUnError(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'ordenQueNoExiste']);

        self::assertIsArray($peticion['respuesta']);
        self::assertArrayHasKey('error', $peticion['respuesta']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/tareas.php:11-39
     * @codigo-afectado modulos/mod_producto/tareas.php:127-134
     *
     * @group defecto
     * @group permisos
     * @group alto
     *
     * @que-ocurre-hoy Una petición sin sesión cambia el estado de los productos.
     * @que-deberia-ocurrir Que sin sesión no se cambie nada.
     * @por-que-ocurre La petición de fondo no comprueba la sesión.
     * @como-deberia-funcionar Comprobar la sesión antes de atender la orden.
     */
    public function test_sinSesionNoSeCambiaElEstado(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'cambiarEstadoProductos', 'productos' => ':p1', 'estado' => 'Baja'],
            $this->producto('Kiwi'), false, [], ['estado' => 'SELECT estado FROM articulos WHERE idArticulo = :p1']);

        self::assertSame('Activo', $peticion['consultas']['estado']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/tareas.php:127-134
     * @codigo-afectado modulos/mod_producto/tareas.php:198-237
     *
     * @group defecto
     * @group permisos
     * @group alto
     *
     * @que-ocurre-hoy Sin los permisos de cambiar el estado y de eliminar productos, que el módulo
     *   declara, la petición cambia el estado y borra los productos.
     * @que-deberia-ocurrir Que sin cada permiso no se haga lo que protege.
     * @por-que-ocurre La petición de fondo no consulta ningún permiso.
     * @como-deberia-funcionar Consultar el permiso de la acción antes de atenderla.
     */
    public function test_sinPermisoNoSeCambiaElEstadoNiSeBorra(): void
    {
        $sinPermisos = [$this->quitarPermiso('cambiarEstado'), $this->quitarPermiso('eliminarProductos')];

        $estado = $this->ejecutar(['pulsado' => 'cambiarEstadoProductos', 'productos' => ':p1', 'estado' => 'Baja'],
            [...$this->producto('Kiwi'), ...$sinPermisos], true, [], ['estado' => 'SELECT estado FROM articulos WHERE idArticulo = :p1']);
        $borra = $this->ejecutar(['pulsado' => 'eliminarProductos', 'idTiendaWeb' => '999999'],
            [...$this->producto('Kiwi', 'Baja'), ...$sinPermisos], true, [':p1'], ['queda' => 'SELECT COUNT(*) FROM articulos WHERE idArticulo = :p1']);

        self::assertSame('Activo', $estado['consultas']['estado']);
        self::assertSame('1', $borra['consultas']['queda']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/tareas.php:65-67
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Poner en una familia, desde el listado, los productos seleccionados que no la
     *   tienen deja un aviso de PHP por cada uno.
     * @que-deberia-ocurrir Que la petición no deje avisos.
     * @por-que-ocurre La respuesta copia los datos de la comprobación antes de mirar si los hay, y no
     *   los hay cuando el producto no está en la familia.
     * @como-deberia-funcionar Copiarlos solo si los hay, o no copiarlos.
     */
    public function test_ponerLosSeleccionadosEnUnaFamiliaNoDejaAvisos(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'guardarFamiliaProductos', 'idfamilia' => ':f1', 'idProducto' => '0', 'dedonde' => 'ListadoProductos'],
            [...$this->producto('Kiwi'), $this->familia('Frutas')], true, [':p1']);

        self::assertSame('', $peticion['errores']);
    }

    /** Un producto con su precio y su referencia en la tienda principal. @return list<string> */
    private function producto(string $nombre, string $estado = 'Activo'): array
    {
        return [
            "INSERT INTO articulos (articulo_name, estado, fecha_creado, ultimoCoste, iva, beneficio, tipo) VALUES ('{$nombre}', '{$estado}', '2026-01-01', 1, 21, 0, 'unidad')",
            'INSERT INTO articulosPrecios (idArticulo, pvpCiva, pvpSiva, idTienda) VALUES (:pN, 1.21, 1, :tienda)',
            "INSERT INTO articulosTiendas (idArticulo, idTienda, crefTienda, estado) VALUES (:pN, :tienda, 'REF-:pN', 'Activo')",
        ];
    }

    private function familia(string $nombre): string
    {
        return "INSERT INTO familias (familiaNombre, familiaPadre, beneficiomedio, mostrar_tpv) VALUES ('{$nombre}', 0, 0, 1)";
    }

    private function quitarPermiso(string $accion): string
    {
        return "UPDATE permisos SET permiso = 0 WHERE idUsuario = :usuario AND modulo = 'mod_producto' AND accion = '{$accion}'";
    }

    /**
     * Ejecuta la petición de fondo en un PHP aparte y devuelve su respuesta, sus avisos, los nombres de
     * lo sembrado y lo que responden las consultas del caso al terminar.
     *
     * `:pN` en la preparación es el último producto dado de alta; `:historico` y `:cruce`, la última
     * línea de histórico y de referencia de tienda; `:proveedor`, el primer proveedor de la base.
     *
     * @param array<string, mixed> $formulario
     * @param list<string> $preparacion
     * @param list<string> $seleccion
     * @param array<string, string> $consultas
     * @return array{respuesta:mixed, errores:string, ids:array<string, string>, consultas:array<string, ?string>}
     */
    private function ejecutar(array $formulario, array $preparacion = [], bool $conSesion = true, array $seleccion = [], array $consultas = []): array
    {
        $sesion = $conSesion ? [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => $this->idUsuario, 'group_id' => 1, 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => $this->idTienda, 'razonsocial' => 'Tienda de pruebas'],
        ] : [];
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["DOCUMENT_ROOT"] = ""; $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = "/TPVFox/modulos/mod_producto/tareas.php";'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_producto', true) . ');'
            . ' require "./../../inicial.php";'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ($conSesion ? ' new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' $BDTpv->query("UPDATE permisos SET permiso = 1 WHERE idUsuario = ' . $this->idUsuario . ' AND modulo = \'mod_producto\'");'
            . ' $ids = [":usuario" => "' . $this->idUsuario . '", ":tienda" => "' . $this->idTienda . '",'
            . ' ":proveedor" => (string) $BDTpv->query("SELECT MIN(idProveedor) FROM proveedores")->fetch_row()[0]]; $productos = 0; $familias = 0;'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->query(strtr($sql, $ids));'
            . ' if (str_starts_with($sql, "INSERT INTO articulos ")) { $ids[":p" . ++$productos] = $ids[":pN"] = (string) $BDTpv->insert_id; }'
            . ' if (str_starts_with($sql, "INSERT INTO familias ")) { $ids[":f" . ++$familias] = (string) $BDTpv->insert_id; }'
            . ' if (str_starts_with($sql, "INSERT INTO historico_precios ")) { $ids[":historico"] = (string) $BDTpv->insert_id; }'
            . ' if (str_starts_with($sql, "INSERT INTO articulosTiendas ")) { $ids[":cruce"] = (string) $BDTpv->insert_id; } }'
            . ' if (' . var_export($seleccion !== [], true) . ') { $_SESSION["productos_seleccionados"] = array_map(fn ($v) => strtr($v, $ids), ' . var_export($seleccion, true) . '); }'
            . ' $_POST = ' . var_export($formulario, true) . ';'
            . ' array_walk_recursive($_POST, function (&$valor) use ($ids) { $valor = strtr((string) $valor, $ids); });'
            . ($conSesion ? ' $ClasePermisos = $thisTpv->permisos = new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' register_shutdown_function(function () use ($BDTpv, &$ids) { $resultados = [];'
            . ' foreach (' . var_export($consultas, true) . ' as $nombre => $sql) { $fila = $BDTpv->query(strtr($sql, $ids))->fetch_row(); $resultados[$nombre] = $fila === null ? null : (string) $fila[0]; }'
            . ' echo "\n@@ESTADO@@" . json_encode(["ids" => $ids, "consultas" => $resultados]); });'
            . ' include "tareas.php";';

        $errores = (string) tempnam(sys_get_temp_dir(), 'productos');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$escrito, $estado] = array_pad(explode('@@ESTADO@@', implode("\n", $salida), 2), 2, 'null');
        $estado = json_decode($estado, true) ?? ['ids' => [], 'consultas' => []];
        $ejecucion = ['respuesta' => json_decode(trim($escrito), true), 'errores' => (string) file_get_contents($errores)] + $estado;
        unlink($errores);

        return $ejecucion;
    }
}
