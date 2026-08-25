<?php
/**
 * Emparejamiento de filas admitidas con el catálogo de este ejercicio: lógica pura,
 * sin base de datos. La clave es idArticulo únicamente; ninguna fila desaparece.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModReorganizacion\Comprobacion;

use PHPUnit\Framework\TestCase;

final class ComprobacionAdmisionTest extends TestCase
{
    private static function instancia(): \ClaseComprobacionAdmision
    {
        global $RutaServidor, $HostNombre, $URLCom;
        require_once RUTA_TPVFOX . '/modulos/mod_reorganizacion/clases/ClaseComprobacionAdmision.php';
        return new \ClaseComprobacionAdmision();
    }

    private function fila(int $idArticulo): array
    {
        return [
            'idArticulo' => $idArticulo,
            'saldoAlCorte' => -5.0,
            'minimoAlcanzado' => -8.5,
            'marcado' => true,
            'tipoIncidencia' => null,
            'condicionesConocidas' => [],
        ];
    }

    public function test_T1_emparejaPorIdArticuloAunqueNombreYCodigoDeBarrasCambienEntreEjercicios(): void
    {
        $comprobacion = self::instancia();

        // El mismo producto en el catálogo de este ejercicio, con nombre y código de
        // barras distintos de los que tenía cuando se emitió el fichero: ninguno de
        // los dos participa en el emparejamiento, solo idArticulo.
        $catalogoDeEsteEjercicio = [
            ['idArticulo' => 10, 'nombre' => 'Camiseta talla M (renombrada)', 'codigoBarras' => '8400000000999'],
        ];

        $resultado = $comprobacion->emparejar([$this->fila(10)], $catalogoDeEsteEjercicio);

        self::assertTrue($resultado[0]['comparable']);
        self::assertSame(10, $resultado[0]['idArticulo']);
    }

    public function test_T2_sinCorrespondenciaQuedaMarcadaNoComparableYLaFilaNoDesaparece(): void
    {
        $comprobacion = self::instancia();

        $resultado = $comprobacion->emparejar([$this->fila(99)], [['idArticulo' => 10]]);

        self::assertCount(1, $resultado, 'La fila sin contraparte no desaparece');
        self::assertFalse($resultado[0]['comparable']);
    }
}
