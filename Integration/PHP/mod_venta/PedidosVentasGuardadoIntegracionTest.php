<?php

/**
 * `PedidosVentas`, la escritura del documento: alta de un pedido con sus lineas y su
 * desglose de impuestos, borrado, y la secuencia que `pedido.php` encadena al pulsar
 * Guardar sobre un pedido que ya existia.
 *
 * El pedido no mueve existencias, asi que aqui no hay inventario que comprobar. Lo que si
 * hay es un documento que se borra entero antes de reescribirse, sin nada que ate las dos
 * operaciones.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVenta;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class PedidosVentasGuardadoIntegracionTest extends CasoIntegracion
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

    // --- Alta de un pedido nuevo ---------------------------------------------

    public function test_addPedidoGuardado_creaLaCabeceraConSuNumeroIgualAlId(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo de alta de pedido');

        $this->pedidos->AddPedidoGuardado($this->datosDeGuardado([$this->linea($idArticulo)]), 0);

        $fila = $this->ultimoPedidoDe($this->siembra->clientePorDefecto());
        self::assertSame($fila['id'], $fila['Numpedcli'], 'El numero de pedido se iguala al id recien creado.');
        self::assertSame('Guardado', $fila['estado']);
    }

    public function test_addPedidoGuardado_escribeUnaLineaPorProductoActivo(): void
    {
        $primero = $this->siembra->articulo('Primer producto del pedido');
        $segundo = $this->siembra->articulo('Segundo producto del pedido');

        $this->pedidos->AddPedidoGuardado($this->datosDeGuardado([
            $this->linea($primero),
            $this->linea($segundo),
        ]), 0);

        self::assertCount(2, $this->pedidos->ProductosPedido($this->idDelUltimoPedido()));
    }

    public function test_addPedidoGuardado_noEscribeLasLineasQueNoEstanActivas(): void
    {
        $activo = $this->siembra->articulo('Producto activo del pedido');
        $retirado = $this->siembra->articulo('Producto retirado del pedido');

        $this->pedidos->AddPedidoGuardado($this->datosDeGuardado([
            $this->linea($activo),
            $this->linea($retirado, ['estadoLinea' => 'Anulado']),
        ]), 0);

        self::assertCount(1, $this->pedidos->ProductosPedido($this->idDelUltimoPedido()));
    }

    public function test_addPedidoGuardado_numeraLasFilasCorrelativamenteSaltandoLasNoActivas(): void
    {
        $primero = $this->siembra->articulo('Primero para numerar filas');
        $descartado = $this->siembra->articulo('Descartado para numerar filas');
        $tercero = $this->siembra->articulo('Tercero para numerar filas');

        $this->pedidos->AddPedidoGuardado($this->datosDeGuardado([
            $this->linea($primero),
            $this->linea($descartado, ['estadoLinea' => 'Anulado']),
            $this->linea($tercero),
        ]), 0);

        $filas = array_column($this->pedidos->ProductosPedido($this->idDelUltimoPedido()), 'nfila');
        self::assertSame(['1', '2'], $filas, 'La linea descartada no consume posicion.');
    }

    public function test_addPedidoGuardado_escribeElDesgloseDeIvas(): void
    {
        $idArticulo = $this->siembra->articulo('Producto con desglose de pedido');

        $this->pedidos->AddPedidoGuardado($this->datosDeGuardado([$this->linea($idArticulo)]), 0);

        $desglose = $this->pedidos->IvasPedidos($this->idDelUltimoPedido());
        self::assertSame('21', $desglose[0]['iva']);
        self::assertSame('2.10', $desglose[0]['importeIva']);
    }

    public function test_addPedidoGuardado_conIdentificadorDadoLoReinsertaConEseMismoId(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de pedido reinsertado');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-10');
        $this->pedidos->eliminarPedidoTablas($idPedido);

        $this->pedidos->AddPedidoGuardado($this->datosDeGuardado([$this->linea($idArticulo)]), $idPedido);

        self::assertSame($idPedido, (int) $this->pedidos->datosPedido($idPedido)['id']);
    }

    // --- Lo que el servidor acepta sin comprobar ------------------------------

    /**
     * El importe del documento se guarda tal como llega, sin contrastarlo con las lineas
     * que se acaban de escribir: el servidor toma `total` del temporal y lo escribe. Un
     * pedido puede quedar guardado con un importe que sus propias lineas no suman.
     */
    public function test_defecto_addPedidoGuardado_guardaElTotalQueRecibeSinContrastarloConSusLineas(): void
    {
        $idArticulo = $this->siembra->articulo('Producto con total inventado');

        $datos = $this->datosDeGuardado([$this->linea($idArticulo)]);
        $datos['total'] = 999.99;
        $this->pedidos->AddPedidoGuardado($datos, 0);

        $idPedido = $this->idDelUltimoPedido();
        self::assertSame('999.99', $this->pedidos->datosPedido($idPedido)['total']);
        self::assertSame('12.10', $this->pedidos->ProductosPedido($idPedido)[0]['precioCiva']);
    }

    /**
     * El estado se escribe tal como llega. `posiblesEstados()` declara los tres validos,
     * pero nada contrasta contra ese catalogo en el punto de escritura, de modo que el
     * pedido queda en un estado que ninguna pantalla sabe interpretar.
     */
    public function test_defecto_addPedidoGuardado_aceptaUnEstadoQueNoEstaEnElCatalogo(): void
    {
        $idArticulo = $this->siembra->articulo('Producto con estado fuera de catalogo');

        $datos = $this->datosDeGuardado([$this->linea($idArticulo)]);
        $datos['estado'] = 'Inventado';
        $this->pedidos->AddPedidoGuardado($datos, 0);

        self::assertSame('Inventado', $this->pedidos->datosPedido($this->idDelUltimoPedido())['estado']);
    }

    /**
     * El tipo impositivo del desglose es la clave del array que llega del navegador, y se
     * escribe sin contrastarla con ningun catalogo: un tipo que no existe entra igual en
     * `pedcliIva` y desde ahi al total de impuestos del listado.
     */
    public function test_defecto_addPedidoGuardado_escribeUnTipoImpositivoQueNoExiste(): void
    {
        $idArticulo = $this->siembra->articulo('Producto con iva inexistente');

        $datos = $this->datosDeGuardado([$this->linea($idArticulo)]);
        $datos['DatosTotales'] = ['desglose' => ['77' => ['iva' => 7.70, 'base' => 10.00]]];
        $this->pedidos->AddPedidoGuardado($datos, 0);

        self::assertSame('77', $this->pedidos->IvasPedidos($this->idDelUltimoPedido())[0]['iva']);
    }

    // --- Lo que ocurre cuando el guardado falla a medias -----------------------

    /**
     * Defecto: el guardado no es atomico. Si una linea falla, las anteriores quedan
     * confirmadas y la cabecera tambien.
     *
     * Sintoma: tras un fallo en la segunda linea, el pedido existe con su importe completo
     * y una sola linea. Causa raiz: `AddPedidoGuardado()` encadena inserciones
     * independientes, acumula los errores en un array y sigue o corta, pero nunca deshace
     * lo ya escrito; no hay transaccion envolvente. Correccion propuesta: abrir transaccion
     * al entrar y deshacerla ante cualquier fallo antes de responder al llamador.
     * Evidencia: este test, que fija el estado a medias que hoy queda en la base.
     *
     * El fallo se provoca con una descripcion que lleva una comilla doble, que es tambien
     * un defecto por si mismo: la sentencia se arma concatenando el texto entre comillas
     * dobles sin escaparlo, asi que un producto llamado `Cable 1" macho` rompe la insercion
     * de su linea.
     */
    public function test_defecto_addPedidoGuardado_fallaEnLaSegundaLineaYConfirmaLaPrimera(): void
    {
        $primero = $this->siembra->articulo('Producto de pedido que si se escribe');
        $segundo = $this->siembra->articulo('Producto de pedido que rompe la sentencia');

        $lanzada = null;
        try {
            $this->pedidos->AddPedidoGuardado($this->datosDeGuardado([
                $this->linea($primero),
                $this->linea($segundo, ['cdetalle' => 'Cable 1" macho']),
            ]), 0);
        } catch (\mysqli_sql_exception $e) {
            $lanzada = $e;
        }

        self::assertNotNull($lanzada, 'La segunda linea debe romper la insercion.');

        $idPedido = $this->idDelUltimoPedido();
        self::assertCount(
            1,
            $this->pedidos->ProductosPedido($idPedido),
            'La primera linea queda confirmada pese a que el guardado no llego a terminar.'
        );
        self::assertSame(
            '12.10',
            $this->pedidos->datosPedido($idPedido)['total'],
            'Y la cabecera conserva el importe del documento entero.'
        );
    }

    /**
     * Defecto: reguardar un pedido ya existente lo borra entero antes de volver a
     * escribirlo, de modo que un fallo al reescribir sus lineas lo deja convertido en un
     * pedido vacio que conserva su numero y su importe.
     *
     * Sintoma: tras la secuencia que `pedido.php` encadena al pulsar Guardar sobre un
     * pedido que ya tenia numero —`eliminarPedidoTablas()` primero, `AddPedidoGuardado()`
     * despues—, si la reescritura falla al llegar a las lineas, la cabecera se ha
     * reinsertado con su total pero el pedido se queda sin ninguna linea y sin desglose. En
     * el listado aparece como un pedido normal, con su numero y su importe; al abrirlo no
     * tiene mercancia, y lo que el cliente pidio ya no consta en ningun sitio. Causa raiz:
     * el borrado y la reescritura son operaciones independientes, sin transaccion comun y
     * sin copia previa. Correccion propuesta: transaccion envolvente sobre la secuencia
     * completa, borrado incluido, y no borrar hasta tener la reescritura confirmada.
     * Evidencia: este test.
     */
    public function test_defecto_reguardarUnPedidoQueFallaLoDejaSinLineasConSuImporte(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de pedido reguardado');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 2.0, '2026-01-10');

        $this->pedidos->eliminarPedidoTablas($idPedido);
        $lanzada = null;
        try {
            $this->pedidos->AddPedidoGuardado($this->datosDeGuardado([
                $this->linea($idArticulo, ['cdetalle' => 'Rotulo 2" ancho']),
            ]), $idPedido);
        } catch (\mysqli_sql_exception $e) {
            $lanzada = $e;
        }

        self::assertNotNull($lanzada, 'La reescritura debe fallar para llegar al estado que este caso documenta.');
        self::assertSame(
            '12.10',
            $this->pedidos->datosPedido($idPedido)['total'],
            'La cabecera vuelve a existir, con el importe que el operador dio por guardado.'
        );
        self::assertSame([], $this->pedidos->ProductosPedido($idPedido), 'Pero el pedido no tiene ninguna linea.');
        self::assertSame([], $this->pedidos->IvasPedidos($idPedido), 'Ni desglose de impuestos.');
    }

    // --- Borrado ----------------------------------------------------------------

    public function test_eliminarPedidoTablas_borraCabeceraLineasEIvas(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de pedido que se borra');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 2.0, '2026-01-10');

        $this->pedidos->eliminarPedidoTablas($idPedido);

        self::assertSame([], $this->pedidos->datosPedido($idPedido));
        self::assertSame([], $this->pedidos->ProductosPedido($idPedido));
        self::assertSame([], $this->pedidos->IvasPedidos($idPedido));
    }

    /**
     * Defecto: borrar un pedido que no existe no se distingue de borrar uno que si existia.
     *
     * Sintoma: `eliminarPedidoTablas()` sobre un id inexistente devuelve exactamente lo
     * mismo —un array vacio— que sobre uno que borro tres tablas. Causa raiz: ningun
     * `DELETE` ni `UPDATE` de la clase comprueba cuantas filas afecto; la respuesta solo
     * distingue "hubo error de SQL" de "no lo hubo", nunca "no habia nada que borrar".
     * Afecta igual a `ModificarEstadoPedido()`, `EliminarRegistroTemporal()`,
     * `addNumRealTemporal()`, `modTotales()` y `modificarFecha()`. Correccion propuesta: que
     * las operaciones de escritura devuelvan cuantas filas tocaron y que el llamador decida,
     * en vez de suponer que el silencio es exito. Evidencia: este test.
     */
    public function test_defecto_eliminarPedidoTablas_noDistingueUnPedidoInexistente(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de pedido existente');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-10');

        $sobreExistente = $this->pedidos->eliminarPedidoTablas($idPedido);
        $sobreInexistente = $this->pedidos->eliminarPedidoTablas(999999999);

        self::assertSame($sobreExistente, $sobreInexistente);
    }

    /**
     * Defecto: borrar un pedido que un albaran ya esta sirviendo lo vacia sin llegar a
     * borrarlo.
     *
     * Sintoma: el borrado ejecuta tres sentencias en orden —lineas, desglose y cabecera—.
     * La clave ajena de `pedcliAlb` contra `pedclit` impide la tercera, pero las dos
     * primeras ya se confirmaron: el pedido sobrevive con su numero, su cliente y su
     * importe, y sin una sola linea. El albaran sigue enlazado a un pedido que ya no dice
     * lo que se pidio. Causa raiz: la misma que el reguardado — tres sentencias
     * independientes sin transaccion, ejecutadas de la mas dependiente a la menos, de modo
     * que el guardian de integridad actua cuando el dano ya esta hecho. Correccion
     * propuesta: transaccion envolvente, y comprobar antes de borrar que el pedido no esta
     * servido. Evidencia: este test.
     */
    public function test_defecto_borrarUnPedidoServidoLoVaciaSinLlegarABorrarlo(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de pedido servido');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 2.0, '2026-01-05');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 2.0, '2026-01-10');
        $this->siembra->adjuntarPedidoAAlbaran($idAlbaran, $idPedido);

        $lanzada = null;
        try {
            $this->pedidos->eliminarPedidoTablas($idPedido);
        } catch (\mysqli_sql_exception $e) {
            $lanzada = $e;
        }

        self::assertNotNull($lanzada, 'La clave ajena impide borrar la cabecera.');
        self::assertNotSame([], $this->pedidos->datosPedido($idPedido), 'El pedido sigue existiendo...');
        self::assertSame([], $this->pedidos->ProductosPedido($idPedido), '... pero se ha quedado sin lineas.');
        self::assertSame([], $this->pedidos->IvasPedidos($idPedido), 'Y sin desglose de impuestos.');
    }


    // --- Casos abiertos por las matrices de condiciones de test ----------------

    /**
     * Un pedido sin ninguna linea se guarda igual: queda la cabecera con su numero y su
     * importe, indistinguible en el listado de un pedido real. Nada comprueba que un
     * documento de venta tenga mercancia.
     */
    public function test_defecto_guardarSinNingunaLineaCreaLaCabeceraIgualmente(): void
    {
        $antes = (int) $this->db->query('SELECT COUNT(*) as n FROM pedclit')->fetch_assoc()['n'];

        $this->pedidos->AddPedidoGuardado($this->datosDeGuardado([]), 0);

        $despues = (int) $this->db->query('SELECT COUNT(*) as n FROM pedclit')->fetch_assoc()['n'];
        self::assertSame($antes + 1, $despues, 'La cabecera se escribe aunque no haya nada que pedir.');

        $idPedido = $this->idDelUltimoPedido();
        self::assertSame([], $this->pedidos->ProductosPedido($idPedido));
        self::assertSame('12.10', $this->pedidos->datosPedido($idPedido)['total']);
    }

    /**
     * El mismo articulo en dos lineas se escribe dos veces, sin aviso: un descuido del
     * operador duplica lo pedido y nada lo senala.
     */
    public function test_defecto_elMismoArticuloEnDosLineasSeEscribeComoDosLineas(): void
    {
        $idArticulo = $this->siembra->articulo('Producto repetido en el pedido');

        $this->pedidos->AddPedidoGuardado($this->datosDeGuardado([
            $this->linea($idArticulo),
            $this->linea($idArticulo),
        ]), 0);

        $lineas = $this->pedidos->ProductosPedido($this->idDelUltimoPedido());
        self::assertCount(2, $lineas);
        self::assertSame($lineas[0]['idArticulo'], $lineas[1]['idArticulo']);
    }

    /**
     * Descartar una linea no se comunica: la respuesta del guardado es la misma con todas
     * las lineas activas que con una descartada. El operador cree haber pedido lo que ve en
     * pantalla, y lo guardado es menos.
     */
    public function test_defecto_laLineaDescartadaNoSeComunicaEnLaRespuesta(): void
    {
        $activo = $this->siembra->articulo('Producto activo sin aviso');
        $descartado = $this->siembra->articulo('Producto descartado sin aviso');

        $conTodas = $this->pedidos->AddPedidoGuardado($this->datosDeGuardado([
            $this->linea($activo),
        ]), 0);
        $conDescarte = $this->pedidos->AddPedidoGuardado($this->datosDeGuardado([
            $this->linea($activo),
            $this->linea($descartado, ['estadoLinea' => 'Anulado']),
        ]), 0);

        self::assertSame($conTodas, $conDescarte, 'Las dos respuestas son indistinguibles.');
        self::assertSame([], $conDescarte);
    }

    /**
     * El borrador guarda seis decimales y la cabecera dos: el importe se redondea al
     * escribirse, sin que nada lo declare ni lo compruebe contra las lineas.
     */
    public function test_defecto_elImporteSeRedondeaAlPasarDelBorradorALaCabecera(): void
    {
        $idArticulo = $this->siembra->articulo('Producto con decimales largos');

        $datos = $this->datosDeGuardado([$this->linea($idArticulo)]);
        $datos['total'] = 12.126789;
        $this->pedidos->AddPedidoGuardado($datos, 0);

        self::assertSame('12.13', $this->pedidos->datosPedido($this->idDelUltimoPedido())['total']);
    }

    /**
     * Reguardar un pedido servido: la clase no opone nada. La proteccion es de pantalla, y
     * el estado que queda es el que traiga el borrador, no el que el documento tenia.
     */
    public function test_defecto_laClaseNoImpideReguardarUnPedidoYaServido(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de pedido servido reguardado');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-05', ['estado' => 'Procesado']);
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $this->siembra->adjuntarPedidoAAlbaran($idAlbaran, $idPedido);

        // El borrado previo que la pantalla haria falla por la clave ajena; la reescritura
        // corre igualmente, que es lo que este caso documenta.
        try {
            $this->pedidos->eliminarPedidoTablas($idPedido);
        } catch (\mysqli_sql_exception) {
            // Documentado en test_defecto_borrarUnPedidoServidoLoVaciaSinLlegarABorrarlo.
        }

        $datos = $this->datosDeGuardado([$this->linea($idArticulo)]);
        $this->pedidos->AddPedidoGuardado($datos, 0);

        self::assertSame(
            'Procesado',
            $this->pedidos->getEstado($idPedido),
            'El pedido servido sigue existiendo, ya vaciado, y su estado no lo protegio de nada.'
        );
    }

    /**
     * Tras un borrado rechazado por la clave ajena, la relacion con el albaran queda
     * intacta: el albaran sigue diciendo que sirve un pedido que ya no tiene lineas.
     */
    public function test_defecto_laRelacionSobreviveIntactaAUnBorradoRechazado(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de relacion superviviente');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-01-05');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $this->siembra->adjuntarPedidoAAlbaran($idAlbaran, $idPedido);

        try {
            $this->pedidos->eliminarPedidoTablas($idPedido);
        } catch (\mysqli_sql_exception) {
            // El rechazo es el objeto del caso anterior; aqui interesa lo que queda.
        }

        $relacion = $this->pedidos->NumAlbaranDePedido($idPedido);
        self::assertSame($idAlbaran, (int) $relacion['idAlbaran']);
        self::assertSame([], $this->pedidos->ProductosPedido($idPedido), 'Y el pedido enlazado esta vacio.');
    }

    /**
     * Un texto donde el guardado espera un numero entra sin delimitador en la sentencia y
     * la rompe. No hay tipado en el punto de escritura: la unica defensa es que la sentencia
     * deje de ser valida, que no es una defensa sino un accidente.
     */
    public function test_defecto_unValorDeTextoDondeSeEsperaNumeroRompeLaSentencia(): void
    {
        $idArticulo = $this->siembra->articulo('Producto con cantidad de texto');

        $lanzada = null;
        try {
            $this->pedidos->AddPedidoGuardado($this->datosDeGuardado([
                $this->linea($idArticulo, ['ncant' => 'dos kilos']),
            ]), 0);
        } catch (\mysqli_sql_exception $e) {
            $lanzada = $e;
        }

        self::assertNotNull($lanzada, 'El valor entra crudo en la sentencia y la invalida.');
        self::assertStringContainsString('kilos', $lanzada->getMessage());
    }


    // --- Casos abiertos por la ronda de negocio del FMEA -----------------------

    /**
     * Las dos cantidades de la linea se escriben tal como llegan y ninguna declara que
     * mide. El pedido es el documento del que el albaran copia las cantidades, y es el
     * albaran quien mueve existencias con `ncant`: un pedido de una caja y uno de doce
     * unidades quedan indistinguibles en la base.
     *
     * La semantica pretendida esta documentada fuera del codigo desde 2018 —`ncant` con
     * decimales, `nunidades` entera— y registra que ventas se dejo con las dos iguales.
     * Este caso fija que hoy no hay nada que las relacione ni que las valide.
     */
    public function test_defecto_lasDosCantidadesDeLaLineaSeGuardanSinDeclararQueMiden(): void
    {
        $idArticulo = $this->siembra->articulo('Producto con caja de doce');

        $this->pedidos->AddPedidoGuardado($this->datosDeGuardado([
            $this->linea($idArticulo, ['ncant' => 12.0, 'nunidades' => 1.0]),
        ]), 0);

        $linea = $this->pedidos->ProductosPedido($this->idDelUltimoPedido())[0];

        self::assertSame(12.0, (float) $linea['ncant']);
        self::assertSame(1.0, (float) $linea['nunidades'], 'Se guardan distintas y nada relaciona una con otra.');
        self::assertArrayNotHasKey('tipoCantidad', $linea, 'Y no hay columna que declare que mide ncant.');
    }

    /**
     * Defecto: el estado «sin guardar» viaja en dos grafias.
     *
     * Sintoma: el catalogo de la clase declara `Sin Guardar`, y lo que el navegador escribe
     * —y lo que la pantalla compara— es `Sin guardar`. Son cadenas distintas: contrastar el
     * estado contra el catalogo rechazaria el unico valor que el producto realmente pone, y
     * el filtro del listado, que sale de la tabla, ofrece el que el catalogo no tiene. Causa
     * raiz: la misma familia que las dos grafias del numero de pedido en la linea — el valor
     * se escribe en un sitio y se declara en otro, sin punto que los normalice. Correccion
     * propuesta: una sola grafia, fijada en el catalogo y usada en la escritura.
     * Evidencia: este test, que fija que la grafia declarada no la escribe nadie.
     */
    public function test_defecto_elEstadoSinGuardarViajaEnDosGrafias(): void
    {
        $declarados = array_column($this->pedidos->posiblesEstados(), 'estado');
        self::assertContains('Sin Guardar', $declarados, 'El catalogo declara la grafia con ge mayuscula...');

        $conLaDeclarada = $this->cuantosPedidosConEstado('Sin Guardar');
        $conLaEscrita = $this->cuantosPedidosConEstado('Sin guardar');

        self::assertSame(0, $conLaDeclarada, '... y ningun pedido la lleva.');
        self::assertGreaterThan(0, $conLaEscrita, 'La que se escribe es la de ge minuscula.');
    }

    /** Recuento sensible a mayusculas: la intercalacion de la columna no las distingue. */
    private function cuantosPedidosConEstado(string $estado): int
    {
        return (int) $this->db
            ->query("SELECT COUNT(*) as n FROM pedclit WHERE BINARY estado = '$estado'")
            ->fetch_assoc()['n'];
    }

    /**
     * Defecto: no se puede saber quien creo un pedido, ni quien lo modifico, ni cuando.
     *
     * Sintoma: `pedclit` declara `fechaCreacion` y `fechaModificacion` y el guardado no
     * escribe ninguna de las dos; y `idUsuario` se rellena con el usuario de la sesion que
     * guarda, de modo que reguardar un documento sustituye a su creador. Causa raiz: la
     * insercion enumera nueve columnas y las tres de traza no estan entre ellas. Correccion
     * propuesta: escribir las dos fechas, y conservar el creador separandolo de quien
     * modifica. Las columnas ya existen: la correccion no toca el esquema. Evidencia: este
     * test.
     */
    public function test_defecto_elGuardadoNoDejaTrazaDeQuienCreoNiDeQuienModifico(): void
    {
        $idArticulo = $this->siembra->articulo('Producto sin traza de autoria');

        $this->pedidos->AddPedidoGuardado($this->datosDeGuardado([$this->linea($idArticulo)]), 0);
        $idPedido = $this->idDelUltimoPedido();

        $traza = $this->db
            ->query("SELECT fechaCreacion, fechaModificacion FROM pedclit WHERE id=$idPedido")
            ->fetch_assoc();

        self::assertNull($traza['fechaCreacion'], 'La columna existe en el esquema y nadie la escribe.');
        self::assertNull($traza['fechaModificacion']);

        // Y el usuario que consta es el de quien guarda, no el de quien creo: al reguardar
        // con otro, el original desaparece sin dejar rastro.
        $otroUsuario = (int) $this->db->query('SELECT id FROM usuarios ORDER BY id DESC LIMIT 1')
            ->fetch_assoc()['id'];
        $datos = $this->datosDeGuardado([$this->linea($idArticulo)]);
        $datos['idUsuario'] = $otroUsuario;
        $this->pedidos->eliminarPedidoTablas($idPedido);
        $this->pedidos->AddPedidoGuardado($datos, $idPedido);

        self::assertSame(
            $otroUsuario,
            (int) $this->pedidos->datosPedido($idPedido)['idUsuario'],
            'El creador se sustituye por quien reguarda, y no queda constancia de que hubo otro.'
        );
    }

    // --- Apoyos ------------------------------------------------------------------

    /** Los datos del temporal tal como `pedido.php` los compone al pulsar Guardar. */
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
    private function ultimoPedidoDe(int $idCliente): array
    {
        return $this->db
            ->query("SELECT id, Numpedcli, estado FROM pedclit WHERE idCliente=$idCliente ORDER BY id DESC LIMIT 1")
            ->fetch_assoc();
    }

    private function idDelUltimoPedido(): int
    {
        return (int) $this->ultimoPedidoDe($this->siembra->clientePorDefecto())['id'];
    }
}
