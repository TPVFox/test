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

    public function test_T9_losTresUmbralesDelMargenDePesajeGobiernanLaSenalDelComponenteConsumido(): void
    {
        // El componente que resuelve las existencias negativas lleva sus umbrales con
        // valor por defecto en la firma: si no se le pasan, aplica los suyos y el
        // resultado depende de un número que nadie fijó. Aquí se ejecuta cuatro veces
        // sobre el mismo producto moviendo un umbral cada vez, de modo que cada cambio
        // de señal solo puede venir del umbral que se movió.
        //
        // Un producto de 0,7 de entrada y 1,0 de venta cierra en -0,3: negativo, con
        // parte decimal y de magnitud pequeña, que es la combinación sobre la que actúan
        // los tres.
        $idArticulo = $this->siembra->articulo('Producto con negativo decimal');
        $this->siembra->entradaProveedor($idArticulo, 0.7, '2026-03-01');
        $this->siembra->ventaTicket($idArticulo, 1.0, '2026-03-02');

        // Con los tres holgados, el componente atribuye el negativo al redondeo del
        // pesaje y rebaja la señal.
        self::assertSame('MEDIA', $this->severidadDe($idArticulo, []));

        // Si la parte decimal deja de contar como decimal, ya no lo atribuye.
        self::assertSame('CRITICA', $this->severidadDe($idArticulo, ['umbralFraccionado' => 0.5]));

        // Si la magnitud admisible baja por debajo del negativo, tampoco.
        self::assertSame('CRITICA', $this->severidadDe($idArticulo, ['umbralMagnitud' => 0.1]));

        // Y el margen por operación de pesaje lo revisa otra vez al final, con el número
        // de ventas: estrechado, vuelve a descartarlo.
        self::assertSame('CRITICA', $this->severidadDe($idArticulo, ['umbralPorVenta' => 0.010]));
    }

    public function test_T10_laVentanaDeRecepcionGobiernaElTimingDelComponenteConsumido(): void
    {
        // El cuarto umbral no cambia la señal sino la ventana en que el componente busca
        // una recepción que explique el negativo puntual. Un producto que toca -3 el día 2
        // y se repone el 5 queda dentro o fuera según cuántos días abarque esa ventana.
        $idArticulo = $this->siembra->articulo('Producto que toca negativo y se repone tres dias despues');
        $this->siembra->entradaProveedor($idArticulo, 5.0, '2026-03-01');
        $this->siembra->ventaTicket($idArticulo, 8.0, '2026-03-02');
        $this->siembra->entradaProveedor($idArticulo, 5.0, '2026-03-05');

        $conVentanaCorta = $this->incidenciaDe($idArticulo, ['timingVentanaDias' => 1]);
        $conVentanaLarga = $this->incidenciaDe($idArticulo, ['timingVentanaDias' => 5]);

        self::assertNotNull($conVentanaCorta, 'El producto que se repone tiene que dar incidencia');
        self::assertSame('Desajuste Puntual de Stock', $conVentanaCorta['tipo']);
        self::assertFalse($conVentanaCorta['timing_proximo']);
        self::assertTrue($conVentanaLarga['timing_proximo']);
    }

    /** Severidad que el componente consumido asigna al producto con los umbrales dados. */
    private function severidadDe(int $idArticulo, array $cambios): ?string
    {
        $incidencia = $this->incidenciaDe($idArticulo, $cambios);
        return $incidencia === null ? null : $incidencia['severidad'];
    }

    /**
     * Incidencia que el componente consumido emite sobre el producto con los umbrales
     * dados. La base parte del margen por operación holgado a propósito: ese umbral se
     * aplica el último y, con el valor de la instalación, taparía a los otros dos.
     */
    private function incidenciaDe(int $idArticulo, array $cambios): ?array
    {
        $umbrales = array_merge([
            'umbralFraccionado' => 0.05,
            'umbralMagnitud' => 0.5,
            'umbralPorVenta' => 1.0,
            'timingVentanaDias' => 1,
        ], $cambios);

        $incidencias = $this->consulta()->incidenciasC1(
            '2026-01-02',
            '2026-06-30',
            '2025-12-31',
            '2026-01-01',
            [],
            $umbrales
        );

        foreach ($incidencias as $incidencia) {
            if ((int) $incidencia['idArticulo'] === $idArticulo) {
                return $incidencia;
            }
        }
        return null;
    }
}
