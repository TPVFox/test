<?php

/**
 * `FacturasVentas`, los metodos que solo leen: la cabecera de la factura, sus lineas, su
 * desglose de impuestos, los albaranes que factura, sus temporales, los estados y la
 * navegacion entre documentos de un cliente.
 *
 * A diferencia de `PedidosVentas`, esta clase redefine `consulta()` en lugar de heredarla
 * de `ClaseVentas`, como hace `AlbaranesVentas`. Lo que aqui se comprueba de la lectura
 * vale por tanto solo para esta clase.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVenta;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class FacturasVentasLecturaIntegracionTest extends CasoIntegracion
{
    private \FacturasVentas $facturas;
    private Siembra $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_venta/clases/facturasVentas.php');
        $this->facturas = new \FacturasVentas($this->db);
        $this->siembra = new Siembra($this->db);
    }

    // --- Cabecera y contenido de la factura ----------------------------------

    public function test_datosFactura_devuelveLaCabeceraDeLaFactura(): void
    {
        $idFactura = $this->facturaSembrada('Articulo de lectura de factura');

        $factura = $this->facturas->datosFactura($idFactura);

        self::assertSame('Guardado', $factura['estado']);
        self::assertSame($this->siembra->clientePorDefecto(), (int) $factura['idCliente']);
    }

    public function test_datosFactura_sinCoincidenciaDevuelveArrayVacio(): void
    {
        self::assertSame([], $this->facturas->datosFactura(999999999));
    }

    public function test_productosFactura_devuelveTodasSusLineas(): void
    {
        $primero = $this->siembra->articulo('Primer articulo de la factura');
        $segundo = $this->siembra->articulo('Segundo articulo de la factura');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($primero, 2.0, '2026-01-10');
        $this->siembra->lineaAlbaranCliente($idAlbaran, $segundo, 3.0);
        $idFactura = $this->siembra->facturarAlbaranCliente($idAlbaran);

        self::assertCount(2, $this->facturas->ProductosFactura($idFactura));
    }

    public function test_productosFactura_sinLineasDevuelveListaVacia(): void
    {
        self::assertSame([], $this->facturas->ProductosFactura(999999999));
    }

    public function test_ivasFactura_devuelveElDesglosePorTipo(): void
    {
        $idFactura = $this->facturaSembrada('Articulo con iva propio de factura', ['iva' => 21.0]);

        $desglose = $this->facturas->IvasFactura($idFactura);

        self::assertCount(1, $desglose);
        self::assertSame('21', $desglose[0]['iva']);
    }

    public function test_ivasFactura_conDosTiposDevuelveUnaFilaPorCadaUno(): void
    {
        $reducido = $this->siembra->articulo('Articulo de factura al diez', ['iva' => 10.0]);
        $general = $this->siembra->articulo('Articulo de factura al veintiuno', ['iva' => 21.0]);
        $idAlbaran = $this->siembra->ventaAlbaranCliente($reducido, 1.0, '2026-01-10');
        $this->siembra->lineaAlbaranCliente($idAlbaran, $general, 1.0);
        $idFactura = $this->siembra->facturarAlbaranCliente($idAlbaran);

        self::assertCount(2, $this->facturas->IvasFactura($idFactura));
    }

    public function test_sumarIva_devuelveLaBaseYLaCuotaSumadasDelDocumento(): void
    {
        $idFactura = $this->facturaSembrada('Articulo para sumar iva de factura', ['iva' => 21.0]);
        $numero = (int) $this->facturas->datosFactura($idFactura)['Numfaccli'];

        $suma = $this->facturas->sumarIva($numero);

        self::assertSame('1.00', $suma['totalbase']);
        self::assertSame('0.21', $suma['importeIva']);
    }

    public function test_sumarIva_deUnaFacturaSinDesgloseDevuelveSumasNulas(): void
    {
        $suma = $this->facturas->sumarIva(999999999);

        self::assertNull($suma['totalbase']);
        self::assertNull($suma['importeIva']);
    }

    // --- Los albaranes que la factura factura --------------------------------

    public function test_albaranesFactura_devuelveLasFilasDeEnlace(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo de enlace de factura');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $idFactura = $this->siembra->facturarAlbaranCliente($idAlbaran);

        $enlaces = $this->facturas->AlbaranesFactura($idFactura);

        self::assertCount(1, $enlaces);
        self::assertSame($idAlbaran, (int) $enlaces[0]['idAlbaran']);
    }

    public function test_obtenerAlbaranesFactura_devuelveLosAlbaranesConSusDatos(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo de albaran facturado');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $idFactura = $this->siembra->facturarAlbaranCliente($idAlbaran);

        $albaranes = $this->facturas->obtenerAlbaranesFactura($idFactura);

        self::assertCount(1, $albaranes['Items']);
        self::assertSame($idAlbaran, (int) $albaranes['Items'][0]['id']);
        self::assertSame('Procesado', $albaranes['Items'][0]['estado']);
    }

    public function test_obtenerAlbaranesFactura_deUnaFacturaSinAlbaranesDevuelveListaVacia(): void
    {
        self::assertSame([], $this->facturas->obtenerAlbaranesFactura(999999999)['Items']);
    }

    /**
     * La consulta que sirve los albaranes de una factura devuelve la fecha bajo la clave
     * `Fecha`, mientras que la composicion del documento impreso la busca bajo `fecha`.
     * El dato viaja, pero con un nombre que su unico consumidor no lee.
     */
    public function test_defecto_laFechaDelAlbaranViajaConUnaClaveDistintaDeLaQueSeLee(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo de albaran con fecha');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $idFactura = $this->siembra->facturarAlbaranCliente($idAlbaran);

        $albaran = $this->facturas->obtenerAlbaranesFactura($idFactura)['Items'][0];

        self::assertArrayHasKey('Fecha', $albaran, 'La consulta la nombra con mayuscula...');
        self::assertArrayNotHasKey('fecha', $albaran, '... y la impresion la busca con minuscula.');
    }

    // --- Listado y filtro -----------------------------------------------------

    public function test_todosFacturaFiltro_devuelveLaFacturaConElNombreDelCliente(): void
    {
        $idCliente = $this->siembra->cliente('Cliente con factura en el listado');
        $idArticulo = $this->siembra->articulo('Articulo del listado de facturas');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10', ['idCliente' => $idCliente]);
        $idFactura = $this->siembra->facturarAlbaranCliente($idAlbaran);

        $listado = $this->facturas->TodosFacturaFiltro("WHERE a.id = $idFactura");

        self::assertCount(1, $listado['Items']);
        self::assertSame('Cliente con factura en el listado', $listado['Items'][0]['Nombre']);
    }

    public function test_todosFacturaFiltro_aplicaElFragmentoDeWhereQueRecibe(): void
    {
        $idCliente = $this->siembra->cliente('Cliente para filtrar facturas');
        $idArticulo = $this->siembra->articulo('Articulo para filtrar facturas');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10', ['idCliente' => $idCliente]);
        $idFactura = $this->siembra->facturarAlbaranCliente($idAlbaran);

        $identificadores = array_column(
            $this->facturas->TodosFacturaFiltro("WHERE a.idCliente = $idCliente")['Items'],
            'id'
        );

        self::assertContains((string) $idFactura, $identificadores);
    }

    /**
     * El listado declara una rama que devuelve el error y la consulta al llamante, y la
     * vista la comprueba para pintar el aviso. Esa rama no se alcanza: la conexion lanza
     * excepcion antes de que la clase pueda devolver nada, y el aviso nunca se pinta.
     */
    public function test_defecto_elErrorDelListadoNoLlegaAlLlamanteSinoComoExcepcion(): void
    {
        $this->expectException(\mysqli_sql_exception::class);

        $this->facturas->TodosFacturaFiltro('WHERE columna_que_no_existe = 1');
    }

    // --- Estados --------------------------------------------------------------

    public function test_getEstado_devuelveElEstadoDeLaFactura(): void
    {
        $idFactura = $this->facturaSembrada('Articulo para el estado de factura');

        self::assertSame('Guardado', $this->facturas->getEstado($idFactura));
    }

    /**
     * Una factura que no existe y una factura sin estado no se distinguen: `getEstado`
     * indexa el array vacio que devuelve la lectura sin comprobar que trajo fila.
     */
    public function test_defecto_getEstadoDeUnaFacturaInexistenteNoSeDistingueDeUnEstadoVacio(): void
    {
        self::assertNull(@$this->facturas->getEstado(999999999));
    }

    public function test_posiblesEstados_declaraTresEstadosDelDocumento(): void
    {
        $estados = array_column($this->facturas->posiblesEstados(), 'estado');

        self::assertSame(['Guardado', 'Sin Guardar', 'Procesado'], $estados);
    }

    public function test_getEstadosFacturas_devuelveLosEstadosPresentesEnLaTabla(): void
    {
        $this->facturaSembrada('Articulo para el catalogo de estados de factura');

        self::assertContains('Guardado', $this->facturas->getEstadosFacturas());
    }

    // --- Navegacion entre facturas --------------------------------------------

    public function test_getPrimeraFactura_deUnClienteDevuelveElNumeroMasBajo(): void
    {
        $idCliente = $this->siembra->cliente('Cliente para navegar sus facturas');
        $primera = $this->numeroDeFacturaDe($idCliente, 'Primera factura del cliente', '2026-01-10');
        $this->numeroDeFacturaDe($idCliente, 'Segunda factura del cliente', '2026-01-11');

        self::assertSame($primera, (int) $this->facturas->getPrimeraFactura($idCliente));
    }

    public function test_getUltimaFactura_deUnClienteDevuelveElNumeroMasAlto(): void
    {
        $idCliente = $this->siembra->cliente('Cliente para la ultima factura');
        $this->numeroDeFacturaDe($idCliente, 'Factura anterior del cliente', '2026-01-10');
        $ultima = $this->numeroDeFacturaDe($idCliente, 'Factura posterior del cliente', '2026-01-11');

        self::assertSame($ultima, (int) $this->facturas->getUltimaFactura($idCliente));
    }

    public function test_getPrimeraFactura_deUnClienteSinFacturasDevuelveCero(): void
    {
        $idCliente = $this->siembra->cliente('Cliente sin ninguna factura');

        self::assertSame(0, $this->facturas->getPrimeraFactura($idCliente));
    }

    public function test_getFacturaAnteriorSiguiente_devuelveElNumeroContiguoDelCliente(): void
    {
        $idCliente = $this->siembra->cliente('Cliente para el anterior y el siguiente');
        $primera = $this->numeroDeFacturaDe($idCliente, 'Factura primera de la serie', '2026-01-10');
        $segunda = $this->numeroDeFacturaDe($idCliente, 'Factura segunda de la serie', '2026-01-11');

        self::assertSame($primera, (int) $this->facturas->getFacturaAnteriorSiguiente($segunda, $idCliente, 'anterior'));
        self::assertSame($segunda, (int) $this->facturas->getFacturaAnteriorSiguiente($primera, $idCliente, 'siguiente'));
    }

    /**
     * Cuando no hay documento contiguo el metodo devuelve `0`, no `false`. La vista de
     * factura pinta el enlace si el resultado no es identico a `false`, de modo que la
     * ausencia de factura anterior produce un enlace al documento numero cero.
     */
    public function test_defecto_sinFacturaContiguaDevuelveCeroYNoElFalsoQueLaVistaComprueba(): void
    {
        $idCliente = $this->siembra->cliente('Cliente con una sola factura');
        $unica = $this->numeroDeFacturaDe($idCliente, 'Unica factura del cliente', '2026-01-10');

        $anterior = $this->facturas->getFacturaAnteriorSiguiente($unica, $idCliente, 'anterior');

        self::assertSame(0, $anterior);
        self::assertNotFalse($anterior, 'La vista descarta el enlace comparando con false, que nunca llega.');
    }

    // --- Identidad de la fila leida -------------------------------------------

    public function test_buscarIdFactura_devuelveElIdentificadorDeEseNumero(): void
    {
        $idFactura = $this->facturaSembrada('Articulo para buscar el id de factura');
        $numero = (int) $this->facturas->datosFactura($idFactura)['Numfaccli'];

        self::assertSame($idFactura, (int) $this->facturas->buscarIdFactura($numero)['id']);
    }

    /**
     * Sin coincidencia la funcion no asigna la variable que devuelve, de modo que responde
     * nulo apoyandose en una variable no definida: la ausencia del documento no llega al
     * llamante como tal.
     */
    public function test_defecto_buscarIdFacturaSinCoincidenciaDevuelveNuloPorVariableNoDefinida(): void
    {
        self::assertNull(@$this->facturas->buscarIdFactura(999999999));
    }

    // --- La consulta propia de la clase ----------------------------------------

    public function test_consulta_conSqlValidoDevuelveElResultado(): void
    {
        $resultado = $this->facturas->consulta('SELECT 1 as uno');

        self::assertSame('1', $resultado->fetch_assoc()['uno']);
    }

    /**
     * `consulta()` esta escrita para devolver un array con el error cuando la sentencia
     * falla, y los veintiocho metodos de la clase comprueban ese array. Con la conexion
     * lanzando excepcion ninguno de esos controles llega a ejecutarse: el manejo de error
     * propio de esta clase es codigo muerto, igual que el de la clase base.
     */
    public function test_defecto_elManejoDeErrorPropioDeLaClaseEsCodigoMuerto(): void
    {
        $this->expectException(\mysqli_sql_exception::class);

        $this->facturas->consulta('SELECT * FROM tabla_que_no_existe');
    }

    public function test_constructor_dejaContadoElNumeroDeFacturas(): void
    {
        $this->facturaSembrada('Articulo para contar facturas');

        $clase = new \FacturasVentas($this->db);

        self::assertGreaterThan(0, (int) $clase->num_rows);
    }

    // --- Apoyos del caso --------------------------------------------------------

    /** Una factura real, por el unico camino que la crea: facturar un albaran de cliente. */
    private function facturaSembrada(string $nombreArticulo, array $opciones = []): int
    {
        $idArticulo = $this->siembra->articulo($nombreArticulo, $opciones);
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');

        return $this->siembra->facturarAlbaranCliente($idAlbaran);
    }

    /** El numero de una factura nueva de ese cliente. */
    private function numeroDeFacturaDe(int $idCliente, string $nombreArticulo, string $fecha): int
    {
        $idArticulo = $this->siembra->articulo($nombreArticulo);
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, $fecha, ['idCliente' => $idCliente]);
        $idFactura = $this->siembra->facturarAlbaranCliente($idAlbaran);

        return (int) $this->facturas->datosFactura($idFactura)['Numfaccli'];
    }
}
