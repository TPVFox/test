<?php
/**
 * Lo que decide la emisión antes de componer: que el conjunto pedido llegó entero
 * —se cuenta lo que llega y se compara con lo que se declaró enviar— y si lo pedido
 * delimita un subconjunto de lo compuesto o es el conjunto entero.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModReorganizacion\Comprobacion;

use PHPUnit\Framework\TestCase;

final class ComprobacionStockEmisionTest extends TestCase
{
    private static function instancia(): \ClaseComprobacionStockEmision
    {
        global $RutaServidor, $HostNombre, $URLCom;
        require_once RUTA_TPVFOX . '/modulos/mod_reorganizacion/clases/ClaseComprobacionStockEmision.php';
        return new \ClaseComprobacionStockEmision();
    }

    /** El conjunto compuesto, del que solo importa el identificador. */
    private function compuesto(array $ids): array
    {
        $filas = [];
        foreach ($ids as $id) {
            $filas[] = ['idArticulo' => $id];
        }
        return $filas;
    }

    public function test_T1_elConjuntoQueLlegaEnteroSeAdmite(): void
    {
        $emision = self::instancia();

        $resultado = $emision->conjuntoPedido('10,11,12', '3');

        self::assertTrue($resultado['ok']);
        self::assertSame([10, 11, 12], $resultado['ids']);
    }

    public function test_T2_unConjuntoQueLlegaAMediasNoSeEmite(): void
    {
        $emision = self::instancia();

        // Lo que ocurre cuando algo por el camino recorta la petición: llegan menos
        // identificadores de los que se enviaron, y nada más lo delataría después.
        $resultado = $emision->conjuntoPedido('10,11', '2000');

        self::assertFalse($resultado['ok']);
        self::assertStringContainsString('2000', $resultado['motivo']);
        self::assertStringContainsString('2', $resultado['motivo']);
        self::assertArrayNotHasKey('ids', $resultado);
    }

    public function test_T3_sinRecuentoDeclaradoNoSeEmite(): void
    {
        $emision = self::instancia();

        // Sin el recuento no hay con qué comparar: el conjunto podría estar entero o
        // no, y emitir sin saberlo es exactamente lo que hay que evitar.
        self::assertFalse($emision->conjuntoPedido('10,11', null)['ok']);
        self::assertFalse($emision->conjuntoPedido('10,11', '')['ok']);
    }

    public function test_T4_unaSeleccionVaciaNoEmiteElConjuntoCompleto(): void
    {
        $emision = self::instancia();

        $resultado = $emision->conjuntoPedido('', '0');

        self::assertFalse($resultado['ok']);
        self::assertStringContainsString('ningún producto', $resultado['motivo']);
    }

    public function test_T5_loPedidoIgualALoCompuestoNoDeclaraSubconjunto(): void
    {
        $emision = self::instancia();

        $filtro = $emision->filtroDeclarable($this->compuesto([10, 11, 12]), [12, 10, 11]);

        // El orden no hace conjunto: son los mismos tres, luego no hay subconjunto
        // que declarar y la ausencia del filtro dirá que el conjunto es completo.
        self::assertNull($filtro);
    }

    public function test_T6_loPedidoQueDejaFueraAlgunoDeclaraSubconjunto(): void
    {
        $emision = self::instancia();

        $filtro = $emision->filtroDeclarable($this->compuesto([10, 11, 12]), [10, 11]);

        self::assertSame([10, 11], $filtro);
    }

    public function test_T7_loPedidoQueNombraAlgoQueNoEstaSigueDeclarandoseEntero(): void
    {
        $emision = self::instancia();

        // Se pidió un producto que la composición no contiene. Declarar la
        // intersección borraría el rastro; declarando lo pedido, la diferencia con
        // lo que el fichero lleva queda legible en el propio fichero.
        $filtro = $emision->filtroDeclarable($this->compuesto([10, 11]), [10, 11, 999]);

        self::assertSame([10, 11, 999], $filtro);
    }

    public function test_T8_sobreUnConjuntoVacioLoPedidoSigueSiendoSubconjunto(): void
    {
        $emision = self::instancia();

        // Nada compuesto y algo pedido no son el mismo conjunto: el fichero ha de
        // declarar qué se buscó, o «se miró y no había» se confunde con «no se pidió».
        self::assertSame([10], $emision->filtroDeclarable([], [10]));
    }
}
