<?php
/**
 * Lo que la aplicación de una relación lee de la base: qué relaciones se pueden aplicar, el
 * coste de partida del producto de entrada, el precio de mercado de cada pieza y el aviso de
 * proceso duplicado. Ninguna de estas lecturas escribe nada.
 *
 * El periodo de los avisos es febrero de 2026, y el proveedor elegido, un proveedor «Especial».
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModRelaciones;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\EscenarioRelaciones;
use TPVFox\Test\Siembra\Siembra;

final class RelacionesAplicacionIntegracionTest extends CasoIntegracion
{
    protected bool $compartirConexionConElProducto = true;

    private EscenarioRelaciones $escenario;
    private Siembra $siembra;
    private int $elegido;
    private int $otroEspecial;
    private int $habitual;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_relaciones/clases/RelacionesAplicacion.php');
        $this->incluirTPVFox('/modulos/mod_reorganizacion/clases/ClaseComprobacionStockConsulta.php');
        $this->escenario = new EscenarioRelaciones($this->db);
        $this->siembra = new Siembra($this->db);
        $this->elegido = $this->proveedor('Despiece de la prueba', 'Especial');
        $this->otroEspecial = $this->proveedor('Otro especial de la prueba', 'Especial');
        $this->habitual = $this->proveedor('Habitual de la prueba', 'Activo');
    }

    /**
     * @comportamiento Solo se ofrecen las relaciones que rigen de forma 1:N con cantidad y todas
     *   sus líneas en la misma unidad.
     */
    public function test_T1_soloSeOfrecenLasRelacionesUnoAVariosConCantidadYUnaSolaUnidad(): void
    {
        $a = $this->siembra->articulo('Aplicación: entra');
        $b = $this->siembra->articulo('Aplicación: sale');
        $c = $this->siembra->articulo('Aplicación: sale también');
        $lineas = [['idArticulo' => $a, 'sentido' => 'entrada'], ['idArticulo' => $b, 'sentido' => 'salida', 'cantidad' => 0.5], ['idArticulo' => $c, 'sentido' => 'salida', 'cantidad' => 0.4]];
        $despiece = $this->escenario->relacionVigente($this->escenario->tipoDeRelacion('Despiece de la prueba', '1:N', 1), $lineas);
        $sinCantidad = $this->escenario->relacionVigente($this->escenario->tipoDeRelacion('Surtido de la prueba', '1:N', 0), [['idArticulo' => $a, 'sentido' => 'entrada', 'cantidad' => null, 'unidad' => null], ['idArticulo' => $b, 'sentido' => 'salida', 'cantidad' => null, 'unidad' => null]]);
        $elaboracion = $this->escenario->relacionVigente($this->escenario->tipoDeRelacion('Elaboración de la prueba', 'N:1', 1), [['idArticulo' => $b, 'sentido' => 'entrada', 'cantidad' => 0.5], ['idArticulo' => $c, 'sentido' => 'entrada', 'cantidad' => 0.5], ['idArticulo' => $a, 'sentido' => 'salida']]);
        $mezcla = $lineas;
        $mezcla[2]['unidad'] = 'ud';
        $mezclada = $this->escenario->relacionVigente($this->escenario->tipoDeRelacion('Despiece mezclado de la prueba', '1:N', 1), $mezcla);

        $ofrecidas = array_column($this->aplicacion()->relacionesAplicables()['datos'], 'numRelacion');

        self::assertContains($despiece['numRelacion'], $ofrecidas);
        foreach ([$sinCantidad, $elaboracion, $mezclada] as $noAplicable) {
            self::assertNotContains($noAplicable['numRelacion'], $ofrecidas);
        }
    }

    /**
     * @comportamiento El coste de partida es el de la ficha del producto con su proveedor
     *   principal, y viene con su fecha. Sin proveedor principal, o sin su coste, se rechaza y el
     *   aviso dice qué producto y dónde corregirlo.
     */
    public function test_T2_elCosteDePartidaEsElDelProveedorPrincipalConSuFecha(): void
    {
        $conCoste = $this->siembra->articulo('Pollo de la prueba', ['idProveedor' => $this->habitual]);
        $this->siembra->articuloProveedor($conCoste, $this->habitual, 2.9, ['fecha' => '2026-01-15']);
        $sinPrincipal = $this->siembra->articulo('Pollo sin principal de la prueba');
        $sinFila = $this->siembra->articulo('Pollo sin coste de la prueba', ['idProveedor' => $this->habitual]);

        $coste = $this->aplicacion()->costeDePartida($conCoste);
        self::assertSame(2.9, $coste['datos']['coste']);
        self::assertSame('2026-01-15', $coste['datos']['fecha']);

        foreach ([$sinPrincipal => 'Pollo sin principal de la prueba', $sinFila => 'Pollo sin coste de la prueba'] as $id => $nombre) {
            $rechazo = $this->aplicacion()->costeDePartida($id);
            self::assertArrayHasKey('error', $rechazo);
            self::assertStringContainsString($nombre, $rechazo['error']['mensaje']);
            self::assertStringContainsString('ficha', $rechazo['error']['mensaje']);
        }
    }

    /**
     * @comportamiento El precio de mercado de una pieza es la media del coste de sus proveedores;
     *   no cuentan los proveedores «Especial» —el del despiece deja allí sus filas al guardar— ni
     *   las filas inactivas. Una pieza sin ninguna fila así no tiene precio.
     */
    public function test_T3_elPrecioDeMercadoEsLaMediaSinProveedoresEspecialNiFilasInactivas(): void
    {
        $pechuga = $this->siembra->articulo('Pechuga de la prueba');
        $carcasa = $this->siembra->articulo('Carcasa de la prueba');
        $segundo = $this->proveedor('Segundo habitual de la prueba', 'Activo');
        $tercero = $this->proveedor('Tercero inactivo de la prueba', 'Activo');
        $this->siembra->articuloProveedor($pechuga, $this->habitual, 6.0);
        $this->siembra->articuloProveedor($pechuga, $segundo, 7.0);
        $this->siembra->articuloProveedor($pechuga, $this->elegido, 3.1);
        $this->siembra->articuloProveedor($pechuga, $tercero, 20.0, ['estado' => 'inactivo']);

        $precios = $this->aplicacion()->preciosDeMercado([$pechuga, $carcasa])['datos'];

        self::assertEqualsWithDelta(6.5, $precios[$pechuga], 0.000001);
        self::assertNull($precios[$carcasa]);
    }

    /**
     * @comportamiento El aviso mira los albaranes de proveedores «Especial» con el producto desde
     *   el primer día del periodo: el mismo proveedor dentro del periodo, rojo; el mismo después,
     *   u otro «Especial» en el periodo o después, amarillo. Uno anterior al periodo, o de un
     *   proveedor que no es «Especial», no cuenta.
     */
    public function test_T4_elAvisoMarcaRojoElMismoProveedorEnElPeriodoYAmarilloLoDemas(): void
    {
        $casos = [
            'rojo'     => [$this->elegido, '2026-02-15'],
            'amarillo' => [$this->elegido, '2026-03-10'],
            'otro en el periodo' => [$this->otroEspecial, '2026-02-15'],
            'otro después' => [$this->otroEspecial, '2026-03-10'],
            'antes del periodo' => [$this->elegido, '2026-01-20'],
            'de un habitual' => [$this->habitual, '2026-02-15'],
        ];
        $esperado = ['rojo' => 'rojo', 'amarillo' => 'amarillo', 'otro en el periodo' => 'amarillo', 'otro después' => 'amarillo', 'antes del periodo' => 'verde', 'de un habitual' => 'verde'];

        foreach ($casos as $caso => [$proveedor, $fecha]) {
            $producto = $this->siembra->articulo('Pollo del aviso: ' . $caso);
            $this->siembra->entradaProveedor($producto, -10, $fecha, ['idProveedor' => $proveedor]);

            self::assertSame($esperado[$caso], $this->aviso($producto)['color'], $caso);
        }
        self::assertSame('verde', $this->aviso($this->siembra->articulo('Pollo sin albaranes'))['color']);
    }

    /**
     * @comportamiento Un albarán temporal de compras cuenta como un albarán: el del mismo
     *   proveedor en el periodo, rojo; el de otro «Especial» después, amarillo. Un temporal que no
     *   lleva el producto no cuenta.
     */
    public function test_T5_elAvisoCuentaLosTemporalesAbiertosDeCompras(): void
    {
        $enRojo = $this->siembra->articulo('Pollo con temporal en el periodo');
        $enAmarillo = $this->siembra->articulo('Pollo con temporal después');
        $sinEl = $this->siembra->articulo('Pollo que no está en el temporal');
        $otro = $this->siembra->articulo('Otro producto del temporal');

        $this->temporalDeCompras($this->elegido, '2026-02-28', [$enRojo, $otro]);
        $this->temporalDeCompras($this->otroEspecial, '2026-03-05', [$enAmarillo]);
        $this->temporalDeCompras($this->elegido, '2026-02-10', [$otro]);

        self::assertSame('rojo', $this->aviso($enRojo)['color']);
        self::assertSame('amarillo', $this->aviso($enAmarillo)['color']);
        self::assertSame('verde', $this->aviso($sinEl)['color']);
    }

    /** @comportamiento El aviso nombra cada albarán o temporal, su proveedor y su fecha. */
    public function test_T6_elAvisoNombraElAlbaranSuProveedorYSuFecha(): void
    {
        $producto = $this->siembra->articulo('Pollo del aviso con nombre');
        $albaran = $this->siembra->entradaProveedor($producto, -10, '2026-02-15', ['idProveedor' => $this->otroEspecial]);
        $temporal = $this->temporalDeCompras($this->elegido, '2026-03-02', [$producto]);

        $aviso = $this->aviso($producto);

        self::assertSame('amarillo', $aviso['color']);
        self::assertCount(2, $aviso['documentos']);
        self::assertContains(['documento' => 'albarán', 'numero' => $this->numeroDeAlbaran($albaran), 'proveedor' => 'Otro especial de la prueba', 'fecha' => '2026-02-15', 'color' => 'amarillo'], $aviso['documentos']);
        self::assertContains(['documento' => 'temporal', 'numero' => $temporal, 'proveedor' => 'Despiece de la prueba', 'fecha' => '2026-03-02', 'color' => 'amarillo'], $aviso['documentos']);
    }

    /** @comportamiento Las lecturas de la aplicación no escriben en ninguna tabla. */
    public function test_T7_lasLecturasDeLaAplicacionNoEscribenNada(): void
    {
        $producto = $this->siembra->articulo('Pollo de las lecturas', ['idProveedor' => $this->habitual]);
        $this->siembra->articuloProveedor($producto, $this->habitual, 2.9);
        $aplicacion = $this->aplicacionQueAnota();

        $aplicacion->relacionesAplicables();
        $aplicacion->costeDePartida($producto);
        $aplicacion->preciosDeMercado([$producto]);
        $aplicacion->avisoDeDuplicado([$producto], $this->elegido, '2026-02-01', '2026-02-28');
        $aplicacion->datosDeLineas([$producto]);

        self::assertNotEmpty($aplicacion->sentencias);
        foreach ($aplicacion->sentencias as $sql) {
            self::assertMatchesRegularExpression('/^SELECT /', ltrim($sql));
        }
    }

    /**
     * @comportamiento Los periodos que se ofrecen son los meses en que entró el producto del
     *   despiece, del más reciente al más antiguo y con los kg que entraron. No cuentan las
     *   salidas, las líneas eliminadas, los albaranes sin guardar ni otros productos.
     */
    public function test_T8_losMesesQueSeOfrecenSonLosQueTuvieronEntradasDelProducto(): void
    {
        $numRelacion = $this->despieceDelPollo($pollo, $otro);
        $this->siembra->entradaProveedor($pollo, 50, '2026-01-05', ['idProveedor' => $this->habitual]);
        $this->siembra->entradaProveedor($pollo, 100, '2026-02-03', ['idProveedor' => $this->habitual]);
        $this->siembra->entradaProveedor($pollo, 100, '2026-02-17', ['idProveedor' => $this->habitual]);
        $this->siembra->devolucionProveedor($pollo, 10, '2026-02-18', ['idProveedor' => $this->habitual]);
        $this->siembra->entradaProveedor($pollo, 30, '2026-03-02', ['idProveedor' => $this->habitual, 'estado' => 'Sin Guardar']);
        $this->siembra->entradaProveedor($pollo, 40, '2026-04-02', ['idProveedor' => $this->habitual, 'estadoLinea' => 'Eliminado']);
        $this->siembra->entradaProveedor($otro, 60, '2026-05-02', ['idProveedor' => $this->habitual]);

        $meses = $this->aplicacion()->periodosConEntradas($numRelacion, 'mes', new \ClaseComprobacionStockConsulta());

        self::assertSame([['valor' => '2026-02', 'cantidad' => 200.0, 'unidad' => 'kg'], ['valor' => '2026-01', 'cantidad' => 50.0, 'unidad' => 'kg']], $meses['datos']);
    }

    /**
     * @comportamiento Por lotes se ofrecen los días con entradas, también del más reciente al
     *   más antiguo. Un tipo de periodo desconocido, una relación que no existe o que no se
     *   aplica, o un identificador que no es un número se rechazan con su aviso.
     */
    public function test_T9_losDiasQueSeOfrecenYLosRechazos(): void
    {
        $numRelacion = $this->despieceDelPollo($pollo, $otro);
        $this->siembra->entradaProveedor($pollo, 50, '2026-01-05', ['idProveedor' => $this->habitual]);
        $this->siembra->entradaProveedor($pollo, 100, '2026-02-17', ['idProveedor' => $this->habitual]);
        $this->siembra->entradaProveedor($pollo, 25, '2026-02-17', ['idProveedor' => $this->habitual]);
        $consulta = new \ClaseComprobacionStockConsulta();

        $dias = $this->aplicacion()->periodosConEntradas($numRelacion, 'lote', $consulta);
        self::assertSame([['valor' => '2026-02-17', 'cantidad' => 125.0, 'unidad' => 'kg'], ['valor' => '2026-01-05', 'cantidad' => 50.0, 'unidad' => 'kg']], $dias['datos']);

        $elaboracion = $this->escenario->relacionVigente($this->escenario->tipoDeRelacion('Elaboración de los periodos', 'N:1', 1), [
            ['idArticulo' => $pollo, 'sentido' => 'entrada', 'cantidad' => 0.5],
            ['idArticulo' => $otro, 'sentido' => 'entrada', 'cantidad' => 0.5],
            ['idArticulo' => $this->siembra->articulo('Producto elaborado de los periodos'), 'sentido' => 'salida'],
        ], ['fecha' => '2026-01-10 09:00:00']);
        foreach ([[$numRelacion, 'semana'], [999999, 'mes'], [$elaboracion['numRelacion'], 'mes'], ['1 OR 1=1', 'mes']] as [$relacion, $tipo]) {
            $rechazo = $this->aplicacion()->periodosConEntradas($relacion, $tipo, $consulta);
            self::assertArrayHasKey('error', $rechazo, $tipo);
            self::assertArrayNotHasKey('datos', $rechazo);
        }
    }

    private function aplicacion(): \RelacionesAplicacion
    {
        return new \RelacionesAplicacion();
    }

    private function aplicacionQueAnota(): object
    {
        return new class extends \RelacionesAplicacion {
            /** @var list<string> */
            public array $sentencias = [];

            protected function consultaPreparada($sql, $valores = array())
            {
                $this->sentencias[] = $sql;

                return parent::consultaPreparada($sql, $valores);
            }
        };
    }

    /** @return array{color:string,documentos:list<array<string,mixed>>} */
    private function aviso(int $producto): array
    {
        $aviso = $this->aplicacion()->avisoDeDuplicado([$producto], $this->elegido, '2026-02-01', '2026-02-28');
        self::assertArrayHasKey('datos', $aviso, json_encode($aviso['error'] ?? null, JSON_UNESCAPED_UNICODE));

        return $aviso['datos'];
    }

    /** Un despiece que rige desde enero de 2026; devuelve su número y deja en $pollo y $otro los productos. */
    private function despieceDelPollo(&$pollo, &$otro): int
    {
        $pollo = $this->siembra->articulo('Pollo de los periodos', ['idProveedor' => $this->habitual]);
        $pechuga = $this->siembra->articulo('Pechuga de los periodos');
        $otro = $this->siembra->articulo('Otro producto de los periodos');
        $despiece = $this->escenario->relacionVigente($this->escenario->tipoDeRelacion('Despiece de los periodos', '1:N', 1), [
            ['idArticulo' => $pollo, 'sentido' => 'entrada', 'cantidad' => 1],
            ['idArticulo' => $pechuga, 'sentido' => 'salida', 'cantidad' => 0.5],
        ], ['fecha' => '2026-01-01 09:00:00']);

        return $despiece['numRelacion'];
    }

    private function proveedor(string $nombre, string $estado): int
    {
        $id = $this->siembra->proveedor($nombre);
        $this->db->query("UPDATE proveedores SET estado = '$estado', nombrecomercial = '$nombre' WHERE idProveedor = $id");

        return $id;
    }

    /** Un albarán temporal de compras con una línea activa por producto, como lo deja la pantalla. */
    private function temporalDeCompras(int $proveedor, string $fecha, array $productos): int
    {
        $lineas = [];
        foreach ($productos as $posicion => $producto) {
            $lineas[] = ['idArticulo' => $producto, 'cdetalle' => 'Línea', 'cref' => '', 'ccodbar' => '', 'ref_prov' => '', 'estado' => 'Activo',
                         'ncant' => 1, 'nunidades' => 1, 'ultimoCoste' => 1, 'importe' => 1, 'iva' => 10, 'nfila' => $posicion + 1];
        }
        $json = json_encode($lineas);
        $alta = $this->db->prepare("INSERT INTO albproltemporales (idUsuario, idTienda, estadoAlbPro, Fecha, idProveedor, Productos, Pedidos, Su_numero) VALUES (?, ?, 'Nuevo', ?, ?, ?, '[]', '')");
        $usuario = $this->siembra->usuarioPorDefecto();
        $tienda = $this->siembra->tiendaPorDefecto();
        $alta->bind_param('iisis', $usuario, $tienda, $fecha, $proveedor, $json);
        $alta->execute();

        return (int) $this->db->insert_id;
    }

    private function numeroDeAlbaran(int $id): int
    {
        return (int) $this->db->query("SELECT Numalbpro FROM albprot WHERE id = $id")->fetch_assoc()['Numalbpro'];
    }
}
