<?php

/**
 * La clase de la reorganización (`modulos/mod_reorganizacion/clases/ClaseReorganizar.php`): las lecturas
 * que usa el cierre de stock anual —las familias con existencias, sus subfamilias, los productos que
 * cerrar de cada una, el nivel de una familia y los datos de un producto para su línea de cierre—, la
 * de una familia por su número, y las del mantenimiento: contar los productos, leer los usuarios, leer
 * los productos de la tienda web y obtener su complemento.
 *
 * La clase hereda de la base de modelos y consulta con la conexión que comparte el producto, que aquí es
 * la del caso, dentro de su transacción. Las familias, los productos, sus existencias y la tienda web los
 * da de alta el propio caso; el cierre lee la tienda 1, así que las existencias se ponen en ella. Ningún
 * caso se conecta a una tienda web. Crear la clase carga los complementos del módulo, y el de la tienda
 * web declara una clase que otro complemento repite: cada caso corre en su propio proceso.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModReorganizacion;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ClaseReorganizarIntegracionTest extends CasoIntegracion
{
    protected bool $compartirConexionConElProducto = true;

    private Siembra $siembra;

    /** @var array<string, int> */
    private array $familias = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_reorganizacion/clases/ClaseReorganizar.php');
        $this->siembra = new Siembra($this->db);
        $this->siembra->tiendaConId(1, $this->ano());
        $this->familias['frutas'] = $this->siembra->familia('Frutas');
        $this->familias['citricos'] = $this->siembra->familia('Cítricos', $this->familias['frutas']);
        $this->familias['naranjas'] = $this->siembra->familia('Naranjas', $this->familias['citricos']);
        $this->familias['lacteos'] = $this->siembra->familia('Lácteos');
    }

    /**
     * @comportamiento Contar las familias da las de primer nivel con algún producto con existencias en la
     *   tienda 1, en orden, sin las de los productos de las familias excluidas.
     */
    public function test_contarFamiliasDaLasDePrimerNivelConExistencias(): void
    {
        $this->producto('Naranja', 'naranjas', 5);
        $this->producto('Leche', 'lacteos', 2);
        $this->producto('Agua', 'lacteos', 0);
        $reorganizar = new \ClaseReorganizar();

        self::assertSame([(string) $this->familias['frutas'], (string) $this->familias['lacteos']], $this->propias($reorganizar->contarFamilias()));
        self::assertSame([(string) $this->familias['frutas']], $this->propias($reorganizar->contarFamilias([$this->familias['lacteos']])));
    }

    /**
     * @comportamiento Las subfamilias de una familia se cuentan por su segundo nivel, de la que más
     *   productos con existencias tiene a la que menos; los productos de la propia familia van sin
     *   subfamilia.
     */
    public function test_lasSubfamiliasSeCuentanDeMayorAMenor(): void
    {
        $otra = $this->siembra->familia('Pomelos', $this->familias['frutas']);
        $this->producto('Naranja', 'naranjas', 5);
        $this->producto('Limón', 'citricos', 3);
        $this->producto('Manzana', 'frutas', 4);
        $this->productoEn('Pomelo', $otra, 1);

        $subfamilias = (new \ClaseReorganizar())->contarProductosSubfamilias($this->familias['frutas']);

        self::assertSame([[(string) $this->familias['citricos'], '2']], array_slice(array_map(static fn ($fila) => [(string) $fila['idN2'], (string) $fila['total_articulos']], $subfamilias), 0, 1));
        self::assertCount(3, $subfamilias);
        self::assertContains([null, '1'], array_map(static fn ($fila) => [$fila['idN2'], (string) $fila['total_articulos']], $subfamilias));
    }

    /**
     * @comportamiento Los productos que cerrar se leen con sus existencias: los de una familia de primer
     *   nivel, sin las subfamilias que se cierran aparte; los de una subfamilia; los de una familia
     *   concreta; y, al final, los que quedan con existencias.
     */
    public function test_losProductosQueCerrarSeLeenPorFamiliaSubfamiliaOPendientes(): void
    {
        $naranja = $this->producto('Naranja', 'naranjas', 5);
        $limon = $this->producto('Limón', 'citricos', 3);
        $leche = $this->producto('Leche', 'lacteos', 2);
        $reorganizar = new \ClaseReorganizar();

        self::assertSame([$naranja, $limon], $this->numeros($reorganizar->obtenerProductosPorFamilia($this->familias['frutas'])));
        self::assertSame([], $this->numeros($reorganizar->obtenerProductosPorFamilia($this->familias['frutas'], [$this->familias['citricos']])));
        self::assertSame([$naranja, $limon], $this->numeros($reorganizar->obtenerProductosPorSubfamilia($this->familias['citricos'])));
        self::assertSame([$naranja], $this->numeros($reorganizar->obtenerProductosPorIdFamilia($this->familias['naranjas'])));
        self::assertSame([$naranja, $limon, $leche], array_values(array_intersect($this->numeros($reorganizar->obtenerProductosPendientesCierre()), [$naranja, $limon, $leche])));
        self::assertSame([$naranja, $limon], array_values(array_intersect($this->numeros($reorganizar->obtenerProductosPendientesCierre([$this->familias['lacteos']])), [$naranja, $limon, $leche])));
        self::assertSame('5.000000', (string) $reorganizar->obtenerProductosPorIdFamilia($this->familias['naranjas'])[0]['stockOn']);
    }

    /** @comportamiento El nivel de una familia es el de su jerarquía; el de una que no existe, ninguno. */
    public function test_elNivelDeUnaFamilia(): void
    {
        $reorganizar = new \ClaseReorganizar();

        self::assertSame(['1', '2', '3'], [(string) $reorganizar->obtenerNivelFamilia($this->familias['frutas']), (string) $reorganizar->obtenerNivelFamilia($this->familias['citricos']), (string) $reorganizar->obtenerNivelFamilia($this->familias['naranjas'])]);
        self::assertNull($reorganizar->obtenerNivelFamilia(999999));
    }

    /**
     * @comportamiento Los datos de un producto para su línea de cierre son su código de barras, si lo
     *   tiene, su nombre, su último coste y su IVA; los de uno que no existe, ninguno.
     */
    public function test_losDatosDeUnProductoParaSuLineaDeCierre(): void
    {
        $naranja = $this->siembra->articulo('Naranja', ['ultimoCoste' => 2.5, 'iva' => 10]);
        $this->db->query("INSERT INTO articulosCodigoBarras (idArticulo, codBarras) VALUES ({$naranja}, '8400000000001')");
        $limon = $this->siembra->articulo('Limón', ['ultimoCoste' => 1.0, 'iva' => 4]);
        $reorganizar = new \ClaseReorganizar();

        $datos = $reorganizar->articulosAlbaranCierre($naranja);
        self::assertSame(['8400000000001', 'Naranja', '10.00'], [$datos['ccodbar'], $datos['cdetalle'], (string) $datos['iva']]);
        self::assertSame(2.5, (float) $datos['costSiva']);
        self::assertNull($reorganizar->articulosAlbaranCierre($limon)['ccodbar']);
        self::assertSame([], $reorganizar->articulosAlbaranCierre(999999));
    }

    /** @comportamiento Una familia se lee por su número con todos sus datos. */
    public function test_unaFamiliaSeLeePorSuNumero(): void
    {
        $familia = (new \ClaseReorganizar())->buscarFamiliaId($this->familias['citricos']);

        self::assertSame(['Cítricos', (string) $this->familias['frutas']], [$familia['familiaNombre'], (string) $familia['familiaPadre']]);
    }

    /**
     * @comportamiento Contar los productos da los del catálogo; los de la tienda web, los que tienen ficha en
     *   ella.
     */
    public function test_contarLosProductosDelCatalogoYDeLaTiendaWeb(): void
    {
        $web = $this->siembra->tienda($this->ano(), 'web');
        $producto = $this->siembra->articulo('Naranja');
        $this->db->query("INSERT INTO articulosTiendas (idArticulo, idTienda, idVirtuemart, estado) VALUES ({$producto}, {$web}, 77, 'Activo')");
        $reorganizar = new \ClaseReorganizar();
        $catalogo = (int) $this->db->query('SELECT COUNT(*) FROM articulos')->fetch_row()[0];

        self::assertSame($catalogo, (int) $reorganizar->contar());
        $reorganizar->setIdTiendaWeb($web);
        self::assertSame(1, (int) $reorganizar->contar('web'));
    }

    /** @comportamiento Los usuarios se leen todos, sin su contraseña. */
    public function test_losUsuariosSeLeenSinSuContrasena(): void
    {
        $usuarios = (new \ClaseReorganizar())->obtenerUsuarios();
        $idUsuario = $this->siembra->usuarioPorDefecto();

        self::assertContains((string) $idUsuario, array_map('strval', array_column($usuarios, 'id')));
        self::assertSame(['id', 'username', 'group_id', 'estado', 'nombre'], array_keys($usuarios[0]));
    }

    /**
     * @comportamiento Los productos de la tienda web se leen por tandas con su número en la tienda web, sus
     *   existencias y su precio sin IVA.
     */
    public function test_losProductosDeLaTiendaWebSeLeenConSuExistenciaYSuPrecio(): void
    {
        [$web, $producto] = $this->productoEnLaWeb();
        $reorganizar = new \ClaseReorganizar();
        $reorganizar->setIdTiendaWeb($web);

        $fila = array_values(array_filter($reorganizar->obtenerIdsWeb(0, 1000)['datos'], static fn ($fila) => (int) $fila['idArticulo'] === $producto))[0];

        self::assertSame(['77', 4.0, 2.5], [(string) $fila['idVirtuemart'], (float) $fila['stockOn'], (float) $fila['pvpSiva']]);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_reorganizacion/clases/ClaseReorganizar.php:55-72
     *
     * @group defecto
     * @group existencias
     * @group medio
     *
     * @que-ocurre-hoy Si un producto de la tienda web tiene existencias en dos tiendas, leer los productos
     *   de la tienda web termina con un error de la base, y no se sube ninguno.
     * @que-deberia-ocurrir Que el producto se lea una vez, con las existencias de la tienda que se envían a
     *   la web.
     * @por-que-ocurre La existencia y el precio se leen con una subconsulta que no filtra la tienda, y con
     *   dos filas no se puede tomar ninguna.
     * @como-deberia-funcionar Leer la existencia y el precio de la tienda que corresponde.
     */
    public function test_unProductoConExistenciasEnDosTiendasSeLeeParaLaTiendaWeb(): void
    {
        [$web, $producto] = $this->productoEnLaWeb();
        $this->siembra->existenciaRegistrada($producto, 9, $this->siembra->tiendaPorDefecto());
        $reorganizar = new \ClaseReorganizar();
        $reorganizar->setIdTiendaWeb($web);

        try {
            $filas = $reorganizar->obtenerIdsWeb(0, 1000)['datos'];
        } catch (\Throwable $error) {
            self::fail('La lectura termina con un error: ' . get_class($error));
        }

        self::assertCount(1, array_filter($filas, static fn ($fila) => (int) $fila['idArticulo'] === $producto));
    }

    /** @comportamiento El complemento de la tienda web se obtiene por el nombre de su clase; otro, no. */
    public function test_elComplementoDeLaTiendaWebSeObtienePorSuNombre(): void
    {
        $reorganizar = new \ClaseReorganizar();

        self::assertIsObject($reorganizar->SetPlugin('ClaseVirtuemart'));
        self::assertFalse($reorganizar->SetPlugin('ClaseQueNoExiste'));
    }

    /**
     * Un producto con ficha en una tienda web, número 77 en ella, existencias 4 en la tienda 1 y precio
     * sin IVA 2,5 en la tienda principal.
     *
     * @return array{0:int, 1:int}
     */
    private function productoEnLaWeb(): array
    {
        $web = $this->siembra->tienda($this->ano(), 'web');
        $producto = $this->siembra->articulo('Naranja');
        $this->db->query("INSERT INTO articulosTiendas (idArticulo, idTienda, idVirtuemart, estado) VALUES ({$producto}, {$web}, 77, 'Activo')");
        $this->siembra->existenciaRegistrada($producto, 4, 1);
        $this->siembra->precioYTienda($producto, 2.75, 2.5);

        return [$web, $producto];
    }

    /** Un producto de la familia indicada por su nombre, con sus existencias en la tienda 1. */
    private function producto(string $nombre, string $familia, float $existencias): int
    {
        return $this->productoEn($nombre, $this->familias[$familia], $existencias);
    }

    private function productoEn(string $nombre, int $familia, float $existencias): int
    {
        $id = $this->siembra->articulo($nombre, ['familia' => $familia]);
        $this->siembra->existenciaRegistrada($id, $existencias, 1);

        return $id;
    }

    /**
     * Las familias de la lista que son de este caso: la base de pruebas puede tener otras.
     *
     * @param list<string|int> $familias
     * @return list<string>
     */
    private function propias(array $familias): array
    {
        return array_values(array_intersect(array_map('strval', $familias), array_map('strval', $this->familias)));
    }

    /**
     * @param list<array{idArticulo:mixed}> $filas
     * @return list<int>
     */
    private function numeros(array $filas): array
    {
        $numeros = array_map(static fn ($fila) => (int) $fila['idArticulo'], $filas);
        sort($numeros);

        return $numeros;
    }
}
