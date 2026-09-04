<?php

/**
 * Que queda escrito cuando un albaran se compone: importes, cantidades, tipos de iva y el
 * estado de los documentos de los que sale.
 *
 * A diferencia de los otros ficheros de esta clase, que recorren metodo a metodo, este
 * persigue preguntas sobre el documento completo —cuadra el total con sus lineas?, que pasa
 * con el mismo producto dos veces?, que viaja al importar un pedido?— cuya respuesta no se
 * ve mirando ningun metodo por separado.
 *
 * La conexion se comparte con el producto porque varios casos observan el inventario, que
 * mueve una clase de `mod_producto`.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVenta;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class AlbaranesVentasComposicionIntegracionTest extends CasoIntegracion
{
    protected bool $compartirConexionConElProducto = true;

    private \AlbaranesVentas $albaranes;
    private Siembra $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_venta/clases/albaranesVentas.php');
        $this->albaranes = new \AlbaranesVentas($this->db);
        $this->siembra = new Siembra($this->db);
    }

    // --- El total frente a sus lineas -----------------------------------------

    /**
     * El total de la cabecera no se calcula al guardar: llega ya hecho desde el navegador y
     * se escribe tal cual. Este caso lo fija: el guardado copia lo que le den, sin
     * contrastarlo con las lineas que el mismo acaba de escribir.
     */
    public function test_elTotalDeLaCabeceraEsElQueLlegaDelCliente_noSeCalculaDeLasLineas(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de total declarado');

        $datos = $this->datosDeGuardado([$this->linea($idArticulo, ['precioCiva' => 12.10])]);
        $datos['total'] = 999.99; // Un total que no corresponde con la unica linea.
        $this->albaranes->AddAlbaranGuardado($datos, 0);

        $idAlbaran = $this->ultimoAlbaran();
        self::assertSame('999.99', $this->albaranes->datosAlbaran($idAlbaran)['total']);
        self::assertSame('12.10', $this->albaranes->ProductosAlbaran($idAlbaran)[0]['precioCiva']);
    }

    /**
     * Lo mismo con el desglose de ivas: se escribe desde los datos que vienen montados del
     * cliente, no de las lineas recien insertadas. Cabecera, lineas y desglose son tres
     * verdades independientes dentro del mismo documento.
     */
    public function test_elDesgloseDeIvasEsElQueLlegaDelCliente_noSeCalculaDeLasLineas(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de desglose declarado');

        $datos = $this->datosDeGuardado([$this->linea($idArticulo, ['iva' => 21])]);
        $datos['DatosTotales'] = ['desglose' => ['4' => ['iva' => 1.00, 'base' => 25.00]]];
        $this->albaranes->AddAlbaranGuardado($datos, 0);

        $idAlbaran = $this->ultimoAlbaran();
        $desglose = $this->albaranes->IvasAlbaran($idAlbaran);
        self::assertCount(1, $desglose);
        self::assertSame('4', $desglose[0]['iva'], 'El desglose dice 4 % ...');
        self::assertSame('21.00', $this->albaranes->ProductosAlbaran($idAlbaran)[0]['iva'], '... y la unica linea, 21 %.');
    }

    // --- El mismo producto dos veces ------------------------------------------

    /** Dos lineas del mismo articulo se escriben como dos, con su numero de fila propio. */
    public function test_elMismoProductoDosVecesSeEscribeComoDosLineas(): void
    {
        $idArticulo = $this->siembra->articulo('Producto repetido');

        $this->albaranes->AddAlbaranGuardado($this->datosDeGuardado([
            $this->linea($idArticulo, ['ncant' => 2.0]),
            $this->linea($idArticulo, ['ncant' => 3.0]),
        ]), 0);

        $lineas = $this->albaranes->ProductosAlbaran($this->ultimoAlbaran());
        self::assertCount(2, $lineas);
        self::assertSame(['1', '2'], array_column($lineas, 'nfila'));
    }

    /** Y el inventario se mueve una vez por linea, de modo que el articulo se descuenta dos veces. */
    public function test_elMismoProductoDosVecesDescuentaExistenciasDosVeces(): void
    {
        $idArticulo = $this->siembra->articulo('Producto repetido con stock');
        $this->siembra->existenciaRegistrada($idArticulo, 10.0);

        $this->albaranes->AddAlbaranGuardado($this->datosDeGuardado([
            $this->linea($idArticulo, ['ncant' => 2.0]),
            $this->linea($idArticulo, ['ncant' => 3.0]),
        ]), 0);

        self::assertSame(5.0, $this->existenciasDe($idArticulo));
    }

    // --- Guardar un albaran vacio o a cero ------------------------------------

    /**
     * Un albaran sin ninguna linea se guarda igual: queda la cabecera, con su numero y su
     * total, y ninguna linea. No hay comprobacion de que un documento de venta tenga algo
     * que vender.
     */
    public function test_guardarSinNingunaLineaCreaLaCabeceraIgualmente(): void
    {
        $datos = $this->datosDeGuardado([]);
        $datos['total'] = 0;
        $datos['DatosTotales'] = ['desglose' => []];

        $this->albaranes->AddAlbaranGuardado($datos, 0);

        $idAlbaran = $this->ultimoAlbaran();
        self::assertSame('0.00', $this->albaranes->datosAlbaran($idAlbaran)['total']);
        self::assertSame([], $this->albaranes->ProductosAlbaran($idAlbaran));
    }

    /**
     * Y una linea con todo a cero tambien: cantidad, precio e importe a cero se escriben como
     * una linea mas, y el movimiento de existencias de cero se ejecuta igual.
     */
    public function test_guardarUnaLineaConTodoACeroLaEscribeYMueveCeroExistencias(): void
    {
        $idArticulo = $this->siembra->articulo('Producto a cero');
        $this->siembra->existenciaRegistrada($idArticulo, 10.0);

        $datos = $this->datosDeGuardado([
            $this->linea($idArticulo, ['ncant' => 0.0, 'nunidades' => 0.0, 'precioCiva' => 0.0, 'pvpSiva' => 0.0]),
        ]);
        $datos['total'] = 0;
        $this->albaranes->AddAlbaranGuardado($datos, 0);

        $idAlbaran = $this->ultimoAlbaran();
        self::assertCount(1, $this->albaranes->ProductosAlbaran($idAlbaran));
        self::assertSame(10.0, $this->existenciasDe($idArticulo));
    }

    // --- Cantidades: ncant frente a nunidades ---------------------------------

    /**
     * La linea lleva dos cantidades y no son intercambiables: `ncant` es la que mueve
     * existencias, `nunidades` solo se guarda. Este caso las separa a proposito para dejar
     * fijado cual manda sobre el inventario.
     */
    public function test_lasExistenciasSeMuevenConNcant_noConNunidades(): void
    {
        $idArticulo = $this->siembra->articulo('Producto con dos cantidades');
        $this->siembra->existenciaRegistrada($idArticulo, 100.0);

        $this->albaranes->AddAlbaranGuardado($this->datosDeGuardado([
            $this->linea($idArticulo, ['ncant' => 7.0, 'nunidades' => 1.0]),
        ]), 0);

        $linea = $this->albaranes->ProductosAlbaran($this->ultimoAlbaran())[0];
        self::assertSame('7.000000', $linea['ncant']);
        self::assertSame('1.000000', $linea['nunidades']);
        self::assertSame(93.0, $this->existenciasDe($idArticulo), 'Se descuentan 7, no 1.');
    }

    /**
     * Al importar un pedido a un albaran, sus lineas se copian tal cual: las dos cantidades
     * viajan con el valor que traian del pedido, sin conversion ni comprobacion de unidad.
     * Nada en el trayecto sabe si el pedido contaba cajas y el albaran cuenta kilos.
     */
    public function test_importarUnPedidoCopiaSusCantidadesSinConvertirlas(): void
    {
        $idArticulo = $this->siembra->articulo('Producto que viene de un pedido');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-05');
        $this->db->query("UPDATE pedclilinea SET ncant=12, nunidades=1 WHERE idpedcli={$idPedido}");

        $lineaDelPedido = $this->db
            ->query("SELECT ncant, nunidades, idArticulo, cdetalle FROM pedclilinea WHERE idpedcli={$idPedido}")
            ->fetch_assoc();

        // Tal como `BuscarAdjunto.php` las trae y el navegador las reenvia al guardar.
        $this->albaranes->AddAlbaranGuardado($this->datosDeGuardado([
            $this->linea((int) $lineaDelPedido['idArticulo'], [
                'ncant'     => (float) $lineaDelPedido['ncant'],
                'nunidades' => (float) $lineaDelPedido['nunidades'],
                'cdetalle'  => $lineaDelPedido['cdetalle'],
            ]),
        ]), 0);

        $lineaDelAlbaran = $this->albaranes->ProductosAlbaran($this->ultimoAlbaran())[0];
        self::assertSame('12.000000', $lineaDelAlbaran['ncant']);
        self::assertSame('1.000000', $lineaDelAlbaran['nunidades']);
    }

    // --- El estado del documento de origen ------------------------------------

    /**
     * Importar un pedido a un albaran guardado deja el pedido **en el mismo estado en que
     * estaba**: el guardado escribe el enlace y no toca la tabla de pedidos.
     *
     * Es la respuesta a si los estados heredados se actualizan: por esta via, no. El pedido
     * sigue figurando como 'Guardado', que es el estado por el que se ofrece como adjunto
     * disponible, de modo que nada impide adjuntarlo otra vez a un segundo albaran. Si algun
     * otro punto del sistema lo marca, no es este.
     */
    public function test_importarUnPedidoNoCambiaElEstadoDelPedido(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de pedido importado');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-05');
        $numeroPedido = $this->numeroDePedido($idPedido);

        $datos = $this->datosDeGuardado([$this->linea($idArticulo)]);
        $datos['pedidos'] = json_encode([
            ['id' => $idPedido, 'NumAdjunto' => $numeroPedido, 'estado' => 'Activo'],
        ]);
        $this->albaranes->AddAlbaranGuardado($datos, 0);

        self::assertSame('Guardado', $this->estadoDelPedido($idPedido));
    }

    /** Y el enlace si queda escrito, de modo que la relacion existe aunque el estado no la refleje. */
    public function test_importarUnPedidoSiEscribeElEnlaceConElAlbaran(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de enlace de pedido');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-05');

        $datos = $this->datosDeGuardado([$this->linea($idArticulo)]);
        $datos['pedidos'] = json_encode([
            ['id' => $idPedido, 'NumAdjunto' => $this->numeroDePedido($idPedido), 'estado' => 'Activo'],
        ]);
        $this->albaranes->AddAlbaranGuardado($datos, 0);

        $pedidos = $this->albaranes->obtenerPedidosAlbaran($this->ultimoAlbaran());
        self::assertCount(1, $pedidos['Items']);
    }

    /**
     * Defecto: el mismo pedido se puede servir en dos albaranes distintos, y los dos
     * descuentan existencias.
     *
     * Sintoma: incorporado un pedido a un albaran y guardado este, el pedido sigue en
     * 'Guardado' —el estado por el que se ofrece como adjunto disponible— y nada impide
     * incorporarlo a un segundo albaran. Los dos quedan enlazados con el mismo pedido, los
     * dos llevan su mercancia y los dos la descuentan del inventario. Causa raiz: el guardado
     * escribe el enlace y no toca el documento de origen, de modo que ningun estado registra
     * que ese pedido ya se sirvio; es la consecuencia de lo que el caso anterior demuestra
     * sobre la causa. Correccion propuesta: que el documento de origen deje de estar
     * disponible en la misma transaccion que lo consume; la escritura de ese estado toca la
     * clase de pedidos, de su propio componente. Evidencia: este test.
     */
    public function test_defecto_elMismoPedidoSePuedeServirEnDosAlbaranes(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de pedido servido dos veces');
        $this->siembra->existenciaRegistrada($idArticulo, 10.0);
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-05');

        $datos = $this->datosDeGuardado([$this->linea($idArticulo, ['ncant' => 2.0])]);
        $datos['pedidos'] = json_encode([
            ['id' => $idPedido, 'NumAdjunto' => $this->numeroDePedido($idPedido), 'estado' => 'Activo'],
        ]);

        $this->albaranes->AddAlbaranGuardado($datos, 0);
        $primero = $this->ultimoAlbaran();
        $this->albaranes->AddAlbaranGuardado($datos, 0);
        $segundo = $this->ultimoAlbaran();

        self::assertNotSame($primero, $segundo, 'Son dos albaranes distintos ...');
        self::assertCount(1, $this->albaranes->obtenerPedidosAlbaran($primero)['Items'], '... los dos enlazan el mismo pedido ...');
        self::assertCount(1, $this->albaranes->obtenerPedidosAlbaran($segundo)['Items']);
        self::assertSame('Guardado', $this->estadoDelPedido($idPedido), '... que sigue disponible para un tercero ...');
        self::assertSame(6.0, $this->existenciasDe($idArticulo), '... y la mercancia salio dos veces del inventario.');
    }

    // --- La referencia de la linea a su pedido --------------------------------

    /**
     * La linea que no procede de ningun pedido se guarda con el valor cero en la misma
     * columna que lleva las referencias reales.
     *
     * No es un defecto por si mismo: es la condicion que hace que «sin pedido» y «del pedido
     * numero tal» compartan dominio de valores, y con ella que una comparacion no pueda
     * distinguirlos. El caso la fija para que se lea junto al de la linea incorporada.
     */
    public function test_laLineaAnadidaDirectamenteSeGuardaConReferenciaCero(): void
    {
        $idArticulo = $this->siembra->articulo('Producto anadido a mano');

        $this->albaranes->AddAlbaranGuardado($this->datosDeGuardado([$this->linea($idArticulo)]), 0);

        self::assertSame('0', $this->albaranes->ProductosAlbaran($this->ultimoAlbaran())[0]['NumpedCli']);
    }

    /**
     * Hallazgo de contrato: una linea leida de la base no lleva la grafia que el navegador
     * consulta.
     *
     * El navegador decide que lineas retirar, cuando se retira un pedido, comparando contra
     * `Numpedcli` —con minuscula—, mientras que lo que la base devuelve es `NumpedCli`. Este
     * caso fija la asimetria en el punto donde nace: lo que `ProductosAlbaran()` entrega
     * lleva la grafia de la columna y no la otra, de modo que la comparacion del navegador
     * sobre esas lineas no encuentra valor que comparar. La consecuencia en pantalla —que las
     * lineas del pedido retirado se queden activas— pertenece al recorrido de navegador y a
     * la capa compartida, cuya correccion cruza de componente.
     */
    public function test_lasLineasLeidasDeLaBaseLlevanSoloLaGrafiaDeLaColumna(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de grafia leida');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');

        $linea = $this->albaranes->ProductosAlbaran($idAlbaran)[0];

        self::assertArrayHasKey('NumpedCli', $linea, 'La grafia de la columna, presente ...');
        self::assertArrayNotHasKey('Numpedcli', $linea, '... y la que el navegador consulta, ausente.');
    }

    // --- El catalogo de tipos de iva ------------------------------------------

    /**
     * El catalogo de tipos de iva que mantiene `mod_configuracion` (tablas principales) no
     * restringe nada en la venta: una linea de albaran puede llevar un tipo que no figura en
     * el y se escribe sin objecion. El modulo de venta no consulta esa tabla en ningun punto
     * —el tipo viaja copiado desde el articulo—, y no hay clave ajena que lo ate.
     */
    public function test_unaLineaAdmiteUnTipoDeIvaQueNoEstaEnElCatalogo(): void
    {
        $this->siembra->catalogoDeIvas(); // 4, 10 y 21
        $idArticulo = $this->siembra->articulo('Producto con iva inventado');

        $this->albaranes->AddAlbaranGuardado($this->datosDeGuardado([
            $this->linea($idArticulo, ['iva' => 17]),
        ]), 0);

        $tiposDelCatalogo = array_column(
            $this->db->query('SELECT iva FROM iva')->fetch_all(MYSQLI_ASSOC),
            'iva'
        );
        self::assertNotContains('17.00', $tiposDelCatalogo, 'El 17 % no esta en el catalogo ...');
        self::assertSame('17.00', $this->albaranes->ProductosAlbaran($this->ultimoAlbaran())[0]['iva'], '... y aun asi queda escrito.');
    }

    // --- Vida del documento en curso ------------------------------------------

    /**
     * Un documento en curso puede quedar apuntando a un albaran que ya no existe: borrar el
     * albaran no toca sus temporales, y el huerfano sigue apareciendo en el listado de
     * «Albaranes Abiertos» con un numero que ya no corresponde a nada.
     */
    public function test_borrarUnAlbaranDejaVivosSusTemporales(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de temporal huerfano');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $idTemporal = $this->siembra->albaranTemporal([], [], ['Numalbcli' => $idAlbaran]);

        $this->albaranes->eliminarAlbaranTablas($idAlbaran);

        self::assertSame([], $this->albaranes->datosAlbaran($idAlbaran), 'El albaran ya no existe ...');
        self::assertNotSame([], $this->albaranes->buscarDatosTemporal($idTemporal), '... y su temporal sigue vivo.');
    }

    /**
     * Nada impide que un mismo albaran tenga varios documentos en curso a la vez. La pantalla
     * tiene un aviso preparado para ese caso, lo que indica que ocurre; pero la escritura no
     * lo evita.
     */
    public function test_unAlbaranAdmiteVariosTemporalesALaVez(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de varios temporales');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $this->siembra->albaranTemporal([], [], ['Numalbcli' => $idAlbaran]);
        $this->siembra->albaranTemporal([], [], ['Numalbcli' => $idAlbaran]);

        self::assertCount(2, $this->albaranes->TodosTemporal($idAlbaran));
    }

    // --- Apoyos del caso ------------------------------------------------------

    /** Los datos de guardado con la forma exacta que `albaran.php` monta al pulsar Guardar. */
    private function datosDeGuardado(array $productos): array
    {
        return [
            'Numtemp_albcli' => 0,
            'Fecha'          => '2026-02-15',
            'idTienda'       => $this->siembra->tiendaPorDefecto(),
            'idUsuario'      => $this->siembra->usuarioPorDefecto(),
            'idCliente'      => $this->siembra->clientePorDefecto(),
            'estado'         => 'Guardado',
            'total'          => 12.10,
            'DatosTotales'   => ['desglose' => ['21' => ['iva' => 2.10, 'base' => 10.00]]],
            'productos'      => json_encode($productos),
            'pedidos'        => json_encode([]),
        ];
    }

    /** Una linea de producto tal como el navegador la envia dentro de `productos`. */
    private function linea(int $idArticulo, array $extra = []): array
    {
        return array_merge([
            'idArticulo'  => $idArticulo,
            'cref'        => 'REF' . $idArticulo,
            'ccodbar'     => '',
            'cdetalle'    => 'Linea de prueba',
            'ncant'       => 1.0,
            'nunidades'   => 1.0,
            'precioCiva'  => 12.10,
            'iva'         => 21,
            'pvpSiva'     => 10.00,
            'estadoLinea' => 'Activo',
        ], $extra);
    }

    /** El id de la ultima cabecera escrita para el cliente por defecto. */
    private function ultimoAlbaran(): int
    {
        $idCliente = $this->siembra->clientePorDefecto();

        return (int) $this->db
            ->query("SELECT id FROM albclit WHERE idCliente=$idCliente ORDER BY id DESC LIMIT 1")
            ->fetch_assoc()['id'];
    }

    /** Las existencias registradas del producto en la tienda por defecto. */
    private function existenciasDe(int $idArticulo): float
    {
        $idTienda = $this->siembra->tiendaPorDefecto();

        return (float) $this->db
            ->query("SELECT stockOn FROM articulosStocks WHERE idArticulo=$idArticulo AND idTienda=$idTienda")
            ->fetch_assoc()['stockOn'];
    }

    private function numeroDePedido(int $idPedido): int
    {
        return (int) $this->db
            ->query("SELECT Numpedcli FROM pedclit WHERE id={$idPedido}")
            ->fetch_assoc()['Numpedcli'];
    }

    private function estadoDelPedido(int $idPedido): string
    {
        return (string) $this->db
            ->query("SELECT estado FROM pedclit WHERE id={$idPedido}")
            ->fetch_assoc()['estado'];
    }
}
