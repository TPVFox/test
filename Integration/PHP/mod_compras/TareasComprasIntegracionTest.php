<?php

/**
 * La petición de fondo de las compras (`modulos/mod_compras/tareas.php`) y sus órdenes comunes de
 * `tareas/`: las incidencias, buscar proveedores, productos y adjuntos, relacionar un producto con su
 * proveedor, comprobar los adjuntos, cancelar y eliminar temporales, cambiar estados, las ventanas, las
 * filas que se añaden a un documento, el formulario del correo y la impresión.
 *
 * Cada petición se ejecuta en un PHP aparte, desde la carpeta del módulo, con la sesión del usuario de
 * pruebas o sin ninguna, en una transacción sobre su conexión que nunca se confirma; lo que necesita se
 * siembra en ella. El documento impreso se escribe en una carpeta del caso, que se borra al terminar.
 * Ningún caso envía un correo: la orden de enviarlo no se ejecuta.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModCompras;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class TareasComprasIntegracionTest extends CasoIntegracion
{
    private int $idUsuario;

    private int $idTienda;

    private string $carpeta;

    protected function setUp(): void
    {
        parent::setUp();
        $siembra = new Siembra($this->db);
        $this->idUsuario = $siembra->usuarioPorDefecto();
        $this->idTienda = $siembra->tiendaPorDefecto();
        $this->carpeta = sys_get_temp_dir() . '/compras-' . getmypid() . '-' . bin2hex(random_bytes(4));
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

    /** @comportamiento Abrir una incidencia responde su ventana con el documento de origen. */
    public function test_abrirUnaIncidenciaRespondeSuVentana(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'abririncidencia', 'dedonde' => 'albaran', 'idReal' => '31', 'configuracion' => [['descripcion' => 'mostrar_Select', 'valor' => 'No', 'dedonde' => 'albaran'], ['descripcion' => 'usuario_Defecto', 'valor' => '1', 'dedonde' => 'albaran']]]);

        self::assertSame('{"vista":"albaran","idReal":"31"}', $peticion['respuesta']['datos']);
        self::assertStringContainsString('albaran', $peticion['respuesta']['html']);
    }

    /** @comportamiento Una incidencia nueva con mensaje se guarda en el módulo de compras, con el usuario elegido en sus datos; sin mensaje, no. */
    public function test_unaIncidenciaNuevaSeGuardaConSuMensaje(): void
    {
        $datos = '{"vista":"albaran","idReal":"31"}';

        $guardada = $this->ejecutar(['pulsado' => 'nuevaIncidencia', 'datos' => $datos, 'estado' => 'No resuelto', 'mensaje' => 'Falta una caja', 'usuarioSelec' => '5']);
        $sinMensaje = $this->ejecutar(['pulsado' => 'nuevaIncidencia', 'datos' => $datos, 'estado' => 'No resuelto', 'mensaje' => '']);

        self::assertSame([['mod_compras', 'Falta una caja', '{"vista":"albaran","idReal":"31","usuarioSelec":"5"}']], $guardada['incidencias']);
        self::assertSame([], $sinMensaje['incidencias']);
    }

    /** @comportamiento Las incidencias adjuntas de un documento se responden en su ventana. */
    public function test_lasIncidenciasAdjuntasSeRespondenEnSuVentana(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'abrirIncidenciasAdjuntas', 'id' => '31', 'modulo' => 'mod_compras', 'dedonde' => 'albaran'], [
            "INSERT INTO modulo_incidencia (num_incidencia, fecha_creacion, id_usuario, dedonde, mensaje, datos, estado) VALUES (900001, '2026-02-03', :usuario, 'mod_compras', 'Falta una caja', '{\"vista\":\"albaran\",\"idReal\":\"31\"}', 'No resuelto')",
        ]);

        self::assertStringContainsString('Falta una caja', $peticion['respuesta']['html']);
    }

    /**
     * @comportamiento Buscar proveedores sin nada lista todos; por su número, uno activo se elige con su
     *   nombre y uno inactivo se lista; por su nombre, la lista de los que coinciden. La lista va dentro del
     *   resultado de su función, como la lee el JavaScript.
     */
    public function test_buscarProveedores(): void
    {
        $preparacion = [
            "INSERT INTO proveedores (nombrecomercial, razonsocial, nif, direccion, fecha_creado, estado) VALUES ('Frutas compra', 'Frutas compra SL', 'X1', 'Sin direccion', NOW(), 'Activo')",
            "INSERT INTO proveedores (nombrecomercial, razonsocial, nif, direccion, fecha_creado, estado) VALUES ('Frutas inactiva', 'Frutas inactiva SL', 'X2', 'Sin direccion', NOW(), 'inactivo')",
        ];

        $todos = $this->ejecutar(['pulsado' => 'buscarProveedor', 'busqueda' => '', 'dedonde' => 'albaran', 'idcaja' => 'id_proveedor'], $preparacion);
        $activo = $this->ejecutar(['pulsado' => 'buscarProveedor', 'busqueda' => ':p1', 'dedonde' => 'albaran', 'idcaja' => 'id_proveedor'], $preparacion);
        $inactivo = $this->ejecutar(['pulsado' => 'buscarProveedor', 'busqueda' => ':p2', 'dedonde' => 'albaran', 'idcaja' => 'id_proveedor'], $preparacion);
        $nombre = $this->ejecutar(['pulsado' => 'buscarProveedor', 'busqueda' => 'Frutas', 'dedonde' => 'albaran', 'idcaja' => 'Proveedor'], $preparacion);

        self::assertGreaterThanOrEqual(2, $todos['respuesta']['Nitems']);
        self::assertSame([$activo['ids'][':p1'], 'Frutas compra', 1], [(string) $activo['respuesta']['id'], $activo['respuesta']['nombre'], $activo['respuesta']['Nitems']]);
        self::assertSame(0, $inactivo['respuesta']['id']);
        self::assertStringContainsString('class="FilaModal danger"', $inactivo['respuesta']['html']['html']);
        self::assertStringContainsString('Frutas compra SL', $nombre['respuesta']['html']['html']);
        self::assertStringContainsString('Frutas inactiva SL', $nombre['respuesta']['html']['html']);
    }

    /** @comportamiento Buscar productos con un solo resultado responde sus datos; con varios, o desde la caja del listado, el listado. */
    public function test_buscarProductos(): void
    {
        $preparacion = [
            "INSERT INTO proveedores (nombrecomercial, razonsocial, nif, direccion, fecha_creado, estado) VALUES ('Frutas compra', 'Frutas compra SL', 'X1', 'Sin direccion', NOW(), 'Activo')",
            "INSERT INTO articulos (iva, articulo_name, estado, fecha_creado, ultimoCoste, tipo) VALUES (4, 'Pera única de compra', 'Activo', NOW(), 1, 'unidad')",
            "INSERT INTO articulos (iva, articulo_name, estado, fecha_creado, ultimoCoste, tipo) VALUES (4, 'Manzana uno de compra', 'Activo', NOW(), 1, 'unidad')",
            "INSERT INTO articulos (iva, articulo_name, estado, fecha_creado, ultimoCoste, tipo) VALUES (4, 'Manzana dos de compra', 'Activo', NOW(), 1, 'unidad')",
        ];
        $formulario = ['pulsado' => 'buscarProductos', 'campo' => 'a.articulo_name', 'id_input' => 'Descripcion', 'idcaja' => 'Descripcion', 'idProveedor' => ':p1', 'dedonde' => 'albaran'];

        $uno = $this->ejecutar(['valorCampo' => 'Pera única de compra'] + $formulario, $preparacion);
        $varios = $this->ejecutar(['valorCampo' => 'Manzana de compra'] + $formulario, $preparacion);
        $caja = $this->ejecutar(['valorCampo' => 'Pera única de compra', 'idcaja' => 'cajaBusqueda'] + $formulario, $preparacion);

        self::assertSame([1, $uno['ids'][':a1']], [$uno['respuesta']['Nitems'], $uno['respuesta']['datos'][0]['idArticulo']]);
        self::assertSame(2, $varios['respuesta']['Nitems']);
        self::assertStringContainsString('Manzana dos de compra', $varios['respuesta']['html']);
        self::assertArrayHasKey('html', $caja['respuesta']);
    }

    /** @comportamiento Relacionar un producto con un proveedor lo da de alta con su referencia y su coste; si ya estaba, cambia su referencia. */
    public function test_relacionarUnProductoConSuProveedor(): void
    {
        $preparacion = [
            "INSERT INTO proveedores (nombrecomercial, razonsocial, nif, direccion, fecha_creado, estado) VALUES ('Frutas compra', 'Frutas compra SL', 'X1', 'Sin direccion', NOW(), 'Activo')",
            "INSERT INTO articulos (iva, articulo_name, estado, fecha_creado, ultimoCoste, tipo) VALUES (4, 'Pera de compra', 'Activo', NOW(), 1, 'unidad')",
        ];
        $formulario = ['pulsado' => 'addProveedorArticulo', 'idArticulo' => ':a1', 'idProveedor' => ':p1', 'refProveedor' => 'PER-1', 'coste' => '1.10'];

        $nueva = $this->ejecutar($formulario, $preparacion);
        $cambiada = $this->ejecutar(['refProveedor' => 'PER-2', 'coste' => '9'] + $formulario, array_merge($preparacion, ["INSERT INTO articulosProveedores (idArticulo, idProveedor, crefProveedor, coste, fechaActualizacion, estado) VALUES (:a1, :p1, 'PER-1', 1.1, '2026-01-01', 'activo')"]));

        self::assertSame([['PER-1', '1.100000', date('Y-m-d'), 'activo']], $nueva['relaciones']);
        self::assertSame([['PER-2', '1.100000', '2026-01-01', 'activo']], $cambiada['relaciones']);
    }

    /**
     * @comportamiento Buscar el pedido que se añade a un albarán: con uno guardado del proveedor, sus datos
     *   y sus productos; con varios, la ventana para elegir; sin ninguno, el aviso.
     */
    public function test_buscarElPedidoQueSeAnadeAUnAlbaran(): void
    {
        $preparacion = [
            "INSERT INTO proveedores (nombrecomercial, razonsocial, nif, direccion, fecha_creado, estado) VALUES ('Frutas compra', 'Frutas compra SL', 'X1', 'Sin direccion', NOW(), 'Activo')",
            "INSERT INTO pedprot (Numpedpro, Numtemp_pedpro, Fecha, idTienda, idUsuario, idProveedor, estado, total_siniva, total) VALUES (901, 0, '2026-02-01 10:00:00', :tienda, :usuario, :p1, 'Guardado', 10, 10.4)",
            "INSERT INTO pedprolinea (idpedpro, Numpedpro, idArticulo, cref, ref_prov, ccodbar, cdetalle, ncant, nunidades, costeSiva, iva, nfila, estadoLinea) SELECT :ped1, 901, MIN(idArticulo), '', '', '', 'Pera', 5, 5, 2, 4, 1, 'Activo' FROM articulos",
        ];
        $formulario = ['pulsado' => 'buscarAdjunto', 'numAdjunto' => '0', 'idProveedor' => ':p1', 'dedonde' => 'albaran'];

        $uno = $this->ejecutar($formulario, $preparacion);
        $varios = $this->ejecutar($formulario, array_merge($preparacion, ["INSERT INTO pedprot (Numpedpro, Numtemp_pedpro, Fecha, idTienda, idUsuario, idProveedor, estado, total_siniva, total) VALUES (902, 0, '2026-02-02', :tienda, :usuario, :p1, 'Guardado', 5, 5.2)"]));
        $ninguno = $this->ejecutar(['numAdjunto' => '999'] + $formulario, $preparacion);

        self::assertSame([1, '901', '2026-02-01', 'activo', '10.000000'], [$uno['respuesta']['Nitems'], $uno['respuesta']['datos']['NumAdjunto'], $uno['respuesta']['datos']['fecha'], $uno['respuesta']['datos']['estado'], $uno['respuesta']['datos']['totalSiva']]);
        self::assertCount(1, $uno['respuesta']['productos']);
        self::assertStringContainsString("buscarAdjunto('albaran', 902);", $varios['respuesta']['html']);
        self::assertStringContainsString('No se encontro resultado', $ninguno['respuesta']['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/tareas/buscarAdjunto.php:1-15, :57-64; modulos/mod_compras/clases/pedidosCompras.php:558-593
     *
     * @group defecto
     * @group errores
     * @group medio
     *
     * @que-ocurre-hoy Buscar un adjunto sin proveedor termina en un error de PHP, sin respuesta: la consulta
     *   se arma sin el proveedor y la base la rechaza antes de llegar al aviso que la orden tiene para ese caso.
     * @que-deberia-ocurrir Que responda que falta el proveedor.
     * @por-que-ocurre El aviso se da tras la consulta, cuando la consulta ya falló.
     * @como-deberia-funcionar Comprobar el proveedor antes de consultar.
     */
    public function test_buscarUnAdjuntoSinProveedorDiceQueFalta(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'buscarAdjunto', 'numAdjunto' => '0', 'idProveedor' => '', 'dedonde' => 'albaran']);

        self::assertSame('No envias id de proveedor', $peticion['respuesta']['error'] ?? null);
    }

    /** @comportamiento Comprobar los adjuntos de un proveedor dice si tiene albaranes guardados que facturar, o pedidos guardados que servir. */
    public function test_comprobarSiUnProveedorTieneAdjuntos(): void
    {
        $preparacion = [
            "INSERT INTO proveedores (nombrecomercial, razonsocial, nif, direccion, fecha_creado, estado) VALUES ('Frutas compra', 'Frutas compra SL', 'X1', 'Sin direccion', NOW(), 'Activo')",
            "INSERT INTO albprot (Numalbpro, Su_numero, Fecha, idTienda, idUsuario, idProveedor, estado, total_siniva, total) VALUES (901, '', '2026-02-01', :tienda, :usuario, :p1, 'Guardado', 10, 10.4)",
        ];

        $factura = $this->ejecutar(['pulsado' => 'comprobarAdjunto', 'idProveedor' => ':p1', 'dedonde' => 'factura'], $preparacion);
        $albaran = $this->ejecutar(['pulsado' => 'comprobarAdjunto', 'idProveedor' => ':p1', 'dedonde' => 'albaran'], $preparacion);

        self::assertSame([1, 2], [$factura['respuesta']['bandera'], $albaran['respuesta']['bandera']]);
    }

    /** @comportamiento Cancelar un temporal lo hace según su documento, y eliminar el de un pedido o el de un albarán desde su listado lo quita; desde otro sitio, no se hace. */
    public function test_cancelarYEliminarTemporales(): void
    {
        $preparacion = [
            "INSERT INTO proveedores (nombrecomercial, razonsocial, nif, direccion, fecha_creado, estado) VALUES ('Frutas compra', 'Frutas compra SL', 'X1', 'Sin direccion', NOW(), 'Activo')",
            "INSERT INTO pedprotemporales (estadoPedPro, idTienda, idUsuario, Fecha, fechaInicio, idProveedor, total, Productos) VALUES ('Sin guardar', :tienda, :usuario, NOW(), NOW(), :p1, 0, '[]')",
            "INSERT INTO albproltemporales (Su_numero, estadoAlbPro, idTienda, idUsuario, Fecha, fechaInicio, idProveedor, total, Productos) VALUES ('', 'Sin guardar', :tienda, :usuario, NOW(), NOW(), :p1, 0, '[]')",
        ];

        $cancelado = $this->ejecutar(['pulsado' => 'cancelarTemporal', 'idTemporal' => ':tped1', 'dedonde' => 'pedido'], $preparacion);
        $eliminado = $this->ejecutar(['pulsado' => 'eliminarTemporal', 'id_temporal' => ':talb1', 'dedonde' => 'ListadoAlbaranes'], $preparacion);
        $otro = $this->ejecutar(['pulsado' => 'eliminarTemporal', 'id_temporal' => ':talb1', 'dedonde' => 'Otro'], $preparacion);

        self::assertSame([[], 1], [$cancelado['respuesta'], count($cancelado['albaranesTemporales'])]);
        self::assertSame([[], 1], [$cancelado['pedidosTemporales'], $eliminado['respuesta']['valores_insert']]);
        self::assertSame([], $eliminado['albaranesTemporales']);
        self::assertSame(['error' => 'No se ejecuto.'], $otro['respuesta']);
    }

    /** @comportamiento Cambiar el estado de varios albaranes pone el elegido en cada uno; sin elegir, el «0» de la ventana, no hace nada. */
    public function test_cambiarElEstadoDeVariosAlbaranes(): void
    {
        $preparacion = $this->dosAlbaranes();

        $validados = $this->ejecutar(['pulsado' => 'cambiarEstadoVariosAlbaranes', 'ids' => [':alb1', ':alb2'], 'estado' => 'Validado'], $preparacion);
        $sinElegir = $this->ejecutar(['pulsado' => 'cambiarEstadoVariosAlbaranes', 'ids' => [':alb1'], 'estado' => '0'], $preparacion);

        self::assertSame(['Validado', 'Validado'], array_column($validados['albaranes'], 'estado'));
        self::assertSame(['Guardado', 'Guardado'], array_column($sinElegir['albaranes'], 'estado'));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/tareas.php:119-129; modulos/mod_compras/funciones.php:442-473
     *
     * @group defecto
     * @group integridad
     * @group medio
     *
     * @que-ocurre-hoy La ventana de cambiar el estado de los albaranes tiene dos opciones vacías: «0» y
     *   «Seleccione un estado». Con la segunda, la petición deja los albaranes sin estado.
     * @que-deberia-ocurrir Que solo se cambie a uno de los estados de la lista.
     * @por-que-ocurre La petición solo rechaza el «0».
     * @como-deberia-funcionar Aceptar solo los estados de la lista.
     */
    public function test_cambiarElEstadoSinElegirNoDejaLosAlbaranesSinEstado(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'cambiarEstadoVariosAlbaranes', 'ids' => [':alb1'], 'estado' => ''], $this->dosAlbaranes());

        self::assertSame('Guardado', $peticion['albaranes'][0]['estado']);
    }

    /** @comportamiento Modificar el estado desde un albarán cambia el de su pedido, y desde una factura, el de su albarán. */
    public function test_modificarElEstadoDelAdjunto(): void
    {
        $preparacion = array_merge($this->dosAlbaranes(), ["INSERT INTO pedprot (Numpedpro, Numtemp_pedpro, Fecha, idTienda, idUsuario, idProveedor, estado, total_siniva, total) VALUES (901, 0, '2026-02-01', :tienda, :usuario, :p1, 'Guardado', 0, 0)"]);

        $desdeAlbaran = $this->ejecutar(['pulsado' => 'modificarEstado', 'dedonde' => 'albaran', 'id' => ':ped1', 'estado' => 'Facturado'], $preparacion);
        $desdeFactura = $this->ejecutar(['pulsado' => 'modificarEstado', 'dedonde' => 'factura', 'id' => ':alb1', 'estado' => 'Facturado'], $preparacion);

        self::assertSame('Facturado', $desdeAlbaran['pedidos'][0]['estado']);
        self::assertSame('Facturado', $desdeFactura['albaranes'][0]['estado']);
    }

    /** @comportamiento Las ventanas de importar albaranes, del cambio de año y de cambiar el estado se responden en HTML. */
    public function test_lasVentanasSeRespondenEnHtml(): void
    {
        $importar = $this->ejecutar(['pulsado' => 'modalImportarAlbaranes']);
        $cambioAno = $this->ejecutar(['pulsado' => 'modalCambioAno']);
        $estado = $this->ejecutar(['pulsado' => 'modalAlbaranesCambioEstado']);

        self::assertStringContainsString('id="btnImportarXMLCambioAno"', $importar['respuesta']['html']);
        self::assertStringContainsString('id="formImportarAlbaranCierreAno"', $cambioAno['respuesta']['html']);
        self::assertStringContainsString('id="Nuevo_estado_albaranes"', $estado['respuesta']['html']);
    }

    /** @comportamiento Las filas que se añaden a un documento —la de un adjunto, y las de sus productos con su cabecera— se responden en HTML; un solo producto puede llegar sin lista. */
    public function test_lasFilasQueSeAnadenAUnDocumento(): void
    {
        $producto = ['idArticulo' => '5', 'cref' => '1001', 'ccodbar' => '', 'cdetalle' => 'Pera', 'ultimoCoste' => '1.5', 'nunidades' => '2', 'iva' => '4', 'estado' => 'Activo', 'nfila' => '1'];

        $adjunto = $this->ejecutar(['pulsado' => 'htmlAgregarFilaAdjunto', 'datos' => ['estado' => 'activo', 'NumAdjunto' => '901', 'nfila' => '1', 'fecha' => '2026-02-01', 'total' => '10.40'], 'dedonde' => 'albaran']);
        $lista = $this->ejecutar(['pulsado' => 'htmlAgregarFilasProductos', 'productos' => json_encode([$producto, ['nfila' => '2'] + $producto]), 'dedonde' => 'albaran', 'cabecera' => ['NumAdjunto' => '901', 'fecha' => '2026-02-01']]);
        $uno = $this->ejecutar(['pulsado' => 'htmlAgregarFilasProductos', 'productos' => json_encode($producto), 'dedonde' => 'pedido', 'cabecera' => 'NO']);

        self::assertStringContainsString('<a href="./pedido.php?id=901&accion=ver">901</a>', $adjunto['respuesta']['html']);
        self::assertStringContainsString('NºPedido:', $lista['respuesta']['html']);
        self::assertSame(2, substr_count($lista['respuesta']['html'], '<tr id="Row'));
        self::assertSame(1, substr_count($uno['respuesta']['html'], '<tr id="Row'));
    }

    /** @comportamiento El formulario del correo de un pedido lleva el asunto de la configuración con su número y el mensaje de partida. */
    public function test_elFormularioDelCorreoDeUnPedido(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'obtenerFormularioEmail', 'id' => '901', 'dedonde' => 'pedido', 'idTienda' => ':tienda', 'destinatario' => 'compras@example.com']);

        self::assertStringContainsString('value="Pedido 901 automatico"', $peticion['respuesta']['html']);
        self::assertStringContainsString('por favor confirme este pedido', $peticion['respuesta']['html']);
        self::assertStringContainsString('value ="compras@example.com" readonly>', $peticion['respuesta']['html']);
    }

    /**
     * @comportamiento Imprimir un pedido, un albarán o una factura escribe su PDF en la carpeta de
     *   temporales y responde su ruta; el documento lleva su número, el del proveedor, los datos de la tienda
     *   y los del proveedor.
     */
    public function test_imprimirUnDocumentoEscribeSuPdf(): void
    {
        $preparacion = array_merge($this->dosAlbaranes(), [
            "INSERT INTO albprolinea (idalbpro, Numalbpro, idArticulo, cref, ccodbar, cdetalle, ncant, nunidades, costeSiva, iva, nfila, estadoLinea, ref_prov) SELECT :alb1, 901, MIN(idArticulo), '1001', '', 'Pera', 5, 5, 2, 4, 1, 'Activo', '' FROM articulos",
            "INSERT INTO pedprot (Numpedpro, Numtemp_pedpro, Fecha, idTienda, idUsuario, idProveedor, estado, total_siniva, total) VALUES (901, 0, '2026-02-01', :tienda, :usuario, :p1, 'Guardado', 0, 0)",
            "INSERT INTO facprot (Numfacpro, Su_num_factura, Fecha, idTienda, idUsuario, idProveedor, estado, total_siniva, total) VALUES (901, 'F-7', '2026-02-01', :tienda, :usuario, :p1, 'Guardado', 0, 0)",
        ]);

        $albaran = $this->ejecutar(['pulsado' => 'datosImprimir', 'id' => ':alb1', 'dedonde' => 'albaran', 'idTienda' => ':tienda'], $preparacion);
        $pdfAlbaran = (string) @file_get_contents($this->carpeta . '/albarancompras.pdf');
        $pedido = $this->ejecutar(['pulsado' => 'datosImprimir', 'id' => ':ped1', 'dedonde' => 'pedido', 'idTienda' => ':tienda'], $preparacion);
        $factura = $this->ejecutar(['pulsado' => 'datosImprimir', 'id' => ':fac1', 'dedonde' => 'factura', 'idTienda' => ':tienda'], $preparacion);

        self::assertStringEndsWith('/albarancompras.pdf', (string) $albaran['respuesta']);
        self::assertStringStartsWith('%PDF', $pdfAlbaran);
        self::assertStringEndsWith('/pedidocompras.pdf', (string) $pedido['respuesta']);
        self::assertStringEndsWith('/facturacompras.pdf', (string) $factura['respuesta']);
        self::assertFileExists($this->carpeta . '/facturacompras.pdf');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/tareas.php:1-22
     *
     * @group defecto
     * @group seguridad
     * @group alto
     *
     * @que-ocurre-hoy Sin sesión, la petición de fondo de las compras se atiende: cambia el estado de los
     *   albaranes, elimina temporales y relaciona productos con proveedores, sin mirar la sesión ni el
     *   permiso de la acción.
     * @que-deberia-ocurrir Que sin sesión no se atienda ninguna orden, y que cada una exija su permiso.
     * @por-que-ocurre El arranque común no corta sin sesión, y la petición no consulta ningún permiso.
     * @como-deberia-funcionar Comprobar la sesión y el permiso de la acción al empezar.
     */
    public function test_sinSesionNoSeCambiaElEstadoDeLosAlbaranes(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'cambiarEstadoVariosAlbaranes', 'ids' => [':alb1'], 'estado' => 'Validado'], $this->dosAlbaranes(), false);

        self::assertSame('Guardado', $peticion['albaranes'][0]['estado']);
    }

    /** @comportamiento Una orden que no existe se anota en el registro de errores y responde vacío. */
    public function test_unaOrdenQueNoExisteRespondeVacio(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'ordenQueNoExiste']);

        self::assertSame([], $peticion['respuesta']);
    }

    /** @return list<string> Un proveedor con dos albaranes guardados. */
    private function dosAlbaranes(): array
    {
        return [
            "INSERT INTO proveedores (nombrecomercial, razonsocial, nif, direccion, fecha_creado, estado) VALUES ('Frutas compra', 'Frutas compra SL', 'X1', 'Sin direccion', NOW(), 'Activo')",
            "INSERT INTO albprot (Numalbpro, Su_numero, Fecha, idTienda, idUsuario, idProveedor, estado, total_siniva, total) VALUES (901, 'A-7', '2026-02-01', :tienda, :usuario, :p1, 'Guardado', 10, 10.4)",
            "INSERT INTO albprot (Numalbpro, Su_numero, Fecha, idTienda, idUsuario, idProveedor, estado, total_siniva, total) VALUES (902, 'A-8', '2026-02-02', :tienda, :usuario, :p1, 'Guardado', 5, 5.2)",
        ];
    }

    /**
     * Ejecuta la petición en un PHP aparte, en una transacción que no se confirma, y devuelve su respuesta,
     * lo que escribió, sus avisos y lo que quedó en la base de lo sembrado.
     *
     * Los marcadores `:usuario` y `:tienda` son los del caso; cada fila sembrada de proveedores,
     * artículos, pedidos, albaranes, facturas y temporales lleva el suyo —`:p1`, `:a1`, `:ped1`, `:alb1`,
     * `:fac1`, `:tped1`, `:talb1`…—, que el formulario puede usar.
     *
     * @param array<string, mixed> $formulario
     * @param list<string> $preparacion
     * @return array<string, mixed>
     */
    private function ejecutar(array $formulario, array $preparacion = [], bool $conSesion = true): array
    {
        $sesion = $conSesion ? [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => (string) $this->idUsuario, 'group_id' => '1', 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => (string) $this->idTienda, 'razonsocial' => 'Tienda de pruebas SL', 'NombreComercial' => 'Tienda de pruebas'],
        ] : [];
        $marcas = ['proveedores' => ':p', 'articulos' => ':a', 'pedprot' => ':ped', 'albprot' => ':alb', 'facprot' => ':fac', 'pedprotemporales' => ':tped', 'albproltemporales' => ':talb', 'facproltemporales' => ':tfac'];
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["DOCUMENT_ROOT"] = ""; $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = "/TPVFox/modulos/mod_compras/tareas.php";'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_compras', true) . ');'
            . ' $_POST = ["pulsado" => ""]; require "./../../inicial.php";'
            . ' $rutatmp = str_repeat("/..", substr_count(rtrim($RutaServidor, "/"), "/")) . ' . var_export($this->carpeta, true) . ';'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ' $ids = [":usuario" => "' . $this->idUsuario . '", ":tienda" => "' . $this->idTienda . '"]; $cuentas = []; $marcas = ' . var_export($marcas, true) . ';'
            . ' $antes = (int) $BDTpv->query("SELECT COALESCE(MAX(id), 0) FROM modulo_incidencia")->fetch_row()[0];'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->query(strtr($sql, $ids)); $id = $BDTpv->insert_id;'
            . ' if (preg_match("/^INSERT INTO (\\\\w+) /", $sql, $t) && isset($marcas[$t[1]])) { $cuentas[$t[1]] = ($cuentas[$t[1]] ?? 0) + 1; $ids[$marcas[$t[1]] . $cuentas[$t[1]]] = (string) $id; } }'
            . ' $_POST = json_decode(strtr(' . var_export((string) json_encode($formulario), true) . ', $ids), true);'
            . ' $sembrado = function ($marca) use (&$ids) { $lista = []; foreach ($ids as $k => $v) { if (preg_match("/^" . $marca . "\\\\d+$/", $k)) { $lista[] = (int) $v; } } return $lista ? implode(",", $lista) : "0"; };'
            . ' $foto = function () use ($BDTpv, $sembrado, $antes) { return ['
            . ' "albaranes" => $BDTpv->query("SELECT id, estado FROM albprot WHERE id IN (" . $sembrado(":alb") . ") ORDER BY id")->fetch_all(MYSQLI_ASSOC),'
            . ' "pedidos" => $BDTpv->query("SELECT id, estado FROM pedprot WHERE id IN (" . $sembrado(":ped") . ") ORDER BY id")->fetch_all(MYSQLI_ASSOC),'
            . ' "pedidosTemporales" => $BDTpv->query("SELECT id FROM pedprotemporales WHERE id IN (" . $sembrado(":tped") . ")")->fetch_all(MYSQLI_ASSOC),'
            . ' "albaranesTemporales" => $BDTpv->query("SELECT id FROM albproltemporales WHERE id IN (" . $sembrado(":talb") . ")")->fetch_all(MYSQLI_ASSOC),'
            . ' "relaciones" => $BDTpv->query("SELECT crefProveedor, coste, fechaActualizacion, estado FROM articulosProveedores WHERE idArticulo IN (" . $sembrado(":a") . ")")->fetch_all(),'
            . ' "incidencias" => $BDTpv->query("SELECT dedonde, mensaje, datos FROM modulo_incidencia WHERE id > " . $antes . " AND num_incidencia <> 900001")->fetch_all()]; };'
            . ' $GLOBALS["ultimaFoto"] = []; ob_start(function ($salida) use ($foto) { try { $GLOBALS["ultimaFoto"] = $foto(); } catch (Throwable $e) { } return $salida; }, 1);'
            . ' register_shutdown_function(function () use ($foto, &$ids) { try { $datos = $foto(); } catch (Throwable $e) { $datos = $GLOBALS["ultimaFoto"]; }'
            . ' echo "\n@@DATOS@@" . json_encode(["ids" => $ids] + $datos, JSON_INVALID_UTF8_SUBSTITUTE); });'
            . ' include "tareas.php";';

        $errores = (string) tempnam(sys_get_temp_dir(), 'compras');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -d error_log=/dev/null -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$escrito, $estado] = array_pad(explode('@@DATOS@@', implode("\n", $salida), 2), 2, 'null');
        $estado = (json_decode($estado, true) ?? []) + ['ids' => [], 'albaranes' => [], 'pedidos' => [], 'pedidosTemporales' => [], 'albaranesTemporales' => [], 'relaciones' => [], 'incidencias' => []];
        $ejecucion = ['respuesta' => json_decode(trim($escrito), true), 'escrito' => $escrito, 'errores' => (string) file_get_contents($errores)] + $estado;
        unlink($errores);

        return $ejecucion;
    }
}
