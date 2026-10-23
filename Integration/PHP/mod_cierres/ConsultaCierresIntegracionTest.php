<?php

/**
 * Las consultas de los cierres guardados: las de `ClaseCierres` —el listado, el último cierre y los
 * tickets de un usuario en un cierre, que usa también el listado de tickets del TPV— y las de
 * `modulos/mod_cierres/funciones.php` para la vista de un cierre y el resumen entre fechas.
 *
 * Contra la base, con la conexión del caso dentro de una transacción que se deshace. La clase abre su
 * propia conexión al construirse: aquí se crea sin constructor y se le da la del caso. Los cierres los
 * siembra el caso fila a fila, en sus cuatro tablas. Otros módulos declaran funciones con el mismo
 * nombre: cada caso corre en su propio proceso.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModCierres;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ConsultaCierresIntegracionTest extends CasoIntegracion
{
    private int $idTienda;
    private int $idCliente;
    private int $cajeraUno;
    private int $cajeroDos;
    private \ClaseCierres $cierres;

    protected function setUp(): void
    {
        parent::setUp();
        require_once RUTA_TPVFOX . '/modulos/mod_cierres/funciones.php';
        $this->incluirTPVFox('/modulos/mod_cierres/clases/ClaseCierres.php');
        $this->cierres = (new \ReflectionClass(\ClaseCierres::class))->newInstanceWithoutConstructor();
        $this->cierres->BDTpv = $this->db;

        $siembra = new Siembra($this->db);
        $this->idTienda = $siembra->tiendaPorDefecto();
        $this->idCliente = $siembra->clientePorDefecto();
        $this->cajeraUno = $this->insertar('usuarios', ['username' => 'cierre_uno', 'password' => 'sin-uso', 'fecha' => '2020-01-01', 'group_id' => 1, 'estado' => 'Activo', 'nombre' => 'Cajera Uno']);
        $this->cajeroDos = $this->insertar('usuarios', ['username' => 'cierre_dos', 'password' => 'sin-uso', 'fecha' => '2020-01-01', 'group_id' => 1, 'estado' => 'Activo', 'nombre' => 'Cajero Dos']);
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
     * Un cierre del día, hecho por la cajera, con sus IVA, lo de cada usuario por forma de pago y su rango
     * de tickets.
     *
     * @param array<int,array{0:float,1:float}> $ivas tipo => [base, cuota]
     * @param array<int,array{formas:array<string,float>,desde:int,hasta:int}> $usuarios
     */
    private function cierre(string $dia, float $total, array $ivas, array $usuarios): int
    {
        $id = $this->insertar('cierres', [
            'FechaCierre' => $dia, 'idTienda' => $this->idTienda, 'idUsuario' => $this->cajeraUno,
            'FechaInicio' => "$dia 09:00:00", 'FechaFinal' => "$dia 20:00:00", 'FechaCreacion' => "$dia 21:00:00", 'Total' => $total,
        ]);
        foreach ($ivas as $tipo => [$base, $cuota]) {
            $this->insertar('cierres_ivas', ['idCierre' => $id, 'idTienda' => $this->idTienda, 'tipo_iva' => $tipo, 'importe_base' => $base, 'importe_iva' => $cuota]);
        }
        foreach ($usuarios as $idUsuario => $usuario) {
            foreach ($usuario['formas'] as $forma => $importe) {
                $this->insertar('cierres_usuariosFormasPago', ['idCierre' => $id, 'idTienda' => $this->idTienda, 'idUsuario' => $idUsuario, 'FormasPago' => $forma, 'importe' => $importe]);
            }
            $this->insertar('cierres_usuarios_tickets', [
                'idCierre' => $id, 'idUsuario' => $idUsuario, 'idTienda' => $this->idTienda, 'Importe' => array_sum($usuario['formas']),
                'Num_ticket_inicial' => $usuario['desde'], 'Num_ticket_final' => $usuario['hasta'],
            ]);
        }

        return $id;
    }

    /** Dos cierres en días seguidos. */
    private function sembrarDosCierres(): array
    {
        return [
            'primero' => $this->cierre('2031-05-10', 52.80, [21 => [30.00, 6.30], 10 => [15.00, 1.50]], [
                $this->cajeraUno => ['formas' => ['Efectivo' => 12.10, 'Tarjeta' => 29.70], 'desde' => 9101, 'hasta' => 9102],
                $this->cajeroDos => ['formas' => ['Efectivo' => 11.00], 'desde' => 9201, 'hasta' => 9201],
            ]),
            'segundo' => $this->cierre('2031-05-11', 24.20, [21 => [20.00, 4.20]], [
                $this->cajeraUno => ['formas' => ['Efectivo' => 24.20], 'desde' => 9103, 'hasta' => 9103],
            ]),
        ];
    }

    private function ticket(int $idUsuario, int $numero, string $estado = 'Cerrado'): int
    {
        return $this->insertar('ticketst', [
            'Numticket' => $numero, 'Numtempticket' => $numero, 'Fecha' => '2031-05-10 12:00:00', 'idTienda' => $this->idTienda,
            'idUsuario' => $idUsuario, 'idCliente' => $this->idCliente, 'estado' => $estado, 'formaPago' => 'Efectivo', 'entregado' => 0, 'total' => 1,
        ]);
    }

    private function intentar(callable $llamada): mixed
    {
        try {
            return $llamada();
        } catch (\Throwable $error) {
            return get_class($error) . ': ' . $error->getMessage();
        }
    }

    /**
     * @comportamiento El listado da cada cierre con el nombre de quien lo hizo; el filtro y el límite que
     *   recibe se añaden a la consulta tal cual, y un filtro que la base rechaza devuelve la consulta y el
     *   error.
     */
    public function test_elListadoDaLosCierresConQuienLosHizo(): void
    {
        $ids = $this->sembrarDosCierres();

        $todos = array_column($this->cierres->obtenerCierres(), null, 'idCierre');
        $delSegundoDia = $this->cierres->obtenerCierres(' FechaCierre between "2031-05-11" AND "2031-05-11"');

        self::assertSame('Cajera Uno', $todos[$ids['primero']]['nombreUsuario']);
        self::assertSame('52.8000', $todos[$ids['primero']]['Total']);
        self::assertSame([(string) $ids['segundo']], array_column($delSegundoDia, 'idCierre'));
        self::assertCount(1, $this->cierres->obtenerCierres('', ' LIMIT 1'));
    }

    /** @comportamiento El último cierre es el de número más alto, como texto. */
    public function test_elUltimoCierreEsElDeNumeroMasAlto(): void
    {
        $ids = $this->sembrarDosCierres();

        self::assertSame((string) $ids['segundo'], $this->cierres->UltimoIdCierre());
    }

    /**
     * @comportamiento La vista de un cierre recibe su cabecera, su desglose de IVA, lo de cada usuario por
     *   forma de pago, con su nombre de acceso, y cada usuario con su primer y su último ticket.
     */
    public function test_unCierreSeLeeConSusIvasSusFormasDePagoYSusUsuarios(): void
    {
        $id = $this->sembrarDosCierres()['primero'];

        $cierre = \obtenerCierreUnico($this->db, $id);

        self::assertSame('52.8000', $cierre['cierres'][$id]['Total']);
        self::assertSame((string) $this->cajeraUno, $cierre['cierres'][$id]['idUsuario']);
        self::assertEqualsCanonicalizing(['21', '10'], array_column($cierre['ivas'][$id], 'tipo_iva'));
        self::assertSame(['Efectivo' => '12.1000', 'Tarjeta' => '29.7000'], $cierre['fpago'][$this->cajeraUno]['formas']);
        self::assertSame('cierre_dos', $cierre['fpago'][$this->cajeroDos]['nombre']);
        self::assertEqualsCanonicalizing(
            [['cierre_uno', '9101', '9102'], ['cierre_dos', '9201', '9201']],
            array_map(static fn (array $u): array => [$u['nombreUsuario'], $u['Num_ticket_inicial'], $u['Num_ticket_final']], $cierre['usuario'])
        );
    }

    /**
     * @comportamiento El rango de tickets de un usuario en un cierre se lee de la clase y de las funciones;
     *   un usuario que no está en el cierre da el aviso de que no hay registros.
     */
    public function test_elRangoDeTicketsDeUnUsuarioEnUnCierre(): void
    {
        $ids = $this->sembrarDosCierres();

        self::assertSame(['Num_ticket_inicial' => '9101', 'Num_ticket_final' => '9102'], $this->cierres->obtenerRangoTicketsUsuarioCierre($this->cajeraUno, $ids['primero'], $this->idTienda));
        self::assertSame(['Num_ticket_inicial' => '9201', 'Num_ticket_final' => '9201'], \obtenerRangoTicketsUsuarioCierre($this->db, $this->cajeroDos, $ids['primero'], $this->idTienda));
        self::assertSame(' No hay registros o hay mas de un registro', $this->cierres->obtenerRangoTicketsUsuarioCierre($this->cajeroDos, $ids['segundo'], $this->idTienda)['error']);
    }

    /**
     * @comportamiento Los tickets de un usuario en un cierre son los suyos de la tienda cuyo número cae en su
     *   rango, con el nombre del cliente; el filtro que recibe se añade cambiando su WHERE por AND.
     */
    public function test_losTicketsDeUnUsuarioEnUnCierre(): void
    {
        $id = $this->sembrarDosCierres()['primero'];
        $dentro = [$this->ticket($this->cajeraUno, 9101), $this->ticket($this->cajeraUno, 9102)];
        $this->ticket($this->cajeraUno, 9103);
        $this->ticket($this->cajeroDos, 9102);

        $tickets = $this->cierres->obtenerTicketsUsuariosCierre($this->cajeraUno, $id, $this->idTienda);
        $filtrados = $this->cierres->obtenerTicketsUsuariosCierre($this->cajeraUno, $id, $this->idTienda, 'WHERE t.Numticket = 9102');

        self::assertEqualsCanonicalizing($dentro, array_map('intval', array_column($tickets['datos'], 'id')));
        self::assertSame('Cliente de pruebas', $tickets['datos'][0]['Nombre']);
        self::assertSame([(string) $dentro[1]], array_column($filtrados['datos'], 'id'));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_cierres/clases/ClaseCierres.php:128-130,159
     *
     * @group defecto
     * @group cierres
     * @group medio
     *
     * @que-ocurre-hoy Los tickets de un usuario en un cierre —que el listado de tickets del TPV pide con el
     *   usuario y el cierre que llegan en la dirección— pegan los dos números en la consulta, sin comillas ni
     *   comprobación: un usuario que no es un número rompe la consulta, que termina con un error de la base.
     * @que-deberia-ocurrir Que un número que no lo es no encuentre ningún ticket.
     * @por-que-ocurre El rango y los tickets se leen componiendo la sentencia con los valores recibidos.
     * @como-deberia-funcionar Comprobar que son números y pasarlos como parámetros de una sentencia preparada.
     */
    public function test_unUsuarioQueNoEsUnNumeroNoRompeLaConsultaDeTickets(): void
    {
        $id = $this->sembrarDosCierres()['primero'];

        self::assertIsArray($this->intentar(fn () => $this->cierres->obtenerTicketsUsuariosCierre('abc', $id, $this->idTienda)));
    }

    /**
     * @comportamiento El resumen entre fechas suma, de los cierres de su intervalo, la base y la cuota de un
     *   tipo de IVA, lo de cada usuario y lo de cada forma de pago con cuántas veces aparece.
     */
    public function test_elResumenEntreFechasSumaIvasUsuariosYFormasDePago(): void
    {
        $this->sembrarDosCierres();
        $filtro = ' FechaCierre between "2031-05-10" AND "2031-05-11"';

        $iva21 = \sumDatosIva($this->db, '21', $filtro);
        $usuarios = array_column(\UsuariosCierre($this->db, '2031-05-10', '2031-05-11'), 'importe', 'idUsuario');
        $formas = array_column(\cantMOdPago($this->db, '2031-05-10', '2031-05-11'), null, 'FormasPago');

        self::assertSame(['50.0000', '10.5000'], [$iva21['base'], $iva21['iva']]);
        self::assertSame('66.0000', $usuarios[$this->cajeraUno]);
        self::assertSame('11.0000', $usuarios[$this->cajeroDos]);
        self::assertSame(['3', '47.3000'], [$formas['Efectivo']['total'], $formas['Efectivo']['importe']]);
        self::assertSame(['Cajera Uno'], array_values(\datosUsuario($this->db, $this->cajeraUno)));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_cierres/funciones.php:654-664
     * @codigo-afectado modulos/mod_cierres/funciones.php:675-685
     *
     * @group defecto
     * @group cierres
     * @group bajo
     *
     * @que-ocurre-hoy Un intervalo sin cierres deja avisos de PHP: el resumen por usuario y el de formas de
     *   pago devuelven una variable sin definir, y la pantalla del resumen recorre ese nulo.
     * @que-deberia-ocurrir Que, sin cierres, los dos resúmenes devuelvan una lista vacía.
     * @por-que-ocurre La lista solo se crea dentro del bucle de filas.
     * @como-deberia-funcionar Darle un valor vacío de partida.
     */
    public function test_unIntervaloSinCierresDaResumenesVacios(): void
    {
        self::assertSame([], $this->intentar(fn () => \UsuariosCierre($this->db, '2040-01-01', '2040-01-02')));
        self::assertSame([], $this->intentar(fn () => \cantMOdPago($this->db, '2040-01-01', '2040-01-02')));
    }
}
