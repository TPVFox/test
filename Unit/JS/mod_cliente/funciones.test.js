/**
 * El JavaScript de los clientes (`modulos/mod_cliente/funciones.js`): las opciones del listado, el
 * buscador y la línea de precios de la tarifa, grabar y borrar un producto de la tarifa, los resúmenes,
 * las impresiones y el informe de descuentos de tickets.
 *
 * Se ejecuta en un contexto propio de Node, con un jQuery de prueba que apunta lo que se le pide —las
 * peticiones a las tareas, los valores de las cajas y lo que se enseña o se esconde— sin una página
 * real. `alert`, `window.open`, la dirección de la página, la ventana modal y las funciones del núcleo
 * que usa (`VerIdSeleccionado`, `ponerSelect`) son de pega.
 */

'use strict';

const path = require('path');
const { cargarScriptGlobal } = require('../../../support/js/cargarScriptGlobal');

const FUNCIONES = path.resolve(__dirname, '../../../../TPVFox/modulos/mod_cliente/funciones.js');

/** Un elemento de pega de jQuery: apunta lo que se le hace. */
function elemento(selector, pagina) {
  const el = {
    val(valor) { if (valor === undefined) { return pagina.valores[selector] ?? ''; } pagina.valores[selector] = String(valor); return el; },
    html(contenido) { if (contenido === undefined) { return pagina.pintado[selector] ?? ''; } pagina.pintado[selector] = contenido; return el; },
    show() { pagina.visibles[selector] = true; return el; },
    hide() { pagina.visibles[selector] = false; return el; },
    removeAttr() { return el; },
    select() { pagina.enfocada = selector; return el; },
    focus() { pagina.enfocada = selector; return el; },
    css() { return el; },
    animate() { return el; },
  };
  return el;
}

function paginaDePrueba({ valores = {}, marcados = ['7'] } = {}) {
  const pagina = { peticiones: [], avisos: [], modales: [], abiertas: [], cerradas: 0, valores: { ...valores }, pintado: {}, visibles: {}, enfocada: null };
  const $ = (selector) => elemento(selector, pagina);
  $.ajax = (opciones) => { pagina.peticiones.push(opciones); };
  $.parseJSON = JSON.parse;
  pagina.window = { location: { href: 'http://tpv/modulos/mod_cliente/ListaClientes.php' }, open: (url) => { pagina.abiertas.push(url); } };
  pagina.contexto = cargarScriptGlobal(FUNCIONES, {
    $,
    jQuery: $,
    alert: (texto) => { pagina.avisos.push(String(texto)); },
    console: { log() {} },
    window: pagina.window,
    setTimeout: (funcion) => { funcion(); return 0; },
    checkID: [],
    cliente: { idClientes: '7' },
    abrirModal: (titulo, html) => { pagina.modales.push([titulo, html]); },
    cerrarPopUp: () => { pagina.cerradas++; },
    focusAlLanzarModal: () => {},
    ponerSelect: () => {},
  });
  pagina.contexto.VerIdSeleccionado = () => { pagina.contexto.checkID = marcados.slice(); };
  pagina.ultima = () => pagina.peticiones[pagina.peticiones.length - 1];
  pagina.responder = (respuesta) => pagina.ultima().success(JSON.stringify(respuesta));

  return pagina;
}

/** Una caja de pega de `teclado.js`. */
function caja(nombre, valor, mas = {}) {
  return { name_cja: nombre, id_input: 'caja' + nombre, dedonde: 'tarifa', darValor: () => valor, darParametro: () => '', ...mas };
}

describe('las opciones del listado de clientes', () => {
  /** @comportamiento «Añadir» abre la ficha vacía; «Modificar» y «Tarifa», las del cliente marcado; sin uno solo marcado, avisa. */
  test('añadir, modificar y la tarifa', () => {
    const anadir = paginaDePrueba();
    const modificar = paginaDePrueba();
    const tarifa = paginaDePrueba();
    const dos = paginaDePrueba({ marcados: ['7', '8'] });

    anadir.contexto.metodoClick('AgregarCliente');
    modificar.contexto.metodoClick('VerCliente');
    tarifa.contexto.metodoClick('TarificarCliente');
    dos.contexto.metodoClick('VerCliente');

    expect(anadir.window.location.href).toBe('./cliente.php');
    expect(modificar.window.location.href).toBe('./cliente.php?id=7');
    expect(tarifa.window.location.href).toBe('./tarifaCliente.php?id=7');
    expect(dos.avisos).toHaveLength(1);
    expect(dos.window.location.href).toBe('http://tpv/modulos/mod_cliente/ListaClientes.php');
  });

  /** @comportamiento Los resúmenes de tickets y de albaranes abren su pantalla con el cliente. */
  test('los resúmenes abren su pantalla', () => {
    const tickets = paginaDePrueba();
    const albaranes = paginaDePrueba();

    tickets.contexto.resumen('tickets', 7);
    albaranes.contexto.resumen('albaranes', 7);

    expect(tickets.window.location.href).toBe('./Resumenes/resumenTickets.php?id=7&historyJS=1');
    expect(albaranes.window.location.href).toBe('./Resumenes/resumenAlbaranes.php?id=7&historyJS=1');
  });
});

describe('la tarifa de un cliente', () => {
  /** @comportamiento Buscar con valor pide los productos a la petición; sin valor, pasa a la caja siguiente. */
  test('buscar un producto pide los productos o pasa a la siguiente caja', () => {
    const conValor = paginaDePrueba({ valores: { '#cajaidArticulo': '5' } });
    const sinValor = paginaDePrueba();

    conValor.contexto.controladorAcciones(caja('Descripcion', 'Pera'), 'buscarProducto');
    sinValor.contexto.controladorAcciones(caja('Referencia', ''), 'buscarProducto');

    expect(conValor.peticiones[0].data).toEqual({ dedonde: 'tarifa', idcliente: '7', caja: 'Descripcion', valor: 'Pera', pulsado: 'leerArticulo' });
    expect(conValor.valores['#cajaidArticulo']).toBe('');
    expect(sinValor.peticiones).toHaveLength(0);
    expect(sinValor.enfocada).toBe('#cajaCodbarras');
  });

  /** @comportamiento Con un producto encontrado, enseña su línea de precios; con varios, abre el buscador. */
  test('la respuesta de la búsqueda enseña la línea de precios o el buscador', () => {
    const uno = paginaDePrueba();
    const varios = paginaDePrueba();

    uno.contexto.leerArticulo({ caja: 'idArticulo', valor: '5' });
    uno.responder({ NItems: 1, datos: [{ idArticulo: 5, articulo_name: 'Pera', pvpSiva: '1.818182', pvpCiva: '2.000000', iva: '10.00' }] });
    varios.contexto.leerArticulo({ caja: 'Descripcion', valor: 'Pe' });
    varios.responder({ NItems: 2, html: { html: '<table>dos</table>' } });

    expect([uno.valores['#inputIdArticulo'], uno.valores['#inputPrecioSin'], uno.valores['#inputPrecioCon'], uno.visibles['#formulario']]).toEqual(['5', '1.82', '2.00', true]);
    expect(varios.modales).toEqual([['Buscar producto en tarifa clientes ', '<table>dos</table>']]);
  });

  /** @comportamiento Elegir un producto del buscador lo cierra y enseña la línea de precios del producto. */
  test('elegir un producto del buscador enseña su línea', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.escribirProductoSeleccionado('Pera', '10.00', '1.818182', '2.00', 5);

    expect(pagina.cerradas).toBe(1);
    expect([pagina.valores['#inputDescripcion'], pagina.valores['#inputIVA'], pagina.valores['#inputPrecioSin']]).toEqual(['Pera', '10.00', '1.82']);
  });

  /** @comportamiento Cambiar el precio sin IVA calcula el precio con IVA, y al revés. */
  test('los dos precios se calculan uno desde otro', () => {
    const desdeSin = paginaDePrueba({ valores: { '#inputIVA': '10', '#inputPrecioSin': '2.00' } });
    const desdeCon = paginaDePrueba({ valores: { '#inputIVA': '21', '#inputPrecioCon': '12.10' } });

    desdeSin.contexto.recalcularPvp('inputPrecioSin');
    desdeCon.contexto.recalcularPvp('inputPrecioCon');

    expect(desdeSin.valores['#inputPrecioCon']).toBe('2.20');
    expect(desdeCon.valores['#inputPrecioSin']).toBe('10.00');
  });

  /** @comportamiento Grabar el producto envía sus dos precios con dos decimales y vuelve a la tarifa del cliente; cancelar vacía y esconde la línea. */
  test('grabar y cancelar la línea de precios', () => {
    const grabar = paginaDePrueba({ valores: { '#inputIdArticulo': '5', '#inputPrecioSin': '1.818', '#inputPrecioCon': '2', '#id_cliente': '7' } });
    const cancelar = paginaDePrueba({ valores: { '#inputIdArticulo': '5' } });

    grabar.contexto.grabarArticulo();
    grabar.ultima().success('[]');
    cancelar.contexto.cancelarAnhadir();

    expect(grabar.peticiones[0].data).toEqual({ pulsado: 'Grabar_tarifa_producto_cliente', idarticulo: '5', pvpSiva: '1.82', pvpCiva: '2.00', idcliente: '7' });
    expect(grabar.window.location.href).toBe('./tarifaCliente.php?id=7');
    expect([cancelar.valores['#inputIdArticulo'], cancelar.visibles['#formulario']]).toEqual(['', false]);
  });

  /**
   * @estado rojo
   * @codigo-afectado modulos/mod_cliente/funciones.js:218-237
   *
   * @que-ocurre-hoy Borrar un producto de la tarifa lo borra en la base, y la pantalla sigue
   *   enseñándolo con su precio, hasta que se recarga.
   * @que-deberia-ocurrir Que al responder, la tarifa en pantalla ya no lo enseñe.
   * @por-que-ocurre La respuesta no se usa: el propio código lo anota como pendiente.
   * @como-deberia-funcionar Recargar la tarifa, o quitar su fila, al responder.
   */
  test('borrar un producto de la tarifa lo quita de la pantalla', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.borrarArticulo(5);
    pagina.responder([]);

    expect(pagina.peticiones[0].data).toEqual({ pulsado: 'Borrar_producto_tarifa_cliente', idcliente: '7', idarticulo: 5 });
    expect(pagina.window.location.href).toBe('./tarifaCliente.php?id=7');
  });
});

describe('las impresiones y el informe', () => {
  /** @comportamiento Imprimir la ficha, la tarifa o un resumen abre el PDF que responde la petición; la tarifa con error, lo avisa. */
  test('las impresiones abren su PDF', () => {
    const ficha = paginaDePrueba();
    const tarifa = paginaDePrueba();
    const tarifaMal = paginaDePrueba();
    const resumen = paginaDePrueba();

    ficha.contexto.imprimirFicha(7);
    ficha.responder('/datos/tmp/FichaCliente.pdf');
    tarifa.contexto.imprimirTarifa(7);
    tarifa.responder('/datos/tmp/TarifaCliente.pdf');
    tarifaMal.contexto.imprimirTarifa(7);
    tarifaMal.responder({ error: 'Error no se ha enviado el id del cliente' });
    resumen.contexto.imprimirResumen('ticket', 7, '2026-09-01', '2026-09-30');
    resumen.responder('/datos/tmp/Resumen.pdf');

    expect(ficha.peticiones[0].data).toEqual({ pulsado: 'imprimirFichaCliente', idCliente: 7 });
    expect([...ficha.abiertas, ...tarifa.abiertas, ...resumen.abiertas]).toEqual(['/datos/tmp/FichaCliente.pdf', '/datos/tmp/TarifaCliente.pdf', '/datos/tmp/Resumen.pdf']);
    expect(tarifaMal.avisos).toEqual(['Error no se ha enviado el id del cliente']);
    expect(resumen.peticiones[0]).toMatchObject({ url: './../tareas.php', data: { pulsado: 'imprimirResumen', dedonde: 'ticket', idCliente: 7, fechaInicial: '2026-09-01', fechaFinal: '2026-09-30' } });
  });

  /**
   * @estado rojo
   * @codigo-afectado modulos/mod_cliente/funciones.js:427-432
   *
   * @que-ocurre-hoy Si el resumen responde un error —sin fechas, por ejemplo—, la ventana de error sale
   *   vacía, o con la sentencia de la base, y no dice el motivo.
   * @que-deberia-ocurrir Que la ventana diga el motivo del error.
   * @por-que-ocurre Se enseña el dato del error, no su mensaje.
   * @como-deberia-funcionar Enseñar el mensaje del error.
   */
  test('un resumen con error dice el motivo', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.imprimirResumen('ticket', 7, '', '');
    pagina.responder({ error: { tipo: 'DANGER!', dato: '', class: 'alert alert-danger', mensaje: 'Error no se han enviado corectamente las fechas' } });

    expect(pagina.modales).toEqual([['Error', 'Error no se han enviado corectamente las fechas']]);
  });

  /** @comportamiento El informe de descuentos abre su ventana, pide el cálculo del periodo y añade a la ventana lo que responde. */
  test('el informe de descuentos pide el cálculo y lo enseña', () => {
    const pagina = paginaDePrueba();
    pagina.pintado['.modal-body'] = 'Mes de septiembre';

    pagina.contexto.abrirModalInforme('Descuentos', 'Mes de septiembre', '2026-09-01', '2026-09-30 23:59:59', 1);
    pagina.responder(['<table>Descuentos insertados: 1</table>']);

    expect(pagina.modales).toEqual([['Descuentos', 'Mes de septiembre']]);
    expect(pagina.peticiones[0].data).toEqual({ pulsado: 'generarResumenTicketsClientes', fechainicio: '2026-09-01', fechafin: '2026-09-30 23:59:59', actualizar: 1 });
    expect(pagina.pintado['.modal-body']).toBe('Mes de septiembre<br/><br/><table>Descuentos insertados: 1</table>');
  });
});
