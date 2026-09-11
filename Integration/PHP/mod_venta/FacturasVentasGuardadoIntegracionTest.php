<?php

/**
 * `FacturasVentas`, la escritura del documento: alta de una factura con sus lineas, su
 * desglose de impuestos y los albaranes que factura, borrado, y la secuencia que
 * `factura.php` encadena al pulsar Guardar sobre una factura que ya existia.
 *
 * La factura no mueve existencias —eso lo hizo el albaran— pero es el registro fiscal:
 * lo que aqui se borra antes de reescribirse es un documento emitido.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVenta;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class FacturasVentasGuardadoIntegracionTest extends CasoIntegracion
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

    // --- Alta de una factura nueva --------------------------------------------

    public function test_addFacturaGuardado_creaLaCabeceraConSuNumeroIgualAlId(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo de alta de factura');

        $this->facturas->AddFacturaGuardado($this->datosDeGuardado([$this->linea($idArticulo)]), 0);

        $fila = $this->ultimaFacturaDe($this->siembra->clientePorDefecto());
        self::assertSame($fila['id'], $fila['Numfaccli'], 'El numero de factura se iguala al id recien creado.');
        self::assertSame('Guardado', $fila['estado']);
    }

    public function test_addFacturaGuardado_escribeUnaLineaPorProductoActivo(): void
    {
        $primero = $this->siembra->articulo('Primer producto de la factura');
        $segundo = $this->siembra->articulo('Segundo producto de la factura');

        $this->facturas->AddFacturaGuardado($this->datosDeGuardado([
            $this->linea($primero),
            $this->linea($segundo),
        ]), 0);

        self::assertCount(2, $this->facturas->ProductosFactura($this->idDeLaUltimaFactura()));
    }

    public function test_addFacturaGuardado_noEscribeLasLineasQueNoEstanActivas(): void
    {
        $activo = $this->siembra->articulo('Producto activo de la factura');
        $retirado = $this->siembra->articulo('Producto retirado de la factura');

        $this->facturas->AddFacturaGuardado($this->datosDeGuardado([
            $this->linea($activo),
            $this->linea($retirado, ['estadoLinea' => 'Anulado']),
        ]), 0);

        self::assertCount(1, $this->facturas->ProductosFactura($this->idDeLaUltimaFactura()));
    }

    public function test_addFacturaGuardado_numeraLasFilasCorrelativamenteSaltandoLasNoActivas(): void
    {
        $primero = $this->siembra->articulo('Primero para numerar filas de factura');
        $descartado = $this->siembra->articulo('Descartado para numerar filas de factura');
        $tercero = $this->siembra->articulo('Tercero para numerar filas de factura');

        $this->facturas->AddFacturaGuardado($this->datosDeGuardado([
            $this->linea($primero),
            $this->linea($descartado, ['estadoLinea' => 'Anulado']),
            $this->linea($tercero),
        ]), 0);

        $filas = array_column($this->facturas->ProductosFactura($this->idDeLaUltimaFactura()), 'nfila');
        self::assertSame(['1', '2'], $filas, 'La linea descartada no consume posicion.');
    }

    public function test_addFacturaGuardado_escribeElDesgloseDeIvas(): void
    {
        $idArticulo = $this->siembra->articulo('Producto con desglose de factura');

        $this->facturas->AddFacturaGuardado($this->datosDeGuardado([$this->linea($idArticulo)]), 0);

        $desglose = $this->facturas->IvasFactura($this->idDeLaUltimaFactura());
        self::assertSame('21', $desglose[0]['iva']);
        self::assertSame('2.10', $desglose[0]['importeIva']);
    }

    public function test_addFacturaGuardado_conIdentificadorDadoLoReinsertaConEseMismoId(): void
    {
        $idFactura = $this->facturaSembrada('Producto de factura reinsertada');
        $idArticulo = $this->siembra->articulo('Producto nuevo de la factura reinsertada');
        $this->facturas->eliminarFacturasTablas($idFactura);

        $this->facturas->AddFacturaGuardado($this->datosDeGuardado([$this->linea($idArticulo)]), $idFactura);

        self::assertSame($idFactura, (int) $this->facturas->datosFactura($idFactura)['id']);
    }

    public function test_addFacturaGuardado_escribeLaFechaDeVencimientoQueRecibe(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de factura con vencimiento');

        $this->facturas->AddFacturaGuardado(
            $this->datosDeGuardado([$this->linea($idArticulo)], ['fechaVencimiento' => '2026-03-31']),
            0
        );

        $fecha = $this->facturas->datosFactura($this->idDeLaUltimaFactura())['fechaVencimiento'];
        self::assertSame('2026-03-31 00:00:00', $fecha);
    }

    // --- Los albaranes que la factura factura ---------------------------------

    /**
     * El enlace solo se escribe cuando el numero del albaran coincide con su identificador,
     * porque es el numero lo que se guarda en la columna del identificador. Aqui se fuerza
     * esa coincidencia para poder observar el mecanismo funcionando.
     */
    public function test_addFacturaGuardado_enlazaLosAlbaranesActivosDeLaFactura(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de albaran facturado en alta');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $this->db->query("UPDATE albclit SET Numalbcli=id WHERE id=$idAlbaran");

        $this->facturas->AddFacturaGuardado($this->datosDeGuardado(
            [$this->linea($idArticulo)],
            ['albaranes' => json_encode([$this->adjunto($idAlbaran)])]
        ), 0);

        self::assertCount(1, $this->facturas->AlbaranesFactura($this->idDeLaUltimaFactura()));
    }

    /**
     * La coincidencia entre numero e identificador de la que depende el enlace ya no se da
     * en la base: el numero se asigna por serie y el identificador por la secuencia de la
     * tabla, y ambas se separan en cuanto se borra un documento o se siembran varios.
     */
    public function test_defecto_numeroEIdentificadorDelAlbaranYaDivergenEnLaBase(): void
    {
        $fila = $this->db
            ->query('SELECT COUNT(*) as total, SUM(id <> Numalbcli) as divergen FROM albclit')
            ->fetch_assoc();

        self::assertGreaterThan(0, (int) $fila['divergen'], 'Hay albaranes cuyo numero no es su identificador.');
        self::assertLessThan(
            (int) $fila['total'],
            (int) $fila['divergen'],
            'Y conviven con los que si coinciden: el enlace funciona para unos y no para otros.'
        );
    }

    public function test_addFacturaGuardado_noEnlazaLosAlbaranesQueElOperadorRetiro(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de albaran retirado de la factura');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');

        $this->facturas->AddFacturaGuardado($this->datosDeGuardado(
            [$this->linea($idArticulo)],
            ['albaranes' => json_encode([$this->adjunto($idAlbaran, ['estado' => 'Eliminado'])])]
        ), 0);

        self::assertSame([], $this->facturas->AlbaranesFactura($this->idDeLaUltimaFactura()));
    }

    /**
     * El enlace entre factura y albaran se escribe dos veces con el mismo valor: el numero
     * del albaran va tanto a la columna del numero como a la del identificador, aunque el
     * dato que llega del navegador trae los dos por separado. Mientras numero e
     * identificador coincidan la lectura funciona; en cuanto dejen de coincidir, la columna
     * del identificador deja de nombrar el albaran que se facturo.
     */
    public function test_defecto_elEnlaceConElAlbaranGuardaElNumeroEnLaColumnaDelIdentificador(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de albaran con numero desplazado');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $ajeno = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-11');
        $this->db->query("UPDATE albclit SET Numalbcli=$ajeno WHERE id=$idAlbaran");

        $this->facturas->AddFacturaGuardado($this->datosDeGuardado(
            [$this->linea($idArticulo)],
            ['albaranes' => json_encode([$this->adjunto($idAlbaran, ['NumAdjunto' => $ajeno])])]
        ), 0);

        $enlace = $this->facturas->AlbaranesFactura($this->idDeLaUltimaFactura())[0];
        self::assertSame($ajeno, (int) $enlace['idAlbaran'], 'Queda enlazado el albaran ajeno...');
        self::assertNotSame($idAlbaran, (int) $enlace['idAlbaran'], '... y no el que se facturo.');
    }

    /**
     * La columna que recibe el numero del albaran tiene clave foranea contra el
     * identificador del albaran. Cuando el numero no corresponde a ningun identificador,
     * la base rechaza el enlace y la factura queda escrita sin los albaranes que la
     * justifican.
     */
    public function test_defecto_unNumeroDeAlbaranSinIdentificadorEquivalenteRompeElEnlace(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de albaran sin equivalencia');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');

        try {
            $this->facturas->AddFacturaGuardado($this->datosDeGuardado(
                [$this->linea($idArticulo)],
                ['albaranes' => json_encode([$this->adjunto($idAlbaran, ['NumAdjunto' => 999999999])])]
            ), 0);
            self::fail('Se esperaba que la clave foranea rechazase el enlace.');
        } catch (\mysqli_sql_exception) {
            // La cabecera y las lineas ya estan escritas cuando el enlace falla.
        }

        $idFactura = $this->idDeLaUltimaFactura();
        self::assertCount(1, $this->facturas->ProductosFactura($idFactura), 'La factura queda con sus lineas...');
        self::assertSame([], $this->facturas->AlbaranesFactura($idFactura), '... y sin ningun albaran que la respalde.');
    }

    // --- Lo que el servidor acepta sin comprobar -------------------------------

    /**
     * El importe de la factura y su desglose de impuestos se escriben tal como llegan, sin
     * contrastarlos con las lineas que se estan guardando en la misma llamada.
     */
    public function test_defecto_elTotalYElDesgloseSeEscribenTalComoLleganSinContrastarConLasLineas(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de factura con total ajeno');

        $this->facturas->AddFacturaGuardado($this->datosDeGuardado(
            [$this->linea($idArticulo)],
            ['total' => 999.99, 'DatosTotales' => ['desglose' => ['21' => ['iva' => 500.00, 'base' => 1.00]]]]
        ), 0);

        $idFactura = $this->idDeLaUltimaFactura();
        self::assertSame('999.99', $this->facturas->datosFactura($idFactura)['total']);
        self::assertSame('500.00', $this->facturas->IvasFactura($idFactura)[0]['importeIva']);
    }

    /**
     * El estado se escribe tal como llega. La clase declara un catalogo de tres estados y
     * ninguna escritura lo consulta.
     */
    public function test_defecto_elEstadoSeEscribeSinContrastarloConElCatalogoDeLaClase(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de factura con estado inventado');

        $this->facturas->AddFacturaGuardado(
            $this->datosDeGuardado([$this->linea($idArticulo)], ['estado' => 'Inventado']),
            0
        );

        $declarados = array_column($this->facturas->posiblesEstados(), 'estado');
        $escrito = $this->facturas->datosFactura($this->idDeLaUltimaFactura())['estado'];

        self::assertSame('Inventado', $escrito);
        self::assertNotContains($escrito, $declarados, 'El estado escrito no esta en el catalogo.');
    }

    /**
     * Una factura sin ninguna linea activa se guarda igual: queda una cabecera con su
     * numero de serie fiscal y sin nada que justifique su importe.
     */
    public function test_defecto_guardarSinNingunaLineaCreaLaCabeceraIgualmente(): void
    {
        $antes = $this->cuantasFacturas();

        $this->facturas->AddFacturaGuardado($this->datosDeGuardado([]), 0);

        self::assertSame($antes + 1, $this->cuantasFacturas());
        self::assertSame([], $this->facturas->ProductosFactura($this->idDeLaUltimaFactura()));
    }

    /**
     * La descripcion de la linea se concatena en la sentencia entre comillas dobles sin
     * escapar. Una comilla doble en el texto —una medida en pulgadas, por ejemplo— corta
     * la sentencia y deja la factura sin esa linea ni las siguientes.
     */
    public function test_defecto_unaComillaEnLaDescripcionCortaElGuardadoDeLasLineas(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de factura con comilla');

        try {
            $this->facturas->AddFacturaGuardado($this->datosDeGuardado([
                $this->linea($idArticulo, ['cdetalle' => 'Cable 1" macho']),
                $this->linea($idArticulo),
            ]), 0);
            self::fail('Se esperaba que la sentencia rota interrumpiese el guardado.');
        } catch (\mysqli_sql_exception) {
            // La cabecera ya esta escrita cuando la primera linea falla.
        }

        self::assertSame([], $this->facturas->ProductosFactura($this->idDeLaUltimaFactura()));
    }

    /**
     * La linea de la factura guarda dos cantidades, `ncant` y `nunidades`, y el guardado
     * escribe cada una con lo que reciba sin que nada declare que mide cada cual. El issue
     * TPVFox #40 documenta desde 2018 la semantica pretendida —`ncant` el peso o contenido,
     * `nunidades` el numero de unidades vendidas— y anota que en la practica solo se usa
     * una de las dos. El importe del documento se calcula sobre `nunidades`.
     */
    public function test_defecto_lasDosCantidadesDeLaLineaSeEscribenSinDeclararQueMideCadaUna(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de factura con dos cantidades');

        $this->facturas->AddFacturaGuardado($this->datosDeGuardado([
            $this->linea($idArticulo, ['ncant' => 0.420, 'nunidades' => 1.0]),
        ]), 0);

        $linea = $this->facturas->ProductosFactura($this->idDeLaUltimaFactura())[0];
        self::assertSame('0.420000', $linea['ncant']);
        self::assertSame('1.000000', $linea['nunidades']);
    }

    /**
     * Nada impide que dos facturas incorporen el mismo albaran: el guardado no consulta si
     * el albaran ya esta enlazado a otra factura ni en que estado se encuentra.
     */
    public function test_defecto_elMismoAlbaranSePuedeFacturarDosVeces(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de albaran facturado dos veces');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $this->db->query("UPDATE albclit SET Numalbcli=id WHERE id=$idAlbaran");
        $datos = $this->datosDeGuardado(
            [$this->linea($idArticulo)],
            ['albaranes' => json_encode([$this->adjunto($idAlbaran)])]
        );

        $this->facturas->AddFacturaGuardado($datos, 0);
        $primera = $this->idDeLaUltimaFactura();
        $this->facturas->AddFacturaGuardado($datos, 0);
        $segunda = $this->idDeLaUltimaFactura();

        self::assertNotSame($primera, $segunda);
        self::assertCount(1, $this->facturas->AlbaranesFactura($primera));
        self::assertCount(1, $this->facturas->AlbaranesFactura($segunda), 'El mismo albaran, en dos facturas.');
    }

    /**
     * Una factura sin cliente no se puede escribir: la clave foranea de `facclit` la rechaza.
     * El borrador si admite quedarse sin cliente, de modo que la pantalla deja componer un
     * documento que la base no va a aceptar, y el rechazo llega al pulsar Guardar.
     */
    public function test_defecto_laFacturaSinClienteLaRechazaLaBaseAlGuardarNoLaPantallaAntes(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de factura sin cliente');
        $borrador = $this->facturas->insertarDatosTemporal(
            $this->siembra->usuarioPorDefecto(),
            $this->siembra->tiendaPorDefecto(),
            '2026-02-15',
            [],
            [],
            0
        );
        self::assertGreaterThan(0, $borrador['id'], 'El borrador sin cliente si se crea...');

        $this->expectException(\mysqli_sql_exception::class);
        $this->facturas->AddFacturaGuardado(
            $this->datosDeGuardado([$this->linea($idArticulo)], ['idCliente' => 0]),
            0
        );
    }

    /**
     * El desglose de una factura real agrupa productos a distintos tipos impositivos. La
     * lectura lo tenia cubierto; la escritura no se habia ejercido con mas de una entrada.
     * Hueco cerrado desde `MCT-2026-046-TPY`.
     */
    public function test_addFacturaGuardado_escribeUnaFilaDeDesglosePorCadaTipoImpositivo(): void
    {
        $reducido = $this->siembra->articulo('Producto de factura al diez');
        $general = $this->siembra->articulo('Producto de factura al veintiuno');

        $this->facturas->AddFacturaGuardado($this->datosDeGuardado(
            [$this->linea($reducido, ['iva' => 10]), $this->linea($general, ['iva' => 21])],
            ['DatosTotales' => ['desglose' => [
                '10' => ['iva' => 1.00, 'base' => 10.00],
                '21' => ['iva' => 2.10, 'base' => 10.00],
            ]]]
        ), 0);

        $desglose = $this->facturas->IvasFactura($this->idDeLaUltimaFactura());
        self::assertCount(2, $desglose);
        self::assertSame(['10', '21'], array_column($desglose, 'iva'));
    }

    /**
     * Una factura mensual agrupa los albaranes del periodo: incorporar mas de uno es el caso
     * normal y ninguna prueba lo ejercia. Hueco cerrado desde `MCT-2026-043-TPY`.
     */
    public function test_addFacturaGuardado_enlazaTodosLosAlbaranesIncorporados(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de factura con dos albaranes');
        $primero = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $segundo = $this->siembra->ventaAlbaranCliente($idArticulo, 2.0, '2026-01-11');
        $this->db->query("UPDATE albclit SET Numalbcli=id WHERE id IN ($primero, $segundo)");

        $this->facturas->AddFacturaGuardado($this->datosDeGuardado(
            [$this->linea($idArticulo, ['NumalbCli' => $primero]), $this->linea($idArticulo, ['NumalbCli' => $segundo])],
            ['albaranes' => json_encode([$this->adjunto($primero), $this->adjunto($segundo)])]
        ), 0);

        $enlazados = array_map('intval', array_column(
            $this->facturas->AlbaranesFactura($this->idDeLaUltimaFactura()),
            'idAlbaran'
        ));
        sort($enlazados);
        self::assertSame([$primero, $segundo], $enlazados);
    }

    /**
     * La mitad de `CQA-16` que el caso de la traza no cubria: reescribir con otro usuario.
     * El creador que consta pasa a ser quien reguarda. Hueco cerrado desde `MCT-2026-049-TPY`.
     */
    public function test_defecto_reguardarConOtroUsuarioSustituyeAlCreadorDeLaFactura(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de factura con dos usuarios');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $idFactura = $this->siembra->facturarAlbaranCliente($idAlbaran);
        $creador = (int) $this->facturas->datosFactura($idFactura)['idUsuario'];
        $otroUsuario = $this->otroUsuario();

        $this->facturas->eliminarFacturasTablas($idFactura);
        $this->facturas->AddFacturaGuardado(
            $this->datosDeGuardado([$this->linea($idArticulo)], ['idUsuario' => $otroUsuario]),
            $idFactura
        );

        self::assertNotSame($creador, $otroUsuario, 'Son dos usuarios distintos...');
        self::assertSame(
            $otroUsuario,
            (int) $this->facturas->datosFactura($idFactura)['idUsuario'],
            '... y el que consta en la factura es el que la reguardo, no el que la emitio.'
        );
    }

    /**
     * Nada en el esquema impide dos facturas con el mismo numero: `Numfaccli` no tiene indice
     * unico. La busqueda por numero devuelve una sola fila y no señala que hay mas. Hoy la
     * coincidencia con el identificador garantiza la unicidad por accidente, y la correccion
     * de la serie la retira. Hueco cerrado desde `MCT-2026-048-TPY`.
     */
    public function test_defecto_dosFacturasPuedenCompartirNumeroYLaBusquedaDevuelveUnaSola(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de factura con numero repetido');
        $primera = $this->siembra->facturarAlbaranCliente(
            $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10')
        );
        $segunda = $this->siembra->facturarAlbaranCliente(
            $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-11')
        );
        $this->db->query("UPDATE facclit SET Numfaccli=900100 WHERE id IN ($primera, $segunda)");

        $encontrada = (int) $this->facturas->buscarIdFactura(900100)['id'];

        self::assertContains($encontrada, [$primera, $segunda]);
        self::assertSame(
            2,
            (int) $this->db->query('SELECT COUNT(*) as n FROM facclit WHERE Numfaccli=900100')->fetch_assoc()['n'],
            'Hay dos facturas con ese numero y la busqueda devuelve una, sin señalarlo.'
        );
    }

    // --- Borrado ---------------------------------------------------------------

    public function test_eliminarFacturasTablas_borraCabeceraLineasDesgloseYEnlaces(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de factura que se borra');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $idFactura = $this->siembra->facturarAlbaranCliente($idAlbaran);

        $this->facturas->eliminarFacturasTablas($idFactura);

        self::assertSame([], $this->facturas->datosFactura($idFactura));
        self::assertSame([], $this->facturas->ProductosFactura($idFactura));
        self::assertSame([], $this->facturas->IvasFactura($idFactura));
        self::assertSame([], $this->facturas->AlbaranesFactura($idFactura));
    }

    public function test_eliminarFacturasTablas_dejaElAlbaranQueSeFacturoEnSuSitio(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de albaran que sobrevive');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $idFactura = $this->siembra->facturarAlbaranCliente($idAlbaran);

        $this->facturas->eliminarFacturasTablas($idFactura);

        self::assertSame(
            'Procesado',
            $this->db->query("SELECT estado FROM albclit WHERE id=$idAlbaran")->fetch_assoc()['estado'],
            'El albaran sigue marcado como procesado aunque su factura ya no exista.'
        );
    }

    // --- La secuencia que la pantalla encadena al reguardar --------------------

    /**
     * Reguardar una factura es borrarla entera y volver a escribirla, sin nada que ate las
     * dos operaciones. Si la reescritura falla, lo que queda no es la factura anterior:
     * es su ausencia.
     */
    public function test_defecto_siLaReescrituraFallaLaFacturaAnteriorYaNoExiste(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de factura que se pierde');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $idFactura = $this->siembra->facturarAlbaranCliente($idAlbaran);

        $this->facturas->eliminarFacturasTablas($idFactura);
        try {
            $this->facturas->AddFacturaGuardado($this->datosDeGuardado([
                $this->linea($idArticulo, ['cdetalle' => 'Perfil de 2" reforzado']),
            ]), $idFactura);
        } catch (\mysqli_sql_exception) {
            // La reescritura se corta en la primera linea.
        }

        self::assertSame([], $this->facturas->ProductosFactura($idFactura), 'La factura queda sin lineas...');
        self::assertNotSame([], $this->facturas->datosFactura($idFactura), '... con su cabecera reescrita y su numero.');
    }

    /**
     * Al reguardar, la fecha de creacion se reescribe con la del dia y el usuario que
     * consta pasa a ser el que guarda: la factura pierde tanto cuando se creo como quien
     * la creo. Las tres columnas de traza existen en el esquema y se escriben, pero
     * ninguna conserva el valor original.
     */
    public function test_defecto_reguardarSustituyeLaFechaDeCreacionYElUsuarioDeLaFactura(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de factura con traza');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $idFactura = $this->siembra->facturarAlbaranCliente($idAlbaran);
        $creacionOriginal = $this->facturas->datosFactura($idFactura)['fechaCreacion'];

        $this->facturas->eliminarFacturasTablas($idFactura);
        $this->facturas->AddFacturaGuardado($this->datosDeGuardado([$this->linea($idArticulo)]), $idFactura);

        $reguardada = $this->facturas->datosFactura($idFactura);
        self::assertSame('2026-01-10 00:00:00', $creacionOriginal, 'La factura se creo con la fecha del albaran...');
        self::assertNotSame($creacionOriginal, $reguardada['fechaCreacion'], '... y al reguardarla se pierde.');
        self::assertSame(
            $reguardada['fechaCreacion'],
            $reguardada['fechaModificacion'],
            'Creacion y modificacion quedan con el mismo valor: no distinguen nada.'
        );
    }

    // --- Apoyos del caso --------------------------------------------------------

    /** Los datos de guardado tal como `factura.php` los compone al pulsar Guardar. */
    private function datosDeGuardado(array $productos, array $extra = []): array
    {
        return array_merge([
            'Numtemp_faccli'    => 0,
            'Fecha'             => '2026-02-15',
            'idTienda'          => $this->siembra->tiendaPorDefecto(),
            'idUsuario'         => $this->siembra->usuarioPorDefecto(),
            'idCliente'         => $this->siembra->clientePorDefecto(),
            'estado'            => 'Guardado',
            'total'             => 12.10,
            'DatosTotales'      => ['desglose' => ['21' => ['iva' => 2.10, 'base' => 10.00]]],
            'productos'         => json_encode($productos),
            'albaranes'         => json_encode([]),
            'fechaCreacion'     => date('Y-m-d'),
            'fechaVencimiento'  => '2026-03-15',
            'fechaModificacion' => date('Y-m-d'),
        ], $extra);
    }

    /** Una linea de producto tal como el navegador la envia dentro de `productos`. */
    private function linea(int $idArticulo, array $extra = []): array
    {
        return array_merge([
            'idArticulo'  => $idArticulo,
            'cref'        => 'REF' . $idArticulo,
            'ccodbar'     => '',
            'cdetalle'    => 'Linea de factura de prueba',
            'ncant'       => 1.0,
            'nunidades'   => 1.0,
            'precioCiva'  => 12.10,
            'iva'         => 21,
            'pvpSiva'     => 10.00,
            'estadoLinea' => 'Activo',
            'NumalbCli'   => 0,
        ], $extra);
    }

    /** Un albaran adjunto tal como el navegador lo envia dentro de `albaranes`. */
    private function adjunto(int $idAlbaran, array $extra = []): array
    {
        $numero = (int) $this->db
            ->query("SELECT Numalbcli FROM albclit WHERE id=$idAlbaran")
            ->fetch_assoc()['Numalbcli'];

        return array_merge([
            'id'         => $idAlbaran,
            'NumAdjunto' => $numero,
            'fecha'      => '2026-01-10',
            'total'      => 12.10,
            'estado'     => 'Activo',
        ], $extra);
    }

    /** Un segundo usuario, para poder distinguir quien emitio de quien reguardo. */
    private function otroUsuario(): int
    {
        $fila = $this->db->query("SELECT id FROM usuarios WHERE username='pruebas-segundo' LIMIT 1")->fetch_assoc();
        if ($fila !== null) {
            return (int) $fila['id'];
        }

        $this->db->query(
            "INSERT INTO usuarios (username, password, fecha, group_id, estado, nombre)"
            . " VALUES ('pruebas-segundo', 'sin-uso', '2020-01-01', 1, 'Activo', 'Segundo usuario de pruebas')"
        );

        return (int) $this->db->insert_id;
    }

    /** Una factura real, por el unico camino que la crea: facturar un albaran de cliente. */
    private function facturaSembrada(string $nombreArticulo): int
    {
        $idArticulo = $this->siembra->articulo($nombreArticulo);
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');

        return $this->siembra->facturarAlbaranCliente($idAlbaran);
    }

    /** La ultima cabecera escrita para ese cliente, que es la que el caso acaba de crear. */
    private function ultimaFacturaDe(int $idCliente): array
    {
        return $this->db
            ->query("SELECT id, Numfaccli, estado FROM facclit WHERE idCliente=$idCliente ORDER BY id DESC LIMIT 1")
            ->fetch_assoc();
    }

    private function idDeLaUltimaFactura(): int
    {
        return (int) $this->ultimaFacturaDe($this->siembra->clientePorDefecto())['id'];
    }

    private function cuantasFacturas(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) as n FROM facclit')->fetch_assoc()['n'];
    }
}
