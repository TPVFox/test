<?php
/**
 * La clase de consulta ante el conjunto vacío. Es el punto por el que el módulo
 * entero habla con la base, y varios de sus métodos reciben una lista de productos
 * que puede llegar vacía: una cláusula IN sin elementos no devuelve cero filas, es un
 * error de sintaxis. Aquí se comprueba que ninguno de esos casos llega a consultar y
 * que todos responden con el conjunto vacío, que es lo que quien llama espera.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModReorganizacion\Comprobacion;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class ComprobacionStockConsultaIntegracionTest extends CasoIntegracion
{
    protected bool $compartirConexionConElProducto = true;

    private Siembra $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siembra = new Siembra($this->db);
        $this->incluirTPVFox('/modulos/mod_reorganizacion/clases/ClaseComprobacionStockConsulta.php');
    }

    private function consulta(): \ClaseComprobacionStockConsulta
    {
        return new \ClaseComprobacionStockConsulta();
    }

    public function test_T1_elSaldoDePartidaDeUnaListaVaciaEsElConjuntoVacio(): void
    {
        self::assertSame([], $this->consulta()->stockBase('2026-01-01', '2026-12-31', []));
    }

    public function test_T2_sinProductosOSinFamiliasNoSeConsultaLaExclusion(): void
    {
        $idFamilia = $this->siembra->familia('Familia excluida de la comprobación');
        $consulta = $this->consulta();

        // Las dos ausencias significan lo mismo para el resultado —ningún producto
        // queda excluido— pero son dos condiciones distintas y las dos han de parar
        // antes de construir la cláusula.
        self::assertSame([], $consulta->deFamiliasExcluidas([], [$idFamilia]));
        self::assertSame([], $consulta->deFamiliasExcluidas([1], []));
    }

    public function test_T3_sinProductosNoSePreguntaQuienTeniaExistenciasEnElCierre(): void
    {
        self::assertSame([], $this->consulta()->conStockPositivoEnElCierre([]));
    }

    public function test_T4_sinProductosNoSePreguntaPorRegularizaciones(): void
    {
        self::assertSame([], $this->consulta()->conRegularizacionEntre([], '2026-01-01', '2026-12-31'));
    }

    public function test_T5_elCatalogoDeUnaListaVaciaEsElConjuntoVacio(): void
    {
        self::assertSame([], $this->consulta()->catalogoDe([]));
    }

    public function test_T6_unProductoQueNoEstaEnElCatalogoNoTieneTipo(): void
    {
        // No se supone «unidad» aquí: la ausencia se entrega tal cual y es quien llama
        // —ClaseComprobacionStockMinimo::tipoSupuesto()— quien decide qué hacer con ella.
        self::assertNull($this->consulta()->tipoDeArticulo(999999));
    }

    public function test_T7_unProductoDelCatalogoDeclaraSuTipo(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de peso para la consulta', ['tipo' => 'peso']);

        self::assertSame('peso', $this->consulta()->tipoDeArticulo($idArticulo));
    }

    public function test_T8_unPeriodoSinNingunMovimientoEsElConjuntoVacio(): void
    {
        // Un periodo anterior a cualquier siembra: la lectura no devuelve conjunto y
        // eso ha de llegar como lista vacía, no como algo que quien llama tenga que
        // distinguir de un fallo.
        self::assertSame([], $this->consulta()->movimientosDelPeriodo('1990-01-01', '1990-12-31'));
    }
}
