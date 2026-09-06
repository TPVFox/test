<?php

/**
 * Como se nombra un pedido: unas operaciones lo buscan por su identificador y otras por su
 * numero, y la escritura iguala los dos.
 *
 * Mientras `id` y `Numpedcli` coinciden —que es lo que ocurre en un pedido recien creado—
 * las dos vias llevan al mismo documento y nada se nota. Estos casos fijan que pasa cuando
 * dejan de coincidir.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVenta;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class PedidosVentasIdentidadIntegracionTest extends CasoIntegracion
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

    public function test_lasLecturasDelDocumentoConsultanPorIdentificador(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de identidad basica');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-10');
        $this->db->query("UPDATE pedclit SET Numpedcli=Numpedcli+5000 WHERE id={$idPedido}");
        $numero = (int) $this->pedidos->datosPedido($idPedido)['Numpedcli'];

        self::assertNotSame($numero, $idPedido);
        self::assertSame([], $this->pedidos->datosPedido($numero), 'El numero no alcanza el documento...');
        self::assertNotSame([], $this->pedidos->datosPedido($idPedido), '... y el identificador si.');
    }

    /**
     * Defecto: reguardar un pedido cuyo numero no coincide con su identificador le cambia
     * el numero.
     *
     * Sintoma: al pulsar Guardar sobre un pedido ya existente, `pedido.php` invoca
     * `AddPedidoGuardado()` con el identificador, y la rama de reinsercion escribe
     * `id` y `Numpedcli` con ese mismo valor. El pedido conserva su contenido pero pasa a
     * llamarse de otra manera: el numero que el cliente tiene en su copia deja de existir.
     * Causa raiz: la reinsercion no recupera el numero del documento que acaba de borrar,
     * sino que lo deriva del identificador, dando por hecho que son el mismo dato.
     * Correccion propuesta: conservar el numero del documento a traves del reguardado, y
     * no derivarlo nunca del identificador. Evidencia: este test.
     */
    public function test_defecto_reguardarUnPedidoIgualaSuNumeroASuIdentificador(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de pedido renumerado');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-10');
        $this->db->query("UPDATE pedclit SET Numpedcli=Numpedcli+5000 WHERE id={$idPedido}");
        $numeroOriginal = (int) $this->pedidos->datosPedido($idPedido)['Numpedcli'];

        $this->pedidos->eliminarPedidoTablas($idPedido);
        $this->pedidos->AddPedidoGuardado($this->datosDeGuardado([$this->linea($idArticulo)]), $idPedido);

        self::assertSame(
            $idPedido,
            (int) $this->pedidos->datosPedido($idPedido)['Numpedcli'],
            'El numero del documento pasa a ser su identificador.'
        );
        self::assertNotSame($numeroOriginal, $idPedido, 'Y el numero con el que se emitio se pierde.');
    }

    /**
     * Las lineas y el desglose se escriben con el identificador en las dos columnas, tambien
     * en la que se llama `Numpedcli`. Quien despues consulte por el numero real del
     * documento no las encontrara.
     */
    public function test_defecto_lasLineasYElDesgloseGuardanElIdentificadorEnLaColumnaDeNumero(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de linea con numero desplazado');

        $this->pedidos->AddPedidoGuardado($this->datosDeGuardado([$this->linea($idArticulo)]), 0);
        $idPedido = $this->idDelUltimoPedido();
        $this->db->query("UPDATE pedclit SET Numpedcli=Numpedcli+5000 WHERE id={$idPedido}");
        $numero = (int) $this->pedidos->datosPedido($idPedido)['Numpedcli'];

        self::assertSame($idPedido, (int) $this->pedidos->ProductosPedido($idPedido)[0]['Numpedcli']);
        self::assertSame($idPedido, (int) $this->pedidos->IvasPedidos($idPedido)[0]['Numpedcli']);
        self::assertNotSame($numero, $idPedido);
    }

    /**
     * Defecto: el listado puede mostrar cero impuestos para un pedido que si los tiene.
     *
     * Sintoma: `pedidosListado.php` pinta la columna de impuestos llamando a
     * `sumarIva($pedido['Numpedcli'])` —el numero real del documento—, mientras el desglose
     * se escribio con el identificador en esa misma columna. Cuando los dos valores no
     * coinciden, la suma no encuentra ninguna fila y el listado presenta el pedido como si
     * no tuviera impuestos. Causa raiz: la escritura y la lectura del desglose no usan la
     * misma clave. Correccion propuesta: consultar el desglose por el identificador del
     * documento, que es la clave que la escritura realmente usa. Evidencia: este test.
     */
    public function test_defecto_elListadoNoEncuentraLosImpuestosDeUnPedidoConNumeroDesplazado(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de impuestos invisibles');

        $this->pedidos->AddPedidoGuardado($this->datosDeGuardado([$this->linea($idArticulo)]), 0);
        $idPedido = $this->idDelUltimoPedido();
        $this->db->query("UPDATE pedclit SET Numpedcli=Numpedcli+5000 WHERE id={$idPedido}");
        $numero = (int) $this->pedidos->datosPedido($idPedido)['Numpedcli'];

        self::assertCount(1, $this->pedidos->IvasPedidos($idPedido), 'El pedido tiene su desglose...');
        self::assertNull(
            $this->pedidos->sumarIva($numero)['importeIva'],
            '... y el listado, que suma por el numero, no lo encuentra.'
        );
    }

    /**
     * `PedidosClienteGuardado()` busca por numero mientras el resto de la clase busca por
     * identificador. Es la unica lectura del componente que usa el numero como clave de
     * entrada, y es justo la que alimenta la lista de pedidos que se ofrecen para servir en
     * un albaran.
     */
    public function test_defecto_laBusquedaDePedidosParaServirUsaElNumeroYNoElIdentificador(): void
    {
        $idCliente = $this->siembra->cliente('Cliente de busqueda por numero desplazado');
        $idArticulo = $this->siembra->articulo('Producto de busqueda desplazada');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-10', ['idCliente' => $idCliente]);
        $this->db->query("UPDATE pedclit SET Numpedcli=Numpedcli+5000 WHERE id={$idPedido}");
        $numero = (int) $this->pedidos->datosPedido($idPedido)['Numpedcli'];

        self::assertSame(1, $this->pedidos->PedidosClienteGuardado($numero, $idCliente)['Nitems']);
        self::assertSame(0, $this->pedidos->PedidosClienteGuardado($idPedido, $idCliente)['Nitems']);
    }

    // --- Apoyos ------------------------------------------------------------------

    private function datosDeGuardado(array $productos): array
    {
        return [
            'Numtemp_pedcli' => 0,
            'Fecha'          => '2026-02-15',
            'idTienda'       => $this->siembra->tiendaPorDefecto(),
            'idUsuario'      => $this->siembra->usuarioPorDefecto(),
            'idCliente'      => $this->siembra->clientePorDefecto(),
            'estado'         => 'Guardado',
            'total'          => 12.10,
            'DatosTotales'   => ['desglose' => ['21' => ['iva' => 2.10, 'base' => 10.00]]],
            'productos'      => json_encode($productos),
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

    private function idDelUltimoPedido(): int
    {
        return (int) $this->db
            ->query('SELECT id FROM pedclit WHERE idCliente=' . $this->siembra->clientePorDefecto()
                . ' ORDER BY id DESC LIMIT 1')
            ->fetch_assoc()['id'];
    }
}
