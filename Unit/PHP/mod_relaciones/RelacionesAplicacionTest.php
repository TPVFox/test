<?php
/**
 * Las reglas de la aplicación de una relación que no necesitan la base: el escandallo de un
 * despiece —cuánto sale de cada pieza y cuánto cuesta—, el periodo y su fecha, lo que hay que
 * despiezar según los movimientos, el signo de cada línea según el documento, y las líneas que
 * se dejan preparadas para compras.
 *
 * El despiece de casi todos los casos es el del pollo: de 1 kg entran 0,25 kg de pechuga,
 * 0,35 kg de muslo, 0,12 kg de alas y 0,24 kg de carcasa; 0,96 kg en total.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModRelaciones;

use PHPUnit\Framework\TestCase;

final class RelacionesAplicacionTest extends TestCase
{
    /**
     * @comportamiento Con el ajuste por defecto, la mitad del coste se reparte por kilos y la
     *   otra mitad por valor de mercado, y la suma es exactamente el coste de lo despiezado.
     */
    public function test_T1_conElAjustePorDefectoSeRepartePorMitadesYLaSumaEsElCosteDeLoDespiezado(): void
    {
        $version = $this->despiece(1, [[2, 0.5], [3, 0.5]]);

        $escandallo = $this->escandallo($version, 100, 2, [], [2 => 3.0, 3 => 1.0]);

        self::assertSame(100.0, $escandallo['despiezado']);
        self::assertSame(200.0, $escandallo['coste']);
        self::assertSame([2.5, 1.5], $this->costesPorUnidad($escandallo));
        self::assertEqualsWithDelta(200.0, $this->sumaDeCostes($escandallo), 0.0001);
        self::assertFalse($escandallo['soloPeso']);
    }

    /** @comportamiento Dos piezas con el mismo precio de mercado salen al mismo coste por kilo. */
    public function test_T2_dosPiezasConElMismoPrecioSalenAlMismoCostePorKilo(): void
    {
        $version = $this->despiece(1, [[2, 0.3], [3, 0.3], [4, 0.2]]);

        $escandallo = $this->escandallo($version, 100, 2.9, [], [2 => 5.0, 3 => 5.0, 4 => 1.0]);

        $costes = $this->costesPorUnidad($escandallo);
        self::assertSame($costes[0], $costes[1]);
        self::assertGreaterThan($costes[2], $costes[0]);
    }

    /**
     * @comportamiento Una pieza sin valor de mercado, como el desecho, no sale con coste
     *   negativo: solo recibe su parte por peso, también con el ajuste en sus bordes.
     */
    public function test_T3_unaPiezaConPrecioCeroNoSaleConCosteNegativo(): void
    {
        $version = $this->despiece(1, [[2, 0.5], [3, 0.5]]);

        foreach ([[1.0, 1.0], [0.0, 2.0], [0.6, 1.4], [1.4, 0.6], [2.0, 0.0]] as [$peso, $valor]) {
            $escandallo = $this->escandallo($version, 100, 2, [], [2 => 4.0, 3 => 0.0], $peso, $valor);
            foreach ($escandallo['piezas'] as $pieza) {
                self::assertGreaterThanOrEqual(0.0, $pieza['costeUnidad']);
            }
            self::assertEqualsWithDelta(200 * $peso * 0.5 / 2 / 50, $escandallo['piezas'][1]['costeUnidad'], 0.0001);
        }
    }

    /**
     * @comportamiento Si ninguna pieza tiene un precio de mercado mayor que 0, el reparto por
     *   valor no tiene base: se reparte solo por peso y se avisa.
     */
    public function test_T4_sinNingunPrecioMayorQueCeroSeRepartePorPesoYSeAvisa(): void
    {
        $version = $this->despiece(1, [[2, 0.5], [3, 0.25]]);

        $resultado = $this->aplicacion()->escandallo($version, 100, 2, [], [2 => 0.0, 3 => 0.0], 1, 1);

        self::assertTrue($resultado['datos']['soloPeso']);
        self::assertSame([2.0, 2.0], $this->costesPorUnidad($resultado['datos']));
        self::assertNotEmpty($resultado['avisos']);
        self::assertStringContainsString('solo por peso', $resultado['avisos'][0]['mensaje']);
    }

    /** @comportamiento El ajuste va de 0 a 2 y suma 2; los bordes valen y lo demás se rechaza. */
    public function test_T5_unAjusteFueraDeRangoOQueNoSumaDosSeRechaza(): void
    {
        $version = $this->despiece(1, [[2, 0.5], [3, 0.5]]);
        $aplicacion = $this->aplicacion();

        foreach ([[0, 2], [2, 0], [0.6, 1.4], [1, 1]] as [$peso, $valor]) {
            self::assertArrayHasKey('datos', $aplicacion->escandallo($version, 100, 2, [], [2 => 3.0, 3 => 1.0], $peso, $valor));
        }
        foreach ([[-0.1, 2.1], [2.1, -0.1], [1, 1.2], [1.5, 1.5], ['uno', 'dos']] as [$peso, $valor]) {
            $resultado = $aplicacion->escandallo($version, 100, 2, [], [2 => 3.0, 3 => 1.0], $peso, $valor);
            self::assertArrayHasKey('error', $resultado);
            self::assertStringContainsString('ajuste', $resultado['error']['mensaje']);
        }
    }

    /**
     * @comportamiento Los kilos de cada pieza salen de su proporción sobre la entrada, aunque la
     *   entrada del despiece no sea de 1 kg.
     */
    public function test_T6_losKilosDeCadaPiezaSalenDeSuProporcionSobreLaEntrada(): void
    {
        $version = $this->despiece(2, [[2, 1], [3, 0.5]]);

        $escandallo = $this->escandallo($version, 100, 2, [], [2 => 3.0, 3 => 1.0]);

        self::assertSame([50.0, 25.0], $this->cantidades($escandallo));
        self::assertSame(75.0, $escandallo['despiezado']);
        self::assertSame(25.0, $escandallo['merma']);
    }

    /**
     * @comportamiento La salida no puede pesar más que la entrada. Si pesa igual no hay merma, y
     *   si pesa menos, la diferencia queda como merma del producto despiezado y no se carga a las
     *   piezas: solo se reparte el coste de lo despiezado.
     */
    public function test_T7_laSalidaQuePesaMasSeRechazaYLaQuePesaMenosDejaMerma(): void
    {
        $aplicacion = $this->aplicacion();

        $completo = $this->escandallo($this->despiece(1, [[2, 0.5], [3, 0.5]]), 180, 2, [], [2 => 3.0, 3 => 1.0]);
        self::assertSame(0.0, $completo['merma']);

        $pollo = $this->escandallo($this->pollo(), 180, 2.9, [], $this->preciosDelPollo());
        self::assertSame([45.0, 63.0, 21.6, 43.2], $this->cantidades($pollo));
        self::assertSame(172.8, $pollo['despiezado']);
        self::assertSame(7.2, $pollo['merma']);
        self::assertEqualsWithDelta(172.8 * 2.9, $this->sumaDeCostes($pollo), 0.005);

        $demasiado = $aplicacion->escandallo($this->despiece(1, [[2, 0.6], [3, 0.5]]), 180, 2, [], [2 => 3.0, 3 => 1.0], 1, 1);
        self::assertArrayHasKey('error', $demasiado);
        self::assertStringContainsString('pesa más que la entrada', $demasiado['error']['mensaje']);
    }

    /**
     * @comportamiento Un despiece con líneas en kg y en ud a la vez no se aplica. En ud, cada
     *   pieza sale en unidades enteras, y la fracción queda como merma.
     */
    public function test_T8_unasUnidadesMezcladasNoSeAplicanYEnUdSaleEnUnidadesEnteras(): void
    {
        $aplicacion = $this->aplicacion();
        $mezcla = $this->despiece(1, [[2, 0.5], [3, 0.5]]);
        $mezcla['lineas'][2]['unidad'] = 'ud';

        $resultado = $aplicacion->escandallo($mezcla, 100, 2, [], [2 => 3.0, 3 => 1.0], 1, 1);
        self::assertArrayHasKey('error', $resultado);
        self::assertStringContainsString('misma unidad', $resultado['error']['mensaje']);

        $enUnidades = $this->despiece(2, [[2, 1], [3, 1]], 'ud');
        $escandallo = $this->escandallo($enUnidades, 3, 2, [], [2 => 3.0, 3 => 1.0]);
        self::assertSame([1.0, 1.0], $this->cantidades($escandallo));
        self::assertSame(1.0, $escandallo['merma']);
    }

    /**
     * @comportamiento Cada coste por kilo va a 4 decimales, la escala de las líneas de compra, y
     *   la pieza con menos kilos cierra el total: el albarán queda en 0,00 € sin IVA aunque los
     *   importes no se dividan exactos.
     */
    public function test_T9_losCostesVanACuatroDecimalesYElTotalSinIvaQuedaEnCero(): void
    {
        $version = $this->despiece(1, [[2, 0.37], [3, 0.29], [4, 0.21], [5, 0.07]]);

        $escandallo = $this->escandallo($version, 137.413, 2.9137, [], [2 => 6.5, 3 => 3.1, 4 => 1.7, 5 => 0.3]);

        foreach ($escandallo['piezas'] as $pieza) {
            self::assertSame(round($pieza['costeUnidad'], 4), $pieza['costeUnidad']);
        }
        $lineas = $this->aplicacion()->lineasDelAlbaran($escandallo, $this->datosDeArticulos([1, 2, 3, 4, 5]), \RelacionesAplicacion::DOCUMENTO_PROVEEDOR);
        self::assertEqualsWithDelta(0.0, $this->totalSinIvaComoCompras($lineas), 0.0049);
    }

    /**
     * @comportamiento El signo de cada línea lo dan su sentido y el documento: en un albarán de
     *   proveedor, la entrada resta y las salidas suman; en uno de cliente, al revés.
     */
    public function test_T10_elSignoLoDanElSentidoYElDocumento(): void
    {
        $aplicacion = $this->aplicacion();

        self::assertSame(-1, $aplicacion->signo('entrada', \RelacionesAplicacion::DOCUMENTO_PROVEEDOR));
        self::assertSame(1, $aplicacion->signo('salida', \RelacionesAplicacion::DOCUMENTO_PROVEEDOR));
        self::assertSame(1, $aplicacion->signo('entrada', \RelacionesAplicacion::DOCUMENTO_CLIENTE));
        self::assertSame(-1, $aplicacion->signo('salida', \RelacionesAplicacion::DOCUMENTO_CLIENTE));
    }

    /** @comportamiento Solo se aplica la forma 1:N con cantidad; las demás, con su motivo. */
    public function test_T11_soloSeAplicaLaFormaUnoAVariosConCantidad(): void
    {
        $aplicacion = $this->aplicacion();
        $lineas = $this->pollo()['lineas'];

        self::assertTrue($aplicacion->esAplicable('1:N', 1, $lineas)['aplicable']);
        foreach ([['1:N', 0], ['N:1', 1], ['1:1', 1]] as [$forma, $llevaCantidad]) {
            $resultado = $aplicacion->esAplicable($forma, $llevaCantidad, $lineas);
            self::assertFalse($resultado['aplicable']);
            self::assertNotSame('', $resultado['motivo']);
        }
    }

    /**
     * @comportamiento Un precio escrito vale lo mismo con coma que con punto decimal; uno
     *   negativo o que no es un número se rechaza, y uno vacío es que no se escribió.
     */
    public function test_T12_unPrecioEscritoSeNormalizaYUnoNoValidoSeRechaza(): void
    {
        $aplicacion = $this->aplicacion();

        self::assertSame(6.5, $aplicacion->precioEscrito('6,5')['datos']);
        self::assertSame(6.5, $aplicacion->precioEscrito(' 6.5 ')['datos']);
        self::assertSame(0.0, $aplicacion->precioEscrito('0')['datos']);
        self::assertNull($aplicacion->precioEscrito('')['datos']);
        foreach (['-1', 'abc', '6,5,1', '1e3'] as $texto) {
            self::assertArrayHasKey('error', $aplicacion->precioEscrito($texto));
        }
    }

    /**
     * @comportamiento El precio escrito manda sobre la media de los proveedores; sin ninguno de
     *   los dos, se pide escribirlo y el aviso nombra la pieza.
     */
    public function test_T13_elPrecioEscritoMandaYSinNingunoSePideEscribirlo(): void
    {
        $aplicacion = $this->aplicacion();
        $version = $this->despiece(1, [[2, 0.5], [3, 0.5]]);

        $escandallo = $this->escandallo($version, 100, 2, [3 => '2,5'], [2 => 3.0, 3 => 1.0]);
        self::assertSame(['media', 'escrito'], array_column($escandallo['piezas'], 'origenPrecio'));
        self::assertSame([3.0, 2.5], array_column($escandallo['piezas'], 'precio'));

        $sinPrecio = $aplicacion->escandallo($version, 100, 2, [], [2 => 3.0, 3 => null], 1, 1);
        self::assertArrayHasKey('error', $sinPrecio);
        self::assertStringContainsString('Artículo 3', $sinPrecio['error']['mensaje']);
    }

    /**
     * @comportamiento Lo que hay que despiezar es lo que entra desde la primera entrada del
     *   periodo menos lo que sale desde ese día: lo vendido antes no cuenta, y sin ninguna
     *   entrada no hay nada que despiezar.
     */
    public function test_T14_loQueHayQueDespiezarEsLaEntradaDesdeLaPrimeraMenosLasSalidas(): void
    {
        $aplicacion = $this->aplicacion();
        $proveedor = [$this->mov('2026-02-03', 100), $this->mov('2026-02-17', 100), $this->mov('2026-02-20', -50)];
        $tickets = [$this->mov('2026-02-02', 4), $this->mov('2026-02-20', 15)];
        $clientes = [$this->mov('2026-02-25', 5)];

        $resultado = $aplicacion->cantidadADespiezar($proveedor, $tickets, $clientes);
        self::assertSame(130.0, $resultado['cantidad']);
        self::assertSame('2026-02-03', $resultado['primeraEntrada']);

        $sinEntrada = $aplicacion->cantidadADespiezar([$this->mov('2026-02-20', -50)], $tickets, []);
        self::assertSame(0.0, $sinEntrada['cantidad']);
        self::assertNull($sinEntrada['primeraEntrada']);
    }

    /**
     * @comportamiento Un mes va del día 1 a su último día y el albarán se fecha el último; un
     *   lote es un solo día. Una fecha que no existe se rechaza.
     */
    public function test_T15_elPeriodoDeUnMesYElDeUnLote(): void
    {
        $aplicacion = $this->aplicacion();

        self::assertSame(['desde' => '2026-02-01', 'hasta' => '2026-02-28', 'fecha' => '2026-02-28'], $aplicacion->periodo('mes', '2026-02')['datos']);
        self::assertSame('2024-02-29', $aplicacion->periodo('mes', '2024-02')['datos']['fecha']);
        self::assertSame('2026-04-30', $aplicacion->periodo('mes', '2026-04')['datos']['fecha']);
        self::assertSame('2026-01-31', $aplicacion->periodo('mes', '2026-01')['datos']['fecha']);
        self::assertSame(['desde' => '2026-02-03', 'hasta' => '2026-02-03', 'fecha' => '2026-02-03'], $aplicacion->periodo('lote', '2026-02-03')['datos']);
        foreach ([['mes', '2026-13'], ['mes', 'abc'], ['lote', '2026-02-30'], ['semana', '2026-02'], ['mes', "2026-02' OR 1=1"]] as [$tipo, $valor]) {
            self::assertArrayHasKey('error', $aplicacion->periodo($tipo, $valor));
        }
    }

    /**
     * @comportamiento Las líneas que se dejan preparadas tienen la forma de las de compras: la
     *   entrada en negativo por lo despiezado a su coste de partida, cada pieza en positivo a su
     *   coste, cada una con el IVA de su artículo y sin coste anterior, y con la relación y la
     *   versión aplicadas en el detalle, que se recorta para que todo quepa en 100 caracteres.
     */
    public function test_T16_lasLineasDelAlbaranTienenLaFormaDeComprasYElSignoDeSuSentido(): void
    {
        $escandallo = $this->escandallo($this->pollo(), 180, 2.9, [], $this->preciosDelPollo());

        $lineas = $this->aplicacion()->lineasDelAlbaran($escandallo, $this->datosDeArticulos([1, 2, 3, 4, 5]), \RelacionesAplicacion::DOCUMENTO_PROVEEDOR, ' · REL 7 v1.2');

        self::assertCount(5, $lineas);
        $claves = ['ccodbar', 'cdetalle', 'cref', 'estado', 'idArticulo', 'importe', 'iva', 'ncant', 'nfila', 'nunidades', 'ref_prov', 'ultimoCoste'];
        foreach ($lineas as $posicion => $linea) {
            $tiene = array_keys($linea);
            sort($tiene);
            self::assertSame($claves, $tiene);
            self::assertSame($posicion + 1, $linea['nfila']);
            self::assertSame('Activo', $linea['estado']);
            self::assertSame($linea['nunidades'], $linea['ncant']);
            self::assertEqualsWithDelta($linea['nunidades'] * $linea['ultimoCoste'], $linea['importe'], 0.000001);
        }
        self::assertSame(1, $lineas[0]['idArticulo']);
        self::assertSame(-172.8, $lineas[0]['nunidades']);
        self::assertSame(2.9, $lineas[0]['ultimoCoste']);
        self::assertSame(45.0, $lineas[1]['nunidades']);
        self::assertSame('Artículo 2 · REL 7 v1.2', $lineas[1]['cdetalle']);

        $largo = $this->datosDeArticulos([1, 2, 3, 4, 5]);
        $largo[2]['articulo_name'] = str_repeat('Ñ', 120);
        $recortadas = $this->aplicacion()->lineasDelAlbaran($escandallo, $largo, \RelacionesAplicacion::DOCUMENTO_PROVEEDOR, ' · REL 7 v1.2');
        self::assertSame(100, mb_strlen($recortadas[1]['cdetalle']));
        self::assertSame(str_repeat('Ñ', 87) . ' · REL 7 v1.2', $recortadas[1]['cdetalle']);
        self::assertEqualsWithDelta(0.0, $this->totalSinIvaComoCompras($lineas), 0.0049);
    }

    /**
     * @comportamiento Compras no guarda un texto con comillas o barra invertida: si el nombre,
     *   la referencia o el código de barras de una línea lo lleva, no se prepara, y el aviso
     *   nombra el artículo.
     */
    public function test_T17_unTextoConComillasOBarraInvertidaEnUnaLineaNoSeAdmite(): void
    {
        $aplicacion = $this->aplicacion();
        $limpias = [$this->lineaDeCompras(1, 'Pollo entero', 'REF1', '840001')];

        self::assertArrayNotHasKey('error', $aplicacion->textosAdmitidos($limpias));
        foreach ([['Pollo "de corral"', 'REF1', '840001'], ["Pollo d'Ávila", 'REF1', '840001'], ['Pollo', 'REF\\1', '840001'], ['Pollo', "RE'F", '840001'], ['Pollo', 'REF1', '84"0001']] as [$nombre, $referencia, $codigo]) {
            $resultado = $aplicacion->textosAdmitidos([$this->lineaDeCompras(1, $nombre, $referencia, $codigo)]);
            self::assertArrayHasKey('error', $resultado, $nombre . ' ' . $referencia . ' ' . $codigo);
            self::assertStringContainsString($nombre, $resultado['error']['mensaje']);
        }
    }

    /**
     * @comportamiento Si una pieza tiene otro IVA que el producto despiezado, el albarán quedaría
     *   con un IVA que no se compensa: se avisa, nombrando la pieza.
     */
    public function test_T18_unaPiezaConOtroIvaQueLaEntradaSeAvisa(): void
    {
        $aplicacion = $this->aplicacion();
        $lineas = [$this->lineaDeCompras(1, 'Pollo', '', '', 10), $this->lineaDeCompras(2, 'Pechuga', '', '', 10), $this->lineaDeCompras(3, 'Caldo', '', '', 21)];

        self::assertSame(['Caldo'], $aplicacion->ivaDistinto($lineas));
        self::assertSame([], $aplicacion->ivaDistinto(array_slice($lineas, 0, 2)));
    }

    /**
     * @comportamiento En los extremos del ajuste, todo por peso da el mismo coste por kilo a todas
     *   las piezas, sin necesidad de precios de mercado; todo por valor da un coste por kilo
     *   proporcional al precio, y una pieza sin precio sale a 0 € y se avisa.
     */
    public function test_T19_enLosExtremosDelAjusteTodoSeRepartePorPesoOPorValor(): void
    {
        $version = $this->despiece(1, [[2, 0.5], [3, 0.3], [4, 0.1]]);
        $aplicacion = $this->aplicacion();

        $porPeso = $aplicacion->escandallo($version, 100, 2, [], [2 => null, 3 => null, 4 => null], 2.0, 0.0);
        self::assertArrayHasKey('datos', $porPeso, json_encode($porPeso['error'] ?? null, JSON_UNESCAPED_UNICODE));
        self::assertSame([2.0, 2.0, 2.0], $this->costesPorUnidad($porPeso['datos']));
        self::assertEqualsWithDelta(90 * 2.0, $this->sumaDeCostes($porPeso['datos']), 0.0049);
        self::assertSame([], $porPeso['avisos']);

        $porValor = $this->escandallo($version, 100, 2, [], [2 => 4.0, 3 => 2.0, 4 => 0.0], 0.0, 2.0);
        $costes = $this->costesPorUnidad($porValor);
        self::assertEqualsWithDelta(2 * $costes[1], $costes[0], 0.0002);
        self::assertSame(0.0, $costes[2]);
        self::assertEqualsWithDelta(90 * 2.0, $this->sumaDeCostes($porValor), 0.0049);

        $aviso = $aplicacion->escandallo($version, 100, 2, [], [2 => 4.0, 3 => 2.0, 4 => 0.0], 0.0, 2.0)['avisos'];
        self::assertStringContainsString('Artículo 4', $aviso[0]['mensaje']);
    }

    /**
     * @comportamiento La cantidad que se aplica se escribe con coma o con punto decimal, mayor que 0
     *   y con hasta tres decimales; en un despiece por unidades, entera. Vacía es que no se escribió.
     *   Lo demás se rechaza y el aviso dice cómo escribirla.
     */
    public function test_T20_laCantidadEscritaSeNormalizaYUnaQueNoValeSeRechaza(): void
    {
        $aplicacion = $this->aplicacion();

        self::assertSame(100.5, $aplicacion->cantidadEscrita('100,5', 'kg')['datos']);
        self::assertSame(100.125, $aplicacion->cantidadEscrita(' 100.125 ', 'kg')['datos']);
        self::assertSame(4.0, $aplicacion->cantidadEscrita('4', 'ud')['datos']);
        self::assertNull($aplicacion->cantidadEscrita('', 'kg')['datos']);
        self::assertNull($aplicacion->cantidadEscrita('  ', 'ud')['datos']);
        foreach (['0', '0,000', '-5', 'abc', '1,2345', '1e3', '100,5,1', '1 OR 1=1'] as $texto) {
            self::assertArrayHasKey('error', $aplicacion->cantidadEscrita($texto, 'kg'), $texto);
        }
        $enUnidades = $aplicacion->cantidadEscrita('2,5', 'ud');
        self::assertArrayHasKey('error', $enUnidades);
        self::assertStringContainsString('sin decimales', $enUnidades['error']['mensaje']);
    }

    /**
     * @comportamiento El rendimiento de una versión es la suma de sus salidas entre su entrada, sin las
     *   líneas que no están activas. Sin entrada o sin salidas es 1: la línea saca su cantidad.
     */
    public function test_T21_elRendimientoEsLaSumaDeLasSalidasEntreLaEntrada(): void
    {
        $aplicacion = $this->aplicacion();
        $baja = $this->despiece(2, [[2, 1.2], [3, 0.72], [4, 0.5]]);
        $baja['lineas'][3]['estado'] = 'Eliminado';

        self::assertEqualsWithDelta(0.96, $aplicacion->rendimiento($this->pollo()['lineas']), 0.000001);
        self::assertEqualsWithDelta(0.96, $aplicacion->rendimiento($baja['lineas']), 0.000001);
        self::assertSame(1.0, $aplicacion->rendimiento(array_slice($this->pollo()['lineas'], 1)));
        self::assertSame(1.0, $aplicacion->rendimiento(array_slice($this->pollo()['lineas'], 0, 1)));
    }

    /**
     * @comportamiento Lo que queda de cada pieza es su saldo inicial, más lo que entró, menos lo que
     *   salió, más lo que genera la aplicación. Su mínimo es lo que le falta sin lo que genera, entre lo
     *   que da por unidad de la entrada, redondeado hacia arriba al gramo; el de la receta, el mayor.
     */
    public function test_T22_loQueQuedaDeCadaPiezaYElMinimoDeLaReceta(): void
    {
        $desajuste = $this->aplicacion()->desajuste([
            $this->pieza(2, 0.25, 0, 0, 40, 25),
            $this->pieza(3, 0.35, 5, 10, 20, 35),
            $this->pieza(4, 0.12, 0, 0, 0, 12),
        ]);

        self::assertSame([-15.0, 30.0, 12.0], array_column($desajuste['piezas'], 'queda'));
        self::assertSame([160.0, 14.286, 0.0], array_column($desajuste['piezas'], 'minimo'));
        self::assertSame(160.0, $desajuste['minimo']);
    }

    /** @comportamiento En un despiece por unidades, el mínimo se redondea hacia arriba a la unidad. */
    public function test_T23_enUnidadesElMinimoEsEntero(): void
    {
        $desajuste = $this->aplicacion()->desajuste([$this->pieza(2, 0.4, 0, 0, 3, 2, 'ud')]);

        self::assertSame(-1.0, $desajuste['piezas'][0]['queda']);
        self::assertSame(8.0, $desajuste['minimo']);
    }

    private function aplicacion(): \RelacionesAplicacion
    {
        global $RutaServidor, $HostNombre, $URLCom;
        require_once RUTA_TPVFOX . '/modulos/mod_relaciones/clases/RelacionesAplicacion.php';

        return new \RelacionesAplicacion();
    }

    /** Una pieza para el desajuste: lo que da por unidad de la entrada y sus movimientos. */
    private function pieza(int $id, float $porUnidad, float $saldoInicial, float $entradas, float $salidas, float $genera, string $unidad = 'kg'): array
    {
        return ['idArticulo' => $id, 'articulo' => 'Artículo ' . $id, 'unidad' => $unidad, 'porUnidad' => $porUnidad,
                'saldoInicial' => $saldoInicial, 'entradas' => $entradas, 'salidas' => $salidas, 'genera' => $genera];
    }

    /**
     * Un despiece de forma 1:N: el artículo 1 entra con la cantidad indicada y cada salida es un
     * par artículo y cantidad.
     *
     * @param list<array{0:int,1:float}> $salidas
     */
    private function despiece(float $entrada, array $salidas, string $unidad = 'kg'): array
    {
        $lineas = [['idArticulo' => 1, 'articulo' => 'Artículo 1', 'sentido' => 'entrada', 'cantidad' => $entrada, 'unidad' => $unidad, 'estado' => 'Activo']];
        foreach ($salidas as [$id, $cantidad]) {
            $lineas[] = ['idArticulo' => $id, 'articulo' => 'Artículo ' . $id, 'sentido' => 'salida', 'cantidad' => $cantidad, 'unidad' => $unidad, 'estado' => 'Activo'];
        }

        return ['forma' => '1:N', 'lineas' => $lineas];
    }

    private function pollo(): array
    {
        return $this->despiece(1, [[2, 0.25], [3, 0.35], [4, 0.12], [5, 0.24]]);
    }

    private function preciosDelPollo(): array
    {
        return [2 => 6.5, 3 => 3.0, 4 => 2.5, 5 => 0.5];
    }

    private function escandallo(array $version, float $cantidad, float $coste, array $escritos, array $medias, float $peso = 1, float $valor = 1): array
    {
        $resultado = $this->aplicacion()->escandallo($version, $cantidad, $coste, $escritos, $medias, $peso, $valor);
        self::assertArrayHasKey('datos', $resultado, json_encode($resultado['error'] ?? null, JSON_UNESCAPED_UNICODE));

        return $resultado['datos'];
    }

    /** @return list<float> */
    private function costesPorUnidad(array $escandallo): array
    {
        return array_column($escandallo['piezas'], 'costeUnidad');
    }

    /** @return list<float> */
    private function cantidades(array $escandallo): array
    {
        return array_column($escandallo['piezas'], 'cantidad');
    }

    private function sumaDeCostes(array $escandallo): float
    {
        $suma = 0.0;
        foreach ($escandallo['piezas'] as $pieza) {
            $suma += $pieza['cantidad'] * $pieza['costeUnidad'];
        }

        return $suma;
    }

    /** @return array<int,array{articulo_name:string,crefTienda:string,codBarras:string,iva:float}> */
    private function datosDeArticulos(array $ids): array
    {
        $datos = [];
        foreach ($ids as $id) {
            $datos[$id] = ['articulo_name' => 'Artículo ' . $id, 'crefTienda' => 'REF' . $id, 'codBarras' => '84000' . $id, 'iva' => 10.0];
        }

        return $datos;
    }

    private function mov(string $fecha, float $unidades): array
    {
        return ['fecha' => $fecha, 'nunidades' => $unidades];
    }

    private function lineaDeCompras(int $id, string $nombre, string $referencia, string $codigo, float $iva = 10): array
    {
        return ['ccodbar' => $codigo, 'cdetalle' => $nombre, 'cref' => $referencia, 'ref_prov' => '', 'estado' => 'Activo', 'idArticulo' => $id,
                'importe' => 0.0, 'iva' => $iva, 'ncant' => 1.0, 'nfila' => 1, 'nunidades' => 1.0, 'ultimoCoste' => 0.0];
    }

    /** El total sin IVA tal como lo calcula compras: base por tipo de IVA, redondeada a céntimos. */
    private function totalSinIvaComoCompras(array $lineas): float
    {
        $bases = [];
        foreach ($lineas as $linea) {
            $bases[(string) $linea['iva']] = ($bases[(string) $linea['iva']] ?? 0.0) + $linea['importe'];
        }
        $total = 0.0;
        foreach ($bases as $base) {
            $total += (float) number_format($base, 2, '.', '');
        }

        return round($total, 2);
    }
}
