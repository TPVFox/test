<?php

/**
 * La clase de la reorganización (`modulos/mod_reorganizacion/clases/ClaseReorganizar.php`): las lecturas
 * que usa el cierre de stock anual —las familias con existencias, sus subfamilias, los productos que
 * cerrar de cada una, el nivel de una familia y los datos de un producto para su línea de cierre— y la
 * de una familia por su número.
 *
 * La clase hereda de la base de modelos y consulta con la conexión que comparte el producto, que aquí es
 * la del caso, dentro de su transacción. Las familias, los productos y sus existencias los da de alta el
 * propio caso; el cierre lee la tienda 1, así que las existencias se ponen en ella.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModReorganizacion;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

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
