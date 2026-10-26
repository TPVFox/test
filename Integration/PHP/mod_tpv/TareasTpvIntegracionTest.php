<?php

/**
 * La petición de fondo del TPV (`modulos/mod_tpv/tareas.php`, con los ficheros de `tareas/`): buscar
 * productos y clientes, grabar el ticket temporal, la ventana de cobrar, cobrar el ticket —grabarlo
 * cobrado, con su número, sus líneas, su desglose y la resta del stock, e imprimirlo—, la configuración
 * del usuario, la configuración base, el registro del stock descontado en la web y las incidencias.
 *
 * Cada petición se ejecuta en un PHP aparte, desde la carpeta del módulo, con la sesión del usuario de
 * pruebas o sin ninguna. Tras el arranque común se abre una transacción en su conexión, y las clases
 * escriben por esa misma conexión: lo que siembra el caso y lo que guarda la petición se deshace al
 * terminar el proceso. Antes de terminar, el proceso escribe los tickets temporales y cobrados del caso,
 * los índices del usuario, el stock de los productos sembrados, el registro del stock de la web, la
 * configuración y las incidencias.
 *
 * La clase de tickets abre su propia conexión, fuera de la transacción: solo se usa aquí para leer el
 * cliente al imprimir, que es uno de los ya existentes. La impresora es un fichero de una carpeta del
 * caso, que se borra al terminar; ninguna petición usa una dirección de red ni guarda el fichero de
 * parámetros del módulo.
 *
 * La preparación es una lista de sentencias; cada alta de un producto queda con un nombre —`:a1`,
 * `:a2`…—, y lo mismo las de clientes, familias y tiendas —`:c1`, `:f1`, `:t1`…—, que las sentencias
 * siguientes y el formulario pueden usar, como `:usuario`, `:tienda` y `:cliente`. Reimprimir y preparar
 * el stock de la web leen con la clase de tickets un ticket cerrado de los que ya hay en la base. Salvo que el caso diga lo contrario, el usuario tiene sus índices: el último ticket
 * cobrado es el 40 y el último temporal el 7.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModTpv;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class TareasTpvIntegracionTest extends CasoIntegracion
{
    private int $idUsuario;

    private int $idTienda;

    private int $idCliente;

    private string $carpeta;

    protected function setUp(): void
    {
        parent::setUp();
        $siembra = new Siembra($this->db);
        $this->idUsuario = $siembra->usuarioPorDefecto();
        $this->idTienda = $siembra->tiendaPorDefecto();
        $this->idCliente = $siembra->clientePorDefecto();
        $this->carpeta = sys_get_temp_dir() . '/tpv-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->carpeta, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->carpeta . '/*') ?: [] as $fichero) {
            unlink($fichero);
        }
        rmdir($this->carpeta);
        parent::tearDown();
    }

    /** @comportamiento Grabar un ticket nuevo le da el siguiente número temporal del usuario, lo deja actual con su total recalculado, y responde el desglose. */
    public function test_grabarUnTicketNuevoLeDaElSiguienteNumeroTemporal(): void
    {
        $peticion = $this->ejecutar($this->grabar('Nuevo', 0, [$this->linea(':a1', 2, '1.10'), $this->linea(':a2', 1, '12.10', ['ctipoiva' => '21.00', 'nfila' => 2])]), [$this->producto('Pera'), $this->producto('Vino')]);

        self::assertSame([['8', 'Actual', '14.300000']], array_map(static fn (array $t): array => [$t['numticket'], $t['estadoTicket'], $t['total']], $peticion['temporales']));
        self::assertSame(['40', '8'], [$peticion['indices'][0]['numticket'], $peticion['indices'][0]['tempticket']]);
        self::assertSame(['Actual', 8, '14.30'], [$peticion['respuesta']['estadoTicket'], $peticion['respuesta']['NumeroTicket'], $peticion['respuesta']['total']]);
        self::assertStringContainsString('<td class="tipo_21">21%</td><td class="base">10.00</td><td class="importe_iva">2.10</td>', $peticion['respuesta']['html']);
        self::assertCount(2, json_decode($peticion['temporales'][0]['Productos'], true));
    }

    /** @comportamiento Grabar un ticket abierto cambia sus productos, su cliente y su total, sin gastar número. */
    public function test_grabarUnTicketAbiertoLoCambia(): void
    {
        $peticion = $this->ejecutar($this->grabar('Abierto', 5, [$this->linea(':a1', 3, '1.10')]), [$this->producto('Pera'), $this->temporal(5, 'Abierto', [$this->linea(':a1', 1, '1.10')])]);

        self::assertSame([['5', 'Abierto', '3.300000']], array_map(static fn (array $t): array => [$t['numticket'], $t['estadoTicket'], $t['total']], $peticion['temporales']));
        self::assertNotNull($peticion['temporales'][0]['fechaFinal']);
        self::assertSame('7', $peticion['indices'][0]['tempticket']);
    }

    /** @comportamiento La ventana de cobrar lleva el total que manda la página y el recalculado de sus productos. */
    public function test_laVentanaDeCobrarLlevaElTotalRecalculado(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'cobrar', 'total' => '3.30', 'productos' => json_encode([$this->linea('5', 3, '1.10')]), 'configuracion' => ['impresion_ticket' => 'No']]);

        self::assertStringContainsString('<h1>3.30<span class="small"> €</span></h1>', $peticion['respuesta']['html']);
        self::assertSame('3.30', $peticion['respuesta']['recalculo']['total']);
    }

    /**
     * @comportamiento Cobrar un ticket lo graba cobrado con el siguiente número del usuario: la cabecera con
     *   su cliente, forma de pago, entregado y total; todas sus líneas, las eliminadas también; el desglose
     *   por IVA; el temporal queda cobrado, y el stock de la tienda se resta de las líneas activas.
     */
    public function test_cobrarUnTicketLoGrabaCobrado(): void
    {
        $peticion = $this->ejecutar($this->cerrar('14.30'), $this->conTicketDeTresLineas());

        self::assertSame([['41', '8', 'Cobrado', 'tarjeta', '20.00', '14.30']], array_map(static fn (array $t): array => [$t['Numticket'], $t['Numtempticket'], $t['estado'], $t['formaPago'], $t['entregado'], $t['total']], $peticion['tickets']));
        self::assertSame((string) $this->idCliente, $peticion['tickets'][0]['idCliente']);
        self::assertSame([[':a1', 'Pera', '2.000000', '1.10', '10.00', 'Activo'], [':a2', 'Vino', '1.000000', '12.10', '21.00', 'Activo'], [':a1', 'Pera', '5.000000', '1.10', '10.00', 'Eliminado']], $this->lineas($peticion));
        self::assertSame([['10', '0.20', '2.00'], ['21', '2.10', '10.00']], array_map(static fn (array $i): array => [$i['iva'], $i['importeIva'], $i['totalbase']], $peticion['ivas']));
        self::assertSame('Cobrado', $peticion['temporales'][0]['estadoTicket']);
        self::assertSame('41', $peticion['indices'][0]['numticket']);
        self::assertSame([[':a1', '-2.000000'], [':a2', '-1.000000']], $this->stocks($peticion));
        self::assertArrayNotHasKey('error', $peticion['respuesta'] ?? []);
    }

    /**
     * @comportamiento Una línea sin código de barras ni referencia se cobra entera: el producto encontrado
     *   directamente los trae nulos, y el elegido de la lista, vacíos. Se graban la cabecera, las dos
     *   líneas con el código y la referencia vacíos, el desglose, el temporal cobrado y el stock.
     */
    public function test_unaLineaSinCodigoDeBarrasNiReferenciaSeCobraEntera(): void
    {
        $peticion = $this->ejecutar($this->cerrar('14.30'), [
            $this->producto('Pera'),
            $this->producto('Vino', '21.00'),
            $this->temporal(8, 'Actual', [$this->linea(':a1', 2, '1.10', ['ccodebar' => null, 'cref' => null]), $this->linea(':a2', 1, '12.10', ['cdetalle' => 'Vino', 'ctipoiva' => '21.00', 'nfila' => 2, 'ccodebar' => '', 'cref' => ''])]),
        ]);

        self::assertSame([['41', 'Cobrado', '14.30']], array_map(static fn (array $t): array => [$t['Numticket'], $t['estado'], $t['total']], $peticion['tickets']));
        self::assertSame([[':a1', 'Pera', '2.000000', '1.10', '10.00', 'Activo'], [':a2', 'Vino', '1.000000', '12.10', '21.00', 'Activo']], $this->lineas($peticion));
        self::assertSame([['', ''], ['', '']], array_map(static fn (array $l): array => [$l['cref'], $l['ccodbar']], $peticion['lineas']));
        self::assertSame([['10', '0.20', '2.00'], ['21', '2.10', '10.00']], array_map(static fn (array $i): array => [$i['iva'], $i['importeIva'], $i['totalbase']], $peticion['ivas']));
        self::assertSame('Cobrado', $peticion['temporales'][0]['estadoTicket']);
        self::assertSame([[':a1', '-2.000000'], [':a2', '-1.000000']], $this->stocks($peticion));
        self::assertArrayNotHasKey('error', $peticion['respuesta'] ?? []);
    }

    /** @comportamiento Un ticket que ya está cobrado no se vuelve a cobrar: no se graba otro ni se resta el stock. */
    public function test_unTicketYaCobradoNoSeVuelveACobrar(): void
    {
        $peticion = $this->ejecutar($this->cerrar('2.20'), [$this->producto('Pera'), $this->temporal(8, 'Cobrado', [$this->linea(':a1', 2, '1.10')])]);

        self::assertSame([[], [], '40'], [$peticion['tickets'], $peticion['stocks'], $peticion['indices'][0]['numticket']]);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tpv/tareas/CerrarTicket.php:29-38
     *
     * @group defecto
     * @group calculo
     * @group critico
     *
     * @que-ocurre-hoy Si el total que manda la página no es el de sus productos, el ticket se graba con el
     *   de la página: la cabecera dice un importe y las líneas y el desglose, otro. Solo se avisa.
     * @que-deberia-ocurrir Que el ticket se grabe con el total recalculado, o que no se grabe.
     * @por-que-ocurre El total recalculado solo se compara para avisar; la cabecera se graba con el
     *   recibido.
     * @como-deberia-funcionar Grabar la cabecera con el total recalculado de las líneas.
     */
    public function test_cobrarGrabaElTotalRecalculado(): void
    {
        $peticion = $this->ejecutar($this->cerrar('1.00'), $this->conTicketDeTresLineas());

        self::assertSame('14.30', $peticion['tickets'][0]['total'] ?? null);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tpv/funciones.php:588-721
     *
     * @group defecto
     * @group integridad
     * @group critico
     *
     * @que-ocurre-hoy Un producto con comillas dobles en el nombre rompe la sentencia de las líneas: el
     *   ticket queda grabado sin líneas ni desglose, con su número gastado, el temporal sin cobrar y la
     *   petición sin respuesta.
     * @que-deberia-ocurrir Que el ticket se grabe entero, o que no se grabe nada.
     * @por-que-ocurre Los datos de las líneas se pegan a la sentencia sin escapar, y las escrituras van
     *   sin transacción.
     * @como-deberia-funcionar Pasar los datos como parámetros y grabar el ticket en una transacción.
     */
    public function test_unProductoConComillasSeCobraEntero(): void
    {
        $peticion = $this->ejecutar($this->cerrar('1.10'), [$this->producto('Tornillo'), $this->temporal(8, 'Actual', [$this->linea(':a1', 1, '1.10', ['cdetalle' => 'Tornillo 1/2" inox'])])]);

        self::assertCount(1, $peticion['tickets']);
        self::assertCount(1, $peticion['lineas']);
        self::assertSame('Cobrado', $peticion['temporales'][0]['estadoTicket']);
    }

    /**
     * @comportamiento Con imprimir marcado, cobrar escribe el ticket en la impresora: la tienda, «factura
     *   simplificada», la fecha, la serie y el número, las líneas activas, el desglose, el total, la forma
     *   de pago, lo entregado y el cambio.
     */
    public function test_imprimirAlCobrarEscribeElTicketEnLaImpresora(): void
    {
        touch($this->carpeta . '/impresora');

        $peticion = $this->ejecutar($this->cerrar('14.30', ['checkimprimir' => 'true', 'ruta_impresora' => $this->carpeta . '/impresora']), $this->conTicketDeTresLineas());

        $impreso = (string) file_get_contents($this->carpeta . '/impresora');
        self::assertSame([], array_diff_key($peticion['respuesta'] ?? [], ['grabar' => 0]));
        foreach (['Tienda de pruebas', 'FACTURA  SIMPLIFICADA', 'Serie:' . $this->idTienda . '-' . $this->idUsuario . ' Numero:41', '(id:', ') Pera', ') Vino', '2.00  -> 10.00%  -> 0.20', '14.30', 'tarjeta', '20.00', '5.70'] as $texto) {
            self::assertStringContainsString($texto, $impreso);
        }
        self::assertSame(2, substr_count($impreso, ' X '));
    }

    /** @comportamiento Si la impresora no existe, el ticket se cobra igualmente y la respuesta lo avisa. */
    public function test_sinImpresoraElCobroLoAvisa(): void
    {
        $peticion = $this->ejecutar($this->cerrar('14.30', ['checkimprimir' => 'true', 'ruta_impresora' => $this->carpeta . '/no-existe']), $this->conTicketDeTresLineas());

        self::assertSame(' no existe la impresora asignada, hay un error', $peticion['respuesta']['error_impresora']);
        self::assertCount(1, $peticion['tickets']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tpv/tareas/CerrarTicket.php:16-17,60; modulos/mod_tpv/funciones.php:732-735
     *
     * @group defecto
     * @group seguridad
     * @group critico
     *
     * @que-ocurre-hoy La ruta de la impresora llega en la petición de cobrar y se pega a una orden del
     *   sistema: lo que lleve detrás de un punto y coma se ejecuta en el servidor. Aquí, crear un fichero
     *   en la carpeta del caso.
     * @que-deberia-ocurrir Que la impresora sea la de la configuración, y que comprobarla no ejecute nada.
     * @por-que-ocurre La petición toma la ruta del formulario y la comprueba con `shell_exec('ls ' . $ruta)`.
     * @como-deberia-funcionar Leer la ruta de la configuración en el servidor y comprobarla con las
     *   funciones de PHP.
     */
    public function test_laRutaDeLaImpresoraDeLaPeticionNoEjecutaOrdenes(): void
    {
        touch($this->carpeta . '/impresora');

        $this->ejecutar($this->cerrar('14.30', ['checkimprimir' => 'true', 'ruta_impresora' => $this->carpeta . '/impresora; touch ' . $this->carpeta . '/ejecutado']), $this->conTicketDeTresLineas());

        self::assertFileDoesNotExist($this->carpeta . '/ejecutado');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tpv/tareas.php:12-35; modulos/mod_tpv/clases/ClaseTickets.php:30-34
     *
     * @group defecto
     * @group errores
     * @group medio
     *
     * @que-ocurre-hoy Sin sesión, la petición no cobra el ticket, pero porque termina en un error de PHP
     *   al crear la clase de tickets, sin responder nada a la página.
     * @que-deberia-ocurrir Que sin sesión la petición no se atienda y responda que no hay sesión.
     * @por-que-ocurre La petición no mira la sesión: la clase de tickets la comprueba al crearse y falla
     *   sin ella.
     * @como-deberia-funcionar Comprobar la sesión al empezar la petición y responder el rechazo.
     */
    public function test_sinSesionLaPeticionRespondeQueNoSeAtiende(): void
    {
        $peticion = $this->ejecutar($this->cerrar('14.30'), $this->conTicketDeTresLineas(), false);

        self::assertSame([], $peticion['tickets']);
        self::assertStringNotContainsString('Fatal error', $peticion['errores']);
        self::assertIsArray($peticion['respuesta']);
    }

    /**
     * @comportamiento Buscar un producto por su nombre responde «Correcto» con uno igual, un listado con
     *   los que lo contienen, y «NoExiste» con ninguno; sin nada que buscar, no busca.
     */
    public function test_buscarUnProductoPorSuNombre(): void
    {
        $preparacion = [$this->producto('Peraconferenciatpv'), $this->producto('Peralimoneratpv')];

        $igual = $this->ejecutar($this->buscar('Descripcion', 'a.articulo_name', 'Peraconferenciatpv'), $preparacion);
        $contiene = $this->ejecutar($this->buscar('Descripcion', 'a.articulo_name', 'tpv'), $preparacion);
        $ninguno = $this->ejecutar($this->buscar('Descripcion', 'a.articulo_name', 'Ningunproductotpv'), $preparacion);
        $nada = $this->ejecutar($this->buscar('Descripcion', 'a.articulo_name', ''), $preparacion);

        self::assertSame(['Correcto', 'Peraconferenciatpv', 0], [$igual['respuesta']['Estado'], $igual['respuesta']['datos'][0]['articulo_name'], $igual['respuesta']['tipo_busqueda']]);
        self::assertSame(['Listado', 2, 1], [$contiene['respuesta']['Estado'], $contiene['respuesta']['Nitems'], $contiene['respuesta']['tipo_busqueda']]);
        self::assertStringContainsString('Peralimoneratpv', $contiene['respuesta']['listado']['html']);
        self::assertSame('NoExiste', $ninguno['respuesta']['Estado']);
        self::assertStringContainsString('No se encontrado nada con esa busqueda', $ninguno['respuesta']['listado']['html']);
        self::assertSame(['NoSeBusco', 'tpv'], [$nada['respuesta']['Estado'], $nada['respuesta']['dedonde']]);
    }

    /** @comportamiento Buscar por un campo que no es de la lista de los permitidos no busca: responde «CampoNoValido». */
    public function test_buscarPorUnCampoNoPermitidoNoBusca(): void
    {
        $peticion = $this->ejecutar($this->buscar('Descripcion', 'a.articulo_name; DROP TABLE articulos', 'Peraconferenciatpv'), [$this->producto('Peraconferenciatpv')]);

        self::assertSame('CampoNoValido', $peticion['respuesta']['Estado']);
    }

    /** @comportamiento Buscando desde el listado, un producto encontrado igual se responde como listado. */
    public function test_buscarDesdeElListadoRespondeElListado(): void
    {
        $peticion = $this->ejecutar($this->buscar('Descripcion', 'a.articulo_name', 'Peraconferenciatpv', 'popup'), [$this->producto('Peraconferenciatpv')]);

        self::assertSame(['Listado', 'popup'], [$peticion['respuesta']['Estado'], $peticion['respuesta']['dedonde']]);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tpv/funciones.php:63-66
     *
     * @group defecto
     * @group calculo
     * @group medio
     *
     * @que-ocurre-hoy El producto buscado trae el precio y la referencia de la tienda número 1, sea cual
     *   sea la de la sesión: en otra tienda llega sin precio.
     * @que-deberia-ocurrir Que traiga el precio y la referencia de la tienda de la sesión.
     * @por-que-ocurre La consulta tiene escrita la tienda 1.
     * @como-deberia-funcionar Tomar la tienda de la sesión.
     */
    public function test_elProductoBuscadoTraeElPrecioDeLaTiendaDeLaSesion(): void
    {
        $peticion = $this->ejecutar($this->buscar('Descripcion', 'a.articulo_name', 'Peraconferenciatpv'), [$this->producto('Peraconferenciatpv'), $this->precio(':a1', '1001', '2.30')]);

        self::assertSame(['2.30', '1001'], [$peticion['respuesta']['datos'][0]['pvpCiva'], $peticion['respuesta']['datos'][0]['crefTienda']]);
    }

    /**
     * @comportamiento Un código de barras propio se desglosa: el que empieza por 20 da la referencia y el
     *   peso; el que empieza por 21, la referencia y el precio. El de dígito de control erróneo, no.
     */
    public function test_unCodigoDeBarrasPropioSeDesglosa(): void
    {
        $peso = $this->ejecutar($this->buscar('Codbarras', 'ac.codBarras', $this->ean('200123401250')));
        $precio = $this->ejecutar($this->buscar('Codbarras', 'ac.codBarras', $this->ean('210123400450')));
        $erroneo = $this->ejecutar($this->buscar('Codbarras', 'ac.codBarras', substr($this->ean('200123401250'), 0, 12) . '9'));

        self::assertSame(['referencia' => '01234', 'peso' => 1.25, 'codbarras_leido' => $this->ean('200123401250')], $peso['respuesta']['codBarrasPropio']);
        self::assertSame(['referencia' => '01234', 'precio' => 4.5, 'codbarras_leido' => $this->ean('210123400450')], $precio['respuesta']['codBarrasPropio']);
        self::assertArrayNotHasKey('codBarrasPropio', $erroneo['respuesta']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tpv/tareas/buscarProducto.php:3,32; modulos/mod_tpv/funciones.php:44-46,62-66
     *
     * @group defecto
     * @group seguridad
     * @group alto
     *
     * @que-ocurre-hoy El campo por el que se busca llega en la petición y se pega a la consulta: con un
     *   campo escrito a propósito, la búsqueda trae productos que no tienen nada de lo buscado.
     * @que-deberia-ocurrir Que el campo sea uno de los de la configuración, y lo buscado, un dato.
     * @por-que-ocurre La consulta se arma con el campo y las palabras tal como llegan.
     * @como-deberia-funcionar Elegir el campo de una lista cerrada y pasar lo buscado como parámetro.
     */
    public function test_elCampoDeBusquedaNoSePegaALaConsulta(): void
    {
        $peticion = $this->ejecutar($this->buscar('Descripcion', 'a.idArticulo > 0 OR a.articulo_name', 'Ningunproductotpv'), [$this->producto('Peraconferenciatpv')]);

        self::assertSame('NoExiste', $peticion['respuesta']['Estado'] ?? null);
    }

    /**
     * @comportamiento Buscar clientes con un solo activo responde su número y su nombre; con varios, la lista;
     *   y uno elegido de la lista se busca por su número.
     */
    public function test_buscarClientes(): void
    {
        $preparacion = [$this->cliente('Bar Pepe de tpv'), $this->cliente('Bar Luis de tpv')];

        $uno = $this->ejecutar(['pulsado' => 'buscarClientes', 'busqueda' => 'Pepe de tpv', 'dedonde' => 'tpv'], $preparacion);
        $varios = $this->ejecutar(['pulsado' => 'buscarClientes', 'busqueda' => 'de tpv', 'dedonde' => 'tpv'], $preparacion);
        $elegido = $this->ejecutar(['pulsado' => 'buscarClientes', 'busqueda' => ':c2', 'dedonde' => 'Linea_tpv'], $preparacion);

        self::assertSame([1, 'Bar Pepe de tpv-Bar Pepe de tpv'], [$uno['respuesta']['Nitems'], $uno['respuesta']['nombre']]);
        self::assertSame(2, $varios['respuesta']['Nitems']);
        self::assertStringContainsString('<td>Bar Luis de tpv</td>', $varios['respuesta']['html']);
        self::assertSame($elegido['ids'][':c2'], $elegido['respuesta']['id']);
    }

    /** @comportamiento Un cliente inactivo no se elige solo: se responde la lista, con él marcado. */
    public function test_unClienteInactivoNoSeElige(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'buscarClientes', 'busqueda' => 'Pepe de tpv', 'dedonde' => 'tpv'], [$this->cliente('Bar Pepe de tpv', 'Inactivo')]);

        self::assertArrayNotHasKey('id', $peticion['respuesta']);
        self::assertStringContainsString('FilaModal danger', $peticion['respuesta']['html']);
    }

    /** @comportamiento Registrar que se descontó el stock de un ticket en la web deja el registro con su estado y las filas cambiadas. */
    public function test_registrarQueSeDescontoElStockEnLaWeb(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'RegistrarRestaStock', 'id_ticketst' => '123456', 'estado' => 'Correcto', 'datos' => ['row_afectados' => '3']]);

        self::assertSame('Correcto', $peticion['respuesta']['estado']);
        self::assertSame([['123456', 'Correcto', 'Registros cambiados 3']], array_map(static fn (array $r): array => [$r['idTicketst'], $r['estado'], $r['respuesta']], $peticion['web']));
    }

    /** @comportamiento Grabar la configuración del usuario la crea la primera vez y la cambia después, sin el módulo ni el usuario dentro. */
    public function test_grabarLaConfiguracionDelUsuario(): void
    {
        $nueva = $this->ejecutar($this->configurar('Modulo', ':usuario'));
        $cambiada = $this->ejecutar($this->configurar('Usuario', ':usuario', ['impresion_ticket' => 'No']), [$this->configuracionSembrada(['impresion_ticket' => 'Si'])]);

        self::assertSame([[(string) $this->idUsuario, 'mod_tpv']], array_map(static fn (array $c): array => [$c['idusuario'], $c['nombre_modulo']], $nueva['configuraciones']));
        self::assertSame(['impresion_ticket' => 'Si', 'impresora_ticket' => '/dev/usb/lp0'], json_decode($nueva['configuraciones'][0]['configuracion'], true));
        self::assertSame('No', json_decode($cambiada['configuraciones'][0]['configuracion'], true)['impresion_ticket']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tpv/tareas.php:127-137
     *
     * @group defecto
     * @group permisos
     * @group alto
     *
     * @que-ocurre-hoy Grabar la configuración graba la del usuario que diga el formulario: un usuario
     *   cambia la configuración del TPV de otro, la ruta de su impresora incluida.
     * @que-deberia-ocurrir Que solo se grabe la del usuario de la sesión.
     * @por-que-ocurre El usuario se toma de la configuración que manda la página.
     * @como-deberia-funcionar Tomar el usuario de la sesión.
     */
    public function test_noSeGrabaLaConfiguracionDeOtroUsuario(): void
    {
        $peticion = $this->ejecutar($this->configurar('Modulo', '999999'));

        self::assertSame([], array_values(array_filter($peticion['configuraciones'], static fn (array $c): bool => $c['idusuario'] === '999999')));
    }

    /** @comportamiento La configuración base se responde como formulario con los valores del fichero de parámetros del módulo. */
    public function test_laConfiguracionBaseSeResponde(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'configuracionBase']);

        self::assertStringContainsString('<form class="form-horizontal" id="configForm">', $peticion['respuesta']['html']);
        self::assertStringContainsString('name="impresora_ticket" id="impresora_ticket" value="/dev/usb/lp0"', $peticion['respuesta']['html']);
    }

    /** @comportamiento El formulario de cambio de fecha se responde con los tickets marcados. */
    public function test_elFormularioDeCambioDeFechaSeResponde(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'htmlFechaNueva', 'Tickets' => ['7', '8', '9']]);

        self::assertStringContainsString('3 ticket(s) seleccionados para cambiar la fecha', $peticion['respuesta']['html']);
    }

    /** @comportamiento La línea del ticket se responde pintada, con la configuración del peso. */
    public function test_laLineaDelTicketSeResponde(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'HtmlLineaTicket', 'producto' => $this->linea('5', 1, '2.30'), 'num_item' => '0', 'CONF_campoPeso' => 'no']);

        self::assertStringContainsString('<td class="detalle">Pera</td>', $peticion['respuesta']['html']);
        self::assertSame('no', $peticion['respuesta']['conf_peso']);
    }

    /** @comportamiento El listado de una familia responde sus hijas como botones y sus productos. */
    public function test_elListadoDeUnaFamiliaSeResponde(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'listadoFamilia', 'idFamilia' => ':f1'], [
            "INSERT INTO familias (familiaNombre, familiaPadre, mostrar_tpv) VALUES ('Frutas de tpv', 0, 1)",
            "INSERT INTO familias (familiaNombre, familiaPadre, mostrar_tpv) VALUES ('Frutas de hueso de tpv', :f1, 1)",
            $this->producto('Pera de tpv'),
            'INSERT INTO articulosFamilias (idArticulo, idFamilia) VALUES (:a1, :f1)',
        ]);

        self::assertStringContainsString('<button class="btn btn-warning" onclick="listadofamilia(' . $peticion['ids'][':f2'] . ')">Frutas de hueso de tpv</button>', $peticion['respuesta']['html']);
        self::assertStringContainsString('Pera de tpv', $peticion['respuesta']['html']);
    }

    /** @comportamiento Una incidencia nueva del TPV se guarda con su mensaje; sin mensaje, no. */
    public function test_unaIncidenciaNuevaSeGuarda(): void
    {
        $conMensaje = $this->ejecutar(['pulsado' => 'nuevaIncidencia', 'fecha' => '2026-10-24', 'datos' => '{"vista":"tpv.php"}', 'estado' => 'No resuelto', 'mensaje' => 'La impresora no corta']);
        $sinMensaje = $this->ejecutar(['pulsado' => 'nuevaIncidencia', 'fecha' => '2026-10-24', 'datos' => '{"vista":"tpv.php"}', 'estado' => 'No resuelto', 'mensaje' => '']);

        self::assertSame([['mod_tpv', 'La impresora no corta']], array_map(static fn (array $i): array => [$i['dedonde'], $i['mensaje']], $conMensaje['incidencias']));
        self::assertSame([], $sinMensaje['incidencias']);
    }

    /**
     * @comportamiento Reimprimir un ticket cobrado lo escribe en la impresora de la configuración del
     *   usuario, con su número, y lo corta si la configuración lo dice.
     */
    public function test_reimprimirUnTicketLoEscribeEnLaImpresoraDeLaConfiguracion(): void
    {
        touch($this->carpeta . '/impresora');
        [$id, $numero] = $this->ticketCerrado();

        $peticion = $this->ejecutar(['pulsado' => 'ImprimirTicketCerrados', 'idTicketst' => (string) $id], [$this->configuracionSembrada(['impresion_ticket' => 'Si', 'corte_tickets' => 'Si', 'impresora_ticket' => $this->carpeta . '/impresora'])]);

        $impreso = (string) file_get_contents($this->carpeta . '/impresora');
        self::assertSame((string) $id, (string) $peticion['respuesta']['idTicketST']);
        self::assertStringContainsString('FACTURA  SIMPLIFICADA', $impreso);
        self::assertStringContainsString('Numero:' . $numero, $impreso);
        self::assertStringContainsString("\x1dV", $impreso);
    }

    /** @comportamiento Reimprimir sin la impresora de la configuración responde el aviso. */
    public function test_reimprimirSinImpresoraLoAvisa(): void
    {
        [$id] = $this->ticketCerrado();

        $peticion = $this->ejecutar(['pulsado' => 'ImprimirTicketCerrados', 'idTicketst' => (string) $id], [$this->configuracionSembrada(['impresion_ticket' => 'Si', 'impresora_ticket' => $this->carpeta . '/no-existe'])]);

        self::assertSame(' no existe la impresora asignada, hay un error', $peticion['respuesta']['error_impresora']);
    }

    /** @comportamiento Preparar el stock de la web responde la tienda web y los productos del ticket con su número en la web. */
    public function test_prepararElStockDeLaWebTraeElNumeroDeCadaProducto(): void
    {
        [$id, , $producto] = $this->ticketCerrado();

        $peticion = $this->ejecutar(['pulsado' => 'ObtenerRefTiendaWeb', 'idTicket' => (string) $id, 'web' => ':t1'], [
            "INSERT INTO tiendas (tipoTienda, razonsocial, nif, telefono, estado, NombreComercial, direccion, ano) VALUES ('web', 'Tienda web de pruebas', 'X0000000X', '000000000', 'Activo', 'Web de pruebas', 'Sin direccion', '2026')",
            "INSERT INTO articulosTiendas (idArticulo, idTienda, crefTienda, idVirtuemart, estado) VALUES ({$producto}, :t1, '', 31, 'Activo')",
        ]);

        self::assertSame('Web de pruebas', $peticion['respuesta']['tienda']['NombreComercial']);
        self::assertSame([[(string) $producto, '31']], array_map(static fn (array $p): array => [$p['idArticulo'], $p['idVirtuemart'] ?? null], $peticion['respuesta']['productos']));
    }

    /** @comportamiento Abrir una incidencia responde su ventana, con de dónde viene. */
    public function test_abrirUnaIncidenciaRespondeSuVentana(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'abririncidencia', 'dedonde' => 'tpv.php', 'idReal' => '7', 'configuracion' => [['descripcion' => 'mostrar_Select', 'valor' => 'Si'], ['descripcion' => 'usuario_Defecto', 'valor' => '1']]]);

        self::assertStringContainsString('id="inci_dedonde" value="tpv.php"', $peticion['respuesta']['html']);
        self::assertSame(['vista' => 'tpv.php', 'idReal' => '7'], json_decode($peticion['respuesta']['datos'], true));
    }

    /** @comportamiento Una incidencia nueva para otro usuario guarda a quién va dirigida. */
    public function test_unaIncidenciaParaOtroUsuarioLoGuarda(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'nuevaIncidencia', 'fecha' => '2026-10-24', 'datos' => '{"vista":"tpv.php"}', 'estado' => 'No resuelto', 'mensaje' => 'La impresora no corta', 'usuarioSelec' => ':usuario']);

        self::assertSame([['mod_tpv', 'La impresora no corta']], array_map(static fn (array $i): array => [$i['dedonde'], $i['mensaje']], $peticion['incidencias']));
    }

    /** @comportamiento Buscar clientes sin nada que buscar responde la lista vacía. */
    public function test_buscarClientesSinNadaRespondeLaListaVacia(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'buscarClientes', 'busqueda' => '', 'dedonde' => 'tpv']);

        self::assertSame(0, $peticion['respuesta']['Nitems']);
        self::assertStringContainsString('<label>Busqueda Cliente en tpv</label>', $peticion['respuesta']['html']);
    }

    /** @comportamiento Buscar clientes por unas cifras los busca en el NIF y en los teléfonos. */
    public function test_buscarClientesPorTelefono(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'buscarClientes', 'busqueda' => '986123', 'dedonde' => 'tpv'], ["INSERT INTO clientes (Nombre, razonsocial, telefono, estado) VALUES ('Bar Pepe de tpv', 'Bar Pepe de tpv', '986123456', 'Activo')"]);

        self::assertSame([1, $peticion['ids'][':c1']], [$peticion['respuesta']['Nitems'], $peticion['respuesta']['id']]);
    }

    /** @comportamiento Un entregado que no es un número se graba como cero. */
    public function test_unEntregadoQueNoEsUnNumeroSeGrabaComoCero(): void
    {
        $peticion = $this->ejecutar($this->cerrar('14.30', ['entregado' => 'veinte']), $this->conTicketDeTresLineas());

        self::assertSame('0.00', $peticion['tickets'][0]['entregado']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tpv/tareas/CerrarTicket.php:19-27
     *
     * @group defecto
     * @group errores
     * @group medio
     *
     * @que-ocurre-hoy Cobrar un temporal que no está termina en un error de PHP al armar el mensaje, sin
     *   responder a la página.
     * @que-deberia-ocurrir Que responda el error de que no se encontró el ticket.
     * @por-que-ocurre El mensaje se arma sumando textos con «+».
     * @como-deberia-funcionar Unir los textos con «.» y responder una sola vez.
     */
    public function test_cobrarUnTemporalQueNoEstaLoDice(): void
    {
        $peticion = $this->ejecutar($this->cerrar('14.30', ['numTickTemporal' => '99']));

        self::assertStringNotContainsString('Fatal error', $peticion['errores']);
        self::assertArrayHasKey('error', $peticion['respuesta'] ?? []);
    }

    /** @return array{0: int, 1: string, 2: int} Un ticket cerrado de los que hay en la base, con líneas y desglose: su número interno, su número y su primer producto. */
    private function ticketCerrado(): array
    {
        $fila = $this->db->query('SELECT t.id, t.Numticket, (SELECT l.idArticulo FROM ticketslinea l WHERE l.idticketst = t.id ORDER BY l.id LIMIT 1) AS producto FROM ticketst t'
            . ' WHERE t.idTienda = ' . $this->idTienda . " AND t.estado = 'Cerrado' AND EXISTS (SELECT 1 FROM ticketslinea l WHERE l.idticketst = t.id)"
            . ' AND EXISTS (SELECT 1 FROM ticketstIva i WHERE i.idticketst = t.id) ORDER BY t.id LIMIT 1')->fetch_assoc();
        self::assertNotNull($fila, 'La base de pruebas no tiene un ticket cerrado con líneas y desglose');

        return [(int) $fila['id'], (string) $fila['Numticket'], (int) $fila['producto']];
    }

    /** @return list<string> Dos productos, Pera al 10 % y Vino al 21 %, y el temporal 8 con dos de Pera, uno de Vino y cinco de Pera eliminados. */
    private function conTicketDeTresLineas(): array
    {
        return [
            $this->producto('Pera'),
            $this->producto('Vino', '21.00'),
            $this->temporal(8, 'Actual', [$this->linea(':a1', 2, '1.10'), $this->linea(':a2', 1, '12.10', ['cdetalle' => 'Vino', 'ctipoiva' => '21.00', 'nfila' => 2]), $this->linea(':a1', 5, '1.10', ['estado' => 'Eliminado', 'nfila' => 3])]),
        ];
    }

    /**
     * @param array<string, mixed> $cambios
     * @return array<string, mixed> Una línea del ticket como la arma la página.
     */
    private function linea(string $producto, int|string $unidad, string $precio, array $cambios = []): array
    {
        return $cambios + ['id' => $producto, 'cref' => '1001', 'cdetalle' => 'Pera', 'pvpconiva' => $precio, 'ccodebar' => '8400000000017', 'ctipoiva' => '10.00', 'unidad' => $unidad, 'estado' => 'Activo', 'tipo' => 'unidad', 'nfila' => 1];
    }

    /** @param list<array<string, mixed>> $lineas */
    private function temporal(int $numero, string $estado, array $lineas): string
    {
        $productos = json_encode(array_map(static fn (array $l): string => (string) json_encode($l), $lineas));

        return "INSERT INTO ticketstemporales (numticket, estadoTicket, idTienda, idUsuario, fechaInicio, idClientes, total, Productos) VALUES ({$numero}, '{$estado}', :tienda, :usuario, '2026-10-24 09:00:00', :cliente, 0, '" . addslashes((string) $productos) . "')";
    }

    private function producto(string $nombre, string $iva = '10.00'): string
    {
        return "INSERT INTO articulos (iva, articulo_name, estado, fecha_creado, ultimoCoste, tipo) VALUES ({$iva}, '{$nombre}', 'Activo', NOW(), 1, 'unidad')";
    }

    private function precio(string $producto, string $referencia, string $precio): string
    {
        return "INSERT INTO articulosTiendas (idArticulo, idTienda, crefTienda, estado) VALUES ({$producto}, :tienda, '{$referencia}', 'Activo');"
            . " INSERT INTO articulosPrecios (idArticulo, pvpCiva, pvpSiva, idTienda) VALUES ({$producto}, {$precio}, {$precio} / 1.1, :tienda)";
    }

    private function cliente(string $nombre, string $estado = 'Activo'): string
    {
        return "INSERT INTO clientes (Nombre, razonsocial, estado) VALUES ('{$nombre}', '{$nombre}', '{$estado}')";
    }

    /** @param array<string, string> $valores */
    private function configuracionSembrada(array $valores): string
    {
        return "INSERT INTO modulos_configuracion (idusuario, nombre_modulo, configuracion, fecha) VALUES (:usuario, 'mod_tpv', '" . json_encode($valores) . "', NOW())";
    }

    /** @param list<array<string, mixed>> $lineas */
    private function grabar(string $estado, int $numero, array $lineas): array
    {
        return ['pulsado' => 'grabarTickes', 'productos' => json_encode($lineas), 'idCliente' => ':cliente', 'idTienda' => ':tienda', 'idUsuario' => ':usuario', 'estadoTicket' => $estado, 'numTicket' => (string) $numero];
    }

    /**
     * @param array<string, string> $cambios
     * @return array<string, string>
     */
    private function cerrar(string $total, array $cambios = []): array
    {
        return $cambios + ['pulsado' => 'CerrarTicket', 'total' => $total, 'entregado' => '20.00', 'formaPago' => 'tarjeta', 'idTienda' => ':tienda', 'idCliente' => ':cliente', 'idUsuario' => ':usuario', 'estadoTicket' => 'Actual', 'numTickTemporal' => '8', 'cambio' => '5.70', 'checkimprimir' => 'false', 'ruta_impresora' => ''];
    }

    /** @return array<string, string> */
    private function buscar(string $caja, string $campo, string $valor, string $dedonde = 'tpv'): array
    {
        return ['pulsado' => 'buscarProductos', 'cajaInput' => $caja, 'campo' => $campo, 'valorCampo' => $valor, 'dedonde' => $dedonde];
    }

    /**
     * @param array<string, string> $valores
     * @return array<string, mixed>
     */
    private function configurar(string $tipo, string $usuario, array $valores = []): array
    {
        return ['pulsado' => 'Grabar_configuracion', 'configuracion' => $valores + ['impresion_ticket' => 'Si', 'impresora_ticket' => '/dev/usb/lp0', 'tipo_configuracion' => $tipo, 'nombre_modulo' => 'mod_tpv', 'idUsuario' => $usuario]];
    }

    /** Completa un código de barras de doce cifras con su dígito de control. */
    private function ean(string $doce): string
    {
        $suma = 0;
        foreach (str_split($doce) as $posicion => $cifra) {
            $suma += (int) $cifra * ($posicion % 2 === 0 ? 1 : 3);
        }

        return $doce . (10 - $suma % 10) % 10;
    }

    /** @return list<list<string>> Las líneas del ticket cobrado, con el número del producto vuelto a su nombre. */
    private function lineas(array $peticion): array
    {
        $nombres = array_flip($peticion['ids']);

        return array_map(static fn (array $l): array => [$nombres[$l['idArticulo']] ?? $l['idArticulo'], $l['cdetalle'], $l['ncant'], $l['precioCiva'], $l['iva'], $l['estadoLinea']], $peticion['lineas']);
    }

    /** @return list<list<string>> El stock de la tienda de los productos sembrados, con su número vuelto a su nombre. */
    private function stocks(array $peticion): array
    {
        $nombres = array_flip($peticion['ids']);

        return array_map(static fn (array $s): array => [$nombres[$s['idArticulo']] ?? $s['idArticulo'], $s['stockOn']], $peticion['stocks']);
    }

    /**
     * Ejecuta la petición de fondo en un PHP aparte y devuelve su respuesta, sus avisos, lo que el caso
     * grabó y los números de lo sembrado.
     *
     * @param array<string, mixed> $formulario
     * @param list<string> $preparacion
     * @return array<string, mixed>
     */
    private function ejecutar(array $formulario, array $preparacion = [], bool $conSesion = true, bool $conIndices = true): array
    {
        $sesion = $conSesion ? [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => $this->idUsuario, 'group_id' => 1, 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => $this->idTienda, 'razonsocial' => 'Tienda de pruebas SL', 'NombreComercial' => 'Tienda de pruebas', 'nif' => 'B00000000', 'direccion' => 'Calle de pruebas 1', 'telefono' => '986000000', 'ano' => '2026'],
        ] : [];
        if ($conIndices) {
            array_unshift($preparacion, 'INSERT INTO indices (idTienda, idUsuario, numticket, tempticket) VALUES (:tienda, :usuario, 40, 7)');
        }
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["DOCUMENT_ROOT"] = ""; $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = "/TPVFox/modulos/mod_tpv/tareas.php";'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_tpv', true) . ');'
            . ' $_POST = ["pulsado" => ""]; require "./../../inicial.php";'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ' $ids = [":usuario" => "' . $this->idUsuario . '", ":tienda" => "' . $this->idTienda . '", ":cliente" => "' . $this->idCliente . '"]; $cuentas = ["clientes" => 0, "articulos" => 0, "familias" => 0, "tiendas" => 0];'
            . ' $antes = []; foreach (["ticketst" => "id", "ticketstemporales" => "id", "articulos" => "idArticulo", "importar_virtuemart_tickets" => "id", "modulos_configuracion" => "id", "modulo_incidencia" => "id"] as $tabla => $clave) { $antes[$tabla] = (int) $BDTpv->query("SELECT COALESCE(MAX(" . $clave . "), 0) FROM " . $tabla)->fetch_row()[0]; }'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->multi_query(strtr($sql, $ids)); $id = $BDTpv->insert_id; do { if ($r = $BDTpv->store_result()) { $r->free(); } } while ($BDTpv->more_results() && $BDTpv->next_result());'
            . ' if (preg_match("/^INSERT INTO (clientes|articulos|familias|tiendas) /", $sql, $t)) { $ids[[":c", ":a", ":f", ":t"][array_search($t[1], ["clientes", "articulos", "familias", "tiendas"])] . ++$cuentas[$t[1]]] = (string) $id; } }'
            . ' $_POST = json_decode(strtr(' . var_export((string) json_encode($formulario), true) . ', $ids), true);'
            . ' $foto = function () use ($BDTpv, $antes) { return ['
            . ' "temporales" => $BDTpv->query("SELECT * FROM ticketstemporales WHERE id > " . $antes["ticketstemporales"] . " ORDER BY id")->fetch_all(MYSQLI_ASSOC),'
            . ' "tickets" => $BDTpv->query("SELECT * FROM ticketst WHERE id > " . $antes["ticketst"] . " ORDER BY id")->fetch_all(MYSQLI_ASSOC),'
            . ' "lineas" => $BDTpv->query("SELECT * FROM ticketslinea WHERE idticketst > " . $antes["ticketst"] . " ORDER BY id")->fetch_all(MYSQLI_ASSOC),'
            . ' "ivas" => $BDTpv->query("SELECT * FROM ticketstIva WHERE idticketst > " . $antes["ticketst"] . " ORDER BY iva")->fetch_all(MYSQLI_ASSOC),'
            . ' "indices" => $BDTpv->query("SELECT * FROM indices WHERE idUsuario = ' . $this->idUsuario . '")->fetch_all(MYSQLI_ASSOC),'
            . ' "stocks" => $BDTpv->query("SELECT * FROM articulosStocks WHERE idArticulo > " . $antes["articulos"] . " ORDER BY idArticulo")->fetch_all(MYSQLI_ASSOC),'
            . ' "web" => $BDTpv->query("SELECT * FROM importar_virtuemart_tickets WHERE id > " . $antes["importar_virtuemart_tickets"])->fetch_all(MYSQLI_ASSOC),'
            . ' "configuraciones" => $BDTpv->query("SELECT * FROM modulos_configuracion WHERE id > " . $antes["modulos_configuracion"] . " OR idusuario = ' . $this->idUsuario . '")->fetch_all(MYSQLI_ASSOC),'
            . ' "incidencias" => $BDTpv->query("SELECT dedonde, mensaje FROM modulo_incidencia WHERE id > " . $antes["modulo_incidencia"])->fetch_all(MYSQLI_ASSOC)]; };'
            . ' $GLOBALS["ultimaFoto"] = []; ob_start(function ($salida) use ($foto) { try { $GLOBALS["ultimaFoto"] = $foto(); } catch (Throwable $e) { } return $salida; }, 1);'
            . ' register_shutdown_function(function () use ($foto, &$ids) { try { $datos = $foto(); } catch (Throwable $e) { $datos = $GLOBALS["ultimaFoto"]; }'
            . ' echo "\n@@DATOS@@" . json_encode(["ids" => $ids] + $datos, JSON_INVALID_UTF8_SUBSTITUTE); });'
            . ' include "tareas.php";';

        $errores = (string) tempnam(sys_get_temp_dir(), 'tpv');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -d error_log=/dev/null -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$escrito, $estado] = array_pad(explode('@@DATOS@@', implode("\n", $salida), 2), 2, 'null');
        $estado = (json_decode($estado, true) ?? []) + ['ids' => [], 'temporales' => [], 'tickets' => [], 'lineas' => [], 'ivas' => [], 'indices' => [], 'stocks' => [], 'web' => [], 'configuraciones' => [], 'incidencias' => []];
        $ejecucion = ['respuesta' => json_decode(trim($escrito), true), 'escrito' => $escrito, 'errores' => (string) file_get_contents($errores)] + $estado;
        unlink($errores);

        return $ejecucion;
    }
}
