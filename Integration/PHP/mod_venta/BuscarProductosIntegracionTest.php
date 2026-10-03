<?php

/**
 * `BuscarProductos()` de `funciones.php` busca por Referencia/Codbarras/Descripcion.
 * Cuando `idcaja` no es `cajaBusqueda` intenta primero una coincidencia identica
 * (`campo = valor`) y solo si esa falla cae a `campo LIKE %valor%`.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVenta;

use TPVFox\Test\CargaAislada;
use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class BuscarProductosIntegracionTest extends CasoIntegracion
{
    /** Los dos estados de una fila de tarifa (`K_TARIFACLIENTE_ESTADO_*`, `modulos/claseModeloP.php`). */
    private const TARIFA_ACTIVA = '1';
    private const TARIFA_BORRADA = '2';

    private Siembra $siembra;
    private int $idCliente;

    protected function setUp(): void
    {
        parent::setUp();
        CargaAislada::requerir(RUTA_TPVFOX . '/modulos/mod_venta/funciones.php');
        $this->siembra = new Siembra($this->db);
        $this->idCliente = $this->siembra->clientePorDefecto();
    }

    public function test_cajaBusqueda_conVariosResultadosDaListado(): void
    {
        // Termino unico e improbable en cualquier catalogo: la busqueda cuenta filas de forma
        // absoluta, y un termino generico chocaria con la siembra persistente de los recorridos
        // E2E, que vive en la misma base sin transaccion que la deshaga.
        $this->siembra->articulo('Zumaque Alfa');
        $this->siembra->articulo('Zumaque Beta');

        $resultado = \BuscarProductos('cajaBusqueda', 'a.articulo_name', 'Zumaque', $this->db, $this->idCliente);

        self::assertSame('Listado', $resultado['Estado']);
        self::assertSame(2, $resultado['Nitems']);
    }

    public function test_cajaBusqueda_sinResultadosDaNoexiste(): void
    {
        $resultado = \BuscarProductos('cajaBusqueda', 'a.articulo_name', 'Inexistente123', $this->db, $this->idCliente);

        self::assertSame('Noexiste', $resultado['Estado']);
        self::assertSame(0, $resultado['Nitems']);
    }

    public function test_idcajaDistinta_conUnaSolaPalabraCoincidenciaExactaDaCorrecto(): void
    {
        // El nombre coincide exactamente con la busqueda: es lo que activa el primer intento de
        // igualdad y fija Estado='Correcto'. Token unico para no colisionar con la siembra E2E.
        $this->siembra->articulo('Zumaque');

        $resultado = \BuscarProductos('idArticulo', 'a.articulo_name', 'Zumaque', $this->db, $this->idCliente);

        self::assertSame('Correcto', $resultado['Estado']);
        self::assertSame(1, $resultado['Nitems']);
    }

    /**
     * Defecto: con una busqueda de varias palabras e `idcaja` distinta de `cajaBusqueda`,
     * `Estado` puede quedar sin definir.
     *
     * Sintoma: `BuscarProductos('idArticulo', ..., 'Zumaque Alfa', ...)` con un solo
     * articulo que coincide devuelve `Nitems=1` pero sin la clave `Estado` en absoluto (PHP
     * avisa de "Undefined array key Estado"). Causa raiz: para `idcaja !== 'cajaBusqueda'`,
     * `funciones.php` arma primero una busqueda "identica" que encadena con `and` una
     * igualdad por cada palabra sobre la MISMA columna (`campo = "Zumaque" and campo =
     * "Alfa"`) — una condicion que ninguna fila puede cumplir nunca con mas de una
     * palabra, asi que ese primer intento siempre da cero filas. El codigo solo marca
     * `Estado = 'Correcto'` cuando el primer intento (`$i === 0`) encuentra algo, y solo
     * marca `Estado = 'Listado'` cuando el `Nitems` final es mayor que uno: el hueco entre
     * ambos —el segundo intento (LIKE) encuentra exactamente una fila— no fija `Estado` en
     * ningun punto del codigo. `tareas/BuscarProductos.php` lee `$res['Nitems'] === 1` para
     * decidir si devuelve un producto unico, sin mirar `Estado`, asi que el efecto visible
     * es solo el aviso de PHP, no una respuesta incorrecta — pero el contrato de la funcion
     * (documentado como "1: Un producto unico. 2: Un listado. 3: O nada un error.") queda
     * incumplido para este caso. Correccion propuesta: la busqueda "identica" solo tiene
     * sentido con una palabra; con varias, se debe generar como frase completa
     * (`campo = "Zumaque Alfa"`) en vez de encadenar igualdades por palabra. Evidencia:
     * este test, en rojo mientras el defecto siga sin corregirse por CC.
     *
     * @estado rojo
          *
     * @group defecto
     * @group busqueda
     * @group bajo
     *
     * @que-ocurre-hoy Una busqueda de varias palabras que encuentra exactamente un articulo
     *   devuelve el recuento pero sin la clave de estado, y PHP avisa de que falta.
     * @que-deberia-ocurrir Que el estado se fije siempre, como su contrato declara.
     * @por-que-ocurre El estado solo se marca cuando el primer intento encuentra algo o cuando
     *   el resultado final tiene mas de una fila; el hueco entre ambos no lo fija nunca. Y el
     *   primer intento encadena una igualdad por palabra sobre la misma columna, condicion que
     *   ninguna fila puede cumplir con mas de una palabra.
     * @como-deberia-funcionar Con varias palabras, buscar la frase completa en vez de encadenar
     *   igualdades, y fijar el estado en todos los caminos.
    */
    public function test_defecto_conVariasPalabrasYUnSoloResultadoPorLikeElEstadoQuedaSinDefinir(): void
    {
        $this->siembra->articulo('Zumaque Alfa');

        $resultado = \BuscarProductos('idArticulo', 'a.articulo_name', 'Zumaque Alfa', $this->db, $this->idCliente);

        self::assertArrayHasKey(
            'Estado',
            $resultado,
            'Nitems=1 deberia venir siempre acompanado de un Estado, sea Correcto o el que corresponda'
        );
    }

    // --- La tarifa del cliente ---------------------------------------------------

    /**
     * Un producto con tarifa activa para el cliente se ofrece a su precio de tarifa: es el
     * precio que la venta pone a la linea.
     *
     * @group control
     * @group busqueda
     * @group importes
     *
     * @para-que-sirve Acompana al caso de la tarifa borrada: demuestra que la tarifa activa se
     *   aplica, de modo que lo que aquel caso mide es solo el estado de la fila.
     */
    public function test_unProductoConTarifaActivaSeOfreceAlPrecioDeTarifa(): void
    {
        $idArticulo = $this->articuloConTarifa('ZumaqueTarifaActiva', self::TARIFA_ACTIVA);

        $resultado = \BuscarProductos('idArticulo', 'a.articulo_name', 'ZumaqueTarifaActiva', $this->db, $this->idCliente);

        self::assertSame($idArticulo, (int) $resultado['datos'][0]['idArticulo']);
        self::assertEqualsWithDelta(7.0, (float) $resultado['datos'][0]['pvpCivaCLI'], 0.000001);
    }

    /**
     * Quitar un producto de la tarifa de un cliente no lo quita de la venta.
     *
     * El modulo de clientes no elimina la fila de la tarifa: la marca como borrada
     * (`mod_cliente/tareas/borrarArticuloCliente.php:25`), y su propio listado solo ensena las
     * activas (`claseTarifaCliente.php:29`). La busqueda de productos de la venta une la
     * tarifa del cliente sin mirar ese estado, de modo que el producto quitado se sigue
     * ofreciendo, y vendiendo, al precio de tarifa. La pantalla de la tarifa dice que ya no lo
     * tiene; el albaran y la factura se lo cobran.
     *
     * @group defecto
     * @group busqueda
     * @group importes
     * @group alto
     *
     * @que-ocurre-hoy Un producto que se ha quitado de la tarifa de un cliente se le sigue
     *   vendiendo al precio de esa tarifa, aunque la pantalla de la tarifa ya no lo muestre.
     * @que-deberia-ocurrir Que la venta solo aplique las filas activas de la tarifa, y que el
     *   producto quitado se venda a su precio normal.
     * @por-que-ocurre Quitar un producto de la tarifa marca la fila como borrada en vez de
     *   eliminarla, y la busqueda de la venta une la tarifa por cliente y articulo sin
     *   comprobar el estado de la fila.
     * @como-deberia-funcionar Unir solo las filas de tarifa activas, como ya hace el listado
     *   de la tarifa en el modulo de clientes.
     *
     * @codigo-afectado modulos/mod_venta/funciones.php:45-50
     */
    public function test_defecto_unProductoQuitadoDeLaTarifaDelClienteSeSigueVendiendoAlPrecioDeTarifa(): void
    {
        $this->articuloConTarifa('ZumaqueTarifaBorrada', self::TARIFA_BORRADA);

        $resultado = \BuscarProductos('idArticulo', 'a.articulo_name', 'ZumaqueTarifaBorrada', $this->db, $this->idCliente);

        self::assertEqualsWithDelta(
            7.0,
            (float) $resultado['datos'][0]['pvpCivaCLI'],
            0.000001,
            'La fila borrada de la tarifa sigue dando el precio de la venta'
        );
    }

    /** Un articulo de precio 10 con tarifa de 7 para el cliente, en el estado indicado. */
    private function articuloConTarifa(string $nombre, string $estado): int
    {
        $idArticulo = $this->siembra->articulo($nombre);
        $this->siembra->precioYTienda($idArticulo, 10.0, 10.0);

        $sentencia = $this->db->prepare(
            'INSERT INTO articulosClientes (idArticulo, idClientes, pvpSiva, pvpCiva, fechaActualizacion, estado)'
            . ' VALUES (?, ?, 7, 7, NOW(), ?)'
        );
        $sentencia->bind_param('iis', $idArticulo, $this->idCliente, $estado);
        $sentencia->execute();

        return $idArticulo;
    }
}
