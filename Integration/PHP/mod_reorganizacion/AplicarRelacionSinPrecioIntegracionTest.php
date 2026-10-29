<?php
/**
 * Una pieza sin precio de mercado, por las peticiones de la pantalla: calcular avisa, no deja preparar
 * y devuelve las piezas de cada receta con el precio que falta, para escribirlo; con el precio escrito,
 * se calcula y se prepara.
 *
 * El escenario es el de las recetas hermanas: el pollo de febrero de 2026, con 180 kg disponibles, su
 * despiece y la hermana para picada. Cada caso quita el coste de proveedor de las piezas que lo necesita.
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
final class AplicarRelacionSinPrecioIntegracionTest extends CasoIntegracion
{
    private const RUTA_TAREAS = RUTA_TPVFOX . '/modulos/mod_reorganizacion/tareas.php';
    private const VISTA = 'AplicarRelacion.php';

    protected bool $compartirConexionConElProducto = true;

    private EscenarioRelaciones $escenario;
    private Siembra $siembra;
    private int $especial;
    private int $habitual;
    private int $despiece;
    private int $tienda;
    /** @var array<string,int> */
    private array $articulo = [];
    private int $numRelacion;
    private int $numPicada;
    private int $numDeOtroTipo;
    private int $numDeOtroProducto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_compras/clases/ClaseCompras.php');
        $this->incluirTPVFox('/modulos/mod_producto/clases/ClaseArticulosStocks.php');
        $this->escenario = new EscenarioRelaciones($this->db);
        $this->siembra = new Siembra($this->db);
        $this->tienda = $this->siembra->tiendaPorDefecto();

        $this->especial = $this->proveedor('Despiece del obrador', 'Especial');
        $this->habitual = $this->proveedor('Granja de la prueba', 'Activo');
        $this->articulo['pollo'] = $this->siembra->articulo('Pollo entero de las hermanas', ['iva' => 10, 'idProveedor' => $this->habitual]);
        $this->articulo['ternera'] = $this->siembra->articulo('Ternera de las hermanas', ['iva' => 10, 'idProveedor' => $this->habitual]);
        foreach (['pechuga' => 6.5, 'muslo' => 3.0, 'alas' => 2.5, 'carcasa' => 0.5, 'picada' => 5.0, 'aguja' => 5.5] as $nombre => $precio) {
            $this->articulo[$nombre] = $this->siembra->articulo(ucfirst($nombre) . ' de las hermanas', ['iva' => 10]);
            $this->siembra->articuloProveedor($this->articulo[$nombre], $this->habitual, $precio);
        }
        $this->siembra->articuloProveedor($this->articulo['pollo'], $this->habitual, 2.9, ['fecha' => '2026-01-15']);
        $this->siembra->articuloProveedor($this->articulo['ternera'], $this->habitual, 4.0, ['fecha' => '2026-01-15']);
        $this->despiece = $this->escenario->tipoDeRelacion('Despiece de las hermanas', '1:N', 1);
        $otroTipo = $this->escenario->tipoDeRelacion('Otro despiece de las hermanas', '1:N', 1);
        $fecha = ['fecha' => '2026-01-10 09:00:00'];
        $this->numRelacion = $this->escenario->relacionVigente($this->despiece, [
            ['idArticulo' => $this->articulo['pollo'], 'sentido' => 'entrada', 'cantidad' => 1],
            ['idArticulo' => $this->articulo['pechuga'], 'sentido' => 'salida', 'cantidad' => 0.25],
            ['idArticulo' => $this->articulo['muslo'], 'sentido' => 'salida', 'cantidad' => 0.35],
            ['idArticulo' => $this->articulo['alas'], 'sentido' => 'salida', 'cantidad' => 0.12],
            ['idArticulo' => $this->articulo['carcasa'], 'sentido' => 'salida', 'cantidad' => 0.24],
        ], $fecha)['numRelacion'];
        $this->numPicada = $this->escenario->relacionVigente($this->despiece, [
            ['idArticulo' => $this->articulo['pollo'], 'sentido' => 'entrada', 'cantidad' => 1],
            ['idArticulo' => $this->articulo['picada'], 'sentido' => 'salida', 'cantidad' => 0.6],
            ['idArticulo' => $this->articulo['muslo'], 'sentido' => 'salida', 'cantidad' => 0.38],
        ], $fecha + ['nombre' => 'Pollo para picada de las hermanas'])['numRelacion'];
        $this->numDeOtroTipo = $this->escenario->relacionVigente($otroTipo, [
            ['idArticulo' => $this->articulo['pollo'], 'sentido' => 'entrada', 'cantidad' => 1],
            ['idArticulo' => $this->articulo['muslo'], 'sentido' => 'salida', 'cantidad' => 0.5],
        ], $fecha)['numRelacion'];
        $this->numDeOtroProducto = $this->escenario->relacionVigente($this->despiece, [
            ['idArticulo' => $this->articulo['ternera'], 'sentido' => 'entrada', 'cantidad' => 1],
            ['idArticulo' => $this->articulo['aguja'], 'sentido' => 'salida', 'cantidad' => 0.5],
        ], $fecha)['numRelacion'];

        $this->siembra->entradaProveedor($this->articulo['pollo'], 100, '2026-02-03', ['idProveedor' => $this->habitual]);
        $this->siembra->entradaProveedor($this->articulo['pollo'], 100, '2026-02-17', ['idProveedor' => $this->habitual]);
        $this->siembra->ventaTicket($this->articulo['pollo'], 15, '2026-02-20');
        $this->siembra->ventaAlbaranCliente($this->articulo['pollo'], 5, '2026-02-25');
    }

    /**
     * @comportamiento Con una pieza de la elegida sin precio de mercado, calcular avisa nombrándola, no
     *   deja preparar ni escribe nada, y devuelve sus piezas con el precio que falta vacío; con el precio
     *   escrito, se calcula y se prepara.
     */
    public function test_T1_unaPiezaDeLaElegidaSinPrecioSeDevuelveParaEscribirlo(): void
    {
        $this->sinCoste('alas');
        $sesion = $this->sesionCompleta();

        $calculo = $this->despachar($this->peticion('calcularAplicacion'), $sesion);

        self::assertFalse($calculo['ok']);
        self::assertStringContainsString('Alas de las hermanas', json_encode($calculo['avisos'], JSON_UNESCAPED_UNICODE));
        $piezas = $calculo['datos']['sinPrecio'][$this->numRelacion]['piezas'];
        self::assertSame([45.0, 63.0, 21.6, 43.2], array_column($piezas, 'cantidad'));
        self::assertSame(['media', 'media', 'falta', 'media'], array_column($piezas, 'origenPrecio'));
        self::assertNull($piezas[2]['precio']);
        self::assertFalse($this->despachar($this->peticion('prepararAlbaranAplicacion'), $sesion)['ok']);
        self::assertSame(0, $this->temporalesDelEspecial());

        $conPrecio = ['precios' => json_encode([$this->articulo['alas'] => '2,5'])];
        $calculado = $this->despachar($this->peticion('calcularAplicacion', $conPrecio), $sesion);
        self::assertTrue($calculado['ok'], json_encode($calculado['avisos'], JSON_UNESCAPED_UNICODE));
        self::assertSame('escrito', $calculado['datos']['escandallo']['piezas'][2]['origenPrecio']);
        self::assertTrue($this->despachar($this->peticion('prepararAlbaranAplicacion', $conPrecio), $sesion)['ok']);
        self::assertSame(1, $this->temporalesDelEspecial());
    }

    /**
     * @comportamiento Con una pieza sin precio en una hermana, el aviso dice de qué receta es y solo esa
     *   vuelve con sus piezas; la elegida, que tiene sus precios, no.
     */
    public function test_T2_unaPiezaDeUnaHermanaSinPrecioDiceDeQueRecetaEs(): void
    {
        $this->sinCoste('picada');

        $calculo = $this->despachar($this->peticion('calcularAplicacion', ['hermanas' => $this->hermana('50')]), $this->sesionCompleta());

        self::assertFalse($calculo['ok']);
        $avisos = json_encode($calculo['avisos'], JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('Pollo para picada de las hermanas', $avisos);
        self::assertStringContainsString('Picada de las hermanas', $avisos);
        self::assertSame([$this->numPicada], array_keys($calculo['datos']['sinPrecio']));
        self::assertSame([30.0, 19.0], array_column($calculo['datos']['sinPrecio'][$this->numPicada]['piezas'], 'cantidad'));
    }

    /** @comportamiento Con precios que faltan en las dos recetas del mismo nivel, vuelven las dos a la vez. */
    public function test_T3_conPreciosQueFaltanEnDosRecetasVuelvenLasDos(): void
    {
        $this->sinCoste('alas');
        $this->sinCoste('picada');

        $calculo = $this->despachar($this->peticion('calcularAplicacion', ['hermanas' => $this->hermana('50')]), $this->sesionCompleta());

        self::assertFalse($calculo['ok']);
        $recetas = array_keys($calculo['datos']['sinPrecio']);
        sort($recetas);
        $esperadas = [$this->numRelacion, $this->numPicada];
        sort($esperadas);
        self::assertSame($esperadas, $recetas);
        self::assertSame(0, $this->temporalesDelEspecial());
    }

    /** Quita los costes de proveedor de una pieza: se queda sin precio de mercado. */
    private function sinCoste(string $pieza): void
    {
        $this->db->query("DELETE FROM articulosProveedores WHERE idArticulo = {$this->articulo[$pieza]}");
    }

    /** La hermana para picada, en la lista de la petición, con la cantidad indicada. */
    private function hermana(string $cantidad): string
    {
        return json_encode([['numRelacion' => $this->numPicada, 'cantidad' => $cantidad, 'precios' => [], 'ajustePeso' => '1', 'ajusteValor' => '1']]);
    }

    /** La petición de la pantalla: febrero de 2026, el despiece del pollo, sin precios escritos, con el ajuste por defecto, sin cantidad ni hermanas. */
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
            'hermanas' => '',
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
        return $this->sesion(1);
    }

    /** La sesión de un usuario nuevo con «Preparar» y, si se indica, «ver» de la pantalla. */
    private function sesion(int $ver): array
    {
        require_once RUTA_TPVFOX . '/clases/ClasePermisos.php';
        $nombre = 'prueba_sin_precio_' . bin2hex(random_bytes(3));
        $this->db->query("INSERT INTO usuarios (username, password, fecha, group_id, estado, nombre) VALUES ('$nombre', MD5('x'), CURDATE(), 1, 'activo', 'Prueba')");
        $usuario = ['id' => (int) $this->db->insert_id, 'group_id' => 1];
        new \ClasePermisos($usuario, $this->db);
        foreach (['ver' => $ver, 'Preparar' => 1] as $accion => $valor) {
            $this->db->query("DELETE FROM permisos WHERE idUsuario = {$usuario['id']} AND modulo = 'mod_reorganizacion' AND vista = '" . self::VISTA . "' AND accion = '$accion'");
            $this->db->query("INSERT INTO permisos (idUsuario, modulo, vista, accion, permiso) VALUES ({$usuario['id']}, 'mod_reorganizacion', '" . self::VISTA . "', '$accion', $valor)");
        }

        return ['ClasePermisos' => new \ClasePermisos($usuario, $this->db), 'Usuario' => $usuario, 'Tienda' => ['idTienda' => $this->tienda]];
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
