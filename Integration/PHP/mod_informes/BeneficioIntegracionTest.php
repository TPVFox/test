<?php

/**
 * El beneficio por familia (`modulos/mod_informes/clases/BeneficioCalculator.php` y, de `tareas/`, los
 * atípicos y la exportación completa a CSV y a PDF): el beneficio de cada producto con su coste y sus
 * mermas, las familias y el resumen, el flujo de compras y ventas, el stock medio y el GMROI, la selección
 * de familias, lo que se exporta y el bloque del informe en su página.
 *
 * Cada caso se ejecuta en un PHP aparte, con el arranque de la aplicación, la sesión del usuario de
 * pruebas y su permiso, en una transacción sobre su conexión que nunca se confirma; lo que necesita se
 * siembra en ella con la siembra de las pruebas, en 2019. Las exportaciones terminan con `exit`: lo que
 * escriben se recoge al apagarse el PHP, y el PDF no se guarda.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModInformes;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class BeneficioIntegracionTest extends CasoIntegracion
{
    private int $idUsuario;

    private int $idTienda;

    protected function setUp(): void
    {
        parent::setUp();
        $siembra = new Siembra($this->db);
        $this->idUsuario = $siembra->usuarioPorDefecto();
        $this->idTienda = $siembra->tiendaPorDefecto();
    }

    /**
     * Una familia con una subfamilia y un producto comprado en marzo de 2019 a 2 y a 3 € (diez unidades
     * de cada), vendido en un ticket cerrado de cuatro unidades a 4 €, y un ticket abierto que no cuenta.
     */
    private const BASE = '$ids["p"] = $siembra->proveedor("Zz Huerta SL"); $ids["f"] = $siembra->familia("Zz Verdura"); $ids["sf"] = $siembra->familia("Zz Hoja", $ids["f"]);'
        . ' $ids["a"] = $siembra->articulo("Zz Lechuga", ["familia" => $ids["sf"], "ultimoCoste" => 2.0, "beneficio" => 100]);'
        . ' $siembra->entradaProveedor($ids["a"], 10, "2019-03-04", ["idProveedor" => $ids["p"]]);'
        . ' $BDTpv->query("UPDATE articulos SET ultimoCoste = 3 WHERE idArticulo = " . $ids["a"]);'
        . ' (new \TPVFox\Test\Siembra\Siembra($BDTpv))->entradaProveedor($ids["a"], 10, "2019-03-20", ["idProveedor" => $ids["p"]]);'
        . ' $siembra->ventaTicket($ids["a"], 4, "2019-03-25"); $siembra->ventaTicket($ids["a"], 9, "2019-03-26", ["estado" => "Abierto"]);';

    /** Una unidad del producto de la base, dada de baja en un albarán a un cliente especial. */
    private const MERMA = ' $ids["m"] = $siembra->cliente("Zz Mermas", ["estado" => "Especial"]); $siembra->ventaAlbaranCliente($ids["a"], 1, "2019-03-26", ["idCliente" => $ids["m"]]);';

    /** El cálculo de marzo de 2019 con la opción 3. */
    private const MARZO = ' $resultado = (new BeneficioCalculator($BDTpv))->calcular(["Finicio" => "2019-03-01", "Ffinal" => "2019-03-31", "opcion" => 3]);';

    /**
     * @comportamiento El beneficio de un producto es su venta sin IVA menos su coste por las unidades
     *   vendidas y menos el valor de sus mermas —lo dado a clientes especiales—, con el coste medio
     *   ponderado de las compras del periodo; las mermas y los tickets abiertos no cuentan como venta.
     */
    public function test_elBeneficioDeCadaProducto(): void
    {
        $r = $this->ejecutar(self::BASE . self::MERMA . self::MARZO);

        $lechuga = $this->articulos($r['resultado'])['Zz Lechuga'];
        self::assertEquals(
            [4, 4, 2.5, true, 16, 10, 1, 2.5, 3.5, 21.88],
            [$lechuga['totalUnidades'], $lechuga['pvpSiva'], $lechuga['costeUsado'], $lechuga['coste_es_periodo'], $lechuga['totalVenta'], $lechuga['totalCoste'], $lechuga['udsMerma'], $lechuga['valorMerma'], $lechuga['beneficio'], $lechuga['margen_pct']]
        );
    }

    /**
     * @comportamiento Sin compras en el periodo, el coste es el medio de las compras anteriores; sin
     *   ninguna, el coste promedio del producto y, si es cero, el último coste. Las compras a un proveedor
     *   especial no cuentan.
     */
    public function test_elCosteSinComprasEnElPeriodo(): void
    {
        $siembra = '$ids["p"] = $siembra->proveedor("Zz Huerta SL"); $ids["f"] = $siembra->familia("Zz Verdura");'
            . ' $ids["b"] = $siembra->articulo("Zz Col", ["familia" => $ids["f"], "ultimoCoste" => 1.5, "beneficio" => 100]); $siembra->entradaProveedor($ids["b"], 5, "2019-01-10", ["idProveedor" => $ids["p"]]);'
            . ' $ids["c"] = $siembra->articulo("Zz Puerro", ["familia" => $ids["f"], "ultimoCoste" => 1.2, "beneficio" => 100]);'
            . ' $ids["d"] = $siembra->articulo("Zz Ajo", ["familia" => $ids["f"], "ultimoCoste" => 0.7, "beneficio" => 100]);'
            . ' foreach (["b", "c", "d"] as $x) { $siembra->ventaTicket($ids[$x], 2, "2019-03-15"); }'
            . ' $ids["e"] = $siembra->proveedor("Zz Descartes SL"); $BDTpv->query("UPDATE proveedores SET estado = \'Especial\' WHERE idProveedor = " . $ids["e"]);'
            . ' $BDTpv->query("UPDATE articulos SET ultimoCoste = 9, costepromedio = 9 WHERE idArticulo = " . $ids["b"]);'
            . ' (new \TPVFox\Test\Siembra\Siembra($BDTpv))->entradaProveedor($ids["b"], 5, "2019-03-05", ["idProveedor" => $ids["e"]]);'
            . ' $BDTpv->query("UPDATE articulos SET ultimoCoste = 5 WHERE idArticulo = " . $ids["c"]);'
            . ' $BDTpv->query("UPDATE articulos SET costepromedio = 0 WHERE idArticulo = " . $ids["d"]);';
        $r = $this->ejecutar($siembra . self::MARZO);

        $articulos = $this->articulos($r['resultado']);
        self::assertEquals([1.5, 1.2, 0.7], [$articulos['Zz Col']['costeUsado'], $articulos['Zz Puerro']['costeUsado'], $articulos['Zz Ajo']['costeUsado']]);
        self::assertFalse($articulos['Zz Col']['coste_es_periodo']);
    }

    /**
     * @comportamiento Las familias y subfamilias suman a sus productos y se ordenan de más a menos venta; lo
     *   que no tiene familia va a «Sin familia». El resumen cuenta cada producto una vez y añade aparte la
     *   merma de los productos sin venta en el periodo.
     */
    public function test_lasFamiliasYElResumen(): void
    {
        $siembra = self::BASE . self::MERMA
            . ' $ids["s"] = $siembra->articulo("Zz Suelto", ["ultimoCoste" => 1.0, "beneficio" => 100]); $siembra->ventaTicket($ids["s"], 3, "2019-03-10");'
            . ' $ids["g"] = $siembra->articulo("Zz Caducado", ["familia" => $ids["sf"], "ultimoCoste" => 0.5]); $siembra->ventaAlbaranCliente($ids["g"], 4, "2019-03-11", ["idCliente" => $ids["m"]]);';
        $r = $this->ejecutar($siembra . self::MARZO);

        $familias = $r['resultado']['familias'];
        $resumen = $r['resultado']['resumen'];
        self::assertSame(['Zz Verdura', 'Sin familia'], array_column($familias, 'nombreN1'));
        self::assertEquals([16, 10, 2.5, 3.5, 1, 'Zz Hoja'], [$familias[0]['totalVenta'], $familias[0]['totalCoste'], $familias[0]['totalMerma'], $familias[0]['beneficio'], $familias[0]['num_referencias'], $familias[0]['subfamilias'][0]['nombreN2']]);
        self::assertEquals(['__sin_familia__', '(Sin subfamilia)'], [$familias[1]['idN1'], $familias[1]['subfamilias'][0]['nombreN2']]);
        self::assertEquals([22, 13, 2.5, 2, 4.5, 4.5, 20.45], [$resumen['totalVenta'], $resumen['totalCoste'], $resumen['totalMerma'], $resumen['mermaSinVentas'], $resumen['totalMermaGlobal'], $resumen['beneficio'], $resumen['margen_pct']]);
        self::assertSame(['Zz Caducado'], array_column($resumen['articulos_merma_sin_ventas'], 'articulo_name'));
    }

    /**
     * @comportamiento El flujo suma las compras y las ventas del periodo sin y con IVA, y su diferencia; el
     *   stock medio es la media del stock al empezar y al acabar, reconstruido desde el 1 de enero, y se
     *   valora al coste del periodo; el GMROI es el margen bruto entre ese valor. Por familia y en el
     *   resumen.
     */
    public function test_elFlujoElStockYElGmroi(): void
    {
        $r = $this->ejecutar(self::BASE . ' $resultado = (new BeneficioCalculator($BDTpv))->calcular(["Finicio" => "2019-01-01", "Ffinal" => "2019-03-31", "opcion" => 3]);');

        $resumen = $r['resultado']['resumen'];
        $verdura = $r['resultado']['familias'][0];
        self::assertEquals([50, 50, 16, 16, -34], [$resumen['flujo']['compras_siva'], $resumen['flujo']['compras_civa'], $resumen['flujo']['ventas_siva'], $resumen['flujo']['ventas_civa'], $resumen['flujo']['resultado_siva']]);
        self::assertEquals([20, 0.3], [$resumen['valor_stock'], $resumen['gmroi']]);
        self::assertEquals([50, 16, 20, 0.3], [$verdura['flujo']['compras_siva'], $verdura['flujo']['ventas_siva'], $verdura['valor_stock'], $verdura['gmroi']]);
    }

    /**
     * @comportamiento Con la opción 4, el informe, la merma y el flujo se limitan a las familias elegidas;
     *   una subfamilia elegida hace de familia.
     */
    public function test_laSeleccionDeFamilias(): void
    {
        $siembra = self::BASE . self::MERMA
            . ' $ids["h"] = $siembra->familia("Zz Seta"); $ids["k"] = $siembra->articulo("Zz Champiñón", ["familia" => $ids["h"], "ultimoCoste" => 1.0, "beneficio" => 100]);'
            . ' $siembra->ventaTicket($ids["k"], 5, "2019-03-12"); $siembra->ventaAlbaranCliente($ids["k"], 2, "2019-03-13", ["idCliente" => $ids["m"]]);';
        $r = $this->ejecutar($siembra . ' $calculo = new BeneficioCalculator($BDTpv); $resultado = [];'
            . ' foreach (["f", "sf"] as $x) { $resultado[] = $calculo->calcular(["Finicio" => "2019-03-01", "Ffinal" => "2019-03-31", "opcion" => 4, "familias" => (string) $ids[$x]]); }');

        [$familia, $subfamilia] = $r['resultado'];
        self::assertSame(['Zz Verdura'], array_column($familia['familias'], 'nombreN1'));
        self::assertEquals([16, 2.5, 16, 50], [$familia['resumen']['totalVenta'], $familia['resumen']['totalMerma'], $familia['resumen']['flujo']['ventas_siva'], $familia['resumen']['flujo']['compras_siva']]);
        self::assertSame(['Zz Hoja'], array_column($subfamilia['familias'], 'nombreN1'));
    }

    /**
     * @comportamiento Los atípicos de una familia salen en CSV con el ámbito, el periodo, el criterio y los
     *   recuentos, y una fila por producto atípico con sus importes y los enlaces a su mayor y a su ficha.
     */
    public function test_losAtipicosDeUnaFamiliaEnCsv(): void
    {
        $siembra = self::BASE . ' $ids["n"] = $siembra->articulo("Zz Rúcula", ["familia" => $ids["sf"], "ultimoCoste" => 2.0, "beneficio" => -50]); $siembra->ventaTicket($ids["n"], 2, "2019-03-15");';
        $r = $this->exportar($siembra, 'exportarBeneficioAtipicosCSV.php', '["Finicio" => "2019-03-01", "Ffinal" => "2019-03-31", "opcion" => "3", "idN1" => (string) $ids["f"]]');

        $filas = $this->filasCsv($r['salida']);
        self::assertSame(['Ambito', 'Zz Verdura'], $filas[1]);
        self::assertSame([['Articulos analizados', '2'], ['Articulos atipicos', '1']], [$filas[4], $filas[5]]);
        self::assertSame(['Zz Rúcula', (string) $r['ids']['n'], '2.000', '1.0000', '2.0000', '2.00', '0.00', '-2.00', '-100.00'], array_slice($filas[8], 0, 9));
        self::assertStringContainsString('/modulos/mod_producto/DetalleMayor.php?idArticulo=' . $r['ids']['n'] . '&', $filas[8][9]);
        self::assertStringEndsWith('/modulos/mod_producto/producto.php?id=' . $r['ids']['n'], $filas[8][10]);
    }

    /**
     * @comportamiento Los atípicos en global salen en PDF; sin la familia ni el global, la petición se
     *   rechaza por faltar parámetros, y una familia que no está en el informe, por no encontrarse.
     */
    public function test_losAtipicosEnPdfYSusErrores(): void
    {
        $global = $this->exportar(self::BASE, 'imprimirBeneficioAtipicosPDF.php', '["Finicio" => "2019-03-01", "Ffinal" => "2019-03-31", "opcion" => "3", "idN1" => "__global__", "global" => "1"]');
        $sinFamilia = $this->exportar(self::BASE, 'exportarBeneficioAtipicosCSV.php', '["Finicio" => "2019-03-01", "Ffinal" => "2019-03-31"]');
        $otraFamilia = $this->exportar(self::BASE, 'imprimirBeneficioAtipicosPDF.php', '["Finicio" => "2019-03-01", "Ffinal" => "2019-03-31", "idN1" => "999999"]');

        self::assertStringStartsWith('%PDF', $global['salida']);
        self::assertSame('Faltan parametros obligatorios.', $sinFamilia['salida']);
        self::assertSame('No se encontro la familia solicitada.', $otraFamilia['salida']);
    }

    /**
     * @comportamiento La exportación completa a CSV da, según la opción, las familias, las familias con sus
     *   subfamilias o también sus productos, con sus importes; con una familia pedida, solo esa.
     */
    public function test_laExportacionCompletaEnCsv(): void
    {
        $siembra = self::BASE . ' $ids["s"] = $siembra->articulo("Zz Suelto", ["ultimoCoste" => 1.0, "beneficio" => 100]); $siembra->ventaTicket($ids["s"], 3, "2019-03-10");';
        $marzo = '"Finicio" => "2019-03-01", "Ffinal" => "2019-03-31"';
        $familias = $this->filasCsv($this->exportar($siembra, 'exportarBeneficioCompletoCSV.php', '[' . $marzo . ', "opcion" => "1"]')['salida']);
        $subfamilias = $this->filasCsv($this->exportar($siembra, 'exportarBeneficioCompletoCSV.php', '[' . $marzo . ', "opcion" => "2", "idN1" => (string) $ids["f"]]')['salida']);
        $productos = $this->filasCsv($this->exportar($siembra, 'exportarBeneficioCompletoCSV.php', '[' . $marzo . ', "opcion" => "3"]')['salida']);

        self::assertSame(['Vista', 'Solo familias'], $familias[2]);
        self::assertSame([['Zz Verdura', '1', '16.00', '10.00', '0.00', '6.00', '37.50'], ['Sin familia', '1', '6.00', '3.00', '0.00', '3.00', '50.00']], array_slice($familias, 6, 2));
        self::assertSame(['Ambito', 'Zz Verdura'], $subfamilias[1]);
        self::assertSame([['FAMILIA', 'Zz Verdura'], ['subfamilia', '  Zz Hoja']], [array_slice($subfamilias[6], 0, 2), array_slice($subfamilias[7], 0, 2)]);
        self::assertCount(1, array_filter($subfamilias, static fn($f) => $f[0] === 'FAMILIA'));
        self::assertContains(['articulo', '    Zz Lechuga', '', '4.000', '4.0000', '2.5000', '16.00', '0.00', '6.00', '37.50'], array_map(static fn($f) => array_replace($f, [2 => '']), $productos));
    }

    /** @comportamiento La exportación completa a PDF se genera con cada opción. */
    public function test_laExportacionCompletaEnPdf(): void
    {
        $productos = $this->exportar(self::BASE, 'imprimirBeneficioCompletoPDF.php', '["Finicio" => "2019-03-01", "Ffinal" => "2019-03-31", "opcion" => "3"]');
        $familias = $this->exportar(self::BASE, 'imprimirBeneficioCompletoPDF.php', '["Finicio" => "2019-03-01", "Ffinal" => "2019-03-31", "opcion" => "1", "global" => "1"]');

        self::assertStringStartsWith('%PDF', $productos['salida']);
        self::assertStringStartsWith('%PDF', $familias['salida']);
    }

    /**
     * @comportamiento La página del informe 6 pinta el beneficio con los nombres como texto y ofrece la
     *   exportación completa y los atípicos de cada familia con el periodo y la opción pedidos.
     */
    public function test_elBloqueDelBeneficioEnLaPagina(): void
    {
        $r = $this->ejecutar(self::BASE . ' $BDTpv->query("UPDATE articulos SET articulo_name = \'Zz <b>Lechuga</b>\' WHERE idArticulo = " . $ids["a"]);'
            . ' $_GET = ["id" => "6", "opcion" => "3", "Finicio" => "2019-03-01", "Ffinal" => "2019-03-31"]; ob_start(); include "informes.php"; $resultado = ob_get_clean();', 'informes.php');

        self::assertStringContainsString('Zz &lt;b&gt;Lechuga&lt;/b&gt;', $r['resultado']);
        self::assertStringContainsString('tareas/exportarBeneficioCompletoCSV.php?Finicio=2019-03-01&amp;Ffinal=2019-03-31&amp;opcion=3', $r['resultado']);
        self::assertStringContainsString('tareas/exportarBeneficioAtipicosCSV.php?Finicio=2019-03-01&amp;Ffinal=2019-03-31&amp;opcion=3&amp;familias=&amp;idN1=' . $r['ids']['f'], $r['resultado']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_informes/clases/BeneficioCalculator.php:527-564
     *
     * @group defecto
     * @group integridad
     * @group medio
     *
     * @que-ocurre-hoy El stock medio que valora el GMROI se reconstruye solo con los tickets cerrados y sin
     *   las mermas: un ticket cobrado y aún sin cerrar, o una unidad dada de baja a un cliente especial,
     *   siguen contando como existencias, aunque el informe los cuenta como venta o como merma.
     * @que-deberia-ocurrir Que el stock reste todas las salidas que el propio informe cuenta.
     * @por-que-ocurre La reconstrucción del stock filtra los tickets por «Cerrado» y deja fuera a los
     *   clientes especiales, con criterios distintos de los de la venta y la merma.
     * @como-deberia-funcionar Reconstruir el stock con los mismos estados y clientes que la venta y la merma.
     */
    public function test_elStockMedioRestaLosTicketsCobradosYLasMermas(): void
    {
        $r = $this->ejecutar(self::BASE . self::MERMA . ' $siembra->ventaTicket($ids["a"], 2, "2019-03-27", ["estado" => "Cobrado"]);'
            . ' $resultado = (new BeneficioCalculator($BDTpv))->calcular(["Finicio" => "2019-01-01", "Ffinal" => "2019-03-31", "opcion" => 3]);');

        self::assertEquals(16.25, $r['resultado']['resumen']['valor_stock']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_informes/clases/BeneficioCalculator.php:153, :185, :260
     *
     * @group defecto
     * @group integridad
     * @group bajo
     *
     * @que-ocurre-hoy Un ticket que lleva el mismo producto a dos precios cuenta como dos ventas del
     *   producto.
     * @que-deberia-ocurrir Que cuente como una.
     * @por-que-ocurre Las ventas se agrupan por precio y cada grupo cuenta sus documentos; luego se suman.
     * @como-deberia-funcionar Contar los documentos distintos del producto, sin partir por precio.
     */
    public function test_unTicketConDosPreciosCuentaUnaVenta(): void
    {
        $siembra = '$ids["a"] = $siembra->articulo("Zz Lechuga", ["ultimoCoste" => 2.0, "beneficio" => 100]); $ticket = $siembra->ventaTicket($ids["a"], 1, "2019-03-25");'
            . ' $BDTpv->query("UPDATE articulos SET beneficio = 200 WHERE idArticulo = " . $ids["a"]); (new \TPVFox\Test\Siembra\Siembra($BDTpv))->lineaTicket($ticket, $ids["a"], 1);';
        $r = $this->ejecutar($siembra . self::MARZO);

        self::assertSame(1, $this->articulos($r['resultado'])['Zz Lechuga']['num_ventas']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_informes/tareas/exportarBeneficioCompletoCSV.php:104-128
     *
     * @group defecto
     * @group integridad
     * @group bajo
     *
     * @que-ocurre-hoy En la exportación completa a CSV con productos, las filas de familia y de subfamilia
     *   dejan vacía la columna del coste, que sí llevan las vistas sin productos.
     * @que-deberia-ocurrir Que la fila de familia lleve su coste.
     * @por-que-ocurre La fila se escribe con el coste en blanco, como el identificador y la cantidad.
     * @como-deberia-funcionar Escribir el coste de la familia y de la subfamilia en su columna.
     */
    public function test_laFilaDeFamiliaLlevaSuCoste(): void
    {
        $filas = $this->filasCsv($this->exportar(self::BASE, 'exportarBeneficioCompletoCSV.php', '["Finicio" => "2019-03-01", "Ffinal" => "2019-03-31", "opcion" => "3"]')['salida']);

        self::assertSame('10.00', $filas[6][5]);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_informes/tareas/exportarBeneficioCompletoCSV.php:31-39; modulos/mod_informes/tareas/imprimirBeneficioCompletoPDF.php:32-37
     *
     * @group defecto
     * @group integridad
     * @group bajo
     *
     * @que-ocurre-hoy La exportación completa que ofrece la página no pide familia ni global, y sale con el
     *   nombre de la primera familia como ámbito, aunque lleva todas.
     * @que-deberia-ocurrir Que sin familia pedida el ámbito sea el global.
     * @por-que-ocurre Solo se toma por global cuando se pide expresamente; si no, el ámbito es la primera
     *   familia del informe.
     * @como-deberia-funcionar Tomar por global la petición sin familia.
     */
    public function test_laExportacionCompletaDeLaPaginaEsGlobal(): void
    {
        $siembra = self::BASE . ' $ids["s"] = $siembra->articulo("Zz Suelto", ["ultimoCoste" => 1.0, "beneficio" => 100]); $siembra->ventaTicket($ids["s"], 3, "2019-03-10");';
        $filas = $this->filasCsv($this->exportar($siembra, 'exportarBeneficioCompletoCSV.php', '["Finicio" => "2019-03-01", "Ffinal" => "2019-03-31", "opcion" => "3", "familias" => ""]')['salida']);

        self::assertSame(['Ambito', 'GLOBAL'], $filas[1]);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_informes/tareas/exportarBeneficioCompletoCSV.php:1-29; modulos/mod_informes/tareas/exportarBeneficioAtipicosCSV.php:1-30; modulos/mod_informes/tareas/imprimirBeneficioCompletoPDF.php:1-30; modulos/mod_informes/tareas/imprimirBeneficioAtipicosPDF.php:1-31
     *
     * @group defecto
     * @group seguridad
     * @group alto
     *
     * @que-ocurre-hoy Sin sesión, las exportaciones del beneficio responden: quien conozca la dirección
     *   descarga las ventas, los costes y los márgenes de la tienda.
     * @que-deberia-ocurrir Que sin sesión, o sin el permiso de los informes, no se exporte nada.
     * @por-que-ocurre Las cuatro arrancan la aplicación y calculan sin mirar la sesión ni el permiso.
     * @como-deberia-funcionar Comprobar la sesión y el permiso al empezar.
     */
    public function test_sinSesionNoSeExportaElBeneficio(): void
    {
        $r = $this->exportar(self::BASE, 'exportarBeneficioCompletoCSV.php', '["Finicio" => "2019-03-01", "Ffinal" => "2019-03-31", "opcion" => "3"]', false);

        self::assertStringNotContainsString('Zz Lechuga', $r['salida']);
    }

    /**
     * Los productos del resultado por nombre, de todas las familias.
     *
     * @param array<string, mixed> $resultado
     * @return array<string, array<string, mixed>>
     */
    private function articulos(array $resultado): array
    {
        $articulos = [];
        foreach ($resultado['familias'] as $familia) {
            foreach ($familia['subfamilias'] as $subfamilia) {
                foreach ($subfamilia['articulos'] as $articulo) {
                    $articulos[$articulo['articulo_name']] = $articulo;
                }
            }
        }

        return $articulos;
    }

    /** @return list<list<string>> Las filas del CSV, sin la marca de orden de bytes. */
    private function filasCsv(string $csv): array
    {
        $lineas = explode("\n", rtrim(substr($csv, 3), "\n"));

        return array_map(static fn($linea) => str_getcsv($linea, ';', '"', '\\'), $lineas);
    }

    /**
     * Pide una exportación de `tareas/` con lo sembrado y los parámetros que se le den, escritos como
     * expresión PHP para poder usar `$ids`, y devuelve lo que escribe y lo sembrado.
     *
     * @return array{salida: string, ids: array<string, int>, errores: string}
     */
    private function exportar(string $siembra, string $script, string $parametros, bool $conSesion = true): array
    {
        $r = $this->ejecutar($siembra . ' $_GET = ' . $parametros . '; ob_start();'
            . ' register_shutdown_function(function () use (&$ids) { $salida = (string) ob_get_clean(); echo "\n@@DATOS@@" . json_encode(["resultado" => base64_encode($salida), "ids" => $ids]); });'
            . ' include "tareas/' . $script . '";', 'tareas/' . $script, $conSesion);

        return ['salida' => (string) base64_decode((string) $r['resultado']), 'ids' => $r['ids'], 'errores' => $r['errores']];
    }

    /**
     * Ejecuta el código en un PHP aparte, desde la carpeta del módulo, con la sesión, el permiso y la
     * siembra de las pruebas, en una transacción que no se confirma, y devuelve lo que deja en
     * `$resultado`, lo sembrado en `$ids` y los avisos. Sin sesión, tampoco se da el permiso.
     *
     * @return array<string, mixed>
     */
    private function ejecutar(string $codigo, string $pagina = 'tareas.php', bool $conSesion = true): array
    {
        $sesion = $conSesion ? [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => (string) $this->idUsuario, 'group_id' => '1', 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => (string) $this->idTienda, 'razonsocial' => 'Tienda de pruebas SL', 'NombreComercial' => 'Tienda de pruebas', 'nif' => 'B00000000', 'direccion' => 'Calle de pruebas 1', 'telefono' => '986000000', 'ano' => '2026'],
        ] : [];
        $permiso = $conSesion
            ? ' new ClasePermisos($Usuario, $BDTpv);'
                . ' $BDTpv->query("UPDATE permisos SET permiso = 1 WHERE idUsuario = ' . $this->idUsuario . ' AND modulo = \'mod_informes\'");'
                . ' $ClasePermisos = $thisTpv->permisos = new ClasePermisos($Usuario, $BDTpv);'
            : '';
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["DOCUMENT_ROOT"] = ' . var_export(dirname(RUTA_TPVFOX), true) . ';'
            . ' $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = ' . var_export('/TPVFox/modulos/mod_informes/' . $pagina, true) . ';'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_informes', true) . ');'
            . ' $_POST = ["pulsado" => ""]; require ' . var_export(RUTA_TPVFOX . '/inicial.php', true) . ';'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . $permiso
            . ' include_once $URLCom . "/modulos/mod_informes/clases/ClaseInformes.php";'
            . ' require ' . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . ';'
            . ' $siembra = new \TPVFox\Test\Siembra\Siembra($BDTpv); $ids = []; $resultado = null;'
            . ' ' . $codigo
            . ' echo "\n@@DATOS@@" . json_encode(["resultado" => $resultado, "ids" => $ids], JSON_INVALID_UTF8_SUBSTITUTE);';

        $errores = (string) tempnam(sys_get_temp_dir(), 'beneficio');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -d error_log=/dev/null -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [, $datos] = array_pad(explode('@@DATOS@@', implode("\n", $salida), 2), 2, 'null');
        $ejecucion = ((json_decode($datos, true) ?? []) + ['resultado' => null, 'ids' => []]) + ['errores' => (string) file_get_contents($errores)];
        unlink($errores);

        return $ejecucion;
    }
}
