<?php
/**
 * Qué clase de cambio hay entre la versión que rige y la que se va a poner a regir. Lo
 * decide el sistema comparándolas, no quien pide el cambio: así un cambio de piezas no
 * puede quedar registrado como un simple ajuste.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModRelaciones;

use PHPUnit\Framework\TestCase;

final class RelacionesCambioTest extends TestCase
{
    public function test_T1_lasMismasPiezasConOtraCantidadEsUnAjusteDeProporciones(): void
    {
        $nuevas = [$this->linea(1, 'entrada'), $this->linea(2, 'salida', 0.25)];

        self::assertSame('proporciones', $this->cambio($this->datos(), $this->lineasQueRigen(), $this->datos(), $nuevas));
    }

    public function test_T2_unProductoMasEsUnCambioDeComposicion(): void
    {
        $nuevas = $this->lineasQueRigen();
        $nuevas[] = $this->linea(3, 'salida');

        self::assertSame('composicion', $this->cambio($this->datos(), $this->lineasQueRigen(), $this->datos(), $nuevas));
    }

    public function test_T3_unProductoMenosEsUnCambioDeComposicion(): void
    {
        $regian = [$this->linea(1, 'entrada'), $this->linea(2, 'salida'), $this->linea(3, 'salida')];

        self::assertSame('composicion', $this->cambio($this->datos(), $regian, $this->datos(), $this->lineasQueRigen()));
    }

    public function test_T4_elMismoProductoQuePasaDeSalirAEntrarEsUnCambioDeComposicion(): void
    {
        $regian = [$this->linea(1, 'entrada'), $this->linea(2, 'entrada'), $this->linea(3, 'salida')];
        $nuevas = [$this->linea(1, 'entrada'), $this->linea(2, 'salida'), $this->linea(3, 'salida')];

        self::assertSame('composicion', $this->cambio($this->datos(), $regian, $this->datos(), $nuevas));
    }

    public function test_T5_soloOtroNombreEsUnCambioDeDatos(): void
    {
        self::assertSame('datos', $this->cambio($this->datos(), $this->lineasQueRigen(), $this->datos('Pollo de corral'), $this->lineasQueRigen()));
    }

    public function test_T6_soloOtraDescripcionUOtraVidaUtilEsUnCambioDeDatos(): void
    {
        $otraDescripcion = ['nombre' => 'Pollo', 'descripcion' => 'Despiezar en frío', 'diasViabilidad' => 3];
        $otraVidaUtil = ['nombre' => 'Pollo', 'descripcion' => null, 'diasViabilidad' => 5];

        self::assertSame('datos', $this->cambio($this->datos(), $this->lineasQueRigen(), $otraDescripcion, $this->lineasQueRigen()));
        self::assertSame('datos', $this->cambio($this->datos(), $this->lineasQueRigen(), $otraVidaUtil, $this->lineasQueRigen()));
    }

    public function test_T7_siNoCambiaNadaNoHayCambio(): void
    {
        self::assertSame('', $this->cambio($this->datos(), $this->lineasQueRigen(), $this->datos(), $this->lineasQueRigen()));
    }

    public function test_T8_otraCantidadYOtroNombreALaVezEsUnAjusteDeProporciones(): void
    {
        $nuevas = [$this->linea(1, 'entrada'), $this->linea(2, 'salida', 0.25)];

        self::assertSame('proporciones', $this->cambio($this->datos(), $this->lineasQueRigen(), $this->datos('Otro nombre'), $nuevas));
    }

    /**
     * @comportamiento Si cambian los productos, el cambio es de composición aunque además
     *   cambien las cantidades: es lo que impide registrar otra fórmula como la misma afinada.
     */
    public function test_T9_otroProductoYOtrasCantidadesALaVezEsUnCambioDeComposicion(): void
    {
        $nuevas = [$this->linea(1, 'entrada', 2), $this->linea(2, 'salida', 0.25), $this->linea(3, 'salida', 0.1)];

        self::assertSame('composicion', $this->cambio($this->datos(), $this->lineasQueRigen(), $this->datos(), $nuevas));
    }

    public function test_T10_laMismaCantidadEnOtraUnidadEsUnAjusteDeProporciones(): void
    {
        $nuevas = [$this->linea(1, 'entrada'), $this->linea(2, 'salida', 1, 'ud')];

        self::assertSame('proporciones', $this->cambio($this->datos(), $this->lineasQueRigen(), $this->datos(), $nuevas));
    }

    /**
     * @comportamiento La misma cantidad escrita de dos maneras no es un cambio: la base la
     *   devuelve con seis decimales y quien edita la manda sin ellos.
     */
    public function test_T11_laMismaCantidadEscritaConOSinDecimalesNoEsUnCambio(): void
    {
        $comoLaBase = [$this->linea(1, 'entrada', '1.000000'), $this->linea(2, 'salida', '1.000000')];

        self::assertSame('', $this->cambio($this->datos(), $comoLaBase, $this->datos(), $this->lineasQueRigen()));
    }

    public function test_T12_unaLineaEliminadaNoCuentaAlComparar(): void
    {
        $regian = $this->lineasQueRigen();
        $regian[] = $this->linea(9, 'salida', 1, 'kg', 'Eliminado');

        self::assertSame('', $this->cambio($this->datos(), $regian, $this->datos(), $this->lineasQueRigen()));
    }

    private function datos(string $nombre = 'Pollo'): array
    {
        return ['nombre' => $nombre, 'descripcion' => null, 'diasViabilidad' => 3];
    }

    private function linea(int $idArticulo, string $sentido, $cantidad = 1, string $unidad = 'kg', string $estado = 'Activo'): array
    {
        return ['idArticulo' => $idArticulo, 'sentido' => $sentido, 'cantidad' => $cantidad, 'unidad' => $unidad, 'estado' => $estado];
    }

    private function lineasQueRigen(): array
    {
        return [$this->linea(1, 'entrada'), $this->linea(2, 'salida')];
    }

    private function cambio(array $datosQueRigen, array $lineasQueRigen, array $datosNuevos, array $lineasNuevas): string
    {
        global $RutaServidor, $HostNombre, $URLCom;
        require_once RUTA_TPVFOX . '/modulos/mod_relaciones/clases/Relaciones.php';

        return (new \Relaciones())->tipoDeCambio($datosQueRigen, $lineasQueRigen, $datosNuevos, $lineasNuevas);
    }
}
