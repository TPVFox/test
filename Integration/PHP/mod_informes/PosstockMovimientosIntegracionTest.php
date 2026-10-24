<?php

/**
 * Los movimientos y el stock con los que trabaja POSStock: los de un periodo, el stock base, el
 * stock previo a cada entrada, el stock rebobinado a una fecha y el reconstruido desde la última
 * entrada, los filtros de familias, productos y proveedores, el plazo medio entre albaranes de un
 * proveedor y los nombres de los productos (`modulos/mod_informes/clases/ClasePosstock.php` y
 * `PosstockQueryRepository.php`).
 *
 * Las clases reciben la conexión de la prueba, de modo que leen lo sembrado en su transacción, que no
 * se confirma. Los movimientos se siembran en 2019, un ejercicio sin datos en la base, y las consultas
 * se acotan a los productos sembrados.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModInformes;

use ClasePosstock;
use PosstockQueryRepository;
use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class PosstockMovimientosIntegracionTest extends CasoIntegracion
{
    private Siembra $siembra;

    private ClasePosstock $posstock;

    private PosstockQueryRepository $repositorio;

    protected function setUp(): void
    {
        parent::setUp();
        require_once RUTA_TPVFOX . '/modulos/mod_informes/clases/ClasePosstock.php';
        $this->siembra = new Siembra($this->db);
        $this->posstock = new ClasePosstock($this->db);
        $this->repositorio = new PosstockQueryRepository($this->db);
    }

    /** @return array<string, float> Las unidades de cada movimiento, por tipo y fecha. */
    private function movimientos(array $filas): array
    {
        $resumen = [];
        foreach ($filas as $fila) {
            $resumen[$fila['tipo_movimiento'] . ' ' . $fila['fecha']] = (float) $fila['nunidades'];
        }

        return $resumen;
    }

    /**
     * @comportamiento Los movimientos del periodo son las entradas de proveedor guardadas o facturadas, los
     *   tickets cerrados y los albaranes de cliente guardados o procesados, con sus líneas activas, sumados
     *   por tipo, producto y día; los borradores, los tickets abiertos, las líneas eliminadas y lo de fuera
     *   del periodo no cuentan.
     */
    public function test_losMovimientosDelPeriodo(): void
    {
        $id = $this->siembra->articulo('Zz Manzana');
        $this->siembra->entradaProveedor($id, 10, '2019-03-04');
        $this->siembra->entradaProveedor($id, 6, '2019-03-05', ['estado' => 'Facturado']);
        $this->siembra->entradaProveedor($id, 50, '2019-03-05', ['estado' => 'Sin Guardar']);
        $this->siembra->ventaTicket($id, 2, '2019-03-06');
        $this->siembra->ventaTicket($id, 1, '2019-03-06');
        $this->siembra->ventaTicket($id, 9, '2019-03-06', ['estado' => 'Abierto']);
        $this->siembra->ventaTicket($id, 9, '2019-03-07', ['estadoLinea' => 'Eliminado']);
        $this->siembra->ventaAlbaranCliente($id, 4, '2019-03-08');
        $this->siembra->ventaAlbaranCliente($id, 3, '2019-03-09', ['estado' => 'Procesado']);
        $this->siembra->ventaTicket($id, 7, '2019-04-01');

        $movimientos = $this->posstock->getMovimientosPeriodo('2019-03-01', '2019-03-31', [], [], [$id]);

        self::assertSame([
            'entrada_proveedor 2019-03-04' => 10.0,
            'entrada_proveedor 2019-03-05' => 6.0,
            'salida_ticket 2019-03-06' => 3.0,
            'salida_albcli 2019-03-08' => 4.0,
            'salida_albcli 2019-03-09' => 3.0,
        ], $this->movimientos($movimientos));
    }

    /**
     * @comportamiento El stock base suma las entradas y resta las salidas del rango, con la última compra y
     *   la última venta; cuenta también las entradas exportadas e importadas. Sin productos, vacío.
     */
    public function test_elStockBase(): void
    {
        $id = $this->siembra->articulo('Zz Pera');
        $this->siembra->entradaProveedor($id, 20, '2019-01-03');
        $this->siembra->entradaProveedor($id, 5, '2019-01-10', ['estado' => 'Importado']);
        $this->siembra->entradaProveedor($id, 4, '2019-01-12', ['estado' => 'Exportado']);
        $this->siembra->ventaTicket($id, 7, '2019-01-15');
        $this->siembra->ventaAlbaranCliente($id, 2, '2019-01-20');

        self::assertSame([$id => ['saldo_acumulado' => 20.0, 'ultima_compra' => '2019-01-12', 'ultima_venta' => '2019-01-20']], $this->posstock->getStockBase([$id], '2019-01-01', '2019-01-31'));
        self::assertSame([], $this->posstock->getStockBase([], '2019-01-01', '2019-01-31'));
    }

    /**
     * @comportamiento El stock previo de cada entrada es el saldo al empezar su día —el base más lo de los
     *   días anteriores—, compartido por las entradas del mismo día; y el stock tras el último albarán, el
     *   del final del día de la última entrada.
     */
    public function test_elStockPrevioACadaEntrada(): void
    {
        $id = $this->siembra->articulo('Zz Ciruela');
        $this->siembra->entradaProveedor($id, 10, '2019-03-04');
        $this->siembra->ventaTicket($id, 4, '2019-03-05');
        $this->siembra->entradaProveedor($id, 6, '2019-03-06');
        $this->siembra->ventaTicket($id, 1, '2019-03-06');
        $this->siembra->ventaTicket($id, 2, '2019-03-08');

        $movimientos = $this->posstock->getMovimientosPeriodo('2019-03-01', '2019-03-31', [], [], [$id]);
        $previos = $this->posstock->calcularStockPrevio($movimientos, [$id => ['saldo_acumulado' => 3.0]]);

        self::assertSame([['2019-03-04', 3.0, 14.0], ['2019-03-06', 9.0, 14.0]], array_map(static fn($e) => [$e['fecha'], $e['stock_previo'], $e['stock_tras_ultimo_albaran']], $previos));
        self::assertSame([], $this->posstock->calcularStockPrevio([['idArticulo' => $id, 'tipo_movimiento' => 'salida_ticket', 'fecha' => '2019-03-05', 'nunidades' => 1]], []));
    }

    /**
     * @comportamiento El filtro de familias incluye la familia con sus hijas y excluye la familia con sus
     *   hijas; el de productos deja solo los pedidos. Una familia que no está en la jerarquía se usa tal cual.
     */
    public function test_losFiltrosDeFamiliasYProductos(): void
    {
        $padre = $this->siembra->familia('Zz Fruta');
        $hija = $this->siembra->familia('Zz Fruta de hueso', $padre);
        $otra = $this->siembra->familia('Zz Bebida');
        $melocoton = $this->siembra->articulo('Zz Melocotón', ['familia' => $hija]);
        $agua = $this->siembra->articulo('Zz Agua', ['familia' => $otra]);
        foreach ([$melocoton, $agua] as $id) {
            $this->siembra->ventaTicket($id, 1, '2019-03-06');
        }
        $ids = fn(array $filas) => array_values(array_unique(array_map(static fn($f) => (int) $f['idArticulo'], $filas)));

        self::assertSame([$melocoton], $ids($this->posstock->getMovimientosPeriodo('2019-03-01', '2019-03-31', [$padre], [], [$melocoton, $agua])));
        self::assertSame([$agua], $ids($this->posstock->getMovimientosPeriodo('2019-03-01', '2019-03-31', [], [$padre], [$melocoton, $agua])));
        self::assertSame([$agua], $ids($this->posstock->getMovimientosPeriodo('2019-03-01', '2019-03-31', [], [], [$agua])));
        self::assertEqualsCanonicalizing([(string) $padre, (string) $hija], explode(',', $this->repositorio->expandirFamilias([$padre])));
        self::assertSame('999999999', $this->repositorio->expandirFamilias([999999999]));
    }

    /**
     * @comportamiento El stock rebobinado a una fecha es el registrado menos lo que entró y más lo que salió
     *   después; un producto sin stock registrado parte de cero.
     */
    public function test_elStockRebobinado(): void
    {
        $conStock = $this->siembra->articulo('Zz Limón');
        $sinStock = $this->siembra->articulo('Zz Lima');
        $this->siembra->existenciaRegistrada($conStock, 12);
        $this->siembra->entradaProveedor($conStock, 5, '2019-04-02');
        $this->siembra->ventaTicket($conStock, 3, '2019-04-03');
        $this->siembra->ventaTicket($conStock, 1, '2019-03-30');
        $this->siembra->ventaTicket($sinStock, 2, '2019-04-05');

        $filas = $this->repositorio->queryStockRebobinado($conStock . ',' . $sinStock, '2019-03-31');
        $stock = array_column($filas, 'stock_en_periodo', 'idArticulo');

        self::assertEquals([$conStock => 10, $sinStock => 2], $stock);
    }

    /** @comportamiento El stock reconstruido es la última entrada menos lo vendido por ticket desde su día hasta la fecha. */
    public function test_elStockReconstruidoDesdeLaUltimaEntrada(): void
    {
        $id = $this->siembra->articulo('Zz Naranja');
        $this->siembra->entradaProveedor($id, 30, '2019-02-01');
        $this->siembra->entradaProveedor($id, 12, '2019-03-01');
        $this->siembra->ventaTicket($id, 2, '2019-02-20');
        $this->siembra->ventaTicket($id, 3, '2019-03-01');
        $this->siembra->ventaTicket($id, 4, '2019-03-10');
        $this->siembra->ventaAlbaranCliente($id, 5, '2019-03-11');
        $this->siembra->ventaTicket($id, 6, '2019-04-10');

        self::assertSame([$id => 5.0], $this->repositorio->queryStockReconstituido((string) $id, '2019-03-31'));
    }

    /**
     * @comportamiento Los productos de unos proveedores: los de relación activa, o todos; el plazo medio
     *   entre los albaranes de cada proveedor, redondeado, y sin dos albaranes, cero.
     */
    public function test_losProductosYElPlazoDeLosProveedores(): void
    {
        $proveedor = $this->siembra->proveedor('Zz Frutas SL');
        $activo = $this->siembra->articulo('Zz Kiwi');
        $deBaja = $this->siembra->articulo('Zz Coco');
        $this->siembra->articuloProveedor($activo, $proveedor, 1.0);
        $this->siembra->articuloProveedor($deBaja, $proveedor, 1.0, ['estado' => 'Baja']);
        foreach (['2019-03-01', '2019-03-08', '2019-03-18'] as $fecha) {
            $this->siembra->entradaProveedor($activo, 1, $fecha, ['idProveedor' => $proveedor]);
        }
        $ids = static fn(array $filas) => array_map(static fn($f) => (int) $f['idArticulo'], $filas);

        self::assertSame([$activo], $ids($this->repositorio->queryIdsArticulosByProveedores((string) $proveedor)));
        self::assertEqualsCanonicalizing([$activo, $deBaja], $ids($this->repositorio->queryIdsArticulosByProveedoresTodos((string) $proveedor)));
        self::assertSame(9, $this->repositorio->calcularLeadTimeProveedores('2019-01-01', '2019-03-31', [$proveedor]));
        self::assertSame(0, $this->repositorio->calcularLeadTimeProveedores('2019-03-10', '2019-03-31', [$proveedor]));
        self::assertSame(0, $this->repositorio->calcularLeadTimeProveedores('2019-01-01', '2019-03-31', []));
    }

    /** @comportamiento A cada incidencia se le añade el nombre de su producto; sin producto conocido, vacío. */
    public function test_losNombresDeLasIncidencias(): void
    {
        $id = $this->siembra->articulo('Zz Uva');

        self::assertSame([['idArticulo' => $id, 'nombre' => 'Zz Uva'], ['idArticulo' => 999999999, 'nombre' => '']], $this->repositorio->anadirNombres([['idArticulo' => $id], ['idArticulo' => 999999999]]));
        self::assertSame([], $this->repositorio->anadirNombres([]));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_informes/clases/PosstockQueryRepository.php:94, :720, :819, :960, :1020, :1041, :1133, :1233
     *
     * @group defecto
     * @group integridad
     * @group medio
     *
     * @que-ocurre-hoy Una entrada de proveedor exportada o importada cuenta en el stock base y en el stock
     *   rebobinado, pero no como movimiento del periodo ni en los casos C1, C2 y C3, que solo miran las
     *   guardadas y las facturadas.
     * @que-deberia-ocurrir Que los mismos albaranes de proveedor cuenten en todas las consultas.
     * @por-que-ocurre Cada consulta escribe su lista de estados, y unas tienen cuatro y otras dos.
     * @como-deberia-funcionar Una sola lista de estados válidos para las entradas.
     */
    public function test_unaEntradaImportadaEsUnMovimientoDelPeriodo(): void
    {
        $id = $this->siembra->articulo('Zz Granada');
        $this->siembra->entradaProveedor($id, 8, '2019-03-04', ['estado' => 'Importado']);

        self::assertSame(['entrada_proveedor 2019-03-04' => 8.0], $this->movimientos($this->posstock->getMovimientosPeriodo('2019-03-01', '2019-03-31', [], [], [$id])));
    }
}
