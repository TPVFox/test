<?php
/**
 * Extracción del estado del vigente: trayectoria compuesta sobre el catálogo
 * completo, cruzada con el detector de existencias negativas, marcada y anotada con
 * sus condiciones conocidas.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModReorganizacion\Comprobacion;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\EscenarioComprobacionStock;
use TPVFox\Test\Siembra\Siembra;

final class ComprobacionStockExtraccionIntegracionTest extends CasoIntegracion
{
    protected bool $compartirConexionConElProducto = true;

    /** El proveedor con el que la instalación identifica el traspaso entre ejercicios. */
    private const PROVEEDOR_DE_CIERRE = 112;

    private Siembra $siembra;
    private EscenarioComprobacionStock $escenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siembra = new Siembra($this->db);
        $this->escenario = $this->nuevoEscenario($this->siembra);
        $this->incluirTPVFox('/modulos/mod_reorganizacion/clases/ClaseComprobacionStockExtraccion.php');
    }

    private function contexto(array $cambios = []): array
    {
        return array_merge([
            'ano' => $this->ano(),
            'idTienda' => (string) $this->siembra->tiendaPorDefecto(),
            'familiasExcluidas' => [],
            // La fecha hasta la que se lee es un dato del contexto, no del reloj. Fijarla
            // aquí es lo que hace que estos casos den el mismo resultado el día que se
            // escriben y cualquier otro; con el reloj, el mismo estado sembrado deja de
            // marcar «periodo no consolidado» en cuanto pasa la ventana.
            'fechaCorte' => $this->ano() . '-06-30',
            'ventanaDias' => 0,
            'umbralFraccionado' => 0.05,
            'umbralMagnitud' => 0.5,
            'umbralPorVenta' => 0.010,
            'timingVentanaDias' => 1,
        ], $cambios);
    }

    private function fila(array $resultado, int $idArticulo): ?array
    {
        foreach ($resultado as $fila) {
            if ($fila['idArticulo'] === $idArticulo) {
                return $fila;
            }
        }
        return null;
    }

    public function test_T1_productoSinMovimientosEnElPeriodoSeMarcaAunqueElDetectorNoLoVea(): void
    {
        // Salida dentro de la ventana de saldo de partida (31 dic - 1 ene): el producto
        // arranca el ejercicio ya en negativo, sin ningún movimiento posterior.
        $idArticulo = $this->escenario->E01()['idArticulo'];

        $comprobacion = new \ClaseComprobacionStockExtraccion();
        $resultado = $comprobacion->extraer($this->contexto());

        $fila = $this->fila($resultado, $idArticulo);
        self::assertNotNull($fila, 'El producto tiene que aparecer: su trayectoria alcanzó negativo');
        self::assertSame(-5.0, $fila['saldoAlCorte']);
        self::assertSame(-5.0, $fila['saldoDeApertura'], 'Sin movimientos en el periodo, coincide con la partida');
        self::assertTrue($fila['marcado']);
        self::assertNull($fila['tipoIncidencia'], 'El detector no examina productos sin movimiento en el periodo');
    }

    public function test_T2_unAlbaranDelProveedorDeCierreFueraDeLaFronteraCuentaComoMovimiento(): void
    {
        // Uno dentro de la frontera (1 de enero), que es saldo de partida, y otro del
        // mismo proveedor fuera de ella, que cuenta como movimiento del ejercicio: la
        // fecha decide, no el proveedor.
        $idArticulo = $this->escenario->E02(self::PROVEEDOR_DE_CIERRE)['idArticulo'];

        $comprobacion = new \ClaseComprobacionStockExtraccion();
        $resultado = $comprobacion->extraer($this->contexto());

        $fila = $this->fila($resultado, $idArticulo);
        self::assertNotNull($fila);
        // Correcto: partida = 8; recorrido = +3 (05-ene) - 20 (10-ene) = -17 acumulado.
        self::assertSame(-9.0, $fila['saldoAlCorte']);
        self::assertSame(-9.0, $fila['minimoAlcanzado']);
        self::assertSame(8.0, $fila['saldoDeApertura']);
    }

    public function test_T3_familiaExcluidaSeAnotaComoCondicionConocida(): void
    {
        ['idArticulo' => $idArticulo, 'idFamilia' => $idFamilia] = $this->escenario->E03();

        $comprobacion = new \ClaseComprobacionStockExtraccion();
        $resultado = $comprobacion->extraer($this->contexto(['familiasExcluidas' => [$idFamilia]]));

        $fila = $this->fila($resultado, $idArticulo);
        self::assertNotNull($fila);
        self::assertContains('familia_excluida', $fila['condicionesConocidas']);
    }

    public function test_T3b_unaFamiliaConfiguradaQueNoExisteEnLaJerarquiaNoAnotaNada(): void
    {
        $idArticulo = $this->escenario->E04()['idArticulo'];

        $comprobacion = new \ClaseComprobacionStockExtraccion();
        // 999999 no existe en vw_jerarquias_familias: expandirFamilias() no encuentra nada.
        $resultado = $comprobacion->extraer($this->contexto(['familiasExcluidas' => [999999]]));

        $fila = $this->fila($resultado, $idArticulo);
        self::assertNotNull($fila);
        self::assertNotContains('familia_excluida', $fila['condicionesConocidas']);
    }

    public function test_T4_productoSinExistenciaRegistradaEnTienda1SeAnotaComoNuncaIncluidoEnElCierre(): void
    {
        // Deliberadamente sin existencia registrada en la tienda por la que el cierre
        // selecciona: nunca lo habría tomado.
        $idArticulo = $this->escenario->E05()['idArticulo'];

        $comprobacion = new \ClaseComprobacionStockExtraccion();
        $resultado = $comprobacion->extraer($this->contexto());

        $fila = $this->fila($resultado, $idArticulo);
        self::assertNotNull($fila);
        self::assertContains('nunca_incluido_en_cierre', $fila['condicionesConocidas']);
    }

    public function test_T4b_productoConExistenciaRegistradaEnTienda1NoSeAnotaComoNuncaIncluido(): void
    {
        $idArticulo = $this->escenario->E06()['idArticulo'];

        $comprobacion = new \ClaseComprobacionStockExtraccion();
        $resultado = $comprobacion->extraer($this->contexto());

        $fila = $this->fila($resultado, $idArticulo);
        self::assertNotNull($fila);
        self::assertNotContains('nunca_incluido_en_cierre', $fila['condicionesConocidas']);
    }

    public function test_T5_minimoDentroDeLaVentanaDeConsolidacionSeAnotaComoPeriodoNoConsolidado(): void
    {
        $idArticulo = $this->escenario->E07()['idArticulo'];

        $comprobacion = new \ClaseComprobacionStockExtraccion();
        // Fecha de corte a 3 días del mínimo, con ventana de 7: cae dentro.
        $resultado = $comprobacion->extraer($this->contexto(['ventanaDias' => 7, 'fechaCorte' => $this->ano() . '-01-23']));

        $fila = $this->fila($resultado, $idArticulo);
        self::assertNotNull($fila);
        self::assertContains('periodo_no_consolidado', $fila['condicionesConocidas']);
    }

    public function test_T6_unaRegularizacionActivaEnElPeriodoSeAnotaComoCondicionConocida(): void
    {
        $idArticulo = $this->escenario->E08()['idArticulo'];

        $comprobacion = new \ClaseComprobacionStockExtraccion();
        $resultado = $comprobacion->extraer($this->contexto());

        $fila = $this->fila($resultado, $idArticulo);
        self::assertNotNull($fila);
        self::assertContains('regularizacion_en_periodo', $fila['condicionesConocidas']);
    }

    public function test_T7_unProductoCuyoMinimoNuncaBajaDeCeroNoSeExamina(): void
    {
        $idArticulo = $this->escenario->E09()['idArticulo'];

        $comprobacion = new \ClaseComprobacionStockExtraccion();
        $resultado = $comprobacion->extraer($this->contexto());

        self::assertNull($this->fila($resultado, $idArticulo));
    }

    public function test_T8_invisibleEnModoNormalDetectadoEnModoEstricto(): void
    {
        $idArticulo = $this->escenario->E10()['idArticulo'];

        $comprobacion = new \ClaseComprobacionStockExtraccion();
        $modoNormal = $comprobacion->extraer($this->contexto());
        $modoEstricto = $comprobacion->extraer($this->contexto(), true);

        self::assertNull($this->fila($modoNormal, $idArticulo), 'La apertura sostiene el saldo por encima de cero');
        self::assertNotNull($this->fila($modoEstricto, $idArticulo), 'Con S0=0 la misma bajada de 10 ya es negativa');
    }

    public function test_T9_unaEntradaExportadaCuentaEnElRecorridoIgualQueEnElSaldoDePartida(): void
    {
        // Un albarán de proveedor pasa a 'Exportado' cuando se exporta a XML, y sigue
        // siendo una recepción real. Las dos mitades de la trayectoria —el saldo de
        // partida y el recorrido del periodo— tienen que contarlo las dos: si una lo
        // deja fuera, la curva baja por una entrada que sí existe y el producto sale
        // examinado sin haber estado nunca en negativo.
        $idArticulo = $this->escenario->E11()['idArticulo'];

        $comprobacion = new \ClaseComprobacionStockExtraccion();
        $resultado = $comprobacion->extraer($this->contexto());

        $fila = $this->fila($resultado, $idArticulo);
        self::assertNotNull($fila);
        // -2 es 10 recibidas menos 12 vendidas. Sin contar la entrada saldría -12.
        self::assertSame(-2.0, $fila['saldoAlCorte']);
        self::assertSame(-2.0, $fila['minimoAlcanzado']);
    }

    public function test_T10_alterarLaExistenciaRegistradaNoCambiaLaTrayectoria(): void
    {
        // La trayectoria se reconstruye desde los movimientos y no desde la existencia
        // que la base declara. Si esa existencia entrara en el cálculo, un traspaso que
        // hubiese importado de más quedaría escondido por construcción.
        ['idArticulo' => $idArticulo, 'idTiendaDelCierre' => $idTienda] = $this->escenario->E12();

        $comprobacion = new \ClaseComprobacionStockExtraccion();
        $antes = $this->fila($comprobacion->extraer($this->contexto()), $idArticulo);

        $this->siembra->existenciaRegistrada($idArticulo, 999.0, $idTienda);
        $despues = $this->fila((new \ClaseComprobacionStockExtraccion())->extraer($this->contexto()), $idArticulo);

        self::assertNotNull($antes);
        self::assertNotNull($despues, 'El producto sigue examinandose: 999 registradas no borran la bajada');
        self::assertSame($antes['saldoAlCorte'], $despues['saldoAlCorte']);
        self::assertSame($antes['minimoAlcanzado'], $despues['minimoAlcanzado']);
        self::assertSame($antes['saldoDeApertura'], $despues['saldoDeApertura']);
    }

    public function test_T11_laAperturaFechadaElUltimoDiaOElPrimeroDaElMismoSaldoDeArranque(): void
    {
        // Unas instalaciones fechan la importación de apertura el 31 de diciembre y
        // otras el 1 de enero. El rango del saldo de partida abarca los dos días, de
        // modo que la trayectoria arranca igual con cualquiera de las dos convenciones.
        [$conCierre, $conApertura] = $this->escenario->E13(self::PROVEEDOR_DE_CIERRE)['idArticulos'];

        $comprobacion = new \ClaseComprobacionStockExtraccion();
        $resultado = $comprobacion->extraer($this->contexto());

        $filaCierre = $this->fila($resultado, $conCierre);
        $filaApertura = $this->fila($resultado, $conApertura);

        self::assertNotNull($filaCierre);
        self::assertNotNull($filaApertura);
        self::assertSame(6.0, $filaCierre['saldoDeApertura']);
        self::assertSame($filaCierre['saldoDeApertura'], $filaApertura['saldoDeApertura']);
        self::assertSame($filaCierre['saldoAlCorte'], $filaApertura['saldoAlCorte']);
        self::assertSame($filaCierre['minimoAlcanzado'], $filaApertura['minimoAlcanzado']);
    }

    public function test_T12_unProductoDadoDeBajaTambienSeExamina(): void
    {
        // El catálogo no se criba por estado: un producto dado de baja con trayectoria
        // negativa es justo lo que hay que ver, y quien decide qué productos se examinan
        // es el catálogo entero.
        $idArticulo = $this->escenario->E14()['idArticulo'];

        $comprobacion = new \ClaseComprobacionStockExtraccion();
        $resultado = $comprobacion->extraer($this->contexto());

        $fila = $this->fila($resultado, $idArticulo);
        self::assertNotNull($fila, 'Un producto en baja no queda fuera del catalogo examinado');
        self::assertSame(-3.0, $fila['saldoAlCorte']);
    }

    public function test_T13_unProductoQueAbreEnNegativoYSoloRecibeSeExaminaPorSuApertura(): void
    {
        // El punto de partida forma parte de la trayectoria. Este producto abre el
        // ejercicio debiendo tres unidades y a partir de ahí solo sube: su mínimo es la
        // propia apertura, y es el caso que el conjunto emitido tiene que recoger aunque
        // el recorrido del periodo no baje en ningún momento.
        $idArticulo = $this->escenario->E15()['idArticulo'];

        $comprobacion = new \ClaseComprobacionStockExtraccion();
        $resultado = $comprobacion->extraer($this->contexto());

        $fila = $this->fila($resultado, $idArticulo);
        self::assertNotNull($fila, 'El minimo es el saldo de apertura, y eso ya es negativo');
        self::assertSame(-3.0, $fila['saldoDeApertura']);
        self::assertSame(-3.0, $fila['minimoAlcanzado']);
        self::assertSame(7.0, $fila['saldoAlCorte']);
        self::assertFalse($fila['marcado'], 'Al corte ya no debe existencias');
    }

    public function test_T14_sinMovimientoQueExpliqueElMinimoNoSeAnotaPeriodoNoConsolidado(): void
    {
        // La condición dice que el mínimo cae en la ventana en que el periodo aún puede
        // cambiar. Si el mínimo es el saldo de apertura no hay ningún movimiento del
        // periodo que lo explique, y la condición no aplica por reciente que sea el corte.
        $idArticulo = $this->escenario->E16()['idArticulo'];

        $comprobacion = new \ClaseComprobacionStockExtraccion();
        $resultado = $comprobacion->extraer($this->contexto(['ventanaDias' => 7, 'fechaCorte' => $this->ano() . '-01-23']));

        $fila = $this->fila($resultado, $idArticulo);
        self::assertNotNull($fila);
        self::assertSame(-4.0, $fila['minimoAlcanzado']);
        self::assertNotContains('periodo_no_consolidado', $fila['condicionesConocidas']);
    }

    public function test_T15_unaRegularizacionDelPrimerDiaDelAnoTambienSeAnota(): void
    {
        // La regularización no es ninguno de los tres movimientos, así que el reparto
        // entre saldo de partida y recorrido no la alcanza: ni una mitad ni la otra la
        // absorben. Fechada el 1 de enero está dentro del ejercicio y está registrada, y
        // si su periodo arrancase con el del recorrido no la vería nadie.
        $idArticulo = $this->escenario->E17()['idArticulo'];

        $comprobacion = new \ClaseComprobacionStockExtraccion();
        $resultado = $comprobacion->extraer($this->contexto());

        $fila = $this->fila($resultado, $idArticulo);
        self::assertNotNull($fila);
        self::assertContains('regularizacion_en_periodo', $fila['condicionesConocidas']);
    }

    public function test_T16_lasCuatroCondicionesSeAcumulanSobreElMismoProductoSinAbsorberse(): void
    {
        // Cada condición se comprueba también por separado. Este caso las reúne porque
        // son ejes independientes que se acumulan sobre un mismo producto: si alguna
        // absorbiera a otra, o si el marcado o el tipo desaparecieran al haber
        // condiciones, no se vería en ningún caso de los de una sola.
        // Sin existencia registrada en la tienda por la que el cierre selecciona: nunca lo
        // habría tomado. El mínimo cae a tres días del corte, dentro de la ventana de siete.
        ['idArticulo' => $idArticulo, 'idFamilia' => $idFamilia] = $this->escenario->E18();

        $comprobacion = new \ClaseComprobacionStockExtraccion();
        $resultado = $comprobacion->extraer(
            $this->contexto([
                'familiasExcluidas' => [$idFamilia],
                'ventanaDias' => 7,
                'fechaCorte' => $this->ano() . '-01-23',
            ])
        );

        $fila = $this->fila($resultado, $idArticulo);
        self::assertNotNull($fila);
        self::assertEqualsCanonicalizing(
            ['familia_excluida', 'nunca_incluido_en_cierre', 'periodo_no_consolidado', 'regularizacion_en_periodo'],
            $fila['condicionesConocidas']
        );
        self::assertTrue($fila['marcado'], 'El marcado no se absorbe en las condiciones');
        self::assertSame(-6.0, $fila['saldoAlCorte'], 'Ni los extremos de la trayectoria');
        self::assertNotNull($fila['tipoIncidencia'], 'Ni el tipo que emite el componente consumido');
    }

    public function test_T17_laExistenciaEnOtraTiendaNoLibraDeNuncaIncluidoEnElCierre(): void
    {
        // La pregunta es a quién habría tomado el cierre, y el cierre selecciona por la
        // tienda principal. Un producto con existencias solo en otra tienda es
        // exactamente uno que el cierre no habría tomado, se opere donde se opere.
        ['idArticulo' => $idArticulo, 'idTienda' => $idOtraTienda] = $this->escenario->E19();

        $comprobacion = new \ClaseComprobacionStockExtraccion();
        $resultado = $comprobacion->extraer($this->contexto(['idTienda' => (string) $idOtraTienda]));

        $fila = $this->fila($resultado, $idArticulo);
        self::assertNotNull($fila);
        self::assertContains('nunca_incluido_en_cierre', $fila['condicionesConocidas']);
    }

    public function test_T18_laFilaEmitidaNoLlevaNingunCampoDeCausaDelComponenteConsumido(): void
    {
        // El componente consumido acompaña cada hallazgo de una severidad, de una señal
        // de fraccionamiento y de una frase de causa probable, y las tres se calculan a
        // partir de suposiciones sobre por qué el producto está así. Ninguna cruza: lo
        // que sale es el artículo y el tipo, y nada más.
        $idArticulo = $this->escenario->E20()['idArticulo'];

        $comprobacion = new \ClaseComprobacionStockExtraccion();
        $fila = $this->fila($comprobacion->extraer($this->contexto()), $idArticulo);

        self::assertNotNull($fila);
        self::assertSame(
            ['idArticulo', 'saldoAlCorte', 'minimoAlcanzado', 'saldoDeApertura', 'marcado', 'tipoIncidencia', 'condicionesConocidas'],
            array_keys($fila),
            'La fila emitida lleva estos siete campos y ninguno más'
        );
        foreach (['severidad', 'fraccionado_es_causa', 'posible_causa'] as $campoDeCausa) {
            self::assertArrayNotHasKey($campoDeCausa, $fila);
        }
    }

    public function test_T19_loQueElComponenteConsumidoSenalaNoDecideQuienSeExamina(): void
    {
        // El componente cuenta las recepciones con dos estados de albarán de proveedor
        // donde aquí se admiten cuatro. Con una entrada exportada por medio, su curva
        // baja donde la de aquí sube: él señala el producto y aquí no llegó a deber
        // existencias en ningún momento. Quien decide el conjunto es la trayectoria de
        // aquí, así que el producto no sale.
        $corte = $this->ano() . '-06-30';
        $idArticulo = $this->escenario->E21()['idArticulo'];

        $comprobacion = new \ClaseComprobacionStockExtraccion();
        $resultado = $comprobacion->extraer($this->contexto(['fechaCorte' => $corte]));
        self::assertNull($this->fila($resultado, $idArticulo), 'La trayectoria de aquí nunca baja de cero');

        // Y que el componente sí lo señale no es una suposición de este caso.
        $incidencias = (new \ClaseComprobacionStockConsulta())
            ->incidenciasC1(
                $this->ano() . '-01-02',
                $corte,
                $this->anoAnterior() . '-12-31',
                $this->ano() . '-01-01',
                [],
                $this->contexto()
            );
        $loSenala = false;
        foreach ($incidencias as $incidencia) {
            if ((int) $incidencia['idArticulo'] === $idArticulo) {
                $loSenala = true;
            }
        }
        self::assertTrue($loSenala, 'Sin esto el caso pasaría por no haber nada que señalar');
    }

    public function test_T20_elTipoPuedeNoCoincidirConLosExtremosDeSuPropiaFila(): void
    {
        // Consecuencia declarada de lo mismo: el tipo lo compone el componente consumido
        // con su definición de movimiento, no con la de aquí. Con una entrada exportada
        // por medio, la fila sale con saldo positivo al corte y sin marcar, y junto a
        // ella el tipo que ese componente reserva para el producto que debe existencias.
        // Solo los extremos y el marcado son lecturas de este módulo.
        $idArticulo = $this->escenario->E22()['idArticulo'];

        $comprobacion = new \ClaseComprobacionStockExtraccion();
        $fila = $this->fila($comprobacion->extraer($this->contexto()), $idArticulo);

        self::assertNotNull($fila);
        self::assertSame(3.0, $fila['saldoAlCorte']);
        self::assertSame(-2.0, $fila['minimoAlcanzado']);
        self::assertFalse($fila['marcado']);
        self::assertSame('Inventario en negativo', $fila['tipoIncidencia']);
    }

    public function test_T21_elMismoEstadoLeidoHastaDosFechasDistintasNoDaElMismoResultado(): void
    {
        // La fecha hasta la que se lee decide dos cosas: qué movimientos entran en la
        // trayectoria, y desde dónde se cuenta hacia atrás la ventana de consolidación.
        // Sin sembrar nada nuevo, el mismo producto sale marcado como periodo no
        // consolidado leído tres días después del mínimo, y sin esa condición leído
        // seis meses después. Por eso es un dato del criterio y lo emitido tiene que
        // declararlo: dos informes que no lo lleven parecen contradecirse.
        $idArticulo = $this->escenario->E23()['idArticulo'];

        $comprobacion = new \ClaseComprobacionStockExtraccion();

        $cerca = $this->fila(
            $comprobacion->extraer($this->contexto(['ventanaDias' => 7, 'fechaCorte' => $this->ano() . '-01-23'])),
            $idArticulo
        );
        $lejos = $this->fila(
            (new \ClaseComprobacionStockExtraccion())
                ->extraer($this->contexto(['ventanaDias' => 7, 'fechaCorte' => $this->ano() . '-06-30'])),
            $idArticulo
        );

        self::assertNotNull($cerca);
        self::assertNotNull($lejos);
        self::assertSame($cerca['minimoAlcanzado'], $lejos['minimoAlcanzado'], 'La trayectoria sembrada es la misma');
        self::assertContains('periodo_no_consolidado', $cerca['condicionesConocidas']);
        self::assertNotContains('periodo_no_consolidado', $lejos['condicionesConocidas']);
    }
}
