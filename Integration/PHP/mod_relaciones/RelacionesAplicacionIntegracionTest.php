<?php
/**
 * Lo que la aplicación de una relación lee de la base: qué relaciones se pueden aplicar, el
 * coste de partida del producto de entrada, el precio de mercado de cada pieza, el aviso de
 * proceso duplicado y lo disponible. Ninguna de estas lecturas escribe nada.
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
     *   el primer día del periodo, y todos salen en amarillo: del mismo proveedor o de otro «Especial»,
     *   en el periodo o después. Uno anterior al periodo, o de un proveedor que no es «Especial», no
     *   cuenta.
     */
    public function test_T4_elAvisoMarcaEnAmarilloLosAlbaranesDeEspecialesDesdeElPeriodo(): void
    {
        $casos = [
            'mismo en el periodo' => [$this->elegido, '2026-02-15'],
            'mismo después' => [$this->elegido, '2026-03-10'],
            'otro en el periodo' => [$this->otroEspecial, '2026-02-15'],
            'otro después' => [$this->otroEspecial, '2026-03-10'],
            'antes del periodo' => [$this->elegido, '2026-01-20'],
            'de un habitual' => [$this->habitual, '2026-02-15'],
        ];
        $esperado = ['mismo en el periodo' => 'amarillo', 'mismo después' => 'amarillo', 'otro en el periodo' => 'amarillo', 'otro después' => 'amarillo', 'antes del periodo' => 'verde', 'de un habitual' => 'verde'];

        foreach ($casos as $caso => [$proveedor, $fecha]) {
            $producto = $this->siembra->articulo('Pollo del aviso: ' . $caso);
            $this->siembra->entradaProveedor($producto, -10, $fecha, ['idProveedor' => $proveedor]);

            self::assertSame($esperado[$caso], $this->aviso($producto)['color'], $caso);
        }
        self::assertSame('verde', $this->aviso($this->siembra->articulo('Pollo sin albaranes'))['color']);
    }

    /**
     * @comportamiento Un albarán temporal de compras cuenta como un albarán: el del mismo
     *   proveedor en el periodo y el de otro «Especial» después, en amarillo. Un temporal que no
     *   lleva el producto no cuenta.
     */
    public function test_T5_elAvisoCuentaLosTemporalesAbiertosDeCompras(): void
    {
        $enElPeriodo = $this->siembra->articulo('Pollo con temporal en el periodo');
        $enAmarillo = $this->siembra->articulo('Pollo con temporal después');
        $sinEl = $this->siembra->articulo('Pollo que no está en el temporal');
        $otro = $this->siembra->articulo('Otro producto del temporal');

        $this->temporalDeCompras($this->elegido, '2026-02-28', [$enElPeriodo, $otro]);
        $this->temporalDeCompras($this->otroEspecial, '2026-03-05', [$enAmarillo]);
        $this->temporalDeCompras($this->elegido, '2026-02-10', [$otro]);

        self::assertSame('amarillo', $this->aviso($enElPeriodo)['color']);
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

    /**
     * @comportamiento El producto que sale en la relación que cita el detalle de su línea es un
     *   derivado de otra aplicación: en un albarán o un temporal del mismo proveedor en el periodo
     *   sale amarillo, no rojo; de otro «Especial», o después del periodo, amarillo como hasta ahora.
     */
    public function test_T10_elDerivadoDeOtraAplicacionSaleAmarilloYNoBloquea(): void
    {
        $tipo = $this->escenario->tipoDeRelacion('Despiece del derivado', '1:N', 1);
        $casos = [
            'albarán en el periodo' => [$this->elegido, '2026-02-28', false],
            'temporal en el periodo' => [$this->elegido, '2026-02-28', true],
            'otro especial' => [$this->otroEspecial, '2026-02-15', false],
            'después' => [$this->elegido, '2026-03-10', false],
        ];

        foreach ($casos as $caso => [$proveedor, $fecha, $esTemporal]) {
            $origen = $this->siembra->articulo('Traseros del derivado: ' . $caso);
            $derivado = $this->siembra->articulo('Muslo del derivado: ' . $caso);
            $relacion = $this->escenario->relacionVigente($tipo, [['idArticulo' => $origen, 'sentido' => 'entrada'], ['idArticulo' => $derivado, 'sentido' => 'salida', 'cantidad' => 0.8]]);
            $etiqueta = ' · REL ' . $relacion['numRelacion'] . ' v1.0';
            $lineas = [[$origen, -10.0, 'Traseros' . $etiqueta], [$derivado, 8.0, 'Muslo' . $etiqueta]];
            $esTemporal ? $this->temporalConDetalle($proveedor, $fecha, $lineas) : $this->albaranConDetalle($proveedor, $fecha, $lineas);

            self::assertSame('amarillo', $this->aviso($derivado)['color'], $caso);
        }
    }

    /** @comportamiento El producto que entra en la relación que cita su línea se aplicó: amarillo, sin la marca de derivado. */
    public function test_T11_elProductoAplicadoPorLaRelacionCitadaSaleComoAplicado(): void
    {
        $tipo = $this->escenario->tipoDeRelacion('Despiece del aplicado', '1:N', 1);
        $aplicado = $this->siembra->articulo('Muslo aplicado');
        $pieza = $this->siembra->articulo('Jamoncito del aplicado');
        $relacion = $this->escenario->relacionVigente($tipo, [['idArticulo' => $aplicado, 'sentido' => 'entrada'], ['idArticulo' => $pieza, 'sentido' => 'salida', 'cantidad' => 0.9]]);
        $etiqueta = ' · REL ' . $relacion['numRelacion'] . ' v1.0';

        $this->albaranConDetalle($this->elegido, '2026-02-28', [[$aplicado, -10.0, 'Muslo' . $etiqueta], [$pieza, 9.0, 'Jamoncito' . $etiqueta]]);

        $aviso = $this->aviso($aplicado);
        self::assertSame('amarillo', $aviso['color']);
        self::assertArrayNotHasKey('derivado', $aviso['documentos'][0]);
    }

    /**
     * @comportamiento Una línea que no se puede atribuir a una aplicación cuenta como aplicada, sea
     *   cual sea su signo: sin la relación en el detalle, con la etiqueta mal formada, con una
     *   versión que no existe o con una relación que no lleva el producto. Sale amarillo, sin la
     *   marca de derivado.
     */
    public function test_T12_unaLineaQueNoSePuedeAtribuirCuentaComoAplicada(): void
    {
        $tipo = $this->escenario->tipoDeRelacion('Despiece ajeno', '1:N', 1);
        $a = $this->siembra->articulo('Entrada ajena');
        $b = $this->siembra->articulo('Salida ajena');
        $ajena = $this->escenario->relacionVigente($tipo, [['idArticulo' => $a, 'sentido' => 'entrada'], ['idArticulo' => $b, 'sentido' => 'salida', 'cantidad' => 0.5]]);
        $casos = [
            'sin relación' => 'Muslo',
            'mal formada' => 'Muslo · REL x vy',
            'versión que no existe' => 'Muslo · REL ' . $ajena['numRelacion'] . ' v9.9',
            'relación que no lo lleva' => 'Muslo · REL ' . $ajena['numRelacion'] . ' v1.0',
        ];

        foreach ($casos as $caso => $detalle) {
            $producto = $this->siembra->articulo('Muslo sin atribuir: ' . $caso);
            $this->albaranConDetalle($this->elegido, '2026-02-28', [[$producto, 8.0, $detalle]]);

            $aviso = $this->aviso($producto);
            self::assertSame('amarillo', $aviso['color'], $caso);
            self::assertArrayNotHasKey('derivado', $aviso['documentos'][0], $caso);
        }
    }

    /** @comportamiento Un albarán que lleva el producto aplicado y derivado cuenta como aplicado: basta una línea aplicada. */
    public function test_T13_aplicadoYDerivadoEnElMismoAlbaranCuentaComoAplicado(): void
    {
        $tipo = $this->escenario->tipoDeRelacion('Despiece de la cadena', '1:N', 1);
        $traseros = $this->siembra->articulo('Traseros de la cadena');
        $muslo = $this->siembra->articulo('Muslo de la cadena');
        $jamoncito = $this->siembra->articulo('Jamoncito de la cadena');
        $primera = $this->escenario->relacionVigente($tipo, [['idArticulo' => $traseros, 'sentido' => 'entrada'], ['idArticulo' => $muslo, 'sentido' => 'salida', 'cantidad' => 0.8]]);
        $segunda = $this->escenario->relacionVigente($tipo, [['idArticulo' => $muslo, 'sentido' => 'entrada'], ['idArticulo' => $jamoncito, 'sentido' => 'salida', 'cantidad' => 0.9]]);
        $de = fn(array $r): string => ' · REL ' . $r['numRelacion'] . ' v1.0';

        $this->albaranConDetalle($this->elegido, '2026-02-28', [
            [$traseros, -10.0, 'Traseros' . $de($primera)], [$muslo, 8.0, 'Muslo' . $de($primera)],
            [$muslo, -8.0, 'Muslo' . $de($segunda)], [$jamoncito, 7.2, 'Jamoncito' . $de($segunda)],
        ]);

        $aviso = $this->aviso($muslo);
        self::assertSame('amarillo', $aviso['color']);
        self::assertArrayNotHasKey('derivado', $aviso['documentos'][0]);
    }

    /**
     * @comportamiento El sentido se lee en la versión que cita la línea, aunque ya no rija: un
     *   producto que salía en la 1.0 y que la 1.1 ya no lleva sigue siendo un derivado.
     */
    public function test_T14_elSentidoSeLeeEnLaVersionCitadaAunqueYaNoRija(): void
    {
        $tipo = $this->escenario->tipoDeRelacion('Despiece que cambió', '1:N', 1);
        $origen = $this->siembra->articulo('Traseros que cambió');
        $derivado = $this->siembra->articulo('Muslo que ya no sale');
        $otra = $this->siembra->articulo('Contramuslo que sale ahora');
        $relacion = $this->escenario->relacionVigente($tipo, [['idArticulo' => $origen, 'sentido' => 'entrada'], ['idArticulo' => $derivado, 'sentido' => 'salida', 'cantidad' => 0.8]], ['fecha' => '2026-01-10 09:00:00']);
        $this->db->query("UPDATE relaciones SET estado = 'Sustituido' WHERE id = {$relacion['id']}");
        $this->escenario->versionDeRelacion($relacion['numRelacion'], $tipo, '2.0', 'Activo', '2026-02-20 09:00:00', [['idArticulo' => $origen, 'sentido' => 'entrada'], ['idArticulo' => $otra, 'sentido' => 'salida', 'cantidad' => 0.8]]);

        $this->albaranConDetalle($this->elegido, '2026-02-15', [[$origen, -10.0, 'Traseros · REL ' . $relacion['numRelacion'] . ' v1.0'], [$derivado, 8.0, 'Muslo · REL ' . $relacion['numRelacion'] . ' v1.0']]);

        self::assertSame('amarillo', $this->aviso($derivado)['color']);
    }

    /**
     * @comportamiento Lo disponible descuenta de cada aplicación guardada todo lo que consumió según
     *   su fórmula: lo despiezado entre el rendimiento de la versión que cita su línea, merma incluida.
     *   Una línea que no se puede atribuir saca solo su cantidad; un derivado entra como un movimiento
     *   más; una aplicación de otro mes no cuenta.
     */
    public function test_T15_loDisponibleDescuentaLaMermaDeLasAplicacionesGuardadas(): void
    {
        $etiqueta = $this->despieceConRendimiento('guardadas', $pollo);
        $deLaCanal = $this->derivadoDe($pollo, 'guardadas');
        $this->siembra->entradaProveedor($pollo, 200, '2026-02-03', ['idProveedor' => $this->habitual]);
        $this->albaranConDetalle($this->elegido, '2026-02-28', [[$pollo, -96.0, 'Pollo' . $etiqueta]]);
        $this->albaranConDetalle($this->otroEspecial, '2026-02-20', [[$pollo, -10.0, 'Pollo despiezado a mano']]);
        $this->albaranConDetalle($this->elegido, '2026-02-25', [[$pollo, 8.0, 'Pollo' . $deLaCanal]]);
        $this->albaranConDetalle($this->elegido, '2026-03-31', [[$pollo, -48.0, 'Pollo' . $etiqueta]]);

        $disponible = $this->disponible($pollo);

        self::assertSame(102.0, $disponible['existencias']);
        self::assertSame(4.0, $disponible['mermaAnterior']);
        self::assertSame(0.0, $disponible['enTemporales']);
        self::assertSame(98.0, $disponible['disponible']);
    }

    /**
     * @comportamiento Lo disponible descuenta lo que sacan del producto los temporales sin guardar de
     *   proveedores «Especial» del periodo: de cada línea en negativo, su cantidad, y entre el
     *   rendimiento si lleva el producto aplicado con la versión que cita. Las líneas en positivo y
     *   las eliminadas no cuentan, ni los temporales de otro mes o de un proveedor que no es «Especial».
     */
    public function test_T16_loDisponibleDescuentaLoQueSacanLosTemporalesSinGuardar(): void
    {
        $etiqueta = $this->despieceConRendimiento('temporales', $pollo);
        $deLaCanal = $this->derivadoDe($pollo, 'temporales');
        $this->siembra->entradaProveedor($pollo, 200, '2026-02-03', ['idProveedor' => $this->habitual]);
        $this->temporalConDetalle($this->elegido, '2026-02-28', [[$pollo, -48.0, 'Pollo' . $etiqueta], [$pollo, 5.0, 'Pollo a mano']]);
        $this->temporalConDetalle($this->otroEspecial, '2026-02-20', [[$pollo, -10.0, 'Pollo a mano'], [$pollo, 8.0, 'Pollo' . $deLaCanal]]);
        $conEliminada = $this->temporalConDetalle($this->otroEspecial, '2026-02-21', [[$pollo, -20.0, 'Pollo quitado']]);
        $this->eliminarLinea($conEliminada, 0);
        $this->temporalConDetalle($this->elegido, '2026-03-05', [[$pollo, -30.0, 'Pollo de marzo']]);
        $this->temporalConDetalle($this->habitual, '2026-02-25', [[$pollo, -30.0, 'Pollo de un habitual']]);

        $disponible = $this->disponible($pollo);

        self::assertSame(200.0, $disponible['existencias']);
        self::assertSame(0.0, $disponible['mermaAnterior']);
        self::assertSame(60.0, $disponible['enTemporales']);
        self::assertSame(140.0, $disponible['disponible']);
    }

    /**
     * @comportamiento Un albarán guardado que compras ha reabierto —«Sin Guardar», con su temporal—
     *   sigue sumando sus entradas, que pueden ser la primera del periodo, y su temporal descuenta lo
     *   que saca aunque no sea de un «Especial». Un albarán «Sin Guardar» sin temporal no cuenta.
     */
    public function test_T17_unAlbaranReabiertoSigueSumandoSusEntradasYSuTemporalDescuenta(): void
    {
        $pollo = $this->siembra->articulo('Pollo del reabierto');
        $reabierto = $this->siembra->entradaProveedor($pollo, 100, '2026-02-03', ['idProveedor' => $this->habitual, 'estado' => 'Sin Guardar']);
        $this->temporalEnlazado($this->habitual, '2026-02-03', $reabierto, [[$pollo, 100.0, 'Pollo'], [$pollo, -5.0, 'Pollo devuelto']]);
        $this->siembra->entradaProveedor($pollo, 100, '2026-02-17', ['idProveedor' => $this->habitual]);
        $this->siembra->entradaProveedor($pollo, 30, '2026-02-10', ['idProveedor' => $this->habitual, 'estado' => 'Sin Guardar']);

        $disponible = $this->disponible($pollo);

        self::assertSame(200.0, $disponible['existencias']);
        self::assertSame('2026-02-03', $disponible['primeraEntrada']);
        self::assertSame(5.0, $disponible['enTemporales']);
        self::assertSame(195.0, $disponible['disponible']);
    }

    /**
     * @comportamiento Lo guardado, los temporales y el albarán reabierto cuentan desde la primera
     *   entrada hasta el último día del periodo y, elegido el proveedor de traspaso, sin sus documentos
     *   del primer y el último día, como los movimientos.
     */
    public function test_T18_cuentanConLaMismaVentanaQueLosMovimientos(): void
    {
        $pollo = $this->siembra->articulo('Pollo de la ventana');
        $traspaso = $this->proveedor('Traspaso especial de la prueba', 'Especial');
        $this->siembra->entradaProveedor($pollo, 200, '2026-02-10', ['idProveedor' => $this->habitual]);
        $this->temporalConDetalle($this->elegido, '2026-02-05', [[$pollo, -20.0, 'Pollo antes de la entrada']]);
        $this->temporalConDetalle($traspaso, '2026-02-28', [[$pollo, -30.0, 'Pollo del cierre']]);

        $sinTraspaso = $this->disponible($pollo);
        self::assertSame(30.0, $sinTraspaso['enTemporales']);
        self::assertSame(170.0, $sinTraspaso['disponible']);

        $conTraspaso = $this->disponible($pollo, $traspaso);
        self::assertSame(0.0, $conTraspaso['enTemporales']);
        self::assertSame(200.0, $conTraspaso['disponible']);
    }

    /** @comportamiento Lo disponible solo lee: todas sus sentencias son consultas. */
    public function test_T19_loDisponibleSoloLee(): void
    {
        $etiqueta = $this->despieceConRendimiento('lecturas', $pollo);
        $this->siembra->entradaProveedor($pollo, 200, '2026-02-03', ['idProveedor' => $this->habitual]);
        $this->albaranConDetalle($this->elegido, '2026-02-28', [[$pollo, -96.0, 'Pollo' . $etiqueta]]);
        $this->temporalConDetalle($this->elegido, '2026-02-28', [[$pollo, -48.0, 'Pollo' . $etiqueta]]);
        $aplicacion = $this->aplicacionQueAnota();

        $aplicacion->loDisponible($pollo, '2026-02-01', '2026-02-28', 0, new \ClaseComprobacionStockConsulta());

        self::assertNotEmpty($aplicacion->sentencias);
        foreach ($aplicacion->sentencias as $sql) {
            self::assertMatchesRegularExpression('/^SELECT /', ltrim($sql));
        }
    }

    /**
     * @comportamiento Lo que queda de cada pieza al final del periodo cuenta lo que tenía al empezarlo
     *   —sus movimientos desde el 1 de enero—, sus líneas de proveedor del periodo con su signo —sus
     *   compras, lo que generó otra aplicación y lo que salió de ella al aplicarla en otra receta—, sus
     *   salidas por ticket y por albarán de cliente, y lo que genera la aplicación. Los tickets y los
     *   albaranes de cliente en estados que no son existencias no cuentan.
     */
    public function test_T20_loQueQuedaDeCadaPiezaCuentaSuSaldoInicialYSusMovimientos(): void
    {
        [$version, $pechuga, $muslo] = $this->versionDelDesajuste('movimientos');
        $this->siembra->entradaProveedor($pechuga, 20, '2026-01-10', ['idProveedor' => $this->habitual]);
        $this->siembra->ventaTicket($pechuga, 5, '2026-01-20');
        $this->siembra->entradaProveedor($pechuga, 12, '2026-02-05', ['idProveedor' => $this->otroEspecial]);
        $this->siembra->entradaProveedor($pechuga, -4, '2026-02-06', ['idProveedor' => $this->elegido]);
        $this->siembra->entradaProveedor($pechuga, 6, '2026-02-07', ['idProveedor' => $this->habitual]);
        $this->siembra->ventaTicket($pechuga, 7, '2026-02-10');
        $this->siembra->ventaTicket($pechuga, 100, '2026-02-15', ['estado' => 'Abierto']);
        $this->siembra->ventaAlbaranCliente($pechuga, 3, '2026-02-12');
        $this->siembra->ventaAlbaranCliente($pechuga, 100, '2026-02-16', ['estado' => 'Sin Guardar']);

        $desajuste = $this->desajuste($version, [$pechuga => 25.0, $muslo => 35.0], '2026-02-01', '2026-02-28', 0);

        $deLaPechuga = $desajuste['piezas'][0];
        self::assertSame([15.0, 14.0, 10.0, 25.0, 44.0], [$deLaPechuga['saldoInicial'], $deLaPechuga['entradas'], $deLaPechuga['salidas'], $deLaPechuga['genera'], $deLaPechuga['queda']]);
        self::assertSame(35.0, $desajuste['piezas'][1]['queda']);
    }

    /**
     * @comportamiento En enero, la apertura del ejercicio cuenta una vez se elija o no el proveedor de
     *   traspaso: sin él, en el periodo; con él, en el saldo inicial. En diciembre, elegido el proveedor
     *   de traspaso, el cierre no cuenta.
     */
    public function test_T21_laAperturaCuentaUnaVezYElCierreNoCuenta(): void
    {
        [$version, $pechuga, $muslo] = $this->versionDelDesajuste('ejercicio');
        $traspaso = $this->proveedor('Traspaso del desajuste', 'Activo');
        $this->siembra->entradaProveedor($pechuga, 30, '2026-01-01', ['idProveedor' => $traspaso]);
        $this->siembra->ventaTicket($pechuga, 10, '2026-01-15');
        $this->siembra->ventaTicket($pechuga, 8, '2026-12-20');
        $this->siembra->entradaProveedor($pechuga, -12, '2026-12-31', ['idProveedor' => $traspaso]);
        $genera = [$pechuga => 25.0, $muslo => 35.0];

        $sinTraspaso = $this->desajuste($version, $genera, '2026-01-01', '2026-01-31', 0)['piezas'][0];
        self::assertSame([0.0, 30.0, 45.0], [$sinTraspaso['saldoInicial'], $sinTraspaso['entradas'], $sinTraspaso['queda']]);
        $conTraspaso = $this->desajuste($version, $genera, '2026-01-01', '2026-01-31', $traspaso)['piezas'][0];
        self::assertSame([30.0, 0.0, 45.0], [$conTraspaso['saldoInicial'], $conTraspaso['entradas'], $conTraspaso['queda']]);

        $diciembre = $this->desajuste($version, $genera, '2026-12-01', '2026-12-31', $traspaso)['piezas'][0];
        self::assertSame([20.0, 0.0, 8.0, 37.0], [$diciembre['saldoInicial'], $diciembre['entradas'], $diciembre['salidas'], $diciembre['queda']]);
    }

    /**
     * Un albarán de proveedor guardado con una línea por elemento —producto, unidades y detalle—,
     * con el detalle que deja la preparación de una aplicación.
     *
     * @param list<array{0:int,1:float,2:string}> $lineas
     */
    private function albaranConDetalle(int $proveedor, string $fecha, array $lineas): int
    {
        [$primero, $unidades] = $lineas[0];
        $albaran = $this->siembra->entradaProveedor($primero, $unidades, $fecha, ['idProveedor' => $proveedor]);
        foreach (array_slice($lineas, 1) as [$producto, $cantidad]) {
            $this->siembra->lineaEntrada($albaran, $producto, $cantidad);
        }
        $ids = $this->db->query("SELECT id FROM albprolinea WHERE idalbpro = $albaran ORDER BY nfila, id")->fetch_all(MYSQLI_NUM);
        foreach ($lineas as $posicion => $linea) {
            $detalle = $this->db->real_escape_string($linea[2]);
            $this->db->query("UPDATE albprolinea SET cdetalle = '$detalle' WHERE id = {$ids[$posicion][0]}");
        }

        return $albaran;
    }

    /**
     * Un albarán temporal de compras con una línea activa por elemento —producto, unidades y detalle—.
     *
     * @param list<array{0:int,1:float,2:string}> $lineas
     */
    private function temporalConDetalle(int $proveedor, string $fecha, array $lineas): int
    {
        $productos = [];
        foreach ($lineas as $posicion => [$producto, $unidades, $detalle]) {
            $productos[] = ['idArticulo' => $producto, 'cdetalle' => $detalle, 'cref' => '', 'ccodbar' => '', 'ref_prov' => '', 'estado' => 'Activo',
                            'ncant' => $unidades, 'nunidades' => $unidades, 'ultimoCoste' => 1, 'importe' => $unidades, 'iva' => 10, 'nfila' => $posicion + 1];
        }
        $json = json_encode($productos);
        $alta = $this->db->prepare("INSERT INTO albproltemporales (idUsuario, idTienda, estadoAlbPro, Fecha, idProveedor, Productos, Pedidos, Su_numero) VALUES (?, ?, 'Nuevo', ?, ?, ?, '[]', '')");
        $usuario = $this->siembra->usuarioPorDefecto();
        $tienda = $this->siembra->tiendaPorDefecto();
        $alta->bind_param('iisis', $usuario, $tienda, $fecha, $proveedor, $json);
        $alta->execute();

        return (int) $this->db->insert_id;
    }

    /**
     * El temporal con que compras reabre un albarán guardado: enlazado a él y «Sin Guardar», con una
     * línea activa por elemento —producto, unidades y detalle—.
     *
     * @param list<array{0:int,1:float,2:string}> $lineas
     */
    private function temporalEnlazado(int $proveedor, string $fecha, int $albaran, array $lineas): int
    {
        $temporal = $this->temporalConDetalle($proveedor, $fecha, $lineas);
        $this->db->query("UPDATE albproltemporales SET estadoAlbPro = 'Sin Guardar', Numalbpro = $albaran WHERE id = $temporal");

        return $temporal;
    }

    /** Marca como eliminada la línea de un temporal que está en esa posición. */
    private function eliminarLinea(int $temporal, int $posicion): void
    {
        $productos = json_decode($this->db->query("SELECT Productos FROM albproltemporales WHERE id = $temporal")->fetch_assoc()['Productos'], true);
        $productos[$posicion]['estado'] = 'Eliminado';
        $json = $this->db->real_escape_string(json_encode($productos));
        $this->db->query("UPDATE albproltemporales SET Productos = '$json' WHERE id = $temporal");
    }

    /**
     * Un despiece del pollo que rinde el 96 %: de 1 kg salen 0,6 de pechuga y 0,36 de muslo. Deja en
     * $pollo el producto y devuelve la etiqueta que lleva el detalle de sus líneas.
     */
    private function despieceConRendimiento(string $caso, &$pollo): string
    {
        $pollo = $this->siembra->articulo('Pollo de lo disponible: ' . $caso);
        $pechuga = $this->siembra->articulo('Pechuga de lo disponible: ' . $caso);
        $muslo = $this->siembra->articulo('Muslo de lo disponible: ' . $caso);
        $relacion = $this->escenario->relacionVigente($this->escenario->tipoDeRelacion('Despiece de lo disponible: ' . $caso, '1:N', 1), [
            ['idArticulo' => $pollo, 'sentido' => 'entrada', 'cantidad' => 1],
            ['idArticulo' => $pechuga, 'sentido' => 'salida', 'cantidad' => 0.6],
            ['idArticulo' => $muslo, 'sentido' => 'salida', 'cantidad' => 0.36],
        ]);

        return ' · REL ' . $relacion['numRelacion'] . ' v1.0';
    }

    /** Una relación de la que sale el producto; devuelve la etiqueta de sus líneas. */
    private function derivadoDe(int $producto, string $caso): string
    {
        $canal = $this->siembra->articulo('Canal de lo disponible: ' . $caso);
        $relacion = $this->escenario->relacionVigente($this->escenario->tipoDeRelacion('Despiece de la canal: ' . $caso, '1:N', 1), [
            ['idArticulo' => $canal, 'sentido' => 'entrada', 'cantidad' => 1],
            ['idArticulo' => $producto, 'sentido' => 'salida', 'cantidad' => 0.8],
        ]);

        return ' · REL ' . $relacion['numRelacion'] . ' v1.0';
    }

    /**
     * La versión de un despiece del pollo, como la devuelve la lectura de relaciones: de 1 kg salen
     * 0,25 de pechuga y 0,35 de muslo. Devuelve la versión, la pechuga y el muslo.
     *
     * @return array{0:array<string,mixed>,1:int,2:int}
     */
    private function versionDelDesajuste(string $caso): array
    {
        $lineas = [];
        foreach (['entrada' => ['Pollo', 1.0], 'pechuga' => ['Pechuga', 0.25], 'muslo' => ['Muslo', 0.35]] as $clave => [$nombre, $cantidad]) {
            $id = $this->siembra->articulo($nombre . ' del desajuste: ' . $caso);
            $lineas[$clave] = ['idArticulo' => $id, 'articulo' => $nombre . ' del desajuste: ' . $caso, 'sentido' => $clave === 'entrada' ? 'entrada' : 'salida',
                               'cantidad' => $cantidad, 'unidad' => 'kg', 'estado' => 'Activo'];
        }

        return [['forma' => '1:N', 'lineas' => array_values($lineas)], $lineas['pechuga']['idArticulo'], $lineas['muslo']['idArticulo']];
    }

    /**
     * Lo que queda de cada pieza con lo que genera la aplicación, por idArticulo.
     *
     * @param array<int,float> $genera
     * @return array{piezas:list<array<string,mixed>>,minimo:float}
     */
    private function desajuste(array $version, array $genera, string $desde, string $hasta, int $traspaso): array
    {
        $piezas = [];
        foreach ($genera as $idArticulo => $cantidad) {
            $piezas[] = ['idArticulo' => $idArticulo, 'cantidad' => $cantidad];
        }
        $desajuste = $this->aplicacion()->desajusteDeLasPiezas($version, ['piezas' => $piezas], $desde, $hasta, $traspaso, new \ClaseComprobacionStockConsulta());
        self::assertArrayHasKey('datos', $desajuste, json_encode($desajuste['error'] ?? null, JSON_UNESCAPED_UNICODE));

        return $desajuste['datos'];
    }

    /** @return array{existencias:float,primeraEntrada:?string,mermaAnterior:float,enTemporales:float,disponible:float} */
    private function disponible(int $producto, int $traspaso = 0): array
    {
        $disponible = $this->aplicacion()->loDisponible($producto, '2026-02-01', '2026-02-28', $traspaso, new \ClaseComprobacionStockConsulta());
        self::assertArrayHasKey('datos', $disponible, json_encode($disponible['error'] ?? null, JSON_UNESCAPED_UNICODE));

        return $disponible['datos'];
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
