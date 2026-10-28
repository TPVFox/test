<?php
/**
 * Reunir despieces en el albarán del mes: el número del albarán que se prepara, los albaranes con
 * los que se ofrece reunir, y añadir las líneas de un despiece a un temporal abierto o a un albarán
 * guardado, siempre repitiendo la comprobación en el servidor.
 *
 * Mismo escenario que la aplicación del despiece del pollo a febrero de 2026, con un segundo
 * despiece —la ternera— para tener qué reunir. Cada caso corre en un proceso aparte por la clase de
 * complemento que comparten reorganización y productos.
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
final class AplicarRelacionReunirIntegracionTest extends CasoIntegracion
{
    private const RUTA_TAREAS = RUTA_TPVFOX . '/modulos/mod_reorganizacion/tareas.php';
    private const VISTA = 'AplicarRelacion.php';

    protected bool $compartirConexionConElProducto = true;

    private EscenarioRelaciones $escenario;
    private Siembra $siembra;
    private int $especial;
    private int $habitual;
    private int $despiece;
    private string $numeroDelTipo;
    /** @var array{id:int,numRelacion:int} */
    private array $relacionTernera;
    /** @var array<string,int> */
    private array $articulo = [];
    /** @var array{id:int,numRelacion:int} */
    private array $relacion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_compras/clases/ClaseCompras.php');
        $this->incluirTPVFox('/modulos/mod_producto/clases/ClaseArticulosStocks.php');
        $this->incluirTPVFox('/modulos/mod_relaciones/clases/RelacionesAplicacion.php');
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

        $this->numeroDelTipo = mb_substr('Despiece de la aplicación', 0, 20);
        $this->articulo['ternera'] = $this->siembra->articulo('Ternera entera de la aplicación', ['iva' => 10, 'idProveedor' => $this->habitual]);
        foreach (['aguja' => 5.5, 'falda' => 3.5] as $nombre => $precio) {
            $this->articulo[$nombre] = $this->siembra->articulo(ucfirst($nombre) . ' de la aplicación', ['iva' => 10]);
            $this->siembra->articuloProveedor($this->articulo[$nombre], $this->habitual, $precio);
        }
        $this->siembra->articuloProveedor($this->articulo['ternera'], $this->habitual, 4.0, ['fecha' => '2026-01-15']);
        $this->relacionTernera = $this->escenario->relacionVigente($this->despiece, [
            ['idArticulo' => $this->articulo['ternera'], 'sentido' => 'entrada', 'cantidad' => 1],
            ['idArticulo' => $this->articulo['aguja'], 'sentido' => 'salida', 'cantidad' => 0.5],
            ['idArticulo' => $this->articulo['falda'], 'sentido' => 'salida', 'cantidad' => 0.4],
        ], ['fecha' => '2026-01-10 09:00:00']);
        $this->siembra->entradaProveedor($this->articulo['ternera'], 80, '2026-02-05', ['idProveedor' => $this->habitual]);
        $this->siembra->entradaProveedor($this->articulo['pollo'], 100, '2026-02-03', ['idProveedor' => $this->habitual]);
        $this->siembra->entradaProveedor($this->articulo['pollo'], 100, '2026-02-17', ['idProveedor' => $this->habitual]);
        $this->siembra->ventaTicket($this->articulo['pollo'], 15, '2026-02-20');
        $this->siembra->ventaAlbaranCliente($this->articulo['pollo'], 5, '2026-02-25');
    }

    /**
     * @comportamiento El albarán preparado lleva como número el nombre del tipo de relación, en un
     *   mes y en un lote; un nombre de más de 20 caracteres se corta a 20.
     */
    public function test_T1_elNumeroDelAlbaranEsElNombreDelTipoCortadoAVeinte(): void
    {
        $sesion = $this->sesionCompleta();

        $mes = $this->despachar($this->peticion('prepararAlbaranAplicacion'), $sesion);
        $lote = $this->despachar($this->peticion('prepararAlbaranAplicacion', ['tipoPeriodo' => 'lote', 'periodo' => '2026-02-17']), $sesion);

        self::assertTrue($mes['ok'], json_encode($mes['avisos']));
        self::assertTrue($lote['ok'], json_encode($lote['avisos']));
        self::assertSame('Despiece de la aplic', $this->numeroDelTemporal((int) $mes['datos']['idTemporal']));
        self::assertSame('Despiece de la aplic', $this->numeroDelTemporal((int) $lote['datos']['idTemporal']));
    }

    /**
     * @comportamiento En un mes se ofrecen los albaranes del mismo proveedor, fecha y número; los de
     *   otro proveedor, otro mes u otro número no se ofrecen. Cada uno dice su proveedor, su fecha y
     *   su contenido.
     */
    public function test_T2_laOfertaSoloTraeLosAlbaranesDelMismoProveedorFechaYNumero(): void
    {
        $bueno = $this->temporalAbierto($this->especial, '2026-02-28', $this->numeroDelTipo);
        $this->temporalAbierto($this->otroEspecial(), '2026-02-28', $this->numeroDelTipo);
        $this->temporalAbierto($this->especial, '2026-01-31', $this->numeroDelTipo);
        $this->temporalAbierto($this->especial, '2026-02-28', 'ESCANDALLO');

        $calculo = $this->despachar($this->peticion('calcularAplicacion'), $this->sesionCompleta());

        self::assertTrue($calculo['ok'], json_encode($calculo['avisos']));
        self::assertCount(1, $calculo['datos']['destinos']);
        $destino = $calculo['datos']['destinos'][0];
        self::assertSame('temporal:' . $bueno, $destino['destino']);
        self::assertSame($this->numeroDelTipo, $destino['numero']);
        self::assertSame('2026-02-28', substr($destino['fecha'], 0, 10));
        self::assertStringContainsString('Despiece del obrador', $destino['proveedor']);
        self::assertSame(['Ternera de otro despiece'], $destino['productos']);
    }

    /**
     * @comportamiento Un albarán guardado se ofrece; uno facturado, no; y el guardado que ya tiene un
     *   temporal abierto se ofrece por su temporal, una sola vez.
     */
    public function test_T3_seOfreceElGuardadoNoElFacturadoYElGuardadoConTemporalPorSuTemporal(): void
    {
        $guardado = $this->albaranGuardado('Guardado');
        $this->albaranGuardado('Facturado');
        $otro = $this->albaranGuardado('Guardado');
        $temporalDelOtro = $this->temporalAbierto($this->especial, '2026-02-28', $this->numeroDelTipo, $otro);

        $calculo = $this->despachar($this->peticion('calcularAplicacion'), $this->sesionCompleta());

        $destinos = array_column($calculo['datos']['destinos'], 'destino');
        sort($destinos);
        $esperados = ['albaran:' . $guardado, 'temporal:' . $temporalDelOtro];
        sort($esperados);
        self::assertSame($esperados, $destinos);
    }

    /** @comportamiento Un lote no ofrece reunir, tampoco con destino por petición; un mes sin albaranes, tampoco. */
    public function test_T4_unLoteNoOfreceNiAdmiteReunir(): void
    {
        $sesion = $this->sesionCompleta();
        $this->temporalAbierto($this->especial, '2026-02-17', $this->numeroDelTipo);
        $antes = $this->temporalesDelEspecial();

        $lote = $this->despachar($this->peticion('calcularAplicacion', ['tipoPeriodo' => 'lote', 'periodo' => '2026-02-17']), $sesion);
        $conDestino = $this->despachar($this->peticion('prepararAlbaranAplicacion', ['tipoPeriodo' => 'lote', 'periodo' => '2026-02-17', 'destino' => 'temporal:1']), $sesion);
        $sinAlbaranes = $this->despachar($this->peticion('calcularAplicacion'), $sesion);

        self::assertSame([], $lote['datos']['destinos']);
        self::assertFalse($conDestino['ok']);
        self::assertSame($antes, $this->temporalesDelEspecial());
        self::assertSame([], $sinAlbaranes['datos']['destinos']);
    }

    /**
     * @comportamiento El rojo manda: si el producto ya está en el albarán de destino, no se calcula ni
     *   se ofrece nada, y el albarán no cambia.
     */
    public function test_T5_conElRojoNoSeOfreceNiSeAnade(): void
    {
        $idT = $this->temporalAbierto($this->especial, '2026-02-28', $this->numeroDelTipo, 0, [$this->lineaDe('pollo', -10.0, 1)]);
        $antes = $this->productosDelTemporal($idT);
        $sesion = $this->sesionCompleta();

        $calculo = $this->despachar($this->peticion('calcularAplicacion'), $sesion);
        $preparado = $this->despachar($this->peticion('prepararAlbaranAplicacion', ['destino' => 'temporal:' . $idT]), $sesion);

        self::assertFalse($calculo['ok']);
        self::assertSame('rojo', $calculo['datos']['aviso']['color']);
        self::assertArrayNotHasKey('destinos', $calculo['datos']);
        self::assertFalse($preparado['ok']);
        self::assertSame($antes, $this->productosDelTemporal($idT));
    }

    /**
     * @comportamiento Añadir a un temporal abierto: las líneas del despiece van detrás de las suyas, con la
     *   relación y la versión en su detalle; el temporal conserva su estado, su fecha y su número; queda a
     *   nombre del usuario de la sesión; no se crea otro temporal.
     */
    public function test_T6_anadirAUnTemporalAbiertoDejaLasLineasDeLosDosDespieces(): void
    {
        $idT = $this->temporalAbierto($this->especial, '2026-02-28', $this->numeroDelTipo, 0, [$this->lineaDe('ternera', -40.0, 1)]);
        $sesion = $this->sesionCompleta();

        $preparado = $this->despachar($this->peticion('prepararAlbaranAplicacion', ['destino' => 'temporal:' . $idT]), $sesion);

        self::assertTrue($preparado['ok'], json_encode($preparado['avisos']));
        self::assertSame($idT, (int) $preparado['datos']['idTemporal']);
        self::assertSame(1, $this->temporalesDelEspecial());
        $fila = $this->db->query("SELECT * FROM albproltemporales WHERE id = $idT")->fetch_assoc();
        self::assertSame('Nuevo', $fila['estadoAlbPro']);
        self::assertSame('2026-02-28', substr($fila['Fecha'], 0, 10));
        self::assertSame($this->numeroDelTipo, $fila['Su_numero']);
        self::assertSame($sesion['Usuario']['id'], (int) $fila['idUsuario']);
        $lineas = json_decode($fila['Productos'], true);
        self::assertCount(6, $lineas);
        self::assertSame($this->articulo['ternera'], (int) $lineas[0]['idArticulo']);
        self::assertSame($this->articulo['pollo'], (int) $lineas[1]['idArticulo']);
        self::assertSame([1, 2, 3, 4, 5, 6], array_map('intval', array_column($lineas, 'nfila')));
        for ($i = 1; $i < 6; $i++) {
            self::assertStringEndsWith(' · REL ' . $this->relacion['numRelacion'] . ' v1.0', $lineas[$i]['cdetalle']);
        }
    }

    /**
     * @comportamiento Añadir a un guardado: se abre un temporal enlazado, con las líneas del albarán y las
     *   del despiece, y el albarán queda «Sin Guardar», como cuando se edita en compras.
     */
    public function test_T7_anadirAUnGuardadoAbreUnTemporalEnlazadoConTodasLasLineas(): void
    {
        $idA = $this->albaranGuardado('Guardado');
        $sesion = $this->sesionCompleta();

        $preparado = $this->despachar($this->peticion('prepararAlbaranAplicacion', ['destino' => 'albaran:' . $idA]), $sesion);

        self::assertTrue($preparado['ok'], json_encode($preparado['avisos']));
        $idT = (int) $preparado['datos']['idTemporal'];
        $fila = $this->db->query("SELECT * FROM albproltemporales WHERE id = $idT")->fetch_assoc();
        self::assertSame($idA, (int) $fila['Numalbpro']);
        self::assertSame('Sin Guardar', $fila['estadoAlbPro']);
        self::assertSame($this->numeroDelTipo, $fila['Su_numero']);
        self::assertCount(6, json_decode($fila['Productos'], true));
        self::assertSame('Sin Guardar', $this->db->query("SELECT estado FROM albprot WHERE id = $idA")->fetch_assoc()['estado']);
        self::assertSame(1, $this->temporalesDelEspecial());
    }

    /**
     * @comportamiento Un destino de otro proveedor, otra fecha u otro número, uno facturado, uno que no
     *   existe o uno manipulado se rechaza sin escribir nada.
     */
    public function test_T8_unDestinoQueNoCumpleSeRechazaSinEscribir(): void
    {
        $bueno = $this->temporalAbierto($this->especial, '2026-02-28', $this->numeroDelTipo, 0, [$this->lineaDe('ternera', -40.0, 1)]);
        $deOtroProveedor = $this->temporalAbierto($this->otroEspecial(), '2026-02-28', $this->numeroDelTipo);
        $deOtraFecha = $this->temporalAbierto($this->especial, '2026-01-31', $this->numeroDelTipo);
        $deOtroNumero = $this->temporalAbierto($this->especial, '2026-02-28', 'ESCANDALLO');
        $facturado = $this->albaranGuardado('Facturado');
        $sesion = $this->sesionCompleta();
        $antes = [$this->productosDelTemporal($bueno), $this->temporalesDelEspecial(), $this->cuantas('albprot')];

        foreach (['temporal:' . $deOtroProveedor, 'temporal:' . $deOtraFecha, 'temporal:' . $deOtroNumero, 'albaran:' . $facturado,
                  'temporal:999999', 'albaran:999999', 'temporal:abc', 'temporal:-3', "temporal:$bueno OR 1=1", 'otro:' . $bueno, 'temporal'] as $destino) {
            $respuesta = $this->despachar($this->peticion('prepararAlbaranAplicacion', ['destino' => $destino]), $sesion);
            self::assertFalse($respuesta['ok'], $destino);
            self::assertNotEmpty($respuesta['avisos'], $destino);
        }

        self::assertSame($antes, [$this->productosDelTemporal($bueno), $this->temporalesDelEspecial(), $this->cuantas('albprot')]);
    }

    /** @comportamiento Si el albarán ofrecido deja de cumplir antes de preparar, se avisa y no se escribe nada. */
    public function test_T9_siElDestinoCambiaEntreLaOfertaYLaPreparacionSeAvisa(): void
    {
        $idT = $this->temporalAbierto($this->especial, '2026-02-28', $this->numeroDelTipo, 0, [$this->lineaDe('ternera', -40.0, 1)]);
        $sesion = $this->sesionCompleta();
        $calculo = $this->despachar($this->peticion('calcularAplicacion'), $sesion);
        self::assertSame('temporal:' . $idT, $calculo['datos']['destinos'][0]['destino']);

        $this->db->query("DELETE FROM albproltemporales WHERE id = $idT");
        $preparado = $this->despachar($this->peticion('prepararAlbaranAplicacion', ['destino' => 'temporal:' . $idT]), $sesion);

        self::assertFalse($preparado['ok']);
        self::assertNotEmpty($preparado['avisos']);
        self::assertSame(0, $this->temporalesDelEspecial());
    }

    /** @comportamiento Sin «Preparar» no se lee ni se escribe, tampoco con destino. */
    public function test_T10_sinPrepararNoSeAnadeNada(): void
    {
        $idT = $this->temporalAbierto($this->especial, '2026-02-28', $this->numeroDelTipo, 0, [$this->lineaDe('ternera', -40.0, 1)]);
        $antes = $this->productosDelTemporal($idT);

        $preparado = $this->despachar($this->peticion('prepararAlbaranAplicacion', ['destino' => 'temporal:' . $idT]), $this->sesion([['ver', 1], ['Preparar', 0]]));

        self::assertFalse($preparado['ok']);
        self::assertStringContainsString('permiso', $preparado['avisos'][0]['mensaje']);
        self::assertSame($antes, $this->productosDelTemporal($idT));
    }

    /**
     * @comportamiento Una cadena en el mismo mes: guardado en el albarán del mes el despiece del que
     *   sale el pollo, el despiece del pollo se calcula, se ofrece reunir con ese albarán y sus
     *   líneas se le añaden.
     */
    public function test_T11_unaCadenaDelMismoMesSeCalculaYSeReuneEnSuAlbaran(): void
    {
        $canal = $this->siembra->articulo('Canal de la aplicación', ['iva' => 10]);
        $deLaCanal = $this->escenario->relacionVigente($this->despiece, [
            ['idArticulo' => $canal, 'sentido' => 'entrada', 'cantidad' => 1],
            ['idArticulo' => $this->articulo['pollo'], 'sentido' => 'salida', 'cantidad' => 0.8],
        ], ['fecha' => '2026-01-10 09:00:00']);
        $idA = $this->siembra->entradaProveedor($canal, -12.5, '2026-02-28', ['idProveedor' => $this->especial, 'suNumero' => $this->numeroDelTipo]);
        $this->siembra->lineaEntrada($idA, $this->articulo['pollo'], 10.0);
        $etiqueta = $this->db->real_escape_string(' · REL ' . $deLaCanal['numRelacion'] . ' v1.0');
        $this->db->query("UPDATE albprolinea SET cdetalle = CONCAT('Línea', '$etiqueta') WHERE idalbpro = $idA");
        $sesion = $this->sesionCompleta();

        $calculo = $this->despachar($this->peticion('calcularAplicacion'), $sesion);
        self::assertTrue($calculo['ok'], json_encode($calculo['avisos'], JSON_UNESCAPED_UNICODE));
        self::assertSame('amarillo', $calculo['datos']['aviso']['color']);
        self::assertContains('albaran:' . $idA, array_column($calculo['datos']['destinos'] ?? [], 'destino'));

        $preparado = $this->despachar($this->peticion('prepararAlbaranAplicacion', ['destino' => 'albaran:' . $idA]), $sesion);
        self::assertTrue($preparado['ok'], json_encode($preparado['avisos'], JSON_UNESCAPED_UNICODE));
        $fila = $this->db->query('SELECT * FROM albproltemporales WHERE id = ' . (int) $preparado['datos']['idTemporal'])->fetch_assoc();
        self::assertSame($idA, (int) $fila['Numalbpro']);
        self::assertCount(7, json_decode($fila['Productos'], true));
    }

    private function otroEspecial(): int
    {
        return $this->proveedor('Otro despiece', 'Especial');
    }

    /**
     * Un temporal abierto del proveedor, con la fecha y el número dados; enlazado a un guardado si se indica.
     *
     * @param list<array<string,mixed>> $lineas
     */
    private function temporalAbierto(int $proveedor, string $fecha, string $numero, int $numalbpro = 0, array $lineas = []): int
    {
        if ($lineas === []) {
            $lineas = [$this->lineaDe('ternera', -10.0, 1)];
        }
        $productos = $this->db->real_escape_string(json_encode($lineas));
        $enlace = $numalbpro > 0 ? (string) $numalbpro : 'NULL';
        $estado = $numalbpro > 0 ? 'Sin Guardar' : 'Nuevo';
        $this->db->query('INSERT INTO albproltemporales (idUsuario, idTienda, estadoAlbPro, Fecha, Productos, Pedidos, Su_numero, idProveedor, Numalbpro)'
            . " VALUES ({$this->siembra->usuarioPorDefecto()}, {$this->siembra->tiendaPorDefecto()}, '$estado', '$fecha', '$productos', '[]', '$numero', $proveedor, $enlace)");

        return (int) $this->db->insert_id;
    }

    /** Un albarán guardado del proveedor Especial, de fin de febrero, con el número del tipo y una línea de otro despiece. */
    private function albaranGuardado(string $estado): int
    {
        return $this->siembra->entradaProveedor($this->articulo['ternera'], -10.0, '2026-02-28', [
            'idProveedor' => $this->especial, 'suNumero' => $this->numeroDelTipo, 'estado' => $estado,
        ]);
    }

    private function lineaDe(string $articulo, float $kg, int $fila): array
    {
        return [
            'ccodbar' => '', 'cdetalle' => 'Ternera de otro despiece', 'cref' => '', 'ref_prov' => '', 'estado' => 'Activo',
            'idArticulo' => $this->articulo[$articulo], 'importe' => $kg * 4.0, 'iva' => 10, 'ncant' => $kg, 'nfila' => $fila,
            'nunidades' => $kg, 'ultimoCoste' => 4.0,
        ];
    }

    private function numeroDelTemporal(int $id): string
    {
        return (string) $this->db->query("SELECT Su_numero FROM albproltemporales WHERE id = $id")->fetch_assoc()['Su_numero'];
    }

    private function productosDelTemporal(int $id): string
    {
        $fila = $this->db->query("SELECT Productos FROM albproltemporales WHERE id = $id")->fetch_assoc();

        return $fila === null ? '' : (string) $fila['Productos'];
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
