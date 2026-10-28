<?php
/**
 * El aviso de las piezas que quedan en negativo al final del periodo y el mínimo de la receta, por las
 * peticiones de calcular y preparar, como las hace la pantalla.
 *
 * El escenario es el despiece del pollo aplicado a febrero de 2026: entran 200 kg del proveedor
 * habitual y salen 20 kg enteros, así que hay 180 kg disponibles. De cada kilo de pollo salen 0,25 kg
 * de pechuga.
 *
 * Cada caso corre en un proceso aparte: las tareas de reorganización cargan una clase de
 * complemento que se llama igual que otra del módulo de productos, y las dos no caben en el mismo
 * proceso.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModReorganizacion;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\DespachoTareas;
use TPVFox\Test\Siembra\EscenarioRelaciones;
use TPVFox\Test\Siembra\Siembra;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class AplicarRelacionDesajusteIntegracionTest extends CasoIntegracion
{
    private const RUTA_TAREAS = RUTA_TPVFOX . '/modulos/mod_reorganizacion/tareas.php';
    private const VISTA = 'AplicarRelacion.php';

    protected bool $compartirConexionConElProducto = true;

    private EscenarioRelaciones $escenario;
    private Siembra $siembra;
    private int $especial;
    private int $habitual;
    private int $despiece;
    /** @var array<string,int> */
    private array $articulo = [];
    private int $numRelacion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_compras/clases/ClaseCompras.php');
        $this->incluirTPVFox('/modulos/mod_producto/clases/ClaseArticulosStocks.php');
        $this->escenario = new EscenarioRelaciones($this->db);
        $this->siembra = new Siembra($this->db);

        $this->especial = $this->proveedor('Despiece del obrador', 'Especial');
        $this->habitual = $this->proveedor('Granja de la prueba', 'Activo');
        $this->articulo['pollo'] = $this->siembra->articulo('Pollo entero del desajuste', ['iva' => 10, 'idProveedor' => $this->habitual]);
        foreach (['pechuga' => 6.5, 'muslo' => 3.0, 'alas' => 2.5, 'carcasa' => 0.5] as $nombre => $precio) {
            $this->articulo[$nombre] = $this->siembra->articulo(ucfirst($nombre) . ' del desajuste', ['iva' => 10]);
            $this->siembra->articuloProveedor($this->articulo[$nombre], $this->habitual, $precio);
        }
        $this->siembra->articuloProveedor($this->articulo['pollo'], $this->habitual, 2.9, ['fecha' => '2026-01-15']);
        $this->despiece = $this->escenario->tipoDeRelacion('Despiece del desajuste', '1:N', 1);
        $this->numRelacion = $this->escenario->relacionVigente($this->despiece, [
            ['idArticulo' => $this->articulo['pollo'], 'sentido' => 'entrada', 'cantidad' => 1],
            ['idArticulo' => $this->articulo['pechuga'], 'sentido' => 'salida', 'cantidad' => 0.25],
            ['idArticulo' => $this->articulo['muslo'], 'sentido' => 'salida', 'cantidad' => 0.35],
            ['idArticulo' => $this->articulo['alas'], 'sentido' => 'salida', 'cantidad' => 0.12],
            ['idArticulo' => $this->articulo['carcasa'], 'sentido' => 'salida', 'cantidad' => 0.24],
        ], ['fecha' => '2026-01-10 09:00:00'])['numRelacion'];

        $this->siembra->entradaProveedor($this->articulo['pollo'], 100, '2026-02-03', ['idProveedor' => $this->habitual]);
        $this->siembra->entradaProveedor($this->articulo['pollo'], 100, '2026-02-17', ['idProveedor' => $this->habitual]);
        $this->siembra->ventaTicket($this->articulo['pollo'], 15, '2026-02-20');
        $this->siembra->ventaAlbaranCliente($this->articulo['pollo'], 5, '2026-02-25');
    }

    /**
     * @comportamiento Con una pieza que salió más de lo que tenía y de lo que genera la aplicación, el
     *   cálculo la nombra en amarillo con lo que le falta y dice el mínimo de la receta; se prepara
     *   igual, también por petición directa.
     */
    public function test_T1_unaPiezaQueQuedaEnNegativoSeAvisaConElMinimoYSePreparaIgual(): void
    {
        $this->siembra->ventaTicket($this->articulo['pechuga'], 40, '2026-02-22');
        $sesion = $this->sesionCompleta();

        $calculo = $this->despachar($this->peticion('calcularAplicacion', ['cantidad' => '100']), $sesion);

        self::assertTrue($calculo['ok'], json_encode($calculo['avisos'], JSON_UNESCAPED_UNICODE));
        self::assertSame(-15.0, $calculo['datos']['desajuste']['piezas'][0]['queda']);
        self::assertSame(160.0, $calculo['datos']['desajuste']['minimo']);
        $amarillos = implode(' ', array_column(array_filter($calculo['avisos'], fn(array $aviso): bool => $aviso['tipo'] === 'warning'), 'mensaje'));
        self::assertStringContainsString('Pechuga del desajuste', $amarillos);
        self::assertStringContainsString('160', $amarillos);

        $preparado = $this->despachar($this->peticion('prepararAlbaranAplicacion', ['cantidad' => '100']), $sesion);
        self::assertTrue($preparado['ok'], json_encode($preparado['avisos'], JSON_UNESCAPED_UNICODE));
        self::assertSame(1, $this->temporalesDelEspecial());
    }

    /** @comportamiento Sin ninguna pieza en negativo no sale el aviso, y el mínimo se devuelve igual. */
    public function test_T2_sinPiezasEnNegativoNoHayAvisoYElMinimoSeDevuelve(): void
    {
        $this->siembra->ventaTicket($this->articulo['pechuga'], 40, '2026-02-22');

        $calculo = $this->despachar($this->peticion('calcularAplicacion'), $this->sesionCompleta());

        self::assertTrue($calculo['ok'], json_encode($calculo['avisos'], JSON_UNESCAPED_UNICODE));
        self::assertSame(5.0, $calculo['datos']['desajuste']['piezas'][0]['queda']);
        self::assertSame(160.0, $calculo['datos']['desajuste']['minimo']);
        self::assertStringNotContainsString('negativo', implode(' ', array_column($calculo['avisos'], 'mensaje')));
    }

    /** @comportamiento En un despiece por unidades, el mínimo de la receta es entero, redondeado hacia arriba. */
    public function test_T3_enUnDespiecePorUnidadesElMinimoEsEntero(): void
    {
        $caja = $this->siembra->articulo('Caja de muslos del desajuste', ['iva' => 10, 'idProveedor' => $this->habitual]);
        $bandeja = $this->siembra->articulo('Bandeja de muslos del desajuste', ['iva' => 10]);
        $this->siembra->articuloProveedor($caja, $this->habitual, 12.0, ['fecha' => '2026-01-15']);
        $this->siembra->articuloProveedor($bandeja, $this->habitual, 6.0);
        $numCaja = $this->escenario->relacionVigente($this->despiece, [
            ['idArticulo' => $caja, 'sentido' => 'entrada', 'cantidad' => 5, 'unidad' => 'ud'],
            ['idArticulo' => $bandeja, 'sentido' => 'salida', 'cantidad' => 2, 'unidad' => 'ud'],
        ], ['fecha' => '2026-01-10 09:00:00'])['numRelacion'];
        $this->siembra->entradaProveedor($caja, 10, '2026-02-05', ['idProveedor' => $this->habitual]);
        $this->siembra->ventaTicket($bandeja, 3, '2026-02-20');

        $calculo = $this->despachar($this->peticion('calcularAplicacion', ['numRelacion' => (string) $numCaja, 'cantidad' => '5']), $this->sesionCompleta());

        self::assertTrue($calculo['ok'], json_encode($calculo['avisos'], JSON_UNESCAPED_UNICODE));
        self::assertSame(-1.0, $calculo['datos']['desajuste']['piezas'][0]['queda']);
        self::assertSame(8.0, $calculo['datos']['desajuste']['minimo']);
    }

    /** La petición de la pantalla: febrero de 2026, el despiece del pollo, sin precios escritos, con el ajuste por defecto y sin cantidad. */
    private function peticion(string $pulsado, array $cambios = []): array
    {
        return array_merge([
            'pulsado' => $pulsado,
            'idProveedor' => (string) $this->especial,
            'numRelacion' => (string) $this->numRelacion,
            'tipoPeriodo' => 'mes',
            'periodo' => '2026-02',
            'idProveedorTraspaso' => '',
            'precios' => '{}',
            'ajustePeso' => '1',
            'ajusteValor' => '1',
            'cantidad' => '',
        ], $cambios);
    }

    private function proveedor(string $nombre, string $estado): int
    {
        $id = $this->siembra->proveedor($nombre);
        $this->db->query("UPDATE proveedores SET estado = '$estado', nombrecomercial = '$nombre' WHERE idProveedor = $id");

        return $id;
    }

    /** @return array{ClasePermisos:object,Usuario:array{id:int,group_id:int},Tienda:array{idTienda:int}} */
    private function sesionCompleta(): array
    {
        require_once RUTA_TPVFOX . '/clases/ClasePermisos.php';
        $nombre = 'prueba_desajuste_' . bin2hex(random_bytes(3));
        $this->db->query("INSERT INTO usuarios (username, password, fecha, group_id, estado, nombre) VALUES ('$nombre', MD5('x'), CURDATE(), 1, 'activo', 'Prueba')");
        $usuario = ['id' => (int) $this->db->insert_id, 'group_id' => 1];
        new \ClasePermisos($usuario, $this->db);
        foreach (['ver', 'Preparar'] as $accion) {
            $this->db->query("DELETE FROM permisos WHERE idUsuario = {$usuario['id']} AND modulo = 'mod_reorganizacion' AND vista = '" . self::VISTA . "' AND accion = '$accion'");
            $this->db->query("INSERT INTO permisos (idUsuario, modulo, vista, accion, permiso) VALUES ({$usuario['id']}, 'mod_reorganizacion', '" . self::VISTA . "', '$accion', 1)");
        }

        return ['ClasePermisos' => new \ClasePermisos($usuario, $this->db), 'Usuario' => $usuario, 'Tienda' => ['idTienda' => $this->siembra->tiendaPorDefecto()]];
    }

    private function temporalesDelEspecial(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) AS n FROM albproltemporales WHERE idProveedor = {$this->especial}")->fetch_assoc()['n'];
    }

    private function despachar(array $post, array $variables): mixed
    {
        return DespachoTareas::invocar(self::RUTA_TAREAS, $this->db, $post, $variables);
    }
}
