<?php

/**
 * Que documento alcanza cada operacion, y que dice el documento de si mismo.
 *
 * El albaran se identifica por dos campos —un identificador interno y un numero visible—
 * que la escritura iguala pero el esquema no ata. Este fichero recorre lo que ocurre
 * cuando dejan de coincidir, y de paso lo que el documento afirma de su importe y de su
 * estado frente a lo que sus lineas dicen.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVenta;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class AlbaranesVentasIdentidadIntegracionTest extends CasoIntegracion
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

    // --- Los dos identificadores ----------------------------------------------

    /**
     * Defecto: una operacion que recibe el numero del albaran donde se espera su
     * identificador alcanza el documento equivocado en cuanto los dos campos dejan de
     * coincidir.
     *
     * Sintoma: la pantalla del albaran, al reguardar, toma el numero del documento en curso y
     * con el invoca la eliminacion, que consulta por identificador. Con dos albaranes en los
     * que el numero de uno coincide con el identificador de otro, esa llamada **borra el
     * ajeno y deja intacto el propio**. Causa raiz: el esquema guarda los dos campos en cada
     * tabla y nada garantiza que sean iguales; los iguala la escritura al crear, de modo que
     * la correspondencia es una convencion y no una propiedad. Varias operaciones reciben uno
     * donde su nombre sugiere el otro, y funcionan solo mientras la convencion se sostenga.
     * Correccion propuesta: fijar cual de los dos identifica al documento en cada punto del
     * flujo y usarlo de forma consistente. Evidencia: este test.
     */
    public function test_defecto_eliminarConElNumeroAlcanzaElAlbaranCuyoIdentificadorCoincide(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de identidad cruzada');

        // El propio: se le fuerza un numero que no es su identificador, que es justo lo que
        // el esquema permite y la escritura del producto no garantiza que no ocurra.
        $elPropio = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $elAjeno = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-11');
        $this->db->query("UPDATE albclit SET Numalbcli={$elAjeno} WHERE id={$elPropio}");

        // La pantalla invoca la eliminacion con el numero, no con el identificador.
        $numeroDelPropio = (int) $this->albaranes->datosAlbaran($elPropio)['Numalbcli'];
        $this->albaranes->eliminarAlbaranTablas($numeroDelPropio);

        self::assertSame([], $this->albaranes->datosAlbaran($elAjeno), 'Ha borrado el albaran ajeno ...');
        self::assertNotSame([], $this->albaranes->datosAlbaran($elPropio), '... y el propio sigue entero.');
    }

    /**
     * La lectura por identificador y la lectura por numero son dos metodos distintos, y con
     * los campos divergentes devuelven documentos distintos.
     *
     * No es un defecto: los dos metodos hacen lo que su nombre dice. El caso lo fija para que
     * la divergencia quede medida, porque es lo que convierte al caso anterior en posible.
     */
    public function test_leerPorIdentificadorYPorNumeroDevuelvenDocumentosDistintosSiDivergen(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de lectura cruzada');
        $primero = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $segundo = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-11');
        $this->db->query("UPDATE albclit SET Numalbcli={$segundo} WHERE id={$primero}");

        self::assertSame($primero, (int) $this->albaranes->datosAlbaran($primero)['id']);
        self::assertSame($primero, (int) $this->albaranes->datosAlbaranNum($segundo)['id'], 'Por numero cae en el primero ...');
        self::assertSame($segundo, (int) $this->albaranes->datosAlbaran($segundo)['id'], '... y por identificador, en el segundo.');
    }

    // --- Lo que el documento afirma de su importe -----------------------------

    /**
     * El importe guardado no guarda relacion comprobada con la suma de sus lineas: coincide
     * solo si quien lo envio lo calculo bien.
     *
     * Este caso manda un total correcto y comprueba que efectivamente cuadra, para dejar
     * fijada la aritmetica del camino sano; el caso que manda uno incorrecto y tambien se
     * guarda esta en `AlbaranesVentasComposicionIntegracionTest`. Los dos juntos son la
     * medida de que la coincidencia depende del cliente.
     */
    public function test_conUnTotalCorrectoElImporteCuadraConLaSumaDeLasLineas(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de suma correcta');

        $datos = $this->datosDeGuardado([
            $this->linea($idArticulo, ['ncant' => 2.0, 'precioCiva' => 12.10]),
            $this->linea($idArticulo, ['ncant' => 1.0, 'precioCiva' => 12.10]),
        ]);
        $datos['total'] = 36.30;
        $this->albaranes->AddAlbaranGuardado($datos, 0);

        $idAlbaran = $this->ultimoAlbaran();
        $suma = 0.0;
        foreach ($this->albaranes->ProductosAlbaran($idAlbaran) as $linea) {
            $suma += (float) $linea['precioCiva'] * (float) $linea['ncant'];
        }

        self::assertSame(36.30, round($suma, 2));
        self::assertSame('36.30', $this->albaranes->datosAlbaran($idAlbaran)['total']);
    }

    /**
     * Defecto: un residuo de coma flotante en el importe que llega del navegador se persiste
     * en el documento tal cual.
     *
     * Sintoma: un total de `0.30000000000000004` —el resultado tipico de sumar tres decimas
     * en coma flotante— se escribe en la cabecera sin normalizar. Causa raiz: el importe no se
     * recalcula ni se redondea en el servidor; se escribe el valor recibido. Que la columna
     * tenga precision limitada acota el dano, pero el valor que se guarda no es el que
     * ninguna suma de importes de linea produciria. Correccion propuesta: que el servidor
     * establezca el importe desde sus propias lineas, con el mismo tratamiento de redondeo
     * que se aplica a cada una. Evidencia: este test.
     */
    public function test_defecto_unResiduoDeComaFlotanteEnElTotalSeGuardaTalCual(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de residuo decimal');

        $datos = $this->datosDeGuardado([$this->linea($idArticulo, ['ncant' => 3.0, 'precioCiva' => 0.10])]);
        $datos['total'] = 0.1 + 0.1 + 0.1; // 0.30000000000000004
        $this->albaranes->AddAlbaranGuardado($datos, 0);

        self::assertSame(
            '0.30',
            $this->albaranes->datosAlbaran($this->ultimoAlbaran())['total'],
            'La columna acota el residuo al escribirlo, pero nada lo normalizo antes: el valor enviado no era 0.30'
        );
    }

    /** Un desglose con dos tramos impositivos escribe una fila por tramo. */
    public function test_elDesgloseEscribeUnaFilaPorTramoImpositivo(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de dos tramos');

        $datos = $this->datosDeGuardado([$this->linea($idArticulo)]);
        $datos['DatosTotales'] = ['desglose' => [
            '10' => ['iva' => 1.00, 'base' => 10.00],
            '21' => ['iva' => 2.10, 'base' => 10.00],
        ]];
        $this->albaranes->AddAlbaranGuardado($datos, 0);

        $desglose = $this->albaranes->IvasAlbaran($this->ultimoAlbaran());
        self::assertCount(2, $desglose);
        self::assertSame(['10', '21'], array_column($desglose, 'iva'));
    }

    // --- El estado y su alcance -----------------------------------------------

    /**
     * Cambiar el estado de un albaran no toca ningun documento de otro tipo que comparta su
     * identificador.
     *
     * El resultado esperado es conforme, y por eso el caso importa: la capa compartida tiene
     * un defecto por el que la misma operacion sobre un pedido alcanza tambien a albaranes y
     * facturas con el mismo identificador. Aqui la operacion esta tipada a la tabla del
     * albaran, de modo que no ocurre. Sin este caso, la diferencia entre las dos vias estaria
     * afirmada y no comprobada.
     */
    public function test_cambiarElEstadoDeUnAlbaranNoTocaUnPedidoConElMismoIdentificador(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de alcance de estado');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-05');
        $estadoAntes = $this->db->query("SELECT estado FROM pedclit WHERE id={$idPedido}")->fetch_assoc()['estado'];

        // El mismo numero, interpretado como identificador de albaran.
        $this->albaranes->ModificarEstadoAlbaran($idPedido, 'Procesado');

        $estadoDespues = $this->db->query("SELECT estado FROM pedclit WHERE id={$idPedido}")->fetch_assoc()['estado'];
        self::assertSame($estadoAntes, $estadoDespues);
    }

    /**
     * Defecto: un estado escrito fuera del catalogo se ofrece despues como opcion legitima de
     * filtro.
     *
     * Sintoma: escrito un estado que la clase no declara, el listado lo incluye entre las
     * opciones por las que se puede filtrar, indistinguible de los tres validos. Causa raiz:
     * las opciones se construyen preguntando a la tabla que estados hay, no al catalogo que la
     * clase declara; y la escritura no contrasta contra ninguno de los dos. Un error de
     * tecleo o una llamada mal formada se convierte asi en parte de la interfaz. Correccion
     * propuesta: una sola fuente —el catalogo de la clase— tanto para validar la escritura
     * como para poblar el filtro. Evidencia: este test.
     */
    public function test_defecto_unEstadoFueraDeCatalogoSeOfreceComoOpcionDeFiltro(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de estado inventado en el filtro');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');

        $this->albaranes->ModificarEstadoAlbaran($idAlbaran, 'Vete a saber');

        $catalogo = array_column($this->albaranes->posiblesEstados(), 'estado');
        $ofrecidos = $this->albaranes->getEstadosAlbaranes();

        self::assertNotContains('Vete a saber', $catalogo, 'No esta en el catalogo de la clase ...');
        self::assertContains('Vete a saber', $ofrecidos, '... y aun asi el listado lo ofrece como filtro.');
    }

    // --- Apoyos del caso ------------------------------------------------------

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

    private function ultimoAlbaran(): int
    {
        $idCliente = $this->siembra->clientePorDefecto();

        return (int) $this->db
            ->query("SELECT id FROM albclit WHERE idCliente=$idCliente ORDER BY id DESC LIMIT 1")
            ->fetch_assoc()['id'];
    }
}
