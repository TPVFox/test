<?php
/**
 * Composición única del resultado, en las dos ejecuciones. En el ejercicio vigente
 * una sola estructura alimenta la vista y el fichero de intercambio, y ese fichero
 * declara su origen, su criterio y un resumen de contenido que detecta la edición.
 *
 * En el anterior la misma estructura alimenta la vista y el informe final, y lleva
 * dos contextos en vez de uno: el de este cálculo y el que trajo el fichero. Los
 * dos salen a las dos salidas, porque de qué ejecución vino el fichero no lo puede
 * comprobar el sistema y solo lo puede juzgar quien lo admite. Los cuatro estados
 * se presentan ahí con los dos números que los sostienen, y el menor de los dos se
 * nombra por lo que es: un mínimo que basta, no una cantidad contada.
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

    /**
     * Sustituir un valor en el fichero ya emitido y devolver si el resumen que viaja
     * dentro sigue cuadrando con el que se recalcula al leerlo.
     */
    private function resumenCuadraTrasEditar(string $ruta, string $busca, string $pone): bool
    {
        file_put_contents($ruta, str_replace($busca, $pone, file_get_contents($ruta)));
        $leido = \ClaseComprobacionStockIntercambioXML::simpleXMLToArray(
            new \SimpleXMLElement(file_get_contents($ruta))
        );
        return $leido['resumenDeclarado'] === $leido['resumenRecalculado'];
    }

    public function test_T15_elResumenCubreDeQueEjecucionProcedeElFichero(): void
    {
        $emision = new \ClaseComprobacionStockEmision();
        $composicion = $emision->componer($this->estadoProducto(), $this->contexto(), false);

        $ruta = $this->rutaTemporal();
        $emision->emitir($composicion, $ruta);

        $momento = (string) (new \SimpleXMLElement(file_get_contents($ruta)))->Origen->Momento;

        // El momento y el autor son una fecha y un número: cambiarlos por otra fecha
        // y otro número pasa la comprobación de tipos del esquema sin objeción. Son
        // además lo único que acredita de qué ejecución salió el fichero, así que si
        // el resumen no los cubriera no quedaría nada que los sostuviera.
        self::assertFalse(
            $this->resumenCuadraTrasEditar($ruta, '<Momento>' . $momento . '</Momento>', '<Momento>2020-01-01T00:00:00+00:00</Momento>'),
            'Editar el momento de la ejecución ha de romper el resumen'
        );

        $ruta = $this->rutaTemporal();
        $emision->emitir($composicion, $ruta);
        self::assertFalse(
            $this->resumenCuadraTrasEditar($ruta, '<Autor>7</Autor>', '<Autor>99</Autor>'),
            'Editar el autor de la ejecución ha de romper el resumen'
        );
    }

    public function test_T16_elResumenCubreLoQueElFicheroDeclaraDeSiMismo(): void
    {
        $emision = new \ClaseComprobacionStockEmision();
        $composicion = $emision->componer($this->estadoProducto(), $this->contexto(), false);

        $ruta = $this->rutaTemporal();
        $emision->emitir($composicion, $ruta);
        $fecha = (string) (new \SimpleXMLElement(file_get_contents($ruta)))->Meta->FechaExportacion;

        // La fecha de emisión y el identificador de origen repiten lo que el bloque
        // de origen ya dice. Que lo repitan no los hace inocuos: quien lea el fichero
        // por la cabecera leerá lo que diga la cabecera.
        self::assertFalse(
            $this->resumenCuadraTrasEditar($ruta, '<FechaExportacion>' . $fecha . '</FechaExportacion>', '<FechaExportacion>2020-01-01T00:00:00+00:00</FechaExportacion>'),
            'Editar la fecha de emisión ha de romper el resumen'
        );

        $ruta = $this->rutaTemporal();
        $emision->emitir($composicion, $ruta);
        self::assertFalse(
            $this->resumenCuadraTrasEditar($ruta, 'idOrigen="2026-1"', 'idOrigen="2025-4"'),
            'Editar el identificador de origen ha de romper el resumen'
        );
    }

    public function test_T17_unFicheroDeOtraVersionDelFormatoNoSeLlegaALeer(): void
    {
        $emision = new \ClaseComprobacionStockEmision();
        $composicion = $emision->componer($this->estadoProducto(), $this->contexto(), false);

        $ruta = $this->rutaTemporal();
        $emision->emitir($composicion, $ruta);
        file_put_contents($ruta, str_replace('<Version>1.0</Version>', '<Version>2.0</Version>', file_get_contents($ruta)));

        // Lo detiene el esquema, no el código que consume el fichero: ese código lo
        // lee entero dando por hecho que es de esta versión, y para cuando pudiera
        // notar que no lo es ya habría leído mal.
        $this->incluirTPVFox('/clases/ClaseIOXML.php');
        $io = new \ClaseIOXML($ruta, RUTA_TPVFOX . '/modulos/mod_reorganizacion/comprobacion_stock_intercambio_v1.xsd');

        $this->expectException(\Exception::class);
        $io->cargar();
    }

    public function test_T18_elCatalogoCompletoSeEmiteYValidaEnUnaSolaEmision(): void
    {
        $filas = [];
        for ($i = 1; $i <= 4000; $i++) {
            $filas[] = [
                'idArticulo' => $i,
                'saldoAlCorte' => -1.5,
                'minimoAlcanzado' => -3.0,
                'saldoDeApertura' => 2.0,
                'marcado' => true,
                'tipoIncidencia' => null,
                'condicionesConocidas' => [],
            ];
        }

        $emision = new \ClaseComprobacionStockEmision();
        $composicion = $emision->componer($filas, $this->contexto(), false);

        $ruta = $this->rutaTemporal();
        // El camino de emisión completo sobre el orden de magnitud de un catálogo de
        // tienda: componer, serializar, resumir y validar contra el esquema.
        self::assertTrue($emision->emitir($composicion, $ruta));

        $leido = \ClaseComprobacionStockIntercambioXML::simpleXMLToArray(
            new \SimpleXMLElement(file_get_contents($ruta))
        );
        self::assertCount(4000, $leido['filas']);
        self::assertSame($leido['resumenDeclarado'], $leido['resumenRecalculado']);
    }

    public function test_T19_unaCantidadDeSeisDecimalesSeEmiteYValidaContraElEsquema(): void
    {
        // Una millonésima es una cantidad legítima —la base guarda seis decimales— y el
        // lenguaje la escribe «1.0E-6», que el esquema no admite como número decimal. Una
        // sola fila así rechazaba el fichero entero, con las mil doscientas restantes
        // dentro, y lo único que llegaba a quien exportaba era que no se pudo.
        $filas = [
            ['idArticulo' => 10, 'saldoAlCorte' => -0.000001, 'minimoAlcanzado' => -0.000002,
             'saldoDeApertura' => 0.000001, 'marcado' => true, 'tipoIncidencia' => null,
             'condicionesConocidas' => []],
            ['idArticulo' => 11, 'saldoAlCorte' => -12.345678, 'minimoAlcanzado' => -12.345678,
             'saldoDeApertura' => 0.0, 'marcado' => true, 'tipoIncidencia' => null,
             'condicionesConocidas' => []],
        ];

        $emision = new \ClaseComprobacionStockEmision();
        $composicion = $emision->componer($filas, $this->contexto(), false);
        $ruta = $this->rutaTemporal();
        self::assertTrue($emision->emitir($composicion, $ruta));

        $contenido = file_get_contents($ruta);
        self::assertStringNotContainsString('E-', $contenido, 'Ninguna cantidad en notación científica');

        $leido = \ClaseComprobacionStockIntercambioXML::simpleXMLToArray(new \SimpleXMLElement($contenido));
        self::assertSame(-0.000001, $leido['filas'][0]['saldoAlCorte'], 'La cantidad no se pierde al escribirla');
        self::assertSame(-12.345678, $leido['filas'][1]['saldoAlCorte']);
        self::assertSame($leido['resumenDeclarado'], $leido['resumenRecalculado']);
    }

    /**
     * Un producto por estado, tal como los cuatro llegan a la composición del
     * ejercicio anterior. Los estados van puestos y no calculados a propósito: lo
     * que se comprueba aquí es cómo se presentan, no si la regla acierta.
     */
    private function losCuatroEstados(): array
    {
        return [
            ['idArticulo' => 30, 'comparable' => true, 'marcado' => false,
             'condicionesConocidas' => [], 'existenciaExigida' => 10.0,
             'stockJustificado' => 10.0, 'margen' => 0.5, 'estado' => 'seguro'],
            ['idArticulo' => 31, 'comparable' => true, 'marcado' => true,
             'condicionesConocidas' => ['periodo_no_consolidado'], 'existenciaExigida' => 5.0,
             'stockJustificado' => 12.0, 'margen' => 0.5, 'estado' => 'no_seguro'],
            ['idArticulo' => 32, 'comparable' => true, 'marcado' => false,
             'condicionesConocidas' => ['historico_incompleto'], 'existenciaExigida' => 20.0,
             'stockJustificado' => 3.0, 'margen' => 0.5, 'estado' => 'dudoso'],
            ['idArticulo' => 33, 'comparable' => false, 'marcado' => true,
             'condicionesConocidas' => [], 'existenciaExigida' => 4.0,
             'stockJustificado' => null, 'margen' => 0.5, 'estado' => 'no_comparable'],
        ];
    }

    public function test_T20_losCuatroEstadosSalenConLosDosNumerosQueLosSostienen(): void
    {
        $emision = new \ClaseComprobacionStockEmision();
        $composicion = $emision->componer(
            $this->losCuatroEstados(),
            $this->contexto(['ano' => '2025']),
            false,
            null,
            $this->contextoVigenteFixture()
        );

        $html = htmlTablaComprobacionStock($composicion, 'anterior');
        $celdas = $this->celdasDeLaVista($html);

        // Columnas del anterior: artículo, estado, marcado, condiciones, existencia
        // exigida y mínimo necesario justificado.
        self::assertSame(
            ['Seguro', 'No seguro', 'Dudoso', 'No comparable'],
            array_column($celdas, 1),
            'Los cuatro estados salen, y en texto'
        );

        // Ninguno viaja solo: al lado van siempre las dos cantidades cuya distancia
        // es lo que el estado resume. Sin ellas la etiqueta vuelve a leerse como un
        // diagnóstico en vez de como una posición frente a un criterio.
        self::assertSame(['10', '5', '20', '4'], array_column($celdas, 4));
        self::assertSame(['10', '12', '3', '—'], array_column($celdas, 5));

        // Y los cuatro se pintan igual entre sí: nada los ordena por gravedad ni
        // señala uno como error. El que no tiene con qué compararse tampoco pierde
        // la cantidad que el otro ejercicio sí estableció por su cuenta.
        self::assertSame(
            4,
            substr_count($html, '<span class="label label-default">'),
            'Los cuatro estados comparten presentación'
        );
    }

    public function test_T21_laPantallaDelAnteriorDiceDeQueEjecucionVinoElFichero(): void
    {
        $emision = new \ClaseComprobacionStockEmision();

        // Dos ficheros del mismo ejercicio y la misma tienda: la admisión los acepta
        // igual porque por su contenido son admisibles los dos, y lo único que los
        // separa es cuándo se emitieron y quién. Si eso no sale en pantalla, quien
        // admite no tiene con qué notar que está clasificando contra el viejo.
        $delVigente = array_merge($this->contextoVigenteFixture(), ['ventanaDias' => 3]);

        $primero = $emision->componer(
            $this->estadoProductoClasificado(),
            $this->contexto(['ano' => '2025', 'ventanaDias' => 7]),
            false,
            null,
            $delVigente
        );
        $segundo = $emision->componer(
            $this->estadoProductoClasificado(),
            $this->contexto(['ano' => '2025', 'ventanaDias' => 7]),
            false,
            null,
            array_merge($delVigente, [
                'momento' => '2026-02-14T18:30:00+01:00',
                'autor' => 4,
            ])
        );

        $htmlPrimero = htmlTablaComprobacionStock($primero, 'anterior');
        $htmlSegundo = htmlTablaComprobacionStock($segundo, 'anterior');

        foreach ([$htmlPrimero, $htmlSegundo] as $html) {
            self::assertStringContainsString('id="contextoComprobacionStockAnterior"', $html);
            self::assertStringContainsString('id="contextoComprobacionStockAnteriorOrigen"', $html);
            self::assertStringContainsString('El fichero admitido se emitió en el ejercicio vigente', $html);
            // El criterio del vigente también sale, y no es el de aquí: las condiciones
            // que llegaron en el fichero se marcaron con esos umbrales, así que sin
            // ellos la mitad de la fila queda sin con qué leerse.
            self::assertStringContainsString('<li><strong>Ventana de consolidación:</strong> 7 día(s)</li>', $html);
            self::assertStringContainsString('<li><strong>Ventana de consolidación:</strong> 3 día(s)</li>', $html);
        }

        self::assertStringContainsString(
            '<li><strong>Emitido el:</strong> 2026-02-01T09:00:00+01:00</li><li><strong>Autor:</strong> 9</li>',
            $htmlPrimero
        );
        self::assertStringContainsString(
            '<li><strong>Emitido el:</strong> 2026-02-14T18:30:00+01:00</li><li><strong>Autor:</strong> 4</li>',
            $htmlSegundo
        );
    }

    public function test_T22_laPantallaDelAnteriorYElInformeLlevanLasMismasFilas(): void
    {
        $emision = new \ClaseComprobacionStockEmision();
        $composicion = $emision->componer(
            $this->losCuatroEstados(),
            $this->contexto(['ano' => '2025']),
            false,
            null,
            $this->contextoVigenteFixture()
        );

        $ruta = $this->rutaTemporal('csv');
        $emision->emitirInforme($composicion, $ruta);
        $lineas = explode("\n", trim(str_replace("\xEF\xBB\xBF", '', file_get_contents($ruta))));

        // Las filas del informe empiezan tras su cabecera de columnas, que es la
        // última línea con el nombre del primer campo.
        $desde = 0;
        foreach ($lineas as $indice => $linea) {
            if (strpos($linea, 'IdArticulo;') === 0) {
                $desde = $indice + 1;
                break;
            }
        }
        $delInforme = array_map(
            static fn (string $linea): array => explode(';', $linea),
            array_slice($lineas, $desde)
        );

        $deLaVista = $this->celdasDeLaVista(htmlTablaComprobacionStock($composicion, 'anterior'));

        self::assertCount(count($delInforme), $deLaVista, 'Las dos salidas llevan tantas filas como la otra');

        $etiquetas = ['seguro' => 'Seguro', 'no_seguro' => 'No seguro',
                      'dudoso' => 'Dudoso', 'no_comparable' => 'No comparable'];

        foreach ($delInforme as $indice => $filaInforme) {
            $celdas = $deLaVista[$indice];

            self::assertSame($filaInforme[0], $celdas[0]);
            self::assertSame($etiquetas[$filaInforme[1]], $celdas[1]);
            self::assertSame($filaInforme[2] === '1' ? 'Sí' : 'No', $celdas[2]);
            self::assertSame(
                $filaInforme[3] === '' ? '—' : implode(', ', explode(',', $filaInforme[3])),
                $celdas[3]
            );
            // Las dos cantidades se escriben igual en las dos salidas: el informe se
            // lee al lado de la pantalla, y un número escrito de dos maneras no se
            // puede comparar fila a fila.
            self::assertSame($filaInforme[4], $celdas[4]);
            self::assertSame($filaInforme[5] === '' ? '—' : $filaInforme[5], $celdas[5]);
        }
    }

    public function test_T23_elMinimoJustificadoSeNombraPorLoQueEsEnLasDosSalidas(): void
    {
        $emision = new \ClaseComprobacionStockEmision();
        $composicion = $emision->componer(
            $this->losCuatroEstados(),
            $this->contexto(['ano' => '2025']),
            false,
            null,
            $this->contextoVigenteFixture()
        );

        $html = htmlTablaComprobacionStock($composicion, 'anterior');

        // El número es el mínimo que basta para explicar los movimientos, no un
        // recuento. Llamarlo stock lo convierte en una existencia comprobada, y este
        // informe se lee para decidir si se corrigen existencias.
        self::assertStringNotContainsString('Stock justificado', $html);
        self::assertStringContainsString('Mínimo necesario justificado <sup>*</sup>', $html);
        self::assertStringContainsString('no un recuento ni una existencia comprobada', $html);

        $ruta = $this->rutaTemporal('csv');
        $emision->emitirInforme($composicion, $ruta);
        $contenido = file_get_contents($ruta);

        self::assertStringContainsString('MinimoNecesarioJustificado', $contenido);
        self::assertStringNotContainsString('StockJustificado', $contenido);
    }

    public function test_T24_unaCantidadMinusculaSeEscribeIgualEnLaPantallaQueEnElInforme(): void
    {
        $emision = new \ClaseComprobacionStockEmision();
        $composicion = $emision->componer(
            [[
                'idArticulo' => 40, 'comparable' => true, 'marcado' => false,
                'condicionesConocidas' => [], 'existenciaExigida' => 0.000001,
                'stockJustificado' => 0.000001, 'margen' => 0.0, 'estado' => 'seguro',
            ]],
            $this->contexto(['ano' => '2025']),
            false,
            null,
            $this->contextoVigenteFixture()
        );

        // Una millonésima es una cantidad legítima: la base guarda seis decimales.
        // El lenguaje la escribe «1.0E-6» al volcarla a texto, y así salía en la
        // pantalla mientras el informe de esa misma composición escribía «0.000001».
        $celdas = $this->celdasDeLaVista(htmlTablaComprobacionStock($composicion, 'anterior'))[0];
        self::assertSame('0.000001', $celdas[4]);
        self::assertSame('0.000001', $celdas[5]);

        $ruta = $this->rutaTemporal('csv');
        $emision->emitirInforme($composicion, $ruta);
        self::assertStringContainsString('40;seguro;0;;0.000001;0.000001', file_get_contents($ruta));
    }
}
