<?php

/**
 * La lectura, validación y escritura de ficheros XML de intercambio (`clases/ClaseIOXML.php`): cargar
 * un fichero, validarlo contra su esquema, guardarlo, recibirlo de una subida y entregarlo como
 * descarga.
 *
 * Cada caso trabaja sobre ficheros en una carpeta temporal propia, con un esquema mínimo.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\Nucleo;

use Exception;
use PHPUnit\Framework\TestCase;

final class IOXMLTest extends TestCase
{
    private const ESQUEMA = <<<'XSD'
        <?xml version="1.0" encoding="UTF-8"?>
        <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema">
          <xs:element name="albaran">
            <xs:complexType><xs:sequence><xs:element name="linea" type="xs:string" maxOccurs="unbounded"/></xs:sequence></xs:complexType>
          </xs:element>
        </xs:schema>
        XSD;

    private const VALIDO = '<?xml version="1.0"?><albaran><linea>lomo</linea></albaran>';
    private const NO_CUMPLE = '<?xml version="1.0"?><albaran><otra>lomo</otra></albaran>';

    private string $carpeta = '';
    /** @var array<string,mixed> */
    private array $ficherosOriginales = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once RUTA_TPVFOX . '/clases/ClaseIOXML.php';
        $this->carpeta = sys_get_temp_dir() . '/tpvfox-ioxml-' . bin2hex(random_bytes(4));
        mkdir($this->carpeta);
        $this->ficherosOriginales = $_FILES;
    }

    protected function tearDown(): void
    {
        $_FILES = $this->ficherosOriginales;
        self::borrar($this->carpeta);
        parent::tearDown();
    }

    /** @comportamiento Un fichero que no existe no se carga: la clase lo dice con una excepción que lo nombra. */
    public function test_unFicheroQueNoExisteNoSeCarga(): void
    {
        $this->expectExceptionMessage('Archivo XML no encontrado: ' . $this->carpeta . '/no_existe.xml');

        (new \ClaseIOXML($this->carpeta . '/no_existe.xml'))->cargar();
    }

    /**
     * @estado rojo
     * @codigo-afectado clases/ClaseIOXML.php:26-36
     *
     * @group esperado
     * @group intercambio
     * @group bajo
     *
     * @que-ocurre-hoy Un fichero que no es un XML bien formado termina con un error de tipo, no con la
     *   excepción de la clase que dice qué falló al leerlo; quien solo recoja excepciones no lo recoge.
     * @que-deberia-ocurrir Que la clase lo diga con su excepción de lectura, con los mensajes del
     *   analizador.
     * @por-que-ocurre La lectura devuelve false y se asigna a una propiedad que solo admite un XML o
     *   null: la asignación falla antes de llegar a la comprobación.
     * @como-deberia-funcionar Guardar la lectura en una variable, comprobarla y asignarla después.
     */
    public function test_unFicheroQueNoEsXmlSeDiceConLaExcepcionDeLectura(): void
    {
        $fichero = $this->fichero('roto.xml', '<albaran><linea></albaran>');

        $fallo = null;
        set_error_handler(static fn (): bool => true);
        try {
            (new \ClaseIOXML($fichero))->cargar();
        } catch (\Throwable $error) {
            $fallo = $error;
        } finally {
            restore_error_handler();
            libxml_clear_errors();
        }

        self::assertInstanceOf(Exception::class, $fallo, 'Tiene que ser la excepción de la clase.');
        self::assertStringStartsWith('Error al leer XML: ', $fallo->getMessage());
    }

    /** @comportamiento Un fichero válido se carga y queda en memoria; con esquema, se valida al cargarlo. */
    public function test_unFicheroValidoSeCargaYSeValidaConSuEsquema(): void
    {
        $io = new \ClaseIOXML($this->fichero('albaran.xml', self::VALIDO), $this->fichero('albaran.xsd', self::ESQUEMA));

        $xml = $io->cargar();

        self::assertSame('lomo', (string) $xml->linea);
        self::assertSame($xml, $io->getXML());
        self::assertTrue($io->validarXSD());
    }

    /** @comportamiento Un fichero que no cumple su esquema no se da por cargado: la clase lo dice con una excepción. */
    public function test_unFicheroQueNoCumpleSuEsquemaNoSeCarga(): void
    {
        $esquema = $this->fichero('albaran.xsd', self::ESQUEMA);
        $this->expectExceptionMessage('XML no cumple con el XSD: ' . $esquema);

        (new \ClaseIOXML($this->fichero('albaran.xml', self::NO_CUMPLE), $esquema))->cargar();
    }

    /**
     * @comportamiento Guardar escribe el XML en su ruta y crea la carpeta si falta. Sin XML en memoria,
     *   si no cumple el esquema o si no se puede escribir, lo dice con una excepción.
     */
    public function test_guardarEscribeElXmlSoloSiCumpleSuEsquema(): void
    {
        $esquema = $this->fichero('albaran.xsd', self::ESQUEMA);
        $destino = $this->carpeta . '/nueva/albaran.xml';
        $io = new \ClaseIOXML($destino, $esquema);

        try {
            $io->guardar();
            self::fail('Sin XML no se puede guardar');
        } catch (Exception $excepcion) {
            self::assertSame('No hay XML cargado para guardar.', $excepcion->getMessage());
        }

        try {
            $io->guardar(simplexml_load_string(self::NO_CUMPLE));
            self::fail('Un XML que no cumple su esquema no se guarda');
        } catch (Exception $excepcion) {
            self::assertFileDoesNotExist($destino);
        }

        self::assertTrue($io->guardar(simplexml_load_string(self::VALIDO)));
        self::assertXmlStringEqualsXmlFile($destino, self::VALIDO);

        $sobreUnaCarpeta = new \ClaseIOXML($this->carpeta);
        set_error_handler(static fn (): bool => true);
        try {
            $sobreUnaCarpeta->guardar(simplexml_load_string(self::VALIDO));
            self::fail('No se puede escribir sobre una carpeta');
        } catch (Exception $excepcion) {
            self::assertSame('Error al guardar XML en: ' . $this->carpeta, $excepcion->getMessage());
        } finally {
            restore_error_handler();
        }
    }

    /** @comportamiento Validar sin XML en memoria, o sin un esquema que exista, lo dice con una excepción. */
    public function test_validarExigeXmlYEsquema(): void
    {
        try {
            (new \ClaseIOXML($this->carpeta . '/a.xml', $this->carpeta . '/a.xsd'))->validarXSD();
            self::fail('Sin XML no se valida');
        } catch (Exception $excepcion) {
            self::assertSame('No hay XML cargado para validar.', $excepcion->getMessage());
        }

        $io = new \ClaseIOXML($this->carpeta . '/a.xml', $this->carpeta . '/a.xsd');
        $io->setXML(simplexml_load_string(self::VALIDO));
        $this->expectExceptionMessage('Archivo XSD no encontrado: ' . $this->carpeta . '/a.xsd');
        $io->validarXSD();
    }

    /** @comportamiento La ruta del fichero y la del esquema se pueden cambiar y consultar. */
    public function test_laRutaYElEsquemaSeCambianYSeConsultan(): void
    {
        $io = new \ClaseIOXML($this->carpeta . '/a.xml');

        self::assertNull($io->getXSD());
        $io->setRutaArchivo($this->carpeta . '/b.xml');
        $io->setXSD($this->carpeta . '/b.xsd');

        self::assertSame($this->carpeta . '/b.xml', $io->getRutaArchivo());
        self::assertSame($this->carpeta . '/b.xsd', $io->getXSD());
        self::assertNull($io->getXML());
    }

    /** @comportamiento Sin un fichero subido correctamente con ese nombre de campo, la subida lo dice con una excepción. */
    public function test_sinFicheroSubidoLaSubidaNoSeAcepta(): void
    {
        $_FILES = ['fichero' => ['error' => UPLOAD_ERR_NO_FILE]];
        $this->expectExceptionMessage("No se ha subido ningún archivo válido con el input 'fichero'.");

        \ClaseIOXML::desdeSubida('fichero', null, $this->carpeta . '/subidas/');
    }

    /**
     * @estado rojo
     * @codigo-afectado clases/ClaseIOXML.php:162-165
     *
     * @group esperado
     * @group intercambio
     * @group bajo
     *
     * @que-ocurre-hoy Si el fichero subido no se puede mover a su destino, la subida se da por buena
     *   igual: devuelve la ruta donde debería estar, y el error aparece después, al cargarlo, como un
     *   fichero que no existe.
     * @que-deberia-ocurrir Que la subida diga que no pudo guardar el fichero.
     * @por-que-ocurre No se mira el resultado de `move_uploaded_file()`.
     * @como-deberia-funcionar Comprobar ese resultado y lanzar la excepción de la clase si falla.
     */
    public function test_unaSubidaQueNoSePuedeGuardarSeDice(): void
    {
        $_FILES = ['fichero' => ['error' => UPLOAD_ERR_OK, 'tmp_name' => $this->fichero('temporal', self::VALIDO), 'name' => 'albaran.xml']];

        $fallo = null;
        try {
            \ClaseIOXML::desdeSubida('fichero', null, $this->carpeta . '/subidas/');
        } catch (Exception $excepcion) {
            $fallo = $excepcion->getMessage();
        }

        self::assertNotNull($fallo, 'La subida tiene que decir que no guardó el fichero.');
    }

    /**
     * @comportamiento La descarga escribe el XML en memoria y termina la petición; sin XML, lo dice
     *   con una excepción. La descarga se comprueba en un PHP aparte, porque termina el proceso.
     */
    public function test_laDescargaEscribeElXmlYTermina(): void
    {
        try {
            (new \ClaseIOXML($this->carpeta . '/a.xml'))->descargar();
            self::fail('Sin XML no hay descarga');
        } catch (Exception $excepcion) {
            self::assertSame('No hay XML cargado para descargar.', $excepcion->getMessage());
        }

        $fichero = $this->fichero('albaran.xml', self::VALIDO);
        $guion = 'require ' . var_export(RUTA_TPVFOX . '/clases/ClaseIOXML.php', true) . ';'
            . ' $io = new ClaseIOXML(' . var_export($fichero, true) . '); $io->cargar(); $io->descargar(); echo "después";';
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($guion), $salida);

        self::assertXmlStringEqualsXmlString(self::VALIDO, implode("\n", $salida));
        self::assertStringNotContainsString('después', implode("\n", $salida));
    }

    private function fichero(string $nombre, string $contenido): string
    {
        $ruta = $this->carpeta . '/' . $nombre;
        file_put_contents($ruta, $contenido);

        return $ruta;
    }

    private static function borrar(string $ruta): void
    {
        if (is_dir($ruta)) {
            foreach (array_diff(scandir($ruta), ['.', '..']) as $hijo) {
                self::borrar($ruta . '/' . $hijo);
            }
            rmdir($ruta);
        } elseif (is_file($ruta)) {
            unlink($ruta);
        }
    }
}
