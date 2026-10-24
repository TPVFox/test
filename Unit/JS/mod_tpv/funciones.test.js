/**
 * El JavaScript del TPV (`modulos/mod_tpv/funciones.js`): añadir, eliminar y recuperar líneas, grabar
 * el ticket temporal, buscar productos y clientes, cobrar y cerrar el ticket, las acciones del teclado,
 * las opciones del listado de tickets, los cambios de un ticket cobrado, la configuración y el envío del
 * stock a la web.
 *
 * Se ejecuta en un contexto propio de Node, con un jQuery de prueba que apunta lo que se le pide —las
 * peticiones a las tareas, los valores de las cajas, el HTML pintado y las clases— sin una página real.
 * `alert`, la dirección de la página, el historial, la ventana modal, los sonidos y las funciones del
 * núcleo que usa (`VerIdSeleccionado`, `enviarStockWeb`) son de pega.
 */

'use strict';

const path = require('path');
const { cargarScriptGlobal } = require('../../../support/js/cargarScriptGlobal');

const FUNCIONES = path.resolve(__dirname, '../../../../TPVFox/modulos/mod_tpv/funciones.js');

/** Un elemento de pega de jQuery: apunta lo que se le hace. */
function elemento(selector, pagina) {
  const el = {
    val(valor) { if (valor === undefined) { return pagina.valores[selector] ?? ''; } pagina.valores[selector] = String(valor); return el; },
    html(contenido) { if (contenido === undefined) { return pagina.pintado[selector] ?? ''; } pagina.pintado[selector] = contenido; return el; },
    prepend(contenido) { pagina.pintado[selector] = contenido + (pagina.pintado[selector] ?? ''); return el; },
    prop(nombre, valor) { if (valor === undefined) { return pagina.propiedades[selector + ':' + nombre]; } pagina.propiedades[selector + ':' + nombre] = valor; return el; },
    addClass(clase) { pagina.clases[selector] = clase; return el; },
    removeClass() { pagina.clases[selector] = ''; return el; },
    attr() { return el; },
    removeAttr() { return el; },
    css() { return el; },
    show() { pagina.visibles[selector] = true; return el; },
    hide() { pagina.visibles[selector] = false; return el; },
    focus() { pagina.enfocada = selector; return el; },
    select() { pagina.enfocada = selector; return el; },
    slice() { return el; },
    remove() { return el; },
  };
  return el;
}

function paginaDePrueba({ valores = {}, propiedades = {}, marcados = ['7'], productos = [], total = 0 } = {}) {
  const pagina = { peticiones: [], avisos: [], modales: [], enviados: [], historial: [], valores: { ...valores }, propiedades: { ...propiedades }, pintado: {}, clases: {}, visibles: {}, enfocada: null, cajas: {} };
  const $ = (selector) => elemento(selector, pagina);
  $.ajax = (opciones) => { pagina.peticiones.push(opciones); };
  $.parseJSON = JSON.parse;
  pagina.window = { location: { href: 'http://tpv/modulos/mod_tpv/tpv.php' } };
  pagina.contexto = cargarScriptGlobal(FUNCIONES, {
    $,
    jQuery: $,
    alert: (texto) => { pagina.avisos.push(String(texto)); },
    console: { log() {} },
    window: pagina.window,
    document: {
      getElementById: (id) => (pagina.cajas[id] ??= { value: 'algo' }),
      location: pagina.window.location,
    },
    history: { pushState: (estado, titulo, url) => { pagina.historial.push(url); } },
    setTimeout: (funcion) => { funcion(); return 0; },
    checkID: [],
    productos: productos.slice(),
    cabecera: { idCliente: 1, idUsuario: 83, idTienda: 55, estadoTicket: 'Nuevo', numTicket: 0 },
    configuracion: { campo_peso: 'no', input_pordefecto: 'Codbarras', impresora_ticket: '/dev/usb/lp0', impresion_ticket: 'Si' },
    abrirModal: (titulo, html) => { pagina.modales.push([titulo, html]); },
    cerrarPopUp: () => {},
    focusAlLanzarModal: (caja) => { pagina.enfocada = caja; },
    SelectAlLanzarModal: () => {},
    enviarStockWeb: (tienda, productosWeb, id) => { pagina.enviados.push([tienda, productosWeb, id]); },
    sonido_selecionaProducto: { play() {} },
    sonido_alerta: { play() {} },
    CobrarAceptar: { parametros: {} },
  });
  pagina.contexto.total = total;
  pagina.contexto.VerIdSeleccionado = () => { pagina.contexto.checkID = marcados.slice(); };
  pagina.ultima = () => pagina.peticiones[pagina.peticiones.length - 1];
  pagina.responder = (respuesta, peticion = pagina.ultima()) => peticion.success(JSON.stringify(respuesta));

  return pagina;
}

/** Un producto como lo responde la búsqueda. */
function encontrado(mas = {}) {
  return { idArticulo: '5', crefTienda: '1001', articulo_name: 'Pera', pvpCiva: '1.1', codBarras: '8400000000017', iva: '10.00', tipo: 'unidad', ...mas };
}

/** Una línea del ticket como la arma la página. */
function linea(mas = {}) {
  return { id: '5', cref: '1001', cdetalle: 'Pera', pvpconiva: '1.10', ccodebar: '8400000000017', ctipoiva: '10.00', unidad: 2, estado: 'Activo', tipo: 'unidad', nfila: 1, ...mas };
}

/** Una caja de pega de `teclado.js`. */
function caja(nombre, valor, mas = {}) {
  return { name_cja: nombre, id_input: nombre, darValor: () => valor, darParametro: (p) => ({ dedonde: 'tpv', campo: 'a.articulo_name', prefijo: 'N_' })[p], ...mas };
}

describe('las líneas del ticket', () => {
  /**
   * @comportamiento Añadir un producto lo pone en el ticket con su precio a dos decimales, una unidad,
   *   activo y en la fila siguiente; pide su línea, la pinta arriba y graba el temporal.
   */
  test('añadir un producto pinta su línea y graba el ticket', () => {
    const pagina = paginaDePrueba({ productos: [linea()] });

    pagina.contexto.agregarFila(encontrado());
    const pedida = pagina.ultima();
    pagina.responder({ html: '<tr id="Row2"></tr>' });

    expect(pagina.contexto.productos[1]).toMatchObject({ id: '5', cref: '1001', cdetalle: 'Pera', pvpconiva: '1.10', ctipoiva: '10.00', unidad: 1, estado: 'Activo', nfila: 2, importe: 1.1 });
    expect(pedida.data).toMatchObject({ pulsado: 'HtmlLineaTicket', num_item: 1, CONF_campoPeso: 'no' });
    expect(pagina.pintado['#tabla']).toBe('<tr id="Row2"></tr>');
    expect(pagina.enfocada).toBe('#Codbarras');
    expect(pagina.ultima().data.pulsado).toBe('grabarTickes');
  });

  /** @comportamiento Eliminar una línea la deja eliminada, tachada y con las unidades bloqueadas; recuperarla la vuelve activa; las dos graban el ticket. */
  test('eliminar y recuperar una línea', () => {
    const pagina = paginaDePrueba({ productos: [linea()] });

    pagina.contexto.eliminarFila(0);
    const eliminada = { ...pagina.contexto.productos[0] };
    const bloqueada = pagina.propiedades['#Unidad_Fila_1:disabled'];
    pagina.contexto.retornarFila(0);

    expect([eliminada.estado, bloqueada]).toEqual(['Eliminado', true]);
    expect(pagina.contexto.productos[0].estado).toBe('Activo');
    expect(pagina.propiedades['#Unidad_Fila_1:disabled']).toBe(false);
    expect(pagina.peticiones.map((p) => p.data.pulsado)).toEqual(['grabarTickes', 'grabarTickes']);
  });

  /**
   * @comportamiento Grabar el ticket manda sus productos y su cabecera; con la respuesta, el ticket queda
   *   actual con su número, el total y el desglose se pintan, y con el primer producto la dirección
   *   lleva el número del temporal.
   */
  test('grabar el ticket temporal', () => {
    const pagina = paginaDePrueba({ productos: [linea()] });

    pagina.contexto.grabarTicketsTemporal();
    const enviada = pagina.ultima().data;
    pagina.responder({ estadoTicket: 'Actual', NumeroTicket: 8, total: '2.20', html: '<tr>desglose</tr>' });

    expect(enviada).toEqual({ pulsado: 'grabarTickes', productos: JSON.stringify([linea()]), idCliente: 1, idTienda: 55, idUsuario: 83, estadoTicket: 'Nuevo', numTicket: 0 });
    expect([pagina.contexto.cabecera.estadoTicket, pagina.contexto.cabecera.numTicket, pagina.contexto.total]).toEqual(['Actual', 8, 2.2]);
    expect([pagina.pintado['#NTicket'], pagina.pintado['.totalImporte'], pagina.pintado['#tabla-iva']]).toEqual(['0/8', '2.20', '<tr>desglose</tr>']);
    expect(pagina.historial).toEqual(['?tActual=8']);
  });

  /** @comportamiento Cambiar las unidades de una línea con un número las pone y pinta su importe; con algo que no es un número, avisa y deja una. */
  test('cambiar las unidades de una línea', () => {
    const bien = paginaDePrueba({ productos: [linea()] });
    const mal = paginaDePrueba({ productos: [linea()] });

    bien.contexto.controladorAcciones(caja('Unidad_Fila_1', '3', { fila: '1' }), 'recalcular_ticket');
    mal.contexto.controladorAcciones(caja('Unidad_Fila_1', 'tres', { fila: '1' }), 'recalcular_ticket');

    expect(bien.contexto.productos[0].unidad).toBe('3');
    expect(bien.pintado['#N1_Importe']).toBe('3.30');
    expect(mal.avisos).toEqual(['Incorrecto la cantidad en Pera']);
    expect(mal.valores['#Unidad_Fila_1']).toBe('1');
  });

  /**
   * @estado rojo
   * @codigo-afectado modulos/mod_tpv/funciones.js:643-655,910-916,1085-1101
   *
   * @group defecto
   * @group presentacion
   * @group bajo
   *
   * @que-ocurre-hoy Poner cero unidades en una línea no la elimina: la página dice que la cantidad es
   *   incorrecta y le pone una.
   * @que-deberia-ocurrir Que cero unidades eliminen la línea, como prevé el recálculo del importe.
   * @por-que-ocurre La comprobación del número rechaza todo valor que empiece por cero sin punto
   *   detrás, también el cero.
   * @como-deberia-funcionar Aceptar el cero como número.
   */
  test('cero unidades eliminan la línea', () => {
    const pagina = paginaDePrueba({ productos: [linea()] });

    pagina.contexto.controladorAcciones(caja('Unidad_Fila_1', '0', { fila: '1' }), 'recalcular_ticket');

    expect(pagina.avisos).toEqual([]);
    expect(pagina.contexto.productos[0].estado).toBe('Eliminado');
  });

  /** @comportamiento Cambiar el precio de una línea con un número lo pone, pinta el importe, bloquea la caja y vuelve al código de barras. */
  test('cambiar el precio de una línea', () => {
    const pagina = paginaDePrueba({ productos: [linea()] });

    pagina.contexto.controladorAcciones(caja('precioCIva_1', '1.50'), 'CambiarPrecioProducto');

    expect(pagina.contexto.productos[0].pvpconiva).toBe('1.50');
    expect(pagina.pintado['#N1_Importe']).toBe('3.00');
    expect(pagina.enfocada).toBe('#Codbarras');
  });
});

describe('buscar productos y clientes', () => {
  /**
   * @comportamiento Buscar un producto manda la caja, el campo y el valor; uno encontrado se añade al
   *   ticket y limpia la caja; un listado se abre en la ventana con el primero enfocado.
   */
  test('un producto encontrado se añade y un listado se abre', () => {
    const uno = paginaDePrueba();
    const varios = paginaDePrueba();

    uno.contexto.buscarProductos('Codbarras', 'ac.codBarras', '8400000000017', 'tpv');
    const pedida = uno.ultima().data;
    uno.responder({ Estado: 'Correcto', datos: [encontrado()], dedonde: 'tpv' });
    varios.contexto.buscarProductos('Descripcion', 'a.articulo_name', 'pera', 'tpv');
    varios.responder({ Estado: 'Listado', datos: [], listado: { html: '<table>pera</table>' }, Nitems: 2, tipo_busqueda: 1, dedonde: 'tpv' });

    expect(pedida).toEqual({ pulsado: 'buscarProductos', cajaInput: 'Codbarras', valorCampo: '8400000000017', campo: 'ac.codBarras', dedonde: 'tpv' });
    expect(uno.cajas.Codbarras.value).toBe('');
    expect(uno.contexto.productos).toHaveLength(1);
    expect(varios.modales).toEqual([['Listado productos encontrados ', '<table>pera</table>']]);
    expect(varios.enfocada).toBe('#N_0');
  });

  /** @comportamiento Desde el listado, buscar sin valor vuelve a la caja de búsqueda. */
  test('buscar sin valor desde el listado vuelve a la caja', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.buscarProductos('Descripcion', 'a.articulo_name', '', 'popup');
    pagina.responder({ Estado: 'NoSeBusco', dedonde: 'popup' });

    expect(pagina.enfocada).toBe('#cajaBusqueda');
    expect(pagina.modales).toEqual([]);
  });

  /**
   * @comportamiento Un cliente encontrado se pone en el ticket y, si tiene productos, lo graba; varios se
   *   abren en la ventana; un error de la consulta se avisa.
   */
  test('buscar clientes', () => {
    const uno = paginaDePrueba({ productos: [linea()] });
    const varios = paginaDePrueba();
    const error = paginaDePrueba();

    uno.contexto.buscarClientes('tpv', 'Pepe');
    uno.responder({ Nitems: 1, id: '7', nombre: 'Bar Pepe-Pepe SL' });
    varios.contexto.buscarClientes('tpv', 'Bar');
    varios.responder({ Nitems: 2, html: '<table>clientes</table>' });
    error.contexto.buscarClientes('tpv', 'Bar');
    error.responder({ error: ['x'], consulta: 'SELECT' });

    expect([uno.valores['#id_cliente'], uno.valores['#Cliente'], uno.contexto.cabecera.idCliente]).toEqual(['7', 'Bar Pepe-Pepe SL', '7']);
    expect(uno.ultima().data.pulsado).toBe('grabarTickes');
    expect(varios.modales).toEqual([['Listado clientes ', '<table>clientes</table>']]);
    expect(error.avisos).toEqual(['ERROR DE SQL: SELECT']);
  });

  /** @comportamiento Un cliente elegido en un ticket cobrado se pone en la pantalla y ofrece cambiarlo. */
  test('elegir un cliente en un ticket cobrado ofrece cambiarlo', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.escribirClienteSeleccionado('7', 'Bar Pepe', 'cobrados');

    expect([pagina.valores['#id_cliente'], pagina.visibles['#cambioCliente']]).toEqual(['7', true]);
  });
});

describe('cobrar el ticket', () => {
  /** @comportamiento Sin productos no se cobra; con productos, se pide la ventana de cobrar con el total y los productos, y se abre. */
  test('cobrar abre la ventana de cobrar', () => {
    const vacio = paginaDePrueba();
    const lleno = paginaDePrueba({ productos: [linea()], total: 2.2 });

    vacio.contexto.cobrarF1();
    lleno.contexto.cobrarF1();
    const pedida = lleno.ultima().data;
    lleno.responder({ html: '<h1>2.20</h1>' });

    expect(vacio.avisos).toEqual(['No hay productos no podemos cobrar']);
    expect([pedida.pulsado, pedida.total, pedida.productos]).toEqual(['cobrar', 2.2, JSON.stringify([linea()])]);
    expect(lleno.modales).toEqual([['COBRAR ', '<h1>2.20</h1>']]);
  });

  /** @comportamiento Lo entregado calcula el cambio; algo que no es un número se avisa y se borra. */
  test('lo entregado calcula el cambio', () => {
    const bien = paginaDePrueba({ total: 14.3 });
    const mal = paginaDePrueba({ total: 14.3 });

    bien.contexto.controladorAcciones(caja('entrega', '20'), 'poner_entrega');
    mal.contexto.controladorAcciones(caja('entrega', 'veinte'), 'poner_entrega');

    expect(bien.valores['#cambio']).toBe('5.70');
    expect(bien.enfocada).toBe('#CobrarAceptar');
    expect(mal.avisos).toEqual(['Pon bien lo entregado..!!!']);
    expect(mal.valores['#entrega']).toBe('');
  });

  /**
   * @comportamiento Cerrar el ticket manda la cabecera, el total, lo entregado, el cambio, la forma de
   *   pago, si imprimir y la impresora de la configuración, y vuelve a un ticket nuevo; sin entregado,
   *   manda el total y cambio cero.
   */
  test('cerrar el ticket', () => {
    const pagina = paginaDePrueba({ total: 14.3, valores: { '#entrega': '20', '#cambio': '5.70', '#modoPago': 'tarjeta' }, propiedades: { 'input[name=checkimprimir]:checked': true } });
    const sinEntregado = paginaDePrueba({ total: 14.3, valores: { '#modoPago': 'contado' } });

    pagina.contexto.cerrarTicket();
    const enviada = pagina.ultima().data;
    pagina.responder({ grabar: {} });
    sinEntregado.contexto.cerrarTicket();

    expect(enviada).toEqual({ pulsado: 'CerrarTicket', idCliente: 1, idTienda: 55, idUsuario: 83, estadoTicket: 'Nuevo', numTickTemporal: 0, total: 14.3, entregado: '20', cambio: '5.70', formaPago: 'tarjeta', checkimprimir: true, ruta_impresora: '/dev/usb/lp0' });
    expect(pagina.propiedades['button[id=CobrarAceptar]:disabled']).toBe(true);
    expect(pagina.window.location.href).toBe('tpv.php');
    expect([sinEntregado.ultima().data.entregado, sinEntregado.ultima().data.cambio]).toEqual([14.3, '0']);
  });

  /** @comportamiento Si la impresora no está, cerrar el ticket lo avisa. */
  test('sin impresora, cerrar el ticket lo avisa', () => {
    const pagina = paginaDePrueba({ total: 14.3, valores: { '#entrega': '20' } });

    pagina.contexto.cerrarTicket();
    pagina.responder({ error_impresora: ' no existe la impresora asignada, hay un error' });

    expect(pagina.avisos).toEqual(['Impresora de ticket apagada o no es correcta configuracion , NO SE PUEDE IMPRIMIR !!']);
  });

  /**
   * @estado rojo
   * @codigo-afectado modulos/mod_tpv/funciones.js:356-370
   *
   * @group defecto
   * @group presentacion
   * @group bajo
   *
   * @que-ocurre-hoy Si cobrar responde un error —el total no coincide—, el aviso dice «tipo = undefined»
   *   y «mensaje = undefined»: el cajero no sabe qué pasó.
   * @que-deberia-ocurrir Que el aviso lleve el mensaje del error.
   * @por-que-ocurre La respuesta pone el tipo, el mensaje y los datos en elementos separados, y el aviso
   *   busca los dos en cada uno.
   * @como-deberia-funcionar Leer el error tal como lo arma la petición, o armarlo en un solo elemento.
   */
  test('un error al cobrar se avisa con su mensaje', () => {
    const pagina = paginaDePrueba({ total: 1, valores: { '#entrega': '20' } });

    pagina.contexto.cerrarTicket();
    pagina.responder({ error: [{ tipo: 'warning' }, { mensaje: ' No coincidente TOTAL:1 con el Total recalculado' }, { datos: { total: '14.30' } }] });

    expect(pagina.avisos[0]).toContain('No coincidente TOTAL');
  });
});

describe('el listado de tickets y un ticket cobrado', () => {
  /** @comportamiento Ver e imprimir exigen un solo ticket marcado: ver abre el ticket y imprimir lo pide a la petición. */
  test('ver e imprimir un ticket marcado', () => {
    const ver = paginaDePrueba();
    const imprimir = paginaDePrueba();
    const dos = paginaDePrueba({ marcados: ['7', '8'] });

    ver.contexto.metodoClick('VerTicket');
    imprimir.contexto.metodoClick('imprimirTicket');
    dos.contexto.metodoClick('VerTicket');

    expect(ver.window.location.href).toBe('./ticketCobrado.php?id=7');
    expect(imprimir.ultima().data).toEqual({ pulsado: 'ImprimirTicketCerrados', idTicketst: '7' });
    expect(dos.avisos).toHaveLength(1);
    expect(dos.peticiones).toHaveLength(0);
  });

  /** @comportamiento Cambiar la fecha pide el formulario con los marcados, lo abre, y manda los marcados con la fecha elegida. */
  test('cambiar la fecha de los marcados', () => {
    const pagina = paginaDePrueba({ marcados: ['7', '8'], valores: { '#fechaNueva': '2026-10-23' } });

    pagina.contexto.metodoClick('cambiarFechaItemsSeleccionado');
    const formulario = pagina.ultima().data;
    pagina.responder({ html: '<div>2 ticket(s)</div>' });
    pagina.contexto.activar_btn_cambiar();
    pagina.contexto.cambioFechaTickets();

    expect(formulario).toEqual({ pulsado: 'htmlFechaNueva', Tickets: ['7', '8'] });
    expect(pagina.modales).toEqual([['Cambio de Fecha de tickets ', '<div>2 ticket(s)</div>']]);
    expect(pagina.propiedades['#btn_cambiar:disabled']).toBe(false);
    expect(pagina.ultima().data).toEqual({ pulsado: 'cambiarFechaTicketsSeleccionados', Tickets: ['7', '8'], FechaNueva: '2026-10-23' });
  });

  /** @comportamiento El cliente y la forma de pago de un ticket cobrado se cambian por la petición, que responde el mensaje que se avisa. */
  test('cambiar el cliente y la forma de pago de un ticket cobrado', () => {
    const pagina = paginaDePrueba({ valores: { '#id_cliente': '9', '#modoPago': 'tarjeta' } });

    pagina.contexto.cambioCliente(7);
    const cliente = pagina.ultima().data;
    pagina.responder({ mensaje: 'Cliente Modificado con Éxito' });
    pagina.contexto.mostrarCambioFormaPago();
    pagina.contexto.cambioFormaPago(7);

    expect(cliente).toEqual({ pulsado: 'cambiarClienteTicketGuardado', id_ticketst: 7, idCliente: '9' });
    expect(pagina.avisos).toEqual(['Cliente Modificado con Éxito']);
    expect(pagina.visibles['#cambioFormaPago']).toBe(true);
    expect(pagina.ultima().data).toEqual({ pulsado: 'cambiarFormaPagoTicketGuardado', id_ticketst: 7, formaPago: 'tarjeta' });
  });

  /**
   * @comportamiento Descontar el stock en la web pide los productos del ticket con su número en la web;
   *   si alguno no lo tiene, avisa y no envía nada; si todos lo tienen, los envía sin su nombre ni su
   *   código de barras.
   */
  test('descontar el stock en la web', () => {
    const falta = paginaDePrueba();
    const todos = paginaDePrueba();

    falta.contexto.PrepararEnviarStockWeb(7);
    const pedida = falta.ultima().data;
    falta.responder({ tienda: { idTienda: '2' }, productos: [{ idArticulo: '5', cdetalle: 'Pera', idVirtuemart: undefined }] });
    todos.contexto.PrepararEnviarStockWeb(7);
    todos.responder({ tienda: { idTienda: '2' }, productos: [{ idArticulo: '5', cdetalle: 'Pera', ccodbar: '84', estadoLinea: 'Activo', nunidades: '2', idVirtuemart: '31' }] });

    expect(pedida).toEqual({ pulsado: 'ObtenerRefTiendaWeb', idTicket: 7, web: '2' });
    expect(falta.avisos).toEqual(['Este producto:Pera \n No tiene relacion con en la web de momento no continuamos']);
    expect(falta.enviados).toEqual([]);
    expect(todos.enviados).toEqual([[{ idTienda: '2' }, [{ idArticulo: '5', nunidades: '2', idVirtuemart: '31' }], 7]]);
  });

  /** @comportamiento Registrar que se descontó el stock bloquea el botón y lo manda a la petición. */
  test('registrar el stock descontado', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.RegistrarRestarStockTicket(7, 'Correcto', { row_afectados: 2 });

    expect(pagina.propiedades['#DescontarStock:disabled']).toBe(true);
    expect(pagina.ultima().data).toEqual({ pulsado: 'RegistrarRestaStock', id_ticketst: 7, estado: 'Correcto', datos: { row_afectados: 2 } });
  });
});

describe('la configuración', () => {
  /** @comportamiento Cambiar una casilla de la configuración la alterna y graba la configuración; cambiar la caja de salto, también. */
  test('cambiar la configuración la graba', () => {
    const pagina = paginaDePrueba();
    pagina.cajas.salto = { value: 'Referencia' };

    pagina.contexto.CambiarConfiguracion({ name: 'impresion_ticket' });
    const primera = { ...pagina.ultima().data.configuracion };
    pagina.contexto.CambiarSaltoCampo();

    expect(primera).toMatchObject({ impresion_ticket: 'No' });
    expect(pagina.ultima().data).toMatchObject({ pulsado: 'Grabar_configuracion', configuracion: { input_pordefecto: 'Referencia' } });
  });

  /** @comportamiento La configuración base se pide y se abre en la ventana; guardarla manda los valores del formulario y avisa la respuesta. */
  test('la configuración base', () => {
    const pagina = paginaDePrueba({ valores: { '#impresion_ticket': 'No', '#impresora_ticket': '/dev/usb/lp1', '#corte_tickets': 'Si', '#input_pordefecto': 'Referencia', '#campo_peso': 'No', '#btn_familias': 'Si', '#incidencias_mostrar_Select': 'Si', '#incidencias_usuario_Defecto': '1' } });

    pagina.contexto.configuracionBase();
    pagina.responder({ html: '<form id="configForm"></form>' });
    pagina.contexto.guardarXML();
    const enviada = pagina.ultima().data;
    pagina.responder({ mensaje: 'Guardado con éxito' });

    expect(pagina.modales).toEqual([['Configuracion Base ', '<form id="configForm"></form>']]);
    expect(enviada).toEqual({ pulsado: 'guardarXML', datos: { impresion_ticket: 'No', impresora_ticket: '/dev/usb/lp1', corte_tickets: 'Si', input_pordefecto: 'Referencia', campo_peso: 'No', btn_familias: 'Si', incidencias_mostrar_Select: 'Si', incidencias_usuario_Defecto: '1' } });
    expect(pagina.avisos).toEqual(['Guardado con éxito']);
  });

  /** @comportamiento El listado de una familia se pide por su número y se abre en la ventana. */
  test('el listado de una familia', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.listadofamilia(3);
    pagina.responder({ html: '<table>familia</table>' });

    expect(pagina.peticiones[0].data).toEqual({ pulsado: 'listadoFamilia', idFamilia: 3 });
    expect(pagina.modales).toEqual([['Listado por familia ', '<table>familia</table>']]);
  });
});
