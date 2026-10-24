<?php

/**
 * El complemento de la tienda web de los productos (`plugins/mod_producto/virtuemart/ClaseVirtuemart.php`,
 * con `lib/curl/conexion_curl.php`): la tienda web y su clave, los enlaces y el HTML de un producto en
 * la web, sus IVA y sus notificaciones de clientes, y las llamadas a la API de la web —leer, modificar y
 * añadir un producto, restar stock, contar, responder por correo—.
 *
 * La tienda web es la de pega de `web/`, servida por el servidor de pruebas en la dirección local: el
 * caso siembra una tienda web con ese dominio y una clave propia, prepara en `web/casos/<clave>/` lo que
 * responde cada acción y lee lo que recibió. Ningún caso conecta con otra dirección.
 *
 * La clase abre su propia conexión y lee la tienda web al crearse: aquí se crea sin su constructor, con
 * la conexión del caso, y toma la tienda web como lo hace el constructor; lo que siembra el caso se
 * deshace al terminar. Otro complemento declara una clase con el mismo nombre: cada caso corre en su
 * propio proceso.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVirtuemart;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Entorno;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ComplementoProductosIntegracionTest extends CasoIntegracion
{
    private string $clave;

    private string $carpeta;

    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER['DOCUMENT_ROOT'] = '';
        $this->incluirTPVFox('/clases/ClaseConexion.php');
        $this->incluirTPVFox('/plugins/mod_producto/virtuemart/ClaseVirtuemart.php');
        $this->clave = 'p' . bin2hex(random_bytes(8));
        $this->carpeta = __DIR__ . '/web/casos/' . $this->clave;
        mkdir($this->carpeta, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->carpeta . '/*') ?: [] as $fichero) {
            unlink($fichero);
        }
        rmdir($this->carpeta);
        @rmdir(dirname($this->carpeta));
        parent::tearDown();
    }

    /** @comportamiento Sin tienda web, la lectura de las tiendas web lo dice; con una, la trae con su dominio y su clave. */
    public function test_laTiendaWebSeLeeConSuDominioYSuClave(): void
    {
        $sin = $this->complemento()->ObtenerTiendasWeb();
        $id = $this->tiendaWeb();
        $con = $this->complemento()->ObtenerTiendasWeb();

        self::assertSame('No hay tienda on-line', $sin['error']);
        self::assertSame([(string) $id, $this->dominio(), $this->clave], [$con['items'][0]['idTienda'], $con['items'][0]['dominio'], $con['items'][0]['key_api']]);
    }

    /** @comportamiento Los enlaces al producto en la web: el de la tienda solo si está publicado, el de administración siempre; sin id, ninguno. */
    public function test_losEnlacesAlProductoEnLaWeb(): void
    {
        $this->tiendaWeb();
        $complemento = $this->complemento();

        $publicado = $complemento->btnLinkProducto(31, 1);
        $sinPublicar = $complemento->btnLinkProducto(31, 0);

        self::assertStringContainsString('href="' . $this->dominio() . '/index.php?option=com_virtuemart&view=productdetails&virtuemart_product_id=31"', $publicado['html_frontEnd']);
        self::assertStringContainsString('href="' . $this->dominio() . '/administrator/index.php?option=com_virtuemart&view=product&task=edit&virtuemart_product_id=31"', $sinPublicar['html_backEnd']);
        self::assertArrayNotHasKey('html_frontEnd', $sinPublicar);
        self::assertSame([], $complemento->btnLinkProducto(0));
    }

    /** @comportamiento El id de la web de un producto sale de su relación con la tienda web; sin relación, vacío. */
    public function test_elIdDeLaWebSaleDeLaRelacionConLaTiendaWeb(): void
    {
        $id = (string) $this->tiendaWeb();
        $complemento = $this->complemento();

        self::assertSame('31', $complemento->obtenerIdVirtuemart([['idTienda' => '55', 'idVirtuemart' => '0'], ['idTienda' => $id, 'idVirtuemart' => '31']]));
        self::assertSame('', $complemento->obtenerIdVirtuemart([['idTienda' => '55', 'idVirtuemart' => '0']]));
        self::assertSame('', $complemento->obtenerIdVirtuemart(null));
    }

    /** @comportamiento Los datos de un producto se piden a la API de la web con su clave y su id, y se devuelve lo que responde. */
    public function test_losDatosDeUnProductoSePidenALaWeb(): void
    {
        $this->tiendaWeb();
        $this->responde('ObtenerProducto', $this->productoWeb());

        $respuesta = $this->complemento()->ObtenerDatosDeProducto(31);

        self::assertSame('Pera conferencia', $respuesta['Datos']['datosProducto']['item']['articulo_name']);
        self::assertSame([['key' => $this->clave, 'action' => 'ObtenerProducto', 'id_virtuemart' => '31']], $this->peticiones());
    }

    /**
     * @comportamiento Lo que la web responde se trata según llega: un código distinto de 200 o un fallo de
     *   la conexión se devuelven como error de conexión, y una respuesta que no es JSON, en bruto.
     */
    public function test_losFallosDeLaWebSeDevuelvenComoErrorDeConexion(): void
    {
        $this->tiendaWeb();
        $this->responde('contarProductos', 'no es json');
        $this->responde('ObtenerProducto', '{}', 500);
        $complemento = $this->complemento();

        $bruto = $complemento->contarProductos();
        $http = $complemento->ObtenerDatosDeProducto(31);
        $complemento->ruta_web = 'http://localhost:9/';
        $sinConexion = $complemento->contarProductos();

        self::assertSame(['no es json', 'Syntax error'], [$bruto['raw_response'], $bruto['json_error']]);
        self::assertSame('Respuesta http:500', $http['error_conexion']);
        self::assertNotSame('', (string) $sinConexion['error_conexion']);
    }

    /**
     * @comportamiento Restar el stock envía los productos como JSON entre comillas simples; modificar y
     *   añadir un producto, sus datos; y contar los productos, solo la acción.
     */
    public function test_lasLlamadasDeEscrituraEnvianSusDatos(): void
    {
        $this->tiendaWeb();
        $complemento = $this->complemento();

        $complemento->modificarStock([['idVirtuemart' => '31', 'nunidades' => '2']]);
        $complemento->modificarProducto('{"id":31}');
        $complemento->addProducto('{"id":0}');
        $complemento->contarProductos();

        self::assertSame([
            ['descontarStock', "'[{\"idVirtuemart\":\"31\",\"nunidades\":\"2\"}]'"],
            ['ModificarProducto', '{"id":31}'],
            ['AddProducto', '{"id":0}'],
            ['contarProductos', null],
        ], array_map(static fn (array $p): array => [$p['action'], $p['productos'] ?? $p['datos'] ?? null], $this->peticiones()));
    }

    /** @comportamiento Los IVA de la web se ofrecen con dos decimales, con el del producto marcado; el JavaScript del complemento se carga con su ruta. */
    public function test_losIvasDeLaWebYElJavascriptDelComplemento(): void
    {
        $this->tiendaWeb();
        $complemento = $this->complemento();

        $html = $complemento->htmlOptionIvasWeb([['virtuemart_calc_id' => '3', 'calc_value' => '10.0000'], ['virtuemart_calc_id' => '4', 'calc_value' => '21.0000']], '4');

        self::assertSame('<option value="3" >10.00%</option><option value="4"  selected>21.00%</option>', $html);
        self::assertStringContainsString('var ruta_plg_virtuemart = "', $complemento->htmlJava());
        self::assertStringContainsString('/plugins/mod_producto/virtuemart/func_plg_virtuemart.js"></script>', $complemento->htmlJava());
    }

    /**
     * @comportamiento El producto en la web se pinta con su id, su estado, su referencia, su nombre, su alias,
     *   sus códigos, su precio sin y con IVA y su IVA; sin id en la web, se ofrece añadirlo.
     */
    public function test_elProductoEnLaWebSePintaConSusDatos(): void
    {
        $this->tiendaWeb();
        $complemento = $this->complemento();
        $ivas = [['virtuemart_calc_id' => '3', 'calc_value' => '10.0000']];

        $relacionado = $complemento->htmlDatosProductoSeleccionado($this->datosWeb(), $ivas, 5, 9, '10.00')['html'];
        $nuevo = $complemento->htmlDatosProductoSeleccionado($this->datosWeb(['idVirtual' => 0, 'estado' => 0]), $ivas, 5, 9, '10.00')['html'];

        self::assertStringContainsString('value="Modificar en Web" id="botonWeb" name="modifWeb" onclick="modificarProductoWeb(5, 9)">', $relacionado);
        self::assertStringContainsString('<p id="idWeb">31</p>', $relacionado);
        self::assertStringContainsString('<select name="estadosWeb" id="estadosWeb"><option value="1">Publicado</option>', $relacionado);
        self::assertStringContainsString('placeholder="precioCiva" data-obj= "cajaPrecioCivaWeb"' . "\n" . '                                    value="2.2"', $relacionado);
        self::assertStringContainsString('<option value="3"  selected>10.00%</option>', $relacionado);
        self::assertStringContainsString('value="Añadir a la web" name="modifWeb"', $nuevo);
        self::assertStringContainsString('<select name="estadosWeb" id="estadosWeb"><option value="0">Sin publicar</option>', $nuevo);
    }

    /**
     * @estado rojo
     * @codigo-afectado plugins/mod_producto/virtuemart/ClaseVirtuemart.php:242-370
     *
     * @group defecto
     * @group seguridad
     * @group medio
     *
     * @que-ocurre-hoy Los datos del producto que llegan de la web se pintan en la ficha sin escapar: unas
     *   comillas cortan la caja del nombre, y unas marcas se interpretan como página.
     * @que-deberia-ocurrir Que los datos de la web se pinten como texto.
     * @por-que-ocurre El HTML concatena los datos tal como los responde la web.
     * @como-deberia-funcionar Escapar cada dato al pintarlo.
     */
    public function test_losDatosDeLaWebSePintanComoTextoEnLaFicha(): void
    {
        $this->tiendaWeb();

        $html = $this->complemento()->htmlDatosProductoSeleccionado($this->datosWeb(['articulo_name' => 'Pera "extra" <b>oferta</b>']), [], 5, 9, '10.00')['html'];

        self::assertStringNotContainsString('value="Pera "extra" <b>oferta</b>"', $html);
    }

    /** @comportamiento Las notificaciones de clientes de un producto se piden a la web, se cuentan y se pintan en una tabla con su opción de responder. */
    public function test_lasNotificacionesDeClientesSePidenYSePintan(): void
    {
        $this->tiendaWeb();
        $this->responde('ObtenerNotificacionesProducto', (string) json_encode(['Datos' => ['items' => [$this->notificacion(), $this->notificacion(['nombreUsuario' => 'Luis', 'idNotificacion' => '8'])]]]));
        $complemento = $this->complemento();

        $respuesta = $complemento->ObtenerNotificacionesProducto(31);
        $html = $complemento->htmlNotificacionesProducto($respuesta['Datos']['items']);

        self::assertSame(2, $complemento->num_notificaciones);
        self::assertStringContainsString('<td id="nombre_1">Ana</td>', $html);
        self::assertStringContainsString('<td id="mail_2">ana@example.com</td>', $html);
        self::assertStringContainsString('id="idNotificacion_2" value="8"', $html);
        self::assertStringContainsString('onclick="ModalNotificacion(1)"', $html);
    }

    /**
     * @estado rojo
     * @codigo-afectado plugins/mod_producto/virtuemart/ClaseVirtuemart.php:398-435
     *
     * @group defecto
     * @group seguridad
     * @group alto
     *
     * @que-ocurre-hoy El nombre y el correo que un cliente escribe en la web se pintan en la ficha del
     *   producto sin escapar: lo que escriba se ejecuta en el TPV de quien la abre.
     * @que-deberia-ocurrir Que se pinten como texto.
     * @por-que-ocurre La tabla concatena los datos de la notificación tal como llegan.
     * @como-deberia-funcionar Escapar cada dato al pintarlo.
     */
    public function test_losDatosDeLosClientesDeLaWebSePintanComoTexto(): void
    {
        $this->tiendaWeb();

        $html = $this->complemento()->htmlNotificacionesProducto([$this->notificacion(['nombreUsuario' => '<script>alert(1)</script>'])]);

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    /** @comportamiento Responder a un cliente envía el correo y marca la notificación en la web, con el producto y el correo. */
    public function test_responderAUnClienteEnviaElCorreoYMarcaLaNotificacion(): void
    {
        $this->tiendaWeb();
        $this->responde('enviarCorreo', '{"Datos":{"mailer":true}}');
        $complemento = $this->complemento();

        $correo = $complemento->enviarCorreo(['email' => 'ana@example.com', 'asunto' => 'Pera', 'mensaje' => 'Ya hay']);
        $complemento->modificarNotificacion(31, 'ana@example.com');

        self::assertTrue($correo['Datos']['mailer']);
        self::assertSame(['enviarCorreo', 'modificarNotificacion'], array_column($this->peticiones(), 'action'));
        self::assertSame(['email' => 'ana@example.com', 'asunto' => 'Pera', 'mensaje' => 'Ya hay'], json_decode($this->peticiones()[0]['datos'], true));
        self::assertSame(['31', 'ana@example.com'], [$this->peticiones()[1]['idProducto'], $this->peticiones()[1]['email']]);
    }

    /**
     * @estado rojo
     * @codigo-afectado plugins/mod_producto/virtuemart/ClaseVirtuemart.php:583-597; plugins/mod_producto/virtuemart/tareas_virtuemart.php:173-192
     *
     * @group defecto
     * @group seguridad
     * @group alto
     *
     * @que-ocurre-hoy La respuesta de enviar un correo lleva los parámetros de la llamada, con la clave de
     *   la API de la web, y la petición la devuelve al navegador.
     * @que-deberia-ocurrir Que la clave de la API no salga del servidor.
     * @por-que-ocurre El método añade a la respuesta los parámetros que envió.
     * @como-deberia-funcionar Devolver solo la respuesta de la web.
     */
    public function test_laRespuestaDeUnCorreoNoLlevaLaClaveDeLaApi(): void
    {
        $this->tiendaWeb();
        $this->responde('enviarCorreo', '{"Datos":{"mailer":true}}');

        $correo = $this->complemento()->enviarCorreo(['email' => 'ana@example.com']);

        self::assertStringNotContainsString($this->clave, (string) json_encode($correo));
    }

    /** @comportamiento Comparar el IVA del producto con el de la web: igual, nada; distinto, el aviso. */
    public function test_elIvaDelProductoSeComparaConElDeLaWeb(): void
    {
        $this->tiendaWeb();
        $complemento = $this->complemento();

        self::assertNull($complemento->comprobarIvas('10.00', '10.0000'));
        self::assertSame('warning', $complemento->comprobarIvas('10.00', '21.0000')['tipo']);
    }

    /**
     * @comportamiento Los datos completos de un producto sin relación con la web proponen añadirlo, con el
     *   IVA de la web que coincide con el del producto.
     */
    public function test_losDatosCompletosDeUnProductoSinRelacion(): void
    {
        $id = $this->tiendaWeb();
        $this->responde('ObtenerProducto', $this->productoWeb());

        $datos = $this->complemento()->datosCompletosTiendaWeb(0, '21.00', 5, $id);

        self::assertSame([0, '4'], [$datos['datosWeb']['idVirtual'], $datos['datosWeb']['idIva']]);
        self::assertStringContainsString('value="Añadir a la web"', $datos['htmlproducto']['html']);
        self::assertArrayNotHasKey('errores', $datos);
    }

    /**
     * @comportamiento Los datos completos de un producto relacionado traen sus datos, sus enlaces, sus
     *   notificaciones pintadas y, si el IVA del TPV no es el de la web, el aviso.
     */
    public function test_losDatosCompletosDeUnProductoRelacionado(): void
    {
        $id = $this->tiendaWeb();
        $this->responde('ObtenerProducto', $this->productoWeb());
        $this->responde('ObtenerNotificacionesProducto', (string) json_encode(['Datos' => ['items' => [$this->notificacion()]]]));

        $igual = $this->complemento()->datosCompletosTiendaWeb(31, '10.00', 5, $id);
        $distinto = $this->complemento()->datosCompletosTiendaWeb(31, '21.00', 5, $id);

        self::assertSame(['Pera conferencia', 1], [$igual['datosWeb']['articulo_name'], $igual['num_notificaciones']]);
        self::assertArrayHasKey('html_frontEnd', $igual['htmlsLinksVirtuemart']);
        self::assertStringContainsString('<td id="nombre_1">Ana</td>', $igual['htmlnotificaciones']);
        self::assertArrayNotHasKey('errores', $igual);
        self::assertSame('warning', $distinto['errores']['tipo']);
    }

    /** @comportamiento Sin respuesta de la web, los datos completos de un producto relacionado lo dicen, y sin sus IVA también. */
    public function test_sinRespuestaDeLaWebLosDatosCompletosLoDicen(): void
    {
        $id = $this->tiendaWeb();
        $this->responde('ObtenerProducto', '{}');

        $relacionado = $this->complemento()->datosCompletosTiendaWeb(31, '10.00', 5, $id);
        $nuevo = $this->complemento()->datosCompletosTiendaWeb(0, '10.00', 5, $id);

        self::assertSame('Hubo un error al intentar obtener el producto con idVirtuemart:31', $relacionado['errores']['mensaje']);
        self::assertSame('No se han obtenido los ivas de la web o la respuesta está malformada.', $nuevo['errores']['mensaje']);
    }

    /** @comportamiento Los productos de un tramo se piden a la web con su inicio y su final. */
    public function test_losProductosDeUnTramoSePidenALaWeb(): void
    {
        $this->tiendaWeb();
        $this->responde('productosInicioFinal', '{"Datos":{"items":[{"id":31}]}}');

        $respuesta = $this->complemento()->productosInicioFinal(0, 100);

        self::assertSame([['id' => 31]], $respuesta['Datos']['items']);
        self::assertSame(['0', '100'], [$this->peticiones()[0]['inicio'], $this->peticiones()[0]['final']]);
    }

    /** @comportamiento El error de una respuesta se explica: el de conexión, el de la web, o que la web no tiene IVA. */
    public function test_elErrorDeUnaRespuestaSeExplica(): void
    {
        $this->tiendaWeb();
        $complemento = $this->complemento();

        self::assertSame('Sin conexión', $complemento->error_string(['error_conexion' => 'Sin conexión']));
        self::assertSame('Clave', $complemento->error_string(['error' => 'Clave']));
        self::assertSame(' No hay ivas en la web', $complemento->error_string(['Datos' => ['ivasWeb' => ['items' => []]]]));
        self::assertNull($complemento->error_string(['Datos' => ['ivasWeb' => ['items' => [['virtuemart_calc_id' => '3']]]]]));
    }

    /**
     * @estado rojo
     * @codigo-afectado plugins/mod_producto/virtuemart/ClaseVirtuemart.php:132-153,599-620
     *
     * @group defecto
     * @group errores
     * @group medio
     *
     * @que-ocurre-hoy Si la web responde un error en sus IVA, el método lo escribe en la salida: en una
     *   petición de fondo, rompe la respuesta que espera la página.
     * @que-deberia-ocurrir Que el error se devuelva en la respuesta, sin escribir nada.
     * @por-que-ocurre El método hace `print_r` del error.
     * @como-deberia-funcionar Devolverlo en la respuesta y que lo enseñe la página.
     */
    public function test_unErrorDeLosIvasDeLaWebNoSeEscribeEnLaSalida(): void
    {
        $this->tiendaWeb();
        $this->responde('ObtenerProducto', '{"Datos":{"ivasWeb":{"error":"Tabla de IVA"}}}');

        ob_start();
        $respuesta = $this->complemento()->ObtenerDatosDeProducto(31);
        $escrito = (string) ob_get_clean();

        self::assertSame('Tabla de IVA', $respuesta['Datos']['ivasWeb']['error']);
        self::assertSame('', $escrito);
    }

    /**
     * @comportamiento Los datos completos de un producto relacionado dicen cuándo la respuesta de la web no
     *   trae el producto, y cuándo fallan sus notificaciones.
     */
    public function test_lasRespuestasIncompletasDeLaWebEnLosDatosCompletosLoDicen(): void
    {
        $id = $this->tiendaWeb();
        $ivas = ['items' => [['virtuemart_calc_id' => '3', 'calc_value' => '10.0000']]];
        $this->responde('ObtenerProducto', (string) json_encode(['Datos' => ['ivasWeb' => $ivas]]));
        $sinProducto = $this->complemento()->datosCompletosTiendaWeb(31, '10.00', 5, $id);
        $this->responde('ObtenerProducto', $this->productoWeb());
        $this->responde('ObtenerNotificacionesProducto', '{"Datos":{"error":"Tabla de notificaciones"}}');
        $sinNotificaciones = $this->complemento()->datosCompletosTiendaWeb(31, '10.00', 5, $id);

        self::assertSame('Los datos del producto devueltos por la web no tienen la estructura esperada.', $sinProducto['errores']['mensaje']);
        self::assertStringContainsString('Error de SQL: Tabla de notificaciones', $sinNotificaciones['htmlnotificaciones']);
    }

    /**
     * @estado rojo
     * @codigo-afectado plugins/mod_producto/virtuemart/ClaseVirtuemart.php:256-257, :565-578
     *
     * @group defecto
     * @group errores
     * @group bajo
     *
     * @que-ocurre-hoy Si la web devuelve el producto sin su IVA, los datos completos lo avisan, pero al
     *   pintarlo leen el IVA que no viene y dejan un aviso de PHP.
     * @que-deberia-ocurrir Que el producto se pinte sin el precio con IVA, sin avisos.
     * @por-que-ocurre El HTML del producto calcula el precio con IVA sin comprobar que el IVA venga.
     * @como-deberia-funcionar Comprobarlo antes de calcular, como hacen los datos completos.
     */
    public function test_sinElIvaDeLaWebLosDatosCompletosLoDicenSinAvisos(): void
    {
        $id = $this->tiendaWeb();
        $datos = $this->datosWeb();
        unset($datos['iva']);
        $this->responde('ObtenerProducto', (string) json_encode(['Datos' => ['ivasWeb' => ['items' => [['virtuemart_calc_id' => '3', 'calc_value' => '10.0000']]], 'datosProducto' => ['item' => $datos]]]));
        $this->responde('ObtenerNotificacionesProducto', '{"Datos":{"items":[]}}');
        $complemento = $this->complemento();
        $avisos = [];
        set_error_handler(static function (int $nivel, string $mensaje) use (&$avisos): bool {
            $avisos[] = $mensaje;

            return true;
        });
        try {
            $sinIva = $complemento->datosCompletosTiendaWeb(31, '10.00', 5, $id);
        } finally {
            restore_error_handler();
        }

        self::assertSame('No se pudo leer el iva del producto en la web para comparar.', $sinIva['errores']['mensaje']);
        self::assertSame([], $avisos);
    }

    /** El complemento, creado sin su constructor con la conexión del caso, y la tienda web tomada como lo hace el constructor. */
    private function complemento(): \PluginClaseVirtuemart
    {
        $complemento = (new \ReflectionClass(\PluginClaseVirtuemart::class))->newInstanceWithoutConstructor();
        $complemento->ruta_proyecto = RUTA_TPVFOX;
        $complemento->conexion = $this->db;
        $complemento->obtenerRutaProyecto();
        $tiendas = $complemento->ObtenerTiendasWeb();
        if (isset($tiendas['items'][0])) {
            $complemento->TiendaWeb = $tiendas['items'][0];
            $complemento->key_api = $tiendas['items'][0]['key_api'];
            $complemento->ruta_web = $tiendas['items'][0]['dominio'] . '/administrator/apisv/tareas.php';
        }

        return $complemento;
    }

    /** Siembra la tienda web de pega, con su dominio y la clave del caso. */
    private function tiendaWeb(): int
    {
        $sentencia = $this->db->prepare("INSERT INTO tiendas (tipoTienda, razonsocial, nif, telefono, estado, NombreComercial, direccion, ano, dominio, key_api) VALUES ('web', 'Tienda web de pruebas', 'X0000000X', '000000000', 'Activo', 'Web de pruebas', 'Sin direccion', '2026', ?, ?)");
        $dominio = $this->dominio();
        $sentencia->bind_param('ss', $dominio, $this->clave);
        $sentencia->execute();

        return (int) $this->db->insert_id;
    }

    private function dominio(): string
    {
        return (string) preg_replace('#TPVFox/?$#', 'test/Integration/PHP/mod_virtuemart/web', Entorno::valor('TPVFOX_URL'));
    }

    /** Prepara lo que la tienda web de pega responde a una acción, y con qué código. */
    private function responde(string $accion, string $cuerpo, int $codigo = 200): void
    {
        file_put_contents($this->carpeta . '/' . $accion . '.json', $cuerpo);
        if ($codigo !== 200) {
            file_put_contents($this->carpeta . '/' . $accion . '.http', (string) $codigo);
        }
    }

    /** @return list<array<string, string>> Lo que recibió la tienda web de pega, petición a petición. */
    private function peticiones(): array
    {
        $lineas = is_file($this->carpeta . '/peticiones.jsonl') ? file($this->carpeta . '/peticiones.jsonl', FILE_IGNORE_NEW_LINES) : [];

        return array_map(static fn (string $linea): array => json_decode($linea, true), $lineas ?: []);
    }

    private function productoWeb(): string
    {
        return (string) json_encode(['Datos' => [
            'ivasWeb' => ['items' => [['virtuemart_calc_id' => '3', 'calc_value' => '10.0000'], ['virtuemart_calc_id' => '4', 'calc_value' => '21.0000']]],
            'datosProducto' => ['item' => $this->datosWeb()],
        ]]);
    }

    /** @param array<string, mixed> $cambios */
    private function datosWeb(array $cambios = []): array
    {
        return $cambios + ['idVirtual' => 31, 'estado' => 1, 'articulo_name' => 'Pera conferencia', 'refTienda' => 'PER-1', 'codBarra' => '8400000000017', 'precioSiva' => '2.000000', 'idIva' => '3', 'alias' => 'pera-conferencia', 'iva' => '10.0000'];
    }

    /** @param array<string, string> $cambios */
    private function notificacion(array $cambios = []): array
    {
        return $cambios + ['nombreUsuario' => 'Ana', 'email' => 'ana@example.com', 'idNotificacion' => '7', 'cant' => '2'];
    }
}
