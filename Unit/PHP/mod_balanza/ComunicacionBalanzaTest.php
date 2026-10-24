<?php

/**
 * La traducción a la balanza (`modulos/mod_balanza/clases/ClaseComunicacionBalanza.php`): los registros
 * del precio (H2), del tipo, el IVA y la sección (H3) y de la sección (DS); la carpeta de cada balanza,
 * con su fichero de configuración; y la lectura del registro que deja el driver.
 *
 * La clase no consulta la base. La carpeta de la balanza es una del caso, que se borra al terminar, y
 * nunca tiene driver: ninguna prueba lo ejecuta ni busca sus procesos. Los avisos que la clase escribe
 * en el registro de errores de PHP se descartan.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModBalanza;

use PHPUnit\Framework\TestCase;

final class ComunicacionBalanzaTest extends TestCase
{
    private string $carpeta;

    private string|false $registroErrores;

    protected function setUp(): void
    {
        parent::setUp();
        require_once RUTA_TPVFOX . '/modulos/mod_balanza/clases/ClaseComunicacionBalanza.php';
        $this->carpeta = sys_get_temp_dir() . '/balanza-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $this->registroErrores = ini_set('error_log', '/dev/null');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->registroErrores);
        if (is_dir($this->carpeta)) {
            $ficheros = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->carpeta, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($ficheros as $fichero) {
                $fichero->isDir() ? rmdir($fichero->getPathname()) : unlink($fichero->getPathname());
            }
            rmdir($this->carpeta);
        }
        parent::tearDown();
    }

    /** @param array<string, mixed> $datos */
    private function registroH2(array $datos, string $modo = 'H'): string
    {
        $traductor = new \ClaseComunicacionBalanza();
        $traductor->setModoComunicacion($modo);
        $traductor->setH2Data($datos);

        return $traductor->traducirH2();
    }

    /** @param array<string, mixed> $datos */
    private function registroH3(array $datos): string
    {
        $traductor = new \ClaseComunicacionBalanza();
        $traductor->setH3Data($datos);

        return $traductor->traducirH3();
    }

    /** El campo del precio del registro H2 en modo H: tras el nombre y dos ceros. */
    private function precioDe(string $registroH2): string
    {
        return substr($registroH2, 78, 6);
    }

    /** El campo del IVA del registro H3: tras la sección. */
    private function ivaDe(string $registroH3): string
    {
        return substr($registroH3, 49, 2);
    }

    /**
     * @comportamiento El registro del precio, en modo H, lleva el grupo, la dirección, el código, la tecla,
     *   el nombre en 60 caracteres, el precio en céntimos y los precios de oferta y de coste, y termina con
     *   los 20 caracteres que no se transmiten.
     */
    public function test_elRegistroDelPrecioLlevaSusCamposEnSuSitio(): void
    {
        $traductor = new \ClaseComunicacionBalanza();
        $traductor->setGrupo(3);
        $traductor->setDireccion(12);
        $traductor->setH2Data(['codigo' => '1001', 'nombre' => 'Pera conferencia', 'precio' => 12.5, 'PLU' => '7', 'precioOferta' => 10.0, 'precioCoste' => 6.0]);

        $registro = $traductor->traducirH2();

        self::assertStringStartsWith('03H212A001001007Pera conferencia ', $registro);
        self::assertSame(str_pad('Pera conferencia', 60), substr($registro, 16, 60));
        self::assertSame('00001250' . '00001000' . '00000600' . str_repeat('0', 30) . str_repeat('-', 20) . "\n", substr($registro, 76));
        self::assertSame([], $traductor->getAlertas());
    }

    /** @comportamiento En modo L, el nombre lleva 12 espacios más y el final 18 ceros en lugar de 30: el registro mide lo mismo. */
    public function test_enModoLElRegistroDelPrecioCambiaElRellenoYNoLaLongitud(): void
    {
        $modoH = $this->registroH2(['codigo' => '1001', 'nombre' => 'Pera', 'precio' => 2.0]);
        $modoL = $this->registroH2(['codigo' => '1001', 'nombre' => 'Pera', 'precio' => 2.0], 'L');

        self::assertSame('00L200A001001000', substr($modoL, 0, 16));
        self::assertSame(str_pad('Pera', 72), substr($modoL, 16, 72));
        self::assertSame('00000200', substr($modoL, 88, 8));
        self::assertSame(strlen($modoH), strlen($modoL));
    }

    /** @comportamiento Un modo que no es H ni L se rechaza. */
    public function test_unModoQueNoEsHNiLSeRechaza(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new \ClaseComunicacionBalanza())->setModoComunicacion('X');
    }

    /**
     * @comportamiento El nombre llega sin acentos ni símbolos, con la eñe como NH y sin espacios de más; si
     *   pasa de 60 caracteres, se recorta y se avisa.
     */
    public function test_elNombreSeLimpiaYSeRecortaA60(): void
    {
        $limpio = $this->registroH2(['codigo' => '1', 'nombre' => "  Jamón  ibérico «extra»\tAÑO ü  ", 'precio' => 1.0]);
        $traductor = new \ClaseComunicacionBalanza();
        $traductor->setH2Data(['codigo' => '1', 'nombre' => str_repeat('Lomo ', 14), 'precio' => 1.0]);
        $largo = $traductor->traducirH2();

        self::assertSame(str_pad('Jamon iberico extra ANHO u', 60), substr($limpio, 16, 60));
        self::assertSame(substr(str_repeat('Lomo ', 14), 0, 60), substr($largo, 16, 60));
        self::assertCount(1, $traductor->getAlertas());
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_balanza/clases/ClaseComunicacionBalanza.php:105-108
     *
     * @group defecto
     * @group traduccion
     * @group alto
     *
     * @que-ocurre-hoy Precios como 1,15 €, 2,30 € o 4,35 € llegan a la balanza con un céntimo menos: 114,
     *   229 y 434 céntimos.
     * @que-deberia-ocurrir Que llegue exactamente el precio del producto.
     * @por-que-ocurre El precio se multiplica por 100 en coma flotante y se trunca: 1,15 × 100 es
     *   114,999…, y el entero que queda es 114.
     * @como-deberia-funcionar Redondear los céntimos, no truncarlos.
     */
    public function test_elPrecioLlegaEnCentimosSinPerderNinguno(): void
    {
        $precios = [];
        foreach ([1.15, '2.30', 4.35] as $precio) {
            $precios[] = $this->precioDe($this->registroH2(['codigo' => '1', 'nombre' => 'Pera', 'precio' => $precio]));
        }

        self::assertSame(['000115', '000230', '000435'], $precios);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_balanza/clases/ClaseComunicacionBalanza.php:238, :561-568
     *
     * @group defecto
     * @group traduccion
     * @group medio
     *
     * @que-ocurre-hoy Un código de más de seis cifras se recorta por la derecha y se envía: 1234567 y
     *   1234568 llegan los dos como 123456, el mismo artículo para la balanza. Solo queda un aviso que nadie
     *   lee.
     * @que-deberia-ocurrir Que un código que no cabe se rechace, y no se envíe otro.
     * @por-que-ocurre El campo se ajusta a su ancho cortando lo que sobra.
     * @como-deberia-funcionar Rechazar el registro de un código que no cabe.
     */
    public function test_unCodigoQueNoCabeSeRechaza(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->registroH2(['codigo' => '1234567', 'nombre' => 'Pera', 'precio' => 1.0]);
    }

    /** @comportamiento Sin código, nombre y precio, el registro del precio se rechaza; con precio cero, también. */
    public function test_elRegistroDelPrecioExigeSusDatos(): void
    {
        $rechazos = 0;
        foreach ([['codigo' => '1', 'nombre' => 'Pera'], ['codigo' => '1', 'nombre' => 'Pera', 'precio' => 0]] as $datos) {
            try {
                $this->registroH2($datos);
            } catch (\InvalidArgumentException) {
                $rechazos++;
            }
        }

        self::assertSame(2, $rechazos);
    }

    /**
     * @comportamiento El registro del tipo lleva el código, el tipo —0 al peso, 1 por unidad—, la sección
     *   en dos cifras y el IVA codificado: 01 el 4 %, 02 el 10 % y 03 el 21 %.
     */
    public function test_elRegistroDelTipoCodificaElTipoLaSeccionYElIva(): void
    {
        $peso = $this->registroH3(['codigo' => '1001', 'tipoProducto' => 'peso', 'iva' => '4.00', 'seccion' => '3']);
        $unidad = $this->registroH3(['codigo' => '1001', 'tipoProducto' => 'unidad', 'iva' => '10.00']);
        $general = $this->registroH3(['codigo' => '1001', 'tipoProducto' => 'peso', 'iva' => 21]);

        self::assertStringStartsWith('00H300001001' . '00', $peso);
        self::assertSame('10', substr($unidad, 12, 2));
        self::assertSame('03', substr($peso, 47, 2));
        self::assertSame(['01', '02', '03'], [$this->ivaDe($peso), $this->ivaDe($unidad), $this->ivaDe($general)]);
        self::assertSame(strlen($peso), strlen($unidad));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_balanza/clases/ClaseComunicacionBalanza.php:274-276, :308-310
     *
     * @group defecto
     * @group traduccion
     * @group alto
     *
     * @que-ocurre-hoy Un producto con IVA cero no se puede enviar: el registro del tipo se rechaza con una
     *   excepción, aunque la traducción tiene el código del IVA cero.
     * @que-deberia-ocurrir Que el IVA cero llegue como 00.
     * @por-que-ocurre La comprobación de los datos obligatorios toma el cero como falta de IVA.
     * @como-deberia-funcionar Exigir que el IVA esté puesto, no que sea distinto de cero.
     */
    public function test_unProductoConIvaCeroSeTraduce(): void
    {
        $registro = $this->registroH3(['codigo' => '1001', 'tipoProducto' => 'peso', 'iva' => '0.00']);

        self::assertSame('00', $this->ivaDe($registro));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_balanza/clases/ClaseComunicacionBalanza.php:320-322
     *
     * @group defecto
     * @group traduccion
     * @group medio
     *
     * @que-ocurre-hoy Un IVA que no es el 0, el 4, el 10 ni el 21 % —el 5 % o el 2 %, por ejemplo— llega a
     *   la balanza como el 21 %.
     * @que-deberia-ocurrir Que un IVA que la traducción no conoce se rechace, y no se envíe otro.
     * @por-que-ocurre Cualquier otro valor cae en el caso por defecto, que pone el código del 21 %.
     * @como-deberia-funcionar Rechazar el registro con un IVA sin código.
     */
    public function test_unIvaSinCodigoSeRechaza(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->registroH3(['codigo' => '1001', 'tipoProducto' => 'peso', 'iva' => '5.00']);
    }

    /** @comportamiento Sin código, tipo e IVA, el registro del tipo se rechaza. */
    public function test_elRegistroDelTipoExigeSusDatos(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->registroH3(['codigo' => '1001', 'tipoProducto' => 'peso']);
    }

    /** @comportamiento El registro de la sección lleva su número y su nombre en 20 caracteres, recortado con aviso si no cabe. */
    public function test_elRegistroDeLaSeccionLlevaSuNumeroYSuNombre(): void
    {
        $traductor = new \ClaseComunicacionBalanza();
        $traductor->setDSData(['seccion' => 2, 'nombre' => 'Carnicería']);
        $corto = $traductor->traducirDS();
        $traductor->setDSData(['seccion' => 2, 'nombre' => 'Frutería y verdulería del mercado', 'PLU' => '5']);
        $largo = $traductor->traducirDS();

        self::assertStringStartsWith('00DS0002' . str_pad('Carniceria', 20) . '000000000 0000', $corto);
        self::assertSame('Fruteria y verduleri', substr($largo, 8, 20));
        self::assertSame('05', substr($largo, 42, 2));
        self::assertCount(1, $traductor->getAlertas());
    }

    /**
     * @comportamiento Crear la carpeta de una balanza deja su fichero de configuración —con la carpeta, la
     *   IP del PC, el grupo y la IP de la balanza, su dirección y su serie—, el fichero de envío y el
     *   registro del driver.
     */
    public function test_crearLaCarpetaDejaLaConfiguracionYLosFicheros(): void
    {
        $traductor = new \ClaseComunicacionBalanza();
        $traductor->setRutaBalanza($this->carpeta . '/Mostrador1');

        $traductor->crearDirectorioBalanza(['ipPc' => '192.168.1.10', 'grupoBalanza' => '01', 'ipBalanza' => '192.168.1.50', 'direccionBalanza' => '02', 'serieTipo' => '1', 'serieH' => '0']);

        $configuracion = (string) file_get_contents($this->carpeta . '/Mostrador1/balctrol');
        self::assertStringContainsString('DT = ' . $this->carpeta . '/Mostrador1/', $configuracion);
        self::assertStringContainsString('DI = 192.168.1.10', $configuracion);
        self::assertStringContainsString('BD = 01 192.168.1.50 3000', $configuracion);
        self::assertStringContainsString('BL = 02 1', $configuracion);
        self::assertStringContainsString('BH = 02 0', $configuracion);
        self::assertStringContainsString("CAPTURAR SE\xD1AL SIGINT", $configuracion);
        self::assertFileExists($this->carpeta . '/Mostrador1/filetx');
        self::assertFileExists($this->carpeta . '/Mostrador1/logs/BalttyEstadoBalanzas.log');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_balanza/clases/ClaseComunicacionBalanza.php:617
     *
     * @group defecto
     * @group carpeta
     * @group bajo
     *
     * @que-ocurre-hoy Crear la carpeta de una balanza responde «No hay cambios para actualizar», aunque la
     *   acabe de crear.
     * @que-deberia-ocurrir Que diga lo que hizo.
     * @por-que-ocurre La respuesta es un texto fijo.
     * @como-deberia-funcionar Responder con lo creado, o con el error.
     */
    public function test_crearLaCarpetaDiceQueLaCreo(): void
    {
        $traductor = new \ClaseComunicacionBalanza();
        $traductor->setRutaBalanza($this->carpeta . '/Mostrador1');

        $respuesta = $traductor->crearDirectorioBalanza(['ipPc' => '', 'grupoBalanza' => '', 'ipBalanza' => '', 'direccionBalanza' => '', 'serieTipo' => '', 'serieH' => '']);

        self::assertDirectoryExists($this->carpeta . '/Mostrador1');
        self::assertNotSame('No hay cambios para actualizar', $respuesta['mensaje']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_balanza/clases/ClaseComunicacionBalanza.php:604-619; modulos/mod_balanza/balanza.php:191
     *
     * @group defecto
     * @group carpeta
     * @group bajo
     *
     * @que-ocurre-hoy La ficha ofrece crear la carpeta en modo «automatico», y en ese modo no se crea nada
     *   ni se responde nada.
     * @que-deberia-ocurrir Que un modo que no se sabe crear no se ofrezca, o que se responda que no se creó.
     * @por-que-ocurre La creación solo tiene el caso del modo «Balctrol».
     * @como-deberia-funcionar Responder con un error a un modo sin creación.
     */
    public function test_unModoSinCreacionLoDice(): void
    {
        $traductor = new \ClaseComunicacionBalanza();
        $traductor->setRutaBalanza($this->carpeta . '/Mostrador1');

        $respuesta = $traductor->crearDirectorioBalanza(['modoDirectorio' => 'automatico']);

        self::assertDirectoryDoesNotExist($this->carpeta . '/Mostrador1');
        self::assertIsArray($respuesta);
    }

    /**
     * @comportamiento El estado de la balanza se lee del registro del driver: «Balanza OK» es que respondió;
     *   «La balanza rechaza la comunicación», también en Latin-1, que no; sin registro, tampoco.
     */
    public function test_elEstadoSeLeeDelRegistroDelDriver(): void
    {
        $traductor = new \ClaseComunicacionBalanza();
        $traductor->setRutaBalanza($this->carpeta);
        $sinRegistro = $traductor->verificarEstadoBalanza();
        mkdir($this->carpeta . '/logs', 0755, true);
        $registro = $this->carpeta . '/logs/BalttyEstadoBalanzas.log';

        file_put_contents($registro, "Conectando\nBalanza OK\n");
        $correcta = $traductor->verificarEstadoBalanza();
        file_put_contents($registro, mb_convert_encoding("La balanza rechaza la comunicación\n", 'ISO-8859-1', 'UTF-8'));
        $rechazada = $traductor->verificarEstadoBalanza();
        file_put_contents($registro, "Conectando\n");
        $sinEstado = $traductor->verificarEstadoBalanza();

        self::assertSame([false, true, false, false], [$sinRegistro, $correcta, $rechazada, $sinEstado]);
        self::assertContains('La balanza rechaza la comunicación.', $traductor->getAlertas());
    }

    /** @comportamiento Sin driver en la carpeta de la balanza, ni se ejecuta ni se buscan sus procesos: se responde que no se pudo. */
    public function test_sinDriverNoSeEjecutaNada(): void
    {
        $traductor = new \ClaseComunicacionBalanza();
        $traductor->setRutaBalanza($this->carpeta);
        $sinCarpeta = $traductor->ejecutarDriverBalanza();
        mkdir($this->carpeta . '/logs', 0755, true);

        $sinDriver = $traductor->ejecutarDriverBalanza();
        $sinReiniciar = $traductor->reiniciarBalanzaEnEjecucion();

        self::assertSame([false, false, false], [$sinCarpeta, $sinDriver, $sinReiniciar]);
        self::assertFileDoesNotExist($this->carpeta . '/logs/BalttyEstadoBalanzas.log');
        self::assertContains('No se puede ejecutar baltty porque no está instalado.', $traductor->getAlertas());
    }
}
