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
