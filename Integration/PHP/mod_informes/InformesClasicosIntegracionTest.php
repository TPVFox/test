<?php

/**
 * Los informes clásicos (`modulos/mod_informes/clases/ClaseInformes.php`, `InformesFiltros.php`,
 * `CosteFluctuacionCalculator.php`, la orden `getCosteFluctuacionData` y las páginas `ListaInformes.php` e
 * `informes.php`): las compras por proveedor y por familia, las ventas por familia, la fluctuación del
 * coste, el filtro de familias y la lista y la página del informe.
 *
 * Cada caso se ejecuta en un PHP aparte, con el arranque de la aplicación, la sesión del usuario de
 * pruebas y su permiso, en una transacción sobre su conexión que nunca se confirma; lo que necesita se
 * siembra en ella con la siembra de las pruebas, en 2019. La clase de informes lee la conexión de la
 * aplicación, que comparte la transacción.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModInformes;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class InformesClasicosIntegracionTest extends CasoIntegracion
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

    /** Un proveedor con dos albaranes de 2019 de un mismo producto a 2 y a 3 €, y una línea eliminada. */
    private const COMPRAS = '$ids["p"] = $siembra->proveedor("Zz Frutas SL"); $ids["f"] = $siembra->familia("Zz Fruta"); $ids["sf"] = $siembra->familia("Zz Fruta de hueso", $ids["f"]);'
        . ' $ids["a"] = $siembra->articulo("Zz Melocotón", ["familia" => $ids["sf"], "ultimoCoste" => 2.0]);'
        . ' $siembra->entradaProveedor($ids["a"], 10, "2019-03-04", ["idProveedor" => $ids["p"]]);'
        . ' $BDTpv->query("UPDATE articulos SET ultimoCoste = 3 WHERE idArticulo = " . $ids["a"]); $s2 = new \TPVFox\Test\Siembra\Siembra($BDTpv);'
        . ' $alb = $s2->entradaProveedor($ids["a"], 10, "2019-03-20", ["idProveedor" => $ids["p"]]);'
        . ' $s2->lineaEntrada($alb, $ids["a"], 50, ["estadoLinea" => "Eliminado"]);'
        . ' $ids["b"] = $siembra->articulo("Zz Sin familia", ["ultimoCoste" => 1.0]); $siembra->entradaProveedor($ids["b"], 4, "2019-03-10", ["idProveedor" => $ids["p"]]);';

    /**
     * @comportamiento Las compras de todos los proveedores suman las líneas activas de sus albaranes del
     *   periodo por producto, con el coste medio ponderado y su marca cuando el coste cambia, y cuentan
     *   los albaranes de cada proveedor.
     */
    public function test_lasComprasPorProveedor(): void
    {
        $r = $this->ejecutar(self::COMPRAS . ' $resultado = (new ClaseInformes())->ResumenProveedores(["id" => "1", "opcion" => "1", "Finicio" => "2019-03-01", "Ffinal" => "2019-03-31"]);');

        $productos = array_column($r['resultado']['informe']['productos'], null, 'idArticulo');
        $proveedor = array_values(array_filter($r['resultado']['datos'], static fn($p) => (int) $p['idProveedor'] === $r['ids']['p']))[0];
        self::assertEquals([20, 2.5, 'OK', 2, 50], [$productos[$r['ids']['a']]['totalUnidades'], $productos[$r['ids']['a']]['costeSiva'], $productos[$r['ids']['a']]['coste_medio'], $productos[$r['ids']['a']]['num_compras'], $productos[$r['ids']['a']]['total_linea']]);
        self::assertSame(3, $proveedor['cant_albaranes']);
    }

    /**
     * @comportamiento Las compras por familia agrupan por familia y subfamilia, con el coste medio
     *   ponderado, el total y las referencias, y dejan lo que no tiene familia en «Sin familia»; ordenadas
     *   de más a menos.
     */
    public function test_lasComprasPorFamilia(): void
    {
        $r = $this->ejecutar(self::COMPRAS . ' $resultado = (new ClaseInformes())->ResumenFamilias(["id" => "2", "opcion" => "3", "Finicio" => "2019-03-01", "Ffinal" => "2019-03-31"]);');

        $familias = array_column($r['resultado'], null, 'nombreN1');
        self::assertEquals([50, 1, 'Zz Fruta de hueso', 50], [$familias['Zz Fruta']['total_linea'], $familias['Zz Fruta']['num_referencias'], $familias['Zz Fruta']['subfamilias'][0]['nombreN2'], $familias['Zz Fruta']['subfamilias'][0]['total_linea']]);
        self::assertEquals([2.5, 'OK', 2], [$familias['Zz Fruta']['subfamilias'][0]['articulos'][0]['costeSiva'], $familias['Zz Fruta']['subfamilias'][0]['articulos'][0]['coste_medio'], $familias['Zz Fruta']['subfamilias'][0]['articulos'][0]['num_compras']]);
        self::assertSame('(Sin subfamilia)', $familias['Sin familia']['subfamilias'][0]['nombreN2']);
    }

    /**
     * @comportamiento Con la selección de familias, si se elige una subfamilia, ella hace de familia y sus
     *   hijas de subfamilias; lo de fuera no entra.
     */
    public function test_laSeleccionDeFamilias(): void
    {
        $r = $this->ejecutar(self::COMPRAS . ' $resultado = (new ClaseInformes())->ResumenFamilias(["id" => "2", "opcion" => "4", "familias" => (string) $ids["sf"], "Finicio" => "2019-03-01", "Ffinal" => "2019-03-31"]); $filtro = InformesFiltros::buildFiltroOp4($BDTpv, [$ids["f"]]);');

        self::assertSame(['Zz Fruta de hueso'], array_column($r['resultado'], 'nombreN1'));
        self::assertSame(50.0, (float) $r['resultado'][0]['total_linea']);
    }

    /**
     * @comportamiento Las ventas por familia suman los tickets cerrados o cobrados y los albaranes de
     *   cliente guardados o procesados, por su precio sin IVA, sin los de clientes especiales ni los
     *   tickets abiertos.
     */
    public function test_lasVentasPorFamilia(): void
    {
        $siembra = '$ids["f"] = $siembra->familia("Zz Bebida"); $ids["a"] = $siembra->articulo("Zz Agua", ["familia" => $ids["f"], "ultimoCoste" => 1.0, "beneficio" => 100]);'
            . ' $siembra->ventaTicket($ids["a"], 3, "2019-03-05"); $siembra->ventaTicket($ids["a"], 9, "2019-03-06", ["estado" => "Abierto"]);'
            . ' $siembra->ventaAlbaranCliente($ids["a"], 2, "2019-03-07");'
            . ' $especial = $siembra->cliente("Zz Mermas", ["estado" => "Especial"]); $siembra->ventaAlbaranCliente($ids["a"], 7, "2019-03-08", ["idCliente" => $especial]);';
        $r = $this->ejecutar($siembra . ' $resultado = (new ClaseInformes())->ResumenVentasFamilias(["id" => "4", "opcion" => "3", "Finicio" => "2019-03-01", "Ffinal" => "2019-03-31"]);');

        $bebida = array_values(array_filter($r['resultado'], static fn($f) => $f['nombreN1'] === 'Zz Bebida'))[0];
        $agua = $bebida['subfamilias'][0]['articulos'][0];
        self::assertEquals([5, 2, 2, 10], [$agua['totalUnidades'], $agua['pvpSiva'], $agua['num_ventas'], $bebida['total_linea']]);
    }

    /**
     * @comportamiento La fluctuación del coste da, por producto con meses suficientes de recepciones
     *   suficientes, el coste medio de cada mes, su desviación sobre la media y la variación; un coste
     *   constante, o con menos meses que el mínimo, no sale; un proveedor especial no cuenta salvo que se
     *   pida. Por familias, la media de las desviaciones de sus productos.
     */
    public function test_laFluctuacionDelCoste(): void
    {
        $siembra = '$ids["p"] = $siembra->proveedor("Zz Aceites SL"); $ids["f"] = $siembra->familia("Zz Aceite");'
            . ' $especial = $siembra->proveedor("Zz Especial"); $BDTpv->query("UPDATE proveedores SET estado = \'Especial\' WHERE idProveedor = " . $especial);'
            . ' foreach (["Zz Oliva" => [2.0, 2.2, 2.6], "Zz Girasol" => [1.0, 1.0, 1.0], "Zz Corto" => [3.0, 3.5]] as $nombre => $costes) { $ids[$nombre] = $id = $siembra->articulo($nombre, ["familia" => $ids["f"]]);'
            . ' foreach ($costes as $m => $coste) { $BDTpv->query("UPDATE articulos SET ultimoCoste = $coste WHERE idArticulo = $id"); $s = new \TPVFox\Test\Siembra\Siembra($BDTpv); $s->entradaProveedor($id, 10, sprintf("2019-%02d-10", $m + 1), ["idProveedor" => $ids["p"]]); } }'
            . ' $BDTpv->query("UPDATE articulos SET ultimoCoste = 9 WHERE idArticulo = " . $ids["Zz Girasol"]); $s = new \TPVFox\Test\Siembra\Siembra($BDTpv); $s->entradaProveedor($ids["Zz Girasol"], 10, "2019-02-12", ["idProveedor" => $especial]);';
        $parametros = '["fecha_inicio" => "2019-01-01", "fecha_final" => "2019-03-31", "min_recepciones" => 1, "min_meses" => 3, "familias" => (string) $ids["f"]';
        $r = $this->ejecutar($siembra . ' $calc = new CosteFluctuacionCalculator($BDTpv); $resultado = ["sin" => $calc->calcular(' . $parametros . ']), "con" => $calc->calcular(' . $parametros . ', "incluir_proveedor_especial" => 1]), "familia" => $calc->calcular(' . $parametros . ', "agrupacion" => "familia"])];');

        $oliva = $r['resultado']['sin']['articulos'][0];
        self::assertSame(['Zz Oliva'], array_column($r['resultado']['sin']['articulos'], 'articulo_name'));
        self::assertEqualsWithDelta([2.2667, 26.47, -11.76], [$oliva['coste_media'], $oliva['rango_pct'], $oliva['meses'][0]['desviacion_pct']], 0.01);
        self::assertSame(['2019-01', '2019-02', '2019-03'], $r['resultado']['sin']['resumen']['meses_rango']);
        self::assertContains('Zz Girasol', array_column($r['resultado']['con']['articulos'], 'articulo_name'));
        self::assertContains('Zz Aceite', array_column($r['resultado']['familia']['familias'], 'nombreN1'));
    }

    /** @comportamiento La orden de la fluctuación responde el mismo cálculo con lo que manda la página. */
    public function test_laOrdenDeLaFluctuacion(): void
    {
        $r = $this->ejecutar('$_POST = ["pulsado" => "getCosteFluctuacionData", "fecha_inicio" => "2019-01-01", "fecha_final" => "2019-02-28", "min_recepciones" => "2", "min_meses" => "1"]; ob_start(); include "tareas.php"; $resultado = json_decode(ob_get_clean(), true);');

        self::assertSame(['2019-01-01', '2019-02-28', 2, 1, 'articulo'], [$r['resultado']['coste_fluctuacion']['filtros']['fecha_inicio'], $r['resultado']['coste_fluctuacion']['filtros']['fecha_final'], $r['resultado']['coste_fluctuacion']['filtros']['min_recepciones'], $r['resultado']['coste_fluctuacion']['filtros']['min_meses'], $r['resultado']['coste_fluctuacion']['filtros']['agrupacion']]);
    }

    /**
     * @comportamiento La lista enseña los informes con sus opciones y la casilla de ejecutar a quien tiene
     *   el permiso; la página del informe pinta su tabla con los nombres como texto y el total.
     */
    public function test_laListaYLaPaginaDelInforme(): void
    {
        $lista = $this->ejecutar('ob_start(); include "ListaInformes.php"; $resultado = ob_get_clean();', 'ListaInformes.php');
        $pagina = $this->ejecutar(self::COMPRAS . ' $BDTpv->query("UPDATE familias SET familiaNombre = \'Zz <b>Fruta</b>\' WHERE idFamilia = " . $ids["f"]); $_GET = ["id" => "2", "opcion" => "1", "Finicio" => "2019-03-01", "Ffinal" => "2019-03-31"]; ob_start(); include "informes.php"; $resultado = ob_get_clean();', 'informes.php');

        self::assertStringContainsString('Suma compras por familias', $lista['resultado']);
        self::assertStringContainsString('Fluctuacion de coste mensual', $lista['resultado']);
        self::assertStringContainsString('Zz &lt;b&gt;Fruta&lt;/b&gt;', $pagina['resultado']);
        self::assertStringContainsString('Listado entre las fechas :2019-03-01-----2019-03-31', $pagina['resultado']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_informes/clases/ClaseInformes.php:105-123
     *
     * @group defecto
     * @group integridad
     * @group medio
     *
     * @que-ocurre-hoy El informe de compras por proveedores ofrece cuatro opciones —todos, facturados, sin
     *   facturar y activos—, pero solo la primera trae algo: las otras tres responden sin proveedores.
     * @que-deberia-ocurrir Que cada opción traiga los proveedores y los albaranes que dice.
     * @por-que-ocurre Solo la opción «Todos» lee los proveedores; para las demás, la lista queda vacía.
     * @como-deberia-funcionar Leer los proveedores y filtrar los albaranes por la opción elegida.
     */
    public function test_lasComprasDeLosProveedoresFacturados(): void
    {
        $r = $this->ejecutar(self::COMPRAS . ' $BDTpv->query("UPDATE albprot SET estado = \'Facturado\' WHERE idProveedor = " . $ids["p"]); $resultado = (new ClaseInformes())->ResumenProveedores(["id" => "1", "opcion" => "2", "Finicio" => "2019-03-01", "Ffinal" => "2019-03-31"]);');

        self::assertContains($r['ids']['p'], array_map('intval', array_column($r['resultado']['datos'], 'idProveedor')));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_informes/clases/CosteFluctuacionCalculator.php:380-445
     *
     * @group defecto
     * @group integridad
     * @group medio
     *
     * @que-ocurre-hoy Agrupada por familias, la fluctuación de los productos de una familia que no tienen
     *   subfamilia sale bajo su familia y, otra vez, bajo una familia «(Sin familia)» que no existe.
     * @que-deberia-ocurrir Que cada producto cuente una vez, bajo su familia.
     * @por-que-ocurre La subfamilia vacía no se asocia a ninguna familia y se cuelga de una de partida.
     * @como-deberia-funcionar Dejar sin subfamilia a los productos de la familia, dentro de ella.
     */
    public function test_losProductosSinSubfamiliaCuentanBajoSuFamilia(): void
    {
        $siembra = '$ids["p"] = $siembra->proveedor("Zz Aceites SL"); $ids["f"] = $siembra->familia("Zz Aceite"); $ids["a"] = $id = $siembra->articulo("Zz Oliva", ["familia" => $ids["f"]]);'
            . ' foreach ([2.0, 2.2, 2.6] as $m => $coste) { $BDTpv->query("UPDATE articulos SET ultimoCoste = $coste WHERE idArticulo = $id"); $s = new \TPVFox\Test\Siembra\Siembra($BDTpv); $s->entradaProveedor($id, 10, sprintf("2019-%02d-10", $m + 1), ["idProveedor" => $ids["p"]]); }';
        $r = $this->ejecutar($siembra . ' $resultado = (new CosteFluctuacionCalculator($BDTpv))->calcular(["fecha_inicio" => "2019-01-01", "fecha_final" => "2019-03-31", "min_recepciones" => 1, "min_meses" => 3, "familias" => (string) $ids["f"], "agrupacion" => "familia"]);');

        self::assertSame(['Zz Aceite'], array_column($r['resultado']['familias'], 'nombreN1'));
    }

    /**
     * Ejecuta el código en un PHP aparte, desde la carpeta del módulo, con la sesión, el permiso y la
     * siembra de las pruebas, en una transacción que no se confirma, y devuelve lo que deja en
     * `$resultado`, lo sembrado en `$ids` y los avisos.
     *
     * @return array<string, mixed>
     */
    private function ejecutar(string $codigo, string $pagina = 'tareas.php'): array
    {
        $sesion = [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => (string) $this->idUsuario, 'group_id' => '1', 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => (string) $this->idTienda, 'razonsocial' => 'Tienda de pruebas SL', 'NombreComercial' => 'Tienda de pruebas', 'nif' => 'B00000000', 'direccion' => 'Calle de pruebas 1', 'telefono' => '986000000', 'ano' => '2026'],
        ];
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["DOCUMENT_ROOT"] = ' . var_export(dirname(RUTA_TPVFOX), true) . ';'
            . ' $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = ' . var_export('/TPVFox/modulos/mod_informes/' . $pagina, true) . ';'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_informes', true) . ');'
            . ' $_POST = ["pulsado" => ""]; require ' . var_export(RUTA_TPVFOX . '/inicial.php', true) . ';'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ' new ClasePermisos($Usuario, $BDTpv);'
            . ' $BDTpv->query("UPDATE permisos SET permiso = 1 WHERE idUsuario = ' . $this->idUsuario . ' AND modulo = \'mod_informes\'");'
            . ' $ClasePermisos = $thisTpv->permisos = new ClasePermisos($Usuario, $BDTpv);'
            . ' include_once $URLCom . "/modulos/mod_informes/clases/ClaseInformes.php";'
            . ' require ' . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . ';'
            . ' $siembra = new \TPVFox\Test\Siembra\Siembra($BDTpv); $ids = []; $resultado = null;'
            . ' ' . $codigo
            . ' echo "\n@@DATOS@@" . json_encode(["resultado" => $resultado, "ids" => $ids], JSON_INVALID_UTF8_SUBSTITUTE);';

        $errores = (string) tempnam(sys_get_temp_dir(), 'informes');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -d error_log=/dev/null -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [, $datos] = array_pad(explode('@@DATOS@@', implode("\n", $salida), 2), 2, 'null');
        $ejecucion = ((json_decode($datos, true) ?? []) + ['resultado' => null, 'ids' => []]) + ['errores' => (string) file_get_contents($errores)];
        unlink($errores);

        return $ejecucion;
    }
}
