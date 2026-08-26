<?php
/**
 * Lo que decide la emisión sin tocar la base: que el conjunto pedido llegó entero —se
 * cuenta lo que llega y se compara con lo que se declaró enviar—, si lo pedido delimita
 * un subconjunto de lo compuesto o es el conjunto entero, y si un fichero con todo lo
 * compuesto cabría por la subida del otro extremo del puente.
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

    public function test_T9_siElConjuntoCompletoCabeNoHayNadaQueAdvertir(): void
    {
        $emision = self::instancia();

        // Se mide contra el conjunto completo porque es el mayor que se puede pedir: si
        // ese cabe, ninguna selección deja de caber.
        self::assertNull($emision->avisoDeVolumen(5000, '2M', '8M'));
    }

    public function test_T10_siNoCabeSeDiceCuantoOcupaYEnPartesDeCuantosEmitirlo(): void
    {
        $emision = self::instancia();

        $aviso = $emision->avisoDeVolumen(10000, '2M', '8M');

        // El aviso llega cuando el operador aún no ha elegido nada y el filtro sigue
        // siendo el remedio: por eso lleva la cifra de la parte, no solo el problema.
        self::assertNotNull($aviso);
        self::assertStringContainsString('10.000', $aviso);
        self::assertStringContainsString('5.518', $aviso);
    }

    public function test_T11_mandaElMenorDeLosDosLimites(): void
    {
        $emision = self::instancia();

        // Un fichero que cabe por sí mismo no entra si la petición que lo lleva no cabe,
        // así que mirar solo el límite por fichero prometería que cabe algo que no entra.
        self::assertNotNull($emision->avisoDeVolumen(10000, '1024M', '2M'));
        self::assertNotNull($emision->avisoDeVolumen(10000, '2M', '1024M'));
        self::assertNull($emision->avisoDeVolumen(10000, '1024M', '1024M'));
    }

    public function test_T12_elLimiteSeTraduceDesdeLaAbreviaturaDelServidor(): void
    {
        $emision = self::instancia();

        self::assertSame(2097152, $emision->bytesDelLimite('2M'));
        self::assertSame(524288, $emision->bytesDelLimite('512K'));
        self::assertSame(1073741824, $emision->bytesDelLimite('1G'));
        self::assertSame(1024, $emision->bytesDelLimite('1024'));
    }

    public function test_T13_sinLimiteDeclaradoNoSeInventaUnAviso(): void
    {
        $emision = self::instancia();

        // Un servidor que no declara límite no es un servidor sin espacio: advertir aquí
        // sería avisar de algo que nadie ha establecido.
        self::assertSame(0, $emision->bytesDelLimite(''));
        self::assertNull($emision->avisoDeVolumen(10000, '', ''));
    }
}
