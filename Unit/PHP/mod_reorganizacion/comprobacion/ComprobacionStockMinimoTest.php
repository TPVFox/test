<?php
/**
 * Reunión de los tres orígenes, formación de lotes y stock justificado: lógica pura,
 * sin base de datos. El recorrido hacia atrás se detiene en el primer lote negativo
 * sin incluirlo; una devolución a proveedor no abre lote, se resta del balance del
 * lote en curso.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModReorganizacion\Comprobacion;

use PHPUnit\Framework\TestCase;

final class ComprobacionStockMinimoTest extends TestCase
{
    private static function instancia(): \ClaseComprobacionStockMinimo
    {
        global $RutaServidor, $HostNombre, $URLCom;
        require_once RUTA_TPVFOX . '/modulos/mod_reorganizacion/clases/ClaseComprobacionStockMinimo.php';
        return new \ClaseComprobacionStockMinimo();
    }

    private function movimiento(string $fecha, float $delta, string $tipo): array
    {
        return ['fecha' => $fecha, 'delta' => $delta, 'tipo' => $tipo];
    }

    public function test_T1_elStockJustificadoSumaSoloLosLotesPosterioresAlUltimoNegativo(): void
    {
        $comprobacion = self::instancia();

        // Caso calculado a mano, igual que el ejemplo de la especificación: el lote
        // del 25-06 al 11-11 cierra en -1 (ancla); el del 12-11 al cierre, en +10.
        $movimientos = [
            $this->movimiento('2025-06-25', 24.0, 'recepcion'),
            $this->movimiento('2025-07-15', -25.0, 'venta'),
            $this->movimiento('2025-11-12', 24.0, 'recepcion'),
            $this->movimiento('2025-12-01', -14.0, 'venta'),
        ];

        $resultado = $comprobacion->justificar($movimientos, 'unidad');

        self::assertSame(10.0, $resultado['stockJustificado']);
    }

    public function test_T2_unaDevolucionAProveedorNoAbreLoteYSeRestaDelLoteEnCurso(): void
    {
        $comprobacion = self::instancia();

        // El caso que motivó la regla: 10, -2, 5, sin ninguna venta alrededor. Si la
        // devolución abriera lote, el balance -2 sería un lote negativo y cortaría el
        // recorrido en 5, en vez de sumar las tres entradas.
        $movimientos = [
            $this->movimiento('2025-03-01', 10.0, 'recepcion'),
            $this->movimiento('2025-03-05', -2.0, 'devolucion'),
            $this->movimiento('2025-03-10', 5.0, 'recepcion'),
        ];

        $resultado = $comprobacion->justificar($movimientos, 'unidad');

        self::assertSame(13.0, $resultado['stockJustificado']);
    }

    public function test_T3_sinNingunaRecepcionNoHayLoteYSaleSinStockJustificadoConLaCondicion(): void
    {
        $comprobacion = self::instancia();

        $movimientos = [
            $this->movimiento('2025-05-01', -3.0, 'venta'),
        ];

        $resultado = $comprobacion->justificar($movimientos, 'unidad');

        self::assertNull($resultado['stockJustificado']);
        self::assertContains('historico_incompleto', $resultado['condicionesConocidas']);
    }

    public function test_T4_elMargenSoloCuentaVentasDeLosLotesContadosYSoloEnProductoDePeso(): void
    {
        $comprobacion = self::instancia();

        // Lote ancla (negativo): tres ventas, no deben contar. Lote contado: dos
        // ventas, sí deben contar.
        $movimientos = [
            $this->movimiento('2025-01-10', 20.0, 'recepcion'),
            $this->movimiento('2025-01-11', -7.0, 'venta'),
            $this->movimiento('2025-01-12', -7.0, 'venta'),
            $this->movimiento('2025-01-13', -7.0, 'venta'),
            $this->movimiento('2025-06-01', 30.0, 'recepcion'),
            $this->movimiento('2025-06-02', -5.0, 'venta'),
            $this->movimiento('2025-06-03', -5.0, 'venta'),
        ];

        $peso = $comprobacion->justificar($movimientos, 'peso');
        $unidad = $comprobacion->justificar($movimientos, 'unidad');

        self::assertSame(20.0, $peso['stockJustificado']);
        self::assertSame(max(0.5, 0.010 * 2), $peso['margen']);
        self::assertSame(0.0, $unidad['margen']);
    }

    public function test_T5_cadaOrigenEntraConSuSignoYSuClaseDeMovimiento(): void
    {
        $comprobacion = self::instancia();

        $movimientos = $comprobacion->componerMovimientos(
            [
                ['fecha' => '2025-03-01', 'nunidades' => 20.0],
                ['fecha' => '2025-03-04', 'nunidades' => -3.0],
            ],
            [['fecha' => '2025-03-02', 'nunidades' => 5.0]],
            [['fecha' => '2025-03-03', 'nunidades' => 4.0]]
        );

        // El albarán de proveedor conserva su propio signo: en positivo es recepción y
        // abre lote, en negativo es devolución al proveedor y no lo abre. Ticket y
        // albarán de cliente llegan en positivo y siempre restan.
        self::assertSame([
            ['fecha' => '2025-03-01', 'delta' => 20.0, 'tipo' => 'recepcion'],
            ['fecha' => '2025-03-04', 'delta' => -3.0, 'tipo' => 'devolucion'],
            ['fecha' => '2025-03-02', 'delta' => -5.0, 'tipo' => 'venta'],
            ['fecha' => '2025-03-03', 'delta' => -4.0, 'tipo' => 'salida_cliente'],
        ], $movimientos);
    }

    public function test_T6_sinLineasEnNingunOrigenNoHayMovimientos(): void
    {
        $comprobacion = self::instancia();

        self::assertSame([], $comprobacion->componerMovimientos([], [], []));
    }

    public function test_T7_elCatalogoQueNoDiceComoSeMideElProductoSuponeUnidades(): void
    {
        $comprobacion = self::instancia();

        // Suponer «peso» ante la ausencia daría margen a un producto que quizá no lo
        // tiene; suponer «unidad» no da margen a nadie que no lo haya declarado.
        self::assertSame('unidad', $comprobacion->tipoSupuesto(null));
        self::assertSame('peso', $comprobacion->tipoSupuesto('peso'));
        self::assertSame('unidad', $comprobacion->tipoSupuesto('unidad'));
    }

    public function test_T8_unLoteQueSeCancelaExactoNoCortaElRecorrido(): void
    {
        $comprobacion = self::instancia();

        // El lote de marzo recibe 8,1 kg y vende 3,7 y 4,4: neto exactamente cero, y un
        // cero no corta. Sumado en coma flotante da -8,88e-16, que es menor que cero
        // para el lenguaje: sin la precisión del dominio ese lote sería el ancla y los
        // seis del lote de enero, que sí sostienen existencia, quedarían fuera.
        $movimientos = [
            $this->movimiento('2025-01-10', 10.0, 'recepcion'),
            $this->movimiento('2025-01-20', -4.0, 'venta'),
            $this->movimiento('2025-03-01', 8.1, 'recepcion'),
            $this->movimiento('2025-03-05', -3.7, 'venta'),
            $this->movimiento('2025-03-10', -4.4, 'venta'),
            $this->movimiento('2025-06-01', 5.0, 'recepcion'),
        ];

        $resultado = $comprobacion->justificar($movimientos, 'unidad');

        self::assertSame(11.0, $resultado['stockJustificado']);
    }

    public function test_T9_siElLoteMasRecienteEsNegativoElMinimoEsCeroYNoUnaFaltaDeHistorico(): void
    {
        $comprobacion = self::instancia();

        // El ancla es el primer lote del recorrido y no queda nada posterior. Cero aquí
        // no es lo mismo que no tener histórico: hay lotes y se han recorrido, y lo que
        // dicen es que ni el último tramo se sostiene. Es una restricción establecida y
        // sale como cantidad, sin la condición de histórico incompleto.
        $movimientos = [
            $this->movimiento('2025-02-01', 10.0, 'recepcion'),
            $this->movimiento('2025-02-10', -4.0, 'venta'),
            $this->movimiento('2025-09-01', 3.0, 'recepcion'),
            $this->movimiento('2025-09-15', -8.0, 'venta'),
        ];

        $resultado = $comprobacion->justificar($movimientos, 'unidad');

        self::assertSame(0.0, $resultado['stockJustificado']);
        self::assertSame([], $resultado['condicionesConocidas']);
    }

    public function test_T10_laSalidaPorAlbaranDeClienteTambienCuentaParaElMargen(): void
    {
        $comprobacion = self::instancia();

        // Sesenta salidas por albarán de cliente, ninguna por ticket. Lo que el margen
        // acota es la imprecisión que cada pesada acumula, y una salida por albarán se
        // pesa igual: si no contaran, el margen se quedaría en su suelo de 0,5.
        $movimientos = [$this->movimiento('2025-03-01', 100.0, 'recepcion')];
        for ($i = 0; $i < 60; $i++) {
            $movimientos[] = $this->movimiento('2025-03-05', -1.0, 'salida_cliente');
        }

        $resultado = $comprobacion->justificar($movimientos, 'peso');

        self::assertSame(40.0, $resultado['stockJustificado']);
        self::assertSame(0.6, $resultado['margen']);
    }

    public function test_T11_dentroDeUnMismoDiaLaRecepcionVaPrimeroYLaSalidaEntraEnSuLote(): void
    {
        $comprobacion = self::instancia();

        // Los movimientos se fechan por día, sin hora, así que coincidir es lo corriente
        // y hay que decidirlo. La venta se pasa antes que la recepción a propósito: si
        // el orden lo decidiera la concatenación de los orígenes, esa venta quedaría
        // antes de la primera recepción, fuera de todo lote, y el mínimo saldría 10.
        $movimientos = [
            $this->movimiento('2025-04-10', -6.0, 'venta'),
            $this->movimiento('2025-04-10', 10.0, 'recepcion'),
        ];

        $resultado = $comprobacion->justificar($movimientos, 'unidad');

        self::assertSame(4.0, $resultado['stockJustificado']);
    }
}
