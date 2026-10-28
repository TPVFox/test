<?php
/**
 * La cantidad que entra en la receta y el tope de lo disponible, por las peticiones de calcular y
 * preparar, como las hace la pantalla: una cantidad escrita, las que no valen, varias aplicaciones
 * del mismo producto en el periodo, y lo disponible al guardar y al reabrir en compras lo preparado.
 *
 * El escenario es el despiece del pollo aplicado a febrero de 2026: entran 200 kg del proveedor
 * habitual y salen 20 kg enteros, así que hay 180 kg disponibles. El despiece rinde el 96 %; la
 * picada, del mismo tipo, el 98 %.
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
final class AplicarRelacionDisponibleIntegracionTest extends CasoIntegracion
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
    private int $entradaDel17;

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
        $this->articulo['pollo'] = $this->siembra->articulo('Pollo entero de lo disponible', ['iva' => 10, 'idProveedor' => $this->habitual]);
        foreach (['pechuga' => 6.5, 'muslo' => 3.0, 'alas' => 2.5, 'carcasa' => 0.5, 'picada' => 5.0] as $nombre => $precio) {
            $this->articulo[$nombre] = $this->siembra->articulo(ucfirst($nombre) . ' de lo disponible', ['iva' => 10]);
            $this->siembra->articuloProveedor($this->articulo[$nombre], $this->habitual, $precio);
        }
        $this->siembra->articuloProveedor($this->articulo['pollo'], $this->habitual, 2.9, ['fecha' => '2026-01-15']);
        $this->despiece = $this->escenario->tipoDeRelacion('Despiece de lo disponible', '1:N', 1);
        $this->numRelacion = $this->escenario->relacionVigente($this->despiece, [
            ['idArticulo' => $this->articulo['pollo'], 'sentido' => 'entrada', 'cantidad' => 1],
            ['idArticulo' => $this->articulo['pechuga'], 'sentido' => 'salida', 'cantidad' => 0.25],
            ['idArticulo' => $this->articulo['muslo'], 'sentido' => 'salida', 'cantidad' => 0.35],
            ['idArticulo' => $this->articulo['alas'], 'sentido' => 'salida', 'cantidad' => 0.12],
            ['idArticulo' => $this->articulo['carcasa'], 'sentido' => 'salida', 'cantidad' => 0.24],
        ], ['fecha' => '2026-01-10 09:00:00'])['numRelacion'];
        $this->numPicada = $this->escenario->relacionVigente($this->despiece, [
            ['idArticulo' => $this->articulo['pollo'], 'sentido' => 'entrada', 'cantidad' => 1],
            ['idArticulo' => $this->articulo['picada'], 'sentido' => 'salida', 'cantidad' => 0.98],
        ], ['fecha' => '2026-01-10 09:00:00'])['numRelacion'];

        $this->siembra->entradaProveedor($this->articulo['pollo'], 100, '2026-02-03', ['idProveedor' => $this->habitual]);
        $this->entradaDel17 = $this->siembra->entradaProveedor($this->articulo['pollo'], 100, '2026-02-17', ['idProveedor' => $this->habitual]);
        $this->siembra->ventaTicket($this->articulo['pollo'], 15, '2026-02-20');
        $this->siembra->ventaAlbaranCliente($this->articulo['pollo'], 5, '2026-02-25');
    }

    /**
     * @comportamiento Una cantidad escrita, con coma o con punto decimal, se aplica: los kg de cada
     *   pieza salen de ella con las proporciones del despiece, la merma es de esa aplicación y el
     *   temporal lleva lo despiezado de esa cantidad. Sin cantidad, o con todo lo disponible, se
     *   aplica todo.
     */
    public function test_T1_unaCantidadEscritaSeAplicaConLasProporcionesDelDespiece(): void
    {
        $sesion = $this->sesionCompleta();

        $calculo = $this->despachar($this->peticion('calcularAplicacion', ['cantidad' => '100']), $sesion);
        self::assertTrue($calculo['ok'], json_encode($calculo['avisos'], JSON_UNESCAPED_UNICODE));
        self::assertSame(180.0, $calculo['datos']['disponible']);
        self::assertSame(100.0, $calculo['datos']['cantidad']);
        self::assertSame(25.0, $calculo['datos']['escandallo']['piezas'][0]['cantidad']);
        self::assertSame(96.0, $calculo['datos']['escandallo']['despiezado']);
        self::assertSame(4.0, $calculo['datos']['escandallo']['merma']);

        $conComa = $this->despachar($this->peticion('calcularAplicacion', ['cantidad' => '100,5']), $sesion);
        self::assertSame(100.5, $conComa['datos']['cantidad']);
        self::assertSame(25.125, $conComa['datos']['escandallo']['piezas'][0]['cantidad']);

        foreach (['', '180'] as $todo) {
            $calculoDeTodo = $this->despachar($this->peticion('calcularAplicacion', ['cantidad' => $todo]), $sesion);
            self::assertTrue($calculoDeTodo['ok'], $todo);
            self::assertSame(180.0, $calculoDeTodo['datos']['cantidad'], $todo);
        }

        $preparado = $this->despachar($this->peticion('prepararAlbaranAplicacion', ['cantidad' => '100']), $sesion);
        self::assertTrue($preparado['ok'], json_encode($preparado['avisos'], JSON_UNESCAPED_UNICODE));
        $lineas = $this->lineasDelTemporal((int) $preparado['datos']['idTemporal']);
        self::assertEqualsWithDelta(-96.0, $lineas[0]['nunidades'], 0.0001);
        self::assertEqualsWithDelta(25.0, $lineas[1]['nunidades'], 0.0001);
    }

    /**
     * @comportamiento Una cantidad mayor que lo disponible, cero, negativa, que no es un número o con
     *   más de tres decimales se rechaza al calcular y al preparar, y no se escribe nada.
     */
    public function test_T2_unaCantidadQueNoValeSeRechazaAlCalcularYAlPreparar(): void
    {
        $sesion = $this->sesionCompleta();

        foreach (['180,001', '181', '0', '0,000', '-5', 'abc', '1,2345', '1e3', '1 OR 1=1', '100; DROP TABLE articulos'] as $cantidad) {
            $calculo = $this->despachar($this->peticion('calcularAplicacion', ['cantidad' => $cantidad]), $sesion);
            self::assertFalse($calculo['ok'], $cantidad);
            self::assertArrayNotHasKey('escandallo', $calculo['datos'], $cantidad);
            $preparado = $this->despachar($this->peticion('prepararAlbaranAplicacion', ['cantidad' => $cantidad]), $sesion);
            self::assertFalse($preparado['ok'], $cantidad);
        }
        $mayor = $this->despachar($this->peticion('calcularAplicacion', ['cantidad' => '181']), $sesion);
        self::assertStringContainsString('disponible', implode(' ', array_column($mayor['avisos'], 'mensaje')));
        self::assertSame(0, $this->temporalesDelEspecial());
    }

    /**
     * @comportamiento Varias aplicaciones del mismo producto en el periodo, con otra receta o con la
     *   misma, se calculan y se preparan dentro de lo que queda, y el aviso nombra la anterior como
     *   aplicada. Sin nada disponible, no se calcula ni se prepara.
     */
    public function test_T3_variasAplicacionesDelMismoProductoCabenEnLoQueQueda(): void
    {
        $sesion = $this->sesionCompleta();

        self::assertTrue($this->despachar($this->peticion('prepararAlbaranAplicacion', ['cantidad' => '100']), $sesion)['ok']);
        $segunda = $this->despachar($this->peticion('calcularAplicacion', ['numRelacion' => (string) $this->numPicada]), $sesion);
        self::assertTrue($segunda['ok'], json_encode($segunda['avisos'], JSON_UNESCAPED_UNICODE));
        self::assertSame(80.0, $segunda['datos']['disponible']);
        self::assertSame('amarillo', $segunda['datos']['aviso']['color']);
        self::assertStringContainsString('aplicado', implode(' ', array_column($segunda['avisos'], 'mensaje')));

        self::assertTrue($this->despachar($this->peticion('prepararAlbaranAplicacion', ['numRelacion' => (string) $this->numPicada, 'cantidad' => '50']), $sesion)['ok']);
        $tercera = $this->despachar($this->peticion('calcularAplicacion'), $sesion);
        self::assertSame(30.0, $tercera['datos']['disponible']);
        self::assertTrue($this->despachar($this->peticion('prepararAlbaranAplicacion'), $sesion)['ok']);

        $sinNada = $this->despachar($this->peticion('calcularAplicacion'), $sesion);
        self::assertFalse($sinNada['ok']);
        self::assertSame(0.0, $sinNada['datos']['disponible']);
        self::assertContains('danger', array_column($sinNada['avisos'], 'tipo'));
        self::assertFalse($this->despachar($this->peticion('prepararAlbaranAplicacion', ['numRelacion' => (string) $this->numPicada]), $sesion)['ok']);
        self::assertSame(3, $this->temporalesDelEspecial());
    }

    /**
     * @comportamiento Guardar en compras lo preparado no cambia lo disponible: las existencias son las
     *   del escandallo de la cantidad aplicada, y la merma que dejó sigue descontada.
     */
    public function test_T4_guardarLoPreparadoNoCambiaLoDisponible(): void
    {
        $sesion = $this->sesionCompleta();
        $antes = $this->existencias();
        $idTemporal = (int) $this->despachar($this->peticion('prepararAlbaranAplicacion', ['cantidad' => '100']), $sesion)['datos']['idTemporal'];
        self::assertSame(80.0, $this->despachar($this->peticion('calcularAplicacion'), $sesion)['datos']['disponible']);

        self::assertSame([], $this->guardarEnCompras($idTemporal, $sesion));

        $despues = $this->existencias();
        self::assertEqualsWithDelta(-96.0, $despues['pollo'] - $antes['pollo'], 0.0001);
        self::assertEqualsWithDelta(25.0, $despues['pechuga'] - $antes['pechuga'], 0.0001);
        $calculo = $this->despachar($this->peticion('calcularAplicacion'), $sesion);
        self::assertSame(84.0, $calculo['datos']['cantidadADespiezar']);
        self::assertSame(4.0, $calculo['datos']['mermaAnterior']);
        self::assertSame(0.0, $calculo['datos']['enTemporales']);
        self::assertSame(80.0, $calculo['datos']['disponible']);
    }

    /**
     * @comportamiento Reabrir en compras un albarán no cambia lo disponible: el de la aplicación,
     *   porque su temporal saca lo mismo; el de una entrada, porque sus entradas siguen contando.
     */
    public function test_T5_reabrirUnAlbaranEnComprasNoCambiaLoDisponible(): void
    {
        $sesion = $this->sesionCompleta();
        $this->guardarEnCompras((int) $this->despachar($this->peticion('prepararAlbaranAplicacion', ['cantidad' => '100']), $sesion)['datos']['idTemporal'], $sesion);
        $idDespiece = (int) $this->db->query("SELECT id FROM albprot WHERE idProveedor = {$this->especial}")->fetch_assoc()['id'];

        $this->reabrirEnCompras($idDespiece, $this->especial, $sesion);
        $conElDespiece = $this->despachar($this->peticion('calcularAplicacion'), $sesion);
        self::assertSame(180.0, $conElDespiece['datos']['cantidadADespiezar']);
        self::assertSame(0.0, $conElDespiece['datos']['mermaAnterior']);
        self::assertSame(100.0, $conElDespiece['datos']['enTemporales']);
        self::assertSame(80.0, $conElDespiece['datos']['disponible']);

        $this->reabrirEnCompras($this->entradaDel17, $this->habitual, $sesion);
        $conLaEntrada = $this->despachar($this->peticion('calcularAplicacion'), $sesion);
        self::assertSame(180.0, $conLaEntrada['datos']['cantidadADespiezar']);
        self::assertSame(80.0, $conLaEntrada['datos']['disponible']);
    }

    /** @comportamiento En un despiece por unidades, la cantidad tiene que ser entera. */
    public function test_T6_enUnDespiecePorUnidadesLaCantidadEsEntera(): void
    {
        $caja = $this->siembra->articulo('Caja de muslos de lo disponible', ['iva' => 10, 'idProveedor' => $this->habitual]);
        $bandeja = $this->siembra->articulo('Bandeja de muslos de lo disponible', ['iva' => 10]);
        $this->siembra->articuloProveedor($caja, $this->habitual, 12.0, ['fecha' => '2026-01-15']);
        $this->siembra->articuloProveedor($bandeja, $this->habitual, 6.0);
        $numCaja = $this->escenario->relacionVigente($this->despiece, [
            ['idArticulo' => $caja, 'sentido' => 'entrada', 'cantidad' => 1, 'unidad' => 'ud'],
            ['idArticulo' => $bandeja, 'sentido' => 'salida', 'cantidad' => 1, 'unidad' => 'ud'],
        ], ['fecha' => '2026-01-10 09:00:00'])['numRelacion'];
        $this->siembra->entradaProveedor($caja, 10, '2026-02-05', ['idProveedor' => $this->habitual]);
        $sesion = $this->sesionCompleta();

        $conDecimales = $this->despachar($this->peticion('calcularAplicacion', ['numRelacion' => (string) $numCaja, 'cantidad' => '2,5']), $sesion);
        self::assertFalse($conDecimales['ok']);
        self::assertStringContainsString('sin decimales', implode(' ', array_column($conDecimales['avisos'], 'mensaje')));

        $entera = $this->despachar($this->peticion('calcularAplicacion', ['numRelacion' => (string) $numCaja, 'cantidad' => '4']), $sesion);
        self::assertTrue($entera['ok'], json_encode($entera['avisos'], JSON_UNESCAPED_UNICODE));
        self::assertSame(4.0, $entera['datos']['escandallo']['piezas'][0]['cantidad']);
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

    /** @return list<array<string,mixed>> */
    private function lineasDelTemporal(int $id): array
    {
        return json_decode($this->db->query("SELECT Productos FROM albproltemporales WHERE id = $id")->fetch_assoc()['Productos'], true);
    }

    /** Guarda el temporal como lo hace la pantalla de albaranes al pulsar «Guardar». */
    private function guardarEnCompras(int $idTemporal, array $sesion): array
    {
        $_SESSION['tiendaTpv'] = ['idTienda' => $this->tienda];
        $_SESSION['usuarioTpv'] = ['id' => $sesion['Usuario']['id']];
        $_POST = ['idTemporal' => $idTemporal, 'estado' => 'Nuevo', 'suNumero' => 'Despiece', 'formaVenci' => '', 'fechaVenci' => '', 'fecha' => '28-02-2026', 'hora' => ''];
        $errores = @(new \AlbaranesCompras($this->db))->guardarAlbaran();
        $_POST = [];

        return $errores;
    }

    /** Abre un temporal a partir de un albarán guardado, como lo hace la pantalla de albaranes al editarlo. */
    private function reabrirEnCompras(int $idAlbaran, int $proveedor, array $sesion): void
    {
        $compras = new \AlbaranesCompras($this->db);
        $datos = $compras->datosAlbaran($idAlbaran);
        $lineas = $compras->ProductosAlbaranFormulario($idAlbaran);
        $alta = $compras->insertarDatosAlbaranTemporal($sesion['Usuario']['id'], $this->tienda, 'Sin Guardar', $datos['Fecha'], $lineas, $proveedor, [], $datos['Su_numero']);
        $compras->addNumRealTemporal((int) $alta['id'], $idAlbaran);
        $compras->modEstadoAlbaran($idAlbaran, 'Sin Guardar');
    }

    /** @return array<string,float> existencias registradas de cada artículo del escenario */
    private function existencias(): array
    {
        $existencias = [];
        foreach ($this->articulo as $nombre => $id) {
            $fila = $this->db->query("SELECT stockOn FROM articulosStocks WHERE idArticulo = $id AND idTienda = {$this->tienda}")->fetch_assoc();
            $existencias[$nombre] = $fila === null ? 0.0 : (float) $fila['stockOn'];
        }

        return $existencias;
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
        $nombre = 'prueba_disponible_' . bin2hex(random_bytes(3));
        $this->db->query("INSERT INTO usuarios (username, password, fecha, group_id, estado, nombre) VALUES ('$nombre', MD5('x'), CURDATE(), 1, 'activo', 'Prueba')");
        $usuario = ['id' => (int) $this->db->insert_id, 'group_id' => 1];
        new \ClasePermisos($usuario, $this->db);
        foreach (['ver', 'Preparar'] as $accion) {
            $this->db->query("DELETE FROM permisos WHERE idUsuario = {$usuario['id']} AND modulo = 'mod_reorganizacion' AND vista = '" . self::VISTA . "' AND accion = '$accion'");
            $this->db->query("INSERT INTO permisos (idUsuario, modulo, vista, accion, permiso) VALUES ({$usuario['id']}, 'mod_reorganizacion', '" . self::VISTA . "', '$accion', 1)");
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
