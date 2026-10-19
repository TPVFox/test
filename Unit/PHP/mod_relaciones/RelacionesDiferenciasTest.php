<?php
/**
 * Qué líneas marca la vista de relación como distintas de la versión que rige. Las marcas
 * tienen que decir lo mismo que registrará el historial al activar: una pieza añadida o
 * quitada es un cambio de composición, y una cantidad distinta, un ajuste. Por eso cada caso
 * comprueba también que las marcas casan con el tipo de cambio que deduce el sistema.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModRelaciones;

use PHPUnit\Framework\TestCase;

final class RelacionesDiferenciasTest extends TestCase
{
    public function test_T1_unaPiezaAnadidaSeMarcaComoNueva(): void
    {
        $nuevas = $this->lineasQueRigen();
        $nuevas[] = $this->linea(3, 'salida', 0.1);

        self::assertSame(['1|entrada' => '', '2|salida' => '', '3|salida' => 'nueva'], $this->marcas($this->lineasQueRigen(), $nuevas));
        $this->assertCasaConElTipoDeCambio($this->lineasQueRigen(), $nuevas);
    }

    public function test_T2_unaPiezaQuitadaSeMarcaComoQuitadaYSigueALaVista(): void
    {
        $regian = $this->lineasQueRigen();
        $regian[] = $this->linea(3, 'salida', 0.1);

        $diferencias = $this->diferencias($regian, $this->lineasQueRigen());

        self::assertSame(['1|entrada' => '', '2|salida' => '', '3|salida' => 'quitada'], $this->marcasDe($diferencias));
        self::assertEquals(0.1, $diferencias[2]['cantidad'], 'La pieza quitada conserva la cantidad que tenía');
        $this->assertCasaConElTipoDeCambio($regian, $this->lineasQueRigen());
    }

    public function test_T3_otraCantidadSeMarcaConLaQueRige(): void
    {
        $nuevas = [$this->linea(1, 'entrada'), $this->linea(2, 'salida', 0.25)];

        $diferencias = $this->diferencias($this->lineasQueRigen(), $nuevas);

        self::assertSame(['1|entrada' => '', '2|salida' => 'cambiada'], $this->marcasDe($diferencias));
        self::assertEquals(1, $diferencias[1]['cantidadQueRige']);
        self::assertSame('kg', $diferencias[1]['unidadQueRige']);
        $this->assertCasaConElTipoDeCambio($this->lineasQueRigen(), $nuevas);
    }

    public function test_T4_laMismaCantidadEnOtraUnidadSeMarcaComoCambiada(): void
    {
        $nuevas = [$this->linea(1, 'entrada'), $this->linea(2, 'salida', 1, 'ud')];

        self::assertSame(['1|entrada' => '', '2|salida' => 'cambiada'], $this->marcas($this->lineasQueRigen(), $nuevas));
        $this->assertCasaConElTipoDeCambio($this->lineasQueRigen(), $nuevas);
    }

    /**
     * @comportamiento Un producto que pasa de salir a entrar es otra composición: se marca
     *   quitado del lado en que estaba y nuevo en el otro.
     */
    public function test_T5_elMismoProductoEnElOtroLadoSeMarcaQuitadoEnUnoYNuevoEnElOtro(): void
    {
        $regian = [$this->linea(1, 'entrada'), $this->linea(2, 'entrada'), $this->linea(3, 'salida')];
        $nuevas = [$this->linea(1, 'entrada'), $this->linea(2, 'salida'), $this->linea(3, 'salida')];

        self::assertSame(['1|entrada' => '', '2|salida' => 'nueva', '3|salida' => '', '2|entrada' => 'quitada'], $this->marcas($regian, $nuevas));
        $this->assertCasaConElTipoDeCambio($regian, $nuevas);
    }

    /**
     * @comportamiento La base devuelve las cantidades con seis decimales y la pantalla las
     *   manda como se escribieron: 0,25 y 0,250000 son la misma cantidad y no se marcan.
     */
    public function test_T6_laMismaCantidadEscritaDeOtraFormaNoSeMarca(): void
    {
        $comoLaBase = [$this->linea(1, 'entrada', '1.000000'), $this->linea(2, 'salida', '0.250000')];
        $comoLaPantalla = [$this->linea(1, 'entrada', '1'), $this->linea(2, 'salida', '0.25')];

        self::assertSame(['1|entrada' => '', '2|salida' => ''], $this->marcas($comoLaBase, $comoLaPantalla));
        $this->assertCasaConElTipoDeCambio($comoLaBase, $comoLaPantalla);
    }

    public function test_T7_sinVersionQueRijaNoHayNadaQueMarcar(): void
    {
        self::assertSame(['1|entrada' => '', '2|salida' => ''], $this->marcas([], $this->lineasQueRigen()));
    }

    /** Las marcas de cada línea, por producto y sentido, en el orden en que llegan. */
    private function marcas(array $regian, array $nuevas): array
    {
        return $this->marcasDe($this->diferencias($regian, $nuevas));
    }

    private function marcasDe(array $diferencias): array
    {
        $marcas = [];
        foreach ($diferencias as $linea) {
            $marcas[$linea['idArticulo'] . '|' . $linea['sentido']] = $linea['marca'];
        }

        return $marcas;
    }

    /**
     * Las marcas dicen lo mismo que el historial: una nueva o una quitada es un cambio de
     * composición; si solo hay cantidades cambiadas, un ajuste; y sin marcas, ningún cambio.
     */
    private function assertCasaConElTipoDeCambio(array $regian, array $nuevas): void
    {
        $marcas = array_values($this->marcas($regian, $nuevas));
        $segunLasMarcas = '';
        if (in_array('nueva', $marcas, true) || in_array('quitada', $marcas, true)) {
            $segunLasMarcas = 'composicion';
        } elseif (in_array('cambiada', $marcas, true)) {
            $segunLasMarcas = 'proporciones';
        }

        self::assertSame($this->relaciones()->tipoDeCambio($this->datos(), $regian, $this->datos(), $nuevas), $segunLasMarcas);
    }

    private function diferencias(array $regian, array $nuevas): array
    {
        return $this->relaciones()->diferencias($regian, $nuevas);
    }

    private function relaciones(): object
    {
        global $RutaServidor, $HostNombre, $URLCom;
        require_once RUTA_TPVFOX . '/modulos/mod_relaciones/clases/Relaciones.php';

        return new \Relaciones();
    }

    private function datos(): array
    {
        return ['nombre' => 'Pollo', 'descripcion' => null, 'diasViabilidad' => 3];
    }

    private function linea(int $idArticulo, string $sentido, $cantidad = 1, string $unidad = 'kg'): array
    {
        return ['idArticulo' => $idArticulo, 'sentido' => $sentido, 'cantidad' => $cantidad, 'unidad' => $unidad, 'estado' => 'Activo'];
    }

    private function lineasQueRigen(): array
    {
        return [$this->linea(1, 'entrada'), $this->linea(2, 'salida')];
    }
}
