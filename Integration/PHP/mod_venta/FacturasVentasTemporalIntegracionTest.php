<?php

/**
 * `FacturasVentas`, la factura en curso: el borrador que la pantalla crea al incorporar el
 * primer albaran o el primer producto, lo que se le va escribiendo mientras se edita, y
 * como se le ata el numero del documento real cuando ya existe.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVenta;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class FacturasVentasTemporalIntegracionTest extends CasoIntegracion
{
    private \FacturasVentas $facturas;
    private Siembra $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_venta/clases/facturasVentas.php');
        $this->facturas = new \FacturasVentas($this->db);
        $this->siembra = new Siembra($this->db);
    }

    // --- Alta y edicion del borrador ------------------------------------------

    public function test_insertarDatosTemporal_creaElBorradorConSusProductosYAlbaranes(): void
    {
        $idTemporal = $this->borradorNuevo();

        $borrador = $this->facturas->buscarDatosTemporal($idTemporal);

        self::assertSame($this->siembra->clientePorDefecto(), (int) $borrador['idCliente']);
        self::assertCount(1, json_decode($borrador['Productos'], true));
    }

    public function test_insertarDatosTemporal_devuelveElIdentificadorDelBorradorCreado(): void
    {
        $respuesta = $this->facturas->insertarDatosTemporal(
            $this->siembra->usuarioPorDefecto(),
            $this->siembra->tiendaPorDefecto(),
            '2026-02-15',
            [],
            [['idArticulo' => 1, 'estadoLinea' => 'Activo']],
            $this->siembra->clientePorDefecto()
        );

        self::assertGreaterThan(0, $respuesta['id']);
    }

    /**
     * La tabla del borrador declara una fecha de vencimiento propia y ninguna escritura de
     * la clase la toca: ni el alta ni la modificacion la reciben. La pantalla la recalcula
     * cada vez a partir de la ficha del cliente, de modo que lo que el operador ajuste a
     * mano en un borrador no sobrevive a recargarlo.
     */
    public function test_defecto_elBorradorNuncaRecibeLaFechaDeVencimientoQueDeclaraSuTabla(): void
    {
        $idTemporal = $this->borradorNuevo();

        self::assertNull($this->facturas->buscarDatosTemporal($idTemporal)['fechaVencimiento']);
    }

    public function test_modificarDatosTemporal_reescribeLosProductosDelBorrador(): void
    {
        $idTemporal = $this->borradorNuevo();

        $this->facturas->modificarDatosTemporal(
            $this->siembra->usuarioPorDefecto(),
            $this->siembra->tiendaPorDefecto(),
            '2026-02-16',
            [],
            $idTemporal,
            [['idArticulo' => 1, 'estadoLinea' => 'Activo'], ['idArticulo' => 2, 'estadoLinea' => 'Activo']]
        );

        $productos = json_decode($this->facturas->buscarDatosTemporal($idTemporal)['Productos'], true);
        self::assertCount(2, $productos);
    }

    /**
     * La sentencia que reescribe el borrador cierra la comilla del bloque de productos un
     * caracter tarde, de modo que cada modificacion deja un espacio pegado al final del
     * contenido. Lo guardado deja de ser identico a lo enviado, aunque siga siendo legible.
     */
    public function test_defecto_cadaModificacionDelBorradorAnadeUnEspacioAlBloqueDeProductos(): void
    {
        $idTemporal = $this->borradorNuevo();
        $productos = [['idArticulo' => 1, 'estadoLinea' => 'Activo']];

        $this->facturas->modificarDatosTemporal(
            $this->siembra->usuarioPorDefecto(),
            $this->siembra->tiendaPorDefecto(),
            '2026-02-16',
            [],
            $idTemporal,
            $productos
        );

        $guardado = $this->facturas->buscarDatosTemporal($idTemporal)['Productos'];
        self::assertNotSame(json_encode($productos), $guardado, 'Lo guardado no es lo enviado...');
        self::assertSame(json_encode($productos), rtrim($guardado), '... difiere solo en el espacio final.');
    }

    /**
     * La modificacion del borrador no recibe el cliente, de modo que un borrador arrastra
     * el cliente con el que se creo aunque la pantalla lo cambiase.
     */
    public function test_defecto_laModificacionDelBorradorNoAlcanzaAlCliente(): void
    {
        $idTemporal = $this->borradorNuevo();
        $otroCliente = $this->siembra->cliente('Cliente que no llega al borrador');

        $this->facturas->modificarDatosTemporal(
            $this->siembra->usuarioPorDefecto(),
            $this->siembra->tiendaPorDefecto(),
            '2026-02-16',
            [],
            $idTemporal,
            [['idArticulo' => 1, 'estadoLinea' => 'Activo']]
        );

        $borrador = $this->facturas->buscarDatosTemporal($idTemporal);
        self::assertNotSame($otroCliente, (int) $borrador['idCliente']);
        self::assertSame($this->siembra->clientePorDefecto(), (int) $borrador['idCliente']);
    }

    public function test_modTotales_escribeElTotalYElDesgloseDelBorrador(): void
    {
        $idTemporal = $this->borradorNuevo();

        $this->facturas->modTotales($idTemporal, 121.00, '21');

        $borrador = $this->facturas->buscarDatosTemporal($idTemporal);
        self::assertSame('121.000000', $borrador['total']);
        self::assertSame('21', $borrador['total_ivas']);
    }

    // --- Lectura de borradores -------------------------------------------------

    public function test_buscarDatosTemporal_sinCoincidenciaDevuelveArrayVacio(): void
    {
        self::assertSame([], $this->facturas->buscarDatosTemporal(999999999));
    }

    public function test_todosTemporal_devuelveLosBorradoresConElNombreDelCliente(): void
    {
        $idCliente = $this->siembra->cliente('Cliente con borrador de factura');
        $idTemporal = $this->siembra->facturaTemporal([], [], ['idCliente' => $idCliente]);

        $nombres = [];
        foreach ($this->facturas->TodosTemporal() as $borrador) {
            if ((int) $borrador['id'] === $idTemporal) {
                $nombres[] = $borrador['Nombre'];
            }
        }

        self::assertSame(['Cliente con borrador de factura'], $nombres);
    }

    public function test_todosTemporal_conNumeroDeFacturaSoloDevuelveElSuyo(): void
    {
        $idTemporal = $this->siembra->facturaTemporal([], [], ['Numfaccli' => 424242]);
        $this->siembra->facturaTemporal();

        $borradores = $this->facturas->TodosTemporal(424242);

        self::assertCount(1, $borradores);
        self::assertSame($idTemporal, (int) $borradores[0]['id']);
    }

    /**
     * El listado de borradores no se acota ni por tienda ni por usuario: quien abra la
     * pantalla ve los documentos en curso de todo el sistema, y puede continuar el de
     * cualquier otro.
     */
    public function test_defecto_elListadoDeBorradoresNoSeAcotaPorTiendaNiPorUsuario(): void
    {
        $otraTienda = $this->siembra->tienda('2027', 'secundaria');
        $ajeno = $this->siembra->facturaTemporal([], [], ['idTienda' => $otraTienda]);

        $identificadores = array_column($this->facturas->TodosTemporal(), 'id');

        self::assertContains((string) $ajeno, $identificadores);
    }

    /**
     * El borrador se crea con el cliente que reciba, sin comprobar que sea alguno: con cero
     * queda una factura en curso sin cliente, que el listado muestra con la columna vacia y
     * que la pantalla no puede abrir —al no haber cliente no hay forma de vencimiento, y sin
     * ella la vista de factura no llega a montarse—.
     *
     * El unico control que hoy lo evita es de navegador: `AccionesDirectas.js` no pide el
     * borrador al cambiar la fecha si el cliente no esta puesto. El servidor no comprueba
     * nada, de modo que cualquier peticion que llegue con cliente cero deja el borrador
     * escrito.
     */
    public function test_defecto_elBorradorSeCreaSinClienteSiEsLoQueRecibe(): void
    {
        $respuesta = $this->facturas->insertarDatosTemporal(
            $this->siembra->usuarioPorDefecto(),
            $this->siembra->tiendaPorDefecto(),
            '2026-02-15',
            [],
            [],
            0
        );

        $borrador = $this->facturas->buscarDatosTemporal((int) $respuesta['id']);
        self::assertSame(0, (int) $borrador['idCliente']);

        $enElListado = null;
        foreach ($this->facturas->TodosTemporal() as $fila) {
            if ((int) $fila['id'] === (int) $respuesta['id']) {
                $enElListado = $fila;
            }
        }
        self::assertNotNull($enElListado, 'Aparece en el listado de facturas abiertas...');
        self::assertNull($enElListado['Nombre'], '... con la columna de cliente vacia.');
    }

    // --- El numero real que se ata al borrador ---------------------------------

    /**
     * La columna del borrador que guarda el documento real se llama «numero de factura»,
     * y lo que la pantalla envia y aqui se escribe es el identificador. La lectura usa esa
     * misma clave con el mismo valor, asi que hoy concuerdan; el nombre de la columna, no.
     */
    public function test_defecto_elBorradorGuardaElIdentificadorEnLaColumnaDelNumero(): void
    {
        $idFactura = $this->facturaSembrada('Producto de factura con borrador atado');
        $numero = (int) $this->facturas->datosFactura($idFactura)['Numfaccli'];
        $idTemporal = $this->borradorNuevo();

        $this->facturas->addNumRealTemporal($idTemporal, $idFactura);

        $guardado = (int) $this->facturas->buscarDatosTemporal($idTemporal)['Numfaccli'];
        self::assertSame($idFactura, $guardado, 'Se escribe el identificador...');
        self::assertNotSame($numero, $guardado, '... en la columna que dice guardar el numero.');
    }

    // --- Eliminacion del borrador ----------------------------------------------

    public function test_eliminarRegistroTemporal_sinFacturaBorraPorIdentificadorDeBorrador(): void
    {
        $idTemporal = $this->borradorNuevo();

        $this->facturas->EliminarRegistroTemporal($idTemporal, 0);

        self::assertSame([], $this->facturas->buscarDatosTemporal($idTemporal));
    }

    /**
     * Con factura, el borrado no va por el identificador del borrador sino por el numero de
     * la factura, y alcanza a todas las filas que lo lleven. Un segundo borrador de la
     * misma factura —el estado que la propia pantalla advierte como duplicidad— desaparece
     * junto con el primero, sin que nadie lo haya pedido.
     */
    public function test_defecto_borrarPorFacturaAlcanzaATodosLosBorradoresQueLaLleven(): void
    {
        $primero = $this->siembra->facturaTemporal([], [], ['Numfaccli' => 515151]);
        $segundo = $this->siembra->facturaTemporal([], [], ['Numfaccli' => 515151]);

        $this->facturas->EliminarRegistroTemporal($primero, 515151);

        self::assertSame([], $this->facturas->buscarDatosTemporal($primero));
        self::assertSame([], $this->facturas->buscarDatosTemporal($segundo), 'El segundo borrador se va tambien.');
    }

    // --- La comprobacion de duplicidad que nadie invoca -------------------------

    /**
     * La funcion inicializa una variable y devuelve otra, con el nombre mal escrito. Solo
     * hay un camino que asigne la que devuelve —el de encontrar exactamente un borrador—,
     * asi que cuando no hay ninguno responde nulo apoyandose en una variable no definida.
     */
    public function test_defecto_sinBorradoresLaComprobacionDevuelveUnaVariableNoDefinida(): void
    {
        self::assertNull(@$this->facturas->comprobarTemporalesIdFac(999999999));
    }

    public function test_comprobarTemporalesIdFac_conUnSoloBorradorDevuelveSuIdentificador(): void
    {
        $idTemporal = $this->siembra->facturaTemporal([], [], ['Numfaccli' => 616161]);

        $respuesta = $this->facturas->comprobarTemporalesIdFac(616161);

        self::assertSame($idTemporal, (int) $respuesta['idTemporal']);
    }

    /**
     * La comprobacion de duplicidad no llega a avisar de nada: al detectar el duplicado
     * acumula el aviso sobre una variable que la funcion nunca inicializo —inicializa otra,
     * con el nombre mal escrito— y falla en el punto exacto para el que fue escrita.
     * Ninguna vista la invoca, asi que el fallo no se ha manifestado nunca.
     */
    public function test_defecto_laComprobacionDeDuplicidadFallaAlEncontrarElDuplicado(): void
    {
        $this->siembra->facturaTemporal([], [], ['Numfaccli' => 717171]);
        $this->siembra->facturaTemporal([], [], ['Numfaccli' => 717171]);

        $this->expectException(\TypeError::class);

        $this->facturas->comprobarTemporalesIdFac(717171);
    }

    // --- Apoyos del caso --------------------------------------------------------

    /** Un borrador de factura creado por la propia clase, como lo crea la pantalla. */
    private function borradorNuevo(): int
    {
        return (int) $this->facturas->insertarDatosTemporal(
            $this->siembra->usuarioPorDefecto(),
            $this->siembra->tiendaPorDefecto(),
            '2026-02-15',
            [],
            [['idArticulo' => 1, 'estadoLinea' => 'Activo']],
            $this->siembra->clientePorDefecto()
        )['id'];
    }

    /** Una factura real, por el unico camino que la crea: facturar un albaran de cliente. */
    private function facturaSembrada(string $nombreArticulo): int
    {
        $idArticulo = $this->siembra->articulo($nombreArticulo);
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');

        return $this->siembra->facturarAlbaranCliente($idAlbaran);
    }
}
