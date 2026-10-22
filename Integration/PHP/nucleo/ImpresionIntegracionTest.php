<?php

/**
 * Los documentos impresos comunes: las dos clases sobre TCPDF —`imprimir` (`clases/imprimir.php`) e
 * `imprimirPDF` (`clases/claseimprimir.php`)— y los dos guiones con los que los módulos sacan un
 * documento a un PDF temporal —`controllers/planImprimir.php`, con cabecera, y
 * `controllers/planImprimirRe.php`, sin ella—.
 *
 * Cada caso escribe el PDF en una carpeta temporal propia y lo lee con `pdftotext`. Corre en un
 * proceso aparte: al cargarse en línea de órdenes, TCPDF pone `$_SERVER['DOCUMENT_ROOT']` a «/», y las
 * clases que deducen de ahí la ruta del producto dejarían de encontrarla en los casos que vinieran
 * detrás.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\Nucleo;

use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ImpresionIntegracionTest extends TestCase
{
    private string $carpeta = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (trim((string) shell_exec('command -v pdftotext')) === '') {
            self::markTestSkipped('Hace falta pdftotext para leer los PDF generados.');
        }
        global $RutaServidor, $HostNombre;
        require_once RUTA_TPVFOX . '/clases/imprimir.php';
        require_once RUTA_TPVFOX . '/clases/claseimprimir.php';
        $this->carpeta = sys_get_temp_dir() . '/tpvfox-impresion-' . bin2hex(random_bytes(4));
        mkdir($this->carpeta);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->carpeta . '/*') ?: []);
        if (is_dir($this->carpeta)) {
            rmdir($this->carpeta);
        }
        parent::tearDown();
    }

    /**
     * @estado rojo
     * @codigo-afectado plugins/paginacion/ClasePaginacion.php:149-157
     * @codigo-afectado clases/ClasePermisos.php:146-151
     *
     * @group esperado
     * @group impresion
     * @group bajo
     *
     * @que-ocurre-hoy En una ejecución por línea de órdenes, después de imprimir, la paginación, los
     *   permisos, el menú y los complementos ya no encuentran la ruta del producto: la librería de PDF
     *   cambia la raíz del servidor al cargarse, y esas clases la deducen de ella.
     * @que-deberia-ocurrir Que imprimir no cambie dónde se encuentra el producto.
     * @por-que-ocurre La librería pone la raíz del servidor a «/» si está vacía, como en línea de
     *   órdenes, y esas clases quitan la raíz de su propia ruta para deducir la del producto: con
     *   «/», quitan todas las barras.
     * @como-deberia-funcionar Que el núcleo tome la ruta del producto de un solo sitio, sin la raíz
     *   del servidor.
     */
    public function test_imprimirNoCambiaDondeSeEncuentraElProducto(): void
    {
        require_once RUTA_TPVFOX . '/plugins/paginacion/ClasePaginacion.php';
        $paginacion = new \PluginClasePaginacion('/tpvfox/modulos/mod_prueba/ListaPrueba.php');

        $paginacion->obtenerRutaProyecto();

        self::assertSame('', $_SERVER['DOCUMENT_ROOT'], 'La raíz del servidor de la línea de órdenes no cambia al imprimir.');
        self::assertSame(realpath(RUTA_TPVFOX), realpath($paginacion->HostNombre), 'La paginación tiene que encontrar el producto.');
    }

    /**
     * @estado rojo
     * @codigo-afectado plugins/paginacion/ClasePaginacion.php:149-157
     * @codigo-afectado clases/ClasePermisos.php:146-151
     *
     * @group esperado
     * @group impresion
     * @group bajo
     *
     * @que-ocurre-hoy En una petición del servidor web cuya raíz no acaba en barra, después de
     *   imprimir, la paginación y las demás clases que deducen la ruta del producto la dan sin la
     *   barra del principio.
     * @que-deberia-ocurrir Que imprimir no cambie la ruta del producto que dan esas clases.
     * @por-que-ocurre La librería de PDF añade una barra al final de la raíz del servidor al
     *   cargarse, y esas clases quitan la raíz de su propia ruta: con la barra, se llevan también la
     *   del principio de la ruta del producto.
     * @como-deberia-funcionar Que el núcleo tome la ruta del producto de un solo sitio, sin la raíz
     *   del servidor.
     */
    public function test_conLaRaizDelServidorWebImprimirNoCambiaLaRutaDelProducto(): void
    {
        $guion = '$_SERVER["DOCUMENT_ROOT"] = ' . var_export(dirname(RUTA_TPVFOX), true) . ';'
            . ' $RutaServidor = $_SERVER["DOCUMENT_ROOT"]; $HostNombre = ' . var_export('/' . basename(RUTA_TPVFOX), true) . ';'
            . ' require $RutaServidor . $HostNombre . "/plugins/paginacion/ClasePaginacion.php";'
            . ' $paginacion = new PluginClasePaginacion("/listado.php");'
            . ' $paginacion->obtenerRutaProyecto(); $antes = $paginacion->HostNombre;'
            . ' require $RutaServidor . $HostNombre . "/clases/imprimir.php";'
            . ' $paginacion->obtenerRutaProyecto();'
            . ' echo json_encode(["antes" => $antes, "despues" => $paginacion->HostNombre]);';
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -r ' . escapeshellarg($guion) . ' 2>/dev/null', $salida);
        $rutas = json_decode((string) end($salida), true);

        self::assertSame('/' . basename(RUTA_TPVFOX) . '/', $rutas['antes'], 'Antes de imprimir, la ruta del producto empieza por barra.');
        self::assertSame($rutas['antes'], $rutas['despues'], 'Imprimir no puede cambiar la ruta del producto.');
    }

    /**
     * @comportamiento El guion de impresión con cabecera escribe en la carpeta temporal un PDF con la
     *   cabecera y el cuerpo del documento.
     */
    public function test_elGuionConCabeceraEscribeCabeceraYCuerpo(): void
    {
        $texto = $this->imprimirConGuion('planImprimir.php', '<p>Albarán 775</p>', '<p>Línea de lomo</p>');

        self::assertStringContainsString('Albarán 775', $texto);
        self::assertStringContainsString('Línea de lomo', $texto);
    }

    /** @comportamiento El guion sin cabecera escribe solo el cuerpo. */
    public function test_elGuionSinCabeceraEscribeSoloElCuerpo(): void
    {
        $texto = $this->imprimirConGuion('planImprimirRe.php', '<p>Albarán 775</p>', '<p>Recálculo de precios</p>');

        self::assertStringContainsString('Recálculo de precios', $texto);
        self::assertStringNotContainsString('Albarán 775', $texto);
    }

    /**
     * @comportamiento La segunda clase pone la cabecera en cada página y numera las páginas al pie, «Página
     *   N/T».
     */
    public function test_laSegundaClaseNumeraLasPaginasAlPie(): void
    {
        $pdf = new \imprimirPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setCabecera('<p>Informe de beneficio</p>');
        $pdf->SetMargins(15, 30, 15);
        $pdf->AddPage();
        $pdf->writeHTML('<p>Primera</p>');
        $pdf->AddPage();
        $pdf->writeHTML('<p>Segunda</p>');

        $texto = $this->leer($pdf);

        self::assertSame(2, substr_count($texto, 'Informe de beneficio'));
        self::assertStringContainsString('Página 1/2', $texto);
        self::assertStringContainsString('Página 2/2', $texto);
    }

    /**
     * @comportamiento La primera clase tiene además un pie propio de prueba y una caja de texto con
     *   margen izquierdo fijo, que no usa ningún módulo.
     */
    public function test_laPrimeraClaseTienePieDePruebaYCajaDeTexto(): void
    {
        $pdf = new \imprimir('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setHtmlHeader('');
        $pdf->AddPage();
        $pdf->CajaDeTexto('Texto suelto', 0, 50);
        $pdf->PieDePagina();

        $texto = $this->leer($pdf);

        self::assertStringContainsString('Texto suelto', $texto);
        self::assertStringContainsString('esto es una prueba de tpv', $texto);
    }

    /**
     * Ejecuta el guion de impresión con las variables que le dejan los módulos y devuelve el texto
     * del PDF que escribe.
     */
    private function imprimirConGuion(string $guion, string $cabecera, string $html): string
    {
        $RutaServidor = '';
        $rutatmp = $this->carpeta;
        $nombreTmp = 'documento.pdf';
        $margen_top_caja_texto = 40;

        include RUTA_TPVFOX . '/controllers/' . $guion;

        self::assertFileExists($this->carpeta . '/documento.pdf');

        return self::texto($this->carpeta . '/documento.pdf');
    }

    private function leer(\TCPDF $pdf): string
    {
        $fichero = $this->carpeta . '/clase.pdf';
        $pdf->Output($fichero, 'F');

        return self::texto($fichero);
    }

    private static function texto(string $pdf): string
    {
        return (string) shell_exec('pdftotext -layout ' . escapeshellarg($pdf) . ' - 2>/dev/null');
    }
}
