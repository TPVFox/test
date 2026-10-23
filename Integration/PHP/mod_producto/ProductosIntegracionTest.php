<?php

/**
 * La clase de productos (`modulos/mod_producto/clases/ClaseProductos.php`), sobre la de
 * `clases/ClaseTablaArticulos.php`: la lectura de un producto con la comprobación de su estado, las
 * búsquedas del listado, el alta y la modificación —datos, precios con su histórico, códigos de
 * barras, familias, proveedores y referencia de tienda—, el cambio de estado, el borrado y lo que
 * guarda desde la tienda web.
 *
 * Crear la clase carga los complementos del módulo, y el fichero de la clase base incluye las del IVA
 * y de las familias sin comprobar si ya están cargadas: cada caso corre en su propio proceso. La clase
 * recibe la conexión del caso, y la de existencias usa la que el caso presta a la clase base.
 *
 * El borrado de un producto que está en una relación entre productos lo prueba su propia clase de
 * pruebas; aquí, el resto del borrado.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModProducto;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ProductosIntegracionTest extends CasoIntegracion
{
    protected bool $compartirConexionConElProducto = true;

    private Siembra $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER['DOCUMENT_ROOT'] = '';
        $_SERVER['PHP_SELF'] = '/TPVFox/modulos/mod_producto/producto.php';
        $this->incluirTPVFox('/clases/ClaseConexion.php');
        $this->incluirTPVFox('/modulos/mod_producto/clases/ClaseProductos.php');
        $this->siembra = new Siembra($this->db);
    }

    /** @comportamiento La clase toma la tienda principal, la vista desde la que se llama y los complementos del módulo. */
    public function test_tomaLaTiendaLaVistaYLosComplementos(): void
    {
        $productos = $this->productos();

        self::assertSame((string) $this->siembra->tiendaPorDefecto(), (string) $productos->idTienda);
        self::assertSame('/TPVFox/modulos/mod_producto/producto.php', $productos->GetView());
        self::assertIsArray($productos->GetPlugins());
        self::assertFalse($productos->SetPlugin('ComplementoQueNoExiste'));
    }

    /** @comportamiento Un producto activo se lee sin avisos; uno «Nuevo» de hace más de veinte días, con el aviso de ponerlo activo. */
    public function test_elEstadoDelProductoSeCompruebaAlLeerlo(): void
    {
        $activo = $this->producto('Manzana');
        $nuevo = $this->producto('Pera', ['estado' => 'Nuevo']);
        $productos = $this->productos();

        self::assertSame([], $productos->GetProducto($activo)['comprobaciones']);
        $avisos = $productos->GetProducto($nuevo)['comprobaciones'];
        self::assertStringContainsString('recomiendo ponerlo como Activo', $avisos[0]['mensaje']);
    }

    /** @comportamiento Un estado que no es de los posibles se avisa al leer el producto. */
    public function test_unEstadoQueNoExisteSeAvisa(): void
    {
        $raro = $this->producto('Pera', ['estado' => 'Inventado']);

        $avisos = $this->productos()->GetProducto($raro)['comprobaciones'];

        self::assertStringContainsString('no existe ese estado', $avisos[0]['mensaje']);
    }

    /** @comportamiento Uno «importado» de hace más de veinte días también se avisa; uno «Nuevo» recién creado, no. */
    public function test_unImportadoAntiguoSeAvisaYUnNuevoRecienteNo(): void
    {
        $importado = $this->producto('Pera', ['estado' => 'importado']);
        $reciente = $this->producto('Kiwi', ['estado' => 'Nuevo']);
        $this->db->query("UPDATE articulos SET fecha_creado = NOW() WHERE idArticulo = {$reciente}");

        $avisos = $this->productos()->GetProducto($importado)['comprobaciones'];
        self::assertStringContainsString('Este producto tiene el estado [importado]', $avisos[0]['mensaje']);
        self::assertSame([], $this->productos()->GetProducto($reciente)['comprobaciones']);
    }

    /** @comportamiento Los estados posibles son ocho para los productos, y las tiendas y los proveedores añaden los suyos. */
    public function test_losEstadosPosiblesDependenDeLaTabla(): void
    {
        $productos = $this->productos();

        self::assertCount(8, $productos->posiblesEstados('articulos'));
        self::assertSame(['NoPublicado', 'Publicado'], array_slice(array_column($productos->posiblesEstados('articulosTiendas'), 'estado'), 8));
        self::assertSame('Tarifa', $productos->posiblesEstados('articulosProveedores')[11]['estado']);
    }

    /** @comportamiento El listado busca por nombre, por referencia, por código de barras o por número; un campo que no es ninguno, avisa. */
    public function test_elListadoBuscaPorElCampoElegido(): void
    {
        $id = $this->producto('Kiwi zqxlistado');
        $this->db->query("INSERT INTO articulosCodigoBarras (idArticulo, codBarras) VALUES ({$id}, '8419999999024')");
        $productos = $this->productos();

        $porNombre = $productos->obtenerProductos('articulo_name', ['filtro' => " WHERE a.articulo_name LIKE '%zqxlistado%'"]);
        $porReferencia = $productos->obtenerProductos('t.crefTienda', ['filtro' => " WHERE t.crefTienda = 'REF-{$id}'"]);
        $porCodigo = $productos->obtenerProductos('codBarras', ['filtro' => " WHERE aCodBarras.codBarras = '8419999999024'"]);
        $porNumero = $productos->obtenerProductos('a.idArticulo', ['filtro' => " WHERE a.idArticulo = {$id}", 'limite' => 'LIMIT 1']);
        $malo = $productos->obtenerProductos('campo_raro');

        self::assertSame([(string) $id], array_column($porNombre, 'idArticulo'));
        self::assertSame([(string) $id], array_column($porReferencia, 'idArticulo'));
        self::assertSame([(string) $id], array_column($porCodigo, 'idArticulo'));
        self::assertSame([(string) $id], array_column($porNumero, 'idArticulo'));
        self::assertSame('Error en campo', $malo['error']);
    }

    /** @comportamiento Un producto se busca por su referencia en la tienda y por su código de barras. */
    public function test_seBuscaPorReferenciaYPorCodigoDeBarras(): void
    {
        $id = $this->producto('Kiwi');
        $this->db->query("INSERT INTO articulosCodigoBarras (idArticulo, codBarras) VALUES ({$id}, '8419999999031')");
        $productos = $this->productos();

        self::assertSame([(string) $id], array_column($productos->buscarReferenciaProductoTienda("REF-{$id}"), 'idArticulo'));
        self::assertSame([], $productos->buscarReferenciaProductoTienda('NO-EXISTE-ZQX'));
        self::assertSame((string) $id, $productos->GetProductosConCodbarras('8419999999031')['Items'][0]['idArticulo']);
    }

    /** @comportamiento Los productos de un proveedor se leen todos, con el principal delante, o solo aquellos de los que es el principal. */
    public function test_losProductosDeUnProveedor(): void
    {
        $proveedor = $this->siembra->proveedor('Huerta del Norte');
        $principal = $this->producto('Pera', ['idProveedor' => (string) $proveedor]);
        $otro = $this->producto('Kiwi');
        $this->siembra->articuloProveedor($principal, $proveedor, 1.0);
        $this->siembra->articuloProveedor($otro, $proveedor, 2.0);
        $productos = $this->productos();

        $todos = $productos->GetProductosProveedor($proveedor)['Items'];
        $solo = $productos->GetProductosProveedor($proveedor, 'articulo_name', 'ASC', true)['Items'];

        self::assertSame([(string) $principal, (string) $otro], array_column($todos, 'idArticulo'));
        self::assertSame(['1', '0'], array_column($todos, 'es_principal'));
        self::assertSame([(string) $principal], array_column($solo, 'idArticulo'));
    }

    /** @comportamiento El último precio de compra es el coste de la línea del albarán más reciente; sin compras, ninguno. */
    public function test_elUltimoPrecioDeCompraEsElDelAlbaranMasReciente(): void
    {
        $id = $this->siembra->articulo('Pera', ['ultimoCoste' => 1.5]);
        $sinCompras = $this->siembra->articulo('Kiwi');
        $this->siembra->entradaProveedor($id, 2, '2026-03-01');
        $this->db->query("UPDATE albprolinea SET costeSiva = 1.25 WHERE idArticulo = {$id}");
        $productos = $this->productos();

        self::assertSame(1.25, $productos->getUltimoPrecioCompra($id));
        self::assertNull($productos->getUltimoPrecioCompra($sinCompras));
    }

    /** @comportamiento Cambiar la tienda solo cambia la de la clase de productos, no la que usa la clase base para leer. */
    public function test_cambiarLaTiendaSoloCambiaLaDeLaClase(): void
    {
        $productos = $this->productos();

        $productos->cambiarTienda(99);

        self::assertSame(99, $productos->idTienda);
        self::assertSame((string) $this->siembra->tiendaPorDefecto(), (string) $productos->GetIdTienda());
    }

    /**
     * @comportamiento Un producto nuevo se da de alta con su precio en la tienda, sus códigos de barras,
     *   sus familias y su referencia en la tienda.
     */
    public function test_unProductoNuevoSeDaDeAltaConTodoLoSuyo(): void
    {
        $familia = $this->siembra->familia('Frutas');
        $tienda = $this->siembra->tiendaPorDefecto();

        $resultado = $this->productos()->AnhadirProductoNuevo([
            'iva' => '21.00', 'articulo_name' => 'Nectarina zqx', 'estado' => 'Nuevo', 'ultimoCoste' => '1.00',
            'beneficio' => '25', 'tipo' => 'unidad', 'idTienda' => $tienda, 'pvpCiva' => '1.51', 'pvpSiva' => '1.25',
            'codBarras' => ['8419999999048'], 'familias' => [['idFamilia' => (string) $familia]], 'refProducto' => 'NECT-1',
        ]);
        $id = $resultado['insert_articulos']['id_producto_nuevo'];

        self::assertSame('Nectarina zqx', $this->unValor("SELECT articulo_name FROM articulos WHERE idArticulo = {$id}"));
        self::assertSame('1.510000', $this->unValor("SELECT pvpCiva FROM articulosPrecios WHERE idArticulo = {$id} AND idTienda = {$tienda}"));
        self::assertSame('8419999999048', $this->unValor("SELECT codBarras FROM articulosCodigoBarras WHERE idArticulo = {$id}"));
        self::assertSame((string) $familia, $this->unValor("SELECT idFamilia FROM articulosFamilias WHERE idArticulo = {$id}"));
        self::assertSame('NECT-1', $this->unValor("SELECT crefTienda FROM articulosTiendas WHERE idArticulo = {$id}"));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/clases/ClaseProductos.php:399-450
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Si la base rechaza un código de barras del alta, el producto queda dado de alta
     *   con su precio y sin sus códigos, sus familias ni su referencia.
     * @que-deberia-ocurrir Que el alta se haga entera o no se haga.
     * @por-que-ocurre El alta escribe el artículo, el precio, los códigos, las familias y la
     *   referencia con una sentencia suelta cada uno, y el rechazo termina la petición a mitad.
     * @como-deberia-funcionar Dar de alta todo lo del producto, o nada.
     */
    public function test_unAltaQueLaBaseRechazaAMitadNoDejaElProductoAMedias(): void
    {
        $antes = $this->recuentoArticulos();

        try {
            $this->productos()->AnhadirProductoNuevo([
                'iva' => '21.00', 'articulo_name' => 'Nectarina qzr', 'estado' => 'Nuevo', 'ultimoCoste' => '1.00',
                'beneficio' => '25', 'tipo' => 'unidad', 'idTienda' => $this->siembra->tiendaPorDefecto(), 'pvpCiva' => '1.51',
                'pvpSiva' => '1.25', 'codBarras' => ['84199999990480000000001'], 'familias' => [], 'refProducto' => '',
            ]);
        } catch (\mysqli_sql_exception $e) {
            // El código es más largo de lo que admite su columna: la base lo rechaza a mitad del alta.
        }

        self::assertSame($antes, $this->recuentoArticulos());
    }

    /** @comportamiento Guardar los códigos de barras quita los que ya no vienen y añade los nuevos. */
    public function test_losCodigosDeBarrasQuitanLosQueNoVienenYAnadenLosNuevos(): void
    {
        $id = $this->producto('Kiwi');
        $this->db->query("INSERT INTO articulosCodigoBarras (idArticulo, codBarras) VALUES ({$id}, '8419999999055')");

        $avisos = $this->productos()->ComprobarCodbarrasUnProducto($id, ['8419999999062']);

        self::assertSame(['8419999999062'], $this->columna("SELECT codBarras FROM articulosCodigoBarras WHERE idArticulo = {$id}"));
        self::assertSame(['warning', 'success'], array_column($avisos, 'tipo'));
    }

    /** @comportamiento Guardar las familias quita las que ya no vienen y añade las nuevas. */
    public function test_lasFamiliasQuitanLasQueNoVienenYAnadenLasNuevas(): void
    {
        $frutas = $this->siembra->familia('Frutas');
        $verduras = $this->siembra->familia('Verduras');
        $id = $this->producto('Kiwi', ['familia' => $frutas]);

        $avisos = $this->productos()->ComprobarFamiliasProducto($id, [['idFamilia' => (string) $verduras]]);

        self::assertSame([(string) $verduras], $this->columna("SELECT idFamilia FROM articulosFamilias WHERE idArticulo = {$id}"));
        self::assertSame(['warning', 'success'], array_column($avisos, 'tipo'));
    }

    /** @comportamiento Un proveedor nuevo se guarda como tarifa; uno que cambia, también; uno igual no se toca. */
    public function test_losProveedoresSeGuardanComoTarifa(): void
    {
        $igual = $this->siembra->proveedor('Igual');
        $cambia = $this->siembra->proveedor('Cambia');
        $nuevo = $this->siembra->proveedor('Nuevo');
        $id = $this->producto('Kiwi');
        $this->siembra->articuloProveedor($id, $igual, 1.0, ['crefProveedor' => 'A']);
        $this->siembra->articuloProveedor($id, $cambia, 1.0, ['crefProveedor' => 'B']);

        $resultado = $this->productos()->ComprobarProveedoresCostes($id, [
            ['idArticulo' => (string) $id, 'idProveedor' => (string) $igual, 'crefProveedor' => 'A', 'coste' => '1.000000'],
            ['idArticulo' => (string) $id, 'idProveedor' => (string) $cambia, 'crefProveedor' => 'B', 'coste' => '2.000000'],
            ['idArticulo' => (string) $id, 'idProveedor' => (string) $nuevo, 'crefProveedor' => 'C', 'coste' => '3.000000'],
        ]);

        self::assertCount(1, $resultado['nuevo']);
        self::assertCount(1, $resultado['modificado']);
        self::assertSame('Activo', $this->unValor("SELECT estado FROM articulosProveedores WHERE idArticulo = {$id} AND idProveedor = {$igual}"));
        self::assertSame('Tarifa', $this->unValor("SELECT estado FROM articulosProveedores WHERE idArticulo = {$id} AND idProveedor = {$cambia}"));
        self::assertSame('Tarifa', $this->unValor("SELECT estado FROM articulosProveedores WHERE idArticulo = {$id} AND idProveedor = {$nuevo}"));
    }

    /** @comportamiento La referencia de la tienda se da de alta si el producto no la tenía, y se cambia si la tenía. */
    public function test_laReferenciaDeLaTiendaSeDaDeAltaOSeCambia(): void
    {
        $con = $this->producto('Kiwi');
        $sin = $this->siembra->articulo('Pera');
        $productos = $this->productos();

        $productos->ComprobarReferenciaProductoTienda($con, 'KIWI-2');
        $productos->ComprobarReferenciaProductoTienda($sin, 'PERA-1');

        self::assertSame('KIWI-2', $this->unValor("SELECT crefTienda FROM articulosTiendas WHERE idArticulo = {$con}"));
        self::assertSame(['PERA-1', 'Nuevo'], $this->fila("SELECT crefTienda, estado FROM articulosTiendas WHERE idArticulo = {$sin}"));
    }

    /** @comportamiento Los datos generales de un producto con proveedor principal se guardan solo si cambian, con la fecha de modificación. */
    public function test_losDatosGeneralesSeGuardanSoloSiCambian(): void
    {
        $proveedor = (string) $this->siembra->proveedor('Huerta');
        $id = $this->producto('Kiwi', ['idProveedor' => $proveedor]);
        $productos = $this->productos();
        $post = ['idTienda' => $productos->GetIdTienda(), 'idArticulo' => $id, 'articulo_name' => 'Kiwi', 'iva' => '21.00',
            'estado' => 'Activo', 'ultimoCoste' => '1.00', 'beneficio' => '0.00', 'tipo' => 'unidad', 'idProveedor' => $proveedor];

        $sinCambios = $productos->ComprobarNuevosDatosProducto($id, $post);
        $conCambios = $productos->ComprobarNuevosDatosProducto($id, ['articulo_name' => 'Kiwi gold'] + $post);

        self::assertSame([], $sinCambios);
        self::assertSame(1, $conCambios['datos_generales']['NAfectados']);
        self::assertSame('Kiwi gold', $this->unValor("SELECT articulo_name FROM articulos WHERE idArticulo = {$id}"));
        self::assertNotNull($this->unValor("SELECT fecha_modificado FROM articulos WHERE idArticulo = {$id}"));
    }

    /**
     * @comportamiento Un precio nuevo se guarda con su histórico: «Recomendado» si es el que resulta del
     *   coste, el IVA y el beneficio, y «A mano» si no; sin cambio de precio, no hay histórico.
     */
    public function test_unPrecioNuevoSeGuardaConSuHistorico(): void
    {
        $id = $this->siembra->articulo('Kiwi', ['iva' => 21, 'ultimoCoste' => 10, 'beneficio' => 25]);
        $this->siembra->precioYTienda($id, 15.0, 12.40);
        $productos = $this->productos();
        $usuario = $this->siembra->usuarioPorDefecto();

        $igual = $productos->ComprobarNuevosPreciosProducto($id, ['pvpCiva' => '15.00', 'pvpSiva' => '12.40'], $usuario);
        $recomendado = $productos->ComprobarNuevosPreciosProducto($id, ['pvpCiva' => '15.13', 'pvpSiva' => '12.50'], $usuario);
        $aMano = $productos->ComprobarNuevosPreciosProducto($id, ['pvpCiva' => '16.00', 'pvpSiva' => '13.22'], $usuario);

        self::assertSame('', $igual['estado']);
        self::assertSame('Recomendado', $recomendado['estado']);
        self::assertSame('A mano', $aMano['estado']);
        self::assertSame(['Recomendado', 'A mano'], $this->columna("SELECT estado FROM historico_precios WHERE idArticulo = {$id} ORDER BY id"));
        self::assertSame('16.000000', $this->unValor("SELECT pvpCiva FROM articulosPrecios WHERE idArticulo = {$id}"));
    }

    /** @comportamiento La línea del histórico de un cambio de precio lleva el usuario que lo hizo y la hora de la base. */
    public function test_elHistoricoGuardaQuienCambioElPrecioYCuando(): void
    {
        $id = $this->siembra->articulo('Kiwi', ['iva' => 21, 'ultimoCoste' => 10, 'beneficio' => 25]);
        $this->siembra->precioYTienda($id, 15.0, 12.40);
        $usuario = $this->siembra->usuarioPorDefecto();

        $this->productos()->ComprobarNuevosPreciosProducto($id, ['pvpCiva' => '16.00', 'pvpSiva' => '13.22'], $usuario);

        self::assertSame((string) $usuario, $this->unValor("SELECT idUsuario FROM historico_precios WHERE idArticulo = {$id}"));
        self::assertLessThanOrEqual(60, (int) $this->unValor("SELECT ABS(TIMESTAMPDIFF(SECOND, Fecha_Creacion, NOW())) FROM historico_precios WHERE idArticulo = {$id}"));
    }

    /** @comportamiento El precio recomendado es el coste con IVA más el beneficio. */
    public function test_elPrecioRecomendadoEsElCosteConIvaMasElBeneficio(): void
    {
        $id = $this->siembra->articulo('Kiwi', ['iva' => 21, 'ultimoCoste' => 10, 'beneficio' => 25]);
        $this->siembra->precioYTienda($id, 15.0, 12.40);
        $productos = $this->productos();
        $productos->GetProducto($id);

        self::assertEqualsWithDelta(15.125, $productos->precioCivaRecalculado(), 0.0001);
    }

    /** @comportamiento Los campos obligatorios de un producto se comprueban: el que falta se avisa como grave. */
    public function test_losCamposObligatoriosSeComprueban(): void
    {
        $productos = $this->productos();
        $completo = ['articulo_name' => 'Kiwi', 'estado' => 'Activo', 'iva' => '21.00', 'pvpSiva' => '1', 'pvpCiva' => '1.21', 'beneficio' => '0', 'tipo' => 'unidad'];

        self::assertSame([], $productos->comprobacionCamposObligatoriosProducto($completo));
        $sinNombre = $completo;
        unset($sinNombre['articulo_name']);
        self::assertSame('danger', $productos->comprobacionCamposObligatoriosProducto($sinNombre)['campos']['tipo']);
    }

    /** @comportamiento Se quitan un código de barras, una referencia de tienda, una tecla de balanza, una familia, una línea del histórico y un proveedor. */
    public function test_losElementosDelProductoSeQuitanUnoAUno(): void
    {
        $familia = $this->siembra->familia('Frutas');
        $proveedor = $this->siembra->proveedor('Huerta');
        $id = $this->producto('Kiwi', ['familia' => $familia]);
        $this->db->query("INSERT INTO articulosCodigoBarras (idArticulo, codBarras) VALUES ({$id}, '8419999999079')");
        $this->siembra->articuloProveedor($id, $proveedor, 1.0);
        $historico = $this->siembra->historicoPrecio($id, 1, 2, 'producto', 0);
        $cruce = (int) $this->unValor("SELECT id FROM articulosTiendas WHERE idArticulo = {$id}");
        $productos = $this->productos();

        $productos->EliminarCodbarras($id, ['8419999999079']);
        $productos->EliminarCruceTienda($cruce);
        $productos->EliminarFamilia($id, [(string) $familia]);
        $productos->EliminarHistorico($historico);
        $productos->EliminarRefProveedor($id, $proveedor);
        $balanza = $productos->EliminarReferenciaBalanza(999999);

        foreach (['articulosCodigoBarras', 'articulosTiendas', 'articulosFamilias', 'historico_precios', 'articulosProveedores'] as $tabla) {
            self::assertSame('0', $this->unValor("SELECT COUNT(*) FROM {$tabla} WHERE idArticulo = {$id}"), $tabla);
        }
        self::assertSame(0, $balanza['NAfectados']);
    }

    /** @comportamiento Un producto sin documentos ni existencias en la tienda indicada se borra, con todo lo suyo. */
    public function test_unProductoSinUsoSeBorraConTodoLoSuyo(): void
    {
        $id = $this->producto('Kiwi', ['estado' => 'Baja']);
        $this->siembra->existenciaRegistrada($id, 0);
        $this->siembra->historicoPrecio($id, 1, 2, 'producto', 0);

        $resultado = $this->productos()->ComprobarEliminar($id, 999999);

        self::assertSame(0, $resultado['bandera']);
        foreach (['articulos', 'articulosPrecios', 'articulosTiendas', 'articulosStocks', 'historico_precios'] as $tabla) {
            self::assertSame('0', $this->unValor("SELECT COUNT(*) FROM {$tabla} WHERE idArticulo = {$id}"), $tabla);
        }
    }

    /** @comportamiento Un producto que está en un documento o en la tienda indicada no se borra, y se dice dónde está. */
    public function test_unProductoEnUnDocumentoNoSeBorra(): void
    {
        $id = $this->producto('Kiwi', ['estado' => 'Baja']);
        $this->siembra->ventaTicket($id, 1, '2026-03-01');

        $resultado = $this->productos()->ComprobarEliminar($id, $this->siembra->tiendaPorDefecto());

        self::assertSame(1, $resultado['bandera']);
        self::assertSame(['Tickets ticketslinea: 1', 'articulosTiendas. Tienda:' . $this->siembra->tiendaPorDefecto() . ': 1'], array_column($resultado['resultado'], 'mensaje'));
        self::assertSame('1', $this->unValor("SELECT COUNT(*) FROM articulos WHERE idArticulo = {$id}"));
    }

    /** @comportamiento El estado de varios productos se cambia de una vez, y el de un producto en una tienda, a publicado o sin publicar. */
    public function test_seCambiaElEstadoDeVariosYElDeUnoEnUnaTienda(): void
    {
        $uno = $this->producto('Kiwi');
        $dos = $this->producto('Pera');
        $tienda = $this->siembra->tiendaPorDefecto();
        $productos = $this->productos();

        $productos->modificarVariosEstados('Baja', "{$uno},{$dos}");
        $productos->modificarEstadoWeb($uno, 0, $tienda);
        $productos->modificarEstadoWeb($dos, 1, $tienda);

        self::assertSame(['Baja', 'Baja'], $this->columna("SELECT estado FROM articulos WHERE idArticulo IN ({$uno}, {$dos}) ORDER BY idArticulo"));
        self::assertSame(['Sin Publicar', 'Publicado'], $this->columna("SELECT estado FROM articulosTiendas WHERE idArticulo IN ({$uno}, {$dos}) ORDER BY idArticulo"));
    }

    /** @comportamiento Las lecturas de apoyo: costes de un proveedor, recuentos por tienda, el producto web, las familias web, las teclas de balanza y el producto. */
    public function test_lasLecturasDeApoyo(): void
    {
        $tienda = $this->siembra->tiendaPorDefecto();
        $proveedor = $this->siembra->proveedor('Huerta');
        $id = $this->producto('Kiwi');
        $this->siembra->articuloProveedor($id, $proveedor, 1.5);
        $this->db->query("UPDATE articulosTiendas SET idVirtuemart = 4321 WHERE idArticulo = {$id}");
        $productos = $this->productos();

        self::assertSame('1.500000', $productos->ObtenerCostesDeUnProveedor($id, $proveedor)[0]['coste']);
        self::assertSame('success', $productos->ObtenerCostesDeUnProveedor($id, 999999)['error']['tipo']);
        self::assertSame((string) $this->recuentoArticulos(), $productos->contarProductosTpv()[0]['cantTpv']);
        self::assertArrayHasKey('cantArticulo', $productos->productosTienda($tienda)[0]);
        self::assertArrayHasKey('cantArticulo', $productos->productosEnTpvNoWeb($tienda)[0]);
        self::assertSame((string) $id, $productos->comprobarIdWebTpv($tienda, 4321)[0]['idArticulo']);
        self::assertSame('success', $productos->comprobarIdWebTpv($tienda, 999999)['error']['tipo']);
        self::assertSame('success', $productos->buscarFamiliasProducto($id, $tienda)['error']['tipo']);
        self::assertSame('success', $productos->obtenerTeclaBalanzas($id)['error']['tipo']);
        self::assertSame('Kiwi', $productos->obtenerProducto($id)['articulo_name']);
        self::assertSame('success', $productos->obtenerProducto(999999)['error']['tipo']);
    }

    /** @comportamiento La relación con otra tienda se da de alta. */
    public function test_laRelacionConOtraTiendaSeDaDeAlta(): void
    {
        $id = $this->siembra->articulo('Kiwi');

        $this->productos()->addTiendaProducto($id, $this->siembra->tiendaPorDefecto(), 77, 'Publicado');

        self::assertSame(['77', 'Publicado'], $this->fila("SELECT idVirtuemart, estado FROM articulosTiendas WHERE idArticulo = {$id}"));
    }

    /** @comportamiento Desde la tienda web, un producto se da de alta con su relación, su precio y sus códigos, y uno existente se modifica. */
    public function test_desdeLaTiendaWebSeDaDeAltaYSeModifica(): void
    {
        $tienda = $this->siembra->tiendaPorDefecto();
        $productos = $this->productos();

        $alta = $productos->addProductoWebTPV([
            'iva' => '21.00', 'nombre' => 'Pomelo zqxweb', 'estado' => 'importado', 'ultimoCoste' => '1', 'beneficio' => '25',
            'tiendaWeb' => $tienda, 'tiendaPrincipal' => $tienda, 'refTienda' => 'POM-1', 'id' => 555, 'estadoWeb' => 'Publicado',
            'optRefWeb' => '1', 'precioCiva' => '1.21', 'precioSiva' => '1.00', 'codBarras' => ['8419999999086'],
        ]);
        $id = $alta['idInsert'];
        $modificado = $productos->modificarProductoTPVWeb([
            'id' => $id, 'iva' => '21.00', 'nombre' => 'Pomelo rosa', 'estado' => 'Activo', 'precioSiva' => '2.00', 'precioCiva' => '2.42',
            'refTienda' => 'POM-2', 'optRefWeb' => '1', 'tiendaWeb' => $tienda, 'tiendaPrincipal' => $tienda, 'codBarras' => ['8419999999093'],
        ]);

        self::assertSame([], $modificado);
        self::assertSame(['Pomelo rosa', 'Activo'], $this->fila("SELECT articulo_name, estado FROM articulos WHERE idArticulo = {$id}"));
        self::assertSame('2.420000', $this->unValor("SELECT pvpCiva FROM articulosPrecios WHERE idArticulo = {$id}"));
        self::assertSame(['POM-2', '555'], $this->fila("SELECT crefTienda, idVirtuemart FROM articulosTiendas WHERE idArticulo = {$id}"));
        self::assertSame(['8419999999093'], $this->columna("SELECT codBarras FROM articulosCodigoBarras WHERE idArticulo = {$id}"));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/clases/ClaseProductos.php:142-161
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Leer un producto que no existe devuelve los datos de un producto vacío, con un
     *   aviso de estado, en lugar de decir que no existe; y deja avisos de PHP.
     * @que-deberia-ocurrir Que la lectura diga que el producto no existe.
     * @por-que-ocurre La lectura vacía los avisos de la clase base, el de que el producto no existe
     *   entre ellos, y comprueba el estado sobre la respuesta de error.
     * @como-deberia-funcionar Devolver el aviso grave de la clase base sin vaciarlo.
     */
    public function test_unProductoQueNoExisteSeDiceQueNoExiste(): void
    {
        $producto = $this->productos()->GetProducto(999999);

        self::assertArrayHasKey('error', $producto);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/clases/ClaseProductos.php:142-161
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Los avisos de la lectura del producto —un proveedor principal que no existe, un
     *   producto sin precio en la tienda— no llegan a quien lo lee: solo llega el del estado.
     * @que-deberia-ocurrir Que lleguen todos los avisos de la lectura.
     * @por-que-ocurre La lectura de la clase de productos vacía los avisos de la clase base antes de
     *   comprobar el estado.
     * @como-deberia-funcionar Añadir el aviso del estado a los de la clase base, sin vaciarlos.
     */
    public function test_losAvisosDeLaLecturaLleganAQuienLeeElProducto(): void
    {
        $id = $this->producto('Kiwi', ['idProveedor' => '99999']);

        $producto = $this->productos()->GetProducto($id);

        self::assertContains('warning', array_column($producto['comprobaciones'], 'tipo'));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/clases/ClaseProductos.php:1129-1188
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy Borrar un producto de baja con una regularización de existencias vacía sus
     *   precios, códigos, familias, proveedores, existencias e histórico, y termina con un error de la
     *   base al borrar el producto, que se queda a medias.
     * @que-deberia-ocurrir Que el borrado se haga entero o no se haga, y que diga por qué no.
     * @por-que-ocurre Las comprobaciones de uso no miran las regularizaciones ni los acumulados de
     *   compra, que la base exige quitar antes; y los borrados van uno a uno, sin transacción.
     * @como-deberia-funcionar Comprobar todo lo que impide borrar el producto, y borrar dentro de una
     *   transacción.
     */
    public function test_borrarUnProductoConUnaRegularizacionNoLoDejaAMedias(): void
    {
        $id = $this->producto('Kiwi', ['estado' => 'Baja']);
        $this->siembra->regularizacion($id, '2026-03-01', 2.0);

        $resultado = $this->productos()->ComprobarEliminar($id, 999999);

        self::assertSame(1, $resultado['bandera']);
        self::assertSame('1', $this->unValor("SELECT COUNT(*) FROM articulosPrecios WHERE idArticulo = {$id}"));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/clases/ClaseProductos.php:741-745
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy Un nombre de producto con comillas dobles no se guarda: la modificación termina
     *   con un error de la base. Un nombre preparado altera la sentencia.
     * @que-deberia-ocurrir Que el nombre se guarde tal como se escribió.
     * @por-que-ocurre Los valores se pegan a la sentencia entre comillas dobles, sin escaparlos.
     * @como-deberia-funcionar Pasar los valores como parámetros de la sentencia.
     */
    public function test_unNombreConComillasDoblesSeGuardaTalCual(): void
    {
        $id = $this->producto('Kiwi');
        $productos = $this->productos();
        $post = ['idTienda' => $productos->GetIdTienda(), 'idArticulo' => $id, 'articulo_name' => 'Kiwi "gold"', 'iva' => '21.00',
            'estado' => 'Activo', 'ultimoCoste' => '1.00', 'beneficio' => '0.00', 'tipo' => 'unidad'];

        $productos->ComprobarNuevosDatosProducto($id, $post);

        self::assertSame('Kiwi "gold"', $this->unValor("SELECT articulo_name FROM articulos WHERE idArticulo = {$id}"));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/clases/ClaseProductos.php:710-732
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Guardar sin cambios un producto que no tiene proveedor principal lo modifica
     *   igual: cambia su fecha de modificación aunque nada haya cambiado.
     * @que-deberia-ocurrir Que un guardado sin cambios no toque el producto.
     * @por-que-ocurre Los datos recibidos llevan siempre el proveedor principal, aunque sea vacío, y
     *   los guardados solo cuando lo hay: sin él, las dos listas nunca son iguales.
     * @como-deberia-funcionar Comparar las dos listas con los mismos campos.
     */
    public function test_guardarSinCambiosUnProductoSinProveedorNoLoModifica(): void
    {
        $id = $this->producto('Kiwi');
        $productos = $this->productos();
        $post = ['idTienda' => $productos->GetIdTienda(), 'idArticulo' => $id, 'articulo_name' => 'Kiwi', 'iva' => '21.00',
            'estado' => 'Activo', 'ultimoCoste' => '1.00', 'beneficio' => '0.00', 'tipo' => 'unidad'];

        $resultado = $productos->ComprobarNuevosDatosProducto($id, $post);

        self::assertSame([], $resultado);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/clases/ClaseProductos.php:1343-1348
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy Una lista de productos con una condición añadida cambia el estado de todos los
     *   productos, no solo el de los indicados.
     * @que-deberia-ocurrir Que solo cambie el estado de los productos de la lista.
     * @por-que-ocurre La lista se pega a la sentencia sin comprobar que son números.
     * @como-deberia-funcionar Convertir cada elemento de la lista en número antes de usarla.
     */
    public function test_cambiarElEstadoDeUnosProductosNoCambiaElDeOtros(): void
    {
        $uno = $this->producto('Kiwi');
        $otro = $this->producto('Pera');

        $this->productos()->modificarVariosEstados('Baja', "{$uno}) OR (1=1");

        self::assertSame('Activo', $this->unValor("SELECT estado FROM articulos WHERE idArticulo = {$otro}"));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/clases/ClaseProductos.php:500-546
     * @codigo-afectado modulos/mod_producto/clases/ClaseProductos.php:451-474
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Un código de barras que ya tiene otro producto se guarda también en este: el
     *   mismo código identifica a dos productos.
     * @que-deberia-ocurrir Que un código de barras de otro producto se rechace al guardar.
     * @por-que-ocurre El guardado no comprueba si el código es de otro producto, y la tabla no lo
     *   impide; solo la pantalla lo pregunta antes.
     * @como-deberia-funcionar Comprobarlo al guardar, y un índice único sobre el código.
     */
    public function test_unCodigoDeBarrasDeOtroProductoNoSeGuarda(): void
    {
        $uno = $this->producto('Kiwi');
        $otro = $this->producto('Pera');
        $this->db->query("INSERT INTO articulosCodigoBarras (idArticulo, codBarras) VALUES ({$uno}, '8419999999109')");

        $this->productos()->ComprobarCodbarrasUnProducto($otro, ['8419999999109']);

        self::assertSame('1', $this->unValor("SELECT COUNT(*) FROM articulosCodigoBarras WHERE codBarras = '8419999999109'"));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/clases/ClaseProductos.php:751-790
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Un precio sin IVA que no corresponde al precio con IVA y al IVA del producto se
     *   guarda igual: el producto queda con dos precios que no cuadran.
     * @que-deberia-ocurrir Que el guardado rechace o recalcule el precio que no cuadra con el IVA.
     * @por-que-ocurre Los dos precios se guardan tal como llegan; solo la pantalla los calcula.
     * @como-deberia-funcionar Calcular el precio sin IVA a partir del precio con IVA y el IVA al guardar.
     */
    public function test_unPrecioSinIvaQueNoCuadraConElIvaNoSeGuarda(): void
    {
        $id = $this->siembra->articulo('Kiwi', ['iva' => 21, 'ultimoCoste' => 10, 'beneficio' => 25]);
        $this->siembra->precioYTienda($id, 15.0, 12.40);

        $this->productos()->ComprobarNuevosPreciosProducto($id, ['pvpCiva' => '16.00', 'pvpSiva' => '1.00'], $this->siembra->usuarioPorDefecto());

        self::assertEqualsWithDelta(16 / 1.21, (float) $this->unValor("SELECT pvpSiva FROM articulosPrecios WHERE idArticulo = {$id}"), 0.01);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/clases/ClaseProductos.php:1218-1231
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Modificar desde la tienda web un producto con la opción de guardar también la
     *   referencia en la tienda principal termina con un error de la base.
     * @que-deberia-ocurrir Que se guarde la referencia en las dos tiendas.
     * @por-que-ocurre Las dos actualizaciones van en una sola llamada separadas por punto y coma, y la
     *   conexión no admite varias sentencias; además, la segunda vuelve a escribir en la tienda web.
     * @como-deberia-funcionar Ejecutar cada actualización por separado, la segunda sobre la tienda
     *   principal.
     */
    public function test_modificarDesdeLaWebConLaReferenciaEnLasDosTiendas(): void
    {
        $tienda = $this->siembra->tiendaPorDefecto();
        $id = $this->producto('Pomelo');

        $this->productos()->modificarProductoTPVWeb([
            'id' => $id, 'iva' => '21.00', 'nombre' => 'Pomelo', 'estado' => 'Activo', 'precioSiva' => '1.00', 'precioCiva' => '1.21',
            'refTienda' => 'POM-3', 'optRefWeb' => '2', 'tiendaWeb' => $tienda, 'tiendaPrincipal' => $tienda, 'codBarras' => [],
        ]);

        self::assertSame('POM-3', $this->unValor("SELECT crefTienda FROM articulosTiendas WHERE idArticulo = {$id}"));
    }

    private function productos(): \ClaseProductos
    {
        return new \ClaseProductos($this->db);
    }

    /** Un producto activo con IVA 21, con su precio y su referencia en la tienda principal. */
    private function producto(string $nombre, array $opciones = []): int
    {
        $id = $this->siembra->articulo($nombre, $opciones + ['iva' => 21]);
        $this->siembra->precioYTienda($id, 1.21, 1.0);

        return $id;
    }

    private function recuentoArticulos(): int
    {
        return (int) $this->unValor('SELECT COUNT(idArticulo) FROM articulos');
    }

    private function unValor(string $sql): ?string
    {
        $fila = $this->db->query($sql)->fetch_row();

        return $fila === null ? null : (string) $fila[0];
    }

    /** @return list<string> */
    private function columna(string $sql): array
    {
        return array_map(static fn (array $f): string => (string) $f[0], $this->db->query($sql)->fetch_all(MYSQLI_NUM));
    }

    /** @return list<string> */
    private function fila(string $sql): array
    {
        return array_map('strval', $this->db->query($sql)->fetch_row() ?? []);
    }
}
