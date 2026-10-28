<?php
/**
 * La aplicación de una relación desde «Reorganizar», por sus dos peticiones —calcular y preparar
 * el albarán—, como las hace la pantalla: el permiso, el proveedor y la relación, el periodo y la
 * versión que regía, lo que hay que despiezar según los movimientos, el aviso de proceso
 * duplicado, lo disponible y el albarán temporal de compras que queda preparado.
 *
 * El escenario de casi todos los casos es el despiece del pollo aplicado a febrero de 2026: entran
 * 200 kg del proveedor habitual y salen 20 kg enteros, así que se despiezan 180 kg, al coste de
 * 2,90 €/kg de la ficha.
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
final class AplicarRelacionIntegracionTest extends CasoIntegracion
{
    private const RUTA_TAREAS = RUTA_TPVFOX . '/modulos/mod_reorganizacion/tareas.php';
    private const VISTA = 'AplicarRelacion.php';

    protected bool $compartirConexionConElProducto = true;

    private EscenarioRelaciones $escenario;
    private Siembra $siembra;
    private int $especial;
    private int $habitual;
    private int $despiece;
    /** @var array<string,int> */
    private array $articulo = [];
    /** @var array{id:int,numRelacion:int} */
    private array $relacion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_compras/clases/ClaseCompras.php');
        $this->incluirTPVFox('/modulos/mod_producto/clases/ClaseArticulosStocks.php');
        $this->escenario = new EscenarioRelaciones($this->db);
        $this->siembra = new Siembra($this->db);

        $this->especial = $this->proveedor('Despiece del obrador', 'Especial');
        $this->habitual = $this->proveedor('Granja de la prueba', 'Activo');
        $this->articulo['pollo'] = $this->siembra->articulo('Pollo entero de la aplicación', ['iva' => 10, 'idProveedor' => $this->habitual]);
        foreach (['pechuga' => 6.5, 'muslo' => 3.0, 'alas' => 2.5, 'carcasa' => 0.5] as $nombre => $precio) {
            $this->articulo[$nombre] = $this->siembra->articulo(ucfirst($nombre) . ' de la aplicación', ['iva' => 10]);
            $this->siembra->articuloProveedor($this->articulo[$nombre], $this->habitual, $precio);
        }
        $this->siembra->articuloProveedor($this->articulo['pollo'], $this->habitual, 2.9, ['fecha' => '2026-01-15']);
        $this->despiece = $this->escenario->tipoDeRelacion('Despiece de la aplicación', '1:N', 1);
        $this->relacion = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo(0.25), ['fecha' => '2026-01-10 09:00:00']);

        $this->siembra->entradaProveedor($this->articulo['pollo'], 100, '2026-02-03', ['idProveedor' => $this->habitual]);
        $this->siembra->entradaProveedor($this->articulo['pollo'], 100, '2026-02-17', ['idProveedor' => $this->habitual]);
        $this->siembra->ventaTicket($this->articulo['pollo'], 15, '2026-02-20');
        $this->siembra->ventaAlbaranCliente($this->articulo['pollo'], 5, '2026-02-25');
    }

    /**
     * @comportamiento Calcular exige «ver» y preparar exige «Preparar», los dos de la pantalla de
     *   aplicar relación. Sin el suyo, cada petición se rechaza y no se prepara nada.
     */
    public function test_T1_sinVerNoSeCalculaYSinPrepararNoSePrepara(): void
    {
        $sinVer = $this->sesion([['ver', 0], ['Preparar', 1]]);
        $calculo = $this->despachar($this->peticion('calcularAplicacion'), $sinVer);
        self::assertFalse($calculo['ok']);
        self::assertStringContainsString('permiso', $calculo['avisos'][0]['mensaje']);

        $sinPreparar = $this->sesion([['ver', 1], ['Preparar', 0]]);
        $preparado = $this->despachar($this->peticion('prepararAlbaranAplicacion'), $sinPreparar);
        self::assertFalse($preparado['ok']);
        self::assertStringContainsString('permiso', $preparado['avisos'][0]['mensaje']);
        self::assertSame(0, $this->temporalesDelEspecial());
    }

    /** @comportamiento Solo vale el permiso de esta pantalla: el de otra vista u otro módulo, no. */
    public function test_T2_losPermisosDeOtraVistaOTroModuloNoValen(): void
    {
        $sesion = $this->sesion([], [
            ['mod_reorganizacion', 'ComprobacionStockVigente.php', 'ver', 1],
            ['mod_relaciones', 'ListaRelaciones.php', 'Preparar', 1],
        ]);

        self::assertFalse($this->despachar($this->peticion('calcularAplicacion'), $sesion)['ok']);
        self::assertFalse($this->despachar($this->peticion('prepararAlbaranAplicacion'), $sesion)['ok']);
        self::assertSame(0, $this->temporalesDelEspecial());
    }

    /**
     * @comportamiento «Reorganizar permisos» reparte «ver» y «Preparar» de la pantalla a todos:
     *   concedidos al administrador y denegados al resto.
     */
    public function test_T3_reorganizarPermisosDaVerYPrepararAlAdministradorYSeLosNiegaAlResto(): void
    {
        $this->incluirTPVFox('/modulos/mod_reorganizacion/clases/ClaseReorganizar.php');
        $sesion = $this->sesion([], [], 9);
        $this->db->query("DELETE FROM permisos WHERE modulo = 'mod_reorganizacion' AND vista = '" . self::VISTA . "'");
        $usuarios = $this->db->query('SELECT id, group_id FROM usuarios ORDER BY id')->fetch_all(MYSQLI_ASSOC);

        foreach (array_keys($usuarios) as $posicion) {
            DespachoTareas::invocar(self::RUTA_TAREAS, $this->db, ['pulsado' => 'reorganizarPermisosModulos', 'inicial' => (string) $posicion, 'total' => (string) count($usuarios)], $sesion);
        }

        foreach ($usuarios as $usuario) {
            $filas = $this->db->query("SELECT accion, permiso FROM permisos WHERE idUsuario = {$usuario['id']} AND modulo = 'mod_reorganizacion' AND vista = '" . self::VISTA . "' AND accion IN ('ver', 'Preparar') ORDER BY accion")->fetch_all(MYSQLI_ASSOC);
            $esperado = $usuario['group_id'] == 9 ? '1' : '0';
            self::assertSame([['accion' => 'Preparar', 'permiso' => $esperado], ['accion' => 'ver', 'permiso' => $esperado]], $filas, 'usuario ' . $usuario['id']);
        }
    }

    /**
     * @comportamiento Solo se aplica con un proveedor «Especial» y una relación aplicable; otro
     *   proveedor, otra forma o una relación que no existe se rechazan, también por petición.
     */
    public function test_T4_unProveedorQueNoEsEspecialOUnaRelacionNoAplicableSeRechazan(): void
    {
        $sesion = $this->sesionCompleta();
        $elaboracion = $this->escenario->relacionVigente($this->escenario->tipoDeRelacion('Elaboración de la aplicación', 'N:1', 1), [
            ['idArticulo' => $this->articulo['pechuga'], 'sentido' => 'entrada', 'cantidad' => 0.5],
            ['idArticulo' => $this->articulo['muslo'], 'sentido' => 'entrada', 'cantidad' => 0.5],
            ['idArticulo' => $this->articulo['pollo'], 'sentido' => 'salida'],
        ], ['fecha' => '2026-01-10 09:00:00']);

        foreach ([['idProveedor' => (string) $this->habitual], ['numRelacion' => (string) $elaboracion['numRelacion']], ['numRelacion' => '999999']] as $cambio) {
            foreach (['calcularAplicacion', 'prepararAlbaranAplicacion'] as $pulsado) {
                $respuesta = $this->despachar($this->peticion($pulsado, $cambio), $sesion);
                self::assertFalse($respuesta['ok'], json_encode($cambio));
                self::assertNotEmpty($respuesta['avisos']);
            }
        }
        self::assertSame(0, $this->temporalesDelEspecial());
    }

    /**
     * @comportamiento Un mes se calcula con la versión del despiece que regía su último día,
     *   aunque después haya cambiado, y el albarán se fecha ese día.
     */
    public function test_T5_unMesAnteriorAUnCambioSeCalculaConLaVersionQueRegia(): void
    {
        $this->db->query("UPDATE relaciones SET estado = 'Sustituido' WHERE id = {$this->relacion['id']}");
        $this->escenario->versionDeRelacion($this->relacion['numRelacion'], $this->despiece, '1.1', 'Activo', '2026-03-10 10:00:00', $this->lineasDelPollo(0.30));

        $calculo = $this->despachar($this->peticion('calcularAplicacion'), $this->sesionCompleta());

        self::assertTrue($calculo['ok'], json_encode($calculo['avisos']));
        self::assertSame('2026-02-28', $calculo['datos']['periodo']['fecha']);
        self::assertSame('1.0', $calculo['datos']['version']['version']);
        self::assertSame(45.0, $calculo['datos']['escandallo']['piezas'][0]['cantidad']);
    }

    /** @comportamiento Si la relación no regía en la fecha del periodo, no se calcula y se avisa. */
    public function test_T6_unaRelacionQueNoRegiaEnLaFechaSeAvisa(): void
    {
        $calculo = $this->despachar($this->peticion('calcularAplicacion', ['periodo' => '2025-12']), $this->sesionCompleta());

        self::assertFalse($calculo['ok']);
        self::assertStringContainsString('no regía', $calculo['avisos'][0]['mensaje']);
    }

    /** @comportamiento Un lote es un solo día: entra lo de ese día y el albarán se fecha ese día. */
    public function test_T7_unLoteEsUnSoloDia(): void
    {
        $calculo = $this->despachar($this->peticion('calcularAplicacion', ['tipoPeriodo' => 'lote', 'periodo' => '2026-02-03']), $this->sesionCompleta());

        self::assertTrue($calculo['ok'], json_encode($calculo['avisos']));
        self::assertSame('2026-02-03', $calculo['datos']['periodo']['fecha']);
        self::assertSame(100.0, $calculo['datos']['cantidadADespiezar']);
    }

    /**
     * @comportamiento Lo que hay que despiezar sale de los movimientos que cuentan como
     *   existencias: un albarán sin guardar y una línea eliminada no cuentan; lo vendido antes de
     *   la primera entrada tampoco; una devolución al proveedor, sí.
     */
    public function test_T8_loQueHayQueDespiezarSaleDeLosMovimientosQueCuentan(): void
    {
        $pollo = $this->articulo['pollo'];
        $this->siembra->entradaProveedor($pollo, 30, '2026-02-10', ['idProveedor' => $this->habitual, 'estado' => 'Sin Guardar']);
        $this->siembra->entradaProveedor($pollo, 40, '2026-02-11', ['idProveedor' => $this->habitual, 'estadoLinea' => 'Eliminado']);
        $this->siembra->ventaTicket($pollo, 4, '2026-02-02');
        $this->siembra->devolucionProveedor($pollo, 10, '2026-02-18', ['idProveedor' => $this->habitual]);

        $calculo = $this->despachar($this->peticion('calcularAplicacion'), $this->sesionCompleta());

        self::assertTrue($calculo['ok'], json_encode($calculo['avisos']));
        self::assertSame(170.0, $calculo['datos']['cantidadADespiezar']);
    }

    /**
     * @comportamiento Elegido un proveedor de traspaso, sus albaranes del primer y el último día
     *   del periodo —el cierre y la apertura del ejercicio— no cuentan; sin elegirlo, sí.
     */
    public function test_T9_elProveedorDeTraspasoElegidoDejaFueraElCierreYLaApertura(): void
    {
        $pollo = $this->articulo['pollo'];
        $traspaso = $this->proveedor('Traspaso de la aplicación', 'Activo');
        $this->siembra->entradaProveedor($pollo, 100, '2026-12-05', ['idProveedor' => $this->habitual]);
        $this->siembra->entradaProveedor($pollo, -100, '2026-12-31', ['idProveedor' => $traspaso]);
        $this->siembra->entradaProveedor($pollo, 50, '2026-01-01', ['idProveedor' => $traspaso]);
        $this->siembra->entradaProveedor($pollo, 80, '2026-01-05', ['idProveedor' => $this->habitual]);

        $diciembre = $this->despachar($this->peticion('calcularAplicacion', ['periodo' => '2026-12', 'idProveedorTraspaso' => (string) $traspaso]), $this->sesionCompleta());
        self::assertTrue($diciembre['ok'], json_encode($diciembre['avisos']));
        self::assertSame(100.0, $diciembre['datos']['cantidadADespiezar']);
        $sinElegir = $this->despachar($this->peticion('calcularAplicacion', ['periodo' => '2026-12']), $this->sesionCompleta());
        self::assertFalse($sinElegir['ok']);

        $enero = $this->despachar($this->peticion('calcularAplicacion', ['periodo' => '2026-01', 'idProveedorTraspaso' => (string) $traspaso]), $this->sesionCompleta());
        self::assertTrue($enero['ok'], json_encode($enero['avisos']));
        self::assertSame(80.0, $enero['datos']['cantidadADespiezar']);
        $eneroSinElegir = $this->despachar($this->peticion('calcularAplicacion', ['periodo' => '2026-01']), $this->sesionCompleta());
        self::assertSame(130.0, $eneroSinElegir['datos']['cantidadADespiezar']);
    }

    /**
     * @comportamiento Sin nada que despiezar —se vendió todo entero— o sin coste de partida en
     *   la ficha, no se calcula, y el aviso dice qué falta y dónde corregirlo.
     */
    public function test_T10_sinNadaQueDespiezarOSinCosteDePartidaNoSeCalcula(): void
    {
        $this->siembra->ventaTicket($this->articulo['pollo'], 180, '2026-02-26');
        $todoVendido = $this->despachar($this->peticion('calcularAplicacion'), $this->sesionCompleta());
        self::assertFalse($todoVendido['ok']);
        self::assertStringContainsString('despiezar', $todoVendido['avisos'][0]['mensaje']);

        $this->db->query("DELETE FROM ticketslinea WHERE idArticulo = {$this->articulo['pollo']} AND nunidades = 180");
        $this->db->query("DELETE FROM articulosProveedores WHERE idArticulo = {$this->articulo['pollo']}");
        $sinCoste = $this->despachar($this->peticion('calcularAplicacion'), $this->sesionCompleta());
        self::assertFalse($sinCoste['ok']);
        self::assertStringContainsString('ficha', $sinCoste['avisos'][0]['mensaje']);
    }

    /**
     * @comportamiento Con una aplicación anterior del mismo proveedor en el periodo que no cita su
     *   relación, el aviso sale amarillo y se calcula sobre lo que queda: la línea saca su cantidad.
     *   También se prepara por petición directa.
     */
    public function test_T11_conUnaAplicacionAnteriorSinRelacionSeCalculaSobreLoQueQueda(): void
    {
        $this->siembra->entradaProveedor($this->articulo['pollo'], -172.8, '2026-02-28', ['idProveedor' => $this->especial]);

        $calculo = $this->despachar($this->peticion('calcularAplicacion'), $this->sesionCompleta());
        self::assertTrue($calculo['ok'], json_encode($calculo['avisos'], JSON_UNESCAPED_UNICODE));
        self::assertSame('amarillo', $calculo['datos']['aviso']['color']);
        self::assertSame(7.2, $calculo['datos']['disponible']);
        self::assertSame(7.2, $calculo['datos']['escandallo']['cantidadADespiezar']);

        $preparado = $this->despachar($this->peticion('prepararAlbaranAplicacion'), $this->sesionCompleta());
        self::assertTrue($preparado['ok'], json_encode($preparado['avisos'], JSON_UNESCAPED_UNICODE));
        self::assertSame(1, $this->temporalesDelEspecial());
    }

    /**
     * @comportamiento Preparar deja un albarán temporal de compras con el proveedor, la fecha del
     *   periodo, el estado «Nuevo», su número propio y las líneas del escandallo con su signo, sin
     *   coste anterior y a 0,00 € sin IVA. Ni las existencias ni los costes cambian.
     */
    public function test_T12_prepararDejaElTemporalConLoCalculadoSinTocarExistenciasNiCostes(): void
    {
        $existencias = $this->cuantas('articulosStocks');
        $costes = $this->db->query('SELECT COUNT(*) AS n, SUM(coste) AS suma FROM articulosProveedores')->fetch_assoc();

        $preparado = $this->despachar($this->peticion('prepararAlbaranAplicacion'), $this->sesionCompleta());

        self::assertTrue($preparado['ok'], json_encode($preparado['avisos']));
        $temporal = $this->db->query("SELECT * FROM albproltemporales WHERE id = {$preparado['datos']['idTemporal']}")->fetch_assoc();
        self::assertSame($this->especial, (int) $temporal['idProveedor']);
        self::assertSame('2026-02-28', substr($temporal['Fecha'], 0, 10));
        self::assertSame('Nuevo', $temporal['estadoAlbPro']);
        self::assertSame('Despiece de la aplic', $temporal['Su_numero']);
        $lineas = json_decode($temporal['Productos'], true);
        self::assertCount(5, $lineas);
        foreach ($lineas as $linea) {
            self::assertStringEndsWith(' · REL ' . $this->relacion['numRelacion'] . ' v1.0', $linea['cdetalle']);
        }
        self::assertSame($this->articulo['pollo'], (int) $lineas[0]['idArticulo']);
        self::assertEqualsWithDelta(-172.8, $lineas[0]['nunidades'], 0.0001);
        self::assertEqualsWithDelta(2.9, $lineas[0]['ultimoCoste'], 0.0001);
        $total = 0.0;
        foreach ($lineas as $linea) {
            self::assertArrayNotHasKey('CosteAnt', $linea);
            $total += $linea['importe'];
        }
        self::assertEqualsWithDelta(0.0, round($total, 2), 0.0049);
        for ($i = 1; $i < 5; $i++) {
            self::assertGreaterThan(0, $lineas[$i]['nunidades']);
        }
        self::assertSame($existencias, $this->cuantas('articulosStocks'));
        self::assertSame($costes, $this->db->query('SELECT COUNT(*) AS n, SUM(coste) AS suma FROM articulosProveedores')->fetch_assoc());
    }

    /** @comportamiento El servidor vuelve a calcular al preparar: un coste en la petición no se usa. */
    public function test_T13_prepararNoUsaCostesQueLleguenEnLaPeticion(): void
    {
        $peticion = $this->peticion('prepararAlbaranAplicacion', [
            'costeDePartida' => '0.01',
            'lineas' => json_encode([['idArticulo' => $this->articulo['pollo'], 'nunidades' => -1, 'ultimoCoste' => 0.01]]),
        ]);

        $preparado = $this->despachar($peticion, $this->sesionCompleta());

        self::assertTrue($preparado['ok'], json_encode($preparado['avisos']));
        $lineas = json_decode($this->db->query("SELECT Productos FROM albproltemporales WHERE id = {$preparado['datos']['idTemporal']}")->fetch_assoc()['Productos'], true);
        self::assertCount(5, $lineas);
        self::assertEqualsWithDelta(2.9, $lineas[0]['ultimoCoste'], 0.0001);
    }

    /**
     * @comportamiento Si el nombre de un artículo lleva comillas, compras no podría guardar el
     *   albarán: no se prepara, y el aviso dice qué artículo corregir.
     */
    public function test_T14_unArticuloConComillasEnSuNombreNoSePrepara(): void
    {
        $this->db->query("UPDATE articulos SET articulo_name = 'Muslo \"de corral\" de la aplicación' WHERE idArticulo = {$this->articulo['muslo']}");

        $preparado = $this->despachar($this->peticion('prepararAlbaranAplicacion'), $this->sesionCompleta());

        self::assertFalse($preparado['ok']);
        self::assertSame('warning', $preparado['avisos'][0]['tipo']);
        self::assertStringContainsString('Muslo "de corral" de la aplicación', $preparado['avisos'][0]['mensaje']);
        self::assertSame(0, $this->temporalesDelEspecial());
    }

    /** @comportamiento Una pieza con otro IVA que el producto despiezado se avisa, y se puede preparar. */
    public function test_T15_unaPiezaConOtroIvaSeAvisaYSePuedePreparar(): void
    {
        $this->db->query("UPDATE articulos SET iva = 21 WHERE idArticulo = {$this->articulo['carcasa']}");

        $calculo = $this->despachar($this->peticion('calcularAplicacion'), $this->sesionCompleta());
        self::assertTrue($calculo['ok'], json_encode($calculo['avisos']));
        $mensajes = implode(' ', array_column($calculo['avisos'], 'mensaje'));
        self::assertStringContainsString('IVA', $mensajes);
        self::assertStringContainsString('Carcasa de la aplicación', $mensajes);

        self::assertTrue($this->despachar($this->peticion('prepararAlbaranAplicacion'), $this->sesionCompleta())['ok']);
    }

    /**
     * @comportamiento La segunda preparación del mismo periodo descuenta todo lo que saca el temporal
     *   de la primera, merma incluida: no queda nada disponible, se avisa y no se escribe.
     */
    public function test_T16_laSegundaPreparacionDelMismoPeriodoNoTieneNadaDisponible(): void
    {
        $sesion = $this->sesionCompleta();

        self::assertTrue($this->despachar($this->peticion('prepararAlbaranAplicacion'), $sesion)['ok']);
        $segunda = $this->despachar($this->peticion('prepararAlbaranAplicacion'), $sesion);

        self::assertFalse($segunda['ok']);
        self::assertSame(0.0, $segunda['datos']['disponible']);
        self::assertSame(180.0, $segunda['datos']['enTemporales']);
        self::assertContains('danger', array_column($segunda['avisos'], 'tipo'));
        self::assertSame(1, $this->temporalesDelEspecial());
    }

    /** @comportamiento Valores hostiles en la petición no corresponden a nada: se rechazan sin escribir. */
    public function test_T17_valoresHostilesNoCorrespondenANada(): void
    {
        $sesion = $this->sesionCompleta();
        $hostiles = [
            ['idProveedor' => '1 OR 1=1'],
            ['numRelacion' => "1' OR '1'='1"],
            ['periodo' => "2026-02' OR '1'='1"],
            ['tipoPeriodo' => 'semana'],
            ['ajustePeso' => 'x'],
            ['ajustePeso' => '2.5', 'ajusteValor' => '-0.5'],
            ['ajustePeso' => '1.5', 'ajusteValor' => '1.5'],
            ['precios' => '{"' . $this->articulo['pechuga'] . '":"1; DROP TABLE articulos"}'],
            ['idProveedorTraspaso' => '0 OR 1=1'],
        ];

        foreach ($hostiles as $cambio) {
            $respuesta = $this->despachar($this->peticion('prepararAlbaranAplicacion', $cambio), $sesion);
            self::assertFalse($respuesta['ok'], json_encode($cambio));
        }
        self::assertSame(0, $this->temporalesDelEspecial());
    }

    /** @comportamiento El temporal queda a nombre del usuario de la sesión, no del que llegue en la petición. */
    public function test_T18_elTemporalQuedaANombreDelUsuarioDeLaSesion(): void
    {
        $sesion = $this->sesionCompleta();

        $preparado = $this->despachar($this->peticion('prepararAlbaranAplicacion', ['idUsuario' => '1']), $sesion);

        self::assertTrue($preparado['ok'], json_encode($preparado['avisos']));
        $idUsuario = (int) $this->db->query("SELECT idUsuario FROM albproltemporales WHERE id = {$preparado['datos']['idTemporal']}")->fetch_assoc()['idUsuario'];
        self::assertSame($sesion['Usuario']['id'], $idUsuario);
    }

    /**
     * @comportamiento Los periodos que se ofrecen se piden con el permiso «ver» de la pantalla,
     *   y llegan los meses o los días con entradas del producto; sin el permiso, una relación
     *   que no existe o un tipo desconocido, se rechazan sin leer.
     */
    public function test_T19_losPeriodosQueSeOfrecenSePidenConElPermisoDeVer(): void
    {
        $meses = $this->despachar($this->peticion('periodosAplicacion'), $this->sesionCompleta());
        self::assertTrue($meses['ok'], json_encode($meses['avisos']));
        self::assertSame([['valor' => '2026-02', 'cantidad' => 200.0, 'unidad' => 'kg']], $meses['datos']);

        $dias = $this->despachar($this->peticion('periodosAplicacion', ['tipoPeriodo' => 'lote']), $this->sesionCompleta());
        self::assertSame(['2026-02-17', '2026-02-03'], array_column($dias['datos'], 'valor'));

        $sinVer = $this->despachar($this->peticion('periodosAplicacion'), $this->sesion([['ver', 0], ['Preparar', 1]]));
        self::assertFalse($sinVer['ok']);
        self::assertSame([], $sinVer['datos']);
        foreach ([['numRelacion' => '999999'], ['tipoPeriodo' => 'semana'], ['numRelacion' => '1; DROP TABLE articulos']] as $cambio) {
            $rechazo = $this->despachar($this->peticion('periodosAplicacion', $cambio), $this->sesionCompleta());
            self::assertFalse($rechazo['ok'], json_encode($cambio));
            self::assertNotEmpty($rechazo['avisos']);
        }
    }

    /**
     * @comportamiento Con «la vigente» se aplica la versión que rige hoy aunque el periodo sea
     *   anterior a un cambio o a la alta de la relación, y se dice cuál es y qué regía; con «la que
     *   regía» se sigue pidiendo un periodo en que la relación existiera. Una relación de baja no
     *   tiene versión vigente, y un valor desconocido se rechaza.
     */
    public function test_T20_laVersionVigenteSeAplicaAunqueElPeriodoSeaAnterior(): void
    {
        $this->db->query("UPDATE relaciones SET estado = 'Sustituido' WHERE id = {$this->relacion['id']}");
        $segunda = $this->escenario->versionDeRelacion($this->relacion['numRelacion'], $this->despiece, '1.1', 'Activo', '2026-03-10 10:00:00', $this->lineasDelPollo(0.26));
        $sesion = $this->sesionCompleta();

        $regia = $this->despachar($this->peticion('calcularAplicacion'), $sesion);
        self::assertSame('1.0', $regia['datos']['version']['version']);
        self::assertSame('periodo', $regia['datos']['cualVersion']);

        $vigente = $this->despachar($this->peticion('calcularAplicacion', ['version' => 'vigente']), $sesion);
        self::assertTrue($vigente['ok'], json_encode($vigente['avisos']));
        self::assertSame('1.1', $vigente['datos']['version']['version']);
        self::assertSame('vigente', $vigente['datos']['cualVersion']);
        self::assertSame(46.8, $vigente['datos']['escandallo']['piezas'][0]['cantidad']);
        $mensajes = implode(' ', array_column($vigente['avisos'], 'mensaje'));
        self::assertStringContainsString('1.1', $mensajes);
        self::assertStringContainsString('1.0', $mensajes);

        $this->siembra->entradaProveedor($this->articulo['pollo'], 100, '2025-12-10', ['idProveedor' => $this->habitual]);
        $antes = $this->despachar($this->peticion('calcularAplicacion', ['periodo' => '2025-12']), $sesion);
        self::assertFalse($antes['ok']);
        self::assertStringContainsString('versión vigente', $antes['avisos'][0]['mensaje']);
        $conVigente = $this->despachar($this->peticion('calcularAplicacion', ['periodo' => '2025-12', 'version' => 'vigente']), $sesion);
        self::assertTrue($conVigente['ok'], json_encode($conVigente['avisos']));
        self::assertStringContainsString('no existía', implode(' ', array_column($conVigente['avisos'], 'mensaje')));

        $preparado = $this->despachar($this->peticion('prepararAlbaranAplicacion', ['version' => 'vigente']), $sesion);
        self::assertTrue($preparado['ok'], json_encode($preparado['avisos']));
        $lineas = json_decode($this->db->query("SELECT Productos FROM albproltemporales WHERE id = {$preparado['datos']['idTemporal']}")->fetch_assoc()['Productos'], true);
        self::assertEqualsWithDelta(46.8, $lineas[1]['nunidades'], 0.0001);
        self::assertStringEndsWith(' · REL ' . $this->relacion['numRelacion'] . ' v1.1', $lineas[1]['cdetalle']);

        $otra = $this->despachar($this->peticion('calcularAplicacion', ['version' => 'otra']), $sesion);
        self::assertFalse($otra['ok']);
        $this->db->query("UPDATE relaciones SET estado = 'Baja' WHERE id = {$segunda}");
        $deBaja = $this->despachar($this->peticion('calcularAplicacion', ['version' => 'vigente']), $sesion);
        self::assertFalse($deBaja['ok']);
        self::assertStringContainsString('de baja', $deBaja['avisos'][0]['mensaje']);
    }

    /**
     * @comportamiento Con el pollo solo como derivado de otra aplicación del mismo proveedor en el
     *   periodo, el aviso sale amarillo, dice que lo lleva como derivado, y se calcula y se prepara,
     *   también por petición directa.
     */
    public function test_T21_conElProductoComoDerivadoSeCalculaYSePrepara(): void
    {
        $canal = $this->siembra->articulo('Canal de la aplicación', ['iva' => 10]);
        $deLaCanal = $this->escenario->relacionVigente($this->despiece, [
            ['idArticulo' => $canal, 'sentido' => 'entrada', 'cantidad' => 1],
            ['idArticulo' => $this->articulo['pollo'], 'sentido' => 'salida', 'cantidad' => 0.8],
        ], ['fecha' => '2026-01-10 09:00:00']);
        $albaran = $this->siembra->entradaProveedor($canal, -12.5, '2026-02-10', ['idProveedor' => $this->especial]);
        $this->siembra->lineaEntrada($albaran, $this->articulo['pollo'], 10.0);
        $etiqueta = $this->db->real_escape_string(' · REL ' . $deLaCanal['numRelacion'] . ' v1.0');
        $this->db->query("UPDATE albprolinea SET cdetalle = CONCAT('Línea', '$etiqueta') WHERE idalbpro = $albaran");

        $calculo = $this->despachar($this->peticion('calcularAplicacion'), $this->sesionCompleta());
        self::assertTrue($calculo['ok'], json_encode($calculo['avisos'], JSON_UNESCAPED_UNICODE));
        self::assertSame('amarillo', $calculo['datos']['aviso']['color']);
        self::assertStringContainsString('como derivado', json_encode($calculo['avisos'], JSON_UNESCAPED_UNICODE));

        $preparado = $this->despachar($this->peticion('prepararAlbaranAplicacion'), $this->sesionCompleta());
        self::assertTrue($preparado['ok'], json_encode($preparado['avisos'], JSON_UNESCAPED_UNICODE));
        self::assertSame(1, $this->temporalesDelEspecial());
    }

    /** @return list<array{idArticulo:int,sentido:string,cantidad:float}> */
    private function lineasDelPollo(float $pechuga): array
    {
        return [
            ['idArticulo' => $this->articulo['pollo'], 'sentido' => 'entrada', 'cantidad' => 1],
            ['idArticulo' => $this->articulo['pechuga'], 'sentido' => 'salida', 'cantidad' => $pechuga],
            ['idArticulo' => $this->articulo['muslo'], 'sentido' => 'salida', 'cantidad' => 0.35],
            ['idArticulo' => $this->articulo['alas'], 'sentido' => 'salida', 'cantidad' => 0.12],
            ['idArticulo' => $this->articulo['carcasa'], 'sentido' => 'salida', 'cantidad' => 0.24],
        ];
    }

    /** La petición de la pantalla: febrero de 2026, el despiece del pollo, sin precios escritos y con el ajuste por defecto. */
    private function peticion(string $pulsado, array $cambios = []): array
    {
        return array_merge([
            'pulsado' => $pulsado,
            'idProveedor' => (string) $this->especial,
            'numRelacion' => (string) $this->relacion['numRelacion'],
            'tipoPeriodo' => 'mes',
            'periodo' => '2026-02',
            'idProveedorTraspaso' => '',
            'precios' => '{}',
            'ajustePeso' => '1',
            'ajusteValor' => '1',
        ], $cambios);
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
        return $this->sesion([['ver', 1], ['Preparar', 1]]);
    }

    /**
     * La sesión de un usuario nuevo del grupo indicado, con los permisos de la pantalla de aplicar
     * relación que fije el caso y los de otros módulos que se indiquen.
     *
     * @param list<array{0:string,1:int}> $deLaPantalla acción y valor
     * @param list<array{0:string,1:string,2:string,3:int}> $deOtros módulo, vista, acción y valor
     * @return array{ClasePermisos:object,Usuario:array{id:int,group_id:int},Tienda:array{idTienda:int}}
     */
    private function sesion(array $deLaPantalla, array $deOtros = [], int $grupo = 1): array
    {
        require_once RUTA_TPVFOX . '/clases/ClasePermisos.php';
        $nombre = 'prueba_aplicar_' . $grupo . '_' . bin2hex(random_bytes(3));
        $this->db->query("INSERT INTO usuarios (username, password, fecha, group_id, estado, nombre) VALUES ('$nombre', MD5('x'), CURDATE(), $grupo, 'activo', 'Prueba')");
        $usuario = ['id' => (int) $this->db->insert_id, 'group_id' => $grupo];

        new \ClasePermisos($usuario, $this->db);
        $fijados = $deOtros;
        foreach ($deLaPantalla as [$accion, $valor]) {
            $fijados[] = ['mod_reorganizacion', self::VISTA, $accion, $valor];
        }
        foreach ($fijados as [$modulo, $vista, $accion, $valor]) {
            $this->db->query("DELETE FROM permisos WHERE idUsuario = {$usuario['id']} AND modulo = '$modulo' AND vista = '$vista' AND accion = '$accion'");
            $this->db->query("INSERT INTO permisos (idUsuario, modulo, vista, accion, permiso) VALUES ({$usuario['id']}, '$modulo', '$vista', '$accion', $valor)");
        }

        return ['ClasePermisos' => new \ClasePermisos($usuario, $this->db), 'Usuario' => $usuario, 'Tienda' => ['idTienda' => $this->siembra->tiendaPorDefecto()]];
    }

    private function temporalesDelEspecial(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) AS n FROM albproltemporales WHERE idProveedor = {$this->especial}")->fetch_assoc()['n'];
    }

    private function cuantas(string $tabla): int
    {
        return (int) $this->db->query("SELECT COUNT(*) AS n FROM $tabla")->fetch_assoc()['n'];
    }

    private function despachar(array $post, array $variables): mixed
    {
        return DespachoTareas::invocar(self::RUTA_TAREAS, $this->db, $post, $variables);
    }
}
