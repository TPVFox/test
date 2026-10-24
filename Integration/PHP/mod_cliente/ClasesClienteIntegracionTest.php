<?php

/**
 * Las clases de los clientes: el servicio de clientes (`modulos/mod_cliente/clases/ClaseCliente.php`),
 * con la ficha completa, el guardado y los resúmenes; las tarifas (`clases/claseTarifaCliente.php`);
 * los descuentos de tickets (`clases/ClaseDescuentosTicket.php`); y las dos clases del núcleo,
 * `clases/cliente.php` y `clases/Fac_cobros.php`.
 *
 * Las clases heredan de la clase base de acceso a datos: el caso les presta su conexión, para que vean
 * lo que siembra y su escritura se deshaga al terminar. Las dos del núcleo reciben la conexión al
 * crearse.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModCliente;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class ClasesClienteIntegracionTest extends CasoIntegracion
{
    protected bool $compartirConexionConElProducto = true;

    private Siembra $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER['DOCUMENT_ROOT'] = '';
        $_SERVER['PHP_SELF'] = '/TPVFox/modulos/mod_cliente/cliente.php';
        if (!defined('FORMATO_FECHA_MYSQL')) {
            define('FORMATO_FECHA_MYSQL', 'Y-m-d H:i:s');
        }
        $this->incluirTPVFox('/modulos/mod_cliente/clases/ClaseCliente.php');
        $this->incluirTPVFox('/modulos/mod_cliente/clases/claseTarifaCliente.php');
        $this->incluirTPVFox('/modulos/mod_cliente/clases/ClaseDescuentosTicket.php');
        $this->incluirTPVFox('/clases/cliente.php');
        $this->incluirTPVFox('/clases/Fac_cobros.php');
        $this->siembra = new Siembra($this->db);
    }

    /**
     * Los datos de la ficha, tal como los manda al guardar: los campos de texto siempre, y las
     * casillas solo si están marcadas.
     *
     * @param array<string, string> $cambios
     * @return array<string, string>
     */
    private function ficha(array $cambios = []): array
    {
        return $cambios + [
            'idClientes' => '0', 'Nombre' => 'Bar Pepe', 'razonsocial' => 'Pepe SL', 'nif' => 'B99000001', 'codpostal' => '36001',
            'direccion' => 'Rúa Real 1', 'telefono' => '986000001', 'movil' => '', 'fax' => '', 'email' => 'bar@pepe.test',
            'descuento_ticket' => '2', 'requiere_factura' => '1', 'recargo_equivalencia' => '1', 'formapago' => '1', 'estado' => 'Activo', 'vencimiento' => '0', 'Guardar' => 'Guardar',
        ];
    }

    /** @return array<string, ?string> */
    private function clienteGuardado(int $id): array
    {
        return $this->db->query('SELECT * FROM clientes WHERE idClientes = ' . $id)->fetch_assoc();
    }

    private function ticketDe(int $idCliente, int $idArticulo, float $unidades, string $fecha): int
    {
        $ticket = $this->siembra->ventaTicket($idArticulo, $unidades, $fecha);
        $this->db->query("UPDATE ticketst SET idCliente = {$idCliente} WHERE id = {$ticket}");

        return $ticket;
    }

    /** @comportamiento Los clientes se leen con el filtro que se dé, y se cuentan. */
    public function test_losClientesSeLeenYSeCuentan(): void
    {
        $this->siembra->cliente('Ultramarinos Lucía', ['estado' => 'inactivo']);

        $clientes = new \ClaseCliente();

        self::assertSame(['Ultramarinos Lucía'], array_column($clientes->obtenerClientes("WHERE Nombre = 'Ultramarinos Lucía'"), 'Nombre'));
        self::assertSame((int) $this->db->query('SELECT COUNT(*) FROM clientes')->fetch_row()[0], $clientes->contarRegistros());
        self::assertSame([], $clientes->obtenerClientes('WHERE idClientes = -1'));
    }

    /** @comportamiento La ficha completa de un cliente lleva sus datos y sus últimos tickets, albaranes, facturas, pedidos y descuentos. */
    public function test_laFichaCompletaLlevaSusDocumentos(): void
    {
        $cliente = $this->siembra->cliente('Bar Pepe');
        $articulo = $this->siembra->articulo('Pera', ['ultimoCoste' => 2.0]);
        $ticket = $this->ticketDe($cliente, $articulo, 1, '2026-10-01');
        $albaran = $this->siembra->ventaAlbaranCliente($articulo, 2, '2026-10-02', ['idCliente' => $cliente]);
        $this->db->query("INSERT INTO descuentos_tickets (idCliente, descuentoCliente, fechaInicio, fechaFin, numTickets, importeTickets, importeDescuento, idUsuario, estado) VALUES ({$cliente}, 2, '2026-09-01', '2026-09-30 23:59:59', 3, 100, 2, {$this->siembra->usuarioPorDefecto()}, 'Pendiente')");

        $ficha = (new \ClaseCliente())->getClienteCompleto($cliente);

        self::assertSame('Bar Pepe', $ficha['Nombre']);
        self::assertSame([(string) $ticket], array_column($ficha['adjuntos']['tickets']['datos'], 'id'));
        self::assertSame([(string) $albaran], array_column($ficha['adjuntos']['albaranes']['datos'], 'id'));
        self::assertSame([[], []], [$ficha['adjuntos']['facturas']['datos'], $ficha['adjuntos']['pedidos']['datos']]);
        self::assertSame(['2.00'], array_column($ficha['adjuntos']['desc_tickets']['datos'], 'total'));
    }

    /** @comportamiento La ficha del número 0 es la de un cliente nuevo, vacía, con sus documentos vacíos. */
    public function test_laFichaDelNumeroCeroEsLaDeUnNuevo(): void
    {
        $nuevo = (new \ClaseCliente())->getClienteCompleto(0);

        self::assertSame('', $nuevo['Nombre']);
        self::assertSame([], $nuevo['adjuntos']['tickets']['datos']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_cliente/clases/ClaseCliente.php:75-91
     *
     * @group defecto
     * @group ficha
     * @group medio
     *
     * @que-ocurre-hoy La ficha de un número de cliente que no existe termina con un error de PHP, en
     *   lugar del aviso que prevé.
     * @que-deberia-ocurrir Que responda el aviso «no hay datos» para ese número.
     * @por-que-ocurre La consulta sin filas no trae lista, y la ficha la cuenta.
     * @como-deberia-funcionar Tomar como lista vacía la consulta sin filas.
     */
    public function test_laFichaDeUnNumeroQueNoExisteLoDice(): void
    {
        $inexistente = (new \ClaseCliente())->getClienteCompleto(999999);

        self::assertSame(['tipo' => 'danger', 'mensaje' => 'Para este id:999999 no hay datos'], $inexistente['error']);
    }

    /** @comportamiento Guardar un cliente nuevo lo da de alta con sus datos, la forma de pago y el vencimiento juntos, y lo devuelve con su ficha. */
    public function test_guardarUnClienteNuevoLoDaDeAlta(): void
    {
        $guardado = (new \ClaseCliente())->guardarCliente($this->ficha());

        self::assertSame('OK', $guardado['estado']);
        $fila = $this->clienteGuardado((int) $guardado['datos']['idClientes']);
        self::assertSame(['Bar Pepe', 'B99000001', '986000001', '2.00', '1', '1', '{"formapago":"1","vencimiento":"0"}'], [$fila['Nombre'], $fila['nif'], $fila['telefono'], $fila['descuento_ticket'], $fila['requiere_factura'], $fila['recargo_equivalencia'], $fila['formasVenci']]);
    }

    /** @comportamiento Con el NIF de otro cliente no se guarda; con su teléfono, se guarda y se avisa. */
    public function test_elNifDeOtroImpideGuardarYElTelefonoAvisa(): void
    {
        $this->db->query("INSERT INTO clientes (Nombre, nif, telefono, estado) VALUES ('Otro', 'B99000001', '986000002', 'Activo')");
        $clientes = new \ClaseCliente();

        $mismoNif = $clientes->guardarCliente($this->ficha());
        $mismoTelefono = $clientes->guardarCliente($this->ficha(['nif' => 'B99000003', 'telefono' => '986000002']));

        self::assertSame('KO', $mismoNif['estado']);
        self::assertSame('danger', $mismoNif['error'][0]['tipo']);
        self::assertSame('OK', $mismoTelefono['estado']);
        self::assertSame('warning', $mismoTelefono['comprobaciones'][0]['tipo']);
    }

    /** @comportamiento Sin nombre ni razón social, el cliente no se guarda. */
    public function test_sinNombreNiRazonSocialNoSeGuarda(): void
    {
        $guardado = (new \ClaseCliente())->guardarCliente($this->ficha(['Nombre' => '', 'razonsocial' => '']));

        self::assertSame('KO', $guardado['estado']);
        self::assertSame('Debe poner Nombre o razon social', $guardado['error'][0]['mensaje']);
    }

    /** @comportamiento Modificar un cliente cambia sus datos y lo devuelve con su ficha. */
    public function test_modificarUnClienteCambiaSusDatos(): void
    {
        $id = (int) (new \ClaseCliente())->guardarCliente($this->ficha())['datos']['idClientes'];

        $modificado = (new \ClaseCliente())->guardarCliente($this->ficha(['idClientes' => (string) $id, 'email' => 'otro@pepe.test', 'descuento_ticket' => '3']));

        self::assertSame('OK', $modificado['estado']);
        self::assertSame(['otro@pepe.test', '3.00'], [$this->clienteGuardado($id)['email'], $this->clienteGuardado($id)['descuento_ticket']]);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_cliente/clases/ClaseCliente.php:180-201, :446-449
     *
     * @group defecto
     * @group ficha
     * @group medio
     *
     * @que-ocurre-hoy Guardar un cliente sin cambiar nada responde que no se grabó, por un error grave:
     *   «Se ha modificado los datos de 0 clientes».
     * @que-deberia-ocurrir Que guardar sin cambios responda que está guardado.
     * @por-que-ocurre El guardado exige que se modifique exactamente una fila, y la base no cuenta como
     *   modificada la que queda igual.
     * @como-deberia-funcionar Tomar como correcto el guardado sin error, haya o no filas cambiadas.
     */
    public function test_guardarSinCambiosRespondeQueEstaGuardado(): void
    {
        $id = (int) (new \ClaseCliente())->guardarCliente($this->ficha())['datos']['idClientes'];

        $otraVez = (new \ClaseCliente())->guardarCliente($this->ficha(['idClientes' => (string) $id]));

        self::assertSame('OK', $otraVez['estado']);
    }

    /** @comportamiento Con las casillas a cero, como las manda la ficha desmarcadas, el cliente deja de requerir factura y de llevar recargo. */
    public function test_lasCasillasACeroSeGuardanDesmarcadas(): void
    {
        $id = (int) (new \ClaseCliente())->guardarCliente($this->ficha())['datos']['idClientes'];

        (new \ClaseCliente())->guardarCliente($this->ficha(['idClientes' => (string) $id, 'requiere_factura' => '0', 'recargo_equivalencia' => '0']));

        self::assertSame(['0', '0'], [$this->clienteGuardado($id)['requiere_factura'], $this->clienteGuardado($id)['recargo_equivalencia']]);
    }

    /**
     * @comportamiento El resumen de tickets de un cliente en un periodo da cada ticket con su base y su
     *   IVA, el desglose por IVA y sus productos; sin tickets en el periodo, lo dice.
     */
    public function test_elResumenDeTicketsSumaLosDelPeriodo(): void
    {
        $cliente = $this->siembra->cliente('Bar Pepe');
        $pera = $this->siembra->articulo('Pera', ['ultimoCoste' => 2.0, 'iva' => 10]);
        $dentro = $this->ticketDe($cliente, $pera, 2, '2026-10-05');
        $this->ticketDe($cliente, $pera, 1, '2026-10-20');
        $this->ticketDe($cliente, $pera, 5, '2026-11-02');
        $this->siembra->ventaTicket($pera, 7, '2026-10-06');

        $resumen = (new \ClaseCliente())->ticketClienteFechas($cliente, '2026-10-01', '2026-10-31');
        $sinTickets = (new \ClaseCliente())->ticketClienteFechas($cliente, '2025-01-01', '2025-01-31');

        self::assertSame(['6.00', '0.60'], [$resumen['desglose'][0]['sumBase'], $resumen['desglose'][0]['sumiva']]);
        self::assertSame(['4.00', '2.00'], array_column($resumen['resumenBases'], 'sumabase'));
        self::assertSame((string) $dentro, $resumen['resumenBases'][0]['idticketst']);
        self::assertSame(['3'], array_map(static fn ($u) => rtrim(rtrim((string) $u, '0'), '.'), array_column($resumen['productos'], 'totalUnidades')));
        self::assertSame(['error' => 1, 'consulta' => 'No existen ids entre estas fechas'], $sinTickets);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_cliente/clases/ClaseCliente.php:262-263, :324
     *
     * @group defecto
     * @group resumenes
     * @group alto
     *
     * @que-ocurre-hoy El resumen de productos junta en una sola fila dos productos distintos cuando la
     *   suma de su número y su precio coincide: el producto 100 a 3 € y el 101 a 2 € salen como uno, con
     *   las unidades de los dos.
     * @que-deberia-ocurrir Que cada producto y precio tenga su fila.
     * @por-que-ocurre Las líneas se agrupan por la suma del número del producto y su precio, no por los
     *   dos campos.
     * @como-deberia-funcionar Agrupar por el producto y por el precio, por separado.
     */
    public function test_elResumenNoJuntaProductosDistintos(): void
    {
        $cliente = $this->siembra->cliente('Bar Pepe');
        $caro = $this->siembra->articulo('Queso', ['ultimoCoste' => 3.0]);
        $barato = $this->siembra->articulo('Pan', ['ultimoCoste' => 2.0]);
        $ticket = $this->ticketDe($cliente, $caro, 1, '2026-10-05');
        $this->siembra->lineaTicket($ticket, $barato, 1);

        $resumen = (new \ClaseCliente())->ticketClienteFechas($cliente, '2026-10-01', '2026-10-31');

        self::assertSame($barato, $caro + 1, 'Los dos productos, seguidos');
        self::assertCount(2, $resumen['productos']);
    }

    /** @comportamiento El resumen de albaranes de un cliente da cada albarán con su base y su IVA, el desglose y sus productos. */
    public function test_elResumenDeAlbaranesSumaLosDelPeriodo(): void
    {
        $cliente = $this->siembra->cliente('Bar Pepe');
        $pera = $this->siembra->articulo('Pera', ['ultimoCoste' => 2.0, 'iva' => 10]);
        $this->siembra->ventaAlbaranCliente($pera, 3, '2026-10-05', ['idCliente' => $cliente]);
        $this->siembra->ventaAlbaranCliente($pera, 1, '2026-11-05', ['idCliente' => $cliente]);

        $resumen = (new \ClaseCliente())->albaranClienteFechas($cliente, '2026-10-01', '2026-10-31');

        self::assertSame(['6.00'], array_column($resumen['resumenBases'], 'sumabase'));
        self::assertSame(['0.60'], array_column($resumen['desglose'], 'sumiva'));
        self::assertCount(1, $resumen['productos']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_cliente/clases/ClaseCliente.php:315-322
     *
     * @group defecto
     * @group resumenes
     * @group medio
     *
     * @que-ocurre-hoy El resumen de albaranes de un cliente sin albaranes en el periodo termina con un
     *   error de PHP, en lugar de decir que no hay.
     * @que-deberia-ocurrir Que diga que no hay albaranes entre esas fechas, como el de tickets.
     * @por-que-ocurre La consulta sin filas no trae lista, y el resumen la recorre sin mirarlo.
     * @como-deberia-funcionar Mirar si hay albaranes antes de juntar sus números.
     */
    public function test_unClienteSinAlbaranesEnElPeriodoLoDice(): void
    {
        $cliente = $this->siembra->cliente('Bar Pepe');

        $resumen = (new \ClaseCliente())->albaranClienteFechas($cliente, '2026-10-01', '2026-10-31');

        self::assertSame(['error' => 1, 'consulta' => 'No existen ids entre estas fechas'], $resumen);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_cliente/clases/ClaseCliente.php:605-608
     *
     * @group defecto
     * @group descuentos
     * @group alto
     *
     * @que-ocurre-hoy Los tickets del cálculo del descuento mensual llegan hasta las 23:00 del último
     *   día: uno de las 23:30 queda fuera del descuento de ese mes y de cualquier otro.
     * @que-deberia-ocurrir Que el periodo llegue al final del último día, como los resúmenes.
     * @por-que-ocurre El final del periodo se completa con «23:00:00».
     * @como-deberia-funcionar Completarlo con «23:59:59», o comparar con el día siguiente.
     */
    public function test_elDescuentoMensualCuentaTodoElUltimoDia(): void
    {
        $cliente = $this->siembra->cliente('Bar Pepe');
        $pera = $this->siembra->articulo('Pera', ['ultimoCoste' => 2.0]);
        $this->ticketDe($cliente, $pera, 1, '2026-09-30 23:30:00');

        $tickets = (new \ClaseCliente())->ticketsClienteDesglose($cliente, '2026-09-01', '2026-09-30');

        self::assertCount(1, $tickets);
    }

    /** @comportamiento El resumen anual de un cliente toma sus tickets cerrados y sus facturas, por ejercicio y trimestre. */
    public function test_elResumenAnualLeeLasVistas(): void
    {
        $cliente = $this->siembra->cliente('Bar Pepe');
        $pera = $this->siembra->articulo('Pera', ['ultimoCoste' => 10.0, 'iva' => 10]);
        $this->ticketDe($cliente, $pera, 1, '2026-02-10');
        $this->ticketDe($cliente, $pera, 2, '2026-08-10');

        $resumen = (new \ClaseCliente())->getResumenAnual((string) $cliente);

        self::assertCount(1, $resumen['resumen_tickets']);
        self::assertSame(['2026', '11.00', '22.00', '33.00', '3.00'], [$resumen['resumen_tickets'][0]['ejercicio'], $resumen['resumen_tickets'][0]['q1'], $resumen['resumen_tickets'][0]['q3'], $resumen['resumen_tickets'][0]['total'], $resumen['resumen_tickets'][0]['totalIva']]);
        self::assertSame([], $resumen['resumen_facturas']);
    }

    /** @comportamiento Las formas de pago y los vencimientos se leen para la ficha, con los días de cada vencimiento; los de uno que no existe son cero. */
    public function test_lasFormasDePagoYLosVencimientos(): void
    {
        $this->db->query("INSERT INTO formasPago (descripcion) VALUES ('Transferencia de pruebas')");
        $clientes = new \ClaseCliente();
        $vencimientos = $clientes->getVencimientos();
        $treinta = array_values(array_filter($vencimientos, static fn (array $v): bool => (int) $v['dias'] === 30))[0];

        self::assertContains('Transferencia de pruebas', array_column($clientes->getFormasPago(), 'descripcion'));
        self::assertSame('30', (string) $clientes->getVencimiento($treinta['id']));
        self::assertSame(0, $clientes->getVencimiento(999999));
    }

    /**
     * @comportamiento La tarifa de un cliente se da de alta, se lee con el nombre y el IVA de cada
     *   producto, se cambia, y al borrarla deja de leerse; se sabe si un producto la tiene.
     */
    public function test_laTarifaDeUnClienteSeGrabaSeLeeYSeBorra(): void
    {
        $cliente = $this->siembra->cliente('Bar Pepe');
        $pera = $this->siembra->articulo('Pera', ['iva' => 10]);
        $tarifas = new \TarifaCliente();

        $tarifas->insert(['idArticulo' => $pera, 'idClientes' => $cliente, 'pvpSiva' => '1.818182', 'pvpCiva' => '2.00', 'fechaActualizacion' => '2026-10-01 10:00:00', 'estado' => K_TARIFACLIENTE_ESTADO_ACTIVO]);
        $alta = $tarifas->leer($cliente)['datos'];
        $tarifas->update(['pvpCiva' => '2.20', 'pvpSiva' => '2.00'], ['idArticulo= ' . $pera, 'idClientes= ' . $cliente]);
        $precio = $tarifas->leerPrecio($cliente, $pera)['datos'][0];
        $existe = $tarifas->existeArticulo($cliente, $pera);
        $tarifas->update(['estado' => K_TARIFACLIENTE_ESTADO_BORRADO], ['idArticulo= ' . $pera, 'idClientes= ' . $cliente]);

        self::assertSame([['Pera', '10.00', '2.000000']], array_map(static fn (array $t): array => [$t['descripcion'], $t['ivaArticulo'], $t['pvpCiva']], $alta));
        self::assertSame(['2.200000', '2.000000'], [$precio['pvpCiva'], $precio['pvpSiva']]);
        self::assertSame('1', (string) $existe);
        self::assertArrayNotHasKey('datos', $tarifas->leer($cliente));
        self::assertSame('0', (string) $tarifas->existeArticulo($cliente, $pera + 1));
    }

    /** @comportamiento Los descuentos de tickets de un cliente se dan de alta y se leen, con los filtros que se den. */
    public function test_losDescuentosDeUnClienteSeGrabanYSeLeen(): void
    {
        $cliente = $this->siembra->cliente('Bar Pepe');
        $descuentos = new \ClaseDescuentosTicket();

        $descuentos->insert(['idCliente' => $cliente, 'descuentoCliente' => 2, 'fechaInicio' => '2026-09-01', 'fechaFin' => '2026-09-30 23:59:59', 'numTickets' => 3, 'importeTickets' => 100, 'importeDescuento' => 2, 'idUsuario' => $this->siembra->usuarioPorDefecto()]);
        $descuentos->insert(['idCliente' => $cliente, 'descuentoCliente' => 2, 'fechaInicio' => '2026-08-01', 'fechaFin' => '2026-08-31 23:59:59', 'numTickets' => 1, 'importeTickets' => 50, 'importeDescuento' => 1, 'idUsuario' => $this->siembra->usuarioPorDefecto()]);

        $todos = $descuentos->leerCliente($cliente);
        $septiembre = $descuentos->leerCliente($cliente, ["DATE(fechaInicio)='2026-09-01'"]);

        self::assertCount(2, $todos);
        self::assertSame(['2.00'], array_column($septiembre, 'importeDescuento'));
    }

    /** @comportamiento La clase de clientes del núcleo lee un cliente por su número y busca por palabras en el nombre o en la razón social. */
    public function test_laClaseDelNucleoLeeYBuscaClientes(): void
    {
        $id = $this->siembra->cliente('Bar Pepe', ['razonsocial' => 'Hostelería Pérez SL']);
        $clientes = @new \Cliente($this->db);

        $porNumero = @$clientes->DatosClientePorId($id);
        $porNombre = @$clientes->BuscarClientePorNombre('Bar Pepe');
        $porRazon = @$clientes->BuscarClientePorNombre('Pérez Hostelería');

        self::assertSame('Bar Pepe', $porNumero['Nombre']);
        self::assertContains((string) $id, array_column($porNombre['datos'], 'idClientes'));
        self::assertContains((string) $id, array_column($porRazon['datos'], 'idClientes'));
    }

    /** @comportamiento La clase de cobros de facturas del núcleo cuenta sus filas al crearse. */
    public function test_laClaseDeCobrosCuentaSusFilas(): void
    {
        $cobros = @new \Fac_cobro($this->db);

        self::assertSame((string) $this->db->query('SELECT COUNT(*) FROM fac_cobros')->fetch_row()[0], (string) $cobros->num_rows);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_cliente/clases/ClaseCliente.php:202-225, :471-523
     *
     * @group defecto
     * @group ficha
     * @group medio
     *
     * @que-ocurre-hoy Un móvil de más de once caracteres —el de un cliente extranjero con su prefijo—
     *   no se guarda: el guardado termina con un error de la base.
     * @que-deberia-ocurrir Que se guarde, o que la ficha diga que no cabe antes de guardar.
     * @por-que-ocurre La columna del móvil tiene once caracteres, y nadie comprueba el largo antes de
     *   guardar.
     * @como-deberia-funcionar Dar a la columna el largo de un teléfono internacional, o comprobarlo al
     *   validar.
     */
    public function test_unMovilExtranjeroSeGuarda(): void
    {
        $guardado = (new \ClaseCliente())->guardarCliente($this->ficha(['movil' => '+447700900123']));

        self::assertSame('OK', $guardado['estado']);
        self::assertSame('+447700900123', $this->clienteGuardado((int) $guardado['datos']['idClientes'])['movil']);
    }
}
