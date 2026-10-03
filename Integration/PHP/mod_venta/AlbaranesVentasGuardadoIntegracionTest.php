<?php

/**
 * `AlbaranesVentas`, el guardado del albaran real: la escritura de la cabecera, de sus
 * lineas, del desglose de ivas y de los pedidos que lleva adjuntos, mas el movimiento de
 * existencias que cada linea provoca y el borrado que deshace todo eso.
 *
 * Es el punto del modulo donde una escritura a medias deja el documento inconsistente, de
 * modo que los casos no se limitan al camino sano: provocan un fallo en mitad de la
 * secuencia y comprueban que queda escrito y que no.
 *
 * La conexion se comparte con el producto porque el movimiento de existencias no pasa por
 * la clase de venta sino por `alArticulosStocks`, que abre la suya propia si no se le
 * inyecta esta: sin compartirla, lo que el guardado toca del stock quedaria fuera de la
 * transaccion que aisla el caso.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVenta;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class AlbaranesVentasGuardadoIntegracionTest extends CasoIntegracion
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

    public function test_addAlbaranGuardado_creaLaCabeceraConSuNumeroIgualAlId(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo de alta de albaran');

        $this->albaranes->AddAlbaranGuardado($this->datosDeGuardado([$this->linea($idArticulo)]), 0);

        $fila = $this->ultimoAlbaranDe($this->siembra->clientePorDefecto());
        self::assertSame($fila['id'], $fila['Numalbcli'], 'El numero de albaran se iguala al id recien creado.');
        self::assertSame('Guardado', $fila['estado']);
    }

    public function test_addAlbaranGuardado_escribeUnaLineaPorProductoActivo(): void
    {
        $primero = $this->siembra->articulo('Primer producto guardado');
        $segundo = $this->siembra->articulo('Segundo producto guardado');

        $this->albaranes->AddAlbaranGuardado($this->datosDeGuardado([
            $this->linea($primero),
            $this->linea($segundo),
        ]), 0);

        $idAlbaran = (int) $this->ultimoAlbaranDe($this->siembra->clientePorDefecto())['id'];
        self::assertCount(2, $this->albaranes->ProductosAlbaran($idAlbaran));
    }

    public function test_addAlbaranGuardado_noEscribeLasLineasQueNoEstanActivas(): void
    {
        $activo = $this->siembra->articulo('Producto activo');
        $retirado = $this->siembra->articulo('Producto retirado de la linea');

        $this->albaranes->AddAlbaranGuardado($this->datosDeGuardado([
            $this->linea($activo),
            $this->linea($retirado, ['estadoLinea' => 'Anulado']),
        ]), 0);

        $idAlbaran = (int) $this->ultimoAlbaranDe($this->siembra->clientePorDefecto())['id'];
        self::assertCount(1, $this->albaranes->ProductosAlbaran($idAlbaran));
    }

    public function test_addAlbaranGuardado_escribeElDesgloseDeIvas(): void
    {
        $idArticulo = $this->siembra->articulo('Producto con desglose');

        $this->albaranes->AddAlbaranGuardado($this->datosDeGuardado([$this->linea($idArticulo)]), 0);

        $idAlbaran = (int) $this->ultimoAlbaranDe($this->siembra->clientePorDefecto())['id'];
        $desglose = $this->albaranes->IvasAlbaran($idAlbaran);
        self::assertSame('21', $desglose[0]['iva']);
    }

    public function test_addAlbaranGuardado_enlazaLosPedidosAdjuntadosActivos(): void
    {
        $idArticulo = $this->siembra->articulo('Producto con pedido adjunto');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-05');

        $datos = $this->datosDeGuardado([$this->linea($idArticulo)]);
        $datos['pedidos'] = json_encode([
            ['id' => $idPedido, 'NumAdjunto' => $idPedido, 'estado' => 'Activo'],
        ]);
        $this->albaranes->AddAlbaranGuardado($datos, 0);

        $idAlbaran = (int) $this->ultimoAlbaranDe($this->siembra->clientePorDefecto())['id'];
        $pedidos = $this->albaranes->obtenerPedidosAlbaran($idAlbaran);
        self::assertCount(1, $pedidos['Items']);
    }

    public function test_addAlbaranGuardado_guardaElNumeroDePedidoDeLaLinea(): void
    {
        $idArticulo = $this->siembra->articulo('Producto que viene de un pedido');

        $this->albaranes->AddAlbaranGuardado($this->datosDeGuardado([
            $this->linea($idArticulo, ['Numpedcli' => 4321]),
        ]), 0);

        $idAlbaran = (int) $this->ultimoAlbaranDe($this->siembra->clientePorDefecto())['id'];
        self::assertSame('4321', $this->albaranes->ProductosAlbaran($idAlbaran)[0]['NumpedCli']);
    }

    /**
     * Hallazgo de contrato, documentado sin corregir: la linea admite dos grafias distintas
     * del mismo campo y, si llegan las dos, se queda con una sin avisar.
     *
     * `AddAlbaranGuardado()` lee `Numpedcli` y acto seguido `NumpedCli` sobre la misma
     * variable, de modo que la segunda pisa a la primera. Que existan las dos comprobaciones
     * indica que el dato llega escrito de las dos maneras segun por donde entre la linea;
     * mientras solo llegue una, funciona por casualidad. Correccion propuesta: fijar una
     * sola grafia en el contrato de la linea y normalizar en el punto de entrada. Evidencia:
     * este test, que fija cual gana hoy.
     */
    public function test_addAlbaranGuardado_conLasDosGrafiasDelNumeroDePedidoSeQuedaConLaSegunda(): void
    {
        $idArticulo = $this->siembra->articulo('Producto con las dos grafias');

        $this->albaranes->AddAlbaranGuardado($this->datosDeGuardado([
            $this->linea($idArticulo, ['Numpedcli' => 1111, 'NumpedCli' => 2222]),
        ]), 0);

        $idAlbaran = (int) $this->ultimoAlbaranDe($this->siembra->clientePorDefecto())['id'];
        self::assertSame('2222', $this->albaranes->ProductosAlbaran($idAlbaran)[0]['NumpedCli']);
    }

    public function test_addAlbaranGuardado_restaDeLasExistenciasLoQueSaleEnCadaLinea(): void
    {
        $idArticulo = $this->siembra->articulo('Producto que mueve existencias');
        $this->siembra->existenciaRegistrada($idArticulo, 10.0);

        $this->albaranes->AddAlbaranGuardado($this->datosDeGuardado([
            $this->linea($idArticulo, ['ncant' => 3.0]),
        ]), 0);

        self::assertSame(7.0, $this->existenciasDe($idArticulo));
    }

    /**
     * Defecto: el guardado escribe cabecera, lineas, existencias, desglose de ivas y
     * adjuntos como escrituras sueltas, sin transaccion que las envuelva, de modo que un
     * fallo en mitad de la secuencia deja confirmado todo lo anterior.
     *
     * Sintoma: al guardar un albaran de dos lineas cuya segunda no se puede escribir, la
     * cabecera queda creada, la primera linea queda escrita y sus existencias ya
     * descontadas, mientras que la segunda linea no existe: un albaran real, visible en el
     * listado, con menos mercancia de la que el operador dio por guardada. Causa raiz:
     * `AddAlbaranGuardado()` no abre transaccion ni la deshace; cada `INSERT` se confirma
     * por su cuenta y el fallo solo corta el bucle con `break`. Correccion propuesta:
     * envolver la secuencia completa —incluido el movimiento de existencias— en una
     * transaccion, y
     * deshacerla ante cualquier fallo antes de responder al llamador. Evidencia: este test,
     * que comprueba el estado a medias que hoy queda en la base.
     *
     * El fallo se provoca con una descripcion que lleva una comilla doble, que es tambien
     * un defecto por si mismo: la sentencia se arma concatenando el texto entre comillas
     * dobles sin escaparlo, asi que un producto llamado —por ejemplo— `Cable 1" macho`
     * rompe la insercion de su linea. No hace falta una entrada rebuscada para llegar aqui.
     */
    public function test_defecto_addAlbaranGuardado_fallaEnLaSegundaLineaYConfirmaLaPrimera(): void
    {
        $primero = $this->siembra->articulo('Producto que si se escribe');
        $segundo = $this->siembra->articulo('Producto que rompe la sentencia');
        $this->siembra->existenciaRegistrada($primero, 10.0);

        $lanzada = null;
        try {
            $this->albaranes->AddAlbaranGuardado($this->datosDeGuardado([
                $this->linea($primero, ['ncant' => 4.0]),
                $this->linea($segundo, ['cdetalle' => 'Cable 1" macho']),
            ]), 0);
        } catch (\mysqli_sql_exception $e) {
            $lanzada = $e;
        }

        self::assertNotNull($lanzada, 'La segunda linea debe romper la insercion.');

        $idAlbaran = (int) $this->ultimoAlbaranDe($this->siembra->clientePorDefecto())['id'];
        self::assertCount(
            1,
            $this->albaranes->ProductosAlbaran($idAlbaran),
            'La primera linea queda confirmada pese a que el guardado no llego a terminar.'
        );
        self::assertSame(
            6.0,
            $this->existenciasDe($primero),
            'Las existencias de la primera linea ya se descontaron y nadie las devuelve.'
        );
    }

    public function test_eliminarAlbaranTablas_borraCabeceraLineasEIvas(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de albaran que se borra');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 2.0, '2026-01-10');

        $this->albaranes->eliminarAlbaranTablas($idAlbaran);

        self::assertSame([], $this->albaranes->datosAlbaran($idAlbaran));
        self::assertSame([], $this->albaranes->ProductosAlbaran($idAlbaran));
        self::assertSame([], $this->albaranes->IvasAlbaran($idAlbaran));
    }

    public function test_eliminarAlbaranTablas_devuelveALasExistenciasLoQueElAlbaranSaco(): void
    {
        $idArticulo = $this->siembra->articulo('Producto que vuelve al stock');
        $this->siembra->existenciaRegistrada($idArticulo, 10.0);
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 3.0, '2026-01-10');

        $this->albaranes->eliminarAlbaranTablas($idAlbaran);

        self::assertSame(13.0, $this->existenciasDe($idArticulo));
    }

    /**
     * Defecto: borrar un albaran que no existe no se distingue de borrar uno que si
     * existia.
     *
     * Sintoma: `eliminarAlbaranTablas()` sobre un id inexistente devuelve exactamente lo
     * mismo —un array vacio— que sobre uno que borro cuatro tablas. Causa raiz: ningun
     * `DELETE` ni `UPDATE` de la clase comprueba cuantas filas afecto; la respuesta solo
     * distingue "hubo error de SQL" de "no lo hubo", nunca "no habia nada que borrar".
     * Afecta igual a `ModificarEstadoAlbaran()`, `EliminarRegistroTemporal()`,
     * `addNumRealTemporal()`, `modTotales()` y `modificarFecha()`. Correccion propuesta:
     * que las operaciones de escritura devuelvan cuantas filas tocaron y que el llamador
     * decida, en vez de suponer que el silencio es exito. Evidencia: este test, que fija
     * que hoy ambos casos son indistinguibles.
     */
    public function test_defecto_eliminarAlbaranTablas_noDistingueUnAlbaranInexistente(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de albaran existente');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');

        $sobreExistente = $this->albaranes->eliminarAlbaranTablas($idAlbaran);
        $sobreInexistente = $this->albaranes->eliminarAlbaranTablas(999999999);

        self::assertSame($sobreExistente, $sobreInexistente);
    }

    /**
     * Defecto: reguardar un albaran ya existente lo borra entero antes de volver a
     * escribirlo, de modo que un fallo al reescribir sus lineas lo deja convertido en un
     * albaran vacio que conserva su numero y su importe.
     *
     * Sintoma: tras la secuencia que `albaran.php` encadena al pulsar Guardar sobre un
     * albaran que ya tenia numero —`eliminarAlbaranTablas()` primero,
     * `AddAlbaranGuardado()` despues—, si la reescritura falla al llegar a las lineas, la
     * cabecera se ha reinsertado con su total, pero el albaran se queda sin ninguna linea
     * y sin desglose de ivas. En el listado aparece como un albaran normal, con su numero
     * y su importe; al abrirlo no tiene mercancia. Ademas el borrado ya habia devuelto las
     * existencias y la reescritura no llego a descontarlas, asi que el stock queda sumado
     * sin documento que lo justifique. Causa raiz: el borrado y la reescritura son
     * operaciones independientes, sin transaccion comun y sin copia previa; nada las ata.
     * Este es el mecanismo por el que un albaran se pierde durante su propio guardado.
     * Correccion propuesta: la misma transaccion envolvente que el caso anterior, extendida
     * a la secuencia completa —borrado incluido—, y no borrar hasta tener la reescritura
     * confirmada. Evidencia: este test.
     */
    public function test_defecto_reguardarUnAlbaranQueFallaLoDejaSinLineasConSuImporte(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de albaran reguardado');
        $this->siembra->existenciaRegistrada($idArticulo, 10.0);
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 2.0, '2026-01-10');

        $this->albaranes->eliminarAlbaranTablas($idAlbaran);
        $lanzada = null;
        try {
            $this->albaranes->AddAlbaranGuardado($this->datosDeGuardado([
                $this->linea($idArticulo, ['cdetalle' => 'Rotulo 2" ancho']),
            ]), $idAlbaran);
        } catch (\mysqli_sql_exception $e) {
            $lanzada = $e;
        }

        self::assertNotNull($lanzada, 'La reescritura debe fallar para llegar al estado que este caso documenta.');
        self::assertSame(
            '12.10',
            $this->albaranes->datosAlbaran($idAlbaran)['total'],
            'La cabecera vuelve a existir, con el importe que el operador dio por guardado.'
        );
        self::assertSame([], $this->albaranes->ProductosAlbaran($idAlbaran), 'Pero el albaran no tiene ninguna linea.');
        self::assertSame([], $this->albaranes->IvasAlbaran($idAlbaran), 'Ni desglose de ivas.');
        self::assertSame(12.0, $this->existenciasDe($idArticulo), 'Y el stock quedo devuelto sin documento que lo justifique.');
    }

    /**
     * Si la base rechaza la cabecera reescrita, el albaran desaparece entero.
     *
     * Es la otra salida del caso anterior: alli la reescritura falla en una linea y la cabecera
     * vuelve a existir sin ellas; aqui falla en la propia cabecera, y del albaran no queda nada.
     * Las lineas, el desglose y la relacion con sus pedidos ya se habian borrado, y las
     * existencias ya se habian devuelto. Solo sobrevive el borrador, que la pantalla borra
     * despues de guardar y aqui no llega a borrar.
     *
     * Es el mecanismo del issue TPVFox #164 —el albaran se borra si la sesion ha caducado—. Sin
     * sesion, el usuario es el invitado, de identificador 0, y hasta que la pantalla paso a
     * conservar al creador del documento era ese 0 el que se escribia como creador: la clave
     * ajena contra los usuarios rechazaba la cabecera. Hoy la sesion caducada ya no llega aqui,
     * porque el creador se toma del albaran que existe; cualquier otro rechazo de la cabecera, si.
     *
     * @group defecto
     * @group albaran
     * @group guardado
     * @group critico
     *
     * @que-ocurre-hoy Si la base rechaza la cabecera al volver a guardar un albaran, el albaran
     *   desaparece del listado con sus lineas, y las existencias quedan devueltas.
     * @que-deberia-ocurrir Que el albaran quede como estaba si su reescritura no se completa.
     * @por-que-ocurre Guardar un albaran que existe lo borra entero y despues lo vuelve a
     *   escribir, sin transaccion que deshaga el borrado si la escritura falla.
     * @como-deberia-funcionar No borrar el albaran hasta tener asegurada su reescritura, dentro
     *   de una misma transaccion.
     *
     * @codigo-afectado modulos/mod_venta/clases/albaranesVentas.php:33-38
     */
    public function test_defecto_siLaBaseRechazaLaCabeceraReescritaElAlbaranDesapareceEntero(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de albaran con cabecera rechazada');
        $this->siembra->existenciaRegistrada($idArticulo, 10.0);
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 2.0, '2026-01-10');

        $this->albaranes->eliminarAlbaranTablas($idAlbaran);
        $lanzada = null;
        try {
            // El usuario 0 es el invitado: el que la pantalla escribia como creador sin sesion.
            $this->albaranes->AddAlbaranGuardado(
                ['idUsuario' => 0] + $this->datosDeGuardado([$this->linea($idArticulo)]),
                $idAlbaran
            );
        } catch (\mysqli_sql_exception $e) {
            $lanzada = $e;
        }

        self::assertNotNull($lanzada, 'La base rechaza la cabecera...');
        self::assertStringContainsStringIgnoringCase('foreign key', $lanzada->getMessage(), '... por la clave ajena del usuario...');
        self::assertSame([], $this->albaranes->datosAlbaran($idAlbaran), '... y el albaran ya no existe...');
        self::assertSame([], $this->albaranes->ProductosAlbaran($idAlbaran), '... ni sus lineas...');
        self::assertSame(12.0, $this->existenciasDe($idArticulo), '... y las existencias quedaron devueltas.');
    }

    /**
     * Un albaran sin cliente lo rechaza la base al guardarlo, no la pantalla antes.
     *
     * Es el final del borrador sin cliente (`AlbaranesVentasTemporalIntegracionTest`): la
     * pantalla lo abre y ofrece guardarlo, y al pulsar Guardar la cabecera choca con la clave
     * ajena de `albclit` contra el cliente. Lo que el operador recibe es la excepcion de la
     * base. Mismo caso que el de la factura.
     *
     * @group defecto
     * @group albaran
     * @group guardado
     * @group validacion
     * @group alto
     *
     * @que-ocurre-hoy Guardar un albaran sin cliente termina en un error de la base, despues de
     *   que la pantalla haya ofrecido guardarlo.
     * @que-deberia-ocurrir Que la pantalla no ofrezca guardar un albaran sin cliente, o que el
     *   servidor lo rechace con un mensaje del sistema antes de escribir.
     * @por-que-ocurre Ninguna comprobacion del producto mira que haya cliente antes de escribir
     *   la cabecera: lo hace la clave ajena de la tabla, al final.
     * @como-deberia-funcionar Comprobar el cliente antes de escribir y responder con un mensaje.
     *
     * @codigo-afectado modulos/mod_venta/clases/albaranesVentas.php:47-52
     */
    public function test_defecto_unAlbaranSinClienteLoRechazaLaBaseAlGuardarloNoLaPantallaAntes(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de albaran sin cliente');
        $datos = ['idCliente' => 0] + $this->datosDeGuardado([$this->linea($idArticulo)]);

        $this->expectException(\mysqli_sql_exception::class);
        $this->expectExceptionMessageMatches('/foreign key constraint fails/i');

        $this->albaranes->AddAlbaranGuardado($datos, 0);
    }

    /**
     * El albaran no tiene donde registrar cuando se creo ni cuando se modifico.
     *
     * De un documento de venta interesa saber quien lo creo, quien lo modifico y cuando. Volver
     * a guardar un albaran ya conserva a su creador; las fechas no se pueden escribir, porque
     * `albclit` no declara las columnas que `pedclit` y `facclit` si tienen (issue TPVFox #167).
     * Solo queda `Fecha`, que es la fecha del documento y la cambia el operador.
     *
     * @group defecto
     * @group albaran
     * @group guardado
     * @group alto
     *
     * @que-ocurre-hoy De un albaran no se puede saber cuando se creo ni cuando se modifico por
     *   ultima vez.
     * @que-deberia-ocurrir Que el albaran registre las dos fechas, como el pedido y la factura.
     * @por-que-ocurre La tabla del albaran no tiene columnas para ellas.
     * @como-deberia-funcionar Anadir las dos columnas a la tabla por el sistema de migraciones y
     *   escribirlas al guardar, como ya se hace en el pedido.
     */
    public function test_defecto_elAlbaranNoTieneDondeRegistrarCuandoSeCreoNiCuandoSeModifico(): void
    {
        $columnas = fn (string $tabla): array => array_column(
            $this->db->query("SHOW COLUMNS FROM {$tabla}")->fetch_all(MYSQLI_ASSOC),
            'Field'
        );

        self::assertContains('fechaCreacion', $columnas('pedclit'), 'El pedido las tiene...');
        self::assertContains('fechaModificacion', $columnas('facclit'), '... y la factura tambien...');
        self::assertNotContains('fechaCreacion', $columnas('albclit'), '... pero el albaran no tiene la de creacion...');
        self::assertNotContains('fechaModificacion', $columnas('albclit'), '... ni la de modificacion.');
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

    /** La ultima cabecera escrita para ese cliente, que es la que el caso acaba de crear. */
    private function ultimoAlbaranDe(int $idCliente): array
    {
        return $this->db
            ->query("SELECT id, Numalbcli, estado FROM albclit WHERE idCliente=$idCliente ORDER BY id DESC LIMIT 1")
            ->fetch_assoc();
    }

    /** Las existencias registradas del producto en la tienda por defecto. */
    private function existenciasDe(int $idArticulo): float
    {
        $idTienda = $this->siembra->tiendaPorDefecto();

        return (float) $this->db
            ->query("SELECT stockOn FROM articulosStocks WHERE idArticulo=$idArticulo AND idTienda=$idTienda")
            ->fetch_assoc()['stockOn'];
    }
}
