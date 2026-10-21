<?php
/**
 * Una relación de baja no se puede aplicar: ni calcular ni preparar su albarán por petición
 * directa, con la versión que regía o con la vigente, ni se ofrece entre las aplicables. Una vez
 * reactivada se ofrece y se aplica como cualquier otra.
 *
 * Mismo escenario que la aplicación del despiece del pollo a febrero de 2026. Cada caso corre en
 * un proceso aparte por la clase de complemento que comparten reorganización y productos.
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
final class AplicarRelacionDeBajaIntegracionTest extends CasoIntegracion
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
    /** @var array{id:int,numRelacion:int} */
    private array $relacion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_compras/clases/ClaseCompras.php');
        $this->incluirTPVFox('/modulos/mod_producto/clases/ClaseArticulosStocks.php');
        $this->incluirTPVFox('/modulos/mod_relaciones/clases/RelacionesAplicacion.php');
        $this->escenario = new EscenarioRelaciones($this->db);
        $this->siembra = new Siembra($this->db);

        $this->especial = $this->proveedor('Despiece del obrador', 'Especial');
        $this->habitual = $this->proveedor('Granja de la prueba', 'Activo');
        $this->articulo['pollo'] = $this->siembra->articulo('Pollo entero de la aplicación', ['iva' => 10, 'idProveedor' => $this->habitual]);
        foreach (['pechuga' => 6.5, 'muslo' => 3.0, 'alas' => 2.5, 'carcasa' => 0.5] as $nombre => $precio) {
            $this->articulo[$nombre] = $this->siembra->articulo(ucfirst($nombre) . ' de la aplicación', ['iva' => 10]);
            $this->siembra->articuloProveedor($this->articulo[$nombre], $this->habitual, $precio);
        }
        $this->siembra->articuloProveedor($this->articulo['pollo'], $this->habitual, 2.9, ['fecha' => '2026-01-15']);
        $this->despiece = $this->escenario->tipoDeRelacion('Despiece de la aplicación', '1:N', 1);
        $this->relacion = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo(0.25), ['fecha' => '2026-01-10 09:00:00']);

        $this->siembra->entradaProveedor($this->articulo['pollo'], 100, '2026-02-03', ['idProveedor' => $this->habitual]);
        $this->siembra->entradaProveedor($this->articulo['pollo'], 100, '2026-02-17', ['idProveedor' => $this->habitual]);
        $this->siembra->ventaTicket($this->articulo['pollo'], 15, '2026-02-20');
        $this->siembra->ventaAlbaranCliente($this->articulo['pollo'], 5, '2026-02-25');
    }

    /**
     * @comportamiento Con la relación de baja, calcular y preparar se rechazan con el aviso de que
     *   está de baja, con la versión que regía y con la vigente, y no dejan ningún temporal.
     */
    public function test_T1_unaRelacionDeBajaNoSeCalculaNiSePreparaPorPeticionDirecta(): void
    {
        $this->db->query("UPDATE relaciones SET estado = 'Baja' WHERE id = {$this->relacion['id']}");
        $sesion = $this->sesionCompleta();

        foreach (['calcularAplicacion', 'prepararAlbaranAplicacion'] as $pulsado) {
            foreach (['periodo', 'vigente'] as $cual) {
                $respuesta = $this->despachar($this->peticion($pulsado, ['version' => $cual]), $sesion);
                self::assertFalse($respuesta['ok'], $pulsado . ' ' . $cual);
                self::assertStringContainsString('de baja', $respuesta['avisos'][0]['mensaje'], $pulsado . ' ' . $cual);
            }
        }
        self::assertSame(0, $this->temporalesDelEspecial());

        $aplicables = (new \RelacionesAplicacion())->relacionesAplicables()['datos'];
        self::assertNotContains($this->relacion['numRelacion'], array_map('intval', array_column($aplicables, 'numRelacion')));
    }

    /** @comportamiento Reactivada, se ofrece entre las aplicables y se calcula y prepara como cualquier otra. */
    public function test_T2_unaRelacionReactivadaSeOfreceYSeAplicaComoCualquierOtra(): void
    {
        $this->db->query("UPDATE relaciones SET estado = 'Baja' WHERE id = {$this->relacion['id']}");
        $this->db->query("UPDATE relaciones SET estado = 'Activo' WHERE id = {$this->relacion['id']}");
        $sesion = $this->sesionCompleta();

        $aplicables = (new \RelacionesAplicacion())->relacionesAplicables()['datos'];
        self::assertContains($this->relacion['numRelacion'], array_map('intval', array_column($aplicables, 'numRelacion')));
        $calculo = $this->despachar($this->peticion('calcularAplicacion'), $sesion);
        self::assertTrue($calculo['ok'], json_encode($calculo['avisos']));
        $preparado = $this->despachar($this->peticion('prepararAlbaranAplicacion'), $sesion);
        self::assertTrue($preparado['ok'], json_encode($preparado['avisos']));
        self::assertSame(1, $this->temporalesDelEspecial());
    }

    /** @return list<array{idArticulo:int,sentido:string,cantidad:float}> */
    private function lineasDelPollo(float $pechuga): array
    {
        return [
            ['idArticulo' => $this->articulo['pollo'], 'sentido' => 'entrada', 'cantidad' => 1],
            ['idArticulo' => $this->articulo['pechuga'], 'sentido' => 'salida', 'cantidad' => $pechuga],
            ['idArticulo' => $this->articulo['muslo'], 'sentido' => 'salida', 'cantidad' => 0.35],
            ['idArticulo' => $this->articulo['alas'], 'sentido' => 'salida', 'cantidad' => 0.12],
            ['idArticulo' => $this->articulo['carcasa'], 'sentido' => 'salida', 'cantidad' => 0.24],
        ];
    }

    /** La petición de la pantalla: febrero de 2026, el despiece del pollo, sin precios escritos y con el ajuste por defecto. */
    private function peticion(string $pulsado, array $cambios = []): array
    {
        return array_merge([
            'pulsado' => $pulsado,
            'idProveedor' => (string) $this->especial,
            'numRelacion' => (string) $this->relacion['numRelacion'],
            'tipoPeriodo' => 'mes',
            'periodo' => '2026-02',
            'idProveedorTraspaso' => '',
            'precios' => '{}',
            'ajustePeso' => '1',
            'ajusteValor' => '1',
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
        return $this->sesion([['ver', 1], ['Preparar', 1]]);
    }

    /**
     * La sesión de un usuario nuevo del grupo indicado, con los permisos de la pantalla de aplicar
     * relación que fije el caso y los de otros módulos que se indiquen.
     *
     * @param list<array{0:string,1:int}> $deLaPantalla acción y valor
     * @param list<array{0:string,1:string,2:string,3:int}> $deOtros módulo, vista, acción y valor
     * @return array{ClasePermisos:object,Usuario:array{id:int,group_id:int},Tienda:array{idTienda:int}}
     */
    private function sesion(array $deLaPantalla, array $deOtros = [], int $grupo = 1): array
    {
        require_once RUTA_TPVFOX . '/clases/ClasePermisos.php';
        $nombre = 'prueba_aplicar_' . $grupo . '_' . bin2hex(random_bytes(3));
        $this->db->query("INSERT INTO usuarios (username, password, fecha, group_id, estado, nombre) VALUES ('$nombre', MD5('x'), CURDATE(), $grupo, 'activo', 'Prueba')");
        $usuario = ['id' => (int) $this->db->insert_id, 'group_id' => $grupo];

        new \ClasePermisos($usuario, $this->db);
        $fijados = $deOtros;
        foreach ($deLaPantalla as [$accion, $valor]) {
            $fijados[] = ['mod_reorganizacion', self::VISTA, $accion, $valor];
        }
        foreach ($fijados as [$modulo, $vista, $accion, $valor]) {
            $this->db->query("DELETE FROM permisos WHERE idUsuario = {$usuario['id']} AND modulo = '$modulo' AND vista = '$vista' AND accion = '$accion'");
            $this->db->query("INSERT INTO permisos (idUsuario, modulo, vista, accion, permiso) VALUES ({$usuario['id']}, '$modulo', '$vista', '$accion', $valor)");
        }

        return ['ClasePermisos' => new \ClasePermisos($usuario, $this->db), 'Usuario' => $usuario, 'Tienda' => ['idTienda' => $this->siembra->tiendaPorDefecto()]];
    }

    private function temporalesDelEspecial(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) AS n FROM albproltemporales WHERE idProveedor = {$this->especial}")->fetch_assoc()['n'];
    }

    private function cuantas(string $tabla): int
    {
        return (int) $this->db->query("SELECT COUNT(*) AS n FROM $tabla")->fetch_assoc()['n'];
    }

    private function despachar(array $post, array $variables): mixed
    {
        return DespachoTareas::invocar(self::RUTA_TAREAS, $this->db, $post, $variables);
    }
}
