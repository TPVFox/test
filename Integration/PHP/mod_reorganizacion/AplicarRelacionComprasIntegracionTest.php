<?php
/**
 * Lo que hace compras con el albarán que deja preparado la aplicación de una relación, sin que
 * compras cambie: cómo lo lee, qué escribe al guardarlo, y cómo lo leen después quienes
 * consumen los albaranes de compra —las existencias, las tareas de coste y Posstock—.
 *
 * Caracteriza código que esta ficha no modifica: describe lo que ocurre, también lo que se aceptó
 * como riesgo, para que un cambio en ese código se note aquí.
 *
 * El escenario es el despiece del pollo de febrero de 2026: 200 kg entrados, 20 kg vendidos
 * enteros, 172,8 kg despiezados a 2,90 €/kg.
 *
 * Cada caso corre en un proceso aparte: el albarán se prepara por las tareas de reorganización,
 * que cargan una clase de complemento que se llama igual que otra del módulo de productos, y las
 * dos no caben en el mismo proceso.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModReorganizacion;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\DespachoTareas;
use TPVFox\Test\Siembra\EscenarioRelaciones;
use TPVFox\Test\Siembra\Siembra;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class AplicarRelacionComprasIntegracionTest extends CasoIntegracion
{
    protected bool $compartirConexionConElProducto = true;

    private Siembra $siembra;
    private int $especial;
    private int $habitual;
    private int $tienda;
    /** @var array<string,int> */
    private array $articulo = [];
    private int $numRelacion;
    /** @var array{ClasePermisos:object,Usuario:array{id:int,group_id:int},Tienda:array{idTienda:int}} */
    private array $sesion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_compras/clases/ClaseCompras.php');
        $this->incluirTPVFox('/modulos/mod_producto/clases/ClaseArticulosStocks.php');
        $escenario = new EscenarioRelaciones($this->db);
        $this->siembra = new Siembra($this->db);
        $this->tienda = $this->siembra->tiendaPorDefecto();

        $this->especial = $this->proveedor('Despiece del obrador', 'Especial');
        $this->habitual = $this->proveedor('Granja de la prueba', 'Activo');
        $this->articulo['pollo'] = $this->siembra->articulo('Pollo entero de compras', ['iva' => 10, 'idProveedor' => $this->habitual]);
        foreach (['pechuga' => 6.5, 'muslo' => 3.0, 'alas' => 2.5, 'carcasa' => 0.5] as $nombre => $precio) {
            $this->articulo[$nombre] = $this->siembra->articulo(ucfirst($nombre) . ' de compras', ['iva' => 10]);
            $this->siembra->articuloProveedor($this->articulo[$nombre], $this->habitual, $precio);
        }
        $this->siembra->articuloProveedor($this->articulo['pollo'], $this->habitual, 2.9, ['fecha' => '2026-01-15']);
        $despiece = $escenario->tipoDeRelacion('Despiece de compras', '1:N', 1);
        $this->numRelacion = $escenario->relacionVigente($despiece, [
            ['idArticulo' => $this->articulo['pollo'], 'sentido' => 'entrada', 'cantidad' => 1],
            ['idArticulo' => $this->articulo['pechuga'], 'sentido' => 'salida', 'cantidad' => 0.25],
            ['idArticulo' => $this->articulo['muslo'], 'sentido' => 'salida', 'cantidad' => 0.35],
            ['idArticulo' => $this->articulo['alas'], 'sentido' => 'salida', 'cantidad' => 0.12],
            ['idArticulo' => $this->articulo['carcasa'], 'sentido' => 'salida', 'cantidad' => 0.24],
        ], ['fecha' => '2026-01-10 09:00:00'])['numRelacion'];
        $this->siembra->entradaProveedor($this->articulo['pollo'], 100, '2026-02-03', ['idProveedor' => $this->habitual]);
        $this->siembra->entradaProveedor($this->articulo['pollo'], 100, '2026-02-17', ['idProveedor' => $this->habitual]);
        $this->siembra->ventaTicket($this->articulo['pollo'], 15, '2026-02-20');
        $this->siembra->ventaAlbaranCliente($this->articulo['pollo'], 5, '2026-02-25');
        $this->sesion = $this->sesionConPermisos();
    }

    /** @comportamiento Compras lista el temporal preparado y lo lee tal cual se dejó. */
    public function test_T1_comprasListaYLeeElTemporalPreparadoTalCual(): void
    {
        $idTemporal = $this->preparar();
        $compras = new \AlbaranesCompras($this->db);

        self::assertContains($idTemporal, array_map('intval', array_column($compras->TodosTemporal(), 'id')));
        $temporal = $compras->buscarAlbaranTemporal($idTemporal);
        self::assertSame('Nuevo', $temporal['estadoAlbPro']);
        self::assertCount(5, json_decode($temporal['Productos'], true));
    }

    /**
     * @comportamiento Guardado en compras, el albarán lleva las cinco líneas, su total sin IVA es
     *   0,00 €, el producto despiezado baja en lo despiezado y cada pieza sube sus kilos. El
     *   temporal desaparece.
     */
    public function test_T2_alGuardarloLasExistenciasSonLasDelEscandalloYElTotalSinIvaEsCero(): void
    {
        $antes = $this->existencias();
        $idTemporal = $this->preparar();

        $errores = $this->guardarEnCompras($idTemporal);

        self::assertSame([], $errores);
        $albaran = $this->db->query("SELECT id, estado, total_siniva FROM albprot WHERE Su_numero = 'ESCANDALLO' AND idProveedor = {$this->especial}")->fetch_assoc();
        self::assertSame('Guardado', $albaran['estado']);
        self::assertEqualsWithDelta(0.0, (float) $albaran['total_siniva'], 0.0049);
        self::assertSame(5, (int) $this->db->query("SELECT COUNT(*) AS n FROM albprolinea WHERE idalbpro = {$albaran['id']}")->fetch_assoc()['n']);
        self::assertSame(5, (int) $this->db->query("SELECT COUNT(*) AS n FROM albprolinea WHERE idalbpro = {$albaran['id']} AND cdetalle LIKE '% · REL {$this->numRelacion} v1.0'")->fetch_assoc()['n']);
        $despues = $this->existencias();
        self::assertEqualsWithDelta(-172.8, $despues['pollo'] - $antes['pollo'], 0.0001);
        foreach (['pechuga' => 45.0, 'muslo' => 63.0, 'alas' => 21.6, 'carcasa' => 43.2] as $nombre => $kilos) {
            self::assertEqualsWithDelta($kilos, $despues[$nombre] - $antes[$nombre], 0.0001, $nombre);
        }
        self::assertSame(0, (int) $this->db->query("SELECT COUNT(*) AS n FROM albproltemporales WHERE id = $idTemporal")->fetch_assoc()['n']);
    }

    /**
     * @comportamiento Al guardarlo, compras crea las filas que faltan del proveedor «Especial» en
     *   «articulosProveedores», con el coste del escandallo, y no toca las de los demás
     *   proveedores ni escribe en el histórico de precios.
     */
    public function test_T3_alGuardarloSeCreanLasFilasDelProveedorEspecialYNoSeTocanLasDemas(): void
    {
        $habitual = $this->db->query("SELECT idArticulo, coste FROM articulosProveedores WHERE idProveedor = {$this->habitual} ORDER BY idArticulo")->fetch_all(MYSQLI_ASSOC);
        $historico = (int) $this->db->query('SELECT COUNT(*) AS n FROM historico_precios')->fetch_assoc()['n'];

        $this->guardarEnCompras($this->preparar());

        self::assertSame(5, (int) $this->db->query("SELECT COUNT(*) AS n FROM articulosProveedores WHERE idProveedor = {$this->especial}")->fetch_assoc()['n']);
        self::assertSame($habitual, $this->db->query("SELECT idArticulo, coste FROM articulosProveedores WHERE idProveedor = {$this->habitual} ORDER BY idArticulo")->fetch_all(MYSQLI_ASSOC));
        self::assertSame($historico, (int) $this->db->query('SELECT COUNT(*) AS n FROM historico_precios')->fetch_assoc()['n']);
    }

    /**
     * @comportamiento «Generar stock» recalcula desde los documentos y da, con el escandallo
     *   guardado, lo que queda del pollo y los kilos de cada pieza.
     */
    public function test_T4_lasExistenciasRecalculadasDesdeLosDocumentosCuentanElEscandallo(): void
    {
        $this->incluirTPVFox('/modulos/mod_producto/clases/ClaseArticulos.php');
        $this->guardarEnCompras($this->preparar());

        $articulos = new \alArticulos();

        self::assertEqualsWithDelta(200 - 15 - 5 - 172.8, (float) $articulos->calculaStock($this->articulo['pollo'], $this->tienda), 0.0001);
        self::assertEqualsWithDelta(45.0, (float) $articulos->calculaStock($this->articulo['pechuga'], $this->tienda), 0.0001);
    }

    /**
     * @comportamiento Riesgo aceptado: la tarea de último precio toma el coste del escandallo
     *   para cada pieza, y el acumulado mensual del producto despiezado cuenta su línea negativa.
     */
    public function test_T5_lasTareasDeCosteLeenElEscandalloComoUnaCompraMas(): void
    {
        $this->incluirTPVFox('/modulos/mod_tareas_cron/clases/MUltimoPrecioCompra.php');
        $this->incluirTPVFox('/modulos/mod_tareas_cron/clases/MAcumuladoCompra.php');
        $this->guardarEnCompras($this->preparar());
        $costePechuga = (float) $this->db->query("SELECT costeSiva FROM albprolinea WHERE idArticulo = {$this->articulo['pechuga']} ORDER BY id DESC LIMIT 1")->fetch_assoc()['costeSiva'];

        $ultimos = [];
        foreach ((new \MUltimoPrecioCompra())->leer()['datos'] as $fila) {
            $ultimos[(int) $fila['idArticulo']] = (float) $fila['costeSIva'];
        }
        self::assertSame($costePechuga, $ultimos[$this->articulo['pechuga']]);

        foreach ((new \MAcumuladoCompra())->leer()['datos'] as $fila) {
            if ((int) $fila['idarticulo'] === $this->articulo['pollo'] && (int) $fila['month'] === 2) {
                self::assertEqualsWithDelta(200 - 172.8, (float) $fila['cantidad'], 0.0001);
            }
        }
    }

    /**
     * @comportamiento Riesgo aceptado: entre las líneas de proveedores «Especial» que Posstock
     *   clasifica, la salida del producto despiezado aparece como una línea negativa.
     */
    public function test_T6_posstockVeLaSalidaDelDespiezadoEntreLasLineasDeProveedoresEspecial(): void
    {
        $this->incluirTPVFox('/modulos/mod_informes/clases/PosstockQueryRepository.php');
        $this->guardarEnCompras($this->preparar());

        $lineas = (new \PosstockQueryRepository($this->db))->queryAlbaranesProvEspecialesC9('2026-02-01', '2026-02-28', (string) $this->articulo['pollo']);

        $delPollo = array_values(array_filter($lineas, fn(array $l): bool => (int) $l['idArticulo'] === $this->articulo['pollo']));
        self::assertCount(1, $delPollo);
        self::assertEqualsWithDelta(-172.8, (float) $delPollo[0]['nunidades'], 0.0001);
    }

    /**
     * @comportamiento El mayor del producto, que lista los movimientos de un artículo, ve la línea
     *   del escandallo como una entrada más: negativa en el producto despiezado y positiva, con
     *   el coste del escandallo, en cada pieza.
     */
    public function test_T7_elMayorDelProductoVeLaLineaDelEscandalloComoUnaEntrada(): void
    {
        $this->incluirTPVFox('/modulos/mod_producto/clases/ClaseArticulos.php');
        $this->guardarEnCompras($this->preparar());
        $articulos = new \alArticulos();

        foreach (['pollo' => -172.8, 'pechuga' => 45.0] as $nombre => $kilos) {
            $mayor = $articulos->calculaMayor(['fecha_inicial' => '2026-02-28', 'fecha_final' => '2026-02-28', 'idArticulo' => $this->articulo[$nombre]]);
            $compras = array_values(array_filter($mayor['datos'], fn(array $fila): bool => $fila['tipodoc'] === 'C'));
            self::assertCount(1, $compras, $nombre);
            self::assertEqualsWithDelta($kilos, (float) $compras[0]['entrega'], 0.0001, $nombre);
        }
    }

    /**
     * @comportamiento La ficha del producto da como último precio de compra el de la última línea
     *   de albarán de proveedor, también la del escandallo: el coste de partida en el producto
     *   despiezado y el coste del escandallo en cada pieza.
     */
    public function test_T8_laFichaDelProductoTomaElCosteDelEscandalloComoUltimoPrecioDeCompra(): void
    {
        $this->incluirTPVFox('/modulos/mod_producto/clases/ClaseProductos.php');
        $this->guardarEnCompras($this->preparar());
        $productos = new \ClaseProductos($this->db);
        $costePechuga = (float) $this->db->query("SELECT costeSiva FROM albprolinea WHERE idArticulo = {$this->articulo['pechuga']} ORDER BY id DESC LIMIT 1")->fetch_assoc()['costeSiva'];

        self::assertSame(2.9, $productos->getUltimoPrecioCompra($this->articulo['pollo']));
        self::assertSame(round($costePechuga, 2), $productos->getUltimoPrecioCompra($this->articulo['pechuga']));
    }

    private function preparar(): int
    {
        $respuesta = DespachoTareas::invocar(RUTA_TPVFOX . '/modulos/mod_reorganizacion/tareas.php', $this->db, [
            'pulsado' => 'prepararAlbaranAplicacion',
            'idProveedor' => (string) $this->especial,
            'numRelacion' => (string) $this->numRelacion,
            'tipoPeriodo' => 'mes',
            'periodo' => '2026-02',
            'idProveedorTraspaso' => '',
            'precios' => '{}',
            'ajustePeso' => '1',
            'ajusteValor' => '1',
        ], $this->sesion);
        self::assertTrue($respuesta['ok'], json_encode($respuesta['avisos'] ?? $respuesta));

        return (int) $respuesta['datos']['idTemporal'];
    }

    /** Guarda el temporal como lo hace la pantalla de albaranes al pulsar «Guardar». */
    private function guardarEnCompras(int $idTemporal): array
    {
        $_SESSION['tiendaTpv'] = ['idTienda' => $this->tienda];
        $_SESSION['usuarioTpv'] = ['id' => $this->sesion['Usuario']['id']];
        $_POST = ['idTemporal' => $idTemporal, 'estado' => 'Nuevo', 'suNumero' => 'ESCANDALLO', 'formaVenci' => '', 'fechaVenci' => '', 'fecha' => '28-02-2026', 'hora' => ''];
        $errores = @(new \AlbaranesCompras($this->db))->guardarAlbaran();
        $_POST = [];

        return $errores;
    }

    /** @return array<string,float> existencias registradas de cada artículo del despiece */
    private function existencias(): array
    {
        $existencias = [];
        foreach ($this->articulo as $nombre => $id) {
            $fila = $this->db->query("SELECT stockOn FROM articulosStocks WHERE idArticulo = $id AND idTienda = {$this->tienda}")->fetch_assoc();
            $existencias[$nombre] = $fila === null ? 0.0 : (float) $fila['stockOn'];
        }

        return $existencias;
    }

    private function proveedor(string $nombre, string $estado): int
    {
        $id = $this->siembra->proveedor($nombre);
        $this->db->query("UPDATE proveedores SET estado = '$estado', nombrecomercial = '$nombre' WHERE idProveedor = $id");

        return $id;
    }

    private function sesionConPermisos(): array
    {
        require_once RUTA_TPVFOX . '/clases/ClasePermisos.php';
        $nombre = 'prueba_compras_' . bin2hex(random_bytes(3));
        $this->db->query("INSERT INTO usuarios (username, password, fecha, group_id, estado, nombre) VALUES ('$nombre', MD5('x'), CURDATE(), 1, 'activo', 'Prueba')");
        $usuario = ['id' => (int) $this->db->insert_id, 'group_id' => 1];
        new \ClasePermisos($usuario, $this->db);
        foreach (['ver', 'Preparar'] as $accion) {
            $this->db->query("DELETE FROM permisos WHERE idUsuario = {$usuario['id']} AND modulo = 'mod_reorganizacion' AND vista = 'AplicarRelacion.php' AND accion = '$accion'");
            $this->db->query("INSERT INTO permisos (idUsuario, modulo, vista, accion, permiso) VALUES ({$usuario['id']}, 'mod_reorganizacion', 'AplicarRelacion.php', '$accion', 1)");
        }

        return ['ClasePermisos' => new \ClasePermisos($usuario, $this->db), 'Usuario' => $usuario, 'Tienda' => ['idTienda' => $this->tienda]];
    }
}
