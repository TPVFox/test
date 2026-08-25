<?php
/**
 * Composición única del resultado en el ejercicio vigente: una sola estructura
 * alimenta la vista y el fichero de intercambio, y ese fichero declara su origen, su
 * criterio y un resumen de contenido que detecta la edición.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModReorganizacion\Comprobacion;

use TPVFox\Test\CasoIntegracion;

final class ComprobacionEmisionIntegracionTest extends CasoIntegracion
{
    /** No toca la base: compone sobre lo que ya le llega. */
    protected bool $aislarPorTransaccion = false;

    private array $rutasEmitidas = [];

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION ??= [];
        $_SESSION['usuarioTpv'] = ['id' => 7];
        $this->incluirTPVFox('/modulos/mod_reorganizacion/clases/ClaseComprobacionEmision.php');
        $this->incluirTPVFox('/modulos/mod_reorganizacion/clases/ClaseComprobacionIntercambioXML.php');
    }

    protected function tearDown(): void
    {
        unset($_SESSION['usuarioTpv']);
        foreach ($this->rutasEmitidas as $ruta) {
            if (file_exists($ruta)) {
                unlink($ruta);
            }
        }
        parent::tearDown();
    }

    private function rutaTemporal(): string
    {
        $ruta = sys_get_temp_dir() . '/comprobacion-emision-' . uniqid('', true) . '.xml';
        $this->rutasEmitidas[] = $ruta;
        return $ruta;
    }

    private function contexto(array $cambios = []): array
    {
        return array_merge([
            'ano' => '2026',
            'idTienda' => '1',
            'ventanaDias' => 7,
            'umbralSobrestock' => 50.0,
            'proveedorCierre' => 112,
            'familiasExcluidas' => [],
        ], $cambios);
    }

    private function estadoProducto(): array
    {
        return [
            [
                'idArticulo' => 10,
                'saldoAlCorte' => -5.0,
                'minimoAlcanzado' => -8.5,
                'marcado' => true,
                'tipoIncidencia' => 'C1a',
                'condicionesConocidas' => ['periodo_no_consolidado'],
            ],
            [
                'idArticulo' => 11,
                'saldoAlCorte' => -2.0,
                'minimoAlcanzado' => -2.0,
                'marcado' => true,
                'tipoIncidencia' => null,
                'condicionesConocidas' => [],
            ],
        ];
    }

    public function test_T1_unaSolaComposicionAlimentaLaVistaYElFichero(): void
    {
        $emision = new \ClaseComprobacionEmision();
        $composicion = $emision->componer($this->estadoProducto(), $this->contexto(), false);

        $ruta = $this->rutaTemporal();
        $emision->emitir($composicion, $ruta);

        $xml = new \SimpleXMLElement(file_get_contents($ruta));
        $delFichero = \ClaseComprobacionIntercambioXML::simpleXMLToArray($xml);

        self::assertCount(2, $delFichero['filas']);
        foreach ($composicion['filas'] as $indice => $filaOriginal) {
            $filaFichero = $delFichero['filas'][$indice];
            self::assertSame($filaOriginal['idArticulo'], $filaFichero['idArticulo']);
            self::assertSame($filaOriginal['saldoAlCorte'], $filaFichero['saldoAlCorte']);
            self::assertSame($filaOriginal['minimoAlcanzado'], $filaFichero['minimoAlcanzado']);
            self::assertSame($filaOriginal['marcado'], $filaFichero['marcado']);
            self::assertSame($filaOriginal['tipoIncidencia'], $filaFichero['tipoIncidencia']);
            self::assertSame($filaOriginal['condicionesConocidas'], $filaFichero['condicionesConocidas']);
        }
    }

    public function test_T2_elFiltroSeResuelveAntesDeComponerYConstaEnLoEmitido(): void
    {
        $emision = new \ClaseComprobacionEmision();
        $composicion = $emision->componer($this->estadoProducto(), $this->contexto(), false, [10]);

        self::assertCount(1, $composicion['filas'], 'El filtro ya resolvió el subconjunto al componer');

        $ruta = $this->rutaTemporal();
        $emision->emitir($composicion, $ruta);

        $xml = new \SimpleXMLElement(file_get_contents($ruta));
        self::assertTrue(isset($xml->Criterio->Filtro), 'Lo emitido declara que es un subconjunto filtrado');
        self::assertSame('10', (string) $xml->Criterio->Filtro->Articulo);

        $vuelta = \ClaseComprobacionIntercambioXML::simpleXMLToArray($xml);
        self::assertSame([10], $vuelta['contexto']['filtro']);
    }

    public function test_T2b_sinFiltroLoEmitidoNoDeclaraSubconjunto(): void
    {
        $emision = new \ClaseComprobacionEmision();
        $composicion = $emision->componer($this->estadoProducto(), $this->contexto(), false);

        $ruta = $this->rutaTemporal();
        $emision->emitir($composicion, $ruta);

        $xml = new \SimpleXMLElement(file_get_contents($ruta));
        self::assertFalse(isset($xml->Criterio->Filtro), 'Sin filtro, el conjunto emitido es el completo');
    }

    public function test_T3_unFicheroEstructuralmenteInvalidoNoSeAdmite(): void
    {
        $ruta = $this->rutaTemporal();
        file_put_contents($ruta, '<?xml version="1.0"?><ComprobacionIntercambio idOrigen="x"><Meta/></ComprobacionIntercambio>');

        $this->incluirTPVFox('/clases/ClaseIOXML.php');
        $io = new \ClaseIOXML($ruta, RUTA_TPVFOX . '/modulos/mod_reorganizacion/comprobacion_intercambio_v1.xsd');

        $this->expectException(\Exception::class);
        $io->cargar();
    }

    public function test_T4_elResumenDetectaUnFicheroEditadoYVueltoAGuardar(): void
    {
        $emision = new \ClaseComprobacionEmision();
        $composicion = $emision->componer($this->estadoProducto(), $this->contexto(), false);

        $ruta = $this->rutaTemporal();
        $emision->emitir($composicion, $ruta);

        $sinEditar = \ClaseComprobacionIntercambioXML::simpleXMLToArray(new \SimpleXMLElement(file_get_contents($ruta)));
        self::assertSame($sinEditar['resumenDeclarado'], $sinEditar['resumenRecalculado']);

        $editado = str_replace('<SaldoAlCorte>-5</SaldoAlCorte>', '<SaldoAlCorte>-500</SaldoAlCorte>', file_get_contents($ruta));
        file_put_contents($ruta, $editado);

        $trasEditar = \ClaseComprobacionIntercambioXML::simpleXMLToArray(new \SimpleXMLElement(file_get_contents($ruta)));
        self::assertNotSame($trasEditar['resumenDeclarado'], $trasEditar['resumenRecalculado']);
    }

    public function test_T5_elOrigenDeclaraElProveedorQueIdentificaElTraspaso(): void
    {
        $emision = new \ClaseComprobacionEmision();
        $composicion = $emision->componer($this->estadoProducto(), $this->contexto(['proveedorCierre' => 112]), false);

        $ruta = $this->rutaTemporal();
        $emision->emitir($composicion, $ruta);

        $xml = new \SimpleXMLElement(file_get_contents($ruta));
        self::assertSame(112, (int) $xml->Origen->Traspaso->IdProveedor);
    }

    public function test_T6_elContextoSeCopiaPorValorYNoCambiaSiSeReconfiguraDespues(): void
    {
        $emision = new \ClaseComprobacionEmision();

        $composicionOriginal = $emision->componer($this->estadoProducto(), $this->contexto(['ventanaDias' => 7]), false);
        $rutaOriginal = $this->rutaTemporal();
        $emision->emitir($composicionOriginal, $rutaOriginal);

        // Una segunda ejecución con la configuración ya cambiada no debe alterar lo que
        // el primer fichero declaró.
        $composicionReconfigurada = $emision->componer($this->estadoProducto(), $this->contexto(['ventanaDias' => 99]), false);
        $rutaSegunda = $this->rutaTemporal();
        $emision->emitir($composicionReconfigurada, $rutaSegunda);

        $primero = new \SimpleXMLElement(file_get_contents($rutaOriginal));
        self::assertSame(7, (int) $primero->Criterio->VentanaDias);

        $segundo = new \SimpleXMLElement(file_get_contents($rutaSegunda));
        self::assertSame(99, (int) $segundo->Criterio->VentanaDias);
    }
}
