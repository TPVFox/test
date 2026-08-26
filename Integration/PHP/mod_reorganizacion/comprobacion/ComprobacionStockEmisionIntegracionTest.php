<?php
/**
 * Composición única del resultado en el ejercicio vigente: una sola estructura
 * alimenta la vista y el fichero de intercambio, y ese fichero declara su origen, su
 * criterio y un resumen de contenido que detecta la edición.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModReorganizacion\Comprobacion;

use TPVFox\Test\CasoIntegracion;

final class ComprobacionStockEmisionIntegracionTest extends CasoIntegracion
{
    /** No toca la base: compone sobre lo que ya le llega. */
    protected bool $aislarPorTransaccion = false;

    private array $rutasEmitidas = [];

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION ??= [];
        $_SESSION['usuarioTpv'] = ['id' => 7];
        $this->incluirTPVFox('/modulos/mod_reorganizacion/clases/ClaseComprobacionStockEmision.php');
        $this->incluirTPVFox('/modulos/mod_reorganizacion/clases/ClaseComprobacionStockIntercambioXML.php');
        // La pantalla es la otra salida de la misma composición, así que se monta
        // aquí igual que el fichero: comprobar solo el fichero deja sin mirar la
        // mitad de lo que la composición alimenta.
        $this->incluirTPVFox('/modulos/mod_reorganizacion/funciones.php');
    }

    /**
     * Extraer las filas de la tabla montada como listas de texto por celda, para
     * poder compararlas con lo que salió al fichero sin depender del marcado.
     *
     * @return array<int, array<int, string>>
     */
    private function celdasDeLaVista(string $html): array
    {
        $documento = new \DOMDocument();
        $documento->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR);
        $xpath = new \DOMXPath($documento);

        $filas = [];
        foreach ($xpath->query('//tbody/tr') as $tr) {
            $celdas = [];
            foreach ($xpath->query('.//td', $tr) as $td) {
                $celdas[] = trim($td->textContent);
            }
            $filas[] = $celdas;
        }
        return $filas;
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

    private function rutaTemporal(string $extension = 'xml'): string
    {
        $ruta = sys_get_temp_dir() . '/comprobacion-emision-' . uniqid('', true) . '.' . $extension;
        $this->rutasEmitidas[] = $ruta;
        return $ruta;
    }

    private function contexto(array $cambios = []): array
    {
        return array_merge([
            'ano' => '2026',
            'idTienda' => '1',
            'ventanaDias' => 7,
            'umbralFraccionado' => 0.05,
            'umbralMagnitud' => 0.5,
            'umbralPorVenta' => 0.010,
            'timingVentanaDias' => 1,
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
                'saldoDeApertura' => 3.0,
                'marcado' => true,
                'tipoIncidencia' => 'Inventario en negativo',
                'condicionesConocidas' => ['periodo_no_consolidado'],
            ],
            [
                'idArticulo' => 11,
                'saldoAlCorte' => -2.0,
                'minimoAlcanzado' => -2.0,
                'saldoDeApertura' => 0.0,
                'marcado' => true,
                'tipoIncidencia' => null,
                'condicionesConocidas' => [],
            ],
        ];
    }

    public function test_T1_unaSolaComposicionAlimentaLaVistaYElFichero(): void
    {
        $emision = new \ClaseComprobacionStockEmision();
        $composicion = $emision->componer($this->estadoProducto(), $this->contexto(), false);

        $ruta = $this->rutaTemporal();
        $emision->emitir($composicion, $ruta);

        $xml = new \SimpleXMLElement(file_get_contents($ruta));
        $delFichero = \ClaseComprobacionStockIntercambioXML::simpleXMLToArray($xml);

        self::assertCount(2, $delFichero['filas']);
        foreach ($composicion['filas'] as $indice => $filaOriginal) {
            $filaFichero = $delFichero['filas'][$indice];
            self::assertSame($filaOriginal['idArticulo'], $filaFichero['idArticulo']);
            self::assertSame($filaOriginal['saldoAlCorte'], $filaFichero['saldoAlCorte']);
            self::assertSame($filaOriginal['minimoAlcanzado'], $filaFichero['minimoAlcanzado']);
            self::assertSame($filaOriginal['saldoDeApertura'], $filaFichero['saldoDeApertura']);
            self::assertSame($filaOriginal['marcado'], $filaFichero['marcado']);
            self::assertSame($filaOriginal['tipoIncidencia'], $filaFichero['tipoIncidencia']);
            self::assertSame($filaOriginal['condicionesConocidas'], $filaFichero['condicionesConocidas']);
        }
    }

    public function test_T2_elFiltroSeResuelveAntesDeComponerYConstaEnLoEmitido(): void
    {
        $emision = new \ClaseComprobacionStockEmision();
        $composicion = $emision->componer($this->estadoProducto(), $this->contexto(), false, [10]);

        self::assertCount(1, $composicion['filas'], 'El filtro ya resolvió el subconjunto al componer');

        $ruta = $this->rutaTemporal();
        $emision->emitir($composicion, $ruta);

        $xml = new \SimpleXMLElement(file_get_contents($ruta));
        self::assertTrue(isset($xml->Criterio->Filtro), 'Lo emitido declara que es un subconjunto filtrado');
        self::assertSame('10', (string) $xml->Criterio->Filtro->Articulo);

        $vuelta = \ClaseComprobacionStockIntercambioXML::simpleXMLToArray($xml);
        self::assertSame([10], $vuelta['contexto']['filtro']);
    }

    public function test_T2b_sinFiltroLoEmitidoNoDeclaraSubconjunto(): void
    {
        $emision = new \ClaseComprobacionStockEmision();
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
        $io = new \ClaseIOXML($ruta, RUTA_TPVFOX . '/modulos/mod_reorganizacion/comprobacion_stock_intercambio_v1.xsd');

        $this->expectException(\Exception::class);
        $io->cargar();
    }

    public function test_T4_elResumenDetectaUnFicheroEditadoYVueltoAGuardar(): void
    {
        $emision = new \ClaseComprobacionStockEmision();
        $composicion = $emision->componer($this->estadoProducto(), $this->contexto(), false);

        $ruta = $this->rutaTemporal();
        $emision->emitir($composicion, $ruta);

        $sinEditar = \ClaseComprobacionStockIntercambioXML::simpleXMLToArray(new \SimpleXMLElement(file_get_contents($ruta)));
        self::assertSame($sinEditar['resumenDeclarado'], $sinEditar['resumenRecalculado']);

        $editado = str_replace('<SaldoAlCorte>-5</SaldoAlCorte>', '<SaldoAlCorte>-500</SaldoAlCorte>', file_get_contents($ruta));
        file_put_contents($ruta, $editado);

        $trasEditar = \ClaseComprobacionStockIntercambioXML::simpleXMLToArray(new \SimpleXMLElement(file_get_contents($ruta)));
        self::assertNotSame($trasEditar['resumenDeclarado'], $trasEditar['resumenRecalculado']);
    }

    public function test_T5_elOrigenDeclaraElProveedorQueIdentificaElTraspaso(): void
    {
        $emision = new \ClaseComprobacionStockEmision();
        $composicion = $emision->componer($this->estadoProducto(), $this->contexto(['proveedorCierre' => 112]), false);

        $ruta = $this->rutaTemporal();
        $emision->emitir($composicion, $ruta);

        $xml = new \SimpleXMLElement(file_get_contents($ruta));
        self::assertSame(112, (int) $xml->Origen->Traspaso->IdProveedor);
    }

    /** Productos ya clasificados, tal como los recibe la emisión del anterior. */
    private function estadoProductoClasificado(): array
    {
        return [
            [
                'idArticulo' => 20,
                'comparable' => true,
                'minimoAlcanzado' => -4.0,
                'saldoDeApertura' => 6.0,
                'marcado' => true,
                'condicionesConocidas' => ['periodo_no_consolidado'],
                'stockJustificado' => 10.0,
                'margen' => 0.5,
                'existenciaExigida' => 10.0,
                'estado' => 'seguro',
            ],
            [
                'idArticulo' => 21,
                'comparable' => true,
                'minimoAlcanzado' => -1.0,
                'saldoDeApertura' => 0.0,
                'marcado' => true,
                'condicionesConocidas' => [],
                'stockJustificado' => null,
                'margen' => 0.5,
                'existenciaExigida' => 1.0,
                'estado' => 'no_comparable',
            ],
        ];
    }

    /** Simula el contexto tal como lo entrega la admisión del fichero del vigente. */
    private function contextoVigenteFixture(): array
    {
        return [
            'ano' => '2026',
            'idTienda' => 1,
            'momento' => '2026-02-01T09:00:00+01:00',
            'autor' => 9,
            'proveedorCierre' => 112,
            'ventanaDias' => 7,
            'umbralFraccionado' => 0.05,
            'umbralMagnitud' => 0.5,
            'umbralPorVenta' => 0.010,
            'timingVentanaDias' => 1,
            'modoTrayectoria' => 'normal',
            'filtro' => [],
        ];
    }

    public function test_T7_elInformeSeAbreConSuSeparadorYCoincideConLaPantalla(): void
    {
        $emision = new \ClaseComprobacionStockEmision();
        $composicion = $emision->componer(
            $this->estadoProductoClasificado(),
            $this->contexto(['ano' => '2025']),
            false,
            null,
            $this->contextoVigenteFixture()
        );

        $ruta = $this->rutaTemporal('csv');
        $emision->emitirInforme($composicion, $ruta);

        $contenido = file_get_contents($ruta);
        self::assertStringStartsWith("\xEF\xBB\xBF", $contenido, 'El informe declara BOM UTF-8');

        $sinBom = substr($contenido, 3);
        self::assertStringContainsString(
            "20;seguro;1;periodo_no_consolidado;10;10",
            str_replace('.0', '', $sinBom),
            'La fila del informe coincide con la de la composición que también alimenta la pantalla'
        );
        self::assertStringContainsString(
            "21;no_comparable;1;;1;",
            str_replace('.0', '', $sinBom)
        );
    }

    public function test_T8_elInformeArrastraLosDosContextosDeLasDosEmisiones(): void
    {
        $_SESSION['usuarioTpv'] = ['id' => 9];
        $emisionVigente = new \ClaseComprobacionStockEmision();
        $composicionVigente = $emisionVigente->componer([], $this->contexto(['ano' => '2026']), false);

        $_SESSION['usuarioTpv'] = ['id' => 7];
        $emisionAnterior = new \ClaseComprobacionStockEmision();
        $composicionAnterior = $emisionAnterior->componer(
            $this->estadoProductoClasificado(),
            $this->contexto(['ano' => '2025']),
            false,
            null,
            $composicionVigente['contexto']
        );

        $ruta = $this->rutaTemporal('csv');
        $emisionAnterior->emitirInforme($composicionAnterior, $ruta);

        $contenido = str_replace("\xEF\xBB\xBF", '', file_get_contents($ruta));

        self::assertMatchesRegularExpression('/Contexto;Anterior\nEjercicio;2025.*Autor;7/s', $contenido);
        self::assertMatchesRegularExpression('/Contexto;Vigente\nEjercicio;2026.*Autor;9/s', $contenido);
    }

    public function test_T6_elContextoSeCopiaPorValorYNoCambiaSiSeReconfiguraDespues(): void
    {
        $emision = new \ClaseComprobacionStockEmision();

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

    public function test_T9_laVistaMontadaYElFicheroLlevanLasMismasFilas(): void
    {
        $emision = new \ClaseComprobacionStockEmision();
        $composicion = $emision->componer($this->estadoProducto(), $this->contexto(), false);

        $ruta = $this->rutaTemporal();
        $emision->emitir($composicion, $ruta);
        $delFichero = \ClaseComprobacionStockIntercambioXML::simpleXMLToArray(
            new \SimpleXMLElement(file_get_contents($ruta))
        );

        $deLaVista = $this->celdasDeLaVista(htmlTablaComprobacionStock($composicion, 'vigente'));

        self::assertCount(count($delFichero['filas']), $deLaVista, 'Las dos salidas llevan tantas filas como la otra');

        foreach ($delFichero['filas'] as $indice => $filaFichero) {
            // Columnas del ejercicio vigente: marca de selección, artículo, saldo al
            // corte, mínimo, saldo de apertura, marcado, incidencia y condiciones.
            $celdas = $deLaVista[$indice];

            self::assertSame($filaFichero['idArticulo'], (int) $celdas[1]);
            self::assertSame($filaFichero['saldoAlCorte'], (float) $celdas[2]);
            self::assertSame($filaFichero['minimoAlcanzado'], (float) $celdas[3]);
            self::assertSame($filaFichero['saldoDeApertura'], (float) $celdas[4]);
            self::assertSame($filaFichero['marcado'] ? 'Sí' : 'No', $celdas[5]);
            self::assertSame($filaFichero['tipoIncidencia'] ?? '—', $celdas[6]);
            self::assertSame(
                $filaFichero['condicionesConocidas'] === [] ? '—' : implode(', ', $filaFichero['condicionesConocidas']),
                $celdas[7]
            );
        }
    }

    public function test_T10_laPantallaSaleConLoQueSeUsoParaCalcularla(): void
    {
        $emision = new \ClaseComprobacionStockEmision();
        $composicion = $emision->componer($this->estadoProducto(), $this->contexto(), false);

        $html = htmlTablaComprobacionStock($composicion, 'vigente');

        // Quien mira la pantalla decide sobre ella, y ni los umbrales ni el momento
        // aparecen en ninguna celda: si no salen aparte, no salen.
        self::assertStringContainsString('id="contextoComprobacionStockVigente"', $html);
        self::assertStringContainsString(
            '<li><strong>Calculado el:</strong> ' . $composicion['contexto']['momento'] . '</li>',
            $html
        );
        self::assertStringContainsString('<li><strong>Trayectoria:</strong> normal</li>', $html);
        self::assertStringContainsString('<li><strong>Umbral de fraccionado:</strong> 0.05</li>', $html);
        self::assertStringContainsString('<li><strong>Umbral de magnitud:</strong> 0.5</li>', $html);
        self::assertStringContainsString('<li><strong>Umbral por venta:</strong> 0.01</li>', $html);
        self::assertStringContainsString('<li><strong>Ventana de consolidación:</strong> 7 día(s)</li>', $html);
        self::assertStringContainsString('<li><strong>Ventana de registro tardío:</strong> 1 día(s)</li>', $html);
    }

    public function test_T11_loQueLlegaDeOtroInformeNoSePintaComoPropio(): void
    {
        $emision = new \ClaseComprobacionStockEmision();
        $composicion = $emision->componer($this->estadoProducto(), $this->contexto(), false);

        $html = htmlTablaComprobacionStock($composicion, 'vigente');

        // Distinguible en la propia celda, anunciado en la cabecera, y explicado
        // debajo: las tres cosas, porque una marca que solo se vea al pasar el ratón
        // no está en la pantalla que alguien imprime o lee.
        self::assertStringContainsString(
            '<span class="label label-info">Inventario en negativo</span>',
            $html
        );
        self::assertStringContainsString('Incidencia <sup>*</sup>', $html);
        self::assertStringContainsString('No es una conclusión de esta comprobación', $html);

        // Y la fila cuyo dato no llegó no inventa ninguna etiqueta.
        self::assertSame('—', $this->celdasDeLaVista($html)[1][6]);
    }

    public function test_T12_loEmitidoDeclaraLaSeleccionPedidaYNoLaQueEncontro(): void
    {
        $emision = new \ClaseComprobacionStockEmision();

        // Se piden tres productos y el conjunto solo tiene dos de ellos: es lo que
        // ocurre cuando se marca en una pantalla y se descarga después, porque entre
        // una cosa y otra el conjunto se vuelve a calcular.
        $composicion = $emision->componer($this->estadoProducto(), $this->contexto(), false, [10, 11, 999]);

        $ruta = $this->rutaTemporal();
        $emision->emitir($composicion, $ruta);
        $xml = new \SimpleXMLElement(file_get_contents($ruta));

        $declarados = [];
        foreach ($xml->Criterio->Filtro->Articulo as $idArticulo) {
            $declarados[] = (int) $idArticulo;
        }
        self::assertSame([10, 11, 999], $declarados, 'Lo emitido declara lo que se pidió');

        $contenidos = [];
        foreach ($xml->Filas->Fila as $fila) {
            $contenidos[] = (int) $fila->IdArticulo;
        }
        self::assertSame([10, 11], $contenidos, 'Y contiene lo que había');

        // La diferencia entre las dos listas es lo que hace legible que no se entregó
        // todo lo pedido. Declarar en su lugar la intersección la borraría.
        self::assertNotSame($declarados, $contenidos);
    }

    public function test_T13_sinNingunProductoLasDosSalidasLoDicenIgual(): void
    {
        $emision = new \ClaseComprobacionStockEmision();
        $composicion = $emision->componer([], $this->contexto(), false);

        $ruta = $this->rutaTemporal();
        // Que no haya nada que señalar es un resultado, no un fallo: el fichero se
        // emite igual y con su criterio dentro, porque es lo que permite distinguir
        // «se miró y no había» de «no se llegó a mirar».
        self::assertTrue($emision->emitir($composicion, $ruta));

        $xml = new \SimpleXMLElement(file_get_contents($ruta));
        self::assertCount(0, $xml->Filas->children());
        self::assertSame(7, (int) $xml->Criterio->VentanaDias);

        $html = htmlTablaComprobacionStock($composicion, 'vigente');
        self::assertSame([], $this->celdasDeLaVista($html));
        self::assertStringContainsString('0 producto(s)', $html);
        // Y la pantalla vacía tampoco pierde con qué se calculó.
        self::assertStringContainsString('id="contextoComprobacionStockVigente"', $html);
    }

    public function test_T14_unaRamaQueNoExisteNoMontaUnaTablaAMedias(): void
    {
        $emision = new \ClaseComprobacionStockEmision();
        $composicion = $emision->componer($this->estadoProducto(), $this->contexto(), false);

        // Las columnas son las que separan los dos ejercicios. Pedir unas que no
        // están declaradas tiene que parar aquí y no producir una tabla incompleta,
        // que se leería como si el ejercicio no tuviera esos datos.
        $this->expectException(\InvalidArgumentException::class);
        htmlTablaComprobacionStock($composicion, 'inexistente');
    }
}
