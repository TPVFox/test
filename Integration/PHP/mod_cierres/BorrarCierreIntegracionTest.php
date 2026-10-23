<?php

/**
 * El borrado del último cierre (`ClaseCierres::borrarDatos_tablasCierres()`): devuelve sus tickets a
 * «Cobrado», borra sus filas de las cuatro tablas de cierres y vuelve a fijar el contador de `cierres`.
 *
 * Sin aislamiento por transacción: el borrado cambia el contador con DDL, que confirma la transacción en
 * curso. El caso siembra sus tickets y sus cierres confirmados, con números de ticket que no usa la base, y
 * al terminar los retira y devuelve el contador de `cierres` al valor que tenía. La clase abre su propia
 * conexión al construirse: aquí se crea sin constructor y se le da la del caso.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModCierres;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class BorrarCierreIntegracionTest extends CasoIntegracion
{
    protected bool $aislarPorTransaccion = false;

    private int $idTienda;
    private int $idUsuario;
    private int $idCliente;
    private string $contador;
    private \ClaseCierres $cierres;
    /** @var array<string,int[]> */
    private array $sembrado = ['cierres' => [], 'ticketst' => []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_cierres/clases/ClaseCierres.php');
        $this->cierres = (new \ReflectionClass(\ClaseCierres::class))->newInstanceWithoutConstructor();
        $this->cierres->BDTpv = $this->db;
        $this->contador = $this->valor("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cierres'");

        $siembra = new Siembra($this->db);
        $this->idTienda = $siembra->tiendaPorDefecto();
        $this->idUsuario = $siembra->usuarioPorDefecto();
        $this->idCliente = $siembra->clientePorDefecto();
    }

    protected function tearDown(): void
    {
        $cierres = implode(',', $this->sembrado['cierres'] ?: [0]);
        foreach (['cierres_usuarios_tickets', 'cierres_usuariosFormasPago', 'cierres_ivas', 'cierres'] as $tabla) {
            $this->db->query("DELETE FROM $tabla WHERE idCierre IN ($cierres)");
        }
        $this->db->query('DELETE FROM ticketst WHERE id IN (' . implode(',', $this->sembrado['ticketst'] ?: [0]) . ')');
        $this->db->query('ALTER TABLE cierres AUTO_INCREMENT = ' . (int) $this->contador);
        parent::tearDown();
    }

    private function insertar(string $tabla, array $valores): int
    {
        $sentencia = $this->db->prepare(
            "INSERT INTO `$tabla` (`" . implode('`, `', array_keys($valores)) . '`) VALUES (' . implode(', ', array_fill(0, count($valores), '?')) . ')'
        );
        $sentencia->bind_param(str_repeat('s', count($valores)), ...array_map('strval', array_values($valores)));
        $sentencia->execute();
        $id = (int) $this->db->insert_id;
        if (isset($this->sembrado[$tabla])) {
            $this->sembrado[$tabla][] = $id;
        }

        return $id;
    }

    private function valor(string $sql): ?string
    {
        $fila = $this->db->query($sql)->fetch_row();

        return $fila === null || $fila[0] === null ? null : (string) $fila[0];
    }

    private function ticket(int $numero, string $estado = 'Cerrado'): int
    {
        return $this->insertar('ticketst', [
            'Numticket' => $numero, 'Numtempticket' => $numero, 'Fecha' => '2031-05-10 12:00:00', 'idTienda' => $this->idTienda,
            'idUsuario' => $this->idUsuario, 'idCliente' => $this->idCliente, 'estado' => $estado, 'formaPago' => 'Efectivo', 'entregado' => 0, 'total' => 12.10,
        ]);
    }

    /** Un cierre del usuario de pruebas con su IVA, su forma de pago y su rango de tickets. */
    private function cierre(int $desde, int $hasta): int
    {
        $id = $this->insertar('cierres', [
            'FechaCierre' => '2031-05-10', 'idTienda' => $this->idTienda, 'idUsuario' => $this->idUsuario, 'FechaInicio' => '2031-05-10 09:00:00',
            'FechaFinal' => '2031-05-10 20:00:00', 'FechaCreacion' => '2031-05-10 21:00:00', 'Total' => 24.20,
        ]);
        $this->insertar('cierres_ivas', ['idCierre' => $id, 'idTienda' => $this->idTienda, 'tipo_iva' => 21, 'importe_base' => 20.00, 'importe_iva' => 4.20]);
        $this->insertar('cierres_usuariosFormasPago', ['idCierre' => $id, 'idTienda' => $this->idTienda, 'idUsuario' => $this->idUsuario, 'FormasPago' => 'Efectivo', 'importe' => 24.20]);
        $this->insertar('cierres_usuarios_tickets', [
            'idCierre' => $id, 'idUsuario' => $this->idUsuario, 'idTienda' => $this->idTienda, 'Importe' => 24.20,
            'Num_ticket_inicial' => $desde, 'Num_ticket_final' => $hasta,
        ]);

        return $id;
    }

    private function filasDelCierre(int $id): int
    {
        $filas = 0;
        foreach (['cierres_usuarios_tickets', 'cierres_usuariosFormasPago', 'cierres_ivas', 'cierres'] as $tabla) {
            $filas += (int) $this->valor("SELECT COUNT(*) FROM $tabla WHERE idCierre = $id");
        }

        return $filas;
    }

    /**
     * @comportamiento Borrar el último cierre devuelve a «Cobrado» los tickets de cada usuario de su rango,
     *   en su tienda, borra sus filas de las cuatro tablas y responde «Ok»; los tickets fuera del rango no
     *   cambian.
     */
    public function test_borrarElUltimoCierreDevuelveSusTicketsACobradoYBorraSusFilas(): void
    {
        $dentro = [$this->ticket(9301), $this->ticket(9302)];
        $fuera = $this->ticket(9303);
        $id = $this->cierre(9301, 9302);

        $respuesta = $this->cierres->borrarDatos_tablasCierres($id);

        self::assertSame('Ok', $respuesta['estado']);
        self::assertSame('2', $this->valor("SELECT COUNT(*) FROM ticketst WHERE estado = 'Cobrado' AND id IN ({$dentro[0]}, {$dentro[1]})"));
        self::assertSame('Cerrado', $this->valor("SELECT estado FROM ticketst WHERE id = $fuera"));
        self::assertSame(0, $this->filasDelCierre($id));
    }

    /** @comportamiento Un cierre que no es el último no se borra: responde «KO» y no cambia nada. */
    public function test_unCierreQueNoEsElUltimoNoSeBorra(): void
    {
        $this->ticket(9301);
        $primero = $this->cierre(9301, 9301);
        $this->cierre(9302, 9302);

        $respuesta = $this->cierres->borrarDatos_tablasCierres($primero);

        self::assertSame(['estado' => 'KO'], $respuesta);
        self::assertSame(4, $this->filasDelCierre($primero));
        self::assertSame('Cerrado', $this->valor("SELECT estado FROM ticketst WHERE Numticket = 9301 AND idUsuario = {$this->idUsuario}"));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_cierres/clases/ClaseCierres.php:68-77
     * @codigo-afectado modulos/mod_cierres/funciones.php:35-40
     *
     * @group defecto
     * @group cierres
     * @group medio
     *
     * @que-ocurre-hoy Borrar el último cierre devuelve a «Cobrado» todos los tickets del usuario cuyo número
     *   cae en su rango, también uno que cerró otro cierre: si el último cerró el 9300 y el 9302 —el 9300,
     *   con la fecha cambiada en el TPV—, su rango va del 9300 al 9302 y el borrado reabre el 9301, que es
     *   de un cierre anterior.
     * @que-deberia-ocurrir Que el borrado reabra solo los tickets que cerró ese cierre.
     * @por-que-ocurre Los tickets de un cierre no se guardan: se deducen del primer y el último número de
     *   cada usuario.
     * @como-deberia-funcionar Anotar en cada ticket el cierre que lo cerró, y reabrir por él.
     */
    public function test_borrarElUltimoCierreNoReabreUnTicketDeOtroCierre(): void
    {
        $this->ticket(9300);
        $deOtroCierre = $this->ticket(9301);
        $this->ticket(9302);
        $this->cierre(9301, 9301);
        $ultimo = $this->cierre(9300, 9302);

        $this->cierres->borrarDatos_tablasCierres($ultimo);

        self::assertSame('Cerrado', $this->valor("SELECT estado FROM ticketst WHERE id = $deOtroCierre"));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_cierres/clases/ClaseCierres.php:90-93
     *
     * @group defecto
     * @group cierres
     * @group medio
     *
     * @que-ocurre-hoy Borrado el último cierre, el contador de `cierres` vuelve atrás y el siguiente cierre
     *   recibe el número del borrado —o el 1, si no queda ninguno—: dos cierres distintos llevan el mismo
     *   número a lo largo del tiempo, y no queda rastro de que hubo uno borrado.
     * @que-deberia-ocurrir Que un número de cierre no se vuelva a dar.
     * @por-que-ocurre El borrado ejecuta `ALTER TABLE cierres AUTO_INCREMENT = 1`, que lleva el contador al
     *   siguiente del mayor que quede.
     * @como-deberia-funcionar No tocar el contador; anular el cierre en lugar de borrarlo, con quién y cuándo.
     */
    public function test_elNumeroDeUnCierreBorradoNoSeVuelveADar(): void
    {
        $this->ticket(9301);
        $borrado = $this->cierre(9301, 9301);
        $this->cierres->borrarDatos_tablasCierres($borrado);

        $siguiente = $this->cierre(9301, 9301);

        self::assertGreaterThan($borrado, $siguiente);
    }
}
