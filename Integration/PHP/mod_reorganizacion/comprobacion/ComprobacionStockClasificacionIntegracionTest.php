<?php
/**
 * La clasificación combinada con el cálculo real del stock mínimo: un producto sin
 * recepciones en el ejercicio anterior no tiene stock justificado que contrastar y
 * sale no comparable con su condición; uno con lotes reconstruibles se compara de
 * verdad contra la existencia que exigieron los movimientos del vigente.
 *
 * Los cuatro estados salen aquí de una reconstrucción real, y el margen que esa
 * reconstrucción produce en un producto de peso se ve decidiendo entre dos de ellos
 * con el mismo histórico detrás.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModReorganizacion\Comprobacion;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\EscenarioComprobacionStock;
use TPVFox\Test\Siembra\Siembra;

final class ComprobacionStockClasificacionIntegracionTest extends CasoIntegracion
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
        $this->incluirTPVFox('/modulos/mod_reorganizacion/clases/ClaseComprobacionStockClasificacion.php');
    }

    private function contexto(): array
    {
        return [
            'ano' => $this->ano(),
            'idTienda' => $this->siembra->tiendaPorDefecto(),
        ];
    }

    /** Simula la fila tal como llega admitida desde el fichero del vigente. */
    private function filaVigente(int $idArticulo, float $minimoAlcanzado, float $saldoDeApertura): array
    {
        return [
            'idArticulo' => $idArticulo,
            'comparable' => true,
            'minimoAlcanzado' => $minimoAlcanzado,
            'saldoDeApertura' => $saldoDeApertura,
            'marcado' => false,
            'condicionesConocidas' => [],
        ];
    }

    public function test_T1_sinRecepcionesEnElAnteriorSaleNoComparableConSuCondicion(): void
    {
        $proveedorTraspaso = $this->siembra->proveedor('Proveedor de cierre');
        $idArticulo = $this->escenario->E37()['idArticulo'];

        $minimo = new \ClaseComprobacionStockMinimo();
        $filas = $minimo->calcular([$this->filaVigente($idArticulo, -1.0, 2.0)], $this->contexto(), $proveedorTraspaso);

        $clasificacion = new \ClaseComprobacionStockClasificacion();
        $resultado = $clasificacion->clasificar($filas);

        self::assertSame('no_comparable', $resultado[0]['estado']);
        self::assertContains('historico_incompleto', $resultado[0]['condicionesConocidas']);
    }

    public function test_T2_unaReconstruccionRealDentroDelMargenSaleSegura(): void
    {
        $proveedorTraspaso = $this->siembra->proveedor('Proveedor de cierre');
        $proveedorHabitual = $this->siembra->proveedor('Proveedor habitual');
        // El mismo ejemplo con lote negativo intermedio usado en el cálculo del
        // mínimo: el stock justificado reconstruido es 10.
        $idArticulo = $this->escenario->E38($proveedorHabitual)['idArticulo'];

        $minimo = new \ClaseComprobacionStockMinimo();
        // Existencia exigida en el vigente: |-4| + 6 = 10, igual que el justificado.
        $filas = $minimo->calcular([$this->filaVigente($idArticulo, -4.0, 6.0)], $this->contexto(), $proveedorTraspaso);

        $clasificacion = new \ClaseComprobacionStockClasificacion();
        $resultado = $clasificacion->clasificar($filas);

        self::assertSame(10.0, $resultado[0]['existenciaExigida']);
        self::assertSame('seguro', $resultado[0]['estado']);
    }

    public function test_T3_laExigidaPorDebajoDeLaReconstruidaSaleNoSegura(): void
    {
        $proveedorTraspaso = $this->siembra->proveedor('Proveedor de cierre');
        $proveedorHabitual = $this->siembra->proveedor('Proveedor habitual');
        $idArticulo = $this->escenario->E39($proveedorHabitual)['idArticulo'];

        $minimo = new \ClaseComprobacionStockMinimo();
        // Reconstruido 10; exigido |2 - (-3)| = 5. Sobra existencia frente a la que los
        // movimientos del vigente necesitaron, y el producto no se registra por peso, de
        // modo que no hay margen que absorba la diferencia.
        $filas = $minimo->calcular([$this->filaVigente($idArticulo, -3.0, 2.0)], $this->contexto(), $proveedorTraspaso);

        $clasificacion = new \ClaseComprobacionStockClasificacion();
        $resultado = $clasificacion->clasificar($filas);

        self::assertSame(5.0, $resultado[0]['existenciaExigida']);
        self::assertSame('no_seguro', $resultado[0]['estado']);
    }

    public function test_T4_laExigidaPorEncimaDeLaReconstruidaSaleDudosa(): void
    {
        $proveedorTraspaso = $this->siembra->proveedor('Proveedor de cierre');
        $proveedorHabitual = $this->siembra->proveedor('Proveedor habitual');
        $idArticulo = $this->escenario->E40($proveedorHabitual)['idArticulo'];

        $minimo = new \ClaseComprobacionStockMinimo();
        // Reconstruido 10; exigido 25. El histórico del anterior no sostiene lo que los
        // movimientos del vigente necesitaron, que es el estado dudoso.
        $filas = $minimo->calcular([$this->filaVigente($idArticulo, -20.0, 5.0)], $this->contexto(), $proveedorTraspaso);

        $clasificacion = new \ClaseComprobacionStockClasificacion();
        $resultado = $clasificacion->clasificar($filas);

        self::assertSame(25.0, $resultado[0]['existenciaExigida']);
        self::assertSame('dudoso', $resultado[0]['estado']);
    }

    public function test_T5_elMargenDelProductoDePesoDecideEntreSeguroYDudosoConLaMismaReconstruccion(): void
    {
        $proveedorTraspaso = $this->siembra->proveedor('Proveedor de cierre');
        $proveedorHabitual = $this->siembra->proveedor('Proveedor habitual');

        // Dos productos de peso sembrados igual: entran 30 y salen tres veces 5, de modo
        // que los dos reconstruyen 15 con un margen de medio kilo. Lo único que cambia es
        // cuánto se les exige, y es el margen el que decide: cuatro décimas caben y una
        // unidad no. Con un producto que no se registrara por peso los dos saldrían fuera.
        [$dentro, $fuera] = $this->escenario->E41($proveedorHabitual)['idArticulos'];

        $minimo = new \ClaseComprobacionStockMinimo();
        $filas = $minimo->calcular(
            [
                $this->filaVigente($dentro, -5.0, 10.4),
                $this->filaVigente($fuera, -5.0, 11.0),
            ],
            $this->contexto(),
            $proveedorTraspaso
        );

        $clasificacion = new \ClaseComprobacionStockClasificacion();
        $resultado = $clasificacion->clasificar($filas);

        self::assertSame(0.5, $resultado[0]['margen']);
        self::assertSame(15.4, $resultado[0]['existenciaExigida']);
        self::assertSame('seguro', $resultado[0]['estado']);

        self::assertSame(16.0, $resultado[1]['existenciaExigida']);
        self::assertSame('dudoso', $resultado[1]['estado']);
    }
}
