<?php

/**
 * `AlbaranesVentas`, los metodos que solo leen: el albaran real, sus lineas, su desglose
 * de ivas, los pedidos que lleva adjuntos, sus temporales y los estados posibles.
 *
 * La clase redefine `consulta()` con el mismo cuerpo que la de `ClaseVentas` en vez de
 * heredarla, asi que su manejo de error se comprueba aqui otra vez: probar la clase base
 * no dice nada sobre esta copia.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVenta;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class AlbaranesVentasLecturaIntegracionTest extends CasoIntegracion
{
    private \AlbaranesVentas $albaranes;
    private Siembra $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_venta/clases/albaranesVentas.php');
        $this->albaranes = new \AlbaranesVentas($this->db);
        $this->siembra = new Siembra($this->db);
    }

    public function test_datosAlbaran_devuelveLaCabeceraDelAlbaran(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo de lectura de albaran');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 2.0, '2026-01-10');

        $albaran = $this->albaranes->datosAlbaran($idAlbaran);

        self::assertSame('Guardado', $albaran['estado']);
    }

    public function test_datosAlbaran_sinCoincidenciaDevuelveArrayVacio(): void
    {
        self::assertSame([], $this->albaranes->datosAlbaran(999999999));
    }

    public function test_datosAlbaranNum_localizaPorNumeroDeAlbaran(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo por numero');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $numero = $this->albaranes->datosAlbaran($idAlbaran)['Numalbcli'];

        $albaran = $this->albaranes->datosAlbaranNum($numero);

        self::assertSame($idAlbaran, (int) $albaran['id']);
    }

    public function test_productosAlbaran_devuelveTodasSusLineas(): void
    {
        $primero = $this->siembra->articulo('Primer articulo del albaran');
        $segundo = $this->siembra->articulo('Segundo articulo del albaran');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($primero, 2.0, '2026-01-10');
        $this->siembra->lineaAlbaranCliente($idAlbaran, $segundo, 3.0);

        $lineas = $this->albaranes->ProductosAlbaran($idAlbaran);

        self::assertCount(2, $lineas);
    }

    public function test_ivasAlbaran_devuelveElDesglosePorTipo(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo con iva propio', ['iva' => 21.0]);
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 2.0, '2026-01-10');

        $desglose = $this->albaranes->IvasAlbaran($idAlbaran);

        self::assertSame('21', $desglose[0]['iva']);
    }

    public function test_obtenerPedidosAlbaran_devuelveLosPedidosAdjuntados(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo de pedido adjunto');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-05');
        $this->siembra->adjuntarPedidoAAlbaran($idAlbaran, $idPedido);

        $pedidos = $this->albaranes->obtenerPedidosAlbaran($idAlbaran);

        self::assertCount(1, $pedidos['Items']);
        self::assertSame($idPedido, (int) $pedidos['Items'][0]['id']);
    }

    public function test_obtenerPedidosAlbaran_sinAdjuntosDevuelveListaVacia(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo sin pedido adjunto');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');

        $pedidos = $this->albaranes->obtenerPedidosAlbaran($idAlbaran);

        self::assertSame([], $pedidos['Items']);
    }

    public function test_todosTemporal_conNumeroDeAlbaranDevuelveSoloLosSuyos(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo de temporal de albaran');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $this->siembra->albaranTemporal([], [], ['Numalbcli' => $idAlbaran]);
        $this->siembra->albaranTemporal();

        $temporales = $this->albaranes->TodosTemporal($idAlbaran);

        self::assertCount(1, $temporales);
        self::assertSame($idAlbaran, (int) $temporales[0]['Numalbcli']);
    }

    public function test_buscarDatosTemporal_devuelveElBorradorCompleto(): void
    {
        $idTemporal = $this->siembra->albaranTemporal([], [], ['total' => 123.45]);

        $temporal = $this->albaranes->buscarDatosTemporal($idTemporal);

        self::assertSame('123.450000', $temporal['total']);
    }

    public function test_buscarTemporalNumReal_localizaElTemporalDeUnAlbaranGuardado(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo de temporal por numero real');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $idTemporal = $this->siembra->albaranTemporal([], [], ['Numalbcli' => $idAlbaran]);

        $temporal = $this->albaranes->buscarTemporalNumReal($idAlbaran);

        self::assertSame($idTemporal, (int) $temporal['id']);
    }

    public function test_comprobarAlbaranes_cuentaLosDeEseClienteYEstado(): void
    {
        $idCliente = $this->siembra->cliente('Cliente de recuento de albaranes');
        $idArticulo = $this->siembra->articulo('Articulo de recuento');
        $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10', ['idCliente' => $idCliente]);
        $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-11', ['idCliente' => $idCliente]);
        $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-12', [
            'idCliente' => $idCliente,
            'estado'    => 'Procesado',
        ]);

        $recuento = $this->albaranes->ComprobarAlbaranes($idCliente, 'Guardado');

        self::assertSame(2, $recuento['NItems']);
    }

    public function test_albaranClienteGuardado_sinNumeroDevuelveTodosLosGuardadosDelCliente(): void
    {
        $idCliente = $this->siembra->cliente('Cliente de albaranes guardados');
        $idArticulo = $this->siembra->articulo('Articulo de guardados');
        $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10', ['idCliente' => $idCliente]);
        $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-11', ['idCliente' => $idCliente]);

        $respuesta = $this->albaranes->AlbaranClienteGuardado(0, $idCliente);

        self::assertSame(2, $respuesta['Nitems']);
    }

    public function test_albaranClienteGuardado_conNumeroDevuelveSoloEseAlbaran(): void
    {
        $idCliente = $this->siembra->cliente('Cliente de albaran concreto');
        $idArticulo = $this->siembra->articulo('Articulo de albaran concreto');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10', ['idCliente' => $idCliente]);
        $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-11', ['idCliente' => $idCliente]);
        $numero = $this->albaranes->datosAlbaran($idAlbaran)['Numalbcli'];

        $respuesta = $this->albaranes->AlbaranClienteGuardado($numero, $idCliente);

        self::assertSame(1, $respuesta['Nitems']);
        self::assertSame($idAlbaran, (int) $respuesta['datos'][0]['id']);
    }

    public function test_albaranClienteGuardado_deOtroClienteNoLoDevuelve(): void
    {
        $idCliente = $this->siembra->cliente('Cliente propietario');
        $idAjeno = $this->siembra->cliente('Cliente ajeno');
        $idArticulo = $this->siembra->articulo('Articulo de cliente propietario');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10', ['idCliente' => $idCliente]);
        $numero = $this->albaranes->datosAlbaran($idAlbaran)['Numalbcli'];

        $respuesta = $this->albaranes->AlbaranClienteGuardado($numero, $idAjeno);

        self::assertSame(0, $respuesta['Nitems']);
    }

    public function test_todosAlbaranesFiltro_aplicaElFiltroYAnadeElNombreDelCliente(): void
    {
        $idCliente = $this->siembra->cliente('Cliente con nombre en el listado');
        $idArticulo = $this->siembra->articulo('Articulo del listado');
        $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10', ['idCliente' => $idCliente]);

        $respuesta = $this->albaranes->TodosAlbaranesFiltro("WHERE a.idCliente=$idCliente");

        self::assertCount(1, $respuesta['Items']);
        self::assertSame('Cliente con nombre en el listado', $respuesta['Items'][0]['Nombre']);
    }

    public function test_getEstado_devuelveElEstadoDelAlbaran(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo para estado');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10', ['estado' => 'Procesado']);

        self::assertSame('Procesado', $this->albaranes->getEstado($idAlbaran));
    }

    public function test_getEstadosAlbaranes_devuelveLosEstadosPresentesEnLaTabla(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo para estados distintos');
        $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10', ['estado' => 'Guardado']);
        $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-11', ['estado' => 'Procesado']);

        $estados = $this->albaranes->getEstadosAlbaranes();

        self::assertContains('Guardado', $estados);
        self::assertContains('Procesado', $estados);
    }

    public function test_numfacturaDeAlbaran_devuelveLaFacturaQueLoProceso(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo facturado');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $idFactura = $this->siembra->facturarAlbaranCliente($idAlbaran);
        $numero = $this->albaranes->datosAlbaran($idAlbaran)['Numalbcli'];

        $relacion = $this->albaranes->NumfacturaDeAlbaran($numero);

        self::assertSame($idFactura, (int) $relacion['idFactura']);
    }

    public function test_numfacturaDeAlbaran_sinFacturaDevuelveArrayVacio(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo sin facturar');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $numero = $this->albaranes->datosAlbaran($idAlbaran)['Numalbcli'];

        self::assertSame([], $this->albaranes->NumfacturaDeAlbaran($numero));
    }

    public function test_sumarIva_sumaBaseYCuotaDelAlbaran(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo de suma de ivas', ['iva' => 21.0]);
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 2.0, '2026-01-10');
        $numero = $this->albaranes->datosAlbaran($idAlbaran)['Numalbcli'];

        $sumas = $this->albaranes->sumarIva($numero);

        self::assertSame('2.00', $sumas['totalbase']);
    }

    /**
     * Documentado, no corregido: `SUM()` sobre cero filas es NULL, no cero, de modo que un
     * albaran sin desglose devuelve totales nulos en vez de ceros. `albaranesListado.php`
     * los imprime tal cual en las columnas BASE e IVA de cada fila, donde se ven como
     * celdas vacias en vez de como 0,00. Mismo comportamiento que `sumarIvaBases()` de la
     * clase base, ya documentado alli.
     */
    public function test_sumarIva_sinDesgloseDevuelveTotalesNulos(): void
    {
        $sumas = $this->albaranes->sumarIva(999999999);

        self::assertNull($sumas['totalbase']);
    }

    /**
     * `posiblesEstados()` no consulta nada: devuelve un catalogo escrito en el propio
     * metodo. Aun asi se prueba aqui y no como caso unitario, porque el constructor de la
     * clase cuenta filas de `albclit` nada mas construirla: sin conexion no hay instancia,
     * y por tanto ningun metodo de esta clase —ni siquiera este— es alcanzable sin base.
     *
     * El caso fija la escritura exacta de los tres estados, que es lo que el resto del
     * modulo compara para decidir si un albaran se puede editar.
     */
    public function test_posiblesEstados_declaraLosTresEstadosConSuEscrituraExacta(): void
    {
        $estados = array_column($this->albaranes->posiblesEstados(), 'estado');

        self::assertSame(['Guardado', 'Sin Guardar', 'Procesado'], $estados);
    }

    public function test_consulta_conSqlValidoDevuelveElResultado(): void
    {
        self::assertInstanceOf(\mysqli_result::class, $this->albaranes->consulta('SELECT 1 AS uno'));
    }

    /**
     * Defecto (contrato incumplido): la clase entera esta escrita para que un fallo de SQL
     * vuelva como `['error']`/`['consulta']` —cada uno de sus 26 metodos comprueba
     * `gettype($smt) === 'array'`—, pero en este entorno cualificado mysqli lanza excepcion
     * antes de que `consulta()` llegue a construir ese array.
     *
     * Sintoma: `consulta()` con una tabla inexistente deja escapar una
     * `mysqli_sql_exception` en vez de devolver el array de error. Causa raiz: el modo de
     * informe de errores de mysqli en PHP 8.1+ lanza excepcion por defecto, y esta clase no
     * lo desactiva ni la captura; su `else`, copia literal del de `ClaseVentas`, queda
     * inalcanzable. Consecuencia propia de este componente, mas grave que en la clase base:
     * **todo el manejo de error de `AlbaranesVentas` es codigo muerto**, incluidas las
     * ramas de las que depende el guardado para decidir si aborta o continua. Correccion
     * propuesta: decidir en el diseño si se captura la excepcion o se asume el nuevo
     * contrato, y aplicarlo a la vez en las tres clases de venta y en sus llamadores.
     * Evidencia: este test, que fija el comportamiento real como resultado esperado.
     */
    public function test_defecto_consulta_conSqlInvalidoLanzaExcepcionEnVezDeDevolverError(): void
    {
        $this->expectException(\mysqli_sql_exception::class);

        $this->albaranes->consulta('SELECT * FROM tabla_que_no_existe');
    }

    /**
     * Defecto: `TodosAlbaranesFiltro()` acepta un fragmento de SQL ya montado y lo concatena
     * sin defensa propia, de modo que el alcance de la consulta lo decide quien construya
     * ese fragmento, no el metodo.
     *
     * Sintoma: un filtro que ademas de la condicion legitima lleva una alternativa siempre
     * cierta devuelve tambien los albaranes de otros clientes. Causa raiz: la consulta se
     * arma por concatenacion y el parametro entra crudo en el `WHERE`; el metodo no
     * distingue el fragmento que le pasa su llamador de una condicion inyectada. Su unico
     * llamador de hoy, `albaranesListado.php`, lo alimenta con lo que
     * `PluginClasePaginacion` construye a partir de `$_GET['buscar']` y `$_GET['filtro']`:
     * esa clase no escapa la entrada, solo la mutila —sustituye comillas dobles, parentesis
     * y guiones por espacios—, de modo que la explotabilidad desde la URL depende de esa
     * mutilacion y no de ninguna defensa de este metodo. Correccion propuesta: que el
     * metodo reciba condiciones y valores por separado y los parametrice, en vez de un
     * fragmento de SQL. Evidencia: este test.
     */
    public function test_defecto_todosAlbaranesFiltro_aceptaUnFiltroQueAmpliaSuAlcance(): void
    {
        $idCliente = $this->siembra->cliente('Cliente del filtro legitimo');
        $idAjeno = $this->siembra->cliente('Cliente de otro albaran');
        $idArticulo = $this->siembra->articulo('Articulo del filtro');
        $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10', ['idCliente' => $idCliente]);
        $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-11', ['idCliente' => $idAjeno]);

        $legitimo = $this->albaranes->TodosAlbaranesFiltro("WHERE a.idCliente=$idCliente");
        $ampliado = $this->albaranes->TodosAlbaranesFiltro("WHERE a.idCliente=$idCliente OR 1=1");

        self::assertGreaterThan(
            count($legitimo['Items']),
            count($ampliado['Items']),
            'El filtro ampliado debe devolver mas albaranes que el legitimo: el metodo no acota su alcance.'
        );
    }
}
