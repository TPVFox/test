<?php

/**
 * Lo que el controlador común de TPVFox (`controllers/Controladores.php`) compone sin tocar la base:
 * la condición de una búsqueda por palabras, las variables de JavaScript de las cajas de una
 * pantalla, una celda de tabla, el aviso de las conexiones y el enlace «Volver».
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\Nucleo;

use PHPUnit\Framework\TestCase;

final class ControladorComunTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $servidorOriginal = [];
    /** @var array<string,mixed> */
    private array $getOriginal = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once RUTA_TPVFOX . '/controllers/Controladores.php';
        $this->servidorOriginal = $_SERVER;
        $this->getOriginal = $_GET;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->servidorOriginal;
        $_GET = $this->getOriginal;
        parent::tearDown();
    }

    /**
     * @comportamiento La búsqueda por palabras pone un LIKE por cada palabra y cada campo, unidos por
     *   el operador pedido, con `?` en lugar de cada valor y los valores aparte. Separa comas, paréntesis
     *   y guiones, y quita las comillas dobles.
     */
    public function test_laBusquedaPorPalabrasPoneUnLikePorPalabraYCampo(): void
    {
        $controlador = new \ControladorComun();

        self::assertSame('a.nombre LIKE ? AND a.ref LIKE ? AND a.nombre LIKE ? AND a.ref LIKE ?', $controlador->ConstructorLike(['a.nombre', 'a.ref'], ' lomo  cerdo '));
        self::assertSame(['%lomo%', '%lomo%', '%cerdo%', '%cerdo%'], $controlador->GetConstructorParams());
        self::assertSame('n LIKE ? OR n LIKE ? OR n LIKE ? OR n LIKE ?', $controlador->ConstructorLike(['n'], 'lomo,cerdo "ibérico"', 'OR'));
        self::assertSame(['%lomo%', '%,%', '%cerdo%', '%ibérico%'], $controlador->GetConstructorParams());
    }

    /**
     * @comportamiento Las palabras de una o dos letras también entran en la búsqueda, aunque el
     *   comentario del código diga que solo entran las de más de tres.
     */
    public function test_lasPalabrasCortasTambienEntranEnLaBusqueda(): void
    {
        $controlador = new \ControladorComun();

        self::assertSame('n LIKE ? AND n LIKE ?', $controlador->ConstructorLike(['n'], 'a de'));
        self::assertSame(['%a%', '%de%'], $controlador->GetConstructorParams());
    }

    /**
     * @comportamiento Por cada caja de `parametros.xml` se declara una variable de JavaScript con su
     *   identificador, las acciones de sus teclas, sus parámetros y los estados de antes y después de
     *   construirla.
     */
    public function test_cadaCajaDeParametrosDeclaraSuVariableDeJavascript(): void
    {
        $parametros = simplexml_load_string(
            '<parametros><cajas_input>'
            . '<caja_input><nombre id_input="Codbarras">cajaCodBarras</nombre>'
            . '<teclas><action tecla="13">buscarProducto</action><action tecla="40">bajar</action></teclas>'
            . '<parametros><parametro nombre="dedonde">tpv</parametro></parametros>'
            . '<before><estado>Si</estado></before><after><estado>No</estado></after></caja_input>'
            . '<caja_input><nombre id_input="Cantidad">cajaCantidad</nombre></caja_input>'
            . '</cajas_input></parametros>'
        );

        $js = (new \ControladorComun())->ObtenerCajasInputParametros($parametros);

        self::assertStringContainsString(
            "var cajaCodBarras = {after_constructor: 'No',id_input :'Codbarras', acciones:{13:'buscarProducto',40:'bajar'}, parametros:{dedonde:'tpv'}, before_constructor: 'Si'};",
            $js
        );
        self::assertStringContainsString("var cajaCantidad = {id_input :'Cantidad'};", $js);
    }

    /** @comportamiento Una celda vacía sale sin contenido; con contenido, también el «0». */
    public function test_unaCeldaLlevaSuContenidoOVaVacia(): void
    {
        self::assertSame('<td></td>', \ControladorComun::insertTd());
        self::assertSame('<td>0</td>', \ControladorComun::insertTd('0'));
        self::assertSame('<td>lomo</td>', \ControladorComun::insertTd('lomo'));
    }

    /** @comportamiento El aviso de conexiones nombra cada base cuya conexión es «Error», y nada si no hay ninguna. */
    public function test_elAvisoDeConexionesNombraLasBasesConError(): void
    {
        $controlador = new \ControladorComun();

        self::assertSame('', $controlador->VerConexiones([['conexion' => 'Correcto', 'NombreBD' => 'tpv']]));
        self::assertSame(
            'Error de conexion en la BD web<br/>¡¡Revisa configuracion! <br/>',
            $controlador->VerConexiones([['conexion' => 'Correcto', 'NombreBD' => 'tpv'], ['conexion' => 'Error', 'NombreBD' => 'web']])
        );
    }

    /** @comportamiento El nombre de un fichero es lo que va entre la última barra y el primer punto. */
    public function test_elNombreDeUnFicheroVaSinRutaNiExtension(): void
    {
        self::assertSame('ListaProductos', (new \ControladorComun())->getNombreFichero('/tpvfox/modulos/mod_producto/ListaProductos.php'));
    }

    /**
     * @comportamiento «Volver» enlaza la página de la que se viene, con sus parámetros, y se rotula
     *   con su nombre o con el texto pedido. La dirección actual se reescribe en el historial con sus
     *   parámetros.
     */
    public function test_volverEnlazaLaPaginaDeLaQueSeViene(): void
    {
        $_SERVER['REQUEST_URI'] = '/tpvfox/modulos/mod_producto/producto.php?id=7';
        $_SERVER['HTTP_REFERER'] = 'http://localhost/tpvfox/modulos/mod_producto/ListaProductos.php?pagina=3';
        $_GET = ['id' => '7'];
        $controlador = new \ControladorComun();

        self::assertSame(
            "<script>history.replaceState(null,'','/tpvfox/modulos/mod_producto/producto.php?id=7');</script>"
            . '<a class="glyphicon glyphicon-circle-arrow-left" href="/tpvfox/modulos/mod_producto/ListaProductos.php?pagina=3">Volver a:ListaProductos</a>',
            $controlador->getHtmlLinkVolver()
        );
        self::assertStringEndsWith('>Al listado</a>', $controlador->getHtmlLinkVolver('Al listado'));
    }

    /**
     * @comportamiento Sin página de origen, «Volver» lleva a la carpeta de la pantalla actual. Si se
     *   viene de la misma pantalla, retrocede en el historial tantas páginas como recargas, contadas
     *   en el parámetro `historyJS`.
     */
    public function test_volverSinOrigenOTrasRecargarLaMismaPantalla(): void
    {
        $_SERVER['REQUEST_URI'] = '/tpvfox/modulos/mod_producto/producto.php';
        unset($_SERVER['HTTP_REFERER']);
        $_GET = [];
        $sinOrigen = (new \ControladorComun())->getHtmlLinkVolver();

        $_SERVER['HTTP_REFERER'] = 'http://localhost/tpvfox/modulos/mod_producto/producto.php';
        $_GET = [];
        $recargada = (new \ControladorComun())->getHtmlLinkVolver();

        self::assertStringContainsString('href="/tpvfox/modulos/mod_producto">Volver a:mod_producto</a>', $sinOrigen);
        self::assertStringContainsString("producto.php?historyJS=2');</script>", $recargada);
        self::assertStringContainsString('onClick="history.go(-2);"', $recargada);

        $_GET = ['historyJS' => '2'];
        $otraVez = (new \ControladorComun())->getHtmlLinkVolver();
        self::assertStringContainsString('onClick="history.go(-3);"', $otraVez, 'Cada recarga suma una página más');
    }

    /**
     * @estado rojo
     * @codigo-afectado controllers/Controladores.php:319-327
     *
     * @group esperado
     * @group pantalla
     * @group alto
     *
     * @que-ocurre-hoy Un parámetro de la dirección con una etiqueta de script se escribe tal cual
     *   dentro del script de «Volver»: quien siga un enlace preparado ejecuta, con su sesión, el
     *   código que el enlace traiga.
     * @que-deberia-ocurrir Que los parámetros de la dirección se escriban escapados, como texto.
     * @por-que-ocurre La dirección actual se recompone con los valores de `$_GET` pegados, y se
     *   inserta en un script sin escapar.
     * @como-deberia-funcionar Componer la dirección con `http_build_query()` y escaparla para
     *   JavaScript y para HTML antes de insertarla.
     */
    public function test_unParametroConUnScriptNoSeEjecutaEnVolver(): void
    {
        $_SERVER['REQUEST_URI'] = '/tpvfox/modulos/mod_producto/producto.php';
        $_SERVER['HTTP_REFERER'] = 'http://localhost/tpvfox/modulos/mod_producto/ListaProductos.php';
        $_GET = ['id' => '</script><script>alert(document.cookie)</script>'];

        $html = (new \ControladorComun())->getHtmlLinkVolver();

        self::assertStringNotContainsString('<script>alert(document.cookie)</script>', $html, 'El parámetro no puede salir como script.');
    }

    /**
     * @estado rojo
     * @codigo-afectado controllers/Controladores.php:329
     *
     * @group esperado
     * @group pantalla
     * @group alto
     *
     * @que-ocurre-hoy El contador de recargas de la dirección se escribe tal cual dentro del
     *   `onClick` del enlace «Volver»: un enlace preparado con código en el contador lo ejecuta, con
     *   la sesión de quien lo abre, al pulsar «Volver».
     * @que-deberia-ocurrir Que el contador solo pueda ser un número.
     * @por-que-ocurre Si la pantalla no se ha recargado, el contador se toma de la dirección sin
     *   tratarlo y se pega en el atributo.
     * @como-deberia-funcionar Tomar el contador como un entero positivo.
     */
    public function test_unContadorConCodigoNoSeEjecutaEnVolver(): void
    {
        $_SERVER['REQUEST_URI'] = '/tpvfox/modulos/mod_producto/producto.php';
        $_SERVER['HTTP_REFERER'] = 'http://localhost/tpvfox/modulos/mod_producto/ListaProductos.php';
        $_GET = ['historyJS' => '1);alert(document.cookie);//'];

        $html = (new \ControladorComun())->getHtmlLinkVolver();

        self::assertStringNotContainsString('alert(document.cookie)', $html, 'El contador no puede llevar código al enlace.');
    }

    /**
     * @estado rojo
     * @codigo-afectado controllers/Controladores.php:303-314
     * @codigo-afectado controllers/Controladores.php:328-336
     *
     * @group esperado
     * @group pantalla
     * @group bajo
     *
     * @que-ocurre-hoy Tras recargar una pantalla, su dirección se queda con el contador de recargas.
     *   Si esa dirección se abre después desde otra página, o en una pestaña nueva, «Volver»
     *   retrocede en el historial las páginas que dice el contador en lugar de ir a la página de la
     *   que se viene, o a la carpeta del módulo si no hay origen.
     * @que-deberia-ocurrir Que el contador solo cuente si se viene de la misma pantalla.
     * @por-que-ocurre El enlace usa el contador siempre que la dirección lo traiga, sin mirar de
     *   dónde se viene.
     * @como-deberia-funcionar Usar el contador solo cuando el origen es la misma pantalla y, si no,
     *   quitarlo de la dirección.
     */
    public function test_conElContadorEnLaDireccionYOtroOrigenVolverLlevaAlOrigen(): void
    {
        $_SERVER['REQUEST_URI'] = '/tpvfox/modulos/mod_producto/producto.php?id=7&historyJS=2';
        $_SERVER['HTTP_REFERER'] = 'http://localhost/tpvfox/modulos/mod_producto/ListaProductos.php?pagina=3';
        $_GET = ['id' => '7', 'historyJS' => '2'];
        $desdeOtraPagina = (new \ControladorComun())->getHtmlLinkVolver();

        unset($_SERVER['HTTP_REFERER']);
        $_GET = ['id' => '7', 'historyJS' => '2'];
        $sinOrigen = (new \ControladorComun())->getHtmlLinkVolver();

        self::assertStringContainsString('href="/tpvfox/modulos/mod_producto/ListaProductos.php?pagina=3"', $desdeOtraPagina, 'Desde otra página, «Volver» lleva a esa página.');
        self::assertStringContainsString('href="/tpvfox/modulos/mod_producto"', $sinOrigen, 'Sin origen, «Volver» lleva a la carpeta del módulo.');
    }

    /**
     * @estado rojo
     * @codigo-afectado controllers/Controladores.php:307-309
     *
     * @group esperado
     * @group pantalla
     * @group bajo
     *
     * @que-ocurre-hoy Si al recargar una pantalla su dirección trae un contador de recargas que no
     *   es un número, la pantalla termina con un error de PHP.
     * @que-deberia-ocurrir Que el contador que no es un número se trate como si no estuviera.
     * @por-que-ocurre Se le suma uno al valor de la dirección sin comprobar que es un número.
     * @como-deberia-funcionar Tomar el contador como un entero positivo.
     */
    public function test_unContadorQueNoEsUnNumeroNoHaceFallarLaPantalla(): void
    {
        $_SERVER['REQUEST_URI'] = '/tpvfox/modulos/mod_producto/producto.php?historyJS=dos';
        $_SERVER['HTTP_REFERER'] = 'http://localhost/tpvfox/modulos/mod_producto/producto.php';
        $_GET = ['historyJS' => 'dos'];

        $fallo = null;
        try {
            $html = (new \ControladorComun())->getHtmlLinkVolver();
        } catch (\TypeError $error) {
            $fallo = $error->getMessage();
        }

        self::assertNull($fallo, 'Un contador que no es un número no puede hacer fallar la pantalla.');
        self::assertStringContainsString('onClick="history.go(-2);"', $html);
    }
}
