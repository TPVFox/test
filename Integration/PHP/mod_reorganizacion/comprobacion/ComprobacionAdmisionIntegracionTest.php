<?php
/**
 * Admisión del resultado del ejercicio vigente: un fallo de esquema, de resumen o de
 * correspondencia de ejercicio y tienda rechaza el fichero entero antes de calcular;
 * la falta de contraparte en el catálogo marca solo esa fila, sin hacerla desaparecer.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModReorganizacion\Comprobacion;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra;

final class ComprobacionAdmisionIntegracionTest extends CasoIntegracion
{
    protected bool $compartirConexionConElProducto = true;

    private Siembra $siembra;

    private array $rutasEmitidas = [];

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION ??= [];
        $_SESSION['usuarioTpv'] = ['id' => 7];
        $this->siembra = new Siembra($this->db);
        $this->incluirTPVFox('/modulos/mod_reorganizacion/clases/ClaseComprobacionAdmision.php');
        $this->incluirTPVFox('/modulos/mod_reorganizacion/clases/ClaseComprobacionEmision.php');
        $this->incluirTPVFox('/modulos/mod_reorganizacion/clases/ClaseComprobacionIntercambioXML.php');
    }

    protected function tearDown(): void
    {
        unset($_SESSION['usuarioTpv']);
        foreach ($this->rutasEmitidas as $ruta) {
            if (file_exists($ruta)) {
                unlink($ruta);
            }
        }
        parent::tearDown();
    }

    private function rutaTemporal(): string
    {
        $ruta = sys_get_temp_dir() . '/comprobacion-admision-' . uniqid('', true) . '.xml';
        $this->rutasEmitidas[] = $ruta;
        return $ruta;
    }

    /** Contexto de operación del ejercicio VIGENTE (n), quien emite el fichero. */
    private function contextoVigente(array $cambios = []): array
    {
        return array_merge([
            'ano' => '2026',
            'idTienda' => '1',
            'ventanaDias' => 7,
            'umbralSobrestock' => 50.0,
            'proveedorCierre' => 112,
            'familiasExcluidas' => [],
        ], $cambios);
    }

    /** Contexto de operación de ESTE ejercicio, el anterior (n-1), quien admite. */
    private function contextoAnterior(array $cambios = []): array
    {
        return array_merge([
            'ano' => '2025',
            'idTienda' => '1',
            'ventanaDias' => 7,
            'umbralSobrestock' => 50.0,
            'proveedorCierre' => 112,
            'familiasExcluidas' => [],
        ], $cambios);
    }

    private function emitirFichero(array $filas, array $contextoVigente): string
    {
        $emision = new \ClaseComprobacionEmision();
        $composicion = $emision->componer($filas, $contextoVigente, false);
        $ruta = $this->rutaTemporal();
        $emision->emitir($composicion, $ruta);
        return $ruta;
    }

    public function test_T1_unFicheroQueNoValidaContraElEsquemaSeRechazaEnteroAntesDeCalcular(): void
    {
        $ruta = $this->rutaTemporal();
        file_put_contents($ruta, '<?xml version="1.0"?><ComprobacionIntercambio idOrigen="x"><Meta/></ComprobacionIntercambio>');

        $admision = new \ClaseComprobacionAdmision();
        $resultado = $admision->admitir($ruta, $this->contextoAnterior());

        self::assertFalse($resultado['ok']);
        self::assertStringContainsString('esquema', $resultado['motivo']);
    }

    public function test_T2_unFicheroEditadoTrasEmitirseSeRechazaPorElResumen(): void
    {
        $ruta = $this->emitirFichero(
            [['idArticulo' => 10, 'saldoAlCorte' => -5.0, 'minimoAlcanzado' => -8.5, 'saldoDeApertura' => 3.0, 'marcado' => true, 'tipoIncidencia' => null, 'condicionesConocidas' => []]],
            $this->contextoVigente()
        );

        $editado = str_replace('<SaldoAlCorte>-5</SaldoAlCorte>', '<SaldoAlCorte>-500</SaldoAlCorte>', file_get_contents($ruta));
        file_put_contents($ruta, $editado);

        $admision = new \ClaseComprobacionAdmision();
        $resultado = $admision->admitir($ruta, $this->contextoAnterior());

        self::assertFalse($resultado['ok']);
        self::assertStringContainsString('resumen', $resultado['motivo']);
    }

    public function test_T3_unFicheroDeOtroEjercicioSeRechazaPorNoCorresponder(): void
    {
        $ruta = $this->emitirFichero(
            [['idArticulo' => 10, 'saldoAlCorte' => -5.0, 'minimoAlcanzado' => -8.5, 'saldoDeApertura' => 3.0, 'marcado' => true, 'tipoIncidencia' => null, 'condicionesConocidas' => []]],
            $this->contextoVigente(['ano' => '2099'])
        );

        $admision = new \ClaseComprobacionAdmision();
        $resultado = $admision->admitir($ruta, $this->contextoAnterior());

        self::assertFalse($resultado['ok']);
        self::assertStringContainsString('ejercicio', $resultado['motivo']);
    }

    public function test_T4_unProductoSinContraparteQuedaMarcadoNoComparableYNoDesaparece(): void
    {
        $ruta = $this->emitirFichero(
            [['idArticulo' => 999999, 'saldoAlCorte' => -1.0, 'minimoAlcanzado' => -1.0, 'saldoDeApertura' => 0.0, 'marcado' => true, 'tipoIncidencia' => null, 'condicionesConocidas' => []]],
            $this->contextoVigente()
        );

        $admision = new \ClaseComprobacionAdmision();
        $resultado = $admision->admitir($ruta, $this->contextoAnterior());

        self::assertTrue($resultado['ok']);
        self::assertCount(1, $resultado['filas'], 'La fila sin contraparte no desaparece');
        self::assertFalse($resultado['filas'][0]['comparable']);
    }

    public function test_T5_unProductoConContraparteQuedaEmparejadoYElResultadoSeAdmite(): void
    {
        $idArticulo = $this->siembra->articulo('Producto con contraparte en el anterior');

        $ruta = $this->emitirFichero(
            [['idArticulo' => $idArticulo, 'saldoAlCorte' => -3.0, 'minimoAlcanzado' => -6.0, 'saldoDeApertura' => 3.0, 'marcado' => true, 'tipoIncidencia' => 'C1a', 'condicionesConocidas' => ['periodo_no_consolidado']]],
            $this->contextoVigente()
        );

        $admision = new \ClaseComprobacionAdmision();
        $resultado = $admision->admitir($ruta, $this->contextoAnterior());

        self::assertTrue($resultado['ok']);
        self::assertTrue($resultado['filas'][0]['comparable']);
        self::assertSame($idArticulo, $resultado['filas'][0]['idArticulo']);
        self::assertSame(112, $resultado['contexto']['proveedorCierre']);
    }

    public function test_T6_unFicheroSinFilasSeAdmiteConElConjuntoVacio(): void
    {
        $ruta = $this->emitirFichero([], $this->contextoVigente());

        $admision = new \ClaseComprobacionAdmision();
        $resultado = $admision->admitir($ruta, $this->contextoAnterior());

        self::assertTrue($resultado['ok']);
        self::assertSame([], $resultado['filas']);
    }
}
