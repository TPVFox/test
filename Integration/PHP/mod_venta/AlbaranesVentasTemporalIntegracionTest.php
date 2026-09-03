<?php

/**
 * `AlbaranesVentas`, los metodos que escriben sobre el borrador y sobre la cabecera ya
 * guardada: crear y modificar el temporal, fijarle totales, enlazarlo con el albaran real,
 * eliminarlo, y cambiar estado y fecha del albaran.
 *
 * Son las escrituras cortas del componente —una sentencia cada una—, que es justo lo que
 * las hace faciles de dar por buenas sin comprobarlas: ninguna devuelve nada cuando tiene
 * exito.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVenta;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class AlbaranesVentasTemporalIntegracionTest extends CasoIntegracion
{
    private \AlbaranesVentas $albaranes;
    private Siembra $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_venta/clases/albaranesVentas.php');
        $this->albaranes = new \AlbaranesVentas($this->db);
        $this->siembra = new Siembra($this->db);
    }

    public function test_insertarDatosTemporal_creaElBorradorYDevuelveSuId(): void
    {
        $respuesta = $this->insertarTemporal([['idArticulo' => 1, 'cdetalle' => 'Producto del borrador']]);

        self::assertIsInt($respuesta['id']);
        self::assertNotSame([], $this->albaranes->buscarDatosTemporal($respuesta['id']));
    }

    public function test_insertarDatosTemporal_guardaLosProductosComoTextoJson(): void
    {
        $respuesta = $this->insertarTemporal([['idArticulo' => 7, 'cdetalle' => 'Producto recuperable']]);

        $temporal = $this->albaranes->buscarDatosTemporal($respuesta['id']);
        self::assertSame('Producto recuperable', json_decode($temporal['Productos'], true)[0]['cdetalle']);
    }

    public function test_insertarDatosTemporal_escapaLasComillasDeLaDescripcionDelProducto(): void
    {
        $respuesta = $this->insertarTemporal([['idArticulo' => 7, 'cdetalle' => 'Cable 1" macho']]);

        $temporal = $this->albaranes->buscarDatosTemporal($respuesta['id']);
        self::assertSame('Cable 1" macho', json_decode($temporal['Productos'], true)[0]['cdetalle']);
    }

    /**
     * Defecto: el metodo escapa dos de sus parametros y no el tercero.
     *
     * Sintoma: una descripcion de producto con comilla doble se guarda bien —el caso
     * anterior lo demuestra— pero una fecha con comilla doble rompe la sentencia y deja
     * escapar la excepcion. Causa raiz: `insertarDatosTemporal()` pasa `Productos` y
     * `Pedidos` por `real_escape_string()` antes de concatenarlos, pero `$fecha`,
     * `$idUsuario`, `$idTienda` e `$idCliente` entran crudos en la sentencia; la fecha, que
     * es texto, se delimita con comillas dobles que la propia entrada puede cerrar. La
     * defensa existe y esta aplicada a medias, que es peor que no tenerla: hace pensar que
     * el metodo esta protegido. Correccion propuesta: parametrizar la sentencia entera en
     * vez de escapar parametro a parametro. Evidencia: este test.
     */
    public function test_defecto_insertarDatosTemporal_noEscapaLaFechaQueSiEscapaLosProductos(): void
    {
        $this->expectException(\mysqli_sql_exception::class);

        $this->albaranes->insertarDatosTemporal(
            $this->siembra->usuarioPorDefecto(),
            $this->siembra->tiendaPorDefecto(),
            '2026-01-01"',
            [],
            [],
            $this->siembra->clientePorDefecto()
        );
    }

    public function test_modificarDatosTemporal_reemplazaProductosYPedidos(): void
    {
        $idTemporal = $this->siembra->albaranTemporal([['idArticulo' => 1, 'cdetalle' => 'Antes']]);

        $this->albaranes->modificarDatosTemporal(
            $this->siembra->usuarioPorDefecto(),
            $this->siembra->tiendaPorDefecto(),
            '2026-03-01',
            [],
            $idTemporal,
            [['idArticulo' => 2, 'cdetalle' => 'Despues']]
        );

        $temporal = $this->albaranes->buscarDatosTemporal($idTemporal);
        self::assertSame('Despues', json_decode($temporal['Productos'], true)[0]['cdetalle']);
        self::assertSame('2026-03-01', substr($temporal['Fecha'], 0, 10));
    }

    /**
     * Hallazgo de contrato, documentado sin corregir: los dos metodos que escriben el mismo
     * campo devuelven ese campo con tipos distintos.
     *
     * `insertarDatosTemporal()` devuelve `productos` como el array que recibio, mientras que
     * `modificarDatosTemporal()` devuelve el mismo dato ya serializado como texto JSON. Un
     * llamador que trate las dos respuestas igual —altas y modificaciones pasan por el mismo
     * sitio en el despacho— acierta en un camino y falla en el otro. Correccion propuesta:
     * que ambos devuelvan la misma forma. Evidencia: este test, que fija la diferencia.
     */
    public function test_modificarDatosTemporal_devuelveLosProductosComoTextoYElAltaComoArray(): void
    {
        $idTemporal = $this->siembra->albaranTemporal();
        $productos = [['idArticulo' => 3, 'cdetalle' => 'Comparacion de contrato']];

        $alta = $this->insertarTemporal($productos);
        $modificacion = $this->albaranes->modificarDatosTemporal(
            $this->siembra->usuarioPorDefecto(),
            $this->siembra->tiendaPorDefecto(),
            '2026-03-01',
            [],
            $idTemporal,
            $productos
        );

        self::assertIsArray($alta['productos']);
        self::assertIsString($modificacion['productos']);
    }

    public function test_modTotales_fijaElTotalYElDesgloseDelBorrador(): void
    {
        $idTemporal = $this->siembra->albaranTemporal();

        $this->albaranes->modTotales($idTemporal, 45.50, '7.90');

        $temporal = $this->albaranes->buscarDatosTemporal($idTemporal);
        self::assertSame('45.500000', $temporal['total']);
        self::assertSame('7.90', $temporal['total_ivas']);
    }

    public function test_addNumRealTemporal_enlazaElBorradorConElAlbaranReal(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de enlace de temporal');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $idTemporal = $this->siembra->albaranTemporal();

        $this->albaranes->addNumRealTemporal($idTemporal, $idAlbaran);

        self::assertSame($idAlbaran, (int) $this->albaranes->buscarDatosTemporal($idTemporal)['Numalbcli']);
    }

    public function test_eliminarRegistroTemporal_sinNumeroDeAlbaranBorraSoloEseBorrador(): void
    {
        $unBorrador = $this->siembra->albaranTemporal();
        $otroBorrador = $this->siembra->albaranTemporal();

        $this->albaranes->EliminarRegistroTemporal($unBorrador, 0);

        self::assertSame([], $this->albaranes->buscarDatosTemporal($unBorrador));
        self::assertNotSame([], $this->albaranes->buscarDatosTemporal($otroBorrador));
    }

    /**
     * Con numero de albaran el borrado es masivo: se lleva todos los borradores de ese
     * albaran, no solo el que se le pasa como primer parametro.
     *
     * Es deliberado —el comentario del metodo lo dice— y resuelve el caso que la pantalla
     * advierte como error ("existen varios temporales de este albaran"), pero conviene
     * fijarlo con una prueba: el primer parametro se ignora por completo en esta rama, de
     * modo que llamarlo con el id equivocado tiene el mismo efecto que con el correcto.
     */
    public function test_eliminarRegistroTemporal_conNumeroDeAlbaranBorraTodosLosSuyos(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de borrado masivo');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $primero = $this->siembra->albaranTemporal([], [], ['Numalbcli' => $idAlbaran]);
        $segundo = $this->siembra->albaranTemporal([], [], ['Numalbcli' => $idAlbaran]);
        $ajeno = $this->siembra->albaranTemporal();

        $this->albaranes->EliminarRegistroTemporal($primero, $idAlbaran);

        self::assertSame([], $this->albaranes->buscarDatosTemporal($primero));
        self::assertSame([], $this->albaranes->buscarDatosTemporal($segundo));
        self::assertNotSame([], $this->albaranes->buscarDatosTemporal($ajeno));
    }

    public function test_modificarEstadoAlbaran_cambiaElEstadoDeLaCabecera(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de cambio de estado');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');

        $this->albaranes->ModificarEstadoAlbaran($idAlbaran, 'Procesado');

        self::assertSame('Procesado', $this->albaranes->getEstado($idAlbaran));
    }

    /**
     * Defecto: el estado de un albaran se puede dejar en cualquier cadena.
     *
     * Sintoma: `ModificarEstadoAlbaran()` acepta un estado que no figura entre los tres que
     * la propia clase declara en `posiblesEstados()`, lo escribe y no avisa. A partir de ahi
     * la pantalla del albaran, que decide si permite editar comparando contra esos tres
     * valores, no encaja en ninguna rama. Causa raiz: el metodo concatena el valor recibido
     * en el `UPDATE` sin contrastarlo con el catalogo que la clase ya tiene. Correccion
     * propuesta: validar contra `posiblesEstados()` antes de escribir, y devolver un error
     * si no encaja. Evidencia: este test.
     */
    public function test_defecto_modificarEstadoAlbaran_aceptaUnEstadoQueNoEstaEnElCatalogo(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de estado inventado');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');

        $this->albaranes->ModificarEstadoAlbaran($idAlbaran, 'Vete a saber');

        $catalogo = array_column($this->albaranes->posiblesEstados(), 'estado');
        self::assertNotContains('Vete a saber', $catalogo, 'El estado escrito no esta en el catalogo de la clase.');
        self::assertSame('Vete a saber', $this->albaranes->getEstado($idAlbaran), 'Y aun asi queda escrito.');
    }

    public function test_modificarFecha_cambiaLaFechaDelAlbaran(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de cambio de fecha');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');

        $this->albaranes->modificarFecha($idAlbaran, '2026-05-20');

        self::assertSame('2026-05-20', substr($this->albaranes->datosAlbaran($idAlbaran)['Fecha'], 0, 10));
    }

    /**
     * Defecto: las cinco escrituras cortas de la clase no distinguen haber cambiado algo de
     * no haber cambiado nada.
     *
     * Sintoma: `ModificarEstadoAlbaran()` sobre un albaran que no existe devuelve
     * exactamente lo mismo —nada— que sobre uno que si cambio. Causa raiz: ninguna de estas
     * escrituras comprueba cuantas filas afecto; todas devuelven `null` cuando el SQL no
     * falla, sin mirar si encontraron la fila. Afecta igual a `modificarFecha()`,
     * `addNumRealTemporal()`, `modTotales()` y `EliminarRegistroTemporal()`. Consecuencia:
     * el llamador que no reciba error da el cambio por hecho. Correccion propuesta: que
     * devuelvan cuantas filas tocaron y que el llamador decida. Evidencia: este test.
     */
    public function test_defecto_lasEscriturasCortasNoDistinguenHaberCambiadoAlgoDeNoHacerNada(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de comparacion de escrituras');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');

        $sobreExistente = $this->albaranes->ModificarEstadoAlbaran($idAlbaran, 'Procesado');
        $sobreInexistente = $this->albaranes->ModificarEstadoAlbaran(999999999, 'Procesado');

        self::assertSame($sobreExistente, $sobreInexistente);
    }

    // --- Apoyos del caso ------------------------------------------------------

    /** Un temporal creado por el propio producto, con los parametros en su orden real. */
    private function insertarTemporal(array $productos, array $pedidos = []): array
    {
        return $this->albaranes->insertarDatosTemporal(
            $this->siembra->usuarioPorDefecto(),
            $this->siembra->tiendaPorDefecto(),
            '2026-01-01',
            $pedidos,
            $productos,
            $this->siembra->clientePorDefecto()
        );
    }
}
