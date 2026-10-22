<?php

/**
 * La lectura y escritura de `parametros.xml` (`controllers/parametros.php`, `ClaseParametros`): de
 * dónde se carga, cómo se le añaden los ficheros que incluye, cómo se consultan sus elementos y cómo
 * se modifican y se guardan.
 *
 * Cada caso trabaja sobre ficheros en una carpeta temporal propia: la clase crea su carpeta `cache/`
 * junto al fichero que lee, y ahí deja lo que guarda.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\Nucleo;

use PHPUnit\Framework\TestCase;

final class ParametrosTest extends TestCase
{
    private const PARAMETROS = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <parametros>
          <datos_generales>
            <nombre>Prueba</nombre>
            <version>1.0</version>
          </datos_generales>
          <configuracion>
            <columna nombre="id" ancho="10">Identificador</columna>
            <columna nombre="nombre" ancho="30">Nombre</columna>
            <filtro>activo</filtro>
            <orden>nombre</orden>
            <orden>precio</orden>
            <vista tipo="lista">Lista</vista>
          </configuracion>
          <seccion><grupo><clave>  valor interno  </clave></grupo></seccion>
        </parametros>
        XML;

    private string $carpeta = '';

    protected function setUp(): void
    {
        parent::setUp();
        require_once RUTA_TPVFOX . '/controllers/parametros.php';
        $this->carpeta = sys_get_temp_dir() . '/tpvfox-parametros-' . bin2hex(random_bytes(4));
        mkdir($this->carpeta);
    }

    protected function tearDown(): void
    {
        self::borrar($this->carpeta);
        parent::tearDown();
    }

    /** @comportamiento Carga el fichero que se le da, y crea junto a él la carpeta `cache/` si no existe. */
    public function test_cargaElFicheroYCreaSuCarpetaCache(): void
    {
        $fichero = $this->fichero('parametros.xml', self::PARAMETROS);

        $parametros = new \ClaseParametros($fichero);

        self::assertSame([$fichero], $parametros->getFicheros());
        self::assertSame('parametros', $parametros->getRoot()->getName());
        self::assertDirectoryExists($this->carpeta . '/cache');
    }

    /** @comportamiento Si hay una copia en `cache/`, se carga esa en lugar del fichero pedido. */
    public function test_laCopiaDeCacheMandaSobreElFichero(): void
    {
        $fichero = $this->fichero('parametros.xml', self::PARAMETROS);
        mkdir($this->carpeta . '/cache');
        $copia = $this->fichero('cache/parametros.xml', str_replace('<nombre>Prueba</nombre>', '<nombre>Guardada</nombre>', self::PARAMETROS));

        $parametros = new \ClaseParametros($fichero);

        self::assertSame([$copia], $parametros->getFicheros());
        self::assertSame(['Guardada'], $parametros->Xpath('datos_generales/nombre', 'Valores'));
    }

    /** @comportamiento Los ficheros de `<includes>` se añaden al árbol como hijos de la raíz. */
    public function test_losFicherosIncluidosSeAnadenAlArbol(): void
    {
        $incluido = $this->fichero('incluido.xml', '<?xml version="1.0"?><extra><dato>x</dato></extra>');
        $fichero = $this->fichero('parametros.xml', str_replace('</parametros>', "<includes><fichero>$incluido</fichero></includes></parametros>", self::PARAMETROS));

        $parametros = new \ClaseParametros($fichero);

        self::assertSame([$fichero, $incluido], $parametros->getFicheros());
        self::assertSame(['x'], $parametros->Xpath('extra/dato', 'Valores'));
    }

    /** @comportamiento Una ruta devuelve los elementos o sus valores, recortados. */
    public function test_unaRutaDevuelveLosElementosOSusValores(): void
    {
        $parametros = new \ClaseParametros($this->fichero('parametros.xml', self::PARAMETROS));

        self::assertCount(2, $parametros->Xpath('configuracion/columna'));
        self::assertSame(['Identificador', 'Nombre'], $parametros->Xpath('configuracion/columna', 'Valores'));
        self::assertSame(['valor interno'], $parametros->Xpath('seccion/grupo/clave', 'Valores'));
        self::assertSame([], $parametros->Xpath('no_existe', 'Valores'));
    }

    /**
     * @comportamiento Un grupo se convierte en un array: el elemento único sin atributos, en su texto;
     *   con atributos, en un objeto con ellos y su valor; los repetidos, en una lista de esos objetos o
     *   de su valor.
     */
    public function test_unGrupoSeConvierteEnUnArray(): void
    {
        $parametros = new \ClaseParametros($this->fichero('parametros.xml', self::PARAMETROS));

        self::assertSame(['nombre' => 'Prueba', 'version' => '1.0'], $parametros->ArrayElementos('datos_generales'));
        $configuracion = json_decode(json_encode($parametros->ArrayElementos('configuracion')), true);
        self::assertSame(
            [
                'columna' => [
                    ['nombre' => 'id', 'ancho' => '10', 'valor' => 'Identificador'],
                    ['nombre' => 'nombre', 'ancho' => '30', 'valor' => 'Nombre'],
                ],
                'filtro' => 'activo',
                'orden' => [['valor' => 'nombre'], ['valor' => 'precio']],
                'vista' => ['tipo' => 'lista', 'valor' => 'Lista'],
            ],
            $configuracion
        );
    }

    /** @comportamiento Un nodo se busca por ruta, por nombre en todo el árbol o dentro de una sección; sin él, null. */
    public function test_unNodoSeBuscaPorRutaNombreOSeccion(): void
    {
        $parametros = new \ClaseParametros($this->fichero('parametros.xml', self::PARAMETROS));

        self::assertSame('Prueba', (string) $parametros->getNode('/parametros/datos_generales/nombre'));
        self::assertSame('activo', (string) $parametros->getNodeIntern('filtro'));
        self::assertSame('  valor interno  ', (string) $parametros->getNodeInternBySection('seccion', 'clave'));
        self::assertNull($parametros->getNode('/parametros/no_existe'));
        self::assertNull($parametros->getNodeIntern('no_existe'));
        self::assertNull($parametros->getNodeInternBySection('seccion', 'no_existe'));
    }

    /**
     * @comportamiento Se cambia el valor o un atributo de un nodo, o se quita, y guardar escribe el
     *   árbol en `cache/`, que es lo que se carga la próxima vez. Sin el nodo, nada cambia y se
     *   devuelve false.
     */
    public function test_losCambiosSeGuardanEnCacheYSeCarganLaProximaVez(): void
    {
        $fichero = $this->fichero('parametros.xml', self::PARAMETROS);
        $parametros = new \ClaseParametros($fichero);

        self::assertTrue($parametros->setNodeValue('/parametros/configuracion/filtro', 'todos'));
        self::assertTrue($parametros->setNodeAttribute('/parametros/configuracion/vista', 'tipo', 'tabla'));
        self::assertTrue($parametros->removeNode('/parametros/seccion'));
        self::assertFalse($parametros->setNodeValue('/parametros/no_existe', 'x'));
        self::assertFalse($parametros->setNodeAttribute('/parametros/no_existe', 'a', 'x'));
        self::assertFalse($parametros->removeNode('/parametros/no_existe'));
        self::assertTrue($parametros->save());

        $releidos = new \ClaseParametros($fichero);
        self::assertSame([$this->carpeta . '/cache/parametros.xml'], $releidos->getFicheros());
        self::assertSame(['todos'], $releidos->Xpath('configuracion/filtro', 'Valores'));
        self::assertSame('tabla', (string) $releidos->getNodeIntern('vista')['tipo']);
        self::assertNull($releidos->getNodeIntern('seccion'));
        self::assertStringEqualsFile($fichero, self::PARAMETROS, 'El fichero original no se toca.');
    }

    /** @comportamiento Se puede sustituir el árbol en memoria por otro. */
    public function test_seSustituyeElArbolEnMemoria(): void
    {
        $parametros = new \ClaseParametros($this->fichero('parametros.xml', self::PARAMETROS));

        $parametros->setRoot(simplexml_load_string('<otro><a>1</a></otro>'));

        self::assertSame('otro', $parametros->getRoot()->getName());
        self::assertSame(['1'], $parametros->Xpath('a', 'Valores'));
    }

    /**
     * @comportamiento Pedir los resultados de una ruta con un tipo de respuesta desconocido avisa y
     *   termina la petición. Se comprueba en un PHP aparte.
     */
    public function test_unTipoDeRespuestaDesconocidoAvisaYTermina(): void
    {
        $fichero = $this->fichero('parametros.xml', self::PARAMETROS);

        $salida = self::ejecutar('$p = new ClaseParametros(' . var_export($fichero, true) . '); $p->Xpath("datos_generales", "Otro"); echo "después";');

        self::assertSame(' Error tipo_respuesta a devolver', $salida);
    }

    /**
     * @estado rojo
     * @codigo-afectado controllers/parametros.php:66-71
     *
     * @group esperado
     * @group parametros
     * @group bajo
     *
     * @que-ocurre-hoy Un `parametros.xml` mal formado termina la petición con un error fatal de tipo,
     *   sin el aviso que la clase prevé con el nombre del fichero.
     * @que-deberia-ocurrir Que avise de qué fichero no se pudo cargar.
     * @por-que-ocurre El fichero se convierte a DOM antes de comprobar que se cargó; con un fichero
     *   mal formado la carga devuelve false, y convertir false es un error de tipo.
     * @como-deberia-funcionar Comprobar la carga antes de convertirla.
     */
    public function test_unFicheroMalFormadoSeAvisaConSuNombre(): void
    {
        $fichero = $this->fichero('parametros.xml', '<parametros><sin_cerrar></parametros>');

        $salida = self::ejecutar('new ClaseParametros(' . var_export($fichero, true) . ');');

        self::assertStringContainsString('Error en carga de fichero ' . $fichero, $salida);
    }

    private function fichero(string $nombre, string $contenido): string
    {
        $ruta = $this->carpeta . '/' . $nombre;
        file_put_contents($ruta, $contenido);

        return $ruta;
    }

    /** Ejecuta el guion en un PHP aparte, con la clase cargada, y devuelve lo que escribe. */
    private static function ejecutar(string $guion): string
    {
        $codigo = 'require ' . var_export(RUTA_TPVFOX . '/controllers/parametros.php', true) . '; ' . $guion;
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d error_reporting=0 -r ' . escapeshellarg($codigo) . ' 2>/dev/null', $salida);

        return implode("\n", $salida);
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
