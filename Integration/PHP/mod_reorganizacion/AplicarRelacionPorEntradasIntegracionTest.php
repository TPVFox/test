<?php
/**
 * Un albarán por cada albarán de entrada, por las peticiones de la pantalla: las entradas del mes y su
 * parte, el reparto de una receta y de varias, las entradas ya citadas, lo que se rechaza y lo que deja
 * compras al guardar los albaranes.
 *
 * El escenario es el pollo de febrero de 2026: entran 100 kg el día 3 y 100 el día 17 del proveedor
 * habitual, y salen 20 kg enteros, así que hay 180 kg disponibles. El despiece del pollo rinde el 96 %.
 *
 * Cada caso corre en un proceso aparte: las tareas de reorganización cargan una clase de
 * complemento que se llama igual que otra del módulo de productos, y las dos no caben en el mismo
 * proceso.
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
final class AplicarRelacionPorEntradasIntegracionTest extends CasoIntegracion
{
    private const RUTA_TAREAS = RUTA_TPVFOX . '/modulos/mod_reorganizacion/tareas.php';
    private const VISTA = 'AplicarRelacion.php';

    protected bool $compartirConexionConElProducto = true;

    private EscenarioRelaciones $escenario;
    private Siembra $siembra;
    private int $especial;
    private int $habitual;
    private int $despiece;
    private int $tienda;
    /** @var array<string,int> */
    private array $articulo = [];
    private int $numRelacion;
    private int $primera;
    private int $segunda;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_compras/clases/ClaseCompras.php');
        $this->incluirTPVFox('/modulos/mod_producto/clases/ClaseArticulosStocks.php');
        $this->escenario = new EscenarioRelaciones($this->db);
        $this->siembra = new Siembra($this->db);
        $this->tienda = $this->siembra->tiendaPorDefecto();

        $this->especial = $this->proveedor('Despiece del obrador', 'Especial');
        $this->habitual = $this->proveedor('Granja de la prueba', 'Activo');
        $this->articulo['pollo'] = $this->siembra->articulo('Pollo entero de las entradas', ['iva' => 10, 'idProveedor' => $this->habitual]);
        foreach (['pechuga' => 6.5, 'muslo' => 3.0, 'alas' => 2.5, 'carcasa' => 0.5, 'picada' => 5.0] as $nombre => $precio) {
            $this->articulo[$nombre] = $this->siembra->articulo(ucfirst($nombre) . ' de las entradas', ['iva' => 10]);
            $this->siembra->articuloProveedor($this->articulo[$nombre], $this->habitual, $precio);
        }
        $this->siembra->articuloProveedor($this->articulo['pollo'], $this->habitual, 2.9, ['fecha' => '2026-01-15']);
        $this->despiece = $this->escenario->tipoDeRelacion('Despiece de las entradas', '1:N', 1);
        $this->numRelacion = $this->escenario->relacionVigente($this->despiece, [
            ['idArticulo' => $this->articulo['pollo'], 'sentido' => 'entrada', 'cantidad' => 1],
            ['idArticulo' => $this->articulo['pechuga'], 'sentido' => 'salida', 'cantidad' => 0.25],
            ['idArticulo' => $this->articulo['muslo'], 'sentido' => 'salida', 'cantidad' => 0.35],
            ['idArticulo' => $this->articulo['alas'], 'sentido' => 'salida', 'cantidad' => 0.12],
            ['idArticulo' => $this->articulo['carcasa'], 'sentido' => 'salida', 'cantidad' => 0.24],
        ], ['fecha' => '2026-01-10 09:00:00'])['numRelacion'];

        $this->primera = $this->numeroDeAlbaran($this->siembra->entradaProveedor($this->articulo['pollo'], 100, '2026-02-03', ['idProveedor' => $this->habitual]));
        $this->segunda = $this->numeroDeAlbaran($this->siembra->entradaProveedor($this->articulo['pollo'], 100, '2026-02-17', ['idProveedor' => $this->habitual]));
        $this->siembra->ventaTicket($this->articulo['pollo'], 15, '2026-02-20');
        $this->siembra->ventaAlbaranCliente($this->articulo['pollo'], 5, '2026-02-25');
    }

    /** @comportamiento Al calcular por entradas, cada entrada del mes sale con lo que trajo y la parte que le toca. */
    public function test_T1_alCalcularCadaEntradaSaleConLoQueTrajoYSuParte(): void
    {
        $calculo = $this->despachar($this->peticion('calcularAplicacion', ['porEntradas' => '1']), $this->sesionCompleta());

        self::assertTrue($calculo['ok'], json_encode($calculo['avisos'], JSON_UNESCAPED_UNICODE));
        self::assertSame([
            ['numero' => $this->primera, 'fecha' => '2026-02-03', 'trajo' => 100.0, 'citada' => false, 'parte' => 90.0],
            ['numero' => $this->segunda, 'fecha' => '2026-02-17', 'trajo' => 100.0, 'citada' => false, 'parte' => 90.0],
        ], $calculo['datos']['entradas']);
    }

    /**
     * @comportamiento Preparar por entradas deja un temporal por entrada, con su fecha y el número del
     *   tipo; sus líneas son las del despiece de su parte, citan la entrada tras la relación y cuadran a
     *   0,00 € sin IVA.
     */
    public function test_T2_prepararDejaUnTemporalPorEntradaConSuFechaYSuCita(): void
    {
        $preparado = $this->despachar($this->peticion('prepararAlbaranAplicacion', ['porEntradas' => '1']), $this->sesionCompleta());

        self::assertTrue($preparado['ok'], json_encode($preparado['avisos'], JSON_UNESCAPED_UNICODE));
        self::assertCount(2, $preparado['datos']['idTemporales']);
        foreach ([[$this->primera, '2026-02-03'], [$this->segunda, '2026-02-17']] as $posicion => [$numero, $fecha]) {
            $fila = $this->db->query('SELECT * FROM albproltemporales WHERE id = ' . (int) $preparado['datos']['idTemporales'][$posicion])->fetch_assoc();
            self::assertSame($fecha, substr($fila['Fecha'], 0, 10));
            self::assertSame('Despiece de las entr', $fila['Su_numero']);
            $lineas = json_decode($fila['Productos'], true);
            self::assertCount(5, $lineas);
            self::assertEqualsWithDelta(-86.4, $lineas[0]['nunidades'], 0.0001);
            $total = 0.0;
            foreach ($lineas as $linea) {
                self::assertStringEndsWith(' · REL ' . $this->numRelacion . ' v1.0 · ENT ' . $numero, $linea['cdetalle']);
                self::assertLessThanOrEqual(100, mb_strlen($linea['cdetalle']));
                $total += $linea['importe'];
            }
            self::assertEqualsWithDelta(0.0, round($total, 2), 0.0049);
        }
    }

    /** @comportamiento Con una hermana, cada receta se reparte entre las entradas, y cada temporal lleva las dos. */
    public function test_T3_conUnaHermanaCadaRecetaSeReparteEntreLasEntradas(): void
    {
        $picada = $this->escenario->relacionVigente($this->despiece, [
            ['idArticulo' => $this->articulo['pollo'], 'sentido' => 'entrada', 'cantidad' => 1],
            ['idArticulo' => $this->articulo['picada'], 'sentido' => 'salida', 'cantidad' => 0.98],
        ], ['fecha' => '2026-01-10 09:00:00'])['numRelacion'];
        $hermanas = json_encode([['numRelacion' => $picada, 'cantidad' => '50', 'precios' => [], 'ajustePeso' => '1', 'ajusteValor' => '1']]);

        $preparado = $this->despachar($this->peticion('prepararAlbaranAplicacion', ['porEntradas' => '1', 'hermanas' => $hermanas]), $this->sesionCompleta());

        self::assertTrue($preparado['ok'], json_encode($preparado['avisos'], JSON_UNESCAPED_UNICODE));
        foreach ($preparado['datos']['idTemporales'] as $idTemporal) {
            $lineas = $this->lineasDelTemporal((int) $idTemporal);
            self::assertCount(7, $lineas);
            self::assertEqualsWithDelta(-62.4, $lineas[0]['nunidades'], 0.0001);
            self::assertEqualsWithDelta(-24.5, $lineas[5]['nunidades'], 0.0001);
        }
    }

    /** @comportamiento Una entrada ya citada por un albarán generado queda fuera del reparto, y se avisa. */
    public function test_T4_unaEntradaYaCitadaNoSeReparteDeNuevo(): void
    {
        $this->citar($this->primera, -48.0);

        $calculo = $this->despachar($this->peticion('calcularAplicacion', ['porEntradas' => '1']), $this->sesionCompleta());

        self::assertTrue($calculo['ok'], json_encode($calculo['avisos'], JSON_UNESCAPED_UNICODE));
        self::assertSame([[$this->primera, true, 0.0], [$this->segunda, false, 130.0]],
            array_map(fn(array $e): array => [$e['numero'], $e['citada'], $e['parte']], $calculo['datos']['entradas']));
        self::assertStringContainsString((string) $this->primera, implode(' ', array_column($calculo['avisos'], 'mensaje')));
    }

    /** @comportamiento Por entradas no vale un lote, ni reunir, ni sin entradas que repartir: no se prepara nada. */
    public function test_T5_unLoteReunirOSinEntradasSeRechazan(): void
    {
        $sesion = $this->sesionCompleta();
        $lote = $this->despachar($this->peticion('calcularAplicacion', ['porEntradas' => '1', 'tipoPeriodo' => 'lote', 'periodo' => '2026-02-03']), $sesion);
        self::assertFalse($lote['ok']);
        $idT = $this->temporalDelMes([$this->lineaDe('carcasa', -10.0, 1)]);
        $reunir = $this->despachar($this->peticion('prepararAlbaranAplicacion', ['porEntradas' => '1', 'destino' => 'temporal:' . $idT]), $sesion);
        self::assertFalse($reunir['ok']);

        $this->citar($this->primera, -10.0);
        $this->citar($this->segunda, -10.0);
        $todas = $this->despachar($this->peticion('prepararAlbaranAplicacion', ['porEntradas' => '1']), $sesion);
        self::assertFalse($todas['ok']);
        self::assertNotEmpty($todas['avisos']);
        self::assertSame(1, $this->temporalesDelEspecial());
    }

    /** @comportamiento Guardados en compras, los albaranes de las dos entradas mueven entre los dos las existencias del mes. */
    public function test_T6_guardadosEnComprasMuevenEntreLosDosLoDelMes(): void
    {
        $sesion = $this->sesionCompleta();
        $antes = $this->existencias();
        $preparado = $this->despachar($this->peticion('prepararAlbaranAplicacion', ['porEntradas' => '1']), $sesion);
        self::assertTrue($preparado['ok'], json_encode($preparado['avisos'], JSON_UNESCAPED_UNICODE));

        foreach ($preparado['datos']['idTemporales'] as $idTemporal) {
            self::assertSame([], $this->guardarEnCompras((int) $idTemporal, $sesion));
        }

        $despues = $this->existencias();
        self::assertEqualsWithDelta(-172.8, $despues['pollo'] - $antes['pollo'], 0.0001);
        self::assertEqualsWithDelta(45.0, $despues['pechuga'] - $antes['pechuga'], 0.0001);
    }

    /** Un albarán guardado del especial con el pollo en negativo, que cita la entrada como lo deja la preparación por entradas. */
    private function citar(int $numero, float $kg): void
    {
        $albaran = $this->siembra->entradaProveedor($this->articulo['pollo'], $kg, '2026-02-28', ['idProveedor' => $this->especial]);
        $detalle = $this->db->real_escape_string('Pollo · REL ' . $this->numRelacion . ' v1.0 · ENT ' . $numero);
        $this->db->query("UPDATE albprolinea SET cdetalle = '$detalle' WHERE idalbpro = $albaran");
    }

    private function numeroDeAlbaran(int $id): int
    {
        return (int) $this->db->query("SELECT Numalbpro FROM albprot WHERE id = $id")->fetch_assoc()['Numalbpro'];
    }

    /** La petición de la pantalla: febrero de 2026, el despiece del pollo, sin precios escritos, con el ajuste por defecto, sin cantidad ni hermanas. */
    private function peticion(string $pulsado, array $cambios = []): array
    {
        return array_merge([
            'pulsado' => $pulsado,
            'idProveedor' => (string) $this->especial,
            'numRelacion' => (string) $this->numRelacion,
            'tipoPeriodo' => 'mes',
            'periodo' => '2026-02',
            'idProveedorTraspaso' => '',
            'precios' => '{}',
            'ajustePeso' => '1',
            'ajusteValor' => '1',
            'cantidad' => '',
            'hermanas' => '',
            'porEntradas' => '',
        ], $cambios);
    }

    /** @return list<array<string,mixed>> */
    private function lineasDelTemporal(int $id): array
    {
        return json_decode($this->db->query("SELECT Productos FROM albproltemporales WHERE id = $id")->fetch_assoc()['Productos'], true);
    }

    /** Un temporal abierto del especial, de fin de febrero, con el número del tipo, como lo deja otro despiece. */
    private function temporalDelMes(array $lineas): int
    {
        $productos = $this->db->real_escape_string(json_encode($lineas));
        $numero = $this->db->real_escape_string(mb_substr('Despiece de las entradas', 0, 20));
        $this->db->query('INSERT INTO albproltemporales (idUsuario, idTienda, estadoAlbPro, Fecha, Productos, Pedidos, Su_numero, idProveedor)'
            . " VALUES ({$this->siembra->usuarioPorDefecto()}, {$this->tienda}, 'Nuevo', '2026-02-28', '$productos', '[]', '$numero', {$this->especial})");

        return (int) $this->db->insert_id;
    }

    private function lineaDe(string $articulo, float $kg, int $fila): array
    {
        return [
            'ccodbar' => '', 'cdetalle' => 'Línea de otro despiece', 'cref' => '', 'ref_prov' => '', 'estado' => 'Activo',
            'idArticulo' => $this->articulo[$articulo], 'importe' => $kg * 4.0, 'iva' => 10, 'ncant' => $kg, 'nfila' => $fila,
            'nunidades' => $kg, 'ultimoCoste' => 4.0,
        ];
    }

    /** Guarda el temporal como lo hace la pantalla de albaranes al pulsar «Guardar». */
    private function guardarEnCompras(int $idTemporal, array $sesion): array
    {
        $_SESSION['tiendaTpv'] = ['idTienda' => $this->tienda];
        $_SESSION['usuarioTpv'] = ['id' => $sesion['Usuario']['id']];
        $_POST = ['idTemporal' => $idTemporal, 'estado' => 'Nuevo', 'suNumero' => 'Despiece', 'formaVenci' => '', 'fechaVenci' => '', 'fecha' => '28-02-2026', 'hora' => ''];
        $errores = @(new \AlbaranesCompras($this->db))->guardarAlbaran();
        $_POST = [];

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

    /** @return array{ClasePermisos:object,Usuario:array{id:int,group_id:int},Tienda:array{idTienda:int}} */
    private function sesionCompleta(): array
    {
        return $this->sesion(1);
    }

    /** La sesión de un usuario nuevo con «Preparar» y, si se indica, «ver» de la pantalla. */
    private function sesion(int $ver): array
    {
        require_once RUTA_TPVFOX . '/clases/ClasePermisos.php';
        $nombre = 'prueba_entradas_' . bin2hex(random_bytes(3));
        $this->db->query("INSERT INTO usuarios (username, password, fecha, group_id, estado, nombre) VALUES ('$nombre', MD5('x'), CURDATE(), 1, 'activo', 'Prueba')");
        $usuario = ['id' => (int) $this->db->insert_id, 'group_id' => 1];
        new \ClasePermisos($usuario, $this->db);
        foreach (['ver' => $ver, 'Preparar' => 1] as $accion => $valor) {
            $this->db->query("DELETE FROM permisos WHERE idUsuario = {$usuario['id']} AND modulo = 'mod_reorganizacion' AND vista = '" . self::VISTA . "' AND accion = '$accion'");
            $this->db->query("INSERT INTO permisos (idUsuario, modulo, vista, accion, permiso) VALUES ({$usuario['id']}, 'mod_reorganizacion', '" . self::VISTA . "', '$accion', $valor)");
        }

        return ['ClasePermisos' => new \ClasePermisos($usuario, $this->db), 'Usuario' => $usuario, 'Tienda' => ['idTienda' => $this->tienda]];
    }

    private function temporalesDelEspecial(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) AS n FROM albproltemporales WHERE idProveedor = {$this->especial}")->fetch_assoc()['n'];
    }

    private function despachar(array $post, array $variables): mixed
    {
        return DespachoTareas::invocar(self::RUTA_TAREAS, $this->db, $post, $variables);
    }
}
