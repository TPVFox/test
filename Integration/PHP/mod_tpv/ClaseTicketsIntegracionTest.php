<?php

/**
 * La clase de los tickets del TPV (`modulos/mod_tpv/clases/ClaseTickets.php`): leer un ticket cobrado
 * con sus líneas y su desglose, listar los tickets por estado y fechas y los temporales abiertos,
 * prepararlo para imprimir, y cambiar el cliente, la forma de pago y la fecha de un ticket ya grabado.
 *
 * La clase abre su propia conexión al crearse, a partir de la sesión: aquí se crea sin su constructor,
 * con la conexión del caso y la tienda de pruebas, para que vea lo que siembra el caso y lo que cambia
 * se deshaga al terminar. El fichero de funciones del módulo, que la impresión usa, declara funciones
 * con el mismo nombre que las de otros módulos: cada caso corre en su propio proceso.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModTpv;

use TPVFox\Test\CargaAislada;
use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ClaseTicketsIntegracionTest extends CasoIntegracion
{
    protected bool $compartirConexionConElProducto = true;

    private Siembra $siembra;

    private int $idTienda;

    private int $idUsuario;

    private int $idCliente;

    private int $pera;

    protected function setUp(): void
    {
        // La clase de sesión incluye la de conexión sin «_once»: tiene que llegar antes que la base común.
        $this->incluirTPVFox('/clases/ClaseSession.php');
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_tpv/clases/ClaseTickets.php');
        CargaAislada::requerir(RUTA_TPVFOX . '/modulos/mod_tpv/funciones.php');
        $this->siembra = new Siembra($this->db);
        $this->idTienda = $this->siembra->tiendaPorDefecto();
        $this->idUsuario = $this->siembra->usuarioPorDefecto();
        $this->idCliente = $this->siembra->cliente('Bar Pepe de tpv', ['razonsocial' => 'Pepe SL']);
        $this->pera = $this->siembra->articulo('Pera', ['iva' => 10]);
    }

    /** @comportamiento Un ticket cobrado se lee con su cabecera —cliente y empleado incluidos—, sus líneas y sus bases e impuestos por tipo. */
    public function test_unTicketCobradoSeLeeConSusLineasYSuDesglose(): void
    {
        $id = $this->ticket('Cobrado', '2026-10-24 10:00:00', '3.30');

        $ticket = $this->tickets()->obtenerUnTicket($id);

        self::assertSame(['Cobrado', '3.30', 'Bar Pepe de tpv', 'Pepe SL', 'pruebas'], [$ticket['cabecera']['estado'], $ticket['cabecera']['total'], $ticket['cabecera']['Nombre'], $ticket['cabecera']['razonsocial'], $ticket['cabecera']['username']]);
        self::assertSame([['Pera', '3.000000', '1.10', 'Activo']], array_map(static fn (array $l): array => [$l['cdetalle'], $l['nunidades'], $l['precioCiva'], $l['estadoLinea']], $ticket['lineas']));
        self::assertSame([['0.30', '3.00', '10']], array_map(static fn (array $i): array => [$i['importeIva'], $i['importeBase'], $i['iva']], $ticket['basesYivas']));
        self::assertArrayNotHasKey('error', $ticket);
    }

    /** @comportamiento Un ticket de otra tienda no se lee: la respuesta dice que no encontró ninguno con ese número. */
    public function test_unTicketDeOtraTiendaNoSeLee(): void
    {
        $id = $this->ticket('Cobrado', '2026-10-24 10:00:00', '3.30', $this->siembra->tienda('2026', 'web'));

        $ticket = $this->tickets()->obtenerUnTicket($id);

        self::assertSame('Error 0 registros encontrado para el idticketst ' . $id, $ticket['error'][0]['mensaje']);
    }

    /** @comportamiento Los tickets se listan por estado entre dos fechas, del más reciente al más antiguo; sin ninguno, la respuesta lo dice. */
    public function test_losTicketsSeListanPorEstadoYFechas(): void
    {
        $antiguo = $this->ticket('Cobrado', '2031-03-02 10:00:00', '1.10');
        $reciente = $this->ticket('Cobrado', '2031-03-05 10:00:00', '2.20');
        $this->ticket('Cerrado', '2031-03-03 10:00:00', '3.30');
        $this->ticket('Cobrado', '2031-04-01 10:00:00', '4.40');

        $marzo = $this->tickets()->obtenerTickets('Cobrado', ['inicio' => '2031-03-01', 'final' => '2031-03-31 23:59:59']);
        $ninguno = $this->tickets()->obtenerTickets('Cobrado', ['inicio' => '2031-05-01', 'final' => '2031-05-31 23:59:59']);

        self::assertSame([(string) $reciente, (string) $antiguo], array_column($marzo['datos'], 'id'));
        self::assertSame('Bar Pepe de tpv', $marzo['datos'][0]['Nombre']);
        self::assertSame('No se encontro ningun registro en el metodo obtenerTickets', $ninguno['error']);
    }

    /** @comportamiento Los temporales abiertos de la tienda se listan con su empleado y su cliente, menos el que se está viendo. */
    public function test_losTemporalesAbiertosSeListanMenosElQueSeEsta(): void
    {
        $this->temporal(5, 'Abierto');
        $this->temporal(6, 'Abierto');
        $this->temporal(7, 'Cobrado');

        $abiertos = $this->tickets()->ObtenerTicketsAbiertos(6);

        self::assertSame([['5', 'Usuario de pruebas', 'Bar Pepe de tpv']], array_map(static fn (array $t): array => [$t['numticket'], $t['usuario'], $t['Nombre']], $abiertos['items']));
    }

    /**
     * @comportamiento El ticket se prepara para imprimir: la tienda, «factura simplificada», el cliente si
     *   no es el genérico, la fecha, la serie y el número, una línea por producto activo con unidades,
     *   precio e importe, el desglose, el total, la forma de pago, lo entregado y el cambio.
     */
    public function test_elTicketSePreparaParaImprimir(): void
    {
        $id = $this->ticket('Cobrado', '2026-10-24 10:05:00', '3.30', null, '5.00');

        $impresion = $this->tickets()->prepararParaImprimirTicket($this->tickets()->obtenerUnTicket($id));

        self::assertSame("Tienda de pruebas\n", $impresion['cabecera1']);
        self::assertStringContainsString('FACTURA  SIMPLIFICADA', $impresion['cabecera2']);
        self::assertStringContainsString(' id:' . $this->idCliente . ' Bar Pepe de tpv' . "\nPepe SL\n NIF:", $impresion['cabecera2-datos']);
        self::assertStringContainsString('Fecha:24/10/2026 Hora: 10:05' . "\n" . ' Serie:' . $this->idTienda . '-' . $this->idUsuario . ' Numero:9001', $impresion['cabecera2-datos']);
        self::assertSame(' (id:' . $this->pera . ') Pera' . "\n" . '         3 X       1.10 =       3.30 (10)' . "\n", $impresion['body']);
        self::assertStringContainsString('3.00  -> 10%  -> 0.30', $impresion['pie-datos']);
        self::assertSame(['3.30', 'contado', '5.00', '1.70'], [$impresion['pie-total'], $impresion['pie-formaPago'], $impresion['pie-entregado'], $impresion['pie-cambio']]);
    }

    /** @comportamiento El último ticket cobrado es el de número interno más alto en ese estado; el primero de un estado da su fecha. */
    public function test_elUltimoYElPrimerTicketDeUnEstado(): void
    {
        $this->ticket('Cobrado', '2026-10-20 10:00:00', '1.10');
        $ultimo = $this->ticket('Cobrado', '2026-10-24 10:00:00', '2.20');

        self::assertSame((string) $ultimo, $this->tickets()->ultimoTicketCobrado()['ultimo']);
        self::assertSame('2026-10-20 10:00:00', $this->tickets()->getPrimerTicket('Cobrado')['fecha']);
    }

    /** @comportamiento Un ticket cobrado cambia de cliente, de forma de pago y de fecha. */
    public function test_unTicketCobradoCambiaDeClienteFormaDePagoYFecha(): void
    {
        $id = $this->ticket('Cobrado', '2026-10-24 10:00:00', '3.30');
        $otro = $this->siembra->cliente('Bar Luis de tpv');

        $this->tickets()->modificarClienteTicket($id, $otro);
        $this->tickets()->cambiarFormaPagoTicket($id, 'tarjeta');
        $this->tickets()->cambiarFechaTickets([$id], '2026-10-23');

        self::assertSame([(string) $otro, 'tarjeta', '2026-10-23 00:00:00'], $this->cabecera($id, ['idCliente', 'formaPago', 'Fecha']));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tpv/clases/ClaseTickets.php:469-493
     *
     * @group defecto
     * @group integridad
     * @group alto
     *
     * @que-ocurre-hoy Un ticket cerrado —ya incluido en un cierre de caja— cambia de cliente, de forma de
     *   pago y de fecha, sin que quede constancia del cambio.
     * @que-deberia-ocurrir Que un ticket cerrado no se cambie.
     * @por-que-ocurre Los tres cambios actualizan el ticket por su número sin mirar su estado.
     * @como-deberia-funcionar Rechazar el cambio de un ticket que no esté cobrado.
     */
    public function test_unTicketCerradoNoSeCambia(): void
    {
        $id = $this->ticket('Cerrado', '2026-10-24 10:00:00', '3.30');
        $otro = $this->siembra->cliente('Bar Luis de tpv');

        $this->tickets()->modificarClienteTicket($id, $otro);
        $this->tickets()->cambiarFormaPagoTicket($id, 'tarjeta');
        $this->tickets()->cambiarFechaTickets([$id], '2026-10-23');

        self::assertSame([(string) $this->idCliente, 'contado', '2026-10-24 10:00:00'], $this->cabecera($id, ['idCliente', 'formaPago', 'Fecha']));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tpv/clases/ClaseTickets.php:478-483; modulos/mod_tpv/tareas.php:195-203
     *
     * @group defecto
     * @group seguridad
     * @group alto
     *
     * @que-ocurre-hoy La forma de pago que llega de la página se pega a la sentencia: una escrita a
     *   propósito cambia también el total del ticket cobrado.
     * @que-deberia-ocurrir Que la forma de pago sea un dato, y una de las que ofrece la página.
     * @por-que-ocurre La sentencia se arma con la forma de pago tal como llega.
     * @como-deberia-funcionar Pasarla como parámetro y comprobarla contra la lista de formas de pago.
     */
    public function test_laFormaDePagoNoCambiaElTotal(): void
    {
        $id = $this->ticket('Cobrado', '2026-10-24 10:00:00', '3.30');

        $this->tickets()->cambiarFormaPagoTicket($id, 'tarjeta", total="0');

        self::assertSame('3.30', $this->cabecera($id, ['total'])[0]);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tpv/clases/ClaseTickets.php:166-173,268-291; modulos/mod_tpv/funciones.php:678-690
     *
     * @group defecto
     * @group errores
     * @group medio
     *
     * @que-ocurre-hoy Un ticket sin desglose —el de cero euros, que el cobro graba sin él— se lee con un
     *   error, y la lectura deja avisos de PHP.
     * @que-deberia-ocurrir Que se lea sin error, con el desglose vacío.
     * @por-que-ocurre La lectura del desglose toma la falta de filas como error, y arma el mensaje con una
     *   variable que no existe.
     * @como-deberia-funcionar Tomar la falta de desglose como desglose vacío.
     */
    public function test_unTicketSinDesgloseSeLeeSinError(): void
    {
        $id = $this->ticket('Cobrado', '2026-10-24 10:00:00', '0.00');
        $this->db->query('DELETE FROM ticketstIva WHERE idticketst = ' . $id);
        $avisos = [];
        set_error_handler(static function (int $nivel, string $mensaje) use (&$avisos): bool {
            $avisos[] = $mensaje;

            return true;
        });
        try {
            $ticket = $this->tickets()->obtenerUnTicket($id);
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $avisos);
        self::assertArrayNotHasKey('error', $ticket);
    }

    private function tickets(): \ClaseTickets
    {
        $tickets = (new \ReflectionClass(\ClaseTickets::class))->newInstanceWithoutConstructor();
        $tickets->BDTpv = $this->db;
        $tickets->Tienda = ['idTienda' => $this->idTienda, 'razonsocial' => 'Tienda de pruebas SL', 'NombreComercial' => 'Tienda de pruebas', 'nif' => 'B00000000', 'direccion' => 'Calle de pruebas 1', 'telefono' => '986000000'];

        return $tickets;
    }

    /** Un ticket de tres de Pera a 1,10 €, con su desglose, en el estado, la fecha y la tienda dados. */
    private function ticket(string $estado, string $fecha, string $total, ?int $idTienda = null, string $entregado = '3.30'): int
    {
        $sentencia = $this->db->prepare('INSERT INTO ticketst (Numticket, Numtempticket, Fecha, idTienda, idUsuario, idCliente, estado, formaPago, entregado, total) VALUES (9001, 1, ?, ?, ?, ?, ?, "contado", ?, ?)');
        $tienda = $idTienda ?? $this->idTienda;
        $sentencia->bind_param('siiisss', $fecha, $tienda, $this->idUsuario, $this->idCliente, $estado, $entregado, $total);
        $sentencia->execute();
        $id = (int) $this->db->insert_id;
        $this->db->query("INSERT INTO ticketslinea (idticketst, Numticket, idArticulo, cref, ccodbar, cdetalle, ncant, nunidades, precioCiva, iva, nfila, estadoLinea) VALUES ({$id}, 9001, {$this->pera}, '1001', '', 'Pera', 3, 3, 1.10, 10, 1, 'Activo')");
        $this->db->query("INSERT INTO ticketstIva (idticketst, Numticket, iva, importeIva, totalbase) VALUES ({$id}, 9001, 10, 0.30, 3.00)");

        return $id;
    }

    private function temporal(int $numero, string $estado): void
    {
        $this->db->query("INSERT INTO ticketstemporales (numticket, estadoTicket, idTienda, idUsuario, fechaInicio, idClientes, total, Productos) VALUES ({$numero}, '{$estado}', {$this->idTienda}, {$this->idUsuario}, '2026-10-24 09:00:00', {$this->idCliente}, 0, '[]')");
    }

    /**
     * @param list<string> $campos
     * @return list<string>
     */
    private function cabecera(int $id, array $campos): array
    {
        $fila = $this->db->query('SELECT * FROM ticketst WHERE id = ' . $id)->fetch_assoc();

        return array_map(static fn (string $campo): string => (string) $fila[$campo], $campos);
    }
}
