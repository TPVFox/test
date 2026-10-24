<?php

/**
 * Las vistas comunes de los informes (`modulos/mod_informes/tareas/vistas/`): las opciones del selector de
 * periodo y de los tipos de incidencia, la barra de botones entre periodos y las ventanas de filtro de
 * familias y de proveedores.
 *
 * Son funciones que componen HTML sin consultar la base: se cargan tal cual; la barra, después de las
 * opciones, de las que usa los tipos de incidencia.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModInformes;

use PHPUnit\Framework\TestCase;

final class VistasInformesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['vistaOpcionesPeriodo', 'vistaBarraPeriodos', 'vistaModalFamilias', 'vistaModalProveedores'] as $vista) {
            require_once RUTA_TPVFOX . '/modulos/mod_informes/tareas/vistas/' . $vista . '.php';
        }
    }

    /** @return list<string> Los valores de las opciones, en orden. */
    private function valores(string $html): array
    {
        preg_match_all('/<option value="([^"]*)"/', $html, $m);

        return $m[1];
    }

    /**
     * @comportamiento En un año que no es el actual, las opciones son todos los periodos del tipo, con su
     *   etiqueta, habilitados: 53 semanas en 2025, 24 quincenas, 12 meses con el año, y los trimestres,
     *   cuatrimestres, semestres y estaciones con sus meses.
     */
    public function test_lasOpcionesDeUnAnioQueNoEsElActual(): void
    {
        $semanas = renderOpcionesPeriodo('semana', 2025, 7, false, 2026);
        $meses = renderOpcionesPeriodo('mes', 2025, 7, false, 2026);

        self::assertCount(53, $this->valores($semanas));
        self::assertStringContainsString('<option value="53">Semana 53</option>', $semanas);
        self::assertCount(24, $this->valores(renderOpcionesPeriodo('quincena', 2025, 0, false, 2026)));
        self::assertStringContainsString('<option value="4">2ª quincena Feb</option>', renderOpcionesPeriodo('quincena', 2025, 0, false, 2026));
        self::assertSame(['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12'], $this->valores($meses));
        self::assertStringContainsString('<option value="2">Feb 2025</option>', $meses);
        self::assertStringContainsString('<option value="4">T4 (Oct–Dic)</option>', renderOpcionesPeriodo('trimestre', 2025, 0, false, 2026));
        self::assertStringContainsString('<option value="3">C3 (Sep–Dic)</option>', renderOpcionesPeriodo('cuatrimestre', 2025, 0, false, 2026));
        self::assertStringContainsString('<option value="2">2º semestre (Jul–Dic)</option>', renderOpcionesPeriodo('semestre', 2025, 0, false, 2026));
        self::assertStringContainsString('<option value="5">Navidad (01 Dic – 31 Dic)</option>', renderOpcionesPeriodo('estacional', 2025, 0, false, 2026));
        self::assertStringNotContainsString('disabled', $semanas);
        self::assertSame('', renderOpcionesPeriodo('decada', 2025, 0, false, 2026));
    }

    /** @comportamiento En el año actual, las opciones son los periodos que llegan, y los que están en la ventana van deshabilitados y avisados. */
    public function test_lasOpcionesDelAnioActualConLaVentana(): void
    {
        $html = renderOpcionesPeriodo('mes', 2026, 14, false, 2026, [['numero' => 9, 'en_ventana' => false], ['numero' => 10, 'en_ventana' => true]]);

        self::assertSame(['9', '10'], $this->valores($html));
        self::assertStringContainsString('<option value="9">Sep 2026</option>', $html);
        self::assertStringContainsString('<option value="10" disabled title="Dentro de la ventana de consolidación (14 días)">Oct 2026 ⚠ (ventana)</option>', $html);
    }

    /**
     * @comportamiento El tipo anual ofrece los tipos de incidencia —el C4 solo con el stock inactivo—; en
     *   el año actual con ventana, todos deshabilitados salvo el C6b, que la admite.
     */
    public function test_lasOpcionesAnualesSonLosTiposDeIncidencia(): void
    {
        $pasado = renderOpcionesPeriodo('anual', 2025, 7, false, 2026);
        $conInactivo = renderOpcionesPeriodo('anual', 2025, 0, true, 2026);
        $actual = renderOpcionesPeriodo('anual', 2026, 7, false, 2026);

        self::assertSame(['caso1', 'caso2', 'caso3a', 'caso3b', 'caso5', 'caso6a', 'caso6b', 'caso7a', 'caso7b', 'caso9'], $this->valores($pasado));
        self::assertSame('caso4', $this->valores($conInactivo)[10]);
        self::assertStringContainsString('<option value="caso1" disabled', $actual);
        self::assertStringContainsString('<option value="caso6b">Punto de Pedido (ROP histórico fijo)</option>', $actual);
    }

    /**
     * @comportamiento La barra pinta un botón por periodo, con su etiqueta corta; el activo, resaltado; los
     *   de la ventana, deshabilitados. En el tipo anual, un botón por tipo de incidencia, todos
     *   deshabilitados salvo el C6b si el periodo está en la ventana. Sin periodos, nada.
     */
    public function test_laBarraDePeriodos(): void
    {
        $meses = renderBarraPeriodos('mes', 2025, 2, 7, false, false, [['numero' => 1, 'en_ventana' => false], ['numero' => 2, 'en_ventana' => false], ['numero' => 3, 'en_ventana' => true]]);
        $anual = renderBarraPeriodos('anual', 2026, 'caso6b', 7, true, false);

        self::assertStringContainsString('<button type="button" class="btn btn-default btn-xs"  onclick="posstockNavegar(1)" id="posstockBtn_1">Ene</button>', $meses);
        self::assertStringContainsString('class="btn btn-primary btn-xs"  onclick="posstockNavegar(2)"', $meses);
        self::assertStringContainsString('class="btn btn-default btn-xs disabled" disabled title="Dentro de la ventana de consolidación (7 días)" onclick="posstockNavegar(3)"', $meses);
        self::assertStringContainsString('class="btn btn-default btn-xs disabled" disabled title="Periodo en ventana de consolidación — solo C6b disponible" onclick="posstockNavegar(\'caso1\')"', $anual);
        self::assertStringContainsString('class="btn btn-primary btn-xs"  onclick="posstockNavegar(\'caso6b\')"', $anual);
        self::assertSame('', renderBarraPeriodos('mes', 2025, 1, 0, false, false, null));
    }

    /** @comportamiento La etiqueta corta de cada tipo de periodo en la barra. */
    public function test_lasEtiquetasDeLaBarra(): void
    {
        self::assertSame(['S7', 'Feb', 'T3', 'C2', '1S', '2S', 'Año', 'Pri', 'Febb', '9', 'E9'], [_etiquetaBoton('semana', 7), _etiquetaBoton('mes', 2), _etiquetaBoton('trimestre', 3), _etiquetaBoton('cuatrimestre', 2), _etiquetaBoton('semestre', 1), _etiquetaBoton('semestre', 2), _etiquetaBoton('anual', 1), _etiquetaBoton('estacional', 2), _etiquetaBoton('quincena', 4), _etiquetaBoton('otro', 9), _etiquetaBoton('estacional', 9)]);
    }

    /**
     * @comportamiento La ventana de familias ofrece todas para buscar, y pinta en cada lista —incluir y
     *   excluir— las ya elegidas, como texto, con su botón de quitar.
     */
    public function test_laVentanaDeFamilias(): void
    {
        $html = renderModalFamilias([['id' => 3, 'nombre' => 'Frutas & verduras']], [['id' => 3, 'nombre' => 'Frutas & verduras']], [['id' => 4, 'nombre' => '<b>Bebidas</b>']]);

        self::assertStringContainsString('<datalist id="posstockFamiliasDatalist"><option value="Frutas &amp; verduras"></datalist>', $html);
        self::assertStringContainsString('<table class="table table-condensed table-bordered small" id="posstockTabla_incluir"><thead><tr><th>Familia</th><th style="width:32px;"></th></tr></thead><tbody><tr data-id="3" data-nombre="Frutas &amp; verduras"><td>Frutas &amp; verduras</td>', $html);
        self::assertStringContainsString('<tr data-id="4" data-nombre="&lt;b&gt;Bebidas&lt;/b&gt;"><td>&lt;b&gt;Bebidas&lt;/b&gt;</td>', $html);
        self::assertStringContainsString("onclick=\"posstockAgregarFamilia('excluir')\"", $html);
        self::assertStringContainsString('onclick="posstockAplicarFiltroFamilias()"', $html);
    }

    /** @comportamiento La ventana de proveedores ofrece todos para buscar, pinta los elegidos como texto y marca «todos los artículos» si se pidió. */
    public function test_laVentanaDeProveedores(): void
    {
        $marcada = renderModalProveedores([['id' => 7, 'nombre' => 'Frutas "Sur"']], [['id' => 7, 'nombre' => 'Frutas "Sur"']], true);
        $sinMarcar = renderModalProveedores([], [], false);

        self::assertStringContainsString('<option value="Frutas &quot;Sur&quot;">', $marcada);
        self::assertStringContainsString('<tr data-id="7" data-nombre="Frutas &quot;Sur&quot;"><td>Frutas &quot;Sur&quot;</td>', $marcada);
        self::assertStringContainsString('<input type="checkbox" id="posstockChkTodosProductos" checked>', $marcada);
        self::assertStringContainsString('<input type="checkbox" id="posstockChkTodosProductos">', $sinMarcar);
    }
}
