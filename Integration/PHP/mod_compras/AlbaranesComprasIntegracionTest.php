<?php

/**
 * `AlbaranesCompras`, la clase del albarán de proveedor: su temporal, su guardado —la cabecera, las
 * líneas activas, el desglose, los pedidos servidos y las existencias—, la modificación de uno guardado,
 * su borrado, sus lecturas y listados, sus estados y la comprobación de temporales.
 *
 * La clase incluye la común y la de existencias con rutas relativas a la carpeta del módulo: se carga
 * desde ella. Recibe la conexión del caso, y la de existencias usa la que el caso presta a la clase base,
 * en la misma transacción. `guardarAlbaran()` lee la sesión y el formulario: el caso los pone y los
 * quita.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModCompras;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class AlbaranesComprasIntegracionTest extends CasoIntegracion
{
    protected bool $compartirConexionConElProducto = true;

    private \AlbaranesCompras $albaranes;

    private Siembra $siembra;

    private int $proveedor;

    private int $tienda;

    private int $usuario;

    protected function setUp(): void
    {
        parent::setUp();
        $cwd = (string) getcwd();
        chdir(RUTA_TPVFOX . '/modulos/mod_compras');
        try {
            $this->incluirTPVFox('/modulos/mod_compras/clases/albaranesCompras.php');
        } finally {
            chdir($cwd);
        }
        $this->albaranes = new \AlbaranesCompras($this->db);
        $this->siembra = new Siembra($this->db);
        $this->proveedor = $this->siembra->proveedor('Proveedor del albarán');
        $this->tienda = $this->siembra->tiendaPorDefecto();
        $this->usuario = $this->siembra->usuarioPorDefecto();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        parent::tearDown();
    }

    /** @comportamiento Un temporal nuevo guarda su cabecera, sus productos y sus pedidos como texto JSON, y su número del proveedor; modificarlo los cambia. */
    public function test_elTemporalSeGuardaYSeModifica(): void
    {
        $nuevo = $this->albaranes->insertarDatosAlbaranTemporal($this->usuario, $this->tienda, 'Sin guardar', '2026-02-03', [$this->linea(5)], $this->proveedor, [['NumAdjunto' => '12']], 'A-7');
        $id = (int) $nuevo['id'];
        $antes = $this->albaranes->buscarAlbaranTemporal($id);
        $modificado = $this->albaranes->modificarDatosAlbaranTemporal($this->usuario, $this->tienda, 'Sin guardar', '2026-02-04', $id, [$this->linea(5), $this->linea(6, ['nfila' => 2])], [], 'A-8');
        $despues = $this->albaranes->buscarAlbaranTemporal($id);

        self::assertSame(['Sin guardar', 'A-7', '[{"NumAdjunto":"12"}]', (string) $this->proveedor], [$antes['estadoAlbPro'], $antes['Su_numero'], $antes['Pedidos'], $antes['idProveedor']]);
        self::assertCount(1, json_decode($antes['Productos'], true));
        self::assertSame($id, $modificado['idTemporal']);
        self::assertSame(['A-8', '2026-02-04 00:00:00', '[]'], [$despues['Su_numero'], $despues['Fecha'], $despues['Pedidos']]);
        self::assertCount(2, json_decode($despues['Productos'], true));
    }

    /** @comportamiento Un temporal se relaciona con su albarán guardado, guarda sus totales, se busca —uno que no está, el error— y se lista con el nombre del proveedor. */
    public function test_elTemporalSeRelacionaSeBuscaYSeLista(): void
    {
        $id = (int) $this->albaranes->insertarDatosAlbaranTemporal($this->usuario, $this->tienda, 'Sin guardar', '2026-02-03', [], $this->proveedor, [], '')['id'];

        $this->albaranes->addNumRealTemporal($id, 777);
        $this->albaranes->modTotales($id, '12.10', '2.10');
        $temporal = $this->albaranes->buscarAlbaranTemporal($id);
        $noEsta = $this->albaranes->buscarAlbaranTemporal(0);
        $deUno = $this->albaranes->TodosTemporal(777);

        self::assertSame(['777', '12.100000', '2.10'], [$temporal['Numalbpro'], $temporal['total'], $temporal['total_ivas']]);
        self::assertStringStartsWith('No se encontro temporal', $noEsta['error']);
        self::assertSame([[(string) 777, (string) $id, (string) $this->proveedor, '12.100000', null]], array_map('array_values', $deUno));
    }

    /** @comportamiento Eliminar un temporal lo quita por su id, o por su albarán guardado. */
    public function test_eliminarUnTemporalPorSuIdOPorSuAlbaran(): void
    {
        $uno = (int) $this->albaranes->insertarDatosAlbaranTemporal($this->usuario, $this->tienda, 'Sin guardar', '2026-02-03', [], $this->proveedor, [], '')['id'];
        $otro = (int) $this->albaranes->insertarDatosAlbaranTemporal($this->usuario, $this->tienda, 'Sin guardar', '2026-02-03', [], $this->proveedor, [], '')['id'];
        $this->albaranes->addNumRealTemporal($otro, 778);

        $porId = $this->albaranes->eliminarTemporal($uno);
        $this->albaranes->EliminarRegistroTemporal(0, 778);

        self::assertSame(1, $porId['valores_insert']);
        self::assertSame(0, $this->cuantos('albproltemporales', "id IN ({$uno}, {$otro})"));
    }

    /** @comportamiento Comprobar los temporales de un albarán: sin ninguno, nada; con uno, su id; con dos, o con uno distinto del que se edita, el aviso de duplicidad. */
    public function test_comprobarLosTemporalesDeUnAlbaran(): void
    {
        $uno = (int) $this->albaranes->insertarDatosAlbaranTemporal($this->usuario, $this->tienda, 'Sin guardar', '2026-02-03', [], $this->proveedor, [], '')['id'];
        $this->albaranes->addNumRealTemporal($uno, 779);

        $ninguno = $this->albaranes->comprobarTemporalIdAlbpro(780);
        $conUno = $this->albaranes->comprobarTemporalIdAlbpro(779);
        $distinto = $this->albaranes->comprobarTemporalIdAlbpro(779, $uno + 1000);
        $dos = (int) $this->albaranes->insertarDatosAlbaranTemporal($this->usuario, $this->tienda, 'Sin guardar', '2026-02-03', [], $this->proveedor, [], '')['id'];
        $this->albaranes->addNumRealTemporal($dos, 779);
        $conDos = $this->albaranes->comprobarTemporalIdAlbpro(779);

        self::assertSame([], $ninguno);
        self::assertSame(['idTemporal' => (string) $uno], $conUno);
        self::assertStringContainsString('no coincide el idtemporal', $distinto[0]['mensaje']);
        self::assertStringContainsString('Hay mas de un temporal con el mismo numero albaran', $conDos[0]['mensaje']);
    }

    /**
     * @comportamiento Guardar un albarán nuevo graba su cabecera «Guardado» con su número igual a su id,
     *   sus líneas activas numeradas, su desglose y sus pedidos activos, y sube las existencias de sus
     *   líneas en la tienda; sin vencimiento, el vencimiento cero.
     */
    public function test_guardarUnAlbaranNuevo(): void
    {
        $pan = $this->siembra->articulo('Pan del albarán', ['iva' => 4]);
        $vino = $this->siembra->articulo('Vino del albarán', ['iva' => 21]);
        $quitado = $this->siembra->articulo('Producto quitado del albarán');
        $this->siembra->existenciaRegistrada($pan, 2);

        $guardado = $this->albaranes->AddAlbaranGuardado($this->datos([
            $this->linea($pan, ['nunidades' => 5, 'ncant' => 5, 'ultimoCoste' => 2]),
            $this->linea($vino, ['iva' => 21, 'nunidades' => 1, 'ncant' => 1, 'ultimoCoste' => 10, 'nfila' => 2]),
            $this->linea($quitado, ['estado' => 'Eliminado', 'nfila' => 3]),
        ], [['estado' => 'activo', 'idAdjunto' => $servido = $this->pedido(12), 'NumAdjunto' => 12], ['estado' => 'Eliminado', 'idAdjunto' => $this->pedido(13), 'NumAdjunto' => 13]]), 0);
        $id = (int) $guardado['id'];

        $cabecera = $this->fila("SELECT Numalbpro, estado, Su_numero, FechaVencimiento, total, total_siniva FROM albprot WHERE id = {$id}");
        self::assertSame([(string) $id, 'Guardado', 'A-7', '0000-00-00', '22.50', '20.000000'], array_values($cabecera));
        self::assertSame(2, $guardado['n_productos_insertados']);
        self::assertSame([[(string) $pan, '1'], [(string) $vino, '2']], $this->db->query("SELECT idArticulo, nfila FROM albprolinea WHERE idalbpro = {$id} ORDER BY nfila")->fetch_all());
        self::assertSame(2, $this->cuantos('albproIva', "idalbpro = {$id}"));
        self::assertSame([[(string) $servido, '12']], $this->db->query("SELECT idPedido, numPedido FROM pedproAlb WHERE idAlbaran = {$id}")->fetch_all());
        self::assertSame(['7.000000', '1.000000'], [$this->existencias($pan), $this->existencias($vino)]);
        self::assertNull($this->existencias($quitado));
    }

    /** @comportamiento Guardar con el id de un albarán cambia su cabecera —con quién y cuándo— y escribe sus líneas. */
    public function test_guardarSobreUnAlbaranExistente(): void
    {
        $pan = $this->siembra->articulo('Pan del albarán');
        $id = (int) $this->albaranes->AddAlbaranGuardado($this->datos([]), 0)['id'];

        $this->albaranes->AddAlbaranGuardado($this->datos([$this->linea($pan)], [], ['suNumero' => 'A-9', 'fechaVenci' => '2026-03-03']), $id);

        $cabecera = $this->fila("SELECT Su_numero, FechaVencimiento, modify_by, fechaModificacion IS NOT NULL FROM albprot WHERE id = {$id}");
        self::assertSame(['A-9', '2026-03-03', (string) $this->usuario, '1'], array_values($cabecera));
        self::assertSame(1, $this->cuantos('albprolinea', "idalbpro = {$id}"));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/clases/albaranesCompras.php:212-345
     *
     * @group defecto
     * @group seguridad
     * @group alto
     *
     * @que-ocurre-hoy Una línea cuya descripción lleva comillas dobles rompe la sentencia de las líneas: el
     *   albarán no se guarda entero.
     * @que-deberia-ocurrir Que la descripción se guarde tal como es.
     * @por-que-ocurre Los datos de las líneas se pegan a la sentencia.
     * @como-deberia-funcionar Pasarlos como parámetros.
     */
    public function test_unaDescripcionConComillasSeGuarda(): void
    {
        $pan = $this->siembra->articulo('Pan del albarán');

        $guardado = $this->albaranes->AddAlbaranGuardado($this->datos([$this->linea($pan, ['cdetalle' => 'Pan "de pueblo"'])]), 0);

        self::assertSame('Pan "de pueblo"', $this->fila('SELECT cdetalle FROM albprolinea WHERE idalbpro = ' . (int) $guardado['id'])['cdetalle'] ?? null);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/clases/albaranesCompras.php:212-345
     *
     * @group defecto
     * @group integridad
     * @group alto
     *
     * @que-ocurre-hoy Si una parte del guardado falla —aquí, el desglose—, la cabecera, las líneas y las
     *   existencias ya escritas se quedan: el albarán queda a medias y el almacén sumado.
     * @que-deberia-ocurrir Que el guardado se haga entero o no deje nada.
     * @por-que-ocurre Cada sentencia se ejecuta por su cuenta, sin transacción, y las existencias se suben
     *   antes de escribir las líneas.
     * @como-deberia-funcionar Guardar la cabecera, las líneas, el desglose, los pedidos y las existencias en
     *   una transacción.
     */
    public function test_unGuardadoQueFallaNoDejaElAlbaranNiLasExistencias(): void
    {
        $pan = $this->siembra->articulo('Pan del albarán');
        $antes = $this->cuantos('albprot', '1 = 1');
        $this->db->query('SAVEPOINT antes_del_guardado');

        try {
            $this->albaranes->AddAlbaranGuardado($this->datos([$this->linea($pan, ['nunidades' => 5])], [], ['DatosTotales' => ['desglose' => ['x' => ['iva' => 0, 'base' => 0]]]]), 0);
        } catch (\mysqli_sql_exception) {
        }

        self::assertSame([$antes, null], [$this->cuantos('albprot', '1 = 1'), $this->existencias($pan)]);
    }

    /**
     * @comportamiento Guardar el albarán de un temporal: con la sesión y el temporal, recalcula los totales,
     *   lo guarda, deja el coste de la compra en el proveedor y quita el temporal; con la sesión sin su tienda
     *   ni su usuario, o sin temporal, los avisos; sin productos, el aviso y el temporal se queda.
     */
    public function test_guardarElAlbaranDeUnTemporal(): void
    {
        $pan = $this->siembra->articulo('Pan del temporal', ['iva' => 4]);
        $this->siembra->articuloProveedor($pan, $this->proveedor, 1.5, ['fecha' => '2026-01-01']);
        $temporal = (int) $this->albaranes->insertarDatosAlbaranTemporal($this->usuario, $this->tienda, 'Sin guardar', '2026-02-03', [$this->linea($pan, ['nunidades' => 4, 'ncant' => 4, 'ultimoCoste' => 2, 'CosteAnt' => 1.5])], $this->proveedor, [], 'A-7')['id'];
        $vacio = (int) $this->albaranes->insertarDatosAlbaranTemporal($this->usuario, $this->tienda, 'Sin guardar', '2026-02-03', [$this->linea($pan, ['estado' => 'Eliminado'])], $this->proveedor, [], '')['id'];

        $sinSesion = $this->guardar($temporal, false);
        $sinTemporal = $this->guardar(null);
        $errores = $this->guardar($temporal);
        $sinProductos = $this->guardar($vacio);

        self::assertSame('ERROR NO HAY DATOS DE SESIÓN!', $sinSesion[0]['mensaje']);
        self::assertSame('No enviarte que albaran temporal es no puede modificarlo.', $sinTemporal[0]['mensaje']);
        self::assertSame([], $errores);
        $guardado = $this->fila("SELECT id, total, Su_numero FROM albprot WHERE idProveedor = {$this->proveedor} AND Su_numero = 'A-7'");
        self::assertSame(['8.32', 'A-7'], [$guardado['total'], $guardado['Su_numero']]);
        self::assertSame(0, $this->cuantos('albproltemporales', "id = {$temporal}"));
        self::assertSame('2.000000', $this->fila("SELECT coste FROM articulosProveedores WHERE idArticulo = {$pan}")['coste']);
        self::assertStringContainsString('No permite guardar un albaran sin productos', $sinProductos[0]['mensaje']);
        self::assertSame(1, $this->cuantos('albproltemporales', "id = {$vacio}"));
    }

    /**
     * @comportamiento Modificar un albarán guardado borra sus líneas —restando de las existencias las que
     *   tenía—, su desglose y sus pedidos, y lo guarda de nuevo: las existencias quedan como las del nuevo.
     *   Las líneas se borran primero, y los borrados siguientes ya no las encuentran.
     */
    public function test_modificarUnAlbaranGuardadoDejaLasExistenciasDelNuevo(): void
    {
        $pan = $this->siembra->articulo('Pan del albarán modificado');
        $id = (int) $this->albaranes->AddAlbaranGuardado($this->datos([$this->linea($pan, ['nunidades' => 5, 'ncant' => 5])]), 0)['id'];
        $temporal = (int) $this->albaranes->insertarDatosAlbaranTemporal($this->usuario, $this->tienda, 'Sin guardar', '2026-02-03', [$this->linea($pan, ['nunidades' => 6, 'ncant' => 6])], $this->proveedor, [], 'A-7')['id'];
        $this->albaranes->addNumRealTemporal($temporal, $id);

        $this->guardar($temporal);

        self::assertSame('6.000000', $this->existencias($pan));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/clases/albaranesCompras.php:197-205, :296
     *
     * @group defecto
     * @group integridad
     * @group medio
     *
     * @que-ocurre-hoy Guardar un albarán suma sus unidades a las existencias, y borrar sus líneas resta su
     *   cantidad: si una línea lleva distinta cantidad que unidades, las existencias no vuelven a donde
     *   estaban.
     * @que-deberia-ocurrir Que borrar reste lo que guardar sumó.
     * @por-que-ocurre El guardado suma `nunidades` y el borrado resta `ncant`.
     * @como-deberia-funcionar Usar el mismo campo en los dos.
     */
    public function test_borrarLasLineasDeUnAlbaranRestaLoQueSumoGuardarlo(): void
    {
        $pan = $this->siembra->articulo('Pan de cantidad y unidades');
        $id = (int) $this->albaranes->AddAlbaranGuardado($this->datos([$this->linea($pan, ['nunidades' => 3, 'ncant' => 1])]), 0)['id'];

        $this->albaranes->eliminarAlbaranTablas($id, 'albprolinea');

        self::assertSame('0.000000', $this->existencias($pan));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/clases/albaranesCompras.php:813-834
     *
     * @group defecto
     * @group errores
     * @group medio
     *
     * @que-ocurre-hoy Los avisos del coste de la compra —por ejemplo, que el proveedor ya tenía un coste
     *   más reciente— no llegan a quien guarda el albarán.
     * @que-deberia-ocurrir Que se devuelvan con los demás avisos del guardado.
     * @por-que-ocurre El guardado busca una clave `error` en lo que devuelve el coste, que es una lista de
     *   avisos.
     * @como-deberia-funcionar Añadir los avisos del coste a los del guardado.
     */
    public function test_losAvisosDelCosteLleganAlGuardar(): void
    {
        $pan = $this->siembra->articulo('Pan con coste reciente');
        $this->siembra->articuloProveedor($pan, $this->proveedor, 1.5, ['fecha' => '2026-03-01']);
        $temporal = (int) $this->albaranes->insertarDatosAlbaranTemporal($this->usuario, $this->tienda, 'Sin guardar', '2026-02-03', [$this->linea($pan, ['ultimoCoste' => 2, 'CosteAnt' => 1.5])], $this->proveedor, [], 'A-7')['id'];

        $errores = $this->guardar($temporal);

        self::assertNotSame([], $errores);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/clases/albaranesCompras.php:212-260; modulos/mod_compras/clases/ClaseCompras.php:20-36
     *
     * @group defecto
     * @group errores
     * @group medio
     *
     * @que-ocurre-hoy Un número del proveedor más largo que su columna hace que la base rechace la cabecera,
     *   y el guardado termina en un error de PHP sin aviso.
     * @que-deberia-ocurrir Que el número se compruebe antes, o que el rechazo vuelva como aviso.
     * @por-que-ocurre No se comprueba la longitud, y el rechazo de la base llega como excepción.
     * @como-deberia-funcionar Comprobar la longitud del número del proveedor y devolver el error.
     */
    public function test_unNumeroDelProveedorDemasiadoLargoSeAvisa(): void
    {
        $respuesta = null;
        try {
            $respuesta = $this->albaranes->AddAlbaranGuardado($this->datos([], [], ['suNumero' => str_repeat('9', 21)]), 0);
        } catch (\mysqli_sql_exception) {
        }

        self::assertArrayHasKey('error', (array) $respuesta);
    }

    /** @comportamiento Un número del proveedor ya guardado para el mismo proveedor se detecta; para otro, no. */
    public function test_unNumeroDelProveedorRepetidoSeDetecta(): void
    {
        $this->albaranes->AddAlbaranGuardado($this->datos([], [], ['suNumero' => 'A-77']), 0);

        self::assertTrue($this->albaranes->ExisteSuNumeroProveedor('A-77', $this->proveedor));
        self::assertFalse($this->albaranes->ExisteSuNumeroProveedor('A-77', $this->siembra->proveedor('Otro proveedor')));
        self::assertFalse($this->albaranes->ExisteSuNumeroProveedor('A-78', $this->proveedor));
    }

    /**
     * @comportamiento Un albarán se lee con sus productos —con los nombres del formulario— y sus pedidos
     *   —también con los datos de cada pedido—, su desglose y su suma; un número de factura de un albarán
     *   se lee de su relación.
     */
    public function test_leerUnAlbaranConSusProductosYSusPedidos(): void
    {
        $pan = $this->siembra->articulo('Pan leído', ['iva' => 4]);
        $pedido = $this->pedido(12);
        $id = (int) $this->albaranes->AddAlbaranGuardado($this->datos([$this->linea($pan, ['ultimoCoste' => 2, 'nunidades' => 5, 'ncant' => 5])], [['estado' => 'activo', 'idAdjunto' => $pedido, 'NumAdjunto' => 12]]), 0)['id'];
        $this->db->query("INSERT INTO facprot (Numfacpro, Su_num_factura, Fecha, idTienda, idUsuario, idProveedor, estado, total_siniva, total) VALUES (31, 'F-31', '2026-02-10', {$this->tienda}, {$this->usuario}, {$this->proveedor}, 'Guardado', 0, 0)");
        $factura = (int) $this->db->insert_id;
        $this->db->query("INSERT INTO albprofac (idFactura, numFactura, idAlbaran, numAlbaran) VALUES ({$factura}, 31, {$id}, {$id})");

        $albaran = $this->albaranes->GetAlbaran($id);
        $completos = $this->albaranes->PedidosAlbaranes($id, 'OK');

        self::assertSame([(string) $pan, '2.0000', 'Activo'], [$albaran['Productos'][0]['idArticulo'], $albaran['Productos'][0]['ultimoCoste'], $albaran['Productos'][0]['estado']]);
        self::assertSame((string) $pedido, $albaran['Pedidos'][0]['idPedido']);
        self::assertSame(['12', 'Guardado'], [$completos[0]['Numpedpro'], $completos[0]['estado']]);
        self::assertCount(1, $this->albaranes->IvasAlbaran($id));
        self::assertEqualsWithDelta([0.4, 10.0], array_map('floatval', array_values($this->albaranes->sumarIva($id))), 0.001);
        self::assertSame('31', $this->albaranes->NumfacturaDeAlbaran($id)['numFactura']);
        self::assertSame((string) $id, $this->albaranes->datosAlbaran($id)['id']);
    }

    /** @comportamiento Los albaranes se cuentan y se listan con su proveedor según la condición que llega. */
    public function test_contarYListarLosAlbaranes(): void
    {
        $this->albaranes->AddAlbaranGuardado($this->datos([]), 0);
        $this->albaranes->AddAlbaranGuardado($this->datos([]), 0);
        $limite = "WHERE a.idProveedor = {$this->proveedor} ORDER BY a.id";

        $cuenta = $this->albaranes->CuentaTodosAlbaranesLimite($limite);
        $lista = $this->albaranes->TodosAlbaranesLimite($limite);

        self::assertSame('2', (string) $cuenta['contador']);
        self::assertCount(2, $lista['Items']);
        self::assertSame(['Guardado', (string) $this->proveedor], [$lista['Items'][0]['estado'], $lista['Items'][0]['idProveedor']]);
    }

    /** @comportamiento Los albaranes de un proveedor se buscan por estado —uno, varios o con su número—, con su total sin IVA del desglose. */
    public function test_buscarLosAlbaranesDeUnProveedorPorEstado(): void
    {
        $pan = $this->siembra->articulo('Pan buscado', ['iva' => 4]);
        $uno = (int) $this->albaranes->AddAlbaranGuardado($this->datos([$this->linea($pan, ['ultimoCoste' => 2, 'nunidades' => 5, 'ncant' => 5])]), 0)['id'];
        $otro = (int) $this->albaranes->AddAlbaranGuardado($this->datos([$this->linea($pan, ['ultimoCoste' => 1, 'nunidades' => 5, 'ncant' => 5])]), 0)['id'];

        $todos = $this->albaranes->buscarAlbaranProveedorPorEstado($this->proveedor, 0, 'Guardado');
        $deUno = $this->albaranes->buscarAlbaranProveedorPorEstado($this->proveedor, $uno, 'Guardado');
        $guardados = $this->albaranes->albaranesProveedorGuardado($this->proveedor, 'Guardado');

        self::assertSame(2, $todos['Nitems']);
        self::assertSame([1, (string) $uno, '10'], [$deUno['Nitems'], $deUno['datos'][0]['Numalbpro'], rtrim(rtrim((string) $deUno['datos'][0]['totalSiva'], '0'), '.')]);
        self::assertEqualsCanonicalizing([(string) $uno, (string) $otro], array_column($guardados, 'id'));
    }

    /** @comportamiento El estado de un albarán se cambia; los estados que hay se listan; el número del proveedor, la fecha, la forma de pago y el vencimiento se cambian juntos. */
    public function test_cambiarElEstadoYLosDatosDeUnAlbaran(): void
    {
        $id = (int) $this->albaranes->AddAlbaranGuardado($this->datos([]), 0)['id'];

        $this->albaranes->cambiarEstadoAlbaran($id, 'Validado');
        $estados = $this->albaranes->getEstadosAlbaranes();
        $this->albaranes->modEstadoAlbaran($id, 'Exportado');
        $this->albaranes->modFechaNumero($id, 'A-99', '2026-02-05', '2', '2026-03-05');

        self::assertContains('Validado', $estados);
        self::assertSame(['Exportado', 'A-99', '2026-02-05 00:00:00', '2', '2026-03-05'], array_values($this->fila("SELECT estado, Su_numero, Fecha, formaPago, FechaVencimiento FROM albprot WHERE id = {$id}")));
        self::assertSame('0000-00-00', $this->albaranes->ComprobarFecha(' '));
        self::assertSame('2026-03-05', $this->albaranes->ComprobarFecha('2026-03-05'));
    }

    /** @comportamiento Borrar una tabla de un albarán —sus líneas, su desglose o sus pedidos— quita solo esa, y resta sus líneas de las existencias. */
    public function test_borrarUnaTablaDeUnAlbaran(): void
    {
        $pan = $this->siembra->articulo('Pan borrado', ['iva' => 4]);
        $id = (int) $this->albaranes->AddAlbaranGuardado($this->datos([$this->linea($pan, ['nunidades' => 5, 'ncant' => 5])], [['estado' => 'activo', 'idAdjunto' => $this->pedido(12), 'NumAdjunto' => 12]]), 0)['id'];

        $this->albaranes->eliminarAlbaranTablas($id, 'pedproAlb');

        self::assertSame([1, 1, 1, 0], [$this->cuantos('albprot', "id = {$id}"), $this->cuantos('albprolinea', "idalbpro = {$id}"), $this->cuantos('albproIva', "idalbpro = {$id}"), $this->cuantos('pedproAlb', "idAlbaran = {$id}")]);
        self::assertSame('0.000000', $this->existencias($pan));
    }

    /** @param array<string, mixed> $cambios */
    private function linea(int $idArticulo, array $cambios = []): array
    {
        return $cambios + ['idArticulo' => $idArticulo, 'cref' => 'REF-' . $idArticulo, 'ccodbar' => '', 'cdetalle' => 'Producto ' . $idArticulo, 'ref_prov' => '', 'ncant' => 1, 'nunidades' => 1, 'ultimoCoste' => 1, 'iva' => 4, 'nfila' => 1, 'estado' => 'Activo'];
    }

    /**
     * Los datos que el guardado recibe, con los totales recalculados de las líneas.
     *
     * @param list<array<string, mixed>> $lineas
     * @param list<array<string, mixed>> $pedidos
     * @param array<string, mixed> $cambios
     */
    private function datos(array $lineas, array $pedidos = [], array $cambios = []): array
    {
        $totales = $this->albaranes->recalculoTotales($lineas);

        return $cambios + ['fecha' => '2026-02-03', 'idTienda' => $this->tienda, 'idUsuario' => $this->usuario, 'idProveedor' => $this->proveedor, 'estado' => 'Guardado', 'total' => $totales['total'], 'total_siniva' => (float) $totales['total'] - (float) $totales['subivas'], 'DatosTotales' => $totales, 'productos' => json_encode($lineas), 'pedidos' => json_encode($pedidos), 'suNumero' => 'A-7', 'formaPago' => '', 'fechaVenci' => ''];
    }

    /** @return list<array<string, string>> Los avisos de `guardarAlbaran()` con la sesión y el formulario del caso. */
    private function guardar(?int $temporal, bool $conSesion = true): array
    {
        $_SESSION = $conSesion ? ['tiendaTpv' => ['idTienda' => $this->tienda], 'usuarioTpv' => ['id' => $this->usuario]] : ['tiendaTpv' => [], 'usuarioTpv' => []];
        $_POST = ['fecha' => '03-02-2026', 'estado' => 'Nuevo', 'suNumero' => 'A-7'] + ($temporal === null ? [] : ['idTemporal' => $temporal]);
        $_GET = [];

        return $this->albaranes->guardarAlbaran();
    }

    private function pedido(int $numero): int
    {
        $this->db->query("INSERT INTO pedprot (Numpedpro, Numtemp_pedpro, Fecha, idTienda, idUsuario, idProveedor, estado, total_siniva, total) VALUES ({$numero}, 0, '2026-02-01', {$this->tienda}, {$this->usuario}, {$this->proveedor}, 'Guardado', 0, 0)");

        return (int) $this->db->insert_id;
    }

    private function existencias(int $idArticulo): ?string
    {
        $fila = $this->db->query("SELECT stockOn FROM articulosStocks WHERE idArticulo = {$idArticulo} AND idTienda = {$this->tienda}")->fetch_row();

        return $fila === null ? null : (string) $fila[0];
    }

    private function cuantos(string $tabla, string $donde): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM {$tabla} WHERE {$donde}")->fetch_row()[0];
    }

    /** @return array<string, string>|null */
    private function fila(string $sql): ?array
    {
        return $this->db->query($sql)->fetch_assoc();
    }
}
