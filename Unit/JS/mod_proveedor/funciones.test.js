/**
 * El JavaScript de los proveedores (`modulos/mod_proveedor/funciones.js`): las opciones del listado, el
 * orden y los filtros del listado de productos, las impresiones, quitar la relación de un artículo y el
 * estado de los productos en la tienda web.
 *
 * Se ejecuta en un contexto propio de Node, con un jQuery de prueba que apunta lo que se le pide —las
 * peticiones a las tareas, los manejadores, las clases y las marcas— sin una página real. `alert`,
 * `confirm`, `window.open`, la dirección de la página y las funciones del núcleo que usa
 * (`VerIdSeleccionado`, `TfObtenerCheck`) son de pega.
 */

'use strict';

const path = require('path');
const { cargarScriptGlobal } = require('../../../support/js/cargarScriptGlobal');

const FUNCIONES = path.resolve(__dirname, '../../../../TPVFox/modulos/mod_proveedor/funciones.js');

/** Un elemento de pega de jQuery: apunta lo que se le hace. */
function elemento(selector, pagina) {
  const el = {
    selector,
    valor: pagina.valores[selector] ?? '0',
    marcado: pagina.marcados.includes(selector),
    off() { return el; },
    on(evento, manejador) { pagina.manejadores[selector] = manejador; return el; },
    is() { return el.marcado; },
    not() { return el; },
    prop(nombre, valor) { pagina.props.push([selector, nombre, valor]); return el; },
    val(valor) { if (valor === undefined) { return el.valor; } pagina.valores[selector] = valor; return el; },
    data(nombre) { return pagina.datos[nombre]; },
    removeClass(clase) { pagina.clases.push([selector, '-' + clase]); return el; },
    addClass(clase) { pagina.clases.push([selector, '+' + clase]); return el; },
    fadeOut(tiempo, despues) { pagina.desvanecidas.push(selector); if (despues) { despues.call({ selector }); } return el; },
    remove() { pagina.quitadas.push(selector); return el; },
  };
  return el;
}

function paginaDePrueba({ seleccionados = [], confirma = true, direccion = 'http://tpv/modulos/mod_proveedor/OtrasVistas/ListadoProductosDeProveedor.php?id=5' } = {}) {
  const pagina = { peticiones: [], avisos: [], abiertas: [], manejadores: {}, props: [], clases: [], desvanecidas: [], quitadas: [], valores: {}, marcados: [], datos: {}, filas: {} };
  const $ = (selector) => (typeof selector === 'string' ? elemento(selector, pagina) : elemento(selector.selector || 'this', pagina));
  $.ajax = (opciones) => { pagina.peticiones.push(opciones); };
  $.parseJSON = JSON.parse;
  pagina.window = { location: { href: direccion }, open: (url) => { pagina.abiertas.push(url); } };
  pagina.document = { getElementsByClassName: (clase) => pagina.filas[clase] || [] };
  pagina.contexto = cargarScriptGlobal(FUNCIONES, {
    $,
    alert: (texto) => { pagina.avisos.push(String(texto)); },
    confirm: () => confirma,
    console: { log() {} },
    window: pagina.window,
    document: pagina.document,
    URL,
    checkID: [],
    TfObtenerCheck: () => ['7', '8'],
  });
  pagina.contexto.VerIdSeleccionado = () => { pagina.contexto.checkID = seleccionados.slice(); };
  pagina.ultima = () => pagina.peticiones[pagina.peticiones.length - 1];
  pagina.responder = (respuesta) => pagina.ultima().success(JSON.stringify(respuesta));

  return pagina;
}

/** Una fila de pega para los filtros: apunta su estilo. */
function fila() {
  return { style: {}, removeAttribute(nombre) { delete this.style.display; this.quitado = nombre; } };
}

describe('las opciones del listado de proveedores', () => {
  /** @comportamiento «Ver» abre la ficha del proveedor marcado; sin uno solo marcado, avisa. */
  test('ver abre la ficha del proveedor marcado', () => {
    const uno = paginaDePrueba({ seleccionados: ['12'] });
    const ninguno = paginaDePrueba();

    uno.contexto.metodoClick('VerProveedor');
    ninguno.contexto.metodoClick('VerProveedor');

    expect(uno.window.location.href).toBe('./proveedor.php?id=12&accion=ver');
    expect(ninguno.avisos).toHaveLength(1);
  });

  /** @comportamiento «Añadir» abre la ficha vacía; los productos y el resumen, los del proveedor marcado. */
  test('añadir, sus productos y su resumen de albaranes', () => {
    const anadir = paginaDePrueba();
    const productos = paginaDePrueba({ seleccionados: ['12'] });
    const resumen = paginaDePrueba({ seleccionados: ['12'] });

    anadir.contexto.metodoClick('AgregarProveedor');
    productos.contexto.metodoClick('ListadoProductos');
    resumen.contexto.metodoClick('ResumenAlbaranes');

    expect(anadir.window.location.href).toBe('./proveedor.php');
    expect(productos.window.location.href).toBe('./OtrasVistas/ListadoProductosDeProveedor.php?id=12');
    expect(resumen.window.location.href).toBe('./OtrasVistas/resumenAlbaranes.php?id=12');
  });

  /** @comportamiento El resumen abierto desde la ficha lleva el tipo de documento. */
  test('el resumen desde la ficha lleva el tipo de documento', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.abrirResumen(12, 'albaranes');

    expect(pagina.window.location.href).toBe('./OtrasVistas/resumenAlbaranes.php?id=12&tipoDoc=albaranes');
  });
});

describe('el listado de productos de un proveedor', () => {
  /** @comportamiento Pulsar una columna ordena por ella en sentido ascendente; pulsarla otra vez, descendente. */
  test('pulsar una columna ordena por ella y alterna el sentido', () => {
    const primera = paginaDePrueba();
    primera.contexto.catchEvents();
    primera.datos.campo = 'crefProveedor';
    primera.manejadores['.ordenar'].call({}, { stopPropagation() {}, preventDefault() {} });

    const segunda = paginaDePrueba({ direccion: primera.window.location.href });
    segunda.contexto.catchEvents();
    segunda.datos.campo = 'crefProveedor';
    segunda.manejadores['.ordenar'].call({}, { stopPropagation() {}, preventDefault() {} });

    expect(new URL(primera.window.location.href).searchParams.get('campoorden')).toBe('crefProveedor');
    expect(new URL(primera.window.location.href).searchParams.get('sentidoorden')).toBe('ASC');
    expect(new URL(segunda.window.location.href).searchParams.get('sentidoorden')).toBe('DESC');
  });

  /** @comportamiento Desmarcar un estado esconde sus filas, y volver a marcarlo las enseña. */
  test('el filtro por estado esconde y enseña sus filas', () => {
    const pagina = paginaDePrueba();
    const filas = [fila(), fila()];
    pagina.filas.Row_2 = filas;
    const caja = { selector: 'caja', value: '1' };
    pagina.valores.caja = '1';

    pagina.contexto.filtroEstado(caja, 2);
    const escondidas = filas.map((f) => f.style.display);
    caja.value = '0';
    pagina.contexto.filtroEstado(caja, 2);

    expect(escondidas).toEqual(['none', 'none']);
    expect(filas.map((f) => f.style.display)).toEqual([undefined, undefined]);
  });

  /** @comportamiento «Seleccionar todos» marca todas las casillas de la tabla, y la segunda vez las desmarca. */
  test('seleccionar todos marca y desmarca', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.SeleccionarTodos();
    pagina.contexto.SeleccionarTodos();

    expect(pagina.props).toEqual([['.table tbody input', 'checked', true], ['.table tbody input', 'checked', false]]);
  });

  /** @comportamiento Imprimir la selección envía a las tareas los artículos marcados y abre el PDF; sin ninguno, avisa. */
  test('imprimir la selección envía los marcados y abre el PDF', () => {
    const conMarcados = paginaDePrueba();
    conMarcados.marcados.push('.table tbody input');
    const sinMarcados = paginaDePrueba();

    conMarcados.contexto.imprimirSeleccion(5);
    conMarcados.responder('/tmp/Listado.pdf');
    sinMarcados.contexto.imprimirSeleccion(5);

    expect(conMarcados.ultima().data).toEqual({ pulsado: 'imprimirListadoProductos', productos: ['7', '8'], idProveedor: 5 });
    expect(conMarcados.abiertas).toEqual(['/tmp/Listado.pdf']);
    expect(sinMarcados.avisos).toEqual(['No has seleccionado ningún articulo']);
  });

  /**
   * @comportamiento Quitar la relación de un artículo pide confirmación y la pide a las tareas; con la
   *   respuesta correcta, la fila desaparece; con un error, o si la petición falla, se avisa.
   */
  test('quitar la relación de un artículo', () => {
    const pagina = paginaDePrueba();
    const sinConfirmar = paginaDePrueba({ confirma: false });

    sinConfirmar.contexto.eliminarRefProveedorArticulo(7, 5, 'Pimiento');
    pagina.contexto.eliminarRefProveedorArticulo(7, 5, 'Pimiento');
    pagina.responder({ ok: true });
    pagina.contexto.eliminarRefProveedorArticulo(7, 5, 'Pimiento');
    pagina.responder({ error: 'No tienes permisos para realizar esta acción' });
    pagina.ultima().error();

    expect(sinConfirmar.peticiones).toHaveLength(0);
    expect(pagina.ultima().data).toEqual({ pulsado: 'eliminarRefProveedorArticulo', idArticulo: 7, idProveedor: 5 });
    expect(pagina.desvanecidas).toEqual(['#fila_articulo_7']);
    expect(pagina.quitadas).toEqual(['#fila_articulo_7']);
    expect(pagina.avisos).toEqual(['Error al eliminar: No tienes permisos para realizar esta acción', 'Error de conexión al intentar eliminar la referencia.']);
  });

  /** @comportamiento El estado web de cada producto se pide a las tareas; el publicado pierde la marca, y el que falla la cambia. */
  test('el estado de los productos en la tienda web', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.obtenerEstadoProductoWeb([7, 8], 1);
    pagina.responder([{ estado: '1', idArticulo: 7 }, { estado: 'Error', idArticulo: 8 }]);

    expect(pagina.ultima().data).toEqual({ pulsado: 'obtenerEstadoProductoWeb', ids_productos: [7, 8], id_tiendaWeb: 1 });
    expect(pagina.clases).toEqual([
      ['#idProducto_estadoWeb_7', '-icono_web despublicado'],
      ['#idProducto_estadoWeb_8', '-icono_web despublicado'],
      ['#idProducto_estadoWeb_8', '+icono_web error_estadoWeb'],
    ]);
  });
});

describe('el resumen de albaranes', () => {
  /** @comportamiento Imprimir el resumen envía a las tareas el proveedor y las fechas, y abre el PDF que responden. */
  test('imprimir el resumen envía el proveedor y las fechas', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.imprimirResumen('albaran', '5', '2031-05-01', '2031-05-31');
    pagina.responder('/tmp/Resumen.pdf');

    expect(pagina.ultima().url).toBe('./../tareas.php');
    expect(pagina.ultima().data).toEqual({ pulsado: 'imprimirResumenAlbaran', idProveedor: '5', fechaInicial: '2031-05-01', fechaFinal: '2031-05-31' });
    expect(pagina.abiertas).toEqual(['/tmp/Resumen.pdf']);
  });
});
