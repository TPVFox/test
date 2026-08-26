<?php
/**
 * Lo que la admisión decide sin tocar la base: si llegó un fichero con el que se pueda
 * seguir —y con qué motivo si no llegó—, y el emparejamiento de las filas admitidas con
 * el catálogo de este ejercicio, donde la clave es idArticulo y ninguna fila desaparece.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModReorganizacion\Comprobacion;

use PHPUnit\Framework\TestCase;

final class ComprobacionStockAdmisionTest extends TestCase
{
    private static function instancia(): \ClaseComprobacionStockAdmision
    {
        global $RutaServidor, $HostNombre, $URLCom;
        require_once RUTA_TPVFOX . '/modulos/mod_reorganizacion/clases/ClaseComprobacionStockAdmision.php';
        return new \ClaseComprobacionStockAdmision();
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

    public function test_T3_conFilasMezcladasCadaUnaRecibeSuMarcaYNingunaSePierde(): void
    {
        $comprobacion = self::instancia();

        // El caso real: parte del fichero tiene contraparte y parte no. Los dos casos
        // anteriores prueban un solo producto cada uno, y con uno solo un recorrido que
        // marcara todas las filas por igual pasaría igual de bien.
        $resultado = $comprobacion->emparejar(
            [$this->fila(10), $this->fila(99), $this->fila(11)],
            [['idArticulo' => 10], ['idArticulo' => 11]]
        );

        self::assertCount(3, $resultado);
        self::assertTrue($resultado[0]['comparable']);
        self::assertFalse($resultado[1]['comparable']);
        self::assertTrue($resultado[2]['comparable']);
    }

    public function test_T4_unFicheroQueLlegaBienSeAdmiteASeguir(): void
    {
        $comprobacion = self::instancia();

        self::assertTrue($comprobacion->subidaAdmisible(['error' => UPLOAD_ERR_OK], '2M')['ok']);
    }

    public function test_T5_noHaberElegidoFicheroSeDistingueDeQueNoQuepa(): void
    {
        $comprobacion = self::instancia();

        $sinElegir = $comprobacion->subidaAdmisible(['error' => UPLOAD_ERR_NO_FILE], '2M');
        $noCabe = $comprobacion->subidaAdmisible(['error' => UPLOAD_ERR_INI_SIZE], '2M');

        // Las dos son «no hay fichero con el que seguir», y quien las junta en un solo
        // motivo deja al operador sin saber cuál de las dos cosas hacer.
        self::assertFalse($sinElegir['ok']);
        self::assertFalse($noCabe['ok']);
        self::assertNotSame($sinElegir['motivo'], $noCabe['motivo']);
    }

    public function test_T6_elMotivoDeQueNoQuepaLlevaElLimiteDelServidor(): void
    {
        $comprobacion = self::instancia();

        // Sin la cifra el aviso no es accionable: el operador no sabe por cuánto se pasa
        // ni en cuántas partes tendría que emitirlo.
        $resultado = $comprobacion->subidaAdmisible(['error' => UPLOAD_ERR_INI_SIZE], '2M');

        self::assertStringContainsString('2M', $resultado['motivo']);
    }

    public function test_T7_unaSubidaCortadaNoSeConfundeConUnFicheroQueNoCabe(): void
    {
        $comprobacion = self::instancia();

        $resultado = $comprobacion->subidaAdmisible(['error' => UPLOAD_ERR_PARTIAL], '2M');

        self::assertFalse($resultado['ok']);
        self::assertStringContainsString('incompleto', $resultado['motivo']);
    }

    public function test_T8_sinCampoDeFicheroNoSeSigue(): void
    {
        $comprobacion = self::instancia();

        self::assertFalse($comprobacion->subidaAdmisible(null, '2M')['ok']);
        self::assertFalse($comprobacion->subidaAdmisible([], '2M')['ok']);
    }

    public function test_T9_unaCausaDelServidorNoSeAtribuyeAQuienSube(): void
    {
        $comprobacion = self::instancia();

        // Sin directorio temporal donde escribir, el fallo es de la instalación. Decirle
        // al operador que su fichero no vale sería atribuirle algo que no hizo.
        $resultado = $comprobacion->subidaAdmisible(['error' => UPLOAD_ERR_NO_TMP_DIR], '2M');

        self::assertFalse($resultado['ok']);
        self::assertStringContainsString('servidor', $resultado['motivo']);
    }
}
