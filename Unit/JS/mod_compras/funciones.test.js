/**
 * El JavaScript común de las compras (`modulos/mod_compras/funciones.js` y `js/AccionesDirectas.js`): añadir
 * productos y adjuntos al pedido, al albarán o a la factura, grabar el temporal, recalcular, eliminar y
 * recuperar líneas y adjuntos, buscar proveedores y productos, las opciones de los listados, cancelar y
 * eliminar temporales, imprimir, el correo y las acciones del teclado.
 *
 * Se ejecutan los dos ficheros en un mismo contexto de Node, con un jQuery de prueba que apunta lo que se
 * le pide —las peticiones, los valores, el HTML pintado, las propiedades y las clases— sin una página real.
 * `alert`, `confirm`, la dirección, el historial, las ventanas y las funciones del núcleo que usan son de
 * pega. Ningún caso envía un correo: la petición queda apuntada y no se ejecuta.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const { cargarScriptGlobal } = require('../../../support/js/cargarScriptGlobal');

const FUNCIONES = path.resolve(__dirname, '../../../../TPVFox/modulos/mod_compras/funciones.js');
const ACCIONES = path.resolve(__dirname, '../../../../TPVFox/modulos/mod_compras/js/AccionesDirectas.js');

/** Un elemento de pega de jQuery: apunta lo que se le hace. */
function elemento(selector, pagina) {
  const el = {
    val(valor) { if (valor === undefined) { return pagina.valores[selector] ?? ''; } pagina.valores[selector] = String(valor); return el; },
    html(contenido) { if (contenido === undefined) { return pagina.pintado[selector] ?? ''; } pagina.pintado[selector] = contenido; return el; },
    prepend(contenido) { pagina.pintado[selector] = contenido + (pagina.pintado[selector] ?? ''); return el; },
    prop(nombre, valor) { if (valor === undefined) { return pagina.propiedades[selector + ':' + nombre]; } pagina.propiedades[selector + ':' + nombre] = valor; return el; },
    addClass(clase) { pagina.clases[selector] = clase; return el; },
    removeClass() { pagina.clases[selector] = ''; return el; },
    attr(nombre, valor) { pagina.propiedades[selector + ':' + nombre] = valor; return el; },
    removeAttr(nombre) { pagina.quitados.push(selector + ':' + nombre); return el; },
    css(nombre, valor) { pagina.propiedades[selector + ':css-' + nombre] = valor; return el; },
    show() { pagina.visibles[selector] = true; return el; },
    hide() { pagina.visibles[selector] = false; return el; },
    focus() { pagina.enfocada = selector; return el; },
    select() { pagina.enfocada = selector; return el; },
    serialize() { return 'pulsado=enviarEmail&id=12'; },
  };
  return el;
}

function paginaDePrueba({ productos = [], pedidos = [], albaranes = [], cabecera = {}, marcados = ['7'], confirmar = true, valores = {} } = {}) {
  const pagina = { peticiones: [], envios: [], avisos: [], modales: [], abiertas: [], historial: [], valores: { ...valores }, propiedades: {}, pintado: {}, clases: {}, visibles: {}, quitados: [], enfocada: null, cajas: {}, preguntas: [] };
  const $ = (selector) => elemento(selector, pagina);
  $.ajax = (opciones) => { pagina.peticiones.push(opciones); };
  $.post = (url, datos, funcion) => { pagina.envios.push({ url, datos, funcion }); };
  $.parseJSON = JSON.parse;
  pagina.location = { href: '', reload: jest.fn() };
  pagina.contexto = cargarScriptGlobal(FUNCIONES, {
    $,
    jQuery: $,
    alert: (texto) => { pagina.avisos.push(String(texto)); },
    confirm: (texto) => { pagina.preguntas.push(String(texto)); return confirmar; },
    console: { log() {}, error() {} },
    window: { location: pagina.location, open: (ruta) => { pagina.abiertas.push(ruta); } },
    location: pagina.location,
    document: { getElementById: (id) => (pagina.cajas[id] ??= { value: '' }) },
    history: { pushState: (estado, titulo, url) => { pagina.historial.push(url); } },
    setTimeout: (funcion) => { funcion(); return 0; },
    abrirModal: (titulo, html) => { pagina.modales.push([titulo, html]); },
    cerrarPopUp: () => {},
    focusAlLanzarModal: (caja) => { pagina.enfocada = '#' + caja; },
    ObtenerIdString: () => -1,
    checkID: [],
    productos: productos.slice(),
    pedidos: pedidos.slice(),
    albaranes: albaranes.slice(),
    cabecera: { idTemporal: 0, idUsuario: 83, idTienda: 55, estado: 'Nuevo', idReal: 0, fecha: '03-02-2026', hora: '10:00', idProveedor: 9, suNumero: '', ...cabecera },
    salto_linea: 'Codbarras',
    id_input: 'Codbarras',
  });
  vm.runInContext(fs.readFileSync(ACCIONES, 'utf8'), pagina.contexto, { filename: ACCIONES });
  pagina.contexto.VerIdSeleccionado = () => { pagina.contexto.checkID = marcados.slice(); };
  pagina.ultima = () => pagina.peticiones[pagina.peticiones.length - 1];
  pagina.responder = (respuesta, peticion = pagina.ultima()) => peticion.success(JSON.stringify(respuesta));
  pagina.pulsados = () => pagina.peticiones.map((p) => p.data.pulsado);

  return pagina;
}

/** Una línea de producto como la deja `ObjProducto`. */
function linea(cambios = {}) {
  return { idArticulo: '5', cref: '1001', ref_prov: '', ccodbar: '84', cdetalle: 'Pera', iva: '4', nunidades: '2', ncant: '2', estado: 'Activo', nfila: 1, ultimoCoste: '1.5', importe: 3, ...cambios };
}

/** Una caja del teclado de pega. */
function caja(cambios = {}) {
  const parametros = { dedonde: 'albaran', ...(cambios.parametros ?? {}) };
  return { id_input: 'Unidad_Fila_1', name_cja: 'Unidad_Fila', fila: '1', tipo_event: 'keydown', valor: '', parametros, darValor() { return this.valor; }, darParametro(nombre) { return this.parametros[nombre]; }, ...cambios, parametros };
}

describe('el temporal y las líneas', () => {
  /**
   * @comportamiento Grabar el temporal manda su orden según el documento, con la cabecera y los
   *   productos; el albarán añade sus pedidos y su número del proveedor, y la factura sus albaranes.
   */
  test('grabar el temporal de cada documento', () => {
    const pedido = paginaDePrueba({ productos: [linea()] });
    const albaran = paginaDePrueba({ productos: [linea()], pedidos: [{ NumAdjunto: '12' }], cabecera: { suNumero: 'A-7' } });
    const factura = paginaDePrueba({ albaranes: [{ NumAdjunto: '31' }] });

    pedido.contexto.addTemporal('pedido');
    albaran.contexto.addTemporal('albaran');
    factura.contexto.addTemporal('factura');

    expect(pedido.ultima().data).toEqual(expect.objectContaining({ pulsado: 'addPedidoTemporal', idTemporal: 0, idUsuario: 83, idTienda: 55, fecha: '03-02-2026', hora: '10:00', idProveedor: 9, productos: JSON.stringify([linea()]) }));
    expect(albaran.ultima().data).toEqual(expect.objectContaining({ pulsado: 'addAlbaranTemporal', pedidos: [{ NumAdjunto: '12' }], suNumero: 'A-7' }));
    expect(factura.ultima().data).toEqual(expect.objectContaining({ pulsado: 'addFacturaTemporal', albaranes: [{ NumAdjunto: '31' }] }));
  });

  /**
   * @comportamiento Con la respuesta, el temporal nuevo pone su número en la dirección y en la página, y
   *   enseña el botón de guardar; un documento guardado pasa a «Sin Guardar»; los totales se pintan. Un
   *   error se avisa.
   */
  test('la respuesta de grabar el temporal', () => {
    const pagina = paginaDePrueba({ cabecera: { estado: 'Guardado' } });
    const conError = paginaDePrueba();

    pagina.contexto.addTemporal('albaran');
    pagina.responder({ id: 41, totales: { total: '12.10' }, htmlTabla: '<tr>totales</tr>' });
    conError.contexto.addTemporal('albaran');
    conError.responder({ error: ['Sin proveedor'] });

    expect(pagina.historial).toEqual(['?tActual=41']);
    expect(pagina.valores["input[name='idTemporal']"]).toBe('41');
    expect(pagina.quitados).toContain('#bGuardar:style');
    expect([pagina.contexto.cabecera.idTemporal, pagina.contexto.cabecera.estado, pagina.cajas.estado.value]).toEqual([41, 'Sin Guardar', 'Sin Guardar']);
    expect(pagina.pintado['#tabla-pie  > tbody ']).toBe('<tr>totales</tr>');
    expect(pagina.contexto.total).toBe(12.1);
    expect(conError.avisos).toEqual(['["Sin proveedor"]']);
  });

  /** @comportamiento Recalcular una línea pinta su importe y su importe con IVA; con cero unidades, la elimina. */
  test('recalcular el importe de una línea', () => {
    const pagina = paginaDePrueba({ productos: [linea({ ultimoCoste: '2.5', iva: '21' })] });

    pagina.contexto.recalculoImporte(4, 0, 'albaran');
    const pintado = [pagina.pintado['#N1_Importe'], pagina.pintado['#N1_ImporteIva']];
    pagina.contexto.recalculoImporte(0, 0, 'albaran');

    expect(pintado).toEqual(['10.00', '12.10']);
    expect(pagina.contexto.productos[0].estado).toBe('Eliminado');
    expect(pagina.pulsados()).toEqual(['addAlbaranTemporal']);
  });

  /** @comportamiento Eliminar una línea la tacha con la orden de recuperarla y graba; recuperarla la vuelve a activar, con una unidad si tenía cero. */
  test('eliminar y recuperar una línea', () => {
    const pagina = paginaDePrueba({ productos: [linea({ nunidades: 0 })] });

    pagina.contexto.eliminarFila(1, 'pedido');
    const tachada = [pagina.clases['#Row1'], pagina.pintado['#Row1> .eliminar']];
    pagina.contexto.retornarFila(1, 'pedido');

    expect(tachada).toEqual(['tachado', '<a onclick="retornarFila(1, \'pedido\');"><span class="glyphicon glyphicon-export"></span></a>']);
    expect([pagina.contexto.productos[0].estado, pagina.contexto.productos[0].nunidades]).toEqual(['Activo', 1]);
    expect(pagina.clases['#Row1']).toBe('');
    expect(pagina.pulsados()).toEqual(['addPedidoTemporal', 'addPedidoTemporal']);
  });

  /**
   * @comportamiento El producto elegido se convierte en línea con unidad 1, activo y el coste del
   *   proveedor —con coma o con punto—; sin coste del proveedor, se pide confirmación, se toma el último
   *   coste del producto y, aceptada, se añade, se graba y se pinta. Si el coste del proveedor es de una
   *   fecha posterior a la del documento, se avisa.
   */
  test('añadir el producto elegido', () => {
    const pagina = paginaDePrueba();
    const sinCoste = paginaDePrueba({ confirmar: false });

    pagina.contexto.escribirProductoSeleccionado('Codbarras', '1001', 'Pera', '4.00', '84', '1.20', 5, 'albaran', 'PER-1', '1,10', '2026-02-10');
    sinCoste.contexto.escribirProductoSeleccionado('Codbarras', '1001', 'Pera', '4.00', '84', '1.20', 5, 'albaran', '', '', '');

    expect(pagina.contexto.productos[0]).toEqual(expect.objectContaining({ idArticulo: '5', cref: '1001', ref_prov: 'PER-1', cdetalle: 'Pera', nunidades: '1.00', estado: 'Activo', nfila: 1, ultimoCoste: '1.10' }));
    expect(pagina.pulsados()).toEqual(['addAlbaranTemporal', 'htmlAgregarFilasProductos']);
    expect(pagina.avisos).toEqual(['El producto que vas a añadir tiene un coste que fue actualizado con fecha superior a la del albarán']);
    expect(pagina.cajas.Codbarras.value).toBe('');
    expect(sinCoste.preguntas).toHaveLength(1);
    expect(sinCoste.contexto.productos).toEqual([]);
  });

  /**
   * @estado rojo
   * @codigo-afectado modulos/mod_compras/funciones.js:593-602
   *
   * @group defecto
   * @group presentacion
   * @group bajo
   *
   * @que-ocurre-hoy El aviso de un producto nuevo para el proveedor dice que no se añade «al albara», «al
   *   pedid» o «al factur»: corta la última letra del documento.
   * @que-deberia-ocurrir Que nombre el documento entero.
   * @por-que-ocurre Se le quita la última letra pensando en un plural que no llega.
   * @como-deberia-funcionar Usar el nombre del documento tal cual.
   */
  test('el aviso de un producto nuevo nombra el documento', () => {
    const pagina = paginaDePrueba({ confirmar: false });

    pagina.contexto.escribirProductoSeleccionado('Codbarras', '1001', 'Pera', '4.00', '84', '1.20', 5, 'albaran', '', '', '');

    expect(pagina.preguntas[0]).toMatch(/al albaran$/);
  });

  /** @comportamiento Las filas de los productos se piden a la petición, con su cabecera, y se ponen delante en la tabla; en la factura con albaranes, las unidades quedan bloqueadas. */
  test('pintar las filas de los productos', () => {
    const pagina = paginaDePrueba({ albaranes: [{ NumAdjunto: '31' }] });

    pagina.contexto.AgregarFilasProductos([linea(), linea({ nfila: 2 })], 'factura', { NumAdjunto: '31' });
    pagina.responder({ html: '<tr id="Row2"></tr>' });

    expect(JSON.parse(pagina.ultima().data.productos).map((p) => p.nfila)).toEqual([2, 1]);
    expect(pagina.ultima().data.cabecera).toEqual({ NumAdjunto: '31' });
    expect(pagina.pintado['#tabla']).toBe('<tr id="Row2"></tr>');
    expect(pagina.propiedades['.unidad:readonly']).toBe('readonly');
  });
});

describe('los adjuntos', () => {
  /**
   * @comportamiento Buscar el pedido de un albarán con uno solo lo añade con su fila, añade sus productos
   *   con su pedido de origen, lo marca «Facturado», pinta su fila y las de sus productos, y graba; si ya
   *   estaba, lo avisa; con varios, abre la ventana; un error se avisa.
   */
  test('añadir el pedido de un albarán', async () => {
    const pagina = paginaDePrueba();
    const repetido = paginaDePrueba({ pedidos: [{ NumAdjunto: '12' }] });
    const varios = paginaDePrueba();
    const conError = paginaDePrueba();
    const respuesta = { Nitems: 1, datos: { NumAdjunto: '12', idAdjunto: '4', estado: 'activo' }, productos: [{ cdetalle: 'Pera', ccodbar: '84', ref_prov: 'PER-1', cref: '1001', idArticulo: '5', iva: '4', costeSiva: '2', nunidades: '5', estadoLinea: 'Activo', Numpedpro: '12', idpedpro: '4' }] };

    const promesa = pagina.contexto.buscarAdjunto('albaran', '12');
    pagina.responder(respuesta);
    await promesa;
    repetido.contexto.buscarAdjunto('albaran', '12');
    repetido.responder(respuesta);
    varios.contexto.buscarAdjunto('albaran');
    varios.responder({ Nitems: 2, html: '<table>pedidos</table>' });
    conError.contexto.buscarAdjunto('albaran');
    conError.responder({ error: 'Sin proveedor', consulta: 'SELECT' });

    expect(pagina.peticiones[0].data).toEqual({ pulsado: 'buscarAdjunto', numAdjunto: '12', idProveedor: 9, dedonde: 'albaran' });
    expect(pagina.contexto.pedidos).toEqual([{ NumAdjunto: '12', idAdjunto: '4', estado: 'activo', nfila: 1 }]);
    expect(pagina.contexto.productos[0]).toEqual(expect.objectContaining({ idArticulo: '5', nunidades: '5', ultimoCoste: '2', numPedido: '12', idpedpro: '4' }));
    expect(pagina.pulsados()).toEqual(['buscarAdjunto', 'modificarEstado', 'htmlAgregarFilaAdjunto', 'htmlAgregarFilasProductos', 'addAlbaranTemporal']);
    expect(pagina.peticiones[1].data).toEqual({ pulsado: 'modificarEstado', id: '4', estado: 'Facturado', dedonde: 'albaran' });
    expect(repetido.avisos).toEqual([' Ya existe este adjunto en este albaran']);
    expect(varios.modales).toEqual([['Listado Pedidos ', '<table>pedidos</table>']]);
    expect(conError.avisos).toEqual(['Sin proveedor\nSELECT']);
  });

  /** @comportamiento La fila de un adjunto se pide a la petición y se pone delante en la tabla de adjuntos, con la caja del número vacía. */
  test('pintar la fila de un adjunto', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.AgregarAdjunto({ NumAdjunto: '12' }, 'albaran');
    pagina.responder({ html: '<tr id="lineaP1"></tr>' });

    expect(pagina.ultima().data).toEqual({ pulsado: 'htmlAgregarFilaAdjunto', datos: { NumAdjunto: '12' }, dedonde: 'albaran' });
    expect(pagina.pintado['#tablaPedidos']).toBe('<tr id="lineaP1"></tr>');
    expect(pagina.valores['#numPedido']).toBe('');
  });

  /** @comportamiento Quitar un adjunto lo tacha con la orden de recuperarlo y elimina sus productos; recuperarlo los vuelve a activar; las dos cosas graban. */
  test('quitar y recuperar un adjunto', () => {
    const pagina = paginaDePrueba({ pedidos: [{ NumAdjunto: '12', idAdjunto: '4', estado: 'activo', nfila: 1 }], productos: [linea({ idpedpro: '12' }), linea({ nfila: 2, idpedpro: '13' })] });

    pagina.contexto.eliminarAdjunto(12, 'albaran', 1);
    const quitado = [pagina.contexto.pedidos[0].estado, pagina.clases['#lineaP1'], pagina.contexto.productos.map((p) => p.estado)];
    pagina.contexto.retornarAdjunto(12, 'albaran', 1);

    expect(quitado).toEqual(['Eliminado', 'tachado', ['Eliminado', 'Activo']]);
    expect([pagina.contexto.pedidos[0].estado, pagina.contexto.productos[0].estado]).toEqual(['activo', 'Activo']);
    expect(pagina.pulsados()).toEqual(['modificarEstado', 'addAlbaranTemporal', 'modificarEstado', 'addAlbaranTemporal']);
  });

  /**
   * @estado rojo
   * @codigo-afectado modulos/mod_compras/funciones.js:839-868, :915-944
   *
   * @group defecto
   * @group integridad
   * @group alto
   *
   * @que-ocurre-hoy Quitar o recuperar un adjunto cambia el estado del documento cuyo id coincide con el
   *   número del adjunto, no el del adjunto: se libera o se marca otro pedido u otro albarán.
   * @que-deberia-ocurrir Que se cambie el estado del adjunto por su id.
   * @por-que-ocurre Se manda el número del adjunto como id; el id que toman (`idPedido`, `idAlbaran`) no
   *   existe en el adjunto, que lo guarda como `idAdjunto`.
   * @como-deberia-funcionar Mandar el `idAdjunto` del adjunto.
   */
  test('quitar un adjunto cambia el estado del adjunto por su id', () => {
    const pagina = paginaDePrueba({ pedidos: [{ NumAdjunto: '12', idAdjunto: '4', estado: 'activo', nfila: 1 }] });

    pagina.contexto.eliminarAdjunto(12, 'albaran', 1);

    expect(pagina.peticiones[0].data).toEqual({ pulsado: 'modificarEstado', id: '4', estado: 'Guardado', dedonde: 'albaran' });
  });

  /**
   * @estado rojo
   * @codigo-afectado modulos/mod_compras/funciones.js:146-173
   *
   * @group defecto
   * @group errores
   * @group medio
   *
   * @que-ocurre-hoy Si cambiar el estado de un adjunto da error, el aviso usa una variable que no existe:
   *   el JavaScript se detiene y no se avisa nada.
   * @que-deberia-ocurrir Que se avise el error con su consulta.
   * @por-que-ocurre El aviso lee `respuesta.consulta` en lugar de `resultado.consulta`.
   * @como-deberia-funcionar Leer la consulta del resultado.
   */
  test('un error al cambiar el estado de un adjunto se avisa', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.modificarEstado('albaran', 'Facturado', '4');

    expect(() => pagina.responder({ error: 'Sin pedido', consulta: 'UPDATE' })).not.toThrow();
    expect(pagina.avisos).toEqual(['Error de SQLUPDATE']);
  });

  /** @comportamiento Comprobar los adjuntos de un proveedor con alguno enseña su zona y pone el foco: en la factura, en el número del adjunto; en el albarán, en el del proveedor. */
  test('comprobar los adjuntos de un proveedor', () => {
    const factura = paginaDePrueba();
    const albaran = paginaDePrueba();

    factura.contexto.comprobarAdjunto('factura');
    factura.responder({ bandera: 1 });
    albaran.contexto.comprobarAdjunto('albaran');
    albaran.responder({ bandera: 1 });

    expect(factura.ultima().data).toEqual({ pulsado: 'comprobarAdjunto', idProveedor: 9, dedonde: 'factura' });
    expect(factura.quitados).toEqual(['.div_adjunto:style']);
    expect([factura.enfocada, albaran.enfocada]).toEqual(['#numPedido', '#suNumero']);
  });
});

describe('los proveedores y los productos', () => {
  /**
   * @comportamiento Buscar un proveedor con uno solo lo pone en la cabecera y bloquea sus cajas; en el
   *   albarán y la factura comprueba sus adjuntos, y en el pedido pone el foco en el producto. Con varios,
   *   abre su lista.
   */
  test('buscar un proveedor', () => {
    const albaran = paginaDePrueba({ cabecera: { idProveedor: 0 } });
    const pedido = paginaDePrueba({ cabecera: { idProveedor: 0 } });
    const varios = paginaDePrueba();

    albaran.contexto.buscarProveedor('albaran', 'id_proveedor', '7');
    albaran.responder({ Nitems: 1, id: '7', nombre: 'Frutas' });
    pedido.contexto.buscarProveedor('pedido', 'id_proveedor', '7');
    pedido.responder({ Nitems: 1, id: '7', nombre: 'Frutas' });
    varios.contexto.buscarProveedor('albaran', 'Proveedor', 'fru');
    varios.responder({ Nitems: 2, html: { html: '<table>proveedores</table>' } });

    expect(albaran.peticiones[0].data).toEqual({ pulsado: 'buscarProveedor', busqueda: '7', dedonde: 'albaran', idcaja: 'id_proveedor' });
    expect([albaran.contexto.cabecera.idProveedor, albaran.valores['#Proveedor'], albaran.propiedades['#Proveedor:disabled'], albaran.propiedades['#id_proveedor:disabled']]).toEqual(['7', 'Frutas', true, true]);
    expect(albaran.pulsados()).toEqual(['buscarProveedor', 'comprobarAdjunto']);
    expect(pedido.pulsados()).toEqual(['buscarProveedor']);
    expect(varios.modales).toEqual([['Listado Proveedores ', '<table>proveedores</table>']]);
    expect(varios.enfocada).toBe('#cajaBusquedaproveedor');
  });

  /**
   * @comportamiento Buscar productos con un resultado lo añade, salvo de baja, que se avisa; con varios
   *   abre el listado; sin nada que buscar, pasa a la caja siguiente.
   */
  test('buscar productos desde las cajas', () => {
    const pagina = paginaDePrueba();
    const baja = paginaDePrueba();
    const varios = paginaDePrueba();
    const vacia = paginaDePrueba();
    const cajaCodigo = caja({ id_input: 'Codbarras', name_cja: 'Codbarras', valor: '84', parametros: { campo: 'ac.codBarras' } });
    const producto = { idArticulo: '5', articulo_name: 'Pera', codBarras: '84', crefTienda: '1001', ref_prov: 'PER-1', coste: '1.1', iva: '4', ultimoCoste: '1.2', fechaActualizacion: null, estadoTabla: 'Activo' };

    pagina.contexto.AccionBuscarProductos(cajaCodigo);
    pagina.responder({ Nitems: 1, datos: [producto] });
    baja.contexto.AccionBuscarProductos(cajaCodigo);
    baja.responder({ Nitems: 1, datos: [{ ...producto, estadoTabla: 'Baja' }] });
    varios.contexto.AccionBuscarProductos(cajaCodigo);
    varios.responder({ Nitems: 2, html: '<table>productos</table>' });
    vacia.contexto.AccionBuscarProductos(caja({ id_input: 'Codbarras', name_cja: 'Codbarras', valor: '', parametros: { campo: 'ac.codBarras' } }));

    expect(pagina.peticiones[0].data).toEqual({ pulsado: 'buscarProductos', id_input: 'Codbarras', valorCampo: '84', campo: 'ac.codBarras', idcaja: 'Codbarras', idProveedor: 9, dedonde: 'albaran' });
    expect(pagina.contexto.productos.map((p) => p.idArticulo)).toEqual(['5']);
    expect(baja.avisos).toEqual(['Este producto no se puede adjuntar ya que el estado del producto es BAJA']);
    expect(varios.modales).toEqual([['Listado productos encontrados ', '<table>productos</table>']]);
    expect(vacia.peticiones).toEqual([]);
    expect(vacia.enfocada).toBe('#Descripcion');
  });

  /** @comportamiento Relacionar el producto de una línea con su referencia del proveedor manda su coste y, si va bien, la pone en la línea, bloquea su caja y graba; un error se avisa. */
  test('relacionar el producto de una línea con su proveedor', () => {
    const pagina = paginaDePrueba({ productos: [linea()] });
    const conError = paginaDePrueba({ productos: [linea()] });
    const cajaReferencia = caja({ id_input: 'Proveedor_Fila_1', valor: 'PER-1' });

    pagina.contexto.AccionAddProveedorProducto(cajaReferencia);
    pagina.responder({});
    conError.contexto.AccionAddProveedorProducto(cajaReferencia);
    conError.responder({ error: 'Duplicado' });

    expect(pagina.peticiones[0].data).toEqual({ pulsado: 'addProveedorArticulo', idArticulo: '5', refProveedor: 'PER-1', idProveedor: 9, coste: '1.5' });
    expect([pagina.contexto.productos[0].ref_prov, pagina.propiedades['#Proveedor_Fila_1:disabled'], pagina.valores['#Proveedor_Fila_1']]).toEqual(['PER-1', true, 'PER-1']);
    expect(pagina.pulsados()).toEqual(['addProveedorArticulo', 'addAlbaranTemporal']);
    expect(conError.avisos).toEqual(['ERROR DE SQL: Duplicado']);
  });

  /** @comportamiento El campo de una búsqueda decide a qué caja se vuelve, y cada caja de la cabecera dice cuál es la siguiente. */
  test('las cajas siguientes', () => {
    const pagina = paginaDePrueba();
    const conProductos = paginaDePrueba({ productos: [linea()] });

    expect(['a.idArticulo', 'at.crefTienda', 'p.crefProveedor', 'ac.codBarras', 'a.articulo_name'].map((c) => pagina.contexto.cambiarValorSaltoLinea(c))).toEqual(['idArticulo', 'Referencia', 'ReferenciaPro', 'Codbarras', 'Descripcion']);
    expect(['idArticulo', 'Referencia', 'ReferenciaPro', 'Codbarras', 'hora'].map((c) => pagina.contexto.ObtenerCajaSiguiente(c))).toEqual(['Referencia', 'ReferenciaPro', 'Codbarras', 'Descripcion', 'Proveedor']);
    expect(conProductos.contexto.ObtenerCajaSiguiente('hora')).toBe('Codbarras');
  });
});

describe('las acciones del teclado', () => {
  /** @comportamiento Un coste nuevo en una línea guarda el anterior, recalcula y graba; el mismo coste en la última línea pasa a la caja de entrada. */
  test('cambiar el coste de una línea', () => {
    const pagina = paginaDePrueba({ productos: [linea()] });
    const igual = paginaDePrueba({ productos: [linea()] });

    pagina.contexto.controladorAcciones(caja({ id_input: 'ultimo_coste_1', valor: '1.8' }), 'buscarUltimoCoste');
    igual.contexto.controladorAcciones(caja({ id_input: 'ultimo_coste_1', valor: '1.5' }), 'buscarUltimoCoste');

    expect([pagina.contexto.productos[0].CosteAnt, pagina.contexto.productos[0].ultimoCoste, pagina.contexto.productos[0].importe]).toEqual(['1.5', '1.8', 3.6]);
    expect(pagina.pulsados()).toEqual(['addAlbaranTemporal']);
    expect(igual.peticiones).toEqual([]);
    expect(igual.enfocada).toBe('#Codbarras');
  });

  /**
   * @estado rojo
   * @codigo-afectado modulos/mod_compras/js/AccionesDirectas.js:27-29
   *
   * @group defecto
   * @group integridad
   * @group medio
   *
   * @que-ocurre-hoy Borrar el coste de una línea no avisa: lo da por bueno, guarda el anterior y graba el
   *   temporal con el coste vacío.
   * @que-deberia-ocurrir Que avise de que no hay coste y no grabe.
   * @por-que-ocurre La comprobación asigna en lugar de comparar (`valor = ""`).
   * @como-deberia-funcionar Comparar el valor de la caja con el vacío.
   */
  test('un coste vacío se avisa y no se graba', () => {
    const pagina = paginaDePrueba({ productos: [linea()] });

    pagina.contexto.controladorAcciones(caja({ id_input: 'ultimo_coste_1', valor: '' }), 'buscarUltimoCoste');

    expect(pagina.avisos).toEqual(['NO HAS INTRODUCIDO NINGÚN COSTE']);
    expect(pagina.peticiones).toEqual([]);
  });

  /** @comportamiento Cambiar las unidades de una línea con un número recalcula y graba, y pasa al coste —en el pedido, a la caja de entrada—; con otra cosa, nada. */
  test('cambiar las unidades de una línea', () => {
    const albaran = paginaDePrueba({ productos: [linea()] });
    const pedido = paginaDePrueba({ productos: [linea()] });
    const texto = paginaDePrueba({ productos: [linea()] });

    albaran.contexto.controladorAcciones(caja({ valor: '3' }), 'recalcular_totalProducto');
    pedido.contexto.controladorAcciones(caja({ valor: '3', parametros: { dedonde: 'pedido' } }), 'recalcular_totalProducto');
    texto.contexto.controladorAcciones(caja({ valor: 'tres' }), 'recalcular_totalProducto');

    expect([albaran.contexto.productos[0].nunidades, albaran.contexto.productos[0].ncant, albaran.pintado['#N1_Importe']]).toEqual(['3', '3', '4.50']);
    expect(albaran.pulsados()).toEqual(['addAlbaranTemporal']);
    expect(albaran.enfocada).toBe('#ultimo_coste_1');
    expect(pedido.enfocada).toBe('#Codbarras');
    expect(texto.peticiones).toEqual([]);
  });

  /** @comportamiento La descripción de una línea del pedido se cambia y se graba; el número del proveedor se guarda en la cabecera; el pedido o el albarán de la caja se buscan como adjunto. */
  test('la descripción, el número del proveedor y el adjunto desde el teclado', () => {
    const pagina = paginaDePrueba({ productos: [linea()] });

    pagina.contexto.controladorAcciones(caja({ id_input: 'Descripcion_Fila_1', valor: 'Pera dulce', parametros: { dedonde: 'pedido' } }), 'cambio_descripcion');
    pagina.contexto.controladorAcciones(caja({ id_input: 'suNumero', valor: 'A-7' }), 'Saltar_SuNumero');
    pagina.contexto.controladorAcciones(caja({ id_input: 'numPedido', valor: '12' }), 'addPedidoAlbaran');

    expect(pagina.contexto.productos[0].cdetalle).toBe('Pera dulce');
    expect(pagina.contexto.cabecera.suNumero).toBe('A-7');
    expect(pagina.pulsados()).toEqual(['addPedidoTemporal', 'buscarAdjunto']);
  });

  /** @comportamiento La fecha del documento se comprueba: una que no es fecha o posterior a hoy se avisa; una buena pasa a la hora en el albarán y queda en la cabecera. */
  test('comprobar la fecha del documento', () => {
    const mala = paginaDePrueba();
    const futura = paginaDePrueba();
    const buena = paginaDePrueba();

    mala.contexto.comprobarFecha(caja({ id_input: 'fecha', valor: '99-99-2026' }));
    futura.contexto.comprobarFecha(caja({ id_input: 'fecha', valor: '01-01-2999' }));
    buena.contexto.comprobarFecha(caja({ id_input: 'fecha', valor: '03-02-2026' }));

    expect(mala.avisos).toEqual(['La fecha no es correcta']);
    expect(futura.avisos).toEqual(['La fecha es superior a hoy']);
    expect([buena.contexto.cabecera.fecha, buena.enfocada]).toEqual(['03-02-2026', '#hora']);
  });
});

describe('los listados y las ventanas', () => {
  /** @comportamiento Ver o modificar el documento marcado lleva a él; sin uno solo marcado, se avisa. Cambiar el estado de los albaranes marcados abre su ventana. */
  test('las opciones de los listados', () => {
    const ver = paginaDePrueba();
    const ninguno = paginaDePrueba({ marcados: [] });
    const estado = paginaDePrueba({ marcados: ['7', '8'] });

    ver.contexto.metodoClick('Modificar', 'albaran');
    ninguno.contexto.metodoClick('Ver', 'pedido');
    ninguno.contexto.metodoClick('cambiarEstado', 'albaranes');
    estado.contexto.metodoClick('cambiarEstado', 'albaranes');
    estado.responder({ html: '<select></select>' });

    expect(ver.location.href).toBe('./albaran.php?id=7&accion=editar');
    expect(ninguno.avisos).toEqual(['Que items tienes seleccionados? \n Solo puedes tener uno seleccionado', 'No tienes items seleccionados']);
    expect(estado.modales).toEqual([['Cambiar estado Albaranes ', '<select></select>']]);
  });

  /** @comportamiento Cambiar el estado de los albaranes marcados manda el elegido y recarga la página. */
  test('cambiar el estado de los albaranes marcados', () => {
    const pagina = paginaDePrueba({ marcados: ['7', '8'], valores: { 'select[id=Nuevo_estado_albaranes]': 'Validado' } });

    pagina.contexto.cambiarEstadoVariosAlbaranes();
    pagina.responder([]);

    expect(pagina.ultima().data).toEqual({ pulsado: 'cambiarEstadoVariosAlbaranes', ids: ['7', '8'], estado: 'Validado', dedonde: 'Albaranes' });
    expect(pagina.location.reload).toHaveBeenCalled();
  });

  /**
   * @estado rojo
   * @codigo-afectado modulos/mod_compras/funciones.js:202-221
   *
   * @group defecto
   * @group presentacion
   * @group bajo
   *
   * @que-ocurre-hoy La ventana de importar albaranes se abre con el título de la de cambiar su estado.
   * @que-deberia-ocurrir Que lleve su título.
   * @por-que-ocurre El título es una copia del de la otra ventana.
   * @como-deberia-funcionar Titularla como importar albaranes.
   */
  test('la ventana de importar albaranes lleva su título', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.modalImportarAlbaranes();
    pagina.responder({ html: '<div>importar</div>' });

    expect(pagina.modales[0][0]).toMatch(/[Ii]mportar/);
  });

  /** @comportamiento Elegir cómo importar pinta su formulario en la zona de la importación. */
  test('elegir cómo importar albaranes', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.ampliarInformacionImportar('CambioAno');
    pagina.responder({ html: '<form id="formImportarAlbaranCierreAno"></form>' });

    expect(pagina.ultima().data).toEqual({ pulsado: 'modalCambioAno' });
    expect(pagina.cajas.areaImportacionAlbaranes.innerHTML).toBe('<form id="formImportarAlbaranCierreAno"></form>');
  });

  /** @comportamiento Cancelar un temporal pide confirmación: sin temporal lo avisa; cancelado, vuelve al listado de su documento; con error, lo avisa. */
  test('cancelar un temporal', () => {
    const sinTemporal = paginaDePrueba();
    const albaran = paginaDePrueba();
    const conError = paginaDePrueba();
    const noConfirmado = paginaDePrueba({ confirmar: false });

    sinTemporal.contexto.mensajeCancelar('0', 'albaran');
    albaran.contexto.mensajeCancelar('41', 'albaran');
    albaran.responder([]);
    conError.contexto.mensajeCancelar('41', 'factura');
    conError.responder({ mensaje: 'Error de SQL', dato: 'DELETE' });
    noConfirmado.contexto.mensajeCancelar('41', 'pedido');

    expect(sinTemporal.avisos).toEqual(['No puedes BORRAR TEMPORAL si aun no existe, pulsa volver']);
    expect(albaran.peticiones[0].data).toEqual({ pulsado: 'cancelarTemporal', dedonde: 'albaran', idTemporal: '41' });
    expect(albaran.location.href).toBe('albaranesListado.php');
    expect(conError.avisos).toEqual(['Error de SQL: DELETE']);
    expect(noConfirmado.peticiones).toEqual([]);
  });

  /** @comportamiento Eliminar un temporal desde su listado avisa y recarga si se eliminó; si no, avisa del error. */
  test('eliminar un temporal desde su listado', () => {
    const pagina = paginaDePrueba();
    const conError = paginaDePrueba();

    pagina.contexto.eliminarTemporal(41, 'ListadoAlbaranes');
    pagina.responder({ valores_insert: 1 });
    conError.contexto.eliminarTemporal(41, 'ListadoAlbaranes');
    conError.responder({ error: 'No se ejecuto.' });

    expect(pagina.avisos).toEqual(['Fue eliminado correctamente']);
    expect(pagina.location.reload).toHaveBeenCalled();
    expect(conError.avisos).toEqual(['Ocurrio un error:{"error":"No se ejecuto."}']);
  });

  /** @comportamiento Imprimir pide el documento y abre su PDF; las incidencias adjuntas se abren en su ventana, y su error se avisa. */
  test('imprimir y las incidencias adjuntas', () => {
    const pagina = paginaDePrueba();
    const conError = paginaDePrueba();

    pagina.contexto.imprimir(41, 'albaran', 55);
    pagina.responder('/tmp/albarancompras.pdf');
    pagina.contexto.abrirIncidenciasAdjuntas(41, 'mod_compras', 'albaran');
    pagina.responder({ html: '<div>incidencias</div>' });
    conError.contexto.abrirIncidenciasAdjuntas(41, 'mod_compras', 'albaran');
    conError.responder({ error: 'x', consulta: 'SELECT' });

    expect(pagina.peticiones[0].data).toEqual({ pulsado: 'datosImprimir', dedonde: 'albaran', id: 41, idTienda: 55 });
    expect(pagina.abiertas).toEqual(['/tmp/albarancompras.pdf']);
    expect(pagina.modales).toEqual([['Listado de incidencias ', '<div>incidencias</div>']]);
    expect(conError.avisos).toEqual(['SELECT']);
  });

  /** @comportamiento El correo se prepara en su ventana; enviarlo manda el formulario y enseña el resultado. Aquí la respuesta es de pega: no se envía nada. */
  test('preparar y enviar el correo de un documento', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.formularioEnvioEmail(12, 'pedido', 55, 'compras@example.com');
    pagina.responder({ html: '<form id="FormEmail"></form>' });
    pagina.contexto.enviarCorreo();
    pagina.envios[0].funcion(JSON.stringify({ envio_destinatario: 'OK', subido_enviados: 'OK' }));

    expect(pagina.peticiones[0].data).toEqual({ pulsado: 'obtenerFormularioEmail', idTienda: 55, dedonde: 'pedido', id: 12, destinatario: 'compras@example.com' });
    expect(pagina.modales[0]).toEqual(['Enviar por email el pedido', '<form id="FormEmail"></form>']);
    expect(pagina.envios[0].datos).toBe('pulsado=enviarEmail&id=12');
    expect(pagina.modales[1][1]).toContain('Fue enviado correctame');
  });
});
