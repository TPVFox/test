<?php

/**
 * La petición de fondo de `mod_productos` (`modulos/mod_productos/tareas.php`): la de `mod_producto`
 * con la selección guardada en un fichero por usuario —marcar, quitar, vaciar, fijar, añadir y leer, y
 * marcar los productos de una familia o de un proveedor—, una respuesta con «ok» y «mensaje», y los
 * fallos recogidos y respondidos como mensaje.
 *
 * Cada petición se ejecuta en un PHP aparte, como las de `mod_producto`: desde la carpeta del módulo,
 * con la sesión del usuario de pruebas o sin ninguna, los permisos del módulo concedidos salvo el que
 * el caso quite, y una transacción que se deshace al terminar. El fichero de selección del usuario se
 * guarda antes de cada caso y se repone al terminar.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModProducto;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class TareasProductosIntegracionTest extends CasoIntegracion
{
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

    /** @comportamiento La selección se guarda en el fichero del usuario: se marca, se quita, se fija, se añade, se lee y se vacía. */
    public function test_laSeleccionSeGuardaEnElFicheroDelUsuario(): void
    {
        $marca = $this->ejecutar(['accion' => 'agregar', 'idArticulo' => '4']);
        $this->ejecutar(['accion' => 'agregar', 'idArticulo' => '4']);
        $this->ejecutar(['accion' => 'agregarASeleccion', 'ids' => ['5', '6']]);
        $quita = $this->ejecutar(['accion' => 'quitar', 'idArticulo' => '5']);
        $lee = $this->ejecutar(['accion' => 'obtener']);
        $fija = $this->ejecutar(['accion' => 'establecerSeleccion', 'ids' => ['9']]);
        $vacia = $this->ejecutar(['accion' => 'limpiar']);
        $malo = $this->ejecutar(['accion' => 'agregar', 'idArticulo' => '0']);

        self::assertSame(['ok' => true, 'mensaje' => '', 'total' => 1], $marca['respuesta']);
        self::assertSame(2, $quita['respuesta']['total']);
        self::assertSame([4, 6], $lee['respuesta']['ids']);
        self::assertSame(1, $fija['respuesta']['total']);
        self::assertTrue($vacia['respuesta']['ok']);
        self::assertFileDoesNotExist($this->ficheroDeSeleccion($this->idUsuario));
        self::assertSame('idArticulo no válido', $malo['respuesta']['mensaje']);
    }

    /** @comportamiento Los productos de una familia o de un proveedor se añaden a la selección; sin ninguno, la respuesta no es correcta. */
    public function test_losProductosDeUnaFamiliaODeUnProveedorSeSeleccionan(): void
    {
        $preparacion = [...$this->producto('Kiwi'), $this->familia('Frutas'), $this->familia('Vacía'), 'INSERT INTO articulosFamilias (idArticulo, idFamilia) VALUES (:p1, :f1)',
            "INSERT INTO articulosProveedores (idArticulo, idProveedor, crefProveedor, coste, fechaActualizacion, estado) SELECT :p1, MIN(idProveedor), 'K', 1, '2026-01-01', 'Activo' FROM proveedores"];

        $familia = $this->ejecutar(['accion' => 'buscarProductosDeFamilia', 'idfamilia' => ':f1'], $preparacion);
        $vacia = $this->ejecutar(['accion' => 'buscarProductosDeFamilia', 'idfamilia' => ':f2'], $preparacion);
        $proveedor = $this->ejecutar(['accion' => 'buscarProductosProveedor', 'idProveedor' => ':proveedor'], $preparacion);

        self::assertSame([(int) $familia['ids'][':p1']], $familia['respuesta']['ids']);
        self::assertFalse($vacia['respuesta']['ok']);
        self::assertContains((int) $proveedor['ids'][':p1'], $proveedor['respuesta']['ids']);
    }

    /** @comportamiento El estado de varios productos se cambia, y la respuesta lo confirma. */
    public function test_seCambiaElEstadoDeVariosProductos(): void
    {
        $peticion = $this->ejecutar(['accion' => 'cambiarEstadoProductos', 'productos' => ':p1,:p2', 'estado' => 'Baja'],
            [...$this->producto('Kiwi'), ...$this->producto('Pera')], true, ['bajas' => "SELECT COUNT(*) FROM articulos WHERE idArticulo IN (:p1, :p2) AND estado = 'Baja'"]);

        self::assertTrue($peticion['respuesta']['ok']);
        self::assertSame('2', $peticion['consultas']['bajas']);
    }

    /** @comportamiento Borrar los seleccionados quita los de baja sin uso y los saca de la selección; el resumen escribe los nombres como texto. */
    public function test_borrarLosSeleccionados(): void
    {
        $preparacion = [...$this->producto('Kiwi <b>bio</b>', 'Baja'), ...$this->producto('Pera')];

        $peticion = $this->ejecutar(['accion' => 'eliminarProductos', 'idTiendaWeb' => '999999'], $preparacion, true,
            ['quedan' => 'SELECT COUNT(*) FROM articulos WHERE idArticulo IN (:p1, :p2)'], [':p1', ':p2']);

        self::assertSame(['Kiwi <b>bio</b>'], array_column($peticion['respuesta']['Eliminados'], 'nombre'));
        self::assertStringContainsString('Kiwi &lt;b&gt;bio&lt;/b&gt;', $peticion['respuesta']['html']);
        self::assertSame('1', $peticion['consultas']['quedan']);
    }

    /** @comportamiento Quitar un proveedor exige un producto y un proveedor válidos; con ellos, lo quita. */
    public function test_quitarUnProveedorExigeParametrosValidos(): void
    {
        $preparacion = [...$this->producto('Kiwi'),
            "INSERT INTO articulosProveedores (idArticulo, idProveedor, crefProveedor, coste, fechaActualizacion, estado) SELECT :p1, MIN(idProveedor), 'K', 1, '2026-01-01', 'Activo' FROM proveedores"];

        $malo = $this->ejecutar(['accion' => 'eliminarRefProveedor', 'idArticulo' => '0', 'idProveedor' => '1']);
        $bueno = $this->ejecutar(['accion' => 'eliminarRefProveedor', 'idArticulo' => ':p1', 'idProveedor' => ':proveedor'], $preparacion, true,
            ['quedan' => 'SELECT COUNT(*) FROM articulosProveedores WHERE idArticulo = :p1']);

        self::assertSame('Parámetros no válidos', $malo['respuesta']['mensaje']);
        self::assertSame('0', $bueno['consultas']['quedan']);
    }

    /** @comportamiento Se comprueban una referencia y se quita la relación con una tienda. */
    public function test_seCompruebaUnaReferenciaYSeQuitaUnaRelacion(): void
    {
        $referencia = $this->ejecutar(['accion' => 'comprobarReferencia', 'referencia' => 'REF-:p1'], $this->producto('Kiwi'));
        $quita = $this->ejecutar(['accion' => 'eliminarReferenciaTienda', 'idCruce' => ':cruce'], $this->producto('Kiwi'), true,
            ['quedan' => 'SELECT COUNT(*) FROM articulosTiendas WHERE idArticulo = :p1']);

        self::assertSame([$referencia['ids'][':p1']], array_column($referencia['respuesta'], 'idArticulo'));
        self::assertSame('0', $quita['consultas']['quedan']);
    }

    /** @comportamiento La regularización se prepara y se graba, como en `mod_producto`, y la respuesta lo confirma. */
    public function test_laRegularizacionSePreparaYSeGraba(): void
    {
        $preparacion = [...$this->producto('Kiwi'), 'INSERT INTO articulosStocks (idArticulo, idTienda, stockOn, stockMin, stockMax) VALUES (:p1, :tienda, 5, 0, 0)'];

        $modal = $this->ejecutar(['accion' => 'datosRegularizar', 'idarticulo' => ':p1'], $preparacion);
        $graba = $this->ejecutar(['accion' => 'grabarRegularizacion', 'idarticulo' => ':p1', 'stockReal' => '8'], $preparacion, true,
            ['stock' => 'SELECT stockOn FROM articulosStocks WHERE idArticulo = :p1']);

        self::assertStringContainsString('id="stockactual" value="5.00"', $modal['respuesta']['html']);
        self::assertTrue($graba['respuesta']['ok']);
        self::assertSame(8.0, (float) $graba['consultas']['stock']);
    }

    /** @comportamiento Las familias se ponen desde la ficha o a la selección, y los modales y la línea de código se piden a la petición. */
    public function test_familiasModalesYLineaDeCodigo(): void
    {
        $preparacion = [...$this->producto('Kiwi'), $this->familia('Frutas')];

        $ficha = $this->ejecutar(['accion' => 'guardarFamiliaProductos', 'idfamilia' => ':f1', 'idProducto' => ':p1'], $preparacion);
        $listado = $this->ejecutar(['accion' => 'guardarFamiliaProductos', 'idfamilia' => ':f1', 'idProducto' => '0', 'dedonde' => 'ListadoProductos'], $preparacion, true, [], [':p1']);
        $familias = $this->ejecutar(['accion' => 'modalFamiliaProducto', 'idProducto' => '', 'dedonde' => 'ListadoProductos'], $preparacion);
        $estados = $this->ejecutar(['accion' => 'modalEstadoProductos'], $preparacion, true, [], [':p1']);
        $linea = $this->ejecutar(['pulsado' => 'HtmlLineaCodigoBarras', 'fila' => '2']);

        self::assertStringContainsString('<td>Frutas</td>', $ficha['respuesta']['html']);
        self::assertSame(1, $listado['respuesta']['contadorProductos']);
        self::assertStringContainsString('>Frutas</option>', $familias['respuesta']['html']);
        self::assertStringContainsString('id="idProductosModal"', $estados['respuesta']['html']);
        self::assertStringContainsString('id="codBarras_2"', $linea['respuesta']['html']);
    }

    /** @comportamiento Los costes de un proveedor nuevo para el producto y el buscador de proveedores se piden a la petición. */
    public function test_losCostesDeUnProveedorYElBuscador(): void
    {
        $costes = $this->ejecutar(['accion' => 'obtenerCostesProveedor', 'idProveedor' => ':proveedor', 'idProducto' => ':p1'], $this->producto('Kiwi'));
        $buscador = $this->ejecutar(['accion' => 'HtmlCajaBuscarProveedor', 'busqueda' => '', 'dedonde' => 'producto', 'idcaja' => 'cajaBusquedaproveedor']);

        self::assertStringContainsString('idProveedor_' . $costes['ids'][':proveedor'], json_encode($costes['respuesta']));
        self::assertStringContainsString('cajaBusquedaproveedor', json_encode($buscador['respuesta']));
    }

    /** @comportamiento Un recálculo se marca «Sin Cambios» al descartarlo; imprimirlo sin haberlo guardado antes responde un error. */
    public function test_elRecalculoSeMarcaYNoSeImprimeSinGuardar(): void
    {
        $preparacion = [...$this->producto('Kiwi'),
            "INSERT INTO historico_precios (idArticulo, Antes, Nuevo, Fecha_Creacion, NumDoc, Dedonde, Tipo, idUsuario, estado) VALUES (:p1, 1, 2, NOW(), 12, 'albaran', 'compras', :usuario, 'Pendiente')"];

        $descartado = $this->ejecutar(['accion' => 'cambiarEstadoRecalculo', 'idArticulo' => ':p1', 'dedonde' => 'albaran', 'id' => '12', 'tipo' => 'compras', 'operacion' => 'eliminar'],
            $preparacion, true, ['estado' => 'SELECT estado FROM historico_precios WHERE idArticulo = :p1']);
        $imprimir = $this->ejecutar(['accion' => 'imprimirRecalculo', 'id' => '999999']);

        self::assertSame('Sin Cambios', $descartado['consultas']['estado']);
        self::assertSame('No hay datos guardados para imprimir. Guarda primero el recalculo.', $imprimir['respuesta']['error']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/tareas.php:304-316
     * @codigo-afectado clases/articulos.php:166-174
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Con dos líneas del mismo producto en el recálculo de un albarán, descartar una descarta
     *   las dos: la pantalla tacha solo la pulsada, y al guardar, el precio de la otra no se guarda.
     * @que-deberia-ocurrir Que descartar cambie solo la línea pulsada.
     * @por-que-ocurre La petición cambia el estado por el producto y el albarán, no por la línea.
     * @como-deberia-funcionar Pedir y cambiar el estado de la línea del histórico, por su número.
     */
    public function test_descartarUnaLineaDelRecalculoNoDescartaOtraDelMismoProducto(): void
    {
        $linea = "INSERT INTO historico_precios (idArticulo, Antes, Nuevo, Fecha_Creacion, NumDoc, Dedonde, Tipo, idUsuario, estado) VALUES (:p1, 1, 2, NOW(), 12, 'albaran', 'compras', :usuario, 'Pendiente')";
        $descartado = $this->ejecutar(['accion' => 'cambiarEstadoRecalculo', 'idArticulo' => ':p1', 'dedonde' => 'albaran', 'id' => '12', 'tipo' => 'compras', 'operacion' => 'eliminar'],
            [...$this->producto('Kiwi'), $linea, $linea], true, ['descartadas' => "SELECT COUNT(*) FROM historico_precios WHERE idArticulo = :p1 AND estado = 'Sin Cambios'"]);

        self::assertSame('1', $descartado['consultas']['descartadas']);
    }

    /** @comportamiento La configuración del listado se graba, y una acción desconocida responde que no se reconoce. */
    public function test_laConfiguracionSeGrabaYUnaAccionDesconocidaSeAvisa(): void
    {
        $configuracion = $this->ejecutar(['accion' => 'Grabar_configuracion', 'configuracion' => ['nombre_modulo' => 'mod_productos', 'idUsuario' => ':usuario', 'tipo_configuracion' => 'Modulo', 'mostrar_lista' => 'x']],
            ["DELETE FROM modulos_configuracion WHERE idusuario = :usuario AND nombre_modulo = 'mod_productos'"], true,
            ['configuracion' => "SELECT configuracion FROM modulos_configuracion WHERE idusuario = :usuario AND nombre_modulo = 'mod_productos'"]);
        $desconocida = $this->ejecutar(['accion' => 'ordenQueNoExiste']);

        self::assertSame('{"mostrar_lista":"x"}', $configuracion['consultas']['configuracion']);
        self::assertSame(['ok' => false, 'mensaje' => 'Accion no reconocida: ordenQueNoExiste'], $desconocida['respuesta']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/tareas.php:1-27
     * @codigo-afectado modulos/mod_productos/tareas.php:46-55
     *
     * @group defecto
     * @group permisos
     * @group alto
     *
     * @que-ocurre-hoy Una petición sin sesión cambia el estado de los productos.
     * @que-deberia-ocurrir Que sin sesión no se cambie nada.
     * @por-que-ocurre La petición de fondo no comprueba la sesión.
     * @como-deberia-funcionar Comprobar la sesión antes de atender la acción.
     */
    public function test_sinSesionNoSeCambiaElEstado(): void
    {
        $peticion = $this->ejecutar(['accion' => 'cambiarEstadoProductos', 'productos' => ':p1', 'estado' => 'Baja'],
            $this->producto('Kiwi'), false, ['estado' => 'SELECT estado FROM articulos WHERE idArticulo = :p1']);

        self::assertSame('Activo', $peticion['consultas']['estado']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/tareas.php:46-55
     * @codigo-afectado modulos/mod_productos/tareas.php:148-208
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
        $preparacion = [...$this->producto('Kiwi', 'Baja'), ...$sinPermisos];

        $estado = $this->ejecutar(['accion' => 'cambiarEstadoProductos', 'productos' => ':p1', 'estado' => 'Activo'], $preparacion, true,
            ['estado' => 'SELECT estado FROM articulos WHERE idArticulo = :p1']);
        $borra = $this->ejecutar(['accion' => 'eliminarProductos', 'idTiendaWeb' => '999999'], $preparacion, true,
            ['queda' => 'SELECT COUNT(*) FROM articulos WHERE idArticulo = :p1'], [':p1']);

        self::assertSame('Baja', $estado['consultas']['estado']);
        self::assertSame('1', $borra['consultas']['queda']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/tareas.php:406-408
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Un fallo de la base responde al navegador su mensaje, con el trozo de la
     *   sentencia en que falló.
     * @que-deberia-ocurrir Que el fallo se comunique sin enseñar la sentencia.
     * @por-que-ocurre El fallo recogido se responde con su mensaje tal cual.
     * @como-deberia-funcionar Responder un mensaje propio y dejar el detalle en el registro de errores.
     */
    public function test_unFalloDeLaBaseNoEnsenaLaSentencia(): void
    {
        $peticion = $this->ejecutar(['accion' => 'cambiarEstadoProductos', 'productos' => '1)', 'estado' => 'Baja']);

        self::assertStringNotContainsString('SQL', $peticion['respuesta']['mensaje']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/tareas.php:28-405
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy La ficha y el mayor de `mod_productos` cargan el JavaScript de `mod_producto`, que
     *   envía sus órdenes a la petición de la carpeta de la página, y esta no atiende varias: quitar una
     *   línea del histórico o una tecla de balanza, comprobar un código de barras o un producto y pedir
     *   el estado en la web responden que la acción no se reconoce; la pantalla quita la línea igual.
     * @que-deberia-ocurrir Que la petición de `mod_productos` atienda todas las órdenes que le envían
     *   sus pantallas.
     * @por-que-ocurre La petición de `mod_productos` se escribió con solo una parte de las órdenes de la
     *   de `mod_producto`, y el JavaScript común no sabe a cuál habla.
     * @como-deberia-funcionar Atender en las dos peticiones las mismas órdenes, o una sola petición.
     */
    public function test_lasOrdenesDeLaFichaYDelMayorSeAtienden(): void
    {
        $noAtendidas = [];
        foreach (['eliminarHistorico', 'eliminarReferenciaBalanza', 'ComprobarSiExisteCodbarras', 'idExiste', 'obtenerEstadoProductoWeb'] as $orden) {
            $respuesta = $this->ejecutar(['pulsado' => $orden])['respuesta'];
            if (str_starts_with((string) ($respuesta['mensaje'] ?? ''), 'Accion no reconocida')) {
                $noAtendidas[] = $orden;
            }
        }

        self::assertSame([], $noAtendidas);
    }

    /** Un producto con su precio y su referencia en la tienda principal. @return list<string> */
    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/tareas.php:62-80
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy La regularización apunta en las existencias, como usuario que la hizo, el número
     *   de la operación de suma.
     * @que-deberia-ocurrir Que apunte el usuario de la sesión.
     * @por-que-ocurre La petición pasa a la regularización la constante de la operación en el lugar del
     *   usuario.
     * @como-deberia-funcionar Pasar el usuario de la sesión.
     */
    public function test_laRegularizacionApuntaEnLasExistenciasQuienLaHizo(): void
    {
        $preparacion = [...$this->producto('Kiwi'), 'INSERT INTO articulosStocks (idArticulo, idTienda, stockOn, stockMin, stockMax) VALUES (:p1, :tienda, 5, 0, 0)'];

        $peticion = $this->ejecutar(['accion' => 'grabarRegularizacion', 'idarticulo' => ':p1', 'stockReal' => '8'], $preparacion, true,
            ['usuario' => 'SELECT usuarioRegularizacion FROM articulosStocks WHERE idArticulo = :p1 AND idTienda = :tienda']);

        self::assertSame((string) $this->idUsuario, $peticion['consultas']['usuario']);
    }

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
        return "UPDATE permisos SET permiso = 0 WHERE idUsuario = :usuario AND modulo = 'mod_productos' AND accion = '{$accion}'";
    }

    private function ficheroDeSeleccion(int $idUsuario): string
    {
        return RUTA_TPVFOX . '/modulos/mod_productos/cache/' . $idUsuario . '_seleccion.xml';
    }

    /**
     * Ejecuta la petición de fondo en un PHP aparte y devuelve su respuesta, sus avisos, los nombres de
     * lo sembrado y lo que responden las consultas del caso al terminar. Los nombres de la preparación
     * son los de `TareasProductoIntegracionTest`; la selección, con esos nombres, se guarda en el fichero
     * del usuario dentro del mismo proceso, tras la preparación.
     *
     * @param array<string, mixed> $formulario
     * @param list<string> $preparacion
     * @param array<string, string> $consultas
     * @param list<string> $seleccion
     * @return array{respuesta:mixed, errores:string, ids:array<string, string>, consultas:array<string, ?string>}
     */
    private function ejecutar(array $formulario, array $preparacion = [], bool $conSesion = true, array $consultas = [], array $seleccion = []): array
    {
        $sesion = $conSesion ? [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => $this->idUsuario, 'group_id' => 1, 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => $this->idTienda, 'razonsocial' => 'Tienda de pruebas'],
        ] : [];
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["DOCUMENT_ROOT"] = ""; $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = "/TPVFox/modulos/mod_productos/tareas.php";'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_productos', true) . ');'
            . ' require "./../../inicial.php";'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ($conSesion ? ' new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' $BDTpv->query("UPDATE permisos SET permiso = 1 WHERE idUsuario = ' . $this->idUsuario . ' AND modulo = \'mod_productos\'");'
            . ' $ids = [":usuario" => "' . $this->idUsuario . '", ":tienda" => "' . $this->idTienda . '",'
            . ' ":proveedor" => (string) $BDTpv->query("SELECT MIN(idProveedor) FROM proveedores")->fetch_row()[0]]; $productos = 0; $familias = 0;'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->query(strtr($sql, $ids));'
            . ' if (str_starts_with($sql, "INSERT INTO articulos ")) { $ids[":p" . ++$productos] = $ids[":pN"] = (string) $BDTpv->insert_id; }'
            . ' if (str_starts_with($sql, "INSERT INTO familias ")) { $ids[":f" . ++$familias] = (string) $BDTpv->insert_id; }'
            . ' if (str_starts_with($sql, "INSERT INTO articulosTiendas ")) { $ids[":cruce"] = (string) $BDTpv->insert_id; } }'
            . ' if (' . var_export($seleccion !== [], true) . ') { require_once $URLCom . "/modulos/mod_productos/clases/ClaseSeleccionProductos.php";'
            . ' $marcados = new ClaseSeleccionProductos((int) $Usuario["id"], ' . var_export(RUTA_TPVFOX . '/modulos/mod_productos/cache', true) . ');'
            . ' foreach (' . var_export($seleccion, true) . ' as $marcado) { $marcados->agregar((int) strtr($marcado, $ids)); } }'
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
