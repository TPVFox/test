<?php
/**
 * Lo que hace compras con un albarán al que se han reunido varios despieces, sin que compras
 * cambie: guardar un temporal con dos despieces, volver a guardar un albarán guardado con un
 * despiece añadido, y un producto que aparece en dos líneas del mismo albarán.
 *
 * Caracteriza código que esta ficha no modifica: describe lo que ocurre para que un cambio en ese
 * código se note aquí.
 *
 * Escenario de febrero de 2026: pollo, 180 kg a despiezar a 2,90 €/kg, y ternera, 80 kg entrados, de los que se despiezan 72, a 4,00 €/kg.
 * Cada caso corre en un proceso aparte por la clase de complemento que comparten reorganización y
 * productos.
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
final class AplicarRelacionReunirComprasIntegracionTest extends CasoIntegracion
{
    protected bool $compartirConexionConElProducto = true;

    private Siembra $siembra;
    private int $especial;
    private int $habitual;
    private int $tienda;
    /** @var array<string,int> */
    private array $articulo = [];
    private int $numRelacion;
    private int $numTernera;
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
        $this->articulo['ternera'] = $this->siembra->articulo('Ternera entera de compras', ['iva' => 10, 'idProveedor' => $this->habitual]);
        foreach (['aguja' => 5.5, 'falda' => 3.5] as $nombre => $precio) {
            $this->articulo[$nombre] = $this->siembra->articulo(ucfirst($nombre) . ' de compras', ['iva' => 10]);
            $this->siembra->articuloProveedor($this->articulo[$nombre], $this->habitual, $precio);
        }
        $this->siembra->articuloProveedor($this->articulo['ternera'], $this->habitual, 4.0, ['fecha' => '2026-01-15']);
        $this->numTernera = $escenario->relacionVigente($despiece, [
            ['idArticulo' => $this->articulo['ternera'], 'sentido' => 'entrada', 'cantidad' => 1],
            ['idArticulo' => $this->articulo['aguja'], 'sentido' => 'salida', 'cantidad' => 0.5],
            ['idArticulo' => $this->articulo['falda'], 'sentido' => 'salida', 'cantidad' => 0.4],
        ], ['fecha' => '2026-01-10 09:00:00'])['numRelacion'];
        $this->siembra->entradaProveedor($this->articulo['ternera'], 80, '2026-02-05', ['idProveedor' => $this->habitual]);
        $this->siembra->entradaProveedor($this->articulo['pollo'], 100, '2026-02-03', ['idProveedor' => $this->habitual]);
        $this->siembra->entradaProveedor($this->articulo['pollo'], 100, '2026-02-17', ['idProveedor' => $this->habitual]);
        $this->siembra->ventaTicket($this->articulo['pollo'], 15, '2026-02-20');
        $this->siembra->ventaAlbaranCliente($this->articulo['pollo'], 5, '2026-02-25');
        $this->sesion = $this->sesionConPermisos();
    }

    /**
     * @comportamiento Guardar un temporal con dos despieces deja las líneas de los dos, una vez cada una,
     *   y mueve las existencias una sola vez por línea.
     */
    public function test_T1_guardarUnTemporalConDosDespiecesMueveLasExistenciasUnaVez(): void
    {
        $antes = $this->existencias();
        $idTemporal = $this->preparar($this->numRelacion);
        $this->preparar($this->numTernera, 'temporal:' . $idTemporal);

        $errores = $this->guardarEnCompras($idTemporal);

        self::assertSame([], $errores);
        $albaranes = $this->db->query("SELECT id FROM albprot WHERE Su_numero = 'Despiece de compras' AND idProveedor = {$this->especial}")->fetch_all(MYSQLI_ASSOC);
        self::assertCount(1, $albaranes);
        self::assertSame(8, (int) $this->db->query("SELECT COUNT(*) AS n FROM albprolinea WHERE idalbpro = {$albaranes[0]['id']}")->fetch_assoc()['n']);
        $despues = $this->existencias();
        self::assertEqualsWithDelta(-172.8, $despues['pollo'] - $antes['pollo'], 0.0001);
        self::assertEqualsWithDelta(45.0, $despues['pechuga'] - $antes['pechuga'], 0.0001);
        self::assertEqualsWithDelta(-72.0, $despues['ternera'] - $antes['ternera'], 0.0001);
        self::assertEqualsWithDelta(40.0, $despues['aguja'] - $antes['aguja'], 0.0001);
        self::assertEqualsWithDelta(32.0, $despues['falda'] - $antes['falda'], 0.0001);
    }

    /**
     * @comportamiento Volver a guardar un albarán guardado con un despiece añadido no repite las
     *   existencias de sus líneas anteriores: queda un albarán, vuelve a «Guardado» y el temporal
     *   desaparece.
     */
    public function test_T2_volverAGuardarUnGuardadoConUnDespieceAnadidoNoRepiteSusExistencias(): void
    {
        $this->guardarEnCompras($this->preparar($this->numRelacion));
        $idAlbaran = (int) $this->db->query("SELECT id FROM albprot WHERE Su_numero = 'Despiece de compras' AND idProveedor = {$this->especial}")->fetch_assoc()['id'];
        $tras = $this->existencias();

        $idTemporal = $this->preparar($this->numTernera, 'albaran:' . $idAlbaran);
        $errores = $this->guardarEnCompras($idTemporal, 'Sin Guardar', $idAlbaran);

        self::assertSame([], $errores);
        self::assertSame(1, (int) $this->db->query("SELECT COUNT(*) AS n FROM albprot WHERE Su_numero = 'Despiece de compras' AND idProveedor = {$this->especial}")->fetch_assoc()['n']);
        self::assertSame('Guardado', $this->db->query("SELECT estado FROM albprot WHERE id = $idAlbaran")->fetch_assoc()['estado']);
        self::assertSame(8, (int) $this->db->query("SELECT COUNT(*) AS n FROM albprolinea WHERE idalbpro = $idAlbaran")->fetch_assoc()['n']);
        $despues = $this->existencias();
        foreach (['pollo', 'pechuga', 'muslo', 'alas', 'carcasa'] as $nombre) {
            self::assertEqualsWithDelta($tras[$nombre], $despues[$nombre], 0.0001, $nombre);
        }
        self::assertEqualsWithDelta(-72.0, $despues['ternera'] - $tras['ternera'], 0.0001);
        self::assertEqualsWithDelta(40.0, $despues['aguja'] - $tras['aguja'], 0.0001);
        self::assertSame(0, (int) $this->db->query("SELECT COUNT(*) AS n FROM albproltemporales WHERE id = $idTemporal")->fetch_assoc()['n']);
    }

    /**
     * @comportamiento Un producto en dos líneas del mismo albarán, como una pieza del primer paso y el
     *   producto despiezado del segundo, se guarda con las dos y suma sus existencias. Riesgo aceptado:
     *   los kg del segundo salen de movimientos registrados.
     */
    public function test_T3_unProductoEnDosLineasDelMismoAlbaranSumaSusExistencias(): void
    {
        $antes = $this->existencias();
        $idTemporal = $this->preparar($this->numRelacion);
        $compras = new \AlbaranesCompras($this->db);
        $temporal = $compras->buscarAlbaranTemporal($idTemporal);
        $lineas = json_decode($temporal['Productos'], true);
        $muslo = $lineas[2];
        $muslo['nfila'] = 6;
        $muslo['nunidades'] = $muslo['ncant'] = -20.0;
        $muslo['importe'] = -20.0 * $muslo['ultimoCoste'];
        $lineas[] = $muslo;
        $compras->modificarDatosAlbaranTemporal($this->sesion['Usuario']['id'], $this->tienda, 'Nuevo', '2026-02-28', $idTemporal, $lineas, [], $temporal['Su_numero']);

        $errores = $this->guardarEnCompras($idTemporal);

        self::assertSame([], $errores);
        $despues = $this->existencias();
        self::assertEqualsWithDelta(63.0 - 20.0, $despues['muslo'] - $antes['muslo'], 0.0001);
        self::assertSame(2, (int) $this->db->query("SELECT COUNT(*) AS n FROM albprolinea WHERE idArticulo = {$this->articulo['muslo']}")->fetch_assoc()['n']);
    }

    /** Prepara el albarán del despiece indicado, febrero, y devuelve el número del temporal. */
    private function preparar(int $numRelacion, string $destino = ''): int
    {
        $respuesta = DespachoTareas::invocar(RUTA_TPVFOX . '/modulos/mod_reorganizacion/tareas.php', $this->db, [
            'pulsado' => 'prepararAlbaranAplicacion',
            'idProveedor' => (string) $this->especial,
            'numRelacion' => (string) $numRelacion,
            'tipoPeriodo' => 'mes',
            'periodo' => '2026-02',
            'idProveedorTraspaso' => '',
            'precios' => '{}',
            'ajustePeso' => '1',
            'ajusteValor' => '1',
            'destino' => $destino,
        ], $this->sesion);
        self::assertTrue($respuesta['ok'], json_encode($respuesta['avisos'] ?? $respuesta));

        return (int) $respuesta['datos']['idTemporal'];
    }

    /** Guarda el temporal como lo hace la pantalla de albaranes al pulsar «Guardar». */
    private function guardarEnCompras(int $idTemporal, string $estado = 'Nuevo', int $idAlbaran = 0): array
    {
        $_SESSION['tiendaTpv'] = ['idTienda' => $this->tienda];
        $_SESSION['usuarioTpv'] = ['id' => $this->sesion['Usuario']['id']];
        $_GET = $idAlbaran > 0 ? ['id' => $idAlbaran] : [];
        $_POST = ['idTemporal' => $idTemporal, 'estado' => $estado, 'suNumero' => 'Despiece de compras', 'formaVenci' => '', 'fechaVenci' => '', 'fecha' => '28-02-2026', 'hora' => ''];
        $errores = @(new \AlbaranesCompras($this->db))->guardarAlbaran();
        $_POST = [];
        $_GET = [];

        return $errores;
    }

    /** @return array<string,float> existencias registradas de cada artículo del escenario */
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
