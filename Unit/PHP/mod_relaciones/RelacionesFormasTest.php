<?php
/**
 * Lo que la admisión decide sin tocar la base: si lo que se pide guardar responde a la
 * forma de su tipo —cuántos productos entran y cuántos salen—, si algún producto se repite
 * y si cada cantidad viene con su unidad y con la precisión de lo que se mide.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModRelaciones;

use PHPUnit\Framework\TestCase;

final class RelacionesFormasTest extends TestCase
{
    public function test_T1_unTipoDeUnoAVariosAdmiteUnaEntradaYVariasSalidas(): void
    {
        $lineas = [$this->linea(1, 'entrada'), $this->linea(2, 'salida'), $this->linea(3, 'salida'), $this->linea(4, 'salida')];

        self::assertSame('admitida', $this->admitir($this->tipo('1:N'), $lineas)['datos']);
    }

    public function test_T2_unTipoDeUnoAVariosRechazaDosEntradas(): void
    {
        $lineas = [$this->linea(1, 'entrada'), $this->linea(2, 'entrada'), $this->linea(3, 'salida')];

        self::assertStringContainsString('una sola entrada', $this->motivo($this->tipo('1:N'), $lineas));
    }

    public function test_T3_unTipoDeUnoAVariosRechazaQueNoSalgaNada(): void
    {
        self::assertStringContainsString('al menos una salida', $this->motivo($this->tipo('1:N'), [$this->linea(1, 'entrada')]));
    }

    public function test_T4_unTipoDeVariosAUnoAdmiteVariasEntradasYUnaSalida(): void
    {
        $lineas = [$this->linea(1, 'entrada'), $this->linea(2, 'entrada'), $this->linea(3, 'entrada'), $this->linea(4, 'salida')];

        self::assertSame('admitida', $this->admitir($this->tipo('N:1'), $lineas)['datos']);
    }

    public function test_T5_unTipoDeVariosAUnoRechazaDosSalidas(): void
    {
        $lineas = [$this->linea(1, 'entrada'), $this->linea(2, 'salida'), $this->linea(3, 'salida')];

        self::assertStringContainsString('una sola salida', $this->motivo($this->tipo('N:1'), $lineas));
    }

    public function test_T6_unTipoDeVariosAUnoRechazaQueNoEntreNada(): void
    {
        self::assertStringContainsString('al menos una entrada', $this->motivo($this->tipo('N:1'), [$this->linea(1, 'salida')]));
    }

    public function test_T7_unTipoDeUnoAUnoAdmiteUnaEntradaYUnaSalida(): void
    {
        $lineas = [$this->linea(1, 'entrada'), $this->linea(2, 'salida')];

        self::assertSame('admitida', $this->admitir($this->tipo('1:1'), $lineas)['datos']);
    }

    public function test_T8_unTipoDeUnoAUnoRechazaTresProductos(): void
    {
        $lineas = [$this->linea(1, 'entrada'), $this->linea(2, 'salida'), $this->linea(3, 'salida')];

        self::assertStringContainsString('una sola salida', $this->motivo($this->tipo('1:1'), $lineas));
    }

    public function test_T9_unTipoDeUnoAUnoRechazaQueNoEntreNada(): void
    {
        self::assertStringContainsString('al menos una entrada', $this->motivo($this->tipo('1:1'), [$this->linea(2, 'salida')]));
    }

    /**
     * @comportamiento La línea de un producto que esta versión quita no cuenta para la forma:
     *   un tipo de uno a varios con dos entradas guardadas, una de ellas eliminada, tiene una.
     */
    public function test_T10_unaLineaEliminadaNoCuentaParaLaForma(): void
    {
        $lineas = [$this->linea(1, 'entrada'), $this->linea(9, 'entrada', 1, 'kg', 'Eliminado'), $this->linea(2, 'salida')];

        self::assertSame('admitida', $this->admitir($this->tipo('1:N'), $lineas)['datos']);
    }

    public function test_T11_elMismoProductoDosVecesSeRechaza(): void
    {
        $lineas = [$this->linea(1, 'entrada'), $this->linea(2, 'salida'), $this->linea(2, 'salida')];

        self::assertStringContainsString('repetido', $this->motivo($this->tipo('1:N'), $lineas));
    }

    /**
     * @comportamiento Para saber si un producto se repite sí cuenta la línea eliminada: el
     *   mismo producto no puede estar quitado y presente a la vez en la misma versión.
     */
    public function test_T12_unProductoEliminadoYPresenteALaVezSeRechazaPorRepetido(): void
    {
        $lineas = [$this->linea(1, 'entrada'), $this->linea(2, 'salida'), $this->linea(2, 'salida', 1, 'kg', 'Eliminado')];

        self::assertStringContainsString('repetido', $this->motivo($this->tipo('1:N'), $lineas));
    }

    public function test_T13_siElTipoLlevaCantidadUnaLineaSinCantidadSeRechaza(): void
    {
        $lineas = [$this->linea(1, 'entrada'), $this->linea(2, 'salida', null, 'kg')];

        self::assertStringContainsString('cantidad', $this->motivo($this->tipo('1:1'), $lineas));
    }

    public function test_T14_unaCantidadSinUnidadSeRechaza(): void
    {
        $lineas = [$this->linea(1, 'entrada'), $this->linea(2, 'salida', 6, null)];

        self::assertStringContainsString('unidad', $this->motivo($this->tipo('1:1'), $lineas));
    }

    public function test_T15_siElTipoNoLlevaCantidadSeAdmitenLasLineasSinCantidadNiUnidad(): void
    {
        $lineas = [$this->linea(1, 'entrada', null, null), $this->linea(2, 'salida', null, null)];

        self::assertSame('admitida', $this->admitir($this->tipo('1:1', 0), $lineas)['datos']);
    }

    public function test_T16_siElTipoNoLlevaCantidadUnaLineaConCantidadSeRechaza(): void
    {
        $lineas = [$this->linea(1, 'entrada', null, null), $this->linea(2, 'salida', 1, 'ud')];

        self::assertStringContainsString('no lleva cantidad', $this->motivo($this->tipo('1:1', 0), $lineas));
    }

    public function test_T17_unKiloYMedioSeAdmite(): void
    {
        $lineas = [$this->linea(1, 'entrada', 1.5, 'kg'), $this->linea(2, 'salida', 0.001, 'kg')];

        self::assertSame('admitida', $this->admitir($this->tipo('1:1'), $lineas)['datos']);
    }

    public function test_T18_unaUnidadYMediaSeRechaza(): void
    {
        $lineas = [$this->linea(1, 'entrada', 1.5, 'ud'), $this->linea(2, 'salida', 1, 'ud')];

        self::assertStringContainsString('enteras', $this->motivo($this->tipo('1:1'), $lineas));
    }

    /**
     * @comportamiento Una cantidad con más precisión de la que da una balanza se rechaza, no
     *   se redondea: guardar el redondeo sería guardar un dato que nadie midió.
     */
    public function test_T19_unPesoConMasDeTresDecimalesSeRechazaYNoSeRedondea(): void
    {
        $lineas = [$this->linea(1, 'entrada', 0.2345, 'kg'), $this->linea(2, 'salida', 1, 'kg')];

        self::assertStringContainsString('tres decimales', $this->motivo($this->tipo('1:1'), $lineas));
    }

    public function test_T20_unaCantidadDeCeroSeRechaza(): void
    {
        $lineas = [$this->linea(1, 'entrada', 0, 'kg'), $this->linea(2, 'salida', 1, 'kg')];

        self::assertStringContainsString('mayor que cero', $this->motivo($this->tipo('1:1'), $lineas));
    }

    public function test_T21_aUnaLineaEliminadaNoSeLeExigeCantidad(): void
    {
        $lineas = [$this->linea(1, 'entrada'), $this->linea(2, 'salida'), $this->linea(3, 'salida', null, null, 'Eliminado')];

        self::assertSame('admitida', $this->admitir($this->tipo('1:N'), $lineas)['datos']);
    }

    public function test_T22_sinTipoNoSeAdmiteNada(): void
    {
        self::assertStringContainsString('no existe', $this->motivo(null, [$this->linea(1, 'entrada'), $this->linea(2, 'salida')]));
    }

    public function test_T23_unTipoDeBajaNoAdmiteUnaRelacionNueva(): void
    {
        $lineas = [$this->linea(1, 'entrada'), $this->linea(2, 'salida')];

        self::assertStringContainsString('de baja', $this->motivo($this->tipo('1:1', 1, 'Baja'), $lineas, true));
    }

    /**
     * @comportamiento Dar de baja un tipo no congela las relaciones que ya lo tienen: se
     *   pueden seguir cambiando, y lo que no se puede es crear otra nueva con él.
     */
    public function test_T24_unaRelacionQueYaExistiaSePuedeCambiarAunqueSuTipoEsteDeBaja(): void
    {
        $lineas = [$this->linea(1, 'entrada'), $this->linea(2, 'salida')];

        self::assertSame('admitida', $this->admitir($this->tipo('1:1', 1, 'Baja'), $lineas, false)['datos']);
    }

    public function test_T25_unaFormaQueNoEsNingunaDeLasTresSeRechaza(): void
    {
        $lineas = [$this->linea(1, 'entrada'), $this->linea(2, 'salida')];

        self::assertStringContainsString('forma', $this->motivo($this->tipo('2:2'), $lineas));
    }

    /**
     * @comportamiento Cuando falla más de una cosa, el motivo es siempre el de la primera
     *   comprobación: el tipo antes que la forma, la forma antes que los repetidos y estos
     *   antes que las cantidades. Así el mismo dato devuelve siempre el mismo motivo.
     */
    public function test_T26_conVariosDefectosElMotivoEsElDeLaPrimeraComprobacion(): void
    {
        $dosEntradasYRepetido = [$this->linea(1, 'entrada'), $this->linea(1, 'entrada'), $this->linea(2, 'salida', null, null)];

        self::assertStringContainsString('de baja', $this->motivo($this->tipo('1:1', 1, 'Baja'), $dosEntradasYRepetido, true));
        self::assertStringContainsString('una sola entrada', $this->motivo($this->tipo('1:1'), $dosEntradasYRepetido));

        $repetidoYSinCantidad = [$this->linea(1, 'entrada'), $this->linea(2, 'salida', null, null), $this->linea(2, 'salida', 1, 'kg', 'Eliminado')];
        self::assertStringContainsString('repetido', $this->motivo($this->tipo('1:1'), $repetidoYSinCantidad));
    }

    public function test_T27_unProductoQueNiEntraNiSaleSeRechaza(): void
    {
        $lineas = [$this->linea(1, 'entrada'), $this->linea(2, 'ninguno')];

        self::assertStringContainsString('entra o sale', $this->motivo($this->tipo('1:1'), $lineas));
    }

    public function test_T28_unaUnidadQueNoEsKilosNiUnidadesSeRechaza(): void
    {
        $lineas = [$this->linea(1, 'entrada', 1, 'litros'), $this->linea(2, 'salida')];

        self::assertStringContainsString('unidad', $this->motivo($this->tipo('1:1'), $lineas));
    }

    private function tipo(string $forma, int $llevaCantidad = 1, string $estado = 'Activo'): array
    {
        return ['id' => 1, 'nombre' => 'Tipo de prueba', 'forma' => $forma, 'llevaCantidad' => $llevaCantidad, 'estado' => $estado];
    }

    private function linea(int $idArticulo, string $sentido, $cantidad = 1, ?string $unidad = 'kg', string $estado = 'Activo'): array
    {
        return ['idArticulo' => $idArticulo, 'sentido' => $sentido, 'cantidad' => $cantidad, 'unidad' => $unidad, 'estado' => $estado];
    }

    private function admitir(?array $tipo, array $lineas, bool $esNueva = true): array
    {
        global $RutaServidor, $HostNombre, $URLCom;
        require_once RUTA_TPVFOX . '/modulos/mod_relaciones/clases/RelacionesFormas.php';

        return (new \RelacionesFormas())->admitir($tipo, $lineas, $esNueva);
    }

    private function motivo(?array $tipo, array $lineas, bool $esNueva = true): string
    {
        $respuesta = $this->admitir($tipo, $lineas, $esNueva);
        self::assertArrayNotHasKey('datos', $respuesta, 'Se esperaba un rechazo');
        self::assertSame('warning', $respuesta['error']['tipo']);

        return $respuesta['error']['mensaje'];
    }
}
