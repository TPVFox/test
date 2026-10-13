<?php

/**
 * `ClaseProductos::ComprobarEliminar()`, el borrado de un artículo, en lo que toca a las
 * relaciones entre productos: un artículo que figura en alguna no se puede borrar.
 *
 * Varios casos quitan y vuelven a crear las tablas de relaciones, y eso confirma cualquier
 * transacción abierta: por eso no corren dentro de la transacción de la suite y limpian lo
 * que siembran.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModProducto;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\EscenarioRelaciones;
use TPVFox\Test\Siembra\Siembra;

final class ProductoBorradoIntegracionTest extends CasoIntegracion
{
    protected bool $aislarPorTransaccion = false;
    protected bool $compartirConexionConElProducto = true;

    private const TABLAS = ['relacionesLineas', 'relaciones', 'relacionesTipos'];

    private Siembra $siembra;
    private EscenarioRelaciones $escenario;
    private int $tienda;
    private int $despiece;
    /** @var array<string,int> */
    private array $articulo = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_producto/clases/ClaseProductos.php');
        $this->siembra = new Siembra($this->db);
        $this->escenario = new EscenarioRelaciones($this->db);
        $this->escenario->limpiar();

        $this->tienda = (int) $this->db->query('SELECT idTienda FROM tiendas ORDER BY idTienda LIMIT 1')->fetch_assoc()['idTienda'];
        $this->despiece = $this->escenario->tipoDeRelacion('Despiece', '1:N');
        foreach (['pollo', 'pechuga', 'alas'] as $nombre) {
            $this->articulo[$nombre] = $this->siembra->articulo('Prueba de borrado: ' . $nombre, ['estado' => 'Baja']);
        }
    }

    protected function tearDown(): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->aplicarElFicheroDeInstalacion();
        foreach ($this->articulo as $idArticulo) {
            $this->db->query('DELETE FROM articulosPrecios WHERE idArticulo = ' . $idArticulo);
            $this->db->query('DELETE FROM articulosTiendas WHERE idArticulo = ' . $idArticulo);
        }
        $this->escenario->limpiar(array_values($this->articulo));
        parent::tearDown();
    }

    public function test_T1_unArticuloQueNoFiguraEnNingunaRelacionSeBorraComoAntes(): void
    {
        $this->escenario->relacionVigente($this->despiece, [$this->linea('pollo', 'entrada'), $this->linea('pechuga', 'salida')]);

        $respuesta = $this->borrar('alas');

        self::assertSame(0, $respuesta['bandera']);
        self::assertSame([], $respuesta['resultado']);
        self::assertFalse($this->existe('alas'));
    }

    /**
     * @comportamiento El borrado se rechaza antes de borrar nada. Si solo lo impidiera la base,
     *   fallaría en el último paso, con las ocho tablas anteriores del artículo ya vaciadas.
     */
    public function test_T2_unArticuloDeUnaRelacionQueRigeNoSeBorraYQuedaIntacto(): void
    {
        $this->escenario->relacionVigente($this->despiece, [$this->linea('pollo', 'entrada'), $this->linea('pechuga', 'salida')]);
        $this->db->query("INSERT INTO articulosPrecios (idArticulo, pvpCiva, pvpSiva, idTienda) VALUES ({$this->articulo['pechuga']}, 1, 1, {$this->tienda})");

        $respuesta = $this->borrar('pechuga');

        self::assertSame(1, $respuesta['bandera']);
        self::assertStringContainsString('Relaciones entre productos', $respuesta['resultado'][0]['mensaje']);
        self::assertSame(1, (int) $respuesta['resultado'][0]['contador']);
        self::assertTrue($this->existe('pechuga'));
        self::assertSame(1, $this->cuantas('articulosPrecios', 'pechuga'));
    }

    /**
     * @comportamiento Cuenta cualquier versión, no solo la que rige: si se borrase el
     *   artículo, el historial de la relación apuntaría a un producto que ya no existe.
     */
    public function test_T3_unArticuloQueSoloFiguraEnUnaVersionQueYaNoRigeTampocoSeBorra(): void
    {
        $pollo = $this->escenario->relacionVigente($this->despiece, [$this->linea('pollo', 'entrada'), $this->linea('pechuga', 'salida')], ['fecha' => '2026-01-10 09:00:00']);
        $this->db->query("UPDATE relaciones SET estado = 'Sustituido' WHERE id = {$pollo['id']}");
        $this->escenario->versionDeRelacion($pollo['numRelacion'], $this->despiece, '2.0', 'Activo', '2026-03-01 09:00:00', [$this->linea('pollo', 'entrada'), $this->linea('alas', 'salida')]);

        $respuesta = $this->borrar('pechuga');

        self::assertSame(1, $respuesta['bandera']);
        self::assertTrue($this->existe('pechuga'));
    }

    public function test_T4_unArticuloQueSoloFiguraComoLineaEliminadaTampocoSeBorra(): void
    {
        $pollo = $this->escenario->relacionVigente($this->despiece, [$this->linea('pollo', 'entrada'), $this->linea('pechuga', 'salida')]);
        $this->db->query("INSERT INTO relacionesLineas (idRelacion, idArticulo, sentido, cantidad, unidad, estado) VALUES ({$pollo['id']}, {$this->articulo['alas']}, 'salida', 1, 'kg', 'Eliminado')");

        $respuesta = $this->borrar('alas');

        self::assertSame(1, $respuesta['bandera']);
        self::assertTrue($this->existe('alas'));
    }

    /**
     * @comportamiento El código de productos y las tablas de relaciones llegan a una
     *   instalación por caminos separados. Con el código nuevo y sin las tablas, borrar un
     *   artículo tiene que seguir funcionando.
     */
    public function test_T5_sinLaTablaDeRelacionesElBorradoFuncionaComoAntes(): void
    {
        $this->quitarLasTablas();

        $respuesta = $this->borrar('alas');

        self::assertSame(0, $respuesta['bandera']);
        self::assertSame([], $respuesta['resultado']);
        self::assertFalse($this->existe('alas'));
    }

    public function test_T6_siFaltaOtraTablaDelModuloLaComprobacionSeHaceIgual(): void
    {
        $this->escenario->relacionVigente($this->despiece, [$this->linea('pollo', 'entrada'), $this->linea('pechuga', 'salida')]);
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->query('DROP TABLE relacionesTipos');
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');

        $respuesta = $this->borrar('pechuga');

        self::assertSame(1, $respuesta['bandera']);
        self::assertTrue($this->existe('pechuga'));
    }

    public function test_T7_elRechazoPorRelacionesSeSumaALosQueElBorradoYaDaba(): void
    {
        $this->escenario->relacionVigente($this->despiece, [$this->linea('pollo', 'entrada'), $this->linea('pechuga', 'salida')]);
        $this->db->query("INSERT INTO articulosTiendas (idArticulo, idTienda, crefTienda, estado) VALUES ({$this->articulo['pechuga']}, {$this->tienda}, 'PRUEBA-BORRADO', 'Sincronizado')");

        $respuesta = $this->borrar('pechuga');

        $mensajes = implode(' | ', array_column($respuesta['resultado'], 'mensaje'));
        self::assertSame(1, $respuesta['bandera']);
        self::assertCount(2, $respuesta['resultado']);
        self::assertStringContainsString('articulosTiendas', $mensajes);
        self::assertStringContainsString('Relaciones entre productos', $mensajes);
    }

    /** @return array{bandera:int,resultado:list<array<string,mixed>>} */
    private function borrar(string $nombre): array
    {
        return (new \ClaseProductos($this->db))->ComprobarEliminar($this->articulo[$nombre], $this->tienda);
    }

    private function existe(string $nombre): bool
    {
        return $this->cuantas('articulos', $nombre) === 1;
    }

    private function cuantas(string $tabla, string $nombre): int
    {
        return (int) $this->db->query("SELECT COUNT(*) AS n FROM $tabla WHERE idArticulo = {$this->articulo[$nombre]}")->fetch_assoc()['n'];
    }

    private function linea(string $nombre, string $sentido): array
    {
        return ['idArticulo' => $this->articulo[$nombre], 'sentido' => $sentido];
    }

    private function quitarLasTablas(): void
    {
        foreach (self::TABLAS as $tabla) {
            $this->db->query("DROP TABLE IF EXISTS $tabla");
        }
    }

    private function aplicarElFicheroDeInstalacion(): void
    {
        $sql = file_get_contents(RUTA_TPVFOX . '/BD/Update/install_update_relaciones.sql');
        self::assertNotFalse($sql);

        $this->db->multi_query($sql);
        while ($this->db->more_results()) {
            $this->db->next_result();
        }
    }
}
