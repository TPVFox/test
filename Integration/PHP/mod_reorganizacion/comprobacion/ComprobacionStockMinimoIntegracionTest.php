<?php
/**
 * Reconstrucción del ejercicio anterior: los dos albaranes de frontera —los del
 * proveedor que declara el fichero admitido, fechados en los bordes del ejercicio—
 * quedan fuera de la ventana, y sus compras corrientes no; sin ninguna recepción no
 * hay lote que reconstruir; el margen solo cuenta salidas de los lotes contados en
 * productos que se registran por peso. Ninguna de las lecturas acota por tienda.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModReorganizacion\Comprobacion;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\EscenarioComprobacionStock;
use TPVFox\Test\Siembra\Siembra;

final class ComprobacionStockMinimoIntegracionTest extends CasoIntegracion
{
    protected string $ejercicio = 'anterior';

    protected bool $compartirConexionConElProducto = true;

    private Siembra $siembra;
    private EscenarioComprobacionStock $escenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siembra = new Siembra($this->db);
        $this->escenario = $this->nuevoEscenario($this->siembra);
        $this->incluirTPVFox('/modulos/mod_reorganizacion/clases/ClaseComprobacionStockMinimo.php');
    }

    private function contexto(array $cambios = []): array
    {
        return array_merge([
            'ano' => $this->ano(),
            'idTienda' => $this->siembra->tiendaPorDefecto(),
        ], $cambios);
    }

    private function filaDe(int $idArticulo, bool $comparable = true): array
    {
        return ['idArticulo' => $idArticulo, 'comparable' => $comparable, 'condicionesConocidas' => []];
    }

    public function test_T1_elStockJustificadoExcluyeLosDosTraspasosYCortaEnElUltimoLoteNegativo(): void
    {
        $proveedorTraspaso = $this->siembra->proveedor('Proveedor de cierre');
        $proveedorHabitual = $this->siembra->proveedor('Proveedor habitual');
        // Con sus dos traspasos —que si no quedaran fuera darían otra magnitud por
        // completo—, un lote ancla que cierra en -1 y un lote contado que cierra en +10.
        $idArticulo = $this->escenario->E29($proveedorTraspaso, $proveedorHabitual)['idArticulo'];

        $comprobacion = new \ClaseComprobacionStockMinimo();
        $resultado = $comprobacion->calcular([$this->filaDe($idArticulo)], $this->contexto(), $proveedorTraspaso);

        self::assertSame(10.0, $resultado[0]['stockJustificado']);
    }

    public function test_T2_sinNingunaRecepcionSaleSinStockJustificadoConLaCondicion(): void
    {
        $proveedorTraspaso = $this->siembra->proveedor('Proveedor de cierre');
        $idArticulo = $this->escenario->E30()['idArticulo'];

        $comprobacion = new \ClaseComprobacionStockMinimo();
        $resultado = $comprobacion->calcular([$this->filaDe($idArticulo)], $this->contexto(), $proveedorTraspaso);

        self::assertNull($resultado[0]['stockJustificado']);
        self::assertContains('historico_incompleto', $resultado[0]['condicionesConocidas']);
    }

    public function test_T3_elMargenDeUnProductoDePesoCuentaLasVentasDelPeriodoReconstruido(): void
    {
        $proveedorTraspaso = $this->siembra->proveedor('Proveedor de cierre');
        $proveedorHabitual = $this->siembra->proveedor('Proveedor habitual');
        $idArticulo = $this->escenario->E31($proveedorHabitual)['idArticulo'];

        $comprobacion = new \ClaseComprobacionStockMinimo();
        $resultado = $comprobacion->calcular([$this->filaDe($idArticulo)], $this->contexto(), $proveedorTraspaso);

        self::assertSame(max(0.5, 0.010 * 3), $resultado[0]['margen']);
    }

    public function test_T4_elMargenDeUnProductoQueNoSeRegistraPorPesoEsSiempreCero(): void
    {
        $proveedorTraspaso = $this->siembra->proveedor('Proveedor de cierre');
        $proveedorHabitual = $this->siembra->proveedor('Proveedor habitual');
        $idArticulo = $this->escenario->E32($proveedorHabitual)['idArticulo'];

        $comprobacion = new \ClaseComprobacionStockMinimo();
        $resultado = $comprobacion->calcular([$this->filaDe($idArticulo)], $this->contexto(), $proveedorTraspaso);

        self::assertSame(0.0, $resultado[0]['margen']);
    }

    public function test_T5_unaSalidaPorAlbaranDeClienteSeRestaDelBalanceDelLote(): void
    {
        $proveedorTraspaso = $this->siembra->proveedor('Proveedor de cierre');
        $proveedorHabitual = $this->siembra->proveedor('Proveedor habitual');
        $idArticulo = $this->escenario->E33($proveedorHabitual)['idArticulo'];

        $comprobacion = new \ClaseComprobacionStockMinimo();
        $resultado = $comprobacion->calcular([$this->filaDe($idArticulo)], $this->contexto(), $proveedorTraspaso);

        self::assertSame(15.0, $resultado[0]['stockJustificado']);
    }

    public function test_T6_unProductoNoComparableNoSeReconstruyeYNoSaleConHistoricoIncompleto(): void
    {
        // No existe en el catálogo de este ejercicio: no hay nada que reconstruir, y lo
        // que le pasa no es que su histórico esté incompleto. Colgarle esa condición
        // pondría en el informe un hallazgo sobre un producto del que no hay hallazgo.
        $proveedorTraspaso = $this->siembra->proveedor('Proveedor de cierre');

        $comprobacion = new \ClaseComprobacionStockMinimo();
        $resultado = $comprobacion->calcular([$this->filaDe(999999, false)], $this->contexto(), $proveedorTraspaso);

        self::assertNull($resultado[0]['stockJustificado']);
        self::assertSame(0.0, $resultado[0]['margen']);
        self::assertSame([], $resultado[0]['condicionesConocidas']);
    }

    public function test_T7_lasComprasCorrientesAlProveedorDelTraspasoSiEntranEnLaVentana(): void
    {
        $proveedorTraspaso = $this->siembra->proveedor('Proveedor de cierre');
        // Con los dos albaranes de frontera, que quedan fuera, y una compra corriente al
        // mismo proveedor en mitad del ejercicio, que sí es movimiento del negocio: es la
        // recepción que abre el único lote. Si la exclusión barriera al proveedor entero,
        // este producto no tendría ninguna recepción y saldría sin stock justificado.
        $idArticulo = $this->escenario->E34($proveedorTraspaso)['idArticulo'];

        $comprobacion = new \ClaseComprobacionStockMinimo();
        $resultado = $comprobacion->calcular([$this->filaDe($idArticulo)], $this->contexto(), $proveedorTraspaso);

        self::assertSame(15.0, $resultado[0]['stockJustificado']);
        self::assertSame([], $resultado[0]['condicionesConocidas']);
    }

    public function test_T8_laReconstruccionCuentaLosMovimientosDeCualquierTienda(): void
    {
        $proveedorTraspaso = $this->siembra->proveedor('Proveedor de cierre');
        $proveedorHabitual = $this->siembra->proveedor('Proveedor habitual');

        // Lo que se reconstruye aquí se compara después contra la existencia exigida del
        // ejercicio vigente, que no acota por tienda. Acotar solo este lado dejaría la
        // resta entre ambos sin significado: saldría 30 en vez de 18.
        $sembrado = $this->escenario->E35($proveedorHabitual);
        $idArticulo = $sembrado['idArticulo'];
        $principal = $sembrado['idTiendaPrincipal'];

        $comprobacion = new \ClaseComprobacionStockMinimo();
        $resultado = $comprobacion->calcular(
            [$this->filaDe($idArticulo)],
            $this->contexto(['idTienda' => $principal]),
            $proveedorTraspaso
        );

        self::assertSame(18.0, $resultado[0]['stockJustificado']);
    }

    public function test_T9_lasCondicionesQueLlegaronNoSeMezclanConLasDeLaReconstruccion(): void
    {
        $proveedorTraspaso = $this->siembra->proveedor('Proveedor de cierre');
        // Sin recepciones, la reconstrucción produce su propia condición. La fila
        // llega además con una que se marcó en el otro ejercicio y con los umbrales
        // de allí: juntarlas deja al lector con «periodo no consolidado» sin poder
        // saber de qué periodo habla, y es sobre este sobre el que se decide si se
        // corrigen existencias.
        $idArticulo = $this->escenario->E36()['idArticulo'];

        $fila = $this->filaDe($idArticulo);
        $fila['condicionesConocidas'] = ['periodo_no_consolidado', 'familia_excluida'];

        $comprobacion = new \ClaseComprobacionStockMinimo();
        $resultado = $comprobacion->calcular([$fila], $this->contexto(), $proveedorTraspaso);

        self::assertSame(['historico_incompleto'], $resultado[0]['condicionesConocidas']);
        self::assertSame(['periodo_no_consolidado', 'familia_excluida'], $resultado[0]['condicionesDelVigente']);
    }

    public function test_T10_elProductoQueAquiNoExisteConservaLoQueTraiaEnSuColumna(): void
    {
        $proveedorTraspaso = $this->siembra->proveedor('Proveedor de cierre');

        // Un producto que no está en el catálogo de este ejercicio sale sin pasar por
        // la reconstrucción, y ese atajo es donde una lista fusionada se conservaría
        // entera bajo el rótulo equivocado: nada de lo que trae es de aquí.
        $fila = $this->filaDe(999999, false);
        $fila['condicionesConocidas'] = ['nunca_incluido_en_cierre'];

        $comprobacion = new \ClaseComprobacionStockMinimo();
        $resultado = $comprobacion->calcular([$fila], $this->contexto(), $proveedorTraspaso);

        self::assertSame([], $resultado[0]['condicionesConocidas']);
        self::assertSame(['nunca_incluido_en_cierre'], $resultado[0]['condicionesDelVigente']);
    }
}
