<?php

/**
 * El borrador del pedido: `pedcliltemporales`, donde vive lo que el operador esta
 * componiendo antes de darlo por bueno.
 *
 * El borrador se crea desde `tareas/AddTemporal.php`, que es despacho compartido, y se
 * actualiza en cada cambio de linea. Lo que se comprueba aqui es que guarda, que ignora y
 * que deja detras.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVenta;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class PedidosVentasTemporalIntegracionTest extends CasoIntegracion
{
    private \PedidosVentas $pedidos;
    private Siembra $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_venta/clases/pedidosVentas.php');
        $this->pedidos = new \PedidosVentas($this->db);
        $this->siembra = new Siembra($this->db);
    }

    // --- Alta del borrador ----------------------------------------------------

    public function test_insertarDatosTemporal_creaElBorradorConSusProductos(): void
    {
        $idTemporal = $this->crearBorrador([['idArticulo' => 7, 'ncant' => 3]]);

        $temporal = $this->pedidos->buscarDatosTemporal($idTemporal);

        self::assertStringContainsString('"ncant":3', $temporal['Productos']);
        self::assertSame($this->siembra->clientePorDefecto(), (int) $temporal['idCliente']);
    }

    public function test_insertarDatosTemporal_devuelveElIdentificadorYLosProductosQueGuardo(): void
    {
        $productos = [['idArticulo' => 7, 'ncant' => 1]];

        $respuesta = $this->pedidos->insertarDatosTemporal(
            $this->siembra->usuarioPorDefecto(),
            $this->siembra->tiendaPorDefecto(),
            '2026-02-15',
            [],
            $productos,
            $this->siembra->clientePorDefecto()
        );

        self::assertGreaterThan(0, $respuesta['id']);
        self::assertSame($productos, $respuesta['productos']);
    }

    public function test_insertarDatosTemporal_dejaElBorradorSinNumeroDeDocumento(): void
    {
        $idTemporal = $this->crearBorrador();

        self::assertNull($this->pedidos->buscarDatosTemporal($idTemporal)['Numpedcli']);
    }

    /**
     * El cuarto parametro se declara `$pedidos` y no se usa: la linea que lo codificaba
     * esta comentada. `tareas/AddTemporal.php` le pasa ahi los adjuntos que recibio del
     * navegador, de modo que el despacho envia un dato que la clase descarta sin avisar.
     * En el pedido no tiene consecuencia —no lleva adjuntos—, pero el contrato de la
     * llamada dice lo contrario de lo que ocurre.
     */
    public function test_defecto_insertarDatosTemporal_descartaSilenciosamenteElCuartoParametro(): void
    {
        $conAdjuntos = $this->pedidos->insertarDatosTemporal(
            $this->siembra->usuarioPorDefecto(),
            $this->siembra->tiendaPorDefecto(),
            '2026-02-15',
            [['id' => 1, 'NumAdjunto' => 1]],
            [],
            $this->siembra->clientePorDefecto()
        );

        $columnas = $this->pedidos->buscarDatosTemporal((int) $conAdjuntos['id']);

        self::assertArrayNotHasKey('Pedidos', $columnas, 'El borrador de pedido no tiene donde guardarlos.');
        self::assertSame('[]', $columnas['Productos']);
    }

    // --- Modificacion del borrador ---------------------------------------------

    public function test_modificarDatosTemporal_actualizaLosProductosYLaFecha(): void
    {
        $idTemporal = $this->crearBorrador([['idArticulo' => 7, 'ncant' => 1]]);

        $this->pedidos->modificarDatosTemporal(
            $this->siembra->usuarioPorDefecto(),
            $this->siembra->tiendaPorDefecto(),
            '2026-03-01',
            [],
            $idTemporal,
            [['idArticulo' => 7, 'ncant' => 5]]
        );

        $temporal = $this->pedidos->buscarDatosTemporal($idTemporal);
        self::assertStringContainsString('"ncant":5', $temporal['Productos']);
        self::assertStringStartsWith('2026-03-01', $temporal['Fecha']);
    }

    /**
     * El cliente no entra en la actualizacion: `modificarDatosTemporal()` escribe usuario,
     * fecha, tienda y productos, y deja `idCliente` como estaba. Cambiar de cliente sobre un
     * pedido a medio componer no se guarda, y el documento acaba yendo al cliente con el que
     * se empezo.
     */
    public function test_defecto_modificarDatosTemporal_noActualizaElCliente(): void
    {
        $inicial = $this->siembra->cliente('Cliente con el que se empezo');
        $corregido = $this->siembra->cliente('Cliente al que se quiso cambiar');
        $idTemporal = $this->siembra->pedidoTemporal([], ['idCliente' => $inicial]);

        $this->pedidos->modificarDatosTemporal(
            $this->siembra->usuarioPorDefecto(),
            $this->siembra->tiendaPorDefecto(),
            '2026-03-01',
            [],
            $idTemporal,
            []
        );

        self::assertSame(
            $inicial,
            (int) $this->pedidos->buscarDatosTemporal($idTemporal)['idCliente'],
            'El cliente del borrador no se puede corregir por esta via.'
        );
        self::assertNotSame($corregido, $inicial);
    }

    public function test_modTotales_actualizaElTotalYElDesglose(): void
    {
        $idTemporal = $this->crearBorrador();

        $this->pedidos->modTotales($idTemporal, 121.00, '21.00');

        $temporal = $this->pedidos->buscarDatosTemporal($idTemporal);
        self::assertSame(121.0, (float) $temporal['total']);
        self::assertSame('21.00', $temporal['total_ivas']);
    }

    // --- Marcado con el documento real -------------------------------------------

    public function test_addNumRealTemporal_ataElBorradorAlDocumentoQueSeEstaEditando(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de borrador atado');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-10');
        $idTemporal = $this->crearBorrador();

        $this->pedidos->addNumRealTemporal($idTemporal, $idPedido);

        self::assertSame($idPedido, (int) $this->pedidos->buscarDatosTemporal($idTemporal)['Numpedcli']);
    }

    /**
     * La columna se llama `Numpedcli` pero guarda el identificador del pedido, no su
     * numero: `tareas/AddTemporal.php` le pasa `idReal`, que la pantalla rellena con el id.
     * El propio codigo lo anota —`TodosTemporal()` lleva un comentario diciendo que el campo
     * "debe ser id"—, y toda la via del borrador es coherente con esa lectura. Queda
     * documentado porque el nombre induce a leerlo al reves, y porque `TodosTemporal($n)`
     * recibe un parametro llamado `$idPedido` que compara contra esa columna.
     */
    public function test_defecto_elCampoDeNumeroDelBorradorGuardaElIdentificador(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de borrador con numero desplazado');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-10');
        $this->db->query("UPDATE pedclit SET Numpedcli=Numpedcli+5000 WHERE id={$idPedido}");
        $numero = (int) $this->pedidos->datosPedido($idPedido)['Numpedcli'];
        $idTemporal = $this->crearBorrador();

        $this->pedidos->addNumRealTemporal($idTemporal, $idPedido);

        self::assertSame($idPedido, (int) $this->pedidos->buscarDatosTemporal($idTemporal)['Numpedcli']);
        self::assertNotSame($numero, $idPedido);
        self::assertCount(1, $this->pedidos->TodosTemporal($idPedido), 'Y se recupera por el id, no por el numero.');
        self::assertSame([], $this->pedidos->TodosTemporal($numero));
    }

    // --- Retirada del borrador -----------------------------------------------------

    public function test_eliminarRegistroTemporal_sinDocumentoBorraSoloEseBorrador(): void
    {
        $primero = $this->crearBorrador();
        $segundo = $this->crearBorrador();

        $this->pedidos->EliminarRegistroTemporal($primero, 0);

        self::assertSame([], $this->pedidos->buscarDatosTemporal($primero));
        self::assertNotSame([], $this->pedidos->buscarDatosTemporal($segundo));
    }

    public function test_eliminarRegistroTemporal_conDocumentoBorraTodosLosBorradoresDeEse(): void
    {
        $idArticulo = $this->siembra->articulo('Producto con dos borradores');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-10');
        $primero = $this->crearBorrador();
        $segundo = $this->crearBorrador();
        $this->pedidos->addNumRealTemporal($primero, $idPedido);
        $this->pedidos->addNumRealTemporal($segundo, $idPedido);

        $this->pedidos->EliminarRegistroTemporal($primero, $idPedido);

        self::assertSame([], $this->pedidos->buscarDatosTemporal($primero));
        self::assertSame([], $this->pedidos->buscarDatosTemporal($segundo));
    }

    /**
     * Retirar un borrador que no existe devuelve lo mismo que retirar uno que si: nada. Es
     * la misma ausencia de comprobacion de filas afectadas que en el resto de escrituras
     * cortas de la clase.
     */
    public function test_defecto_eliminarRegistroTemporal_noDistingueUnBorradorInexistente(): void
    {
        $idTemporal = $this->crearBorrador();

        $sobreExistente = $this->pedidos->EliminarRegistroTemporal($idTemporal, 0);
        $sobreInexistente = $this->pedidos->EliminarRegistroTemporal(999999999, 0);

        self::assertSame($sobreExistente, $sobreInexistente);
    }

    /**
     * Nada impide que un mismo pedido acumule varios borradores a la vez. `pedido.php` lo
     * detecta al abrir y reacciona pasando la pantalla a solo lectura: mientras los dos
     * borradores existan, el pedido no se puede editar por ninguna via de la interfaz.
     */
    public function test_defecto_unPedidoPuedeAcumularVariosBorradoresYQuedarBloqueado(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de pedido bloqueado');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-10');
        $this->pedidos->addNumRealTemporal($this->crearBorrador(), $idPedido);
        $this->pedidos->addNumRealTemporal($this->crearBorrador(), $idPedido);

        self::assertCount(2, $this->pedidos->TodosTemporal($idPedido));
    }

    /**
     * Caso abierto por la matriz de condiciones: borrar el pedido no toca sus borradores.
     *
     * Sintoma: tras borrar un pedido, sus borradores siguen vivos apuntando a un documento
     * que ya no existe, y el listado los sigue ofreciendo como pedidos abiertos. Al pulsar
     * uno se abre una pantalla que dice estar editando algo inexistente. Causa raiz: el
     * borrado del pedido y el ciclo de vida del borrador son operaciones independientes, sin
     * clave ajena que las ate ni transaccion que las agrupe. Correccion propuesta: cerrar el
     * ciclo del borrador en la misma transaccion que el documento al que pertenece.
     * Evidencia: este test.
     */
    public function test_defecto_borrarUnPedidoDejaVivosSusBorradores(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de pedido con borrador huerfano');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-10');
        $idTemporal = $this->crearBorrador();
        $this->pedidos->addNumRealTemporal($idTemporal, $idPedido);

        $this->incluirTPVFox('/modulos/mod_venta/clases/pedidosVentas.php');
        $this->pedidos->eliminarPedidoTablas($idPedido);

        self::assertSame([], $this->pedidos->datosPedido($idPedido), 'El pedido ya no existe...');
        self::assertNotSame([], $this->pedidos->buscarDatosTemporal($idTemporal), '... y su borrador sigue vivo.');
        self::assertCount(1, $this->pedidos->TodosTemporal($idPedido), 'Y se sigue ofreciendo como abierto.');
    }

    /**
     * El borrador del pedido no se puede crear sin cliente: su tabla tiene clave ajena contra
     * el cliente y la base lo rechaza. Es lo que distingue al pedido del albaran y de la
     * factura, cuyos borradores se crean sin cliente si es lo que llega. El rechazo sale como
     * excepcion de la base, no como mensaje del sistema.
     *
     * @group pedido
     * @group borrador
     * @group validacion
     *
     * @comportamiento La base rechaza el borrador de pedido sin cliente al crearlo, con una
     *   excepcion de clave ajena.
     */
    public function test_elBorradorSinClienteLoRechazaLaBaseAlCrearlo(): void
    {
        $this->expectException(\mysqli_sql_exception::class);
        $this->expectExceptionMessageMatches('/foreign key constraint fails/i');

        $this->pedidos->insertarDatosTemporal(
            $this->siembra->usuarioPorDefecto(),
            $this->siembra->tiendaPorDefecto(),
            '2026-02-15',
            [],
            [],
            0
        );
    }

    // --- Apoyos ----------------------------------------------------------------------

    /** Un borrador creado por la via real de la clase, no por la siembra. */
    private function crearBorrador(array $productos = []): int
    {
        return (int) $this->pedidos->insertarDatosTemporal(
            $this->siembra->usuarioPorDefecto(),
            $this->siembra->tiendaPorDefecto(),
            '2026-02-15',
            [],
            $productos,
            $this->siembra->clientePorDefecto()
        )['id'];
    }
}
