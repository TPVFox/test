<?php

/**
 * `FacturasCompras`, la clase de la factura de proveedor: su temporal, su guardado —la cabecera, las
 * líneas activas, el desglose, los albaranes que factura, la forma de pago y el vencimiento—, la
 * modificación de una guardada, sus importes pagados, sus lecturas, su navegación y la comprobación de
 * temporales.
 *
 * La clase incluye la común con una ruta relativa a la carpeta del módulo: se carga desde ella. Recibe
 * la conexión del caso, en su transacción. `guardarFactura()` lee la sesión, la dirección y el
 * formulario: el caso los pone y los quita. Las líneas llevan artículos sembrados, los albaranes que se
 * facturan existen y los importes, una forma de pago sembrada: la base los une por clave ajena.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModCompras;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class FacturasComprasIntegracionTest extends CasoIntegracion
{
    private \FacturasCompras $facturas;

    private Siembra $siembra;

    private int $proveedor;

    private int $tienda;

    private int $usuario;

    /** @var array<int, int> */
    private array $articulos = [];

    protected function setUp(): void
    {
        parent::setUp();
        $cwd = (string) getcwd();
        chdir(RUTA_TPVFOX . '/modulos/mod_compras');
        try {
            $this->incluirTPVFox('/modulos/mod_compras/clases/facturasCompras.php');
        } finally {
            chdir($cwd);
        }
        $this->facturas = new \FacturasCompras($this->db);
        $this->siembra = new Siembra($this->db);
        $this->proveedor = $this->siembra->proveedor('Proveedor de la factura SL');
        $this->db->query("UPDATE proveedores SET nombrecomercial = 'Proveedor de la factura' WHERE idProveedor = {$this->proveedor}");
        $this->tienda = $this->siembra->tiendaPorDefecto();
        $this->usuario = $this->siembra->usuarioPorDefecto();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        parent::tearDown();
    }

    /**
     * @comportamiento Un temporal nuevo guarda sus productos y sus albaranes como texto JSON y su número de
     *   factura; modificarlo los cambia, con su fecha; sus totales se guardan aparte y se relaciona con su
     *   factura guardada; se lista con el proveedor y se elimina por su id o por su factura.
     */
    public function test_elTemporalSeGuardaSeModificaYSeElimina(): void
    {
        $id = (int) $this->facturas->insertarDatosFacturaTemporal($this->usuario, $this->tienda, 'Nuevo', '2026-02-03', [$this->linea(5)], $this->proveedor, [['idAdjunto' => '7']], 'F-1')['id'];
        $antes = $this->facturas->buscarFacturaTemporal($id);
        $modificado = $this->facturas->modificarDatosFacturaTemporal($this->usuario, $this->tienda, 'Sin Guardar', '2026-02-04', $id, [$this->linea(5), $this->linea(6, ['nfila' => 2])], [], 'F-2');
        $this->facturas->modTotales($id, 8.32, '0.32');
        $factura = $this->facturaGuardada();
        $this->facturas->addNumRealTemporal($id, $factura);
        $despues = $this->facturas->buscarFacturaTemporal($id);
        $listado = array_column($this->facturas->TodosTemporal($factura), null, 'id');
        $otro = (int) $this->facturas->insertarDatosFacturaTemporal($this->usuario, $this->tienda, 'Nuevo', '2026-02-03', [], $this->proveedor, [], '')['id'];
        $this->facturas->EliminarRegistroTemporal($otro, 0);
        $this->facturas->EliminarRegistroTemporal(0, $factura);

        self::assertSame(['Nuevo', 'F-1', '[{"idAdjunto":"7"}]', (string) $this->proveedor], [$antes['estadoFacPro'], $antes['Su_num_factura'], $antes['Albaranes'], $antes['idProveedor']]);
        self::assertSame($id, $modificado['idTemporal']);
        self::assertSame(['Sin Guardar', 'F-2', '2026-02-04 00:00:00', '[]', '8.320000', '0.32', (string) $factura], [$despues['estadoFacPro'], $despues['Su_num_factura'], $despues['Fecha'], $despues['Albaranes'], $despues['total'], $despues['total_ivas'], $despues['numfacpro']]);
        self::assertCount(2, json_decode($despues['Productos'], true));
        self::assertSame('Proveedor de la factura', $listado[(string) $id]['nombrecomercial']);
        self::assertSame(0, $this->cuantos('facproltemporales', "id IN ({$id}, {$otro})"));
    }

    /** @comportamiento Comprobar los temporales de una factura: sin ninguno, nada; con uno, su id; con dos, o con uno distinto del que se edita, el aviso de duplicidad. */
    public function test_comprobarLosTemporalesDeUnaFactura(): void
    {
        $factura = $this->facturaGuardada();
        $sinNinguno = $this->facturas->comprobarTemporalIdFacpro($factura);
        $uno = $this->temporal($factura);
        $conUno = $this->facturas->comprobarTemporalIdFacpro($factura);
        $distinto = $this->facturas->comprobarTemporalIdFacpro($factura, $uno + 1);
        $this->temporal($factura);
        $conDos = $this->facturas->comprobarTemporalIdFacpro($factura);

        self::assertSame([], $sinNinguno);
        self::assertSame(['idTemporal' => (string) $uno], $conUno);
        self::assertStringContainsString('no coincide el idtemporal', $distinto[0]['mensaje']);
        self::assertStringContainsString('Hay mas de un temporal con el mismo numero factura', $conDos[0]['mensaje']);
    }

    /**
     * @comportamiento Guardar el temporal de una factura nueva graba su cabecera «Guardado» con su número
     *   igual a su id, su número del proveedor y sus totales, las líneas activas numeradas con su albarán,
     *   el desglose y la relación con los albaranes activos, y quita el temporal.
     */
    public function test_guardarUnaFacturaNueva(): void
    {
        $albaran = $this->albaran();
        $temporal = $this->temporal(null, [$this->linea(5, ['nunidades' => 4, 'ncant' => 4, 'ultimoCoste' => 2, 'idalbpro' => $albaran]), $this->linea(6, ['estado' => 'Eliminado', 'nfila' => 2])], [['idAdjunto' => $albaran, 'NumAdjunto' => 31, 'estado' => 'activo'], ['idAdjunto' => $albaran, 'NumAdjunto' => 31, 'estado' => 'Eliminado']]);

        $avisos = $this->guardar($temporal);

        $id = (int) $this->fila("SELECT id FROM facprot WHERE idProveedor = {$this->proveedor}")['id'];
        self::assertSame([], $avisos);
        self::assertSame([(string) $id, 'F-7', 'Guardado', '2026-02-05 00:00:00', '8.000000', '8.32'], array_values($this->fila("SELECT Numfacpro, Su_num_factura, estado, Fecha, total_siniva, total FROM facprot WHERE id = {$id}")));
        self::assertSame([[(string) $this->articulo(5), '1', (string) $albaran]], $this->db->query("SELECT idArticulo, nfila, idalbpro FROM facprolinea WHERE idfacpro = {$id}")->fetch_all());
        self::assertSame([['4', '0.32', '8.00']], $this->db->query("SELECT iva, importeIva, totalbase FROM facproIva WHERE idfacpro = {$id}")->fetch_all());
        self::assertSame([[(string) $albaran, '31']], $this->db->query("SELECT idAlbaran, numAlbaran FROM albprofac WHERE idFactura = {$id}")->fetch_all());
        self::assertSame(0, $this->cuantos('facproltemporales', "id = {$temporal}"));
    }

    /**
     * @comportamiento Guardar el temporal de una factura guardada cambia su cabecera —fecha, número del
     *   proveedor, forma de pago, vencimiento, quién y cuándo— y sustituye sus líneas, su desglose y sus
     *   albaranes; quita sus temporales.
     */
    public function test_guardarUnaFacturaModificada(): void
    {
        $factura = $this->facturaGuardada();
        $this->db->query("INSERT INTO facprolinea (idfacpro, Numfacpro, idArticulo, cref, ccodbar, cdetalle, ncant, nunidades, costeSiva, iva, nfila, estadoLinea, ref_prov, idalbpro) VALUES ({$factura}, 901, {$this->articulo(5)}, 'R', '', 'Antigua', 1, 1, 1, 4, 1, 'Activo', '', 0)");
        $forma = $this->formaDePago();
        $temporal = $this->temporal($factura, [$this->linea(7, ['nunidades' => 2, 'ncant' => 2, 'ultimoCoste' => 3])]);

        $avisos = $this->guardar($temporal, ['formaVenci' => (string) $forma, 'fechaVenci' => '2026-03-05']);

        self::assertSame([], $avisos);
        self::assertSame(['2026-02-05 00:00:00', 'F-7', (string) $forma, '2026-03-05', '6.24', (string) $this->usuario], array_values($this->fila("SELECT Fecha, Su_num_factura, formaPago, FechaVencimiento, total, modify_by FROM facprot WHERE id = {$factura}")));
        self::assertSame([[(string) $this->articulo(7)]], $this->db->query("SELECT idArticulo FROM facprolinea WHERE idfacpro = {$factura}")->fetch_all());
        self::assertSame(0, $this->cuantos('facproltemporales', "numfacpro = {$factura}"));
    }

    /** @comportamiento Sin la tienda o el usuario de la sesión, sin temporal en la dirección o con la fecha vacía, guardar avisa y no graba. */
    public function test_guardarSinSesionSinTemporalOSinFechaAvisa(): void
    {
        $temporal = $this->temporal();

        $sinSesion = $this->guardar($temporal, [], false);
        $sinTemporal = $this->guardar(0);
        $sinFecha = $this->guardar($temporal, ['fecha' => '']);

        self::assertSame('ERROR NO HAY DATOS DE SESIÓN!', $sinSesion[0]['mensaje']);
        self::assertSame('El temporal ya no existe  !', $sinTemporal[0]['mensaje']);
        self::assertSame('Has dejado el campo fecha sin cubrir !', $sinFecha[0]['mensaje']);
        self::assertSame(0, $this->cuantos('facprot', "idProveedor = {$this->proveedor}"));
    }

    /**
     * @comportamiento Una factura se lee con sus productos —con los nombres del formulario—, su desglose y
     *   sus albaranes; su suma da la base y la cuota; se busca por su número; las facturas se listan con su
     *   proveedor según la condición, y todas.
     */
    public function test_leerYListarLasFacturas(): void
    {
        $factura = $this->facturaGuardada();
        $albaran = $this->albaran();
        $this->db->query("INSERT INTO facprolinea (idfacpro, Numfacpro, idArticulo, cref, ccodbar, cdetalle, ncant, nunidades, costeSiva, iva, nfila, estadoLinea, ref_prov, idalbpro) VALUES ({$factura}, 901, {$this->articulo(5)}, 'R', '', 'Pera', 3, 3, 2, 4, 1, 'Activo', '', {$albaran})");
        $this->db->query("INSERT INTO facproIva (idfacpro, Numfacpro, iva, importeIva, totalbase) VALUES ({$factura}, 901, 4, 0.24, 6)");
        $this->facturas->AddFacturaAlbaran($factura, $albaran);
        $this->facturas->AddFacturaAlbaran($factura, $albaran);

        $leida = $this->facturas->GetFactura($factura);
        $suma = $this->facturas->sumarIva(901);
        $listado = $this->facturas->TodosFacturaLimite("WHERE a.idProveedor = {$this->proveedor}");

        self::assertSame(['901', 'Guardado'], [$leida['Numfacpro'], $leida['estado']]);
        self::assertSame([(string) $this->articulo(5), '2.0000', 'Activo', (string) $albaran], [$leida['Productos'][0]['idArticulo'], $leida['Productos'][0]['ultimoCoste'], $leida['Productos'][0]['estado'], $leida['Productos'][0]['idalbpro']]);
        self::assertCount(1, $leida['Albaranes']);
        self::assertCount(1, $this->facturas->IvasFactura($factura));
        self::assertEqualsWithDelta([0.24, 6.0], [(float) $suma['importeIva'], (float) $suma['totalbase']], 0.001);
        self::assertSame((string) $factura, $this->facturas->buscarFacturaNumero(901)['id']);
        self::assertSame([['Proveedor de la factura', 'Guardado']], array_map(static fn (array $f): array => [$f['nombrecomercial'], $f['estado']], $listado['Items']));
        self::assertContains((string) $factura, array_column($this->facturas->TodosFactura(), 'id'));
    }

    /**
     * @comportamiento Los importes pagados de una factura se leen y se borran; los de su temporal se escriben
     *   como texto y se leen; el estado, la fecha y el número del proveedor se cambian; los estados se listan.
     */
    public function test_importesEstadoYDatosDeUnaFactura(): void
    {
        $factura = $this->facturaGuardada();
        $forma = $this->formaDePago();
        $this->db->query("INSERT INTO facProCobros (idFactura, idFormasPago, FechaPago, importe, Referencia) VALUES ({$factura}, {$forma}, '2026-02-10', 4, 'T-1')");
        $temporal = $this->temporal($factura);

        $importes = $this->facturas->importesFactura($factura);
        $this->facturas->eliminarRealImportes($factura);
        $this->facturas->modificarImportesTemporal($temporal, '[{"importe":"4"}]');
        $this->facturas->modEstadoFactura($factura, 'Sin Guardar');
        $this->facturas->modFechaNumero($factura, '2026-02-09', 'F-9');

        self::assertSame(['4', 'T-1'], [(string) $importes[0]['importe'], $importes[0]['Referencia']]);
        self::assertSame(0, $this->cuantos('facProCobros', "idFactura = {$factura}"));
        self::assertSame(['FacCobros' => '[{"importe":"4"}]'], $this->facturas->importesTemporal($temporal));
        self::assertSame(['Sin Guardar', '2026-02-09 00:00:00', 'F-9'], array_values($this->fila("SELECT estado, Fecha, Su_num_factura FROM facprot WHERE id = {$factura}")));
        self::assertContains('Sin Guardar', $this->facturas->getEstadosFacturas());
    }

    /** @comportamiento La primera, la última, la anterior y la siguiente factura se buscan por su número, de todas o de un proveedor; sin ninguna, 0. */
    public function test_laNavegacionEntreFacturas(): void
    {
        $otro = $this->siembra->proveedor('Otro proveedor');
        $primera = $this->facturaGuardada(90001);
        $this->facturaGuardada(90002, $otro);
        $tercera = $this->facturaGuardada(90003);

        self::assertSame(['90001', '90003'], [(string) $this->facturas->getPrimeraFactura($this->proveedor), (string) $this->facturas->getUltimaFactura($this->proveedor)]);
        self::assertSame(['90003', '90001'], [(string) $this->facturas->getFacturaAnteriorSiguiente(90001, $this->proveedor, 'siguiente'), (string) $this->facturas->getFacturaAnteriorSiguiente(90003, $this->proveedor, 'anterior')]);
        self::assertSame(['90002', '90002'], [(string) $this->facturas->getFacturaAnteriorSiguiente(90001, 0, 'siguiente'), (string) $this->facturas->getFacturaAnteriorSiguiente(90003, 0, 'anterior')]);
        self::assertSame(0, $this->facturas->getFacturaAnteriorSiguiente(90003, $this->proveedor, 'siguiente'));
        self::assertSame(0, $this->facturas->getPrimeraFactura($this->siembra->proveedor('Sin facturas')));
        self::assertNotSame($primera, $tercera);
    }

    /** @comportamiento Borrar una tabla de una factura —sus líneas, su desglose o sus albaranes— quita solo esa. */
    public function test_borrarUnaTablaDeUnaFactura(): void
    {
        $factura = $this->facturaGuardada();
        $this->db->query("INSERT INTO facproIva (idfacpro, Numfacpro, iva, importeIva, totalbase) VALUES ({$factura}, 901, 4, 0.04, 1)");
        $this->facturas->AddFacturaAlbaran($factura, $this->albaran());

        $this->facturas->eliminarFacturasTablas($factura, 'albprofac');

        self::assertSame([1, 1, 0], [$this->cuantos('facprot', "id = {$factura}"), $this->cuantos('facproIva', "idfacpro = {$factura}"), $this->cuantos('albprofac', "idFactura = {$factura}")]);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/clases/facturasCompras.php:356-383
     *
     * @group defecto
     * @group seguridad
     * @group alto
     *
     * @que-ocurre-hoy Una línea cuya descripción lleva comillas dobles rompe la sentencia de las líneas: el
     *   guardado termina en una excepción de la base y la factura queda sin líneas.
     * @que-deberia-ocurrir Que la factura se guarde con la descripción tal como se escribió.
     * @por-que-ocurre Los datos de las líneas se pegan a la sentencia.
     * @como-deberia-funcionar Pasarlos como parámetros.
     */
    public function test_unaDescripcionConComillasSeGuarda(): void
    {
        $temporal = $this->temporal(null, [$this->linea(5, ['cdetalle' => 'Pera "de agua"'])]);

        try {
            $this->guardar($temporal);
        } catch (\mysqli_sql_exception) {
        }

        self::assertSame('Pera "de agua"', $this->fila("SELECT l.cdetalle FROM facprolinea l JOIN facprot c ON c.id = l.idfacpro WHERE c.idProveedor = {$this->proveedor}")['cdetalle'] ?? null);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/clases/facturasCompras.php:308-412
     *
     * @group defecto
     * @group integridad
     * @group alto
     *
     * @que-ocurre-hoy Si una parte del guardado falla —aquí, el desglose—, la cabecera y las líneas ya
     *   escritas se quedan: la factura queda a medias en el libro de facturas recibidas.
     * @que-deberia-ocurrir Que un guardado que falla no deje nada.
     * @por-que-ocurre Cada sentencia se ejecuta por su cuenta, sin transacción.
     * @como-deberia-funcionar Guardar la cabecera, las líneas, el desglose y los albaranes en una transacción.
     */
    public function test_unGuardadoQueFallaNoDejaLaFacturaAMedias(): void
    {
        $datos = $this->datosFactura([$this->linea(5)]);
        $datos['DatosTotales']['desglose'] = ['x' => ['iva' => 0.04, 'base' => 1]];

        try {
            $this->facturas->AddFacturaGuardado($datos, 0);
        } catch (\mysqli_sql_exception) {
        }

        self::assertSame(0, $this->cuantos('facprot', "idProveedor = {$this->proveedor}"));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/clases/facturasCompras.php:332-335
     *
     * @group defecto
     * @group integridad
     * @group alto
     *
     * @que-ocurre-hoy Una factura nueva se guarda sin su forma de pago ni su vencimiento: la sentencia que la
     *   crea no los lleva, aunque el guardado los recibe del formulario.
     * @que-deberia-ocurrir Que la factura nueva lleve la forma de pago y el vencimiento con que se guardó.
     * @por-que-ocurre Solo la sentencia que modifica una factura existente escribe esos dos campos.
     * @como-deberia-funcionar Escribirlos también al crearla.
     */
    public function test_unaFacturaNuevaSeGuardaConSuFormaDePagoYSuVencimiento(): void
    {
        $forma = $this->formaDePago();
        $temporal = $this->temporal();

        $this->guardar($temporal, ['formaVenci' => (string) $forma, 'fechaVenci' => '2026-03-05']);

        self::assertSame([(string) $forma, '2026-03-05'], array_values($this->fila("SELECT formaPago, FechaVencimiento FROM facprot WHERE idProveedor = {$this->proveedor}") ?? []));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/clases/facturasCompras.php:614-765; modulos/mod_compras/tareas/insertarImporte.php:24-32
     *
     * @group defecto
     * @group integridad
     * @group alto
     *
     * @que-ocurre-hoy Los importes pagados que se añaden a una factura van a su temporal, y guardarla no los
     *   escribe en sus importes: se pierden al quitar el temporal. Añadir uno a una factura que ya tenía
     *   importes guardados los borra de ella y los pasa al temporal.
     * @que-deberia-ocurrir Que guardar la factura guarde sus importes.
     * @por-que-ocurre Nada escribe en la tabla de importes de las facturas.
     * @como-deberia-funcionar Escribir los importes del temporal al guardar la factura.
     */
    public function test_guardarUnaFacturaGuardaSusImportes(): void
    {
        $forma = $this->formaDePago();
        $temporal = $this->temporal();
        $this->facturas->modificarImportesTemporal($temporal, (string) json_encode([['importe' => '4', 'fecha' => '2026-02-10', 'forma' => (string) $forma, 'referencia' => 'T-1', 'pendiente' => '1.20']]));

        $this->guardar($temporal);

        $id = (int) ($this->fila("SELECT id FROM facprot WHERE idProveedor = {$this->proveedor}")['id'] ?? 0);
        self::assertSame(1, $this->cuantos('facProCobros', "idFactura = {$id}"));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/clases/facturasCompras.php:614-765
     *
     * @group defecto
     * @group integridad
     * @group alto
     *
     * @que-ocurre-hoy Guardar una factura con el número del proveedor de otra suya ya registrada la guarda:
     *   la misma factura recibida entra dos veces en el libro.
     * @que-deberia-ocurrir Que el guardado lo avise y no guarde la segunda.
     * @por-que-ocurre No se comprueba el número del proveedor.
     * @como-deberia-funcionar Comprobarlo al guardar una factura nueva.
     */
    public function test_unNumeroDeFacturaRepetidoNoSeGuardaDosVeces(): void
    {
        $this->facturaGuardada();
        $temporal = $this->temporal();

        $this->guardar($temporal);

        self::assertSame(1, $this->cuantos('facprot', "idProveedor = {$this->proveedor} AND Su_num_factura = 'F-7'"));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/clases/facturasCompras.php:245-257; modulos/mod_compras/factura.php:134-137
     *
     * @group defecto
     * @group integridad
     * @group medio
     *
     * @que-ocurre-hoy Una factura no se puede dejar «Contabilizado»: el estado tiene trece letras y la base
     *   solo admite doce, y rechaza el cambio. La pantalla y el listado protegen la factura contabilizada,
     *   pero esa protección no se alcanza nunca.
     * @que-deberia-ocurrir Que una factura se pueda contabilizar y quede protegida.
     * @por-que-ocurre El estado de la factura es de doce caracteres; nada en el programa escribe ese estado.
     * @como-deberia-funcionar Un estado que quepa, o una columna que lo admita, y la orden que contabiliza.
     */
    public function test_unaFacturaSePuedeContabilizar(): void
    {
        $factura = $this->facturaGuardada();

        try {
            $this->facturas->modEstadoFactura($factura, 'Contabilizado');
        } catch (\mysqli_sql_exception) {
        }

        self::assertSame('Contabilizado', $this->fila("SELECT estado FROM facprot WHERE id = {$factura}")['estado']);
    }

    /** El artículo sembrado del caso con ese número, creado la primera vez que se pide. */
    private function articulo(int $n): int
    {
        return $this->articulos[$n] ??= $this->siembra->articulo('Producto ' . $n);
    }

    /** @param array<string, mixed> $cambios */
    private function linea(int $n, array $cambios = []): array
    {
        return $cambios + ['idArticulo' => $this->articulo($n), 'cref' => 'REF-' . $n, 'ccodbar' => '', 'cdetalle' => 'Producto ' . $n, 'ref_prov' => '', 'ncant' => 1, 'nunidades' => 1, 'ultimoCoste' => 1, 'iva' => 4, 'nfila' => 1, 'estado' => 'Activo'];
    }

    /**
     * Los datos que el guardado arma para grabar, con los totales de las líneas.
     *
     * @param list<array<string, mixed>> $lineas
     * @return array<string, mixed>
     */
    private function datosFactura(array $lineas): array
    {
        $totales = $this->facturas->recalculoTotales($lineas);

        return ['fecha' => '2026-02-03', 'idTienda' => $this->tienda, 'idUsuario' => $this->usuario, 'idProveedor' => $this->proveedor, 'estado' => 'Guardado', 'total' => $totales['total'],
            'total_siniva' => (float) $totales['total'] - (float) $totales['subivas'], 'DatosTotales' => $totales, 'productos' => json_encode($lineas), 'albaranes' => '[]', 'suNumero' => 'F-7', 'formaPago' => '', 'fechaVenci' => ''];
    }

    private function facturaGuardada(int $numero = 901, ?int $proveedor = null): int
    {
        $proveedor ??= $this->proveedor;
        $this->db->query("INSERT INTO facprot (Numfacpro, Su_num_factura, Fecha, idTienda, idUsuario, idProveedor, estado, total_siniva, total) VALUES ({$numero}, 'F-7', '2026-02-01', {$this->tienda}, {$this->usuario}, {$proveedor}, 'Guardado', 1, 1.04)");

        return (int) $this->db->insert_id;
    }

    private function albaran(): int
    {
        $this->db->query("INSERT INTO albprot (Numalbpro, Su_numero, Fecha, idTienda, idUsuario, idProveedor, estado, total_siniva, total) VALUES (31, 'A-31', '2026-02-01', {$this->tienda}, {$this->usuario}, {$this->proveedor}, 'Facturado', 8, 8.32)");

        return (int) $this->db->insert_id;
    }

    private function formaDePago(): int
    {
        $this->db->query("INSERT INTO formasPago (descripcion) VALUES ('Transferencia')");

        return (int) $this->db->insert_id;
    }

    /**
     * @param list<array<string, mixed>>|null $lineas
     * @param list<array<string, mixed>> $albaranes
     */
    private function temporal(?int $factura = null, ?array $lineas = null, array $albaranes = []): int
    {
        $id = (int) $this->facturas->insertarDatosFacturaTemporal($this->usuario, $this->tienda, $factura === null ? 'Nuevo' : 'Sin Guardar', '2026-02-03', $lineas ?? [$this->linea(5)], $this->proveedor, $albaranes, 'F-7')['id'];
        if ($factura !== null) {
            $this->facturas->addNumRealTemporal($id, $factura);
        }

        return $id;
    }

    /**
     * Los avisos de `guardarFactura()` con la sesión, la dirección y el formulario del caso.
     *
     * @param array<string, string> $formulario
     * @return list<array<string, string>>
     */
    private function guardar(int $temporal, array $formulario = [], bool $conSesion = true): array
    {
        $_SESSION = $conSesion ? ['tiendaTpv' => ['idTienda' => $this->tienda], 'usuarioTpv' => ['id' => $this->usuario]] : ['tiendaTpv' => [], 'usuarioTpv' => []];
        $_POST = $formulario + ['fecha' => '05-02-2026', 'estado' => 'Nuevo', 'suNumero' => 'F-7'];
        $_GET = $temporal > 0 ? ['tActual' => (string) $temporal] : [];

        return $this->facturas->guardarFactura();
    }

    private function cuantos(string $tabla, string $donde): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM {$tabla} WHERE {$donde}")->fetch_row()[0];
    }

    /** @return array<string, string|null>|null */
    private function fila(string $sql): ?array
    {
        return $this->db->query($sql)->fetch_assoc();
    }
}
