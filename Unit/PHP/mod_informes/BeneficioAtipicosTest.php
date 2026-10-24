<?php

/**
 * La detección de productos atípicos del beneficio por familia
 * (`modulos/mod_informes/tareas/helpers/beneficioAtipicos.php`): por subfamilia, el margen negativo o
 * fuera de la media más o menos tres desviaciones típicas; por familia y en global, ordenados de menor a
 * mayor margen; y la búsqueda de una familia en el informe.
 *
 * El fichero no consulta la base ni incluye nada: se carga tal cual.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModInformes;

use PHPUnit\Framework\TestCase;

final class BeneficioAtipicosTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once RUTA_TPVFOX . '/modulos/mod_informes/tareas/helpers/beneficioAtipicos.php';
    }

    /**
     * Una familia del informe con una subfamilia por cada lista de márgenes; cada producto lleva de
     * identificador su posición y de nombre su margen.
     *
     * @param list<list<float>> $subfamilias
     * @return array<string, mixed>
     */
    private function familia(int|string $idN1, string $nombre, array $subfamilias, int $primerId = 1): array
    {
        $id = $primerId;
        $resultado = ['idN1' => $idN1, 'nombreN1' => $nombre, 'subfamilias' => []];
        foreach ($subfamilias as $n => $margenes) {
            $articulos = [];
            foreach ($margenes as $margen) {
                $articulos[] = ['idArticulo' => $id++, 'articulo_name' => (string) $margen, 'margen_pct' => $margen, 'totalVenta' => 10.0];
            }
            $resultado['subfamilias'][] = ['idN2' => $n + 1, 'nombreN2' => 'Sub ' . ($n + 1), 'articulos' => $articulos];
        }

        return $resultado;
    }

    /**
     * @comportamiento La media es la aritmética, cero sin valores; la desviación típica es la poblacional.
     */
    public function test_laMediaYLaDesviacion(): void
    {
        self::assertSame(0.0, calcularMedia([]));
        self::assertSame(5.0, calcularMedia([2, 4, 4, 4, 5, 5, 7, 9]));
        self::assertSame(2.0, calcularDesviacionTipicaPoblacional([2, 4, 4, 4, 5, 5, 7, 9], 5.0));
        self::assertSame(0.0, calcularDesviacionTipicaPoblacional([], 0.0));
    }

    /**
     * @comportamiento En una subfamilia, un margen negativo es atípico, con su motivo, la media, la
     *   desviación y los límites de la subfamilia; los demás, dentro de los límites, no salen.
     */
    public function test_unMargenNegativoEsAtipico(): void
    {
        $atipicos = detectarAtipicosFamilia($this->familia(7, 'Zz Verdura', [[30.0, 32.0, -5.0]]));

        self::assertSame([3, 1], [$atipicos['total_articulos'], $atipicos['total_atipicos']]);
        self::assertSame(['-5', 'margen_negativo', 'Zz Verdura', 'Sub 1'], [$atipicos['filas'][0]['articulo_name'], $atipicos['filas'][0]['motivo'], $atipicos['filas'][0]['familia'], $atipicos['filas'][0]['subfamilia']]);
        self::assertEqualsWithDelta([19.0, 16.99, -31.97, 69.97], [$atipicos['filas'][0]['media_subfamilia'], $atipicos['filas'][0]['sd_subfamilia'], $atipicos['filas'][0]['limite_inferior'], $atipicos['filas'][0]['limite_superior']], 0.01);
    }

    /**
     * @comportamiento Con once productos, el que se aparta más de tres desviaciones de la media de su
     *   subfamilia es atípico aunque su margen sea positivo; en global se juntan los de todas las familias,
     *   de menor a mayor margen, y se cuentan todos los productos analizados.
     */
    public function test_elQueSeAparta(): void
    {
        $verdura = $this->familia(7, 'Zz Verdura', [[...array_fill(0, 10, 20.0), 80.0]]);
        $fruta = $this->familia(8, 'Zz Fruta', [[10.0, -1.0, 12.0], [15.0, -3.0]], 100);

        $familia = detectarAtipicosFamilia($verdura);
        $global = detectarAtipicosGlobal([$verdura, $fruta]);

        self::assertSame(['80'], array_column($familia['filas'], 'articulo_name'));
        self::assertSame('fuera_3sd', $familia['filas'][0]['motivo']);
        self::assertSame(['-3', '-1', '80'], array_column($global['filas'], 'articulo_name'));
        self::assertSame([16, 3], [$global['total_articulos'], $global['total_atipicos']]);
    }

    /**
     * @comportamiento Dos atípicos con el mismo margen se ordenan por su identificador.
     */
    public function test_elEmpateSeOrdenaPorIdentificador(): void
    {
        $atipicos = detectarAtipicosFamilia($this->familia(7, 'Zz Verdura', [[-2.0, 40.0], [-2.0]], 5));

        self::assertSame([5, 7], array_column($atipicos['filas'], 'idArticulo'));
    }

    /**
     * @comportamiento La familia se busca por su identificador, sea número o texto, también la de los
     *   productos sin familia; si no está, no se encuentra.
     */
    public function test_laBusquedaDeLaFamilia(): void
    {
        $familias = [$this->familia(7, 'Zz Verdura', [[1.0]]), $this->familia('__sin_familia__', 'Sin familia', [[1.0]])];

        self::assertSame('Zz Verdura', buscarFamiliaBeneficio($familias, '7')['nombreN1']);
        self::assertSame('Sin familia', buscarFamiliaBeneficio($familias, '__sin_familia__')['nombreN1']);
        self::assertNull(buscarFamiliaBeneficio($familias, 8));
    }
}
