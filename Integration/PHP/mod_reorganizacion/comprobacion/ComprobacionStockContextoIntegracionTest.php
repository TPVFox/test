<?php
/**
 * Contexto de operación: ejercicio y tienda desde la sesión, esquema y parámetros
 * presentes, y bloque de lectura abierto en una transacción de solo lectura.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModReorganizacion\Comprobacion;

use mysqli_sql_exception;
use TPVFox\Test\CasoIntegracion;

final class ComprobacionStockContextoIntegracionTest extends CasoIntegracion
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION ??= [];
        $this->incluirTPVFox('/modulos/mod_reorganizacion/clases/ClaseComprobacionStockContexto.php');
    }

    protected function tearDown(): void
    {
        unset($_SESSION['tiendaTpv']);
        parent::tearDown();
    }

    public function test_T1_ejercicioYTiendaSalenDeLaSesion(): void
    {
        $_SESSION['tiendaTpv'] = ['ano' => '2019', 'idTienda' => '42'];

        $comprobacion = new \ClaseComprobacionStockContexto();
        $contexto = $comprobacion->abrir();

        self::assertTrue($contexto['ok']);
        self::assertSame('2019', $contexto['ano']);
        self::assertSame('42', $contexto['idTienda']);

        // Los parámetros no se fijan aquí con un número: cuáles valen es cosa de la
        // instalación y cambia cuando el operador toca un ajuste. Lo que este caso
        // comprueba es que cada uno llega a su campo y con su tipo, contrastándolo con
        // la misma configuración que el sistema lee.
        $criterio = $this->parametrosDelCriterio();
        self::assertSame((int) $criterio['ventana_dias'], $contexto['ventanaDias']);
        self::assertSame((float) $criterio['c1_umbral_fraccionado'], $contexto['umbralFraccionado']);
        self::assertSame((float) $criterio['c1_umbral_magnitud'], $contexto['umbralMagnitud']);
        self::assertSame((float) $criterio['c1_umbral_por_venta'], $contexto['umbralPorVenta']);
        self::assertSame((int) $criterio['c1_timing_ventana_dias'], $contexto['timingVentanaDias']);

        $cierre = $this->ajustesDelCierre();
        self::assertSame($cierre['proveedor'], $contexto['proveedorCierre']);
        self::assertEqualsCanonicalizing($cierre['familiasExcluidas'], $contexto['familiasExcluidas']);

        // Y la configuración contra la que se compara tiene que traer algo: si los cinco
        // llegasen vacíos, las comparaciones de arriba pasarían sin que nada estuviera
        // conectado. Los dos ajustes del cierre no entran en esta guarda porque sus
        // valores vacíos son configuraciones legítimas: una instalación puede no excluir
        // ninguna familia. Que el proveedor no sea nulo sí, porque sin él el contexto no
        // habría llegado a devolver `ok`.
        self::assertNotContains('', $criterio, 'Ningun parametro del criterio puede venir vacio');
        self::assertNotNull($contexto['proveedorCierre']);

        $comprobacion->cerrar();
    }

    /**
     * Los cinco parametros del criterio, leidos por el mismo camino que el sistema:
     * ClaseParametros antepone la copia de cache del modulo al fichero del repositorio,
     * de modo que leer el XML por nuestra cuenta compararia contra otra cosa.
     *
     * @return array<string,string>
     */
    private function parametrosDelCriterio(): array
    {
        $parametros = new \ClaseParametros(RUTA_TPVFOX . '/modulos/mod_informes/parametros.xml');
        $posstock = $parametros->getNode('configuracion/posstock');

        $valores = [];
        foreach (
            ['ventana_dias', 'c1_umbral_fraccionado', 'c1_umbral_magnitud',
                'c1_umbral_por_venta', 'c1_timing_ventana_dias'] as $nombre
        ) {
            $valores[$nombre] = (string) $posstock->$nombre;
        }
        return $valores;
    }

    /** @return array{proveedor:int, familiasExcluidas:list<int>} */
    private function ajustesDelCierre(): array
    {
        $parametros = new \ClaseParametros(RUTA_TPVFOX . '/modulos/mod_reorganizacion/parametros.xml');

        $proveedor = $parametros->getNode('configuracion/cierre_stock_anual/ajustes_globales/proveedor');
        $familias = $parametros->getNode('configuracion/cierre_stock_anual/familias_excluidas');

        $excluidas = [];
        foreach ($familias->familia as $familia) {
            $excluidas[] = (int) $familia->attributes()['id'];
        }

        return [
            'proveedor' => (int) $proveedor->attributes()['id'],
            'familiasExcluidas' => $excluidas,
        ];
    }

    public function test_T2_sinSesionEstablecidaSePara(): void
    {
        unset($_SESSION['tiendaTpv']);

        $contexto = (new \ClaseComprobacionStockContexto())->abrir();

        self::assertFalse($contexto['ok']);
        self::assertNotEmpty($contexto['motivo']);
    }

    public function test_T3_objetoDelEsquemaAusenteSePara(): void
    {
        $_SESSION['tiendaTpv'] = ['ano' => '2026', 'idTienda' => '1'];

        $comprobacion = new \ClaseComprobacionStockContexto(['tabla_inexistente_de_prueba']);
        $contexto = $comprobacion->abrir();

        self::assertFalse($contexto['ok']);
        self::assertStringContainsString('tabla_inexistente_de_prueba', $contexto['motivo']);
    }

    public function test_T4_parametroDeCriterioAusenteSePara(): void
    {
        $_SESSION['tiendaTpv'] = ['ano' => '2026', 'idTienda' => '1'];

        $comprobacion = new \ClaseComprobacionStockContexto(null, ['umbral_inexistente_de_prueba']);
        $contexto = $comprobacion->abrir();

        self::assertFalse($contexto['ok']);
        self::assertStringContainsString('umbral_inexistente_de_prueba', $contexto['motivo']);
    }

    public function test_T5_proveedorDeCierreAusenteSePara(): void
    {
        $_SESSION['tiendaTpv'] = ['ano' => '2026', 'idTienda' => '1'];

        $comprobacion = new \ClaseComprobacionStockContexto(null, null, 'configuracion/ruta_inexistente_de_prueba');
        $contexto = $comprobacion->abrir();

        self::assertFalse($contexto['ok']);
        self::assertStringContainsString('proveedor', $contexto['motivo']);
    }

    public function test_T6_familiasExcluidasAusentesSePara(): void
    {
        $_SESSION['tiendaTpv'] = ['ano' => '2026', 'idTienda' => '1'];

        $comprobacion = new \ClaseComprobacionStockContexto(null, null, null, 'configuracion/ruta_inexistente_de_prueba');
        $contexto = $comprobacion->abrir();

        self::assertFalse($contexto['ok']);
        self::assertStringContainsString('familias_excluidas', $contexto['motivo']);
    }

    public function test_T7_bloqueDeLecturaAbreEnTransaccionDeSoloLectura(): void
    {
        $_SESSION['tiendaTpv'] = ['ano' => '2026', 'idTienda' => '1'];
        $comprobacion = new \ClaseComprobacionStockContexto();

        $contexto = $comprobacion->abrir();
        self::assertTrue($contexto['ok']);

        // La conexión del producto se toma de la clase de consulta, que es la única
        // del módulo que la sostiene: el bloque de lectura se abre sobre ella.
        $db = (new \ClaseComprobacionStockConsulta())->conexionBDTPV();
        $enTransaccion = $db->query('SELECT @@in_transaction AS activa')->fetch_assoc();
        self::assertSame('1', $enTransaccion['activa']);

        $escrituraRechazada = false;
        try {
            $db->query('DELETE FROM articulos WHERE id = -1');
        } catch (mysqli_sql_exception) {
            $escrituraRechazada = true;
        }
        self::assertTrue($escrituraRechazada, 'La escritura tendría que fallar dentro del bloque de solo lectura');

        $comprobacion->cerrar();
    }

    public function test_T8_elMomentoYLaFechaDeCorteSalenDeUnaSolaLecturaDelReloj(): void
    {
        // Son los dos datos de la ejecución que dependen del reloj: cuándo se hizo y
        // hasta dónde se leyó. Si cada uno lo mirase por su cuenta, una ejecución que
        // cruzase la medianoche declararía un momento de un día sobre datos de otro,
        // y nadie que leyera el resultado después podría notarlo. Saliendo de la misma
        // lectura, no pueden discrepar.
        $_SESSION['tiendaTpv'] = ['ano' => '2026', 'idTienda' => '1'];
        $comprobacion = new \ClaseComprobacionStockContexto();

        $contexto = $comprobacion->abrir();
        self::assertTrue($contexto['ok']);

        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $contexto['fechaCorte']);
        self::assertSame(
            substr($contexto['momento'], 0, 10),
            $contexto['fechaCorte'],
            'La fecha de corte es el día del momento de la ejecución'
        );

        $comprobacion->cerrar();
    }
}
