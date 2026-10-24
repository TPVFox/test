<?php

/**
 * Las funciones de cálculo y de pintado del TPV (`modulos/mod_tpv/funciones.php`) que no consultan la
 * base: los totales y el desglose por IVA de un ticket, la ventana de cobrar, la línea del ticket, los
 * listados de productos, de clientes y de una familia, las fechas, la configuración del módulo y la
 * comprobación de la impresora.
 *
 * El fichero declara funciones con el mismo nombre que las de otros módulos: cada caso corre en su
 * propio proceso. La comprobación de la impresora se hace con ficheros de una carpeta del caso, que se
 * borra al terminar.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModTpv;

use PHPUnit\Framework\TestCase;
use TPVFox\Test\CargaAislada;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class FuncionesTpvTest extends TestCase
{
    private string $carpeta;

    protected function setUp(): void
    {
        parent::setUp();
        CargaAislada::requerir(RUTA_TPVFOX . '/modulos/mod_tpv/funciones.php');
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

    /** @param array<string, mixed> $cambios */
    private function linea(array $cambios = []): object
    {
        return (object) ($cambios + ['id' => 5, 'cref' => '1001', 'cdetalle' => 'Pera conferencia', 'pvpconiva' => '1.10', 'ccodebar' => '8400000000017', 'ctipoiva' => '10.00', 'unidad' => 1, 'estado' => 'Activo', 'nfila' => 1, 'tipo' => 'unidad']);
    }

    /** @param array<string, mixed> $cambios */
    private function producto(array $cambios = []): array
    {
        return $cambios + ['idArticulo' => '5', 'articulo_name' => 'Pera conferencia', 'tipo' => 'unidad', 'codBarras' => '8400000000017', 'pvpCiva' => '1.10', 'crefTienda' => '1001', 'iva' => '10.00'];
    }

    /** @param array<string, string> $cambios */
    private function datosDeConfiguracion(array $cambios = []): array
    {
        return $cambios + ['impresion_ticket' => 'No', 'impresora_ticket' => '/dev/usb/lp1', 'corte_tickets' => 'Si', 'input_pordefecto' => 'Referencia', 'campo_peso' => 'Si', 'btn_familias' => 'Si', 'incidencias_mostrar_Select' => 'No', 'incidencias_usuario_Defecto' => '3'];
    }

    private function configuracion(): \SimpleXMLElement
    {
        return simplexml_load_string('<configuracion><impresion_ticket>Si</impresion_ticket><corte_tickets>No</corte_tickets>'
            . '<campo_peso>No</campo_peso><impresora_ticket>/dev/usb/lp0</impresora_ticket><input_pordefecto>Codbarras</input_pordefecto>'
            . '<btn_familias>No</btn_familias><incidencias descripcion="mostrar_Select">Si</incidencias>'
            . '<incidencias descripcion="usuario_Defecto">1</incidencias></configuracion>');
    }

    /**
     * @comportamiento El total del ticket suma las líneas activas —unidades por precio con IVA— y las
     *   desglosa por tipo de IVA en base e impuesto; las líneas eliminadas no cuentan.
     */
    public function test_elTotalSumaLasLineasActivasYLasDesglosaPorIva(): void
    {
        $totales = \recalculoTotales([
            $this->linea(['unidad' => 2, 'pvpconiva' => '1.10', 'nfila' => 1]),
            $this->linea(['unidad' => 1, 'pvpconiva' => '12.10', 'ctipoiva' => '21.00', 'nfila' => 2]),
            $this->linea(['unidad' => 3, 'pvpconiva' => '0.55', 'nfila' => 3]),
            $this->linea(['unidad' => 5, 'pvpconiva' => '1.00', 'estado' => 'Eliminado', 'nfila' => 4]),
        ]);

        self::assertSame('15.95', $totales['total']);
        self::assertSame(['10.00', '21.00'], array_keys($totales['desglose']));
        self::assertSame(['3.50', '0.35'], [$totales['desglose']['10.00']['base'], $totales['desglose']['10.00']['iva']]);
        self::assertSame(['10.00', '2.10'], [$totales['desglose']['21.00']['base'], $totales['desglose']['21.00']['iva']]);
    }

    /** @comportamiento Una línea de peso suma su peso por el precio; un ticket sin líneas activas suma cero y no tiene desglose. */
    public function test_elPesoSumaPorSuPrecioYUnTicketVacioSumaCero(): void
    {
        $peso = \recalculoTotales([$this->linea(['unidad' => '0.375', 'pvpconiva' => '8.80', 'tipo' => 'peso'])]);
        $vacio = \recalculoTotales([$this->linea(['estado' => 'Eliminado'])]);

        self::assertSame('3.30', $peso['total']);
        self::assertSame(['0.00', []], [$vacio['total'], $vacio['desglose']]);
    }

    /** @comportamiento El desglose se pinta con una fila por tipo de IVA: el tipo, la base y el impuesto. */
    public function test_elDesgloseSePintaPorTipo(): void
    {
        $html = \htmlDesgloseIvas(['10.00' => ['base' => '3.50', 'iva' => '0.35'], '21.00' => ['base' => '10.00', 'iva' => '2.10']]);

        self::assertSame('<tr><td class="tipo_10">10%</td><td class="base">3.50</td><td class="importe_iva">0.35</td></tr>'
            . '<tr><td class="tipo_21">21%</td><td class="base">10.00</td><td class="importe_iva">2.10</td></tr>', $html);
    }

    /**
     * @comportamiento La ventana de cobrar enseña el total, lo propone como entregado, ofrece contado y
     *   tarjeta, y marca imprimir si la configuración lo dice.
     */
    public function test_laVentanaDeCobrarLlevaElTotalYLaImpresion(): void
    {
        $imprimir = \htmlCobrar('15.95', ['impresion_ticket' => 'Si']);
        $sinImprimir = \htmlCobrar('15.95', ['impresion_ticket' => 'No']);

        self::assertStringContainsString('<h1>15.95<span class="small"> €</span></h1>', $imprimir['html']);
        self::assertStringContainsString('id="entrega" name="entrega" class="text-right" value="15.95"', $imprimir['html']);
        self::assertStringContainsString('<option value="contado">Contado</option><option value="tarjeta">Tarjeta</option>', $imprimir['html']);
        self::assertStringContainsString('<input name="checkimprimir" type="checkbox" checked>', $imprimir['html']);
        self::assertStringContainsString('<input name="checkimprimir" type="checkbox" >', $sinImprimir['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tpv/funciones.php:256-286
     *
     * @group defecto
     * @group calculo
     * @group medio
     *
     * @que-ocurre-hoy Con un total de mil euros o más, la caja de lo entregado lleva el total con
     *   separador de miles —«1,234.50»—, que la página lee como 1 € y el cobro guarda como 0.
     * @que-deberia-ocurrir Que lo entregado propuesto sea el total como número.
     * @por-que-ocurre `number_format` se llama sin separador de miles vacío.
     * @como-deberia-funcionar Dar el total con punto decimal y sin separador de miles.
     */
    public function test_cobrarMilEurosOMasProponeElTotalComoNumero(): void
    {
        $cobrar = \htmlCobrar('1234.50', ['impresion_ticket' => 'No']);

        self::assertStringContainsString('name="entrega" class="text-right" value="1234.50"', $cobrar['html']);
    }

    /**
     * @comportamiento La línea del ticket pinta su número, código de barras, referencia, nombre, unidades,
     *   precio con IVA, tipo e importe, con la opción de eliminarla; sin unidades, cuenta una. Llega como
     *   objeto o como lista.
     */
    public function test_laLineaDelTicketPintaElProducto(): void
    {
        $html = \htmlLineaTicket((array) $this->linea(['unidad' => '', 'nfila' => 3, 'pvpconiva' => '2.30']), 2, 'no');
        $objeto = \htmlLineaTicket($this->linea(['unidad' => 2, 'nfila' => 3, 'pvpconiva' => '2.30']), 2, 'no');

        self::assertStringStartsWith('<tr id="Row3" ><td class="linea">3</td><td class="codbarras">8400000000017</td><td class="referencia"> 1001</td><td class="detalle">Pera conferencia</td>', $html);
        self::assertStringContainsString('id="Unidad_Fila_3" type="text" data-obj="Unidad_Fila" name="unidad" placeholder="unidad" size="4"  value="1"', $html);
        self::assertStringContainsString('<td style="display:none"><input id="C3_Kilo"', $html);
        self::assertStringContainsString('<td class="tipoiva">10%</td><td id="N3_Importe" class="importe" >2.30</td><td class="eliminar"><a onclick=" eliminarFila(2);">', $html);
        self::assertStringContainsString('<td id="N3_Importe" class="importe" >4.60</td>', $objeto);
    }

    /** @comportamiento Una línea eliminada se pinta tachada, con sus unidades bloqueadas y la opción de recuperarla. */
    public function test_unaLineaEliminadaSePintaTachadaYSePuedeRecuperar(): void
    {
        $html = \htmlLineaTicket($this->linea(['estado' => 'Eliminado', 'nfila' => 2]), 1, 'no');

        self::assertStringStartsWith('<tr id="Row2"  class="tachado" >', $html);
        self::assertStringContainsString('value="1"  disabled onkeydown', $html);
        self::assertStringContainsString('<a onclick=" retornarFila(1);"><span class="glyphicon glyphicon-export"></span></a>', $html);
    }

    /** @comportamiento Un producto de peso lleva el icono de la balanza; con el campo de peso en la configuración, la caja de peso se ve. */
    public function test_laLineaDePesoLlevaSuIconoYLaCajaDePeso(): void
    {
        $html = \htmlLineaTicket($this->linea(['tipo' => 'peso']), 0, 'si');

        self::assertStringContainsString('<td class="referencia"><img src="../../css/img/balanza.png" title="Peso" alt="Peso"> 1001</td>', $html);
        self::assertStringContainsString('<td><input pattern="[\-+]?[0-9]*[.]?[0-9]+" id="C1_Kilo" type="text" name="kilo"', $html);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tpv/funciones.php:504-509
     *
     * @group defecto
     * @group seguridad
     * @group bajo
     *
     * @que-ocurre-hoy El nombre y la referencia del producto se pegan en la línea del ticket sin escapar:
     *   un nombre con marcas de HTML se interpreta como página.
     * @que-deberia-ocurrir Que se pinten como texto.
     * @por-que-ocurre La línea concatena los datos del producto tal como llegan.
     * @como-deberia-funcionar Escapar cada dato al pintarlo.
     */
    public function test_elNombreDelProductoSePintaComoTextoEnLaLinea(): void
    {
        $html = \htmlLineaTicket($this->linea(['cdetalle' => 'Pera <b>extra</b>']), 0, 'no');

        self::assertStringContainsString('<td class="detalle">Pera &lt;b&gt;extra&lt;/b&gt;</td>', $html);
    }

    /** @comportamiento Las fechas se maquetan en día/mes/año o en hora:minuto. */
    public function test_lasFechasSeMaquetan(): void
    {
        self::assertSame(['24/10/2026', '09:05'], [\MaquetarFecha('2026-10-24 09:05:00'), \MaquetarFecha('2026-10-24 09:05:00', 'HM')]);
    }

    /** @comportamiento El formulario de cambio de fecha dice cuántos tickets hay marcados; sin ninguno, no hay formulario. */
    public function test_elCambioDeFechaDiceCuantosTickets(): void
    {
        $dos = \htmlFechaNueva(['7', '8']);

        self::assertStringContainsString('<div>2 ticket(s) seleccionados para cambiar la fecha:<input type="date" name="fechaNueva"', $dos['html']);
        self::assertStringContainsString('<button id="btn_cambiar" disabled class="btn btn-primary" onclick="cambioFechaTickets()">Cambiar</button>', $dos['html']);
        self::assertSame([], \htmlFechaNueva([]));
    }

    /**
     * @comportamiento La lista de clientes del TPV pinta cada uno con su nombre, razón social y NIF, y lo
     *   elige al pulsarlo; los inactivos van en rojo, con el aviso de que no se pueden añadir.
     */
    public function test_losClientesSePintanConLosInactivosMarcados(): void
    {
        $html = \htmlClientes('pe', 'tpv', [
            ['idClientes' => '7', 'nombre' => 'Bar "Pepe"', 'razonsocial' => 'Pepe SL', 'nif' => 'B11111111', 'estado' => 'Activo'],
            ['idClientes' => '8', 'nombre' => 'Bar Luis', 'razonsocial' => 'Luis SL', 'nif' => 'B22222222', 'estado' => 'Inactivo'],
        ]);

        self::assertStringContainsString('<label>Busqueda Cliente en tpv</label>', $html);
        self::assertStringContainsString('<tr class="FilaModal" id="Fila_0" onclick="buscarClientes(\'Linea_tpv\',\'7\');">', $html);
        self::assertStringContainsString('<td>Bar &quot;Pepe&quot;</td><td>Pepe SL</td><td>B11111111</td>', $html);
        self::assertStringContainsString('<tr class="FilaModal danger" id="Fila_1"', $html);
        self::assertStringContainsString('Recuerda que los clientes INACTIVOS están Rojo, no se puede añadir', $html);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tpv/funciones.php:990-1021
     *
     * @group defecto
     * @group presentacion
     * @group bajo
     *
     * @que-ocurre-hoy Con más de diez clientes, la lista dice que enseña doce y enseña once.
     * @que-deberia-ocurrir Que diga los que enseña.
     * @por-que-ocurre El aviso está escrito con un número fijo, y el corte es en la undécima fila.
     * @como-deberia-funcionar Sacar el número del aviso del mismo límite que corta la lista.
     */
    public function test_laListaDeClientesDiceCuantosEnsena(): void
    {
        $clientes = array_map(static fn (int $i): array => ['idClientes' => (string) $i, 'nombre' => 'Cliente ' . $i, 'razonsocial' => '', 'nif' => '', 'estado' => 'Activo'], range(1, 13));

        $html = \htmlClientes('Cliente', 'tpv', $clientes);

        $filas = substr_count($html, '<tr class="FilaModal');
        self::assertStringContainsString('<span> Se muestra ' . $filas . ' clientes de 13</span>', $html);
    }

    /**
     * @comportamiento Los productos encontrados se listan con su referencia, nombre y precio, y cada uno
     *   lleva sus datos a la línea del ticket al elegirlo; el listado conserva la caja y el campo buscados.
     */
    public function test_losProductosEncontradosSeListan(): void
    {
        $resultado = \htmlProductos([$this->producto(['articulo_name' => 'Pera "extra"']), $this->producto(['idArticulo' => '6', 'articulo_name' => 'Uva', 'tipo' => 'peso'])], 'Descripcion', 'a.articulo_name', 'pera');

        self::assertSame(2, $resultado['encontrados']);
        self::assertSame('a.articulo_name', $resultado['campo']);
        self::assertStringContainsString('<input id="cajaBusqueda" name="Descripcion" placeholder="Buscar" data-obj="cajaBusquedaproductos" size="13" value="pera"', $resultado['html']);
        self::assertStringContainsString("onclick=\"escribirProductoSeleccionado('Descripcion','1001','Pera &quot;extra&quot;','10.00','8400000000017',1.10,'5','unidad');\"", $resultado['html']);
        self::assertStringContainsString('<td>1001</td><td>Pera &quot;extra&quot;</td><td>1.10</td><td></td>', $resultado['html']);
        self::assertStringContainsString('<td><img src="../../css/img/balanza.png" title="Peso" alt="Peso"></td>', $resultado['html']);
    }

    /** @comportamiento Sin productos, el listado pide palabras si no se buscó nada, y si no, dice que no encontró nada. */
    public function test_sinProductosElListadoLoDice(): void
    {
        $vacia = \htmlProductos([], 'Descripcion', 'a.articulo_name', '');
        $sinNada = \htmlProductos([], 'Descripcion', 'a.articulo_name', 'kiwi');

        self::assertStringContainsString('Pon las palabras para buscar productos', $vacia['html']);
        self::assertStringContainsString('No se encontrado nada con esa busqueda', $sinNada['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tpv/funciones.php:207-210
     *
     * @group defecto
     * @group calculo
     * @group alto
     *
     * @que-ocurre-hoy Un producto de mil euros o más se lista con el precio con separador de miles
     *   —«1,250.00»— pegado como argumento: al elegirlo, la línea recibe 1 € de precio y un número de
     *   producto que no es el suyo.
     * @que-deberia-ocurrir Que el producto elegido llegue con su precio y su número.
     * @por-que-ocurre `number_format` pone la coma de los miles, que en la llamada separa argumentos.
     * @como-deberia-funcionar Pasar el precio con punto decimal y sin separador de miles.
     */
    public function test_unProductoDeMilEurosOMasSeEligeConSuPrecio(): void
    {
        $html = \htmlTrProductosModal([$this->producto(['articulo_name' => 'Jamon iberico', 'pvpCiva' => '1250.00'])], 'Descripcion');

        self::assertStringContainsString("escribirProductoSeleccionado('Descripcion','1001','Jamon iberico','10.00','8400000000017',1250.00,'5','unidad');", $html);
    }

    /** @comportamiento El listado de una familia ofrece un botón por cada familia hija y una fila por producto, con su primer código de barras. */
    public function test_elListadoDeUnaFamiliaLlevaSusHijasYSusProductos(): void
    {
        $html = \htmlModalListadoPorFamilias(
            [['idFamilia' => '3', 'familiaNombre' => 'Frutas de hueso']],
            [$this->producto(['codBarras' => ['8400000000024', '8400000000031'], 'cref_tienda_principal' => '2002'])],
            'Codbarras'
        );
        $vacio = \htmlModalListadoPorFamilias([], [], 'Codbarras');

        self::assertStringContainsString('<button class="btn btn-warning" onclick="listadofamilia(3)">Frutas de hueso</button>', $html);
        self::assertStringContainsString("escribirProductoSeleccionado('Codbarras','2002','Pera conferencia','10.00','8400000000024',1.10,'5','unidad');", $html);
        self::assertStringContainsString('<tbody><tr></tr></tbody>', $vacio);
    }

    /** @comportamiento El desplegable de la caja de salto ofrece referencia, código de barras y descripción, con la configurada marcada. */
    public function test_elSaltoDeCajaMarcaElConfigurado(): void
    {
        $html = \htmlSelectConfiguracionSalto('Referencia');

        self::assertStringContainsString('<option value="Referencia" selected>Referencia</option><option value="Codbarras" >Codbarras</option><option value="Descripcion" >Descripcion</option>', $html);
    }

    /** @comportamiento La configuración base se pinta con los valores del fichero de parámetros: impresión, impresora, corte, caja, peso, familias e incidencias. */
    public function test_laConfiguracionBaseSePintaConSusValores(): void
    {
        $html = \htmlConfiguracionBase($this->configuracion());

        self::assertStringContainsString('<option value="Si" selected>Sí</option>', $html);
        self::assertStringContainsString('name="impresora_ticket" id="impresora_ticket" value="/dev/usb/lp0"', $html);
        self::assertStringContainsString('<option value="Codbarras" selected>Codbarras</option>', $html);
        self::assertStringContainsString('name="incidencias_usuario_Defecto" id="incidencias_usuario_Defecto" value="1"', $html);
        self::assertStringContainsString('onclick="guardarXML()"', $html);
    }

    /** @comportamiento Con todos los datos, la configuración base cambia cada valor en el XML en memoria. */
    public function test_laConfiguracionSeActualizaConLosDatosCompletos(): void
    {
        $xml = $this->configuracion();

        \validarXML($xml, $this->datosDeConfiguracion());

        self::assertSame(
            ['No', '/dev/usb/lp1', 'Si', 'Referencia', 'Si', 'Si', 'No', '3'],
            [(string) $xml->impresion_ticket, (string) $xml->impresora_ticket, (string) $xml->corte_tickets, (string) $xml->input_pordefecto, (string) $xml->campo_peso, (string) $xml->btn_familias, (string) $xml->incidencias[0], (string) $xml->incidencias[1]]
        );
    }

    /** @comportamiento Si falta un dato, o el XML no tiene una de sus secciones, la configuración se rechaza con el nombre de lo que falta. */
    public function test_unaConfiguracionIncompletaSeRechaza(): void
    {
        $datos = $this->datosDeConfiguracion();
        unset($datos['corte_tickets']);

        $sinDato = null;
        try {
            \validarXML($this->configuracion(), $datos);
        } catch (\Exception $e) {
            $sinDato = $e->getMessage();
        }
        $sinSeccion = null;
        try {
            \validarXML(simplexml_load_string('<configuracion><impresion_ticket>Si</impresion_ticket></configuracion>'), $this->datosDeConfiguracion());
        } catch (\Exception $e) {
            $sinSeccion = $e->getMessage();
        }

        self::assertSame("Los datos no tienen la clave 'corte_tickets'", $sinDato);
        self::assertSame("El XML no tiene la sección 'impresora_ticket'", $sinSeccion);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tpv/funciones.php:1202-1256
     *
     * @group defecto
     * @group seguridad
     * @group critico
     *
     * @que-ocurre-hoy La configuración acepta como ruta de la impresora cualquier texto, también uno con
     *   órdenes para el sistema, que la comprobación de la impresora ejecuta después.
     * @que-deberia-ocurrir Que la ruta se valide como un dispositivo o una dirección de red.
     * @por-que-ocurre La validación solo mira que la clave exista.
     * @como-deberia-funcionar Validar la forma de la ruta antes de aceptarla.
     */
    public function test_unaRutaDeImpresoraConOrdenesSeRechaza(): void
    {
        $rechazo = null;
        try {
            \validarXML($this->configuracion(), $this->datosDeConfiguracion(['impresora_ticket' => '/dev/usb/lp0; echo hola']));
        } catch (\Exception $e) {
            $rechazo = $e->getMessage();
        }

        self::assertNotNull($rechazo);
    }

    /** @comportamiento Una impresora con ruta de fichero se da por buena si el fichero existe. */
    public function test_laImpresoraEsUnFicheroQueExiste(): void
    {
        touch($this->carpeta . '/impresora');

        self::assertTrue(\ComprobarImpresoraTickets($this->carpeta . '/impresora'));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tpv/funciones.php:724-746
     *
     * @group defecto
     * @group seguridad
     * @group critico
     *
     * @que-ocurre-hoy La ruta de la impresora se pega a una orden del sistema: lo que lleve detrás de un
     *   punto y coma se ejecuta. Aquí, crear un fichero en la carpeta del caso.
     * @que-deberia-ocurrir Que la ruta solo se compruebe, sin ejecutar nada.
     * @por-que-ocurre Se comprueba con `shell_exec('ls ' . $ruta)`.
     * @como-deberia-funcionar Comprobar el fichero con las funciones de PHP, sin pasar por el sistema.
     */
    public function test_laRutaDeLaImpresoraNoEjecutaOrdenes(): void
    {
        touch($this->carpeta . '/impresora');

        \ComprobarImpresoraTickets($this->carpeta . '/impresora; touch ' . $this->carpeta . '/ejecutado');

        self::assertFileDoesNotExist($this->carpeta . '/ejecutado');
    }
}
