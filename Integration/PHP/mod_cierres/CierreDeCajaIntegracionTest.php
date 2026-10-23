<?php

/**
 * El cierre de caja: lo que `modulos/mod_cierres/funciones.php` calcula de los tickets cobrados —por
 * usuario, por forma de pago y por tipo de IVA— y el guardado (`InsertarProceso1Cierres()`), que crea el
 * cierre con sus IVA y sus usuarios y pasa sus tickets a «Cerrado».
 *
 * Contra la base, con la conexión del caso dentro de una transacción que se deshace: las funciones la
 * reciben como parámetro. Cada caso siembra sus tickets con su usuario, su número, su fecha, su forma de
 * pago y su desglose de IVA, y monta los datos del guardado como los deja la pantalla del cierre en la
 * página. Las horas son de mediodía: la pantalla mezcla fechas de la base y de PHP, y sus zonas horarias
 * pueden no coincidir. Otros módulos declaran funciones con el mismo nombre: cada caso corre en su propio
 * proceso, y el registro de PHP va a un fichero del caso.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModCierres;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class CierreDeCajaIntegracionTest extends CasoIntegracion
{
    private const DIA = '2031-05-10';

    private int $idTienda;
    private int $idCliente;
    private int $cajeraUno;
    private int $cajeroDos;
    private string $registroDePhp;

    protected function setUp(): void
    {
        parent::setUp();
        require_once RUTA_TPVFOX . '/modulos/mod_cierres/funciones.php';
        $this->registroDePhp = (string) tempnam(sys_get_temp_dir(), 'cierres');
        ini_set('error_log', $this->registroDePhp);

        $siembra = new Siembra($this->db);
        $this->idTienda = $siembra->tiendaPorDefecto();
        $this->idCliente = $siembra->clientePorDefecto();
        $this->cajeraUno = $this->insertar('usuarios', ['username' => 'cierre_uno', 'password' => 'sin-uso', 'fecha' => '2020-01-01', 'group_id' => 1, 'estado' => 'Activo', 'nombre' => 'Cajera Uno']);
        $this->cajeroDos = $this->insertar('usuarios', ['username' => 'cierre_dos', 'password' => 'sin-uso', 'fecha' => '2020-01-01', 'group_id' => 1, 'estado' => 'Activo', 'nombre' => 'Cajero Dos']);
    }

    protected function tearDown(): void
    {
        unlink($this->registroDePhp);
        parent::tearDown();
    }

    private function insertar(string $tabla, array $valores): int
    {
        $sentencia = $this->db->prepare(
            "INSERT INTO `$tabla` (`" . implode('`, `', array_keys($valores)) . '`) VALUES (' . implode(', ', array_fill(0, count($valores), '?')) . ')'
        );
        $sentencia->bind_param(str_repeat('s', count($valores)), ...array_map('strval', array_values($valores)));
        $sentencia->execute();

        return (int) $this->db->insert_id;
    }

    /**
     * Un ticket con su desglose de IVA: `$bases` da la base de cada tipo; la cuota y el total se calculan.
     *
     * @param array<int,float> $bases
     */
    private function ticket(int $idUsuario, int $numero, string $fecha, string $formaPago, array $bases, string $estado = 'Cobrado'): int
    {
        $total = 0.0;
        foreach ($bases as $iva => $base) {
            $total += $base + round($base * $iva / 100, 2);
        }
        $id = $this->insertar('ticketst', [
            'Numticket' => $numero, 'Numtempticket' => $numero, 'Fecha' => $fecha, 'idTienda' => $this->idTienda,
            'idUsuario' => $idUsuario, 'idCliente' => $this->idCliente, 'estado' => $estado, 'formaPago' => $formaPago,
            'entregado' => 0, 'total' => number_format($total, 2, '.', ''),
        ]);
        foreach ($bases as $iva => $base) {
            $this->insertar('ticketstIva', [
                'idticketst' => $id, 'Numticket' => $numero, 'iva' => $iva,
                'importeIva' => number_format(round($base * $iva / 100, 2), 2, '.', ''), 'totalbase' => number_format($base, 2, '.', ''),
            ]);
        }

        return $id;
    }

    /** Los tickets del día: dos de la cajera, en efectivo y con tarjeta, y uno del cajero; uno ya cerrado. */
    private function sembrarElDia(): array
    {
        return [
            'cerrado' => $this->ticket($this->cajeraUno, 9100, self::DIA . ' 10:00:00', 'Efectivo', [21 => 4.00], 'Cerrado'),
            'uno' => $this->ticket($this->cajeraUno, 9101, self::DIA . ' 11:00:00', 'Efectivo', [21 => 10.00]),
            'dos' => $this->ticket($this->cajeraUno, 9102, self::DIA . ' 12:00:00', 'Tarjeta', [21 => 20.00, 10 => 5.00]),
            'tres' => $this->ticket($this->cajeroDos, 9201, self::DIA . ' 13:00:00', 'Efectivo', [10 => 10.00]),
        ];
    }

    /** Ejecuta la llamada sin que el aviso de `strftime()` obsoleta llegue a PHPUnit. */
    private function sinObsoletas(callable $llamada): mixed
    {
        set_error_handler(static fn (): bool => true, E_DEPRECATED);
        try {
            return $llamada();
        } finally {
            restore_error_handler();
        }
    }

    /** Lo que deja la pantalla del cierre en la página, montado como lo monta ella (`CierreCaja.php:23-149`). */
    private function cierreComoLaPantalla(): array
    {
        $maxMin = \fechaMaxMinTickets($this->db);
        $maxMin['fechas']['Creacion'] = time();
        $maxMin['fechas']['Cierre'] = time();
        $fechas = [];
        foreach ($maxMin['fechas'] as $nombre => $unix) {
            $fechas += $this->sinObsoletas(static fn () => \ArrayFechaUnix($unix, $nombre));
        }
        $resumen = \ticketsPorFechaUsuario($fechas['fechaMin']['String_y-m-d'], $this->db, $fechas['fechaMax']['String_y-m-d']);
        $ivas = \baseIva($this->db, implode(',', $resumen['rangoTickets']));

        $cierre = [
            'tienda' => $this->idTienda, 'idUsuarioLogin' => $this->cajeraUno, 'sumasIvas' => $ivas['items'],
            'totalcaja' => $resumen['totalcaja'], 'rangoTickets' => $resumen['rangoTickets'],
            'fechaCierre' => $fechas['Cierre']['String_d-m-y'], 'fechaCreacion' => $fechas['Creacion']['String_d-m-y_hora'],
            'FI_unix' => $fechas['fechaMin']['Epoch-Unix'], 'FF_unix' => $fechas['fechaMax']['Epoch-Unix'],
            'fechaInicio_tickets' => $fechas['fechaMin']['String_d-m-y_hora'], 'fechaFinal_tickets' => $fechas['fechaMax']['String_d-m-y_hora'],
            'FinicioSINhora' => $fechas['fechaMin']['String_d-m-y'], 'FfinalSINhora' => $fechas['fechaMax']['String_d-m-y'],
        ];
        foreach ($resumen['usuarios'] as $id => $usuario) {
            $cierre['usuarios'][$id] = [
                'nombre' => \nombreUsuario($this->db, $id)['datos']['nombre'],
                'NumInicial' => $usuario['NumInicial'], 'NumFinal' => $usuario['NumFinal'], 'subtotal' => $usuario['total'],
            ];
            foreach ($usuario['formasPago'] as $forma => $importe) {
                $cierre['modoPago'][$id]['formasPago'][$forma] = number_format((float) $importe, 2);
            }
        }

        return $cierre;
    }

    private function guardar(array $datos): mixed
    {
        try {
            return $this->sinObsoletas(fn () => \InsertarProceso1Cierres($this->db, $datos));
        } catch (\Throwable $error) {
            return $error;
        }
    }

    private function valor(string $sql): ?string
    {
        $fila = $this->db->query($sql)->fetch_row();

        return $fila === null || $fila[0] === null ? null : (string) $fila[0];
    }

    private function filas(string $sql): array
    {
        return $this->db->query($sql)->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * @comportamiento Los tickets cobrados del intervalo se suman por usuario —su total, su primer y su
     *   último número y lo de cada forma de pago— y en el total de la caja; los cerrados y los de otro día no
     *   entran, y se devuelven los identificadores de los que entran.
     */
    public function test_losTicketsCobradosDelDiaSeSumanPorUsuarioYFormaDePago(): void
    {
        $tickets = $this->sembrarElDia();
        $this->ticket($this->cajeroDos, 9202, '2031-05-11 12:00:00', 'Efectivo', [21 => 50.00]);

        $resumen = \ticketsPorFechaUsuario(self::DIA, $this->db, self::DIA);

        $uno = $resumen['usuarios'][$this->cajeraUno];
        self::assertEqualsWithDelta(41.80, $uno['total'], 0.001);
        self::assertSame(['9101', '9102'], [$uno['NumInicial'], $uno['NumFinal']]);
        self::assertEqualsWithDelta(12.10, $uno['formasPago']['Efectivo'], 0.001);
        self::assertEqualsWithDelta(29.70, $uno['formasPago']['Tarjeta'], 0.001);
        self::assertEqualsWithDelta(11.00, $resumen['usuarios'][$this->cajeroDos]['total'], 0.001);
        self::assertEqualsWithDelta(52.80, $resumen['totalcaja'], 0.001);
        self::assertEqualsCanonicalizing([$tickets['uno'], $tickets['dos'], $tickets['tres']], array_map('intval', $resumen['rangoTickets']));
        self::assertEqualsCanonicalizing(['Efectivo', 'Tarjeta'], array_values($resumen['formasPago']));
    }

    /** @comportamiento El intervalo de la pantalla va del primer al último ticket cobrado; los cerrados no cuentan. */
    public function test_lasFechasDelCierreSonLasDelPrimerYElUltimoTicketCobrado(): void
    {
        $this->sembrarElDia();

        $fechas = \fechaMaxMinTickets($this->db)['fechas'];

        self::assertSame($this->valor("SELECT UNIX_TIMESTAMP('" . self::DIA . " 11:00:00')"), $fechas['fechaMin']);
        self::assertSame($this->valor("SELECT UNIX_TIMESTAMP('" . self::DIA . " 13:00:00')"), $fechas['fechaMax']);
    }

    /** @comportamiento Las bases y las cuotas de los tickets se suman por tipo de IVA; sin desglose, se devuelve 0. */
    public function test_lasBasesYLasCuotasSeSumanPorTipoDeIva(): void
    {
        $tickets = $this->sembrarElDia();

        $ivas = \baseIva($this->db, implode(',', [$tickets['uno'], $tickets['dos'], $tickets['tres']]));

        $porTipo = array_column($ivas['items'], null, 'iva');
        self::assertEquals(['importeIva' => '6.30', 'importeBase' => '30.00', 'iva' => '21'], $porTipo['21']);
        self::assertEquals(['importeIva' => '1.50', 'importeBase' => '15.00', 'iva' => '10'], $porTipo['10']);
        self::assertSame(0, \baseIva($this->db, '0'));
    }

    /**
     * @comportamiento Los tickets abiertos se cuentan por usuario, con su nombre de acceso, sea cual sea su
     *   fecha: la función recibe el intervalo pero no lo usa. Un ticket temporal que no está abierto no cuenta.
     */
    public function test_unTicketAbiertoDeCualquierFechaCuentaComoAbierto(): void
    {
        $this->insertar('ticketstemporales', ['numticket' => 1, 'estadoTicket' => 'Abierto', 'idTienda' => $this->idTienda, 'idUsuario' => $this->cajeroDos, 'fechaInicio' => '2031-04-01 12:00:00', 'idClientes' => $this->idCliente, 'total' => 0]);
        $this->insertar('ticketstemporales', ['numticket' => 2, 'estadoTicket' => 'Cobrado', 'idTienda' => $this->idTienda, 'idUsuario' => $this->cajeraUno, 'fechaInicio' => self::DIA . ' 12:00:00', 'idClientes' => $this->idCliente, 'total' => 0]);

        $abiertos = \obtenerTicketAbiertos($this->db, self::DIA, self::DIA);

        self::assertSame([['suma' => '1', 'idUsuario' => (string) $this->cajeroDos, 'username' => 'cierre_dos']], $abiertos);
    }

    /** @comportamiento El nombre de un usuario se toma con sus tickets: el de quien no tiene ninguno no se devuelve. */
    public function test_elNombreDeCadaUsuarioConTickets(): void
    {
        $this->sembrarElDia();

        self::assertSame(['username' => 'cierre_uno', 'nombre' => 'Cajera Uno'], array_intersect_key(\nombreUsuario($this->db, $this->cajeraUno)['datos'], ['username' => 0, 'nombre' => 0]));
        self::assertSame([], \nombreUsuario($this->db, $this->insertar('usuarios', ['username' => 'cierre_sin_tickets', 'password' => 'sin-uso', 'fecha' => '2020-01-01', 'group_id' => 1, 'estado' => 'Activo', 'nombre' => 'Sin Tickets'])));
    }

    /**
     * @comportamiento Guardar los datos de la pantalla crea el cierre con su total, su tienda, quien lo hace
     *   y el intervalo; guarda la base y la cuota de cada tipo de IVA, lo de cada usuario por forma de pago y
     *   su suma con su primer y su último ticket; y pasa los tickets del cierre a «Cerrado». Responde cada
     *   paso como correcto.
     */
    public function test_guardarElCierreLoCreaYCierraSusTickets(): void
    {
        $tickets = $this->sembrarElDia();

        $respuesta = $this->guardar($this->cierreComoLaPantalla());

        self::assertSame('Correcto', $respuesta['Proceso1']['insertarCierre']);
        self::assertSame(3, $respuesta['Proceso1']['Nafectados_CambioEstado_tickets']);
        self::assertSame('Correcto', $respuesta['Proceso2']['insertar_ivas_cierre']);
        self::assertSame('Correcto', $respuesta['Proceso3']['insertarTickets_usuarios']);
        $id = $respuesta['Proceso1']['idCierre'];
        self::assertSame(
            ['idTienda' => (string) $this->idTienda, 'idUsuario' => (string) $this->cajeraUno, 'Total' => '52.8000', 'inicio' => self::DIA, 'final' => self::DIA],
            $this->filas("SELECT idTienda, idUsuario, Total, DATE(FechaInicio) AS inicio, DATE(FechaFinal) AS final FROM cierres WHERE idCierre = $id")[0]
        );
        self::assertSame(
            [['tipo_iva' => '10', 'importe_base' => '15.0000', 'importe_iva' => '1.5000'], ['tipo_iva' => '21', 'importe_base' => '30.0000', 'importe_iva' => '6.3000']],
            $this->filas("SELECT tipo_iva, importe_base, importe_iva FROM cierres_ivas WHERE idCierre = $id ORDER BY tipo_iva")
        );
        self::assertSame(
            [
                ['idUsuario' => (string) $this->cajeraUno, 'FormasPago' => 'Efectivo', 'importe' => '12.1000'],
                ['idUsuario' => (string) $this->cajeraUno, 'FormasPago' => 'Tarjeta', 'importe' => '29.7000'],
                ['idUsuario' => (string) $this->cajeroDos, 'FormasPago' => 'Efectivo', 'importe' => '11.0000'],
            ],
            $this->filas("SELECT idUsuario, FormasPago, importe FROM cierres_usuariosFormasPago WHERE idCierre = $id ORDER BY idUsuario, FormasPago")
        );
        self::assertSame(
            [
                ['idUsuario' => (string) $this->cajeraUno, 'Importe' => '41.8000', 'Num_ticket_inicial' => '9101', 'Num_ticket_final' => '9102'],
                ['idUsuario' => (string) $this->cajeroDos, 'Importe' => '11.0000', 'Num_ticket_inicial' => '9201', 'Num_ticket_final' => '9201'],
            ],
            $this->filas("SELECT idUsuario, Importe, Num_ticket_inicial, Num_ticket_final FROM cierres_usuarios_tickets WHERE idCierre = $id ORDER BY idUsuario")
        );
        self::assertSame('3', $this->valor("SELECT COUNT(*) FROM ticketst WHERE estado = 'Cerrado' AND id IN ({$tickets['uno']}, {$tickets['dos']}, {$tickets['tres']})"));
    }

    /** @comportamiento Guardar otra vez los mismos datos se rechaza: sus tickets ya no están cobrados. */
    public function test_unSegundoGuardadoConLosMismosTicketsSeRechaza(): void
    {
        $this->sembrarElDia();
        $datos = $this->cierreComoLaPantalla();
        $this->guardar($datos);

        $respuesta = $this->guardar($datos);

        self::assertSame(['Proceso1' => ['error' => 'No tienes tiquets para cerrar']], $respuesta);
        self::assertSame('1', $this->valor('SELECT COUNT(*) FROM cierres WHERE FechaInicio >= \'' . self::DIA . '\''));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_cierres/funciones.php:118-150
     * @codigo-afectado modulos/mod_cierres/funciones.php:208-300
     *
     * @group defecto
     * @group cierres
     * @group alto
     *
     * @que-ocurre-hoy El cierre se guarda con el total, los IVA, los importes por usuario y forma de pago y
     *   los números de ticket que llegan en la petición: una petición con otro total crea un cierre que no
     *   cuadra con sus tickets.
     * @que-deberia-ocurrir Que el total del cierre, y el resto de sus importes, sea la suma de los tickets
     *   que cierra, calculada en el servidor al guardarlo.
     * @por-que-ocurre La pantalla calcula el cierre y lo deja en la página; el guardado recibe ese array del
     *   navegador y pega sus valores en las inserciones sin volver a calcularlos.
     * @como-deberia-funcionar Calcular el cierre en el guardado, a partir de los tickets cobrados del
     *   intervalo, y recibir del navegador solo el intervalo elegido.
     */
    public function test_elTotalDelCierreEsElDeSusTickets(): void
    {
        $this->sembrarElDia();
        $datos = $this->cierreComoLaPantalla();
        $datos['totalcaja'] = '1.00';

        $respuesta = $this->guardar($datos);

        self::assertSame('52.8000', $this->valor("SELECT Total FROM cierres WHERE idCierre = {$respuesta['Proceso1']['idCierre']}"));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_cierres/funciones.php:118-140
     * @codigo-afectado modulos/mod_cierres/CierreCaja.php:26,122-128
     *
     * @group defecto
     * @group cierres
     * @group alto
     *
     * @que-ocurre-hoy Quién hace el cierre, en qué tienda y su fecha de creación son los que llegan en la
     *   petición: se puede guardar a nombre de otro usuario y con cualquier fecha. Aun sin tocar la petición,
     *   la fecha de creación es la de cuando se abrió la pantalla, no la de cuando se guardó.
     * @que-deberia-ocurrir Que el usuario y la tienda sean los de la sesión, y la fecha de creación la del
     *   sistema al guardar.
     * @por-que-ocurre La pantalla pone el usuario, la tienda y la hora al pintarse, en el array que deja en
     *   la página, y el guardado los toma de ese array.
     * @como-deberia-funcionar Tomar en el guardado el usuario y la tienda de la sesión y la fecha de creación
     *   del reloj del sistema (o de la base).
     */
    public function test_quienCierraYCuandoLosPoneElServidor(): void
    {
        $this->sembrarElDia();
        $_SESSION['usuarioTpv'] = ['id' => $this->cajeraUno];
        $_SESSION['tiendaTpv'] = ['idTienda' => $this->idTienda];
        $datos = $this->cierreComoLaPantalla();
        $datos['idUsuarioLogin'] = $this->cajeroDos;
        $datos['fechaCreacion'] = '01-01-2020 08:00:00';

        $respuesta = $this->guardar($datos);

        $cierre = $this->filas("SELECT idUsuario, FechaCreacion FROM cierres WHERE idCierre = {$respuesta['Proceso1']['idCierre']}")[0];
        self::assertSame((string) $this->cajeraUno, $cierre['idUsuario']);
        self::assertGreaterThan('2026-01-01', $cierre['FechaCreacion']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_cierres/funciones.php:164-199
     *
     * @group defecto
     * @group cierres
     * @group alto
     *
     * @que-ocurre-hoy Si la base rechaza una de las inserciones que siguen a la del cierre —aquí, una cuota
     *   de IVA que no es un importe—, el cierre queda creado sin sus IVA ni sus usuarios, y sus tickets ya
     *   pasados a «Cerrado»: la caja queda cerrada a medias, y no se puede volver a cerrar.
     * @que-deberia-ocurrir Que el cierre se guarde entero o no se guarde: si falla cualquier paso, ni cierre
     *   ni tickets cambiados.
     * @por-que-ocurre Las inserciones y el cambio de estado van uno tras otro sin transacción, y el fallo de
     *   la base llega como excepción, que nadie recoge.
     * @como-deberia-funcionar Hacer el guardado en una transacción y deshacerla si falla cualquier paso.
     */
    public function test_unCierreQueFallaAMedioGuardarNoDejaNadaHecho(): void
    {
        $tickets = $this->sembrarElDia();
        $datos = $this->cierreComoLaPantalla();
        $datos['sumasIvas'][0]['importeIva'] = 'sin importe';

        $resultado = $this->guardar($datos);

        self::assertInstanceOf(\Throwable::class, $resultado);
        self::assertSame('0', $this->valor('SELECT COUNT(*) FROM cierres WHERE FechaInicio >= \'' . self::DIA . '\''));
        self::assertSame('3', $this->valor("SELECT COUNT(*) FROM ticketst WHERE estado = 'Cobrado' AND id IN ({$tickets['uno']}, {$tickets['dos']}, {$tickets['tres']})"));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_cierres/funciones.php:151,186-187
     *
     * @group defecto
     * @group cierres
     * @group bajo
     *
     * @que-ocurre-hoy La respuesta del guardado, que llega al navegador, lleva las sentencias de la base que
     *   ejecutó —la inserción del cierre, el cambio de estado de los tickets y las de los IVA y los
     *   usuarios—, y la del cierre se escribe además en el registro de PHP en cada guardado.
     * @que-deberia-ocurrir Que la respuesta diga qué se hizo, sin las sentencias.
     * @por-que-ocurre El guardado anota cada sentencia en su resultado para depurar, y escribe la primera con
     *   `error_log()`.
     * @como-deberia-funcionar Quitar las sentencias de la respuesta y la escritura del registro.
     */
    public function test_laRespuestaDelGuardadoNoLlevaLasSentencias(): void
    {
        $this->sembrarElDia();

        $respuesta = $this->guardar($this->cierreComoLaPantalla());

        self::assertArrayNotHasKey('insertCierres', $respuesta['Proceso1']);
        self::assertArrayNotHasKey('updateTicketst', $respuesta['Proceso1']);
    }
}
