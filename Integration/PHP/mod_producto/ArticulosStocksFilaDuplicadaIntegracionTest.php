<?php

/**
 * `alArticulosStocks` cuando un artículo tiene más de una fila de saldo en la misma tienda.
 *
 * La tabla no tiene índice único sobre artículo y tienda, de modo que nada impide dos filas. Y la
 * clase no está escrita para ese caso: `existe()` pregunta si hay exactamente una, y
 * `getIdbyArticulo()` y `leer()` toman la primera que devuelva la base, sin orden.
 *
 * La conexión se comparte con el producto: la clase usa la suya propia y, sin compartirla, lo que
 * escribe quedaría fuera de la transacción que aísla cada caso.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModProducto;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class ArticulosStocksFilaDuplicadaIntegracionTest extends CasoIntegracion
{
    protected bool $compartirConexionConElProducto = true;

    private Siembra $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_producto/clases/ClaseArticulosStocks.php');
        $this->siembra = new Siembra($this->db);
    }

    /**
     * Con una sola fila, el movimiento se aplica sobre ella y no crea ninguna más.
     *
     * @group control
     * @group existencias
     *
     * @para-que-sirve Acompaña al caso de la fila duplicada: demuestra que el movimiento normal no
     *   multiplica filas, de modo que lo que aquel caso mide es solo el efecto del duplicado.
     */
    public function test_conUnaSolaFilaElMovimientoSeAplicaSobreElla(): void
    {
        $idArticulo = $this->siembra->articulo('Producto con una fila de saldo');
        $this->siembra->existenciaRegistrada($idArticulo, 5.0);

        \alArticulosStocks::actualizarStock($idArticulo, $this->siembra->tiendaPorDefecto(), 2, K_STOCKARTICULO_RESTA);

        self::assertSame([3.0], $this->saldos($idArticulo));
    }

    /**
     * Con dos filas de saldo para el mismo artículo y tienda, cada movimiento crea una fila nueva.
     *
     * `existe()` exige exactamente una fila. Con dos responde que no existe, y `actualizarStock()`
     * entra en la rama del primer movimiento: crea una fila a cero y aplica el movimiento sobre
     * ella. Las dos filas que había no se tocan. El saldo del artículo depende entonces de quién lo
     * lea: `leer()` devuelve una fila cualquiera, y el informe de existencias las suma todas.
     *
     * Dos filas pueden nacer de dos primeros movimientos simultáneos del mismo artículo: los dos
     * comprueban que no hay fila y los dos la crean, porque la tabla no lo impide.
     *
     * @group defecto
     * @group existencias
     * @group alto
     *
     * @que-ocurre-hoy Si un artículo llega a tener dos filas de saldo en la misma tienda, cada venta
     *   o entrada posterior crea otra fila más, y el saldo que se ve depende de qué pantalla lo lea.
     * @que-deberia-ocurrir Que haya una sola fila de saldo por artículo y tienda, y que el movimiento
     *   se aplique siempre sobre ella.
     * @por-que-ocurre La tabla no tiene índice único sobre artículo y tienda, y la comprobación de que
     *   la fila existe pregunta si hay exactamente una, de modo que con dos responde que no hay
     *   ninguna.
     * @como-deberia-funcionar Un índice único sobre artículo y tienda, y la comprobación de existencia
     *   con «al menos una».
     *
     * @codigo-afectado modulos/mod_producto/clases/ClaseArticulosStocks.php:40-50
     * @codigo-afectado modulos/mod_producto/clases/ClaseArticulosStocks.php:132-144
     */
    public function test_defecto_conDosFilasDeSaldoCadaMovimientoCreaOtraFila(): void
    {
        $idArticulo = $this->siembra->articulo('Producto con dos filas de saldo');
        $this->siembra->existenciaRegistrada($idArticulo, 5.0);
        $this->siembra->existenciaRegistrada($idArticulo, 3.0);

        \alArticulosStocks::actualizarStock($idArticulo, $this->siembra->tiendaPorDefecto(), 2, K_STOCKARTICULO_RESTA);

        self::assertSame([5.0, 3.0, -2.0], $this->saldos($idArticulo), 'Las dos filas no se tocan y aparece una tercera con el movimiento');

        \alArticulosStocks::actualizarStock($idArticulo, $this->siembra->tiendaPorDefecto(), 1, K_STOCKARTICULO_RESTA);

        self::assertCount(4, $this->saldos($idArticulo), 'Cada movimiento posterior añade otra fila');
    }

    /** Los saldos del artículo en la tienda por defecto, en el orden en que se crearon. */
    private function saldos(int $idArticulo): array
    {
        $idTienda = $this->siembra->tiendaPorDefecto();

        return array_map(
            'floatval',
            array_column(
                $this->db->query("SELECT stockOn FROM articulosStocks WHERE idArticulo = {$idArticulo} AND idTienda = {$idTienda} ORDER BY id")
                    ->fetch_all(MYSQLI_ASSOC),
                'stockOn'
            )
        );
    }
}
