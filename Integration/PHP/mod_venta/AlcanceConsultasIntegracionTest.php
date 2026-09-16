<?php

/**
 * Alcance de las consultas: la entrada del operador debe tratarse como dato, nunca como
 * parte de la instruccion. `ComprobarPedidos()` de `PedidosVentas` concatena el identificador de
 * cliente en `... where idCliente=<valor> and estado="Guardado"`, sin parametrizar,
 * de modo que una entrada capaz de cerrar la condicion del identificador y comentar el resto amplia
 * el alcance a documentos que la operacion no autoriza.
 *
 * El test contrasta el recuento legitimo de un cliente con el que devuelve la misma consulta cuando
 * el identificador lleva una carga que anula la condicion de estado. Es la instancia viva del riesgo de
 * inyeccion y la evidencia de que el alcance no esta garantizado por construccion; se conserva en
 * rojo. Su correccion —parametrizar— queda fuera del alcance de estas pruebas.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVenta;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class AlcanceConsultasIntegracionTest extends CasoIntegracion
{
    private \PedidosVentas $pedidos;
    private Siembra $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_venta/clases/pedidosVentas.php');
        $this->pedidos = new \PedidosVentas($this->db);
        $this->siembra = new Siembra($this->db);
    }

    /**
     * El recuento legitimo: un cliente con un pedido Guardado y otro en un estado que no cuenta. La
     * operacion autoriza a ver solo los Guardado de ese cliente, y son uno.
     */
    public function test_recuentoLegitimoSoloCuentaLosGuardadosDelCliente(): void
    {
        $articulo = $this->siembra->articulo('Articulo de alcance');
        $idCliente = $this->siembra->cliente('Cliente con un pedido');
        $this->siembra->pedidoVentaCliente($articulo, 1.0, '2026-01-10', ['idCliente' => $idCliente, 'estado' => 'Guardado']);
        $this->siembra->pedidoVentaCliente($articulo, 1.0, '2026-01-11', ['idCliente' => $idCliente, 'estado' => 'Procesado']);

        $r = $this->pedidos->ComprobarPedidos($idCliente, 'Guardado');

        self::assertSame(1, $r['NItems']);
    }

    /**
     * Defecto: una entrada que cierra la condicion del identificador y comenta el resto —
     * `1 OR 1=1-- ` — hace que el recuento deje de estar acotado por cliente y por estado, y alcance
     * a todos los pedidos de la tabla. El alcance pasa a depender del contenido de la entrada, que es
     * lo que el contrato de la consulta prohibe. Se conserva en rojo.
     *
     * @estado rojo
     */
    public function test_defecto_entradaConCargaAmpliaElAlcanceDelRecuento(): void
    {
        $articulo = $this->siembra->articulo('Articulo de alcance');
        $clienteA = $this->siembra->cliente('Cliente A');
        $clienteB = $this->siembra->cliente('Cliente B');
        // Un solo Guardado de A; ademas, un pedido de B en un estado que no cuenta para A.
        $this->siembra->pedidoVentaCliente($articulo, 1.0, '2026-01-10', ['idCliente' => $clienteA, 'estado' => 'Guardado']);
        $this->siembra->pedidoVentaCliente($articulo, 1.0, '2026-01-11', ['idCliente' => $clienteB, 'estado' => 'Procesado']);

        $legitimo = $this->pedidos->ComprobarPedidos($clienteA, 'Guardado');
        $conCarga = $this->pedidos->ComprobarPedidos($clienteA . ' OR 1=1-- ', 'Guardado');

        // El recuento con carga alcanza mas filas que el legitimo: la entrada amplio el alcance.
        self::assertSame(
            $legitimo['NItems'],
            $conCarga['NItems'],
            'una entrada del operador no debe poder ampliar el alcance de la consulta'
        );
    }
}
