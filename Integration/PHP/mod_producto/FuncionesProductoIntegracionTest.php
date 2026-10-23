<?php

/**
 * Las funciones de `modulos/mod_producto/funciones.php` que consultan la base: el árbol de familias
 * del filtro del listado, que se lee con su propia consulta, y la revisión de los recálculos de precio
 * que ya tienen otro posterior, con la clase de artículos de `clases/articulos.php`.
 *
 * Otros módulos declaran funciones con el mismo nombre que las de este fichero: cada caso corre en su
 * propio proceso, con la conexión del caso, dentro de su transacción.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModProducto;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class FuncionesProductoIntegracionTest extends CasoIntegracion
{
    private Siembra $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_producto/funciones.php');
        $this->incluirTPVFox('/clases/articulos.php');
        $this->siembra = new Siembra($this->db);
    }

    /**
     * @comportamiento El árbol de familias del filtro lista cada familia tras su padre, con un guion por
     *   nivel, su ruta como título, su nivel y el número de hijas del padre.
     */
    public function test_elArbolDeFamiliasDelFiltro(): void
    {
        $frutas = $this->siembra->familia('Frutas');
        $secas = $this->siembra->familia('Secas', $frutas);
        $nueces = $this->siembra->familia('Nueces', $secas);

        $arbol = \selectFamilias(0, '', [], $this->db);
        $porId = array_column($arbol, null, 'id');

        self::assertSame('Frutas', $porId[(string) $frutas]['name']);
        self::assertSame('-Secas', $porId[(string) $secas]['name']);
        self::assertSame('--Nueces', $porId[(string) $nueces]['name']);
        self::assertSame('Frutas&#187;Secas&#187;Nueces', $porId[(string) $nueces]['title']);
        self::assertSame(2, $porId[(string) $nueces]['descendente']);
        self::assertSame(1, $porId[(string) $frutas]['hijos']);
        self::assertSame(array_search((string) $frutas, array_column($arbol, 'id'), true) + 1, array_search((string) $secas, array_column($arbol, 'id'), true));
    }

    /**
     * @comportamiento Un recálculo de precio que ya tiene otro posterior en el histórico se marca «Sin
     *   revisar»; uno sin posteriores no cambia.
     */
    public function test_unRecalculoConOtroPosteriorSeMarcaSinRevisar(): void
    {
        $id = $this->siembra->articulo('Kiwi');
        $anterior = $this->siembra->historicoPrecio($id, 1, 2, 'albaran', 7, ['fecha' => '2026-02-01', 'estado' => 'Pendiente']);
        $this->siembra->historicoPrecio($id, 2, 3, 'albaran', 8, ['fecha' => '2026-03-01', 'estado' => 'Pendiente']);
        $solo = $this->siembra->articulo('Pera');
        $unico = $this->siembra->historicoPrecio($solo, 1, 2, 'albaran', 9, ['fecha' => '2026-02-01', 'estado' => 'Pendiente']);

        $productos = \comprobarRecalculosSuperiores([
            ['id' => $anterior, 'idArticulo' => $id, 'Fecha_Creacion' => '2026-02-01 00:00:00', 'estado' => 'Pendiente'],
            ['id' => $unico, 'idArticulo' => $solo, 'Fecha_Creacion' => '2026-02-01 00:00:00', 'estado' => 'Pendiente'],
        ], new \Articulos($this->db));

        self::assertSame('Sin revisar', $productos[0]['estado']);
        self::assertSame('Pendiente', $productos[1]['estado']);
        self::assertSame('Sin revisar', $this->unValor("SELECT estado FROM historico_precios WHERE id = {$anterior}"));
        self::assertSame('Pendiente', $this->unValor("SELECT estado FROM historico_precios WHERE id = {$unico}"));
    }

    private function unValor(string $sql): ?string
    {
        $fila = $this->db->query($sql)->fetch_row();

        return $fila === null ? null : (string) $fila[0];
    }
}
