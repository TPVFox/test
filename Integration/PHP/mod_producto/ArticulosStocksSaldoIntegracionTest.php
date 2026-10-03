<?php

/**
 * `alArticulosStocks::actualizarStock()`, el movimiento de existencias que usan la venta, la caja,
 * las compras y la reorganizacion.
 *
 * El saldo se calcula en PHP: se lee, se le suma el movimiento y se escribe el resultado. Si entre
 * la lectura y la escritura otra operacion mueve el mismo articulo, su movimiento se pierde. Fuera
 * de una transaccion esa ventana dura milisegundos; dentro de una, la lectura es la foto del
 * principio de la transaccion y la ventana se alarga a todo lo que la transaccion dure.
 *
 * Por eso estos casos no corren dentro de la transaccion de la suite: reproducen lo que pasaria
 * con un guardado en transaccion, y una transaccion dentro de otra confirmaria la de la suite sin
 * avisar. Limpian lo que siembran.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModProducto;

use mysqli;
use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class ArticulosStocksSaldoIntegracionTest extends CasoIntegracion
{
    protected bool $aislarPorTransaccion = false;
    protected bool $compartirConexionConElProducto = true;

    private Siembra $siembra;
    private ?mysqli $caja = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_producto/clases/ClaseArticulosStocks.php');
        $this->siembra = new Siembra($this->db);
    }

    protected function tearDown(): void
    {
        if ($this->db->query('SELECT @@in_transaction AS t')->fetch_assoc()['t'] ?? 0) {
            $this->db->rollback();
        }
        $this->caja?->close();
        foreach ($this->siembra->insertadoEn('articulosStocks') as $id) {
            $this->db->query('DELETE FROM articulosStocks WHERE id = ' . $id);
        }
        foreach ($this->siembra->insertadoEn('articulos') as $id) {
            $this->db->query('DELETE FROM articulos WHERE idArticulo = ' . $id);
        }
        foreach ($this->siembra->insertadoEn('tiendas') as $id) {
            $this->db->query('DELETE FROM tiendas WHERE idTienda = ' . $id);
        }
        parent::tearDown();
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/clases/ClaseArticulosStocks.php:115-130
     *
     * @group esperado
     * @group existencias
     * @group critico
     *
     * @que-ocurre-hoy Si la caja vende un articulo mientras un guardado en transaccion mueve ese
     *   mismo articulo, la venta de la caja desaparece del saldo: el guardado escribe el saldo que
     *   calculo con la foto del principio de su transaccion.
     * @que-deberia-ocurrir Que el saldo final recoja los dos movimientos.
     * @por-que-ocurre El saldo se lee, se suma en PHP y se escribe como valor fijo. Dentro de una
     *   transaccion la lectura no ve lo que otras conexiones confirmaron despues de empezarla.
     * @como-deberia-funcionar Sumar en la propia sentencia, de modo que el motor aplique el
     *   movimiento sobre el saldo vigente.
     */
    public function test_defecto_unaVentaDeCajaDuranteUnGuardadoEnTransaccionNoSePierdeDelSaldo(): void
    {
        [$idArticulo, $idTienda, $idStock] = $this->articuloConSaldo(10.0);

        // El guardado empieza: su transaccion fija la foto en la primera lectura.
        $this->db->begin_transaction();
        $this->db->query("SELECT stockOn FROM articulosStocks WHERE id = $idStock")->fetch_assoc();

        // Mientras tanto, la caja vende una unidad del mismo articulo por su propia conexion.
        $this->caja()->query("UPDATE articulosStocks SET stockOn = stockOn - 1 WHERE id = $idStock");

        // El guardado mueve dos unidades y confirma.
        \alArticulosStocks::actualizarStock($idArticulo, $idTienda, 2, K_STOCKARTICULO_RESTA);
        $this->db->commit();

        self::assertEqualsWithDelta(
            7.0,
            $this->saldoVistoPorLaCaja($idStock),
            0.000001,
            'El saldo tiene que recoger la venta de la caja y el movimiento del guardado: 10 - 1 - 2.'
        );
    }

    /**
     * @group control
     * @group existencias
     *
     * @para-que-sirve Sin transacciones ni otras conexiones, restar y sumar dan el saldo esperado.
     *   Tiene que seguir siendo asi cuando el saldo se calcule en la sentencia.
     */
    public function test_restarYSumarSinConcurrenciaDanElSaldoEsperado(): void
    {
        [$idArticulo, $idTienda, $idStock] = $this->articuloConSaldo(10.0);

        \alArticulosStocks::actualizarStock($idArticulo, $idTienda, 2, K_STOCKARTICULO_RESTA);
        \alArticulosStocks::actualizarStock($idArticulo, $idTienda, 0.420, K_STOCKARTICULO_SUMA);

        self::assertEqualsWithDelta(8.420, $this->saldoVistoPorLaCaja($idStock), 0.000001);
    }

    /**
     * @group control
     * @group existencias
     *
     * @para-que-sirve Regularizar fija el saldo al valor indicado, no lo suma. Es la otra rama de la
     *   misma funcion y no debe cambiar.
     */
    public function test_regularizarFijaElSaldoAlValorIndicado(): void
    {
        [$idArticulo, $idTienda, $idStock] = $this->articuloConSaldo(10.0);

        \alArticulosStocks::actualizarStock($idArticulo, $idTienda, 4, K_STOCKARTICULO_REGULARIZA);

        self::assertEqualsWithDelta(4.0, $this->saldoVistoPorLaCaja($idStock), 0.000001);
    }

    /**
     * @group control
     * @group existencias
     *
     * @para-que-sirve Un articulo sin ficha de existencias la recibe al primer movimiento, con ese
     *   movimiento ya aplicado.
     */
    public function test_elPrimerMovimientoDeUnArticuloSinFichaLaCreaConElMovimiento(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo sin ficha de existencias');
        $idTienda = $this->siembra->tiendaPorDefecto();

        \alArticulosStocks::actualizarStock($idArticulo, $idTienda, 3, K_STOCKARTICULO_RESTA);

        $fila = $this->db
            ->query("SELECT id, stockOn FROM articulosStocks WHERE idArticulo = $idArticulo AND idTienda = $idTienda")
            ->fetch_assoc();
        $this->db->query('DELETE FROM articulosStocks WHERE id = ' . (int) $fila['id']);

        self::assertEqualsWithDelta(-3.0, (float) $fila['stockOn'], 0.000001);
    }

    // --- Apoyos -------------------------------------------------------------

    /** @return array{int,int,int} articulo, tienda y ficha de existencias, ya confirmados */
    private function articuloConSaldo(float $saldo): array
    {
        $idArticulo = $this->siembra->articulo('Articulo con saldo concurrente');
        $idTienda = $this->siembra->tiendaPorDefecto();
        $idStock = $this->siembra->existenciaRegistrada($idArticulo, $saldo, $idTienda);

        return [$idArticulo, $idTienda, $idStock];
    }

    /** Una segunda conexion, como la de la caja: confirma cada sentencia al momento. */
    private function caja(): mysqli
    {
        return $this->caja ??= self::conectar('vigente');
    }

    private function saldoVistoPorLaCaja(int $idStock): float
    {
        return (float) $this->caja()
            ->query("SELECT stockOn FROM articulosStocks WHERE id = $idStock")
            ->fetch_assoc()['stockOn'];
    }
}
