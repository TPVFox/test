<?php
/**
 * Las recetas de los derivados en el mismo árbol, por las peticiones de la pantalla: la lista con su
 * origen, dónde cuelga cada receta, lo disponible y el coste de cada derivado, el orden del cálculo, el
 * doble camino, lo que se rechaza, el albarán de la cadena —nuevo o reunido— y lo que deja compras al
 * guardarlo.
 *
 * El escenario es el pollo de febrero de 2026: entran 200 kg del proveedor habitual y salen 20 kg
 * enteros, así que hay 180 kg disponibles. Del despiece del pollo salen 0,35 kg de muslo por kilo; del
 * deshuesado del muslo, 0,7 kg de muslo deshuesado y 0,25 kg de hueso; del fileteado del muslo
 * deshuesado, 0,9 kg de filete. Las tres relaciones son del mismo tipo.
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
final class AplicarRelacionCadenaIntegracionTest extends CasoIntegracion
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
    private int $numDeshuesado;
    private int $numFileteado;

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
        $this->articulo['pollo'] = $this->siembra->articulo('Pollo entero de la cadena', ['iva' => 10, 'idProveedor' => $this->habitual]);
        foreach (['pechuga' => 6.5, 'muslo' => 3.0, 'alas' => 2.5, 'carcasa' => 0.5, 'deshuesado' => 7.0, 'hueso' => 0.2, 'filete' => 9.0, 'picada' => 5.0] as $nombre => $precio) {
            $this->articulo[$nombre] = $this->siembra->articulo(ucfirst($nombre) . ' de la cadena', ['iva' => 10]);
            $this->siembra->articuloProveedor($this->articulo[$nombre], $this->habitual, $precio);
        }
        $this->siembra->articuloProveedor($this->articulo['pollo'], $this->habitual, 2.9, ['fecha' => '2026-01-15']);
        $this->despiece = $this->escenario->tipoDeRelacion('Despiece de la cadena', '1:N', 1);
        $this->numRelacion = $this->relacion('pollo', ['pechuga' => 0.25, 'muslo' => 0.35, 'alas' => 0.12, 'carcasa' => 0.24]);
        $this->numDeshuesado = $this->relacion('muslo', ['deshuesado' => 0.7, 'hueso' => 0.25], 'Deshuesado del muslo de la cadena');
        $this->numFileteado = $this->relacion('deshuesado', ['filete' => 0.9], 'Fileteado de la cadena');

        $this->siembra->entradaProveedor($this->articulo['pollo'], 100, '2026-02-03', ['idProveedor' => $this->habitual]);
        $this->siembra->entradaProveedor($this->articulo['pollo'], 100, '2026-02-17', ['idProveedor' => $this->habitual]);
        $this->siembra->ventaTicket($this->articulo['pollo'], 15, '2026-02-20');
        $this->siembra->ventaAlbaranCliente($this->articulo['pollo'], 5, '2026-02-25');
    }

    /**
     * @comportamiento Una receta del muslo, con la cantidad vacía, toma lo que genera el despiece y
     *   parte del coste que le dio el escandallo; sus líneas van detrás de las del despiece, con el
     *   muslo en negativo por lo despiezado; y en el desajuste lo consumido resta de lo generado.
     */
    public function test_T1_unaRecetaDelDerivadoVaciaTomaLoGeneradoAlCosteDelEscandallo(): void
    {
        $calculo = $this->despachar($this->peticion('calcularAplicacion', ['hermanas' => $this->anadidas([$this->numDeshuesado => ''])]), $this->sesionCompleta());

        self::assertTrue($calculo['ok'], json_encode($calculo['avisos'], JSON_UNESCAPED_UNICODE));
        self::assertSame(180.0, $calculo['datos']['cantidad']);
        $deshuesado = $calculo['datos']['recetas'][1];
        self::assertSame([$this->numDeshuesado, $this->articulo['muslo'], 63.0], [$deshuesado['numRelacion'], $deshuesado['idEntrada'], $deshuesado['cantidad']]);
        self::assertSame($this->costeEnElEscandallo($calculo['datos']['recetas'][0], 'muslo'), $deshuesado['costeDePartida']);
        self::assertSame([44.1, 15.75], array_column($deshuesado['escandallo']['piezas'], 'cantidad'));
        $muslo = $calculo['datos']['derivados'][0];
        self::assertSame([$this->articulo['muslo'], 63.0, 0.0, 63.0, 63.0], [$muslo['idArticulo'], $muslo['generado'], $muslo['delPeriodo'], $muslo['disponible'], $muslo['aplicado']]);

        $lineas = $calculo['datos']['lineas'];
        self::assertSame(range(1, 8), array_map('intval', array_column($lineas, 'nfila')));
        self::assertSame($this->articulo['muslo'], (int) $lineas[5]['idArticulo']);
        self::assertEqualsWithDelta(-59.85, $lineas[5]['nunidades'], 0.0001);
        self::assertStringEndsWith(' · REL ' . $this->numDeshuesado . ' v1.0', $lineas[5]['cdetalle']);

        $piezas = array_column($calculo['datos']['desajuste']['piezas'], null, 'idArticulo');
        self::assertSame(3.15, $piezas[$this->articulo['muslo']]['genera']);
    }

    /**
     * @comportamiento Con muslo entrado en el periodo, lo disponible es lo generado más lo del periodo;
     *   lo que entra de más va al coste del proveedor de su ficha, y el coste es la media ponderada.
     *   Más de lo disponible se rechaza, al calcular y al preparar.
     */
    public function test_T2_loDisponibleDelDerivadoEsLoGeneradoMasLoDelPeriodoYElCosteLaMedia(): void
    {
        $this->db->query("UPDATE articulos SET idProveedor = {$this->habitual} WHERE idArticulo = {$this->articulo['muslo']}");
        $this->siembra->entradaProveedor($this->articulo['muslo'], 10, '2026-02-10', ['idProveedor' => $this->habitual]);
        $sesion = $this->sesionCompleta();

        $calculo = $this->despachar($this->peticion('calcularAplicacion', ['hermanas' => $this->anadidas([$this->numDeshuesado => '73'])]), $sesion);

        self::assertTrue($calculo['ok'], json_encode($calculo['avisos'], JSON_UNESCAPED_UNICODE));
        $muslo = $calculo['datos']['derivados'][0];
        self::assertSame([63.0, 10.0, 73.0, 73.0], [$muslo['generado'], $muslo['delPeriodo'], $muslo['disponible'], $muslo['aplicado']]);
        $deEscandallo = $this->costeEnElEscandallo($calculo['datos']['recetas'][0], 'muslo');
        self::assertEqualsWithDelta((63 * $deEscandallo + 10 * 3.0) / 73, $calculo['datos']['recetas'][1]['costeDePartida'], 0.0001);

        foreach (['calcularAplicacion', 'prepararAlbaranAplicacion'] as $pulsado) {
            $demasiado = $this->despachar($this->peticion($pulsado, ['hermanas' => $this->anadidas([$this->numDeshuesado => '74'])]), $sesion);
            self::assertFalse($demasiado['ok'], $pulsado);
            self::assertStringContainsString('Muslo de la cadena', implode(' ', array_column($demasiado['avisos'], 'mensaje')));
        }
        self::assertSame(0, $this->temporalesDelEspecial());
    }

    /** @comportamiento Si entra más de lo generado y el derivado no tiene coste de proveedor, no se calcula y se dice dónde escribirlo. */
    public function test_T3_loDeMasSinCosteDeProveedorSeRechaza(): void
    {
        $this->siembra->entradaProveedor($this->articulo['muslo'], 10, '2026-02-10', ['idProveedor' => $this->habitual]);

        $calculo = $this->despachar($this->peticion('calcularAplicacion', ['hermanas' => $this->anadidas([$this->numDeshuesado => '70'])]), $this->sesionCompleta());

        self::assertFalse($calculo['ok']);
        $mensajes = implode(' ', array_column($calculo['avisos'], 'mensaje'));
        self::assertStringContainsString('Muslo de la cadena', $mensajes);
        self::assertStringContainsString('ficha', $mensajes);
    }

    /**
     * @comportamiento Tres niveles se calculan de arriba abajo, aunque lleguen en otro orden: el
     *   fileteado parte de lo que genera el deshuesado, a su coste, y sus líneas van al final.
     */
    public function test_T4_tresNivelesSeCalculanDeArribaAbajo(): void
    {
        $calculo = $this->despachar($this->peticion('calcularAplicacion', ['hermanas' => $this->anadidas([$this->numFileteado => '', $this->numDeshuesado => ''])]), $this->sesionCompleta());

        self::assertTrue($calculo['ok'], json_encode($calculo['avisos'], JSON_UNESCAPED_UNICODE));
        self::assertSame([$this->numRelacion, $this->numDeshuesado, $this->numFileteado], array_column($calculo['datos']['recetas'], 'numRelacion'));
        $fileteado = $calculo['datos']['recetas'][2];
        self::assertSame(44.1, $fileteado['cantidad']);
        self::assertSame($this->costeEnElEscandallo($calculo['datos']['recetas'][1], 'deshuesado'), $fileteado['costeDePartida']);
        self::assertSame([39.69], array_column($fileteado['escandallo']['piezas'], 'cantidad'));
        $lineas = $calculo['datos']['lineas'];
        self::assertCount(10, $lineas);
        self::assertSame($this->articulo['deshuesado'], (int) $lineas[8]['idArticulo']);
        self::assertEqualsWithDelta(-39.69, $lineas[8]['nunidades'], 0.0001);
    }

    /** @comportamiento Un muslo que sale de dos recetas suma lo que dan las dos, a la media de sus costes. */
    public function test_T5_unDerivadoDeDosRecetasSumaLasDos(): void
    {
        $numPicada = $this->relacion('pollo', ['picada' => 0.6, 'muslo' => 0.38], 'Pollo para picada de la cadena');

        $calculo = $this->despachar($this->peticion('calcularAplicacion', ['hermanas' => $this->anadidas([$numPicada => '50', $this->numDeshuesado => ''])]), $this->sesionCompleta());

        self::assertTrue($calculo['ok'], json_encode($calculo['avisos'], JSON_UNESCAPED_UNICODE));
        [$despiece, $picada, $deshuesado] = $calculo['datos']['recetas'];
        self::assertSame([130.0, 50.0, 64.5], [$despiece['cantidad'], $picada['cantidad'], $deshuesado['cantidad']]);
        self::assertSame(64.5, $calculo['datos']['derivados'][0]['generado']);
        $media = (45.5 * $this->costeEnElEscandallo($despiece, 'muslo') + 19 * $this->costeEnElEscandallo($picada, 'muslo')) / 64.5;
        self::assertEqualsWithDelta($media, $deshuesado['costeDePartida'], 0.0001);
    }

    /**
     * @comportamiento La lista ofrece las recetas de los derivados con su origen, también con un ciclo
     *   entre las relaciones. Una receta que no cuelga del árbol, una de otra unidad o una que cierra
     *   un ciclo no calculan ni preparan.
     */
    public function test_T6_laListaLlevaLasRecetasDeLosDerivadosYLoQueNoCuelgaSeRechaza(): void
    {
        $numCiclo = $this->relacion('muslo', ['pollo' => 0.9], 'Recomponer de la cadena');
        $bandeja = $this->siembra->articulo('Bandeja de la cadena', ['iva' => 10]);
        $numUnidades = $this->escenario->relacionVigente($this->despiece, [
            ['idArticulo' => $this->articulo['muslo'], 'sentido' => 'entrada', 'cantidad' => 1, 'unidad' => 'ud'],
            ['idArticulo' => $bandeja, 'sentido' => 'salida', 'cantidad' => 2, 'unidad' => 'ud'],
        ], ['fecha' => '2026-01-10 09:00:00'])['numRelacion'];
        $sesion = $this->sesionCompleta();

        $lista = $this->despachar(['pulsado' => 'hermanasAplicacion', 'numRelacion' => (string) $this->numRelacion], $sesion);
        self::assertTrue($lista['ok'], json_encode($lista['avisos'], JSON_UNESCAPED_UNICODE));
        $origenes = array_column($lista['datos'], 'origen', 'numRelacion');
        ksort($origenes);
        $esperado = [$this->numDeshuesado => 'elegida', $this->numFileteado => 'derivado', $numCiclo => 'elegida', $numUnidades => 'elegida'];
        ksort($esperado);
        self::assertSame($esperado, $origenes);
        $deshuesado = array_column($lista['datos'], null, 'numRelacion')[$this->numDeshuesado];
        self::assertSame($this->articulo['muslo'], $deshuesado['idEntrada']);
        self::assertSame([$this->articulo['deshuesado'], $this->articulo['hueso']], $deshuesado['salidas']);

        foreach ([[$this->numFileteado => ''], [$numUnidades => '5'], [$numCiclo => '']] as $anadidas) {
            foreach (['calcularAplicacion', 'prepararAlbaranAplicacion'] as $pulsado) {
                $respuesta = $this->despachar($this->peticion($pulsado, ['hermanas' => $this->anadidas($anadidas)]), $sesion);
                self::assertFalse($respuesta['ok'], json_encode($anadidas) . ' ' . $pulsado);
                self::assertNotEmpty($respuesta['avisos']);
            }
        }
        self::assertSame(0, $this->temporalesDelEspecial());
    }

    /** @comportamiento Guardado en compras, el albarán de la cadena mueve las existencias de todos sus productos. */
    public function test_T7_guardadoEnComprasMueveLasExistenciasDeLaCadena(): void
    {
        $sesion = $this->sesionCompleta();
        $antes = $this->existencias();
        $preparado = $this->despachar($this->peticion('prepararAlbaranAplicacion', ['hermanas' => $this->anadidas([$this->numDeshuesado => ''])]), $sesion);
        self::assertTrue($preparado['ok'], json_encode($preparado['avisos'], JSON_UNESCAPED_UNICODE));

        self::assertSame([], $this->guardarEnCompras((int) $preparado['datos']['idTemporal'], $sesion));

        $despues = $this->existencias();
        self::assertEqualsWithDelta(-172.8, $despues['pollo'] - $antes['pollo'], 0.0001);
        self::assertEqualsWithDelta(3.15, $despues['muslo'] - $antes['muslo'], 0.0001);
        self::assertEqualsWithDelta(44.1, $despues['deshuesado'] - $antes['deshuesado'], 0.0001);
        self::assertEqualsWithDelta(15.75, $despues['hueso'] - $antes['hueso'], 0.0001);
    }

    /** @comportamiento Reunida en el temporal del mes, la cadena va detrás de sus líneas, en el orden del cálculo. */
    public function test_T8_laCadenaSeReuneEnElTemporalDelMes(): void
    {
        $idT = $this->temporalDelMes();

        $reunido = $this->despachar($this->peticion('prepararAlbaranAplicacion', ['hermanas' => $this->anadidas([$this->numDeshuesado => '']), 'destino' => 'temporal:' . $idT]), $this->sesionCompleta());

        self::assertTrue($reunido['ok'], json_encode($reunido['avisos'], JSON_UNESCAPED_UNICODE));
        $lineas = json_decode($this->db->query("SELECT Productos FROM albproltemporales WHERE id = $idT")->fetch_assoc()['Productos'], true);
        self::assertSame(range(1, 9), array_map('intval', array_column($lineas, 'nfila')));
        self::assertStringEndsWith(' · REL ' . $this->numDeshuesado . ' v1.0', $lineas[8]['cdetalle']);
    }

    /**
     * Una relación del tipo de la cadena que rige desde enero: el producto que entra, 1 kg, y lo que
     * sale de cada pieza. Devuelve su número.
     *
     * @param array<string,float> $salidas
     */
    private function relacion(string $entra, array $salidas, ?string $nombre = null): int
    {
        $lineas = [['idArticulo' => $this->articulo[$entra], 'sentido' => 'entrada', 'cantidad' => 1]];
        foreach ($salidas as $pieza => $cantidad) {
            $lineas[] = ['idArticulo' => $this->articulo[$pieza], 'sentido' => 'salida', 'cantidad' => $cantidad];
        }
        $opciones = ['fecha' => '2026-01-10 09:00:00'];
        if ($nombre !== null) {
            $opciones['nombre'] = $nombre;
        }

        return $this->escenario->relacionVigente($this->despiece, $lineas, $opciones)['numRelacion'];
    }

    /**
     * La lista de recetas añadidas, como la manda la pantalla: por relación, la cantidad escrita.
     *
     * @param array<int,string> $cantidades
     */
    private function anadidas(array $cantidades): string
    {
        $lista = [];
        foreach ($cantidades as $numRelacion => $cantidad) {
            $lista[] = ['numRelacion' => $numRelacion, 'cantidad' => $cantidad, 'precios' => [], 'ajustePeso' => '1', 'ajusteValor' => '1'];
        }

        return json_encode($lista);
    }

    /** El coste por unidad que le dio a una pieza el escandallo de una receta del cálculo. */
    private function costeEnElEscandallo(array $receta, string $pieza): float
    {
        foreach ($receta['escandallo']['piezas'] as $calculada) {
            if ($calculada['idArticulo'] === $this->articulo[$pieza]) {
                return $calculada['costeUnidad'];
            }
        }
        self::fail('La receta no da ' . $pieza);
    }

    /** La petición de la pantalla: febrero de 2026, el despiece del pollo, sin precios escritos, con el ajuste por defecto, sin cantidad ni recetas añadidas. */
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
        ], $cambios);
    }

    /** Un temporal abierto del especial, de fin de febrero, con el número del tipo y una línea de otro despiece. */
    private function temporalDelMes(): int
    {
        $linea = ['ccodbar' => '', 'cdetalle' => 'Línea de otro despiece', 'cref' => '', 'ref_prov' => '', 'estado' => 'Activo',
                  'idArticulo' => $this->articulo['carcasa'], 'importe' => -5.0, 'iva' => 10, 'ncant' => -10.0, 'nfila' => 1,
                  'nunidades' => -10.0, 'ultimoCoste' => 0.5];
        $productos = $this->db->real_escape_string(json_encode([$linea]));
        $numero = $this->db->real_escape_string(mb_substr('Despiece de la cadena', 0, 20));
        $this->db->query('INSERT INTO albproltemporales (idUsuario, idTienda, estadoAlbPro, Fecha, Productos, Pedidos, Su_numero, idProveedor)'
            . " VALUES ({$this->siembra->usuarioPorDefecto()}, {$this->tienda}, 'Nuevo', '2026-02-28', '$productos', '[]', '$numero', {$this->especial})");

        return (int) $this->db->insert_id;
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
        require_once RUTA_TPVFOX . '/clases/ClasePermisos.php';
        $nombre = 'prueba_cadena_' . bin2hex(random_bytes(3));
        $this->db->query("INSERT INTO usuarios (username, password, fecha, group_id, estado, nombre) VALUES ('$nombre', MD5('x'), CURDATE(), 1, 'activo', 'Prueba')");
        $usuario = ['id' => (int) $this->db->insert_id, 'group_id' => 1];
        new \ClasePermisos($usuario, $this->db);
        foreach (['ver', 'Preparar'] as $accion) {
            $this->db->query("DELETE FROM permisos WHERE idUsuario = {$usuario['id']} AND modulo = 'mod_reorganizacion' AND vista = '" . self::VISTA . "' AND accion = '$accion'");
            $this->db->query("INSERT INTO permisos (idUsuario, modulo, vista, accion, permiso) VALUES ({$usuario['id']}, 'mod_reorganizacion', '" . self::VISTA . "', '$accion', 1)");
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
