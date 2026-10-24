<?php

/**
 * Las pantallas de los clientes: el listado (`ListaClientes.php`), la ficha (`cliente.php`), la tarifa
 * (`tarifaCliente.php`) y los resúmenes (`Resumenes/resumenTickets.php`, `resumenAlbaranes.php` y
 * `resumenClientes.php`).
 *
 * Cada una se ejecuta en un PHP aparte, desde su carpeta, con la sesión del usuario de pruebas o sin
 * ninguna, y los permisos del módulo concedidos, o con todas sus acciones o algunas quitadas. Tras el
 * arranque común se abre una transacción en su conexión, donde el caso siembra sus clientes, sus
 * productos y sus documentos; la ficha guarda por esa misma conexión, y todo se deshace al terminar el
 * proceso. Antes de terminar, el proceso escribe los clientes del caso.
 *
 * La preparación es una lista de sentencias; cada alta de un cliente queda con un nombre —`:c1`,
 * `:c2`…— y cada alta de un producto también —`:a1`…—, que las sentencias siguientes, la dirección y el
 * formulario pueden usar.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModCliente;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class PantallasClienteIntegracionTest extends CasoIntegracion
{
    private const TODAS = ['crear', 'modificar', 'ver', 'tarifa', 'descuento_ticket', 'descuento_ticket_update', 'resumen_ticket'];

    private int $idUsuario;

    private int $idTienda;

    protected function setUp(): void
    {
        parent::setUp();
        $siembra = new Siembra($this->db);
        $this->idUsuario = $siembra->usuarioPorDefecto();
        $this->idTienda = $siembra->tiendaPorDefecto();
    }

    /** @comportamiento Con permisos, el listado ofrece añadir, modificar, la tarifa y los descuentos de tickets, y lista los clientes. */
    public function test_elListadoOfreceSusOpcionesYListaLosClientes(): void
    {
        $pantalla = $this->ejecutar('ListaClientes.php', ['buscar' => 'Ultramarinos Lucía'], [$this->cliente('Ultramarinos Lucía')]);

        foreach (["metodoClick('AgregarCliente')", "metodoClick('VerCliente')", "metodoClick('TarificarCliente')", 'abrirModalInforme('] as $opcion) {
            self::assertStringContainsString($opcion, $pantalla['html']);
        }
        self::assertStringContainsString('Ultramarinos Lucía', $pantalla['html']);
    }

    /** @comportamiento Sin sesión, el listado no se pinta. */
    public function test_sinSesionElListadoNoSePinta(): void
    {
        $pantalla = $this->ejecutar('ListaClientes.php', [], [$this->cliente('Ultramarinos Lucía')], false);

        self::assertStringNotContainsString('Ultramarinos Lucía', $pantalla['html']);
    }

    /** @comportamiento Sin permisos, el listado no ofrece añadir, modificar, la tarifa ni los descuentos de tickets. */
    public function test_sinPermisosElListadoNoOfreceSusOpciones(): void
    {
        $pantalla = $this->ejecutar('ListaClientes.php', [], [], true, self::TODAS);

        foreach (["metodoClick('AgregarCliente')", "metodoClick('VerCliente')", "metodoClick('TarificarCliente')", 'abrirModalInforme('] as $opcion) {
            self::assertStringNotContainsString($opcion, $pantalla['html']);
        }
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_cliente/ListaClientes.php:93, :196
     *
     * @group defecto
     * @group permisos
     * @group medio
     *
     * @que-ocurre-hoy Sin ningún permiso del módulo, el listado ofrece imprimir la ficha de cada cliente,
     *   con su NIF, su dirección, su teléfono y su correo.
     * @que-deberia-ocurrir Que imprimir la ficha exija un permiso, como ver o modificar al cliente.
     * @por-que-ocurre La opción de imprimir no consulta ningún permiso.
     * @como-deberia-funcionar Consultar el permiso de ver antes de ofrecerla.
     */
    public function test_sinPermisosNoSeOfreceImprimirLaFicha(): void
    {
        $pantalla = $this->ejecutar('ListaClientes.php', [], [$this->cliente('Ultramarinos Lucía')], true, self::TODAS);

        self::assertStringNotContainsString('imprimirFicha(', $pantalla['html']);
    }

    /** @comportamiento La ficha de un cliente para ver enseña sus datos sin dejar cambiarlos, con sus documentos. */
    public function test_laFichaEnsenaElClienteParaVer(): void
    {
        $pantalla = $this->ejecutar('cliente.php', ['id' => ':c1', 'accion' => 'ver'], [$this->cliente('Ultramarinos Lucía', "nif = 'B99000001'")]);

        self::assertStringContainsString('Cliente: Modificar', $pantalla['html']);
        self::assertStringContainsString('name="Nombre"  readonly placeholder="nombre" value="Ultramarinos Lucía"', $pantalla['html']);
        self::assertStringContainsString('value="B99000001"', $pantalla['html']);
        self::assertStringContainsString('Este cliente no tiene tickets', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_cliente/cliente.php:268-279
     *
     * @group defecto
     * @group ficha
     * @group alto
     *
     * @que-ocurre-hoy La ficha nunca marca el recargo de equivalencia, aunque el cliente lo tenga; como
     *   la casilla desmarcada envía un cero, guardar la ficha se lo quita.
     * @que-deberia-ocurrir Que la casilla salga marcada si el cliente tiene el recargo.
     * @por-que-ocurre La ficha lo lee de un campo que no existe, «requiere_equivalencia», en lugar de
     *   «recargo_equivalencia».
     * @como-deberia-funcionar Leer el campo del recargo.
     */
    public function test_laFichaMarcaElRecargoDeEquivalencia(): void
    {
        $pantalla = $this->ejecutar('cliente.php', ['id' => ':c1', 'accion' => 'editar'], [$this->cliente('Ultramarinos Lucía', 'recargo_equivalencia = 1, requiere_factura = 1')]);

        self::assertMatchesRegularExpression('/id="requiere_factura" name="requiere_factura"  value="1" checked>/', $pantalla['html']);
        self::assertMatchesRegularExpression('/id="recargo_equivalencia" name="recargo_equivalencia"  value="1" checked>/', $pantalla['html']);
    }

    /** @comportamiento Guardar la ficha, con permiso, guarda el cliente y lo dice. */
    public function test_guardarLaFichaGuardaElCliente(): void
    {
        $pantalla = $this->ejecutar('cliente.php', ['id' => ':c1', 'accion' => 'editar'], [$this->cliente('Ultramarinos Lucía')], true, [], $this->ficha(['email' => 'lucia@pruebas.test']));

        self::assertStringContainsString('Fue guardo correctamente', $pantalla['html']);
        self::assertSame('lucia@pruebas.test', $pantalla['clientes'][0]['email']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_cliente/cliente.php:87-90
     *
     * @group defecto
     * @group permisos
     * @group alto
     *
     * @que-ocurre-hoy Sin los permisos de crear y de modificar, la ficha guarda el cliente que se le envíe.
     * @que-deberia-ocurrir Que guardar exija el permiso de crear o el de modificar, como el listado.
     * @por-que-ocurre La ficha guarda sin consultar ningún permiso.
     * @como-deberia-funcionar Consultar el permiso antes de guardar.
     */
    public function test_sinPermisoDeModificarLaFichaNoGuarda(): void
    {
        $pantalla = $this->ejecutar('cliente.php', ['id' => ':c1', 'accion' => 'editar'], [$this->cliente('Ultramarinos Lucía')], true, self::TODAS, $this->ficha(['email' => 'lucia@pruebas.test']));

        self::assertSame('', (string) $pantalla['clientes'][0]['email']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_cliente/cliente.php:243-254; modulos/mod_cliente/clases/ClaseCliente.php:429-433
     *
     * @group defecto
     * @group permisos
     * @group alto
     *
     * @que-ocurre-hoy Sin el permiso de sobrescribir descuentos, la ficha deja el descuento de tickets
     *   solo para leer, pero guarda el que se le envíe.
     * @que-deberia-ocurrir Que sin ese permiso el descuento no cambie.
     * @por-que-ocurre El permiso solo decide si la caja se puede escribir; el guardado no lo consulta.
     * @como-deberia-funcionar Quitar el descuento de lo que se guarda si no hay permiso.
     */
    public function test_sinPermisoDeDescuentosNoCambiaElDescuento(): void
    {
        $pantalla = $this->ejecutar('cliente.php', ['id' => ':c1', 'accion' => 'editar'], [$this->cliente('Ultramarinos Lucía', 'descuento_ticket = 2')], true, ['descuento_ticket_update'], $this->ficha(['descuento_ticket' => '50']));

        self::assertStringContainsString('id="descuentotickets" name="descuento_ticket"  readonly', $pantalla['html']);
        self::assertSame('2.00', $pantalla['clientes'][0]['descuento_ticket']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_cliente/cliente.php:203-241
     *
     * @group defecto
     * @group ficha
     * @group medio
     *
     * @que-ocurre-hoy Un nombre con comillas dobles se pinta en la ficha tal como está guardado: corta la
     *   caja, y lo que sigue a las comillas sale como parte de la página.
     * @que-deberia-ocurrir Que los datos del cliente se pinten como texto.
     * @por-que-ocurre Se escriben en la página sin escapar.
     * @como-deberia-funcionar Escapar lo que se pinta.
     */
    public function test_losDatosDelClienteSePintanComoTexto(): void
    {
        $pantalla = $this->ejecutar('cliente.php', ['id' => ':c1', 'accion' => 'ver'], [$this->cliente('Bar "El Puerto"')]);

        self::assertStringContainsString('value="Bar &quot;El Puerto&quot;"', $pantalla['html']);
    }

    /** @comportamiento La ficha de un cliente con tickets del ejercicio enseña su resumen anual. */
    public function test_laFichaConTicketsEnsenaSuResumenAnual(): void
    {
        $pantalla = $this->ejecutar('cliente.php', ['id' => ':c1', 'accion' => 'ver'], [$this->cliente('Ultramarinos Lucía'), $this->ticket('2026-02-10', '100.00')]);

        self::assertStringContainsString('<h3>Resumen Anual</h3><h4>Tickets</h4>', $pantalla['html']);
        self::assertStringContainsString('<td>100.00</td>', $pantalla['html']);
    }

    /** @comportamiento La tarifa de un cliente enseña sus productos con su precio. */
    public function test_laTarifaEnsenaLosProductosDelCliente(): void
    {
        $pantalla = $this->ejecutar('tarifaCliente.php', ['id' => ':c1'], [$this->cliente('Ultramarinos Lucía'), $this->producto('Queso de tarifa'), $this->tarifa()]);

        self::assertStringContainsString('Queso de tarifa', $pantalla['html']);
        self::assertStringContainsString('imprimirTarifa(' . $pantalla['ids'][':c1'] . ')', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_cliente/tarifaCliente.php:9-50
     *
     * @group defecto
     * @group permisos
     * @group medio
     *
     * @que-ocurre-hoy Sin el permiso de la tarifa, que el listado exige para ofrecerla, la pantalla de la
     *   tarifa de un cliente se enseña por su dirección, y deja grabar precios.
     * @que-deberia-ocurrir Que la tarifa exija su permiso.
     * @por-que-ocurre La pantalla solo pide la sesión.
     * @como-deberia-funcionar Consultar el permiso al empezar.
     */
    public function test_sinPermisoDeTarifaNoSeEnsenaLaTarifa(): void
    {
        $pantalla = $this->ejecutar('tarifaCliente.php', ['id' => ':c1'], [$this->cliente('Ultramarinos Lucía'), $this->producto('Queso de tarifa'), $this->tarifa()], true, self::TODAS);

        self::assertStringNotContainsString('Queso de tarifa', $pantalla['html']);
    }

    /** @comportamiento El resumen de tickets de un cliente en unas fechas enseña sus datos, los totales por IVA y sus productos. */
    public function test_elResumenDeTicketsEnsenaLosDelPeriodo(): void
    {
        $pantalla = $this->ejecutar('Resumenes/resumenTickets.php', ['id' => ':c1', 'hacerResumen' => 'Si', 'fechaIni' => '2026-09-01', 'fechaFin' => '2026-09-30'], [$this->cliente('Ultramarinos Lucía'), $this->ticket('2026-09-05', '10.00'), $this->ticket('2026-09-20', '20.00'), $this->ticket('2026-10-02', '40.00')]);

        self::assertStringContainsString('<b>Nombre: </b>Ultramarinos Lucía', $pantalla['html']);
        self::assertStringContainsString('TOTAL: 30', $pantalla['html']);
    }

    /** @comportamiento El resumen de albaranes de un cliente en unas fechas enseña sus albaranes y sus productos. */
    public function test_elResumenDeAlbaranesEnsenaLosDelPeriodo(): void
    {
        $pantalla = $this->ejecutar('Resumenes/resumenAlbaranes.php', ['id' => ':c1', 'hacerResumen' => 'Si', 'fechaIni' => '2026-09-01', 'fechaFin' => '2026-09-30'], [
            $this->cliente('Ultramarinos Lucía'), $this->producto('Queso de albarán'),
            "INSERT INTO albclit (Numalbcli, Fecha, idTienda, idUsuario, idCliente, estado, formaPago, entregado, total) VALUES (990001, '2026-09-10 10:00:00', :tienda, :usuario, :c1, 'Guardado', 'Efectivo', 0, 12.10)",
            "INSERT INTO albclilinea (idalbcli, Numalbcli, idArticulo, cref, ccodbar, cdetalle, ncant, nunidades, precioCiva, iva, nfila, estadoLinea, pvpSiva) SELECT MAX(id), 990001, :a1, '', '', 'Queso de albarán', 1, 1, 12.10, 10, 1, 'Activo', 11 FROM albclit",
            "INSERT INTO albcliIva (idalbcli, Numalbcli, iva, importeIva, totalbase) SELECT MAX(id), 990001, 10, 1.10, 11 FROM albclit",
        ]);

        self::assertStringContainsString('Queso de albarán', $pantalla['html']);
        self::assertStringContainsString('990001', $pantalla['html']);
    }

    /**
     * @comportamiento El resumen de clientes enseña, de mayor a menor, los que requieren factura con
     *   2.500 € o más en el ejercicio, y destaca los de 3.000 € o más.
     */
    public function test_elResumenDeClientesEnsenaLosDeMasImporte(): void
    {
        $pantalla = $this->ejecutar('Resumenes/resumenClientes.php', [], [
            $this->cliente('Cliente de 2.600', 'requiere_factura = 1'), $this->ticket('2026-03-10', '2600.00'),
            $this->cliente('Cliente de 4.000', 'requiere_factura = 1'), str_replace(':c1', ':c2', $this->ticket('2026-04-10', '4000.00')),
            $this->cliente('Cliente de 100', 'requiere_factura = 1'), str_replace(':c1', ':c3', $this->ticket('2026-04-10', '100.00')),
        ]);

        $cuatroMil = strpos($pantalla['html'], 'Resumen Anual de Cliente de 4.000');
        $dosMil = strpos($pantalla['html'], 'Resumen Anual de Cliente de 2.600');
        self::assertNotFalse($cuatroMil);
        self::assertNotFalse($dosMil);
        self::assertLessThan($dosMil, $cuatroMil);
        self::assertStringNotContainsString('Resumen Anual de Cliente de 100', $pantalla['html']);
        self::assertStringContainsString('col-md-12 bg-info', $pantalla['html']);
    }

    /** @comportamiento El listado enlaza el móvil de un cliente con su chat y su correo con un mensaje. */
    public function test_elListadoEnlazaElMovilYElCorreo(): void
    {
        $pantalla = $this->ejecutar('ListaClientes.php', ['buscar' => 'Ultramarinos Lucía'], [$this->cliente('Ultramarinos Lucía', "movil = '600000001', email = 'lucia@pruebas.test'")]);

        self::assertStringContainsString('href="https://web.whatsapp.com/send?phone=34+600000001"', $pantalla['html']);
        self::assertStringContainsString('href="mailto:lucia@pruebas.test"', $pantalla['html']);
    }

    /** @comportamiento La ficha sin número es la de un cliente nuevo, con el descuento de tickets de la configuración. */
    public function test_laFichaDeUnClienteNuevo(): void
    {
        $pantalla = $this->ejecutar('cliente.php', []);

        self::assertStringContainsString('Cliente: Crear', $pantalla['html']);
        self::assertStringContainsString("history.replaceState(null,'','?accion=Nuevo')", $pantalla['html']);
        self::assertMatchesRegularExpression('/id="descuentotickets" name="descuento_ticket" [^>]*value="[^"]+"/', $pantalla['html']);
    }

    /** @comportamiento Guardar la ficha con el NIF de otro cliente no guarda y lo dice. */
    public function test_guardarConElNifDeOtroNoGuardaYLoDice(): void
    {
        $pantalla = $this->ejecutar('cliente.php', ['id' => ':c1', 'accion' => 'editar'], [$this->cliente('Ultramarinos Lucía'), $this->cliente('Otro', "nif = 'B99000009'")], true, [], $this->ficha(['nif' => 'B99000009']));

        self::assertStringContainsString('No se grabo por un error grave', $pantalla['html']);
        self::assertSame('', (string) $pantalla['clientes'][0]['nif']);
    }

    /** @comportamiento El resumen de tickets de este año va desde el 1 de enero; sin cliente, lo dice. */
    public function test_elResumenDelAnoYSinCliente(): void
    {
        $ano = $this->ejecutar('Resumenes/resumenTickets.php', ['id' => ':c1', 'hacerResumen' => 'Si', 'todo' => 'Si'], [$this->cliente('Ultramarinos Lucía'), $this->ticket(date('Y') . '-01-15', '10.00')]);
        $sinCliente = $this->ejecutar('Resumenes/resumenTickets.php', []);

        self::assertStringContainsString('value="' . date('Y') . '-01-01"', $ano['html']);
        self::assertStringContainsString('TOTAL: 10', $ano['html']);
        self::assertStringContainsString('Error no se ha enviado el id del cliente', $sinCliente['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_cliente/Resumenes/resumenTickets.php:76-78, :93-100; modulos/mod_cliente/clases/ClaseCliente.php:258-260
     *
     * @group defecto
     * @group resumenes
     * @group bajo
     *
     * @que-ocurre-hoy El resumen de un cliente sin tickets en el periodo pedido —el mes anterior, por
     *   ejemplo— dice «Error en sql» y corta la página: no se pueden pedir otras fechas.
     * @que-deberia-ocurrir Que diga que no hay tickets en esas fechas y deje pedir otras.
     * @por-que-ocurre La falta de tickets se responde como un error, y la página corta ante cualquier
     *   error.
     * @como-deberia-funcionar Responder la falta de tickets como un aviso.
     */
    public function test_unResumenSinTicketsEnElPeriodoDejaPedirOtrasFechas(): void
    {
        $pantalla = $this->ejecutar('Resumenes/resumenTickets.php', ['id' => ':c1', 'hacerResumen' => 'Si', 'mes_anterior' => 'Si'], [$this->cliente('Ultramarinos Lucía')]);

        self::assertStringNotContainsString('Error en sql', $pantalla['html']);
        self::assertMatchesRegularExpression('/id="fechaInicial" name="fechaInicial" value="\d{4}-\d{2}-01"/', $pantalla['html']);
    }

    /** @comportamiento El resumen de clientes destaca de otro color al que llega a 3.000 € con IVA sin llegar sin él. */
    public function test_elResumenDeClientesDestacaPorElTotalConIva(): void
    {
        $pantalla = $this->ejecutar('Resumenes/resumenClientes.php', [], [
            $this->cliente('Cliente de 3.100 con IVA', 'requiere_factura = 1'),
            "INSERT INTO ticketst (Numticket, Numtempticket, Fecha, idTienda, idUsuario, idCliente, estado, formaPago, entregado, total) SELECT COALESCE(MAX(Numticket), 0) + 1, COALESCE(MAX(Numticket), 0) + 1, '2026-05-10 10:00:00', :tienda, :usuario, :c1, 'Cerrado', 'Efectivo', 0, 3100.00 FROM ticketst;"
            . " INSERT INTO ticketstIva (idticketst, Numticket, iva, importeIva, totalbase) SELECT id, Numticket, 10, 281.82, 2818.18 FROM ticketst WHERE id = LAST_INSERT_ID()",
        ]);

        self::assertStringContainsString('col-md-12 bg-warning', $pantalla['html']);
    }

    private function cliente(string $nombre, string $mas = ''): string
    {
        return "INSERT INTO clientes (Nombre, estado) VALUES ('" . str_replace("'", "''", $nombre) . "', 'Activo')" . ($mas !== '' ? '; UPDATE clientes SET ' . $mas . ' WHERE idClientes = LAST_INSERT_ID()' : '');
    }

    private function producto(string $nombre): string
    {
        return "INSERT INTO articulos (iva, articulo_name, estado, fecha_creado, ultimoCoste, tipo) VALUES (10.00, '{$nombre}', 'Activo', NOW(), 1, 'unidad')";
    }

    private function tarifa(): string
    {
        return "INSERT INTO articulosClientes (idArticulo, idClientes, pvpSiva, pvpCiva, fechaActualizacion, estado) VALUES (:a1, :c1, 1.818182, 2.00, '2026-10-01 10:00:00', '1')";
    }

    private function ticket(string $fecha, string $base): string
    {
        return "INSERT INTO ticketst (Numticket, Numtempticket, Fecha, idTienda, idUsuario, idCliente, estado, formaPago, entregado, total) SELECT COALESCE(MAX(Numticket), 0) + 1, COALESCE(MAX(Numticket), 0) + 1, '{$fecha} 10:00:00', :tienda, :usuario, :c1, 'Cerrado', 'Efectivo', 0, {$base} FROM ticketst;"
            . " INSERT INTO ticketstIva (idticketst, Numticket, iva, importeIva, totalbase) SELECT id, Numticket, 0, 0, {$base} FROM ticketst WHERE id = LAST_INSERT_ID()";
    }

    /**
     * @param array<string, string> $cambios
     * @return array<string, string>
     */
    private function ficha(array $cambios = []): array
    {
        return $cambios + [
            'idClientes' => ':c1', 'Nombre' => 'Ultramarinos Lucía', 'razonsocial' => '', 'nif' => '', 'codpostal' => '', 'direccion' => '', 'telefono' => '', 'movil' => '', 'fax' => '', 'email' => '',
            'descuento_ticket' => '0', 'requiere_factura' => '0', 'recargo_equivalencia' => '0', 'formapago' => '0', 'estado' => 'Activo', 'vencimiento' => '0', 'Guardar' => 'Guardar',
        ];
    }

    /**
     * Ejecuta una pantalla en un PHP aparte y devuelve su HTML, sus avisos, los clientes del caso y los
     * números de lo sembrado.
     *
     * @param array<string, string> $consulta
     * @param list<string> $preparacion
     * @param list<string> $sinPermiso Las acciones del módulo que se quitan.
     * @param array<string, string> $formulario Si se da, la página se pide con él por POST.
     * @return array{html: string, errores: string, clientes: list<array<string, ?string>>, ids: array<string, string>}
     */
    private function ejecutar(string $fichero, array $consulta, array $preparacion = [], bool $conSesion = true, array $sinPermiso = [], array $formulario = []): array
    {
        $sesion = $conSesion ? [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => $this->idUsuario, 'group_id' => 1, 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => $this->idTienda, 'razonsocial' => 'Tienda de pruebas SL', 'NombreComercial' => 'Tienda de pruebas', 'ano' => '2026'],
        ] : [];
        $carpeta = dirname(RUTA_TPVFOX . '/modulos/mod_cliente/' . $fichero);
        $quitar = $sinPermiso === [] ? 'FALSE' : 'accion IN (' . implode(', ', array_map(static fn (string $a): string => "'{$a}'", $sinPermiso)) . ')';
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["REQUEST_METHOD"] = ' . var_export($formulario === [] ? 'GET' : 'POST', true) . '; $_SERVER["DOCUMENT_ROOT"] = "";'
            . ' $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = ' . var_export("/TPVFox/modulos/mod_cliente/{$fichero}", true) . ';'
            . ' chdir(' . var_export($carpeta, true) . ');'
            . ' require ' . var_export(RUTA_TPVFOX . '/inicial.php', true) . ';'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ($conSesion ? ' new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' $BDTpv->query("UPDATE permisos SET permiso = IF(' . $quitar . ', 0, 1) WHERE idUsuario = ' . $this->idUsuario . ' AND modulo = \'mod_cliente\'");'
            . ' $ids = [":usuario" => "' . $this->idUsuario . '", ":tienda" => "' . $this->idTienda . '"]; $cuentas = ["clientes" => 0, "articulos" => 0];'
            . ' $antes = (int) $BDTpv->query("SELECT MAX(idClientes) FROM clientes")->fetch_row()[0];'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->multi_query(strtr($sql, $ids)); $id = $BDTpv->insert_id; do { if ($r = $BDTpv->store_result()) { $r->free(); } } while ($BDTpv->more_results() && $BDTpv->next_result());'
            . ' if (preg_match("/^INSERT INTO (clientes|articulos) /", $sql, $t)) { $ids[($t[1] === "clientes" ? ":c" : ":a") . ++$cuentas[$t[1]]] = (string) ($t[1] === "clientes" ? $BDTpv->query("SELECT MAX(idClientes) FROM clientes")->fetch_row()[0] : $id); } }'
            . ' $_GET = array_map(fn ($valor) => strtr((string) $valor, $ids), ' . var_export($consulta, true) . ');'
            . ' $_POST = array_map(fn ($valor) => strtr((string) $valor, $ids), ' . var_export($formulario, true) . ');'
            . ($conSesion ? ' $ClasePermisos = $thisTpv->permisos = new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' register_shutdown_function(function () use ($BDTpv, &$ids, $antes) { echo "\n@@DATOS@@" . json_encode(["ids" => $ids,'
            . ' "clientes" => $BDTpv->query("SELECT * FROM clientes WHERE idClientes > " . $antes . " ORDER BY idClientes")->fetch_all(MYSQLI_ASSOC)], JSON_INVALID_UTF8_SUBSTITUTE); });'
            . ' include ' . var_export(basename($fichero), true) . ';';

        $errores = (string) tempnam(sys_get_temp_dir(), 'clientes');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -d error_log=/dev/null -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$html, $datos] = array_pad(explode('@@DATOS@@', implode("\n", $salida), 2), 2, 'null');
        $pintada = ['html' => $html, 'errores' => (string) file_get_contents($errores)] + (json_decode($datos, true) ?? ['clientes' => [], 'ids' => []]);
        unlink($errores);

        return $pintada;
    }
}
