<?php
/**
 * Las recetas hermanas a la vez, por las peticiones de la pantalla: la lista de las que se pueden
 * añadir, calcular y preparar con ellas, lo que se rechaza, el albarán con las líneas de cada receta
 * —nuevo o reunido— y lo que deja compras al guardarlo.
 *
 * El escenario es el pollo de febrero de 2026: entran 200 kg del proveedor habitual y salen 20 kg
 * enteros, así que hay 180 kg disponibles. La relación elegida es el despiece del pollo; su hermana, del
 * mismo tipo, el pollo para picada, que da 0,6 kg de picada y 0,38 kg de muslo por kilo.
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
final class AplicarRelacionHermanasIntegracionTest extends CasoIntegracion
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
    private int $numPicada;
    private int $numDeOtroTipo;
    private int $numDeOtroProducto;

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
        $this->articulo['pollo'] = $this->siembra->articulo('Pollo entero de las hermanas', ['iva' => 10, 'idProveedor' => $this->habitual]);
        $this->articulo['ternera'] = $this->siembra->articulo('Ternera de las hermanas', ['iva' => 10, 'idProveedor' => $this->habitual]);
        foreach (['pechuga' => 6.5, 'muslo' => 3.0, 'alas' => 2.5, 'carcasa' => 0.5, 'picada' => 5.0, 'aguja' => 5.5] as $nombre => $precio) {
            $this->articulo[$nombre] = $this->siembra->articulo(ucfirst($nombre) . ' de las hermanas', ['iva' => 10]);
            $this->siembra->articuloProveedor($this->articulo[$nombre], $this->habitual, $precio);
        }
        $this->siembra->articuloProveedor($this->articulo['pollo'], $this->habitual, 2.9, ['fecha' => '2026-01-15']);
        $this->siembra->articuloProveedor($this->articulo['ternera'], $this->habitual, 4.0, ['fecha' => '2026-01-15']);
        $this->despiece = $this->escenario->tipoDeRelacion('Despiece de las hermanas', '1:N', 1);
        $otroTipo = $this->escenario->tipoDeRelacion('Otro despiece de las hermanas', '1:N', 1);
        $fecha = ['fecha' => '2026-01-10 09:00:00'];
        $this->numRelacion = $this->escenario->relacionVigente($this->despiece, [
            ['idArticulo' => $this->articulo['pollo'], 'sentido' => 'entrada', 'cantidad' => 1],
            ['idArticulo' => $this->articulo['pechuga'], 'sentido' => 'salida', 'cantidad' => 0.25],
            ['idArticulo' => $this->articulo['muslo'], 'sentido' => 'salida', 'cantidad' => 0.35],
            ['idArticulo' => $this->articulo['alas'], 'sentido' => 'salida', 'cantidad' => 0.12],
            ['idArticulo' => $this->articulo['carcasa'], 'sentido' => 'salida', 'cantidad' => 0.24],
        ], $fecha)['numRelacion'];
        $this->numPicada = $this->escenario->relacionVigente($this->despiece, [
            ['idArticulo' => $this->articulo['pollo'], 'sentido' => 'entrada', 'cantidad' => 1],
            ['idArticulo' => $this->articulo['picada'], 'sentido' => 'salida', 'cantidad' => 0.6],
            ['idArticulo' => $this->articulo['muslo'], 'sentido' => 'salida', 'cantidad' => 0.38],
        ], $fecha + ['nombre' => 'Pollo para picada de las hermanas'])['numRelacion'];
        $this->numDeOtroTipo = $this->escenario->relacionVigente($otroTipo, [
            ['idArticulo' => $this->articulo['pollo'], 'sentido' => 'entrada', 'cantidad' => 1],
            ['idArticulo' => $this->articulo['muslo'], 'sentido' => 'salida', 'cantidad' => 0.5],
        ], $fecha)['numRelacion'];
        $this->numDeOtroProducto = $this->escenario->relacionVigente($this->despiece, [
            ['idArticulo' => $this->articulo['ternera'], 'sentido' => 'entrada', 'cantidad' => 1],
            ['idArticulo' => $this->articulo['aguja'], 'sentido' => 'salida', 'cantidad' => 0.5],
        ], $fecha)['numRelacion'];

        $this->siembra->entradaProveedor($this->articulo['pollo'], 100, '2026-02-03', ['idProveedor' => $this->habitual]);
        $this->siembra->entradaProveedor($this->articulo['pollo'], 100, '2026-02-17', ['idProveedor' => $this->habitual]);
        $this->siembra->ventaTicket($this->articulo['pollo'], 15, '2026-02-20');
        $this->siembra->ventaAlbaranCliente($this->articulo['pollo'], 5, '2026-02-25');
    }

    /**
     * @comportamiento Con una hermana, la elegida vacía toma lo que sobra; cada receta se calcula con
     *   sus precios y su ajuste; las líneas van agrupadas por receta y numeradas seguidas; y el
     *   desajuste suma lo que dan las dos, con el mínimo de cada una supuesta la otra.
     */
    public function test_T1_conUnaHermanaSeRepartenLoDisponibleYCadaRecetaSeCalculaConLoSuyo(): void
    {
        $this->siembra->ventaTicket($this->articulo['muslo'], 100, '2026-02-22');
        $hermanas = [['numRelacion' => $this->numPicada, 'cantidad' => '50', 'precios' => [$this->articulo['picada'] => '7,5'], 'ajustePeso' => '2', 'ajusteValor' => '0']];

        $calculo = $this->despachar($this->peticion('calcularAplicacion', ['hermanas' => json_encode($hermanas)]), $this->sesionCompleta());

        self::assertTrue($calculo['ok'], json_encode($calculo['avisos'], JSON_UNESCAPED_UNICODE));
        self::assertSame(130.0, $calculo['datos']['cantidad']);
        self::assertSame([[$this->numRelacion, 130.0], [$this->numPicada, 50.0]], array_map(fn(array $r): array => [$r['numRelacion'], $r['cantidad']], $calculo['datos']['recetas']));
        self::assertSame(32.5, $calculo['datos']['escandallo']['piezas'][0]['cantidad']);
        $deLaPicada = $calculo['datos']['recetas'][1]['escandallo'];
        self::assertSame([30.0, 19.0], array_column($deLaPicada['piezas'], 'cantidad'));
        self::assertSame(['escrito', 'media'], array_column($deLaPicada['piezas'], 'origenPrecio'));
        self::assertEqualsWithDelta($deLaPicada['piezas'][0]['costeUnidad'], $deLaPicada['piezas'][1]['costeUnidad'], 0.001);
        self::assertSame(['media', 'media', 'media', 'media'], array_column($calculo['datos']['escandallo']['piezas'], 'origenPrecio'));

        $lineas = $calculo['datos']['lineas'];
        self::assertSame(range(1, 8), array_map('intval', array_column($lineas, 'nfila')));
        for ($i = 0; $i < 8; $i++) {
            $deQue = $i < 5 ? $this->numRelacion : $this->numPicada;
            self::assertStringEndsWith(' · REL ' . $deQue . ' v1.0', $lineas[$i]['cdetalle'], (string) $i);
        }

        $muslo = $calculo['datos']['desajuste']['piezas'][1];
        self::assertSame([64.5, -35.5], [$muslo['genera'], $muslo['queda']]);
        self::assertSame([$this->numRelacion => 231.429, $this->numPicada => 143.422], $calculo['datos']['desajuste']['minimos']);
    }

    /** @comportamiento Una relación que no es hermana de la elegida, repetida, la elegida o mal escrita no calcula ni prepara. */
    public function test_T2_unaHermanaQueNoLoEsSeRechazaSinEscribir(): void
    {
        $sesion = $this->sesionCompleta();
        $una = fn($numRelacion, string $cantidad = '10'): array => ['numRelacion' => $numRelacion, 'cantidad' => $cantidad, 'precios' => [], 'ajustePeso' => '1', 'ajusteValor' => '1'];
        $listas = [
            json_encode([$una($this->numDeOtroTipo)]),
            json_encode([$una($this->numDeOtroProducto)]),
            json_encode([$una($this->numPicada), $una($this->numPicada)]),
            json_encode([$una($this->numRelacion)]),
            json_encode([$una('1 OR 1=1')]),
            json_encode([$una(999999)]),
            json_encode([$una($this->numPicada, 'abc')]),
            'no es una lista',
        ];

        foreach ($listas as $lista) {
            $calculo = $this->despachar($this->peticion('calcularAplicacion', ['hermanas' => $lista]), $sesion);
            self::assertFalse($calculo['ok'], $lista);
            self::assertNotEmpty($calculo['avisos'], $lista);
            self::assertArrayNotHasKey('escandallo', $calculo['datos'], $lista);
            self::assertFalse($this->despachar($this->peticion('prepararAlbaranAplicacion', ['hermanas' => $lista]), $sesion)['ok'], $lista);
        }
        self::assertSame(0, $this->temporalesDelEspecial());
    }

    /**
     * @comportamiento La suma de las recetas no pasa de lo disponible, al calcular ni al preparar; una
     *   hermana vacía no entra y la elegida toma todo.
     */
    public function test_T3_laSumaNoPasaDeLoDisponibleYUnaHermanaVaciaNoEntra(): void
    {
        $sesion = $this->sesionCompleta();
        $con = fn(string $principal, string $hermana): array => ['cantidad' => $principal, 'hermanas' => json_encode([['numRelacion' => $this->numPicada, 'cantidad' => $hermana, 'precios' => [], 'ajustePeso' => '1', 'ajusteValor' => '1']])];

        foreach ([['150', '50'], ['', '181']] as [$principal, $hermana]) {
            $calculo = $this->despachar($this->peticion('calcularAplicacion', $con($principal, $hermana)), $sesion);
            self::assertFalse($calculo['ok']);
            self::assertStringContainsString('disponible', implode(' ', array_column($calculo['avisos'], 'mensaje')));
            self::assertFalse($this->despachar($this->peticion('prepararAlbaranAplicacion', $con($principal, $hermana)), $sesion)['ok']);
        }
        self::assertSame(0, $this->temporalesDelEspecial());

        $vacia = $this->despachar($this->peticion('calcularAplicacion', $con('', '')), $sesion);
        self::assertTrue($vacia['ok'], json_encode($vacia['avisos'], JSON_UNESCAPED_UNICODE));
        self::assertSame(180.0, $vacia['datos']['cantidad']);
        self::assertSame(0.0, $vacia['datos']['recetas'][1]['cantidad']);
        self::assertCount(5, $vacia['datos']['lineas']);
    }

    /**
     * @comportamiento Preparar con una hermana deja un albarán con las líneas de las dos recetas
     *   agrupadas; reunido en el temporal del mes, van detrás de las suyas.
     */
    public function test_T4_prepararConUnaHermanaDejaLasLineasAgrupadasTambienAlReunir(): void
    {
        $sesion = $this->sesionCompleta();
        $hermanas = json_encode([['numRelacion' => $this->numPicada, 'cantidad' => '50', 'precios' => [], 'ajustePeso' => '1', 'ajusteValor' => '1']]);

        $nuevo = $this->despachar($this->peticion('prepararAlbaranAplicacion', ['hermanas' => $hermanas]), $sesion);
        self::assertTrue($nuevo['ok'], json_encode($nuevo['avisos'], JSON_UNESCAPED_UNICODE));
        $lineas = $this->lineasDelTemporal((int) $nuevo['datos']['idTemporal']);
        self::assertSame(range(1, 8), array_map('intval', array_column($lineas, 'nfila')));
        self::assertStringEndsWith(' · REL ' . $this->numPicada . ' v1.0', $lineas[5]['cdetalle']);
        $this->db->query("DELETE FROM albproltemporales WHERE id = {$nuevo['datos']['idTemporal']}");

        $idT = $this->temporalDelMes([$this->lineaDe('ternera', -10.0, 1)]);
        $reunido = $this->despachar($this->peticion('prepararAlbaranAplicacion', ['hermanas' => $hermanas, 'destino' => 'temporal:' . $idT]), $sesion);
        self::assertTrue($reunido['ok'], json_encode($reunido['avisos'], JSON_UNESCAPED_UNICODE));
        $lineas = $this->lineasDelTemporal($idT);
        self::assertSame(range(1, 9), array_map('intval', array_column($lineas, 'nfila')));
        self::assertSame($this->articulo['ternera'], (int) $lineas[0]['idArticulo']);
        self::assertStringEndsWith(' · REL ' . $this->numPicada . ' v1.0', $lineas[8]['cdetalle']);
    }

    /** @comportamiento Guardado en compras, el albarán de dos recetas mueve las existencias de las dos. */
    public function test_T5_guardadoEnComprasMueveLasExistenciasDeLasDosRecetas(): void
    {
        $sesion = $this->sesionCompleta();
        $antes = $this->existencias();
        $hermanas = json_encode([['numRelacion' => $this->numPicada, 'cantidad' => '50', 'precios' => [], 'ajustePeso' => '1', 'ajusteValor' => '1']]);
        $preparado = $this->despachar($this->peticion('prepararAlbaranAplicacion', ['hermanas' => $hermanas]), $sesion);

        self::assertSame([], $this->guardarEnCompras((int) $preparado['datos']['idTemporal'], $sesion));

        $despues = $this->existencias();
        self::assertEqualsWithDelta(-173.8, $despues['pollo'] - $antes['pollo'], 0.0001);
        self::assertEqualsWithDelta(64.5, $despues['muslo'] - $antes['muslo'], 0.0001);
        self::assertEqualsWithDelta(30.0, $despues['picada'] - $antes['picada'], 0.0001);
    }

    /** @comportamiento La lista de hermanas se pide con «ver»: llegan las del mismo tipo y producto; sin el permiso, nada. */
    public function test_T6_laListaDeHermanasSePideConElPermisoDeVer(): void
    {
        $lista = $this->despachar(['pulsado' => 'hermanasAplicacion', 'numRelacion' => (string) $this->numRelacion], $this->sesionCompleta());
        self::assertTrue($lista['ok'], json_encode($lista['avisos'], JSON_UNESCAPED_UNICODE));
        self::assertSame([$this->numPicada], array_column($lista['datos'], 'numRelacion'));

        $sinVer = $this->despachar(['pulsado' => 'hermanasAplicacion', 'numRelacion' => (string) $this->numRelacion], $this->sesion(0));
        self::assertFalse($sinVer['ok']);
        self::assertStringContainsString('permiso', $sinVer['avisos'][0]['mensaje']);
        self::assertSame([], $sinVer['datos']);
        self::assertFalse($this->despachar(['pulsado' => 'hermanasAplicacion', 'numRelacion' => '1 OR 1=1'], $this->sesionCompleta())['ok']);
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
        $numero = $this->db->real_escape_string(mb_substr('Despiece de las hermanas', 0, 20));
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
        $nombre = 'prueba_hermanas_' . bin2hex(random_bytes(3));
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
