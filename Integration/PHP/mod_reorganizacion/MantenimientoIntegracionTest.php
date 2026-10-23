<?php

/**
 * Las operaciones de mantenimiento de la petición de fondo de `mod_reorganizacion` (`tareas.php`):
 * contar los productos y regenerar las existencias de la tienda desde sus movimientos, subir el stock y
 * los precios a la tienda web y reorganizar los permisos de los usuarios; y lo que responde la
 * petición a una orden que no conoce.
 *
 * Cada petición se ejecuta en un PHP aparte, desde la carpeta del módulo, con la sesión del usuario de
 * pruebas o sin ninguna, los permisos del módulo concedidos salvo los que el caso quite, y una
 * transacción que se deshace al terminar. La regeneración trabaja sobre la tienda 1: la sesión es de
 * una tienda con ese número, que el caso da de alta si no existe, salvo donde el caso prueba otra. Los
 * productos que siembra el caso quedan con un nombre —`:p1`, `:p2`…—, y la posición del usuario de
 * pruebas en la lista de usuarios, como `:indice`.
 *
 * Ningún caso sale del equipo. La tienda web a la que se sube es un servidor de PHP en el propio
 * equipo que responde lo que el caso le da y guarda lo que recibe. La ficha de la tienda web se da de
 * alta fuera de la transacción, porque el complemento la lee con su propia conexión, y se borra al
 * terminar el caso y antes de empezarlo, por si quedó de uno interrumpido.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModReorganizacion;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class MantenimientoIntegracionTest extends CasoIntegracion
{
    private int $idUsuario;

    private int $idTienda;

    /** @var resource|null */
    private $tiendaWeb = null;

    private string $carpetaTiendaWeb = '';

    private ?\mysqli $conexionPropia = null;

    protected function setUp(): void
    {
        parent::setUp();
        $siembra = new Siembra($this->db);
        $this->idUsuario = $siembra->usuarioPorDefecto();
        $this->idTienda = $siembra->tiendaPorDefecto();
        $this->borrarLaTiendaWeb();
    }

    protected function tearDown(): void
    {
        $this->borrarLaTiendaWeb();
        $this->conexionPropia->close();
        if ($this->tiendaWeb !== null) {
            proc_terminate($this->tiendaWeb);
            proc_close($this->tiendaWeb);
        }
        if ($this->carpetaTiendaWeb !== '') {
            array_map('unlink', glob($this->carpetaTiendaWeb . '/*') ?: []);
            @rmdir($this->carpetaTiendaWeb);
        }
        parent::tearDown();
    }

    /** @comportamiento Contar los productos para regenerar el stock da todos los del catálogo. */
    public function test_contarLosProductosDaTodosLosDelCatalogo(): void
    {
        $contar = $this->ejecutar(['pulsado' => 'contarproductos', 'tipo' => ''], [], true, ['catalogo' => 'SELECT COUNT(*) FROM articulos']);

        self::assertSame((int) $contar['consultas']['catalogo'], (int) $contar['respuesta']['totalProductos']);
    }

    /**
     * @comportamiento La primera tanda de la regeneración quita las existencias de la tienda 1 y pone a
     *   cada producto de la tanda las que dan sus entradas de proveedor menos sus ventas; un producto sin
     *   movimientos se queda sin existencias, y las de otra tienda no cambian.
     */
    public function test_regenerarPoneLasExistenciasQueDanLosMovimientos(): void
    {
        $regenerar = $this->regenerar('0', '100000', [
            ...$this->producto('Con movimientos', 4),
            $this->entrada(':p1', 10), $this->venta(':p1', 3),
            ...$this->producto('Sin movimientos', 5),
            'INSERT INTO articulosStocks (idArticulo, idTienda, stockOn, stockMin, stockMax) VALUES (:p2, ' . $this->idTienda . ', 8, 0, 0)',
        ], [
            'movimientos' => 'SELECT ROUND(stockOn, 2) FROM articulosStocks WHERE idArticulo = :p1 AND idTienda = 1',
            'sinMovimientos' => 'SELECT COUNT(*) FROM articulosStocks WHERE idArticulo = :p2 AND idTienda = 1',
            'otraTienda' => 'SELECT ROUND(stockOn, 2) FROM articulosStocks WHERE idArticulo = :p2 AND idTienda = ' . $this->idTienda,
        ]);

        self::assertSame(['7.00', '0', '8.00'], [$regenerar['consultas']['movimientos'], $regenerar['consultas']['sinMovimientos'], $regenerar['consultas']['otraTienda']]);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_reorganizacion/tareas.php:59-62
     *
     * @group defecto
     * @group existencias
     * @group medio
     *
     * @que-ocurre-hoy Regenerar el stock borra el mínimo y el máximo de existencias de cada producto de la
     *   tienda: los productos regenerados vuelven con cero en los dos.
     * @que-deberia-ocurrir Que regenerar cambie el saldo y conserve el mínimo y el máximo de cada producto.
     * @por-que-ocurre La primera tanda borra las filas de existencias de la tienda, donde viven el mínimo y
     *   el máximo, y las que suma después se crean sin ellos.
     * @como-deberia-funcionar Poner a cero el saldo en lugar de borrar las filas, o recalcularlo sobre la
     *   fila de cada producto.
     */
    public function test_regenerarConservaElMinimoYElMaximoDeCadaProducto(): void
    {
        $regenerar = $this->regenerar('0', '100000', [
            ...$this->producto('Con mínimo', 4, 2, 9),
            $this->entrada(':p1', 10),
        ], ['limites' => "SELECT CONCAT(ROUND(stockMin), '|', ROUND(stockMax)) FROM articulosStocks WHERE idArticulo = :p1 AND idTienda = 1"]);

        self::assertSame('2|9', $regenerar['consultas']['limites']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_reorganizacion/tareas.php:59-79
     *
     * @group defecto
     * @group existencias
     * @group alto
     *
     * @que-ocurre-hoy La regeneración deja sin existencias, desde su primera tanda, todos los productos de
     *   la tienda; los de las tandas siguientes siguen sin ellas hasta que llega la suya, y si la
     *   operación se interrumpe se quedan así.
     * @que-deberia-ocurrir Que un producto que aún no se ha recalculado conserve sus existencias.
     * @por-que-ocurre La primera tanda borra las existencias de toda la tienda antes de recalcular ninguna.
     * @como-deberia-funcionar Recalcular producto a producto sin borrar antes las de todos, o hacerlo en una
     *   sola operación que se complete o se deshaga.
     */
    public function test_unaTandaNoDejaSinExistenciasLosProductosDeOtras(): void
    {
        $regenerar = $this->regenerar('0', '1', [...$this->producto('De otra tanda', 5), $this->entrada(':p1', 5)], [
            'existencia' => 'SELECT ROUND(stockOn, 2) FROM articulosStocks WHERE idArticulo = :p1 AND idTienda = 1',
        ]);

        self::assertSame('5.00', $regenerar['consultas']['existencia']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_reorganizacion/tareas.php:54-86
     *
     * @group defecto
     * @group existencias
     * @group alto
     *
     * @que-ocurre-hoy En una tienda que no tiene el número 1, regenerar el stock rehace las existencias de
     *   la tienda 1 con los movimientos de la tienda 1, y deja las de la sesión como estaban.
     * @que-deberia-ocurrir Que regenere las existencias de la tienda de la sesión, con sus movimientos.
     * @por-que-ocurre El vaciado y la suma de las existencias y el cálculo del saldo usan la tienda 1 que
     *   tienen por defecto o escrita en la petición.
     * @como-deberia-funcionar Pasar la tienda de la sesión al vaciado, al cálculo y a la suma.
     */
    public function test_regenerarRehaceLasExistenciasDeLaTiendaDeLaSesion(): void
    {
        $regenerar = $this->ejecutar(['pulsado' => 'generastock', 'inicial' => '0', 'pagina' => '100000', 'totalProductos' => '1'], [
            ...$this->producto('En la sesión', 0),
            'INSERT INTO articulosStocks (idArticulo, idTienda, stockOn, stockMin, stockMax) VALUES (:p1, ' . $this->idTienda . ', 9, 0, 0)',
            $this->entrada(':p1', 4, $this->idTienda),
        ], true, ['existencia' => 'SELECT ROUND(stockOn, 2) FROM articulosStocks WHERE idArticulo = :p1 AND idTienda = ' . $this->idTienda], $this->idTienda);

        self::assertSame('4.00', $regenerar['consultas']['existencia']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_reorganizacion/tareas.php:1-42
     *
     * @group defecto
     * @group permisos
     * @group alto
     *
     * @que-ocurre-hoy Una petición sin sesión regenera el stock: borra las existencias de la tienda.
     * @que-deberia-ocurrir Que sin sesión la petición no haga nada.
     * @por-que-ocurre La petición carga el arranque común sin la cabecera, que es la que pide la sesión, y no
     *   la comprueba; la causa común es la del núcleo.
     * @como-deberia-funcionar Comprobar la sesión antes de atender la orden.
     */
    public function test_sinSesionNoSeRegeneraElStock(): void
    {
        $regenerar = $this->ejecutar(['pulsado' => 'generastock', 'inicial' => '0', 'pagina' => '1', 'totalProductos' => '1'], $this->producto('Intacto', 6), false, [
            'existencia' => 'SELECT ROUND(stockOn, 2) FROM articulosStocks WHERE idArticulo = :p1 AND idTienda = 1',
        ]);

        self::assertSame('6.00', $regenerar['consultas']['existencia']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_reorganizacion/tareas.php:54-86
     *
     * @group defecto
     * @group permisos
     * @group alto
     *
     * @que-ocurre-hoy Un usuario sin el permiso de regenerar el stock lo regenera por la petición de fondo.
     * @que-deberia-ocurrir Que sin ese permiso no cambie ninguna existencia.
     * @por-que-ocurre La petición no consulta ningún permiso: solo la pantalla esconde el botón.
     * @como-deberia-funcionar Consultar el permiso de la acción antes de regenerar.
     */
    public function test_sinPermisoNoSeRegeneraElStock(): void
    {
        $regenerar = $this->regenerar('0', '1', [
            ...$this->producto('Intacto', 6),
            "UPDATE permisos SET permiso = 0 WHERE idUsuario = :usuario AND modulo = 'mod_reorganizacion'",
        ], ['existencia' => 'SELECT ROUND(stockOn, 2) FROM articulosStocks WHERE idArticulo = :p1 AND idTienda = 1']);

        self::assertSame('6.00', $regenerar['consultas']['existencia']);
    }

    /** @comportamiento Sin tienda web, contar sus productos da cero, y la pantalla no sube nada. */
    public function test_sinTiendaWebNoHayProductosQueSubir(): void
    {
        $contar = $this->ejecutar(['pulsado' => 'contarproductos', 'tipo' => 'web']);

        self::assertSame(0, (int) $contar['respuesta']['totalProductos']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_reorganizacion/tareas.php:88-115
     *
     * @group defecto
     * @group validacion
     * @group bajo
     *
     * @que-ocurre-hoy Pedir que se suban el stock y los precios sin una tienda web termina la petición con
     *   un error de la base, sin respuesta.
     * @que-deberia-ocurrir Que responda que no hay tienda web.
     * @por-que-ocurre La subida toma la tienda web aunque no se haya encontrado ninguna, y compone la
     *   consulta de sus productos sin su número.
     * @como-deberia-funcionar Comprobar que hay tienda web antes de subir, y decirlo si no.
     */
    public function test_subirSinTiendaWebRespondeQueNoLaHay(): void
    {
        $subir = $this->ejecutar(['pulsado' => 'subirStockYPrecio', 'inicial' => '0', 'cantidad' => '100', 'totalProductos' => '0']);

        self::assertStringNotContainsString('Fatal error', $subir['errores']);
        self::assertIsArray($subir['respuesta']);
    }

    /**
     * @comportamiento Subir el stock y los precios envía a la tienda web, con su clave, cada producto suyo
     *   con su número en ella, sus existencias y su precio sin IVA, y responde lo que la tienda web dice
     *   que actualizó.
     */
    public function test_subirEnviaALaTiendaWebLaExistenciaYElPrecioDeCadaProducto(): void
    {
        $direccion = $this->tiendaWebQueResponde(['Datos' => ['consulta1' => ['77'], 'consulta2' => ['77']]]);

        $this->tiendaWebEn($direccion);
        $subir = $this->ejecutar(['pulsado' => 'subirStockYPrecio', 'inicial' => '0', 'cantidad' => '100', 'totalProductos' => '1'], [
            ...$this->productoEnLaWeb('Naranja', 77, 4, 2.5),
        ]);
        $recibido = $this->recibidoPorLaTiendaWeb();
        $productos = json_decode($recibido['datos'], true);

        self::assertSame(['clave-de-pruebas', 'cambiarStockYPrecios'], [$recibido['key'], $recibido['action']]);
        self::assertSame([['77', 4.0, 2.5]], array_map(static fn ($fila) => [(string) $fila['idVirtuemart'], (float) $fila['stockOn'], (float) $fila['pvpSiva']], $productos));
        self::assertSame([['77'], ['77']], [$subir['respuesta']['elementos'], $subir['respuesta']['elementos_precios']]);
        self::assertArrayNotHasKey('error', $subir['respuesta']);
    }

    /** @comportamiento Si la tienda web responde un error, la respuesta lo lleva para la pantalla. */
    public function test_unErrorDeLaTiendaWebLlegaEnLaRespuesta(): void
    {
        $direccion = $this->tiendaWebQueResponde(['Datos' => ['consulta1' => [], 'consulta2' => [], 'error' => 'Clave no válida']]);

        $this->tiendaWebEn($direccion);
        $subir = $this->ejecutar(['pulsado' => 'subirStockYPrecio', 'inicial' => '0', 'cantidad' => '100', 'totalProductos' => '1'], [
            ...$this->productoEnLaWeb('Naranja', 77, 4, 2.5),
        ]);

        self::assertSame('Clave no válida', $subir['respuesta']['error']['Datos']['error']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_reorganizacion/tareas.php:106
     *
     * @group defecto
     * @group existencias
     * @group medio
     *
     * @que-ocurre-hoy Cada tanda de la subida dice que la siguiente empieza un producto más allá del que
     *   sigue, y la pantalla pide desde ahí: el primer producto de cada tanda después de la primera no se
     *   sube nunca.
     * @que-deberia-ocurrir Que la siguiente tanda empiece en el producto que sigue al último enviado.
     * @por-que-ocurre La posición siguiente se calcula sumando uno de más a lo enviado.
     * @como-deberia-funcionar Responder como posición siguiente la inicial más los productos enviados.
     */
    public function test_laTandaSiguienteDeLaSubidaEmpiezaEnElProductoQueSigue(): void
    {
        $direccion = $this->tiendaWebQueResponde(['Datos' => ['consulta1' => ['77'], 'consulta2' => ['77']]]);

        $this->tiendaWebEn($direccion);
        $subir = $this->ejecutar(['pulsado' => 'subirStockYPrecio', 'inicial' => '0', 'cantidad' => '1', 'totalProductos' => '2'], [
            ...$this->productoEnLaWeb('Naranja', 77, 4, 2.5),
            ...$this->productoEnLaWeb('Limón', 78, 6, 1.5),
        ]);

        self::assertSame(1, (int) $subir['respuesta']['actual']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_reorganizacion/tareas.php:98-111
     *
     * @group defecto
     * @group validacion
     * @group medio
     *
     * @que-ocurre-hoy Si la tienda web no responde, la subida responde sin error y sin lo actualizado, con
     *   avisos de PHP: la pantalla no dice que la tienda web no recibió nada.
     * @que-deberia-ocurrir Que la respuesta diga que no se pudo enviar a la tienda web.
     * @por-que-ocurre El fallo de la conexión llega en otro campo de la respuesta del complemento, que la
     *   subida no mira; solo pasa el error que devuelve la propia tienda web.
     * @como-deberia-funcionar Pasar también el fallo de la conexión como error.
     */
    public function test_siLaTiendaWebNoRespondeLaRespuestaLoDice(): void
    {
        $this->tiendaWebEn('http://127.0.0.1:' . $this->puertoLibre());
        $subir = $this->ejecutar(['pulsado' => 'subirStockYPrecio', 'inicial' => '0', 'cantidad' => '100', 'totalProductos' => '1'], [
            ...$this->productoEnLaWeb('Naranja', 77, 4, 2.5),
        ]);

        self::assertArrayHasKey('error', $subir['respuesta']);
    }

    /**
     * @comportamiento Reorganizar los permisos de un usuario le quita los de un módulo que ya no existe,
     *   conserva los que tenía —también uno concedido a mano— y responde cuántos quitó y cuántos creó.
     */
    public function test_reorganizarQuitaLosPermisosDeModulosQueNoExistenYConservaLosDemas(): void
    {
        $reorganizar = $this->ejecutar(['pulsado' => 'reorganizarPermisosModulos', 'inicial' => ':indice', 'total' => '1'], [
            "INSERT INTO permisos (idUsuario, modulo, permiso) VALUES (:usuario, 'mod_retirado', 1)",
            "INSERT INTO permisos (idUsuario, modulo, vista, accion, permiso) VALUES (:usuario, 'mod_retirado', 'Vista.php', 'ver', 1)",
            "UPDATE permisos SET permiso = 1 WHERE idUsuario = :usuario AND modulo = 'mod_reorganizacion' AND accion = 'regenerar_stock'",
        ], true, [
            'retirado' => "SELECT COUNT(*) FROM permisos WHERE idUsuario = :usuario AND modulo = 'mod_retirado'",
            'concedido' => "SELECT permiso FROM permisos WHERE idUsuario = :usuario AND modulo = 'mod_reorganizacion' AND accion = 'regenerar_stock'",
        ]);

        self::assertSame(['0', '1'], [$reorganizar['consultas']['retirado'], $reorganizar['consultas']['concedido']]);
        self::assertSame([2, 0], [$reorganizar['respuesta']['eliminado'], $reorganizar['respuesta']['creados']]);
        self::assertSame((string) $this->idUsuario, (string) $reorganizar['respuesta']['usuario']['id']);
    }

    /**
     * @comportamiento Reorganizar los permisos da a un usuario los de partida que le faltan, y la primera
     *   vuelta rehace los permisos de partida desde lo que declaran los módulos.
     */
    public function test_reorganizarDaLosPermisosQueFaltanYRehaceLosDePartida(): void
    {
        $reorganizar = $this->ejecutar(['pulsado' => 'reorganizarPermisosModulos', 'inicial' => '0', 'total' => '1'], [
            "INSERT INTO permisos (idUsuario, modulo, permiso) VALUES (0, 'mod_retirado', 1)",
            "DELETE FROM permisos WHERE idUsuario = (SELECT id FROM usuarios ORDER BY id LIMIT 1) AND modulo = 'mod_reorganizacion' AND accion = 'cerrar_stock_anual'",
        ], true, [
            'partida' => "SELECT COUNT(*) FROM permisos WHERE idUsuario = 0 AND modulo = 'mod_retirado'",
            'partidaModulo' => "SELECT COUNT(*) FROM permisos WHERE idUsuario = 0 AND modulo = 'mod_reorganizacion' AND accion = 'cerrar_stock_anual'",
            'primero' => "SELECT COUNT(*) FROM permisos WHERE idUsuario = (SELECT id FROM usuarios ORDER BY id LIMIT 1) AND modulo = 'mod_reorganizacion' AND accion = 'cerrar_stock_anual'",
        ]);

        self::assertSame(['0', '1', '1'], [$reorganizar['consultas']['partida'], $reorganizar['consultas']['partidaModulo'], $reorganizar['consultas']['primero']]);
    }

    /**
     * @comportamiento A un administrador, los permisos que le faltan se le crean concedidos, aunque el de
     *   partida no lo esté.
     */
    public function test_alAdministradorLeFaltanPermisosYSeLeCreanConcedidos(): void
    {
        $reorganizar = $this->ejecutar(['pulsado' => 'reorganizarPermisosModulos', 'inicial' => ':indice', 'total' => '1'], [
            'UPDATE usuarios SET group_id = 9 WHERE id = :usuario',
            "DELETE FROM permisos WHERE idUsuario = :usuario AND modulo = 'mod_reorganizacion' AND accion = 'subir_stock_web'",
        ], true, [
            'creado' => "SELECT permiso FROM permisos WHERE idUsuario = :usuario AND modulo = 'mod_reorganizacion' AND accion = 'subir_stock_web'",
            'partida' => "SELECT permiso FROM permisos WHERE idUsuario = 0 AND modulo = 'mod_reorganizacion' AND accion = 'subir_stock_web'",
        ]);

        self::assertSame(['1', '0'], [$reorganizar['consultas']['creado'], $reorganizar['consultas']['partida']]);
        self::assertSame(1, $reorganizar['respuesta']['creados']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_reorganizacion/tareas.php:117-193
     *
     * @group defecto
     * @group permisos
     * @group alto
     *
     * @que-ocurre-hoy Un usuario sin el permiso de reorganizar los permisos los reorganiza por la petición de
     *   fondo, y en la primera vuelta borra los permisos de partida.
     * @que-deberia-ocurrir Que sin ese permiso no se toque ningún permiso.
     * @por-que-ocurre La petición no consulta ningún permiso: solo la pantalla esconde el botón.
     * @como-deberia-funcionar Consultar el permiso de la acción antes de reorganizar.
     */
    public function test_sinPermisoNoSeReorganizanLosPermisos(): void
    {
        $reorganizar = $this->ejecutar(['pulsado' => 'reorganizarPermisosModulos', 'inicial' => ':indice', 'total' => '1'], [
            "INSERT INTO permisos (idUsuario, modulo, permiso) VALUES (:usuario, 'mod_retirado', 1)",
            "UPDATE permisos SET permiso = 0 WHERE idUsuario = :usuario AND modulo = 'mod_reorganizacion'",
        ], true, ['retirado' => "SELECT COUNT(*) FROM permisos WHERE idUsuario = :usuario AND modulo = 'mod_retirado'"]);

        self::assertSame('1', $reorganizar['consultas']['retirado']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_reorganizacion/tareas.php:42-414
     *
     * @group defecto
     * @group validacion
     * @group bajo
     *
     * @que-ocurre-hoy Una orden que la petición no conoce no responde nada.
     * @que-deberia-ocurrir Que responda un error.
     * @por-que-ocurre Las órdenes se atienden en un `switch` sin caso por defecto, y cada una escribe su
     *   propia respuesta.
     * @como-deberia-funcionar Responder un error para cualquier otra orden.
     */
    public function test_unaOrdenDesconocidaRespondeUnError(): void
    {
        $orden = $this->ejecutar(['pulsado' => 'borrarTodo']);

        self::assertArrayHasKey('error', (array) $orden['respuesta']);
    }

    /** @return array{respuesta:mixed, errores:string, ids:array<string, string>, consultas:array<string, ?string>} */
    private function regenerar(string $inicial, string $pagina, array $preparacion, array $consultas): array
    {
        return $this->ejecutar(['pulsado' => 'generastock', 'inicial' => $inicial, 'pagina' => $pagina, 'totalProductos' => '1'], $preparacion, true, $consultas);
    }

    /**
     * Un producto con sus existencias en la tienda 1, y su mínimo y su máximo si se indican.
     *
     * @return list<string>
     */
    private function producto(string $nombre, float $existencias, float $minimo = 0, float $maximo = 0): array
    {
        return [
            "INSERT INTO articulos (articulo_name, estado, fecha_creado, ultimoCoste, tipo, iva, costepromedio, beneficio) VALUES ('{$nombre}', 'Activo', '2020-01-01 00:00:00', 1, 'unidad', 0, 1, 0)",
            "INSERT INTO articulosStocks (idArticulo, idTienda, stockOn, stockMin, stockMax) VALUES (:pN, 1, {$existencias}, {$minimo}, {$maximo})",
        ];
    }

    /**
     * Da de alta la tienda web, activa, en la dirección indicada y con una clave de pruebas. El complemento
     * la lee con su propia conexión, que no ve la transacción del caso: se guarda con otra conexión del
     * producto y se borra al terminar el caso.
     */
    private function tiendaWebEn(string $direccion): void
    {
        $this->conexionPropia()->query("INSERT INTO tiendas (tipoTienda, razonsocial, nif, telefono, estado, NombreComercial, direccion, ano, dominio, key_api) VALUES ('web', 'Tienda web de pruebas', 'X0000000X', '000000000', 'Activo', 'Tienda web de pruebas', 'Sin direccion', '2026', '{$direccion}', 'clave-de-pruebas')");
    }

    /** Borra la tienda web de pruebas, también la que pudo dejar un caso interrumpido. */
    private function borrarLaTiendaWeb(): void
    {
        $this->conexionPropia()->query("DELETE FROM tiendas WHERE tipoTienda = 'web' AND key_api = 'clave-de-pruebas'");
    }

    /** Una conexión del producto fuera de la transacción del caso. */
    private function conexionPropia(): \mysqli
    {
        $this->incluirTPVFox('/clases/ClaseConexion.php');

        return $this->conexionPropia ??= (new \ClaseConexion())->getConexion();
    }

    /**
     * Un producto de la tienda web, con su número en ella, sus existencias en la tienda 1 y su precio.
     *
     * @return list<string>
     */
    private function productoEnLaWeb(string $nombre, int $numeroWeb, float $existencias, float $precio): array
    {
        return [
            ...$this->producto($nombre, $existencias),
            "INSERT INTO articulosPrecios (idArticulo, pvpCiva, pvpSiva, idTienda) VALUES (:pN, {$precio}, {$precio}, 1)",
            "INSERT INTO articulosTiendas (idArticulo, idTienda, idVirtuemart, estado) VALUES (:pN, (SELECT idTienda FROM tiendas WHERE tipoTienda = 'web' AND key_api = 'clave-de-pruebas'), {$numeroWeb}, 'Activo')",
        ];
    }

    /**
     * Levanta en el propio equipo una tienda web que responde lo indicado a cualquier petición y guarda lo
     * que recibe; da su dirección.
     *
     * @param array<string, mixed> $respuesta
     */
    private function tiendaWebQueResponde(array $respuesta): string
    {
        $this->carpetaTiendaWeb = sys_get_temp_dir() . '/tpvfox-tienda-web-' . bin2hex(random_bytes(4));
        mkdir($this->carpetaTiendaWeb);
        file_put_contents($this->carpetaTiendaWeb . '/tienda.php', '<?php file_put_contents(__DIR__ . "/recibido.json", json_encode($_POST)); echo ' . var_export(json_encode($respuesta), true) . ';');
        $puerto = $this->puertoLibre();
        $this->tiendaWeb = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $puerto, $this->carpetaTiendaWeb . '/tienda.php'], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $tuberias);
        for ($intento = 0; $intento < 50 && @fsockopen('127.0.0.1', $puerto) === false; $intento++) {
            usleep(50000);
        }

        return 'http://127.0.0.1:' . $puerto;
    }

    /** @return array<string, string> Lo que la tienda web recibió en la última petición. */
    private function recibidoPorLaTiendaWeb(): array
    {
        return json_decode((string) file_get_contents($this->carpetaTiendaWeb . '/recibido.json'), true);
    }

    /** Un puerto del propio equipo en el que no escucha nadie. */
    private function puertoLibre(): int
    {
        $enlace = stream_socket_server('tcp://127.0.0.1:0');
        $puerto = (int) substr(strrchr(stream_socket_get_name($enlace, false), ':'), 1);
        fclose($enlace);

        return $puerto;
    }

    /** Una entrada de proveedor del producto en la tienda indicada, en un albarán de una línea. */
    private function entrada(string $producto, float $unidades, int $tienda = 1): string
    {
        return "INSERT INTO albprot (Fecha, idTienda, idUsuario, idProveedor, estado, total_siniva, total, Su_numero, formaPago, FechaVencimiento) VALUES ('2026-01-10', {$tienda}, :usuario, 1, 'Guardado', 0, 0, 'entrada', '', '2026-01-10');"
            . " INSERT INTO albprolinea (idalbpro, Numalbpro, idArticulo, cref, ccodbar, cdetalle, ncant, nunidades, costeSiva, iva, nfila, estadoLinea, ref_prov, idpedpro) VALUES (LAST_INSERT_ID(), LAST_INSERT_ID(), {$producto}, '', '', 'entrada', {$unidades}, {$unidades}, 1, 0, 1, 'Activo', '', 0)";
    }

    /** Una venta del producto en la tienda 1, en un ticket de una línea. */
    private function venta(string $producto, float $unidades): string
    {
        return "INSERT INTO ticketst (Numticket, Numtempticket, Fecha, idTienda, idUsuario, estado, formaPago, entregado, total, idCliente) VALUES (1, 0, '2026-02-01 10:00:00', 1, :usuario, 'Cobrado', 'contado', 0, 0, (SELECT MIN(idClientes) FROM clientes));"
            . " INSERT INTO ticketslinea (idticketst, Numticket, idArticulo, cref, ccodbar, cdetalle, ncant, nunidades, precioCiva, iva, nfila, estadoLinea) VALUES (LAST_INSERT_ID(), 1, {$producto}, '', '', 'venta', {$unidades}, {$unidades}, 1, 0, 1, 'Activo')";
    }

    /**
     * Ejecuta la petición de fondo en un PHP aparte y devuelve su respuesta, sus avisos, los números de
     * lo sembrado y lo que responden las consultas del caso al terminar.
     *
     * @param array<string, mixed> $formulario
     * @param list<string> $preparacion
     * @param array<string, string> $consultas
     * @return array{respuesta:mixed, errores:string, ids:array<string, string>, consultas:array<string, ?string>}
     */
    private function ejecutar(array $formulario, array $preparacion = [], bool $conSesion = true, array $consultas = [], int $tienda = 1): array
    {
        $modulo = RUTA_TPVFOX . '/modulos/mod_reorganizacion';
        $sesion = $conSesion ? [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => $this->idUsuario, 'group_id' => 1, 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => $tienda, 'razonsocial' => 'Tienda de pruebas', 'ano' => '2026'],
        ] : [];
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["DOCUMENT_ROOT"] = ""; $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = "/TPVFox/modulos/mod_reorganizacion/tareas.php";'
            . ' chdir(' . var_export($modulo, true) . ');'
            . ' require "./../../inicial.php";'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ($conSesion ? ' new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' $BDTpv->query("UPDATE permisos SET permiso = 1 WHERE idUsuario = ' . $this->idUsuario . ' AND modulo = \'mod_reorganizacion\'");'
            . ' $BDTpv->query("INSERT IGNORE INTO tiendas (idTienda, tipoTienda, razonsocial, nif, telefono, estado, NombreComercial, direccion, ano) VALUES (1, \'principal\', \'Tienda uno\', \'X0000000X\', \'000000000\', \'Activo\', \'Tienda uno\', \'Sin direccion\', \'2026\')");'
            . ' $sembrados = [":usuario" => "' . $this->idUsuario . '", ":tienda" => "' . $tienda . '"]; $cuenta = ["p" => 0];'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->multi_query(strtr($sql, $sembrados)); while ($BDTpv->more_results()) { $BDTpv->next_result(); }'
            . ' if (str_starts_with($sql, "INSERT INTO articulos ")) { $sembrados[":p" . ++$cuenta["p"]] = $sembrados[":pN"] = (string) $BDTpv->query("SELECT MAX(idArticulo) FROM articulos")->fetch_row()[0]; } }'
            . ' include_once "./clases/ClaseReorganizar.php";'
            . ' $sembrados[":indice"] = (string) array_search(' . $this->idUsuario . ', array_map("intval", array_column((new ClaseReorganizar())->obtenerUsuarios(), "id")), true);'
            . ' $_POST = ' . var_export($formulario, true) . ';'
            . ' array_walk_recursive($_POST, function (&$valor) use ($sembrados) { $valor = strtr((string) $valor, $sembrados); });'
            . ($conSesion ? ' $ClasePermisos = $thisTpv->permisos = new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' register_shutdown_function(function () use ($BDTpv, &$sembrados) { $resultados = [];'
            . ' foreach (' . var_export($consultas, true) . ' as $nombre => $sql) { $fila = $BDTpv->query(strtr($sql, $sembrados))->fetch_row(); $resultados[$nombre] = $fila === null ? null : (string) $fila[0]; }'
            . ' echo "\n@@ESTADO@@" . json_encode(["ids" => $sembrados, "consultas" => $resultados]); });'
            . ' include "tareas.php";';

        $errores = (string) tempnam(sys_get_temp_dir(), 'mantenimiento');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$escrito, $estado] = array_pad(explode('@@ESTADO@@', implode("\n", $salida), 2), 2, 'null');
        $estado = json_decode($estado, true) ?? ['ids' => [], 'consultas' => []];
        $ejecucion = ['respuesta' => json_decode(trim($escrito), true), 'errores' => (string) file_get_contents($errores)] + $estado;
        unlink($errores);

        return $ejecucion;
    }
}
