/**
 * El JavaScript de la tienda web: el del módulo (`modulos/mod_virtuemart/funciones.js`) —la importación,
 * las imágenes y los campos de peso— y los de los complementos de productos y de familias
 * (`plugins/mod_producto/virtuemart/func_plg_virtuemart.js`, `plugins/mod_familia/virtuemart/func_plg_virtuemart.js`).
 *
 * Cada fichero se ejecuta en un contexto propio de Node, con un jQuery de prueba que apunta lo que se le
 * pide —las peticiones, los valores de las cajas, el HTML pintado— sin una página real. `alert`,
 * `confirm`, la ventana modal, la barra de proceso y las funciones que cada fichero toma del otro o del
 * núcleo son de pega.
 */

'use strict';

const path = require('path');
const { cargarScriptGlobal } = require('../../../support/js/cargarScriptGlobal');

const MODULO = path.resolve(__dirname, '../../../../TPVFox/modulos/mod_virtuemart/funciones.js');
const PRODUCTOS = path.resolve(__dirname, '../../../../TPVFox/plugins/mod_producto/virtuemart/func_plg_virtuemart.js');
const FAMILIAS = path.resolve(__dirname, '../../../../TPVFox/plugins/mod_familia/virtuemart/func_plg_virtuemart.js');

/** Un elemento de pega de jQuery: apunta lo que se le hace. */
function elemento(selector, pagina) {
  const el = {
    length: pagina.longitudes[selector] ?? 0,
    val(valor) { if (valor === undefined) { return pagina.valores[selector] ?? ''; } pagina.valores[selector] = String(valor); return el; },
    html(contenido) { if (contenido === undefined) { return pagina.pintado[selector] ?? ''; } pagina.pintado[selector] = contenido; return el; },
    append(contenido) { pagina.pintado[selector] = (pagina.pintado[selector] ?? '') + contenido; return el; },
    remove() { pagina.quitados.push(selector); return el; },
    show() { pagina.visibles[selector] = true; return el; },
    hide() { pagina.visibles[selector] = false; return el; },
    prop(nombre, valor) { pagina.propiedades[selector + ':' + nombre] = valor; return el; },
    attr(nombre, valor) { pagina.propiedades[selector + ':' + nombre] = valor; return el; },
  };
  return el;
}

function paginaDePrueba(fichero, { valores = {}, longitudes = {}, confirmar = true, globales = {} } = {}) {
  const pagina = { peticiones: [], avisos: [], modales: [], llamadas: [], quitados: [], valores: { ...valores }, longitudes, pintado: {}, visibles: {}, propiedades: {} };
  const $ = (selector) => elemento(selector, pagina);
  $.ajax = (opciones) => { pagina.peticiones.push(opciones); };
  $.parseJSON = JSON.parse;
  const apunta = (nombre) => (...args) => { pagina.llamadas.push([nombre, ...args]); };
  pagina.contexto = cargarScriptGlobal(fichero, {
    $,
    jQuery: $,
    alert: (texto) => { pagina.avisos.push(String(texto)); },
    confirm: () => confirmar,
    console: { log() {} },
    abrirModal: (titulo, html) => { pagina.modales.push([titulo, html]); },
    cerrarPopUp: apunta('cerrarPopUp'),
    BarraProceso: apunta('BarraProceso'),
    destacarCambioCaja: apunta('destacarCambioCaja'),
    comprobarNumero: () => true,
    RegistrarRestarStockTicket: apunta('RegistrarRestarStockTicket'),
    ruta_plg_virtuemart: '/plugins/mod_producto/virtuemart/',
    regWeb: 0,
    ...globales,
  });
  pagina.ultima = () => pagina.peticiones[pagina.peticiones.length - 1];
  pagina.responder = (respuesta, peticion = pagina.ultima()) => peticion.success(JSON.stringify(respuesta));

  return pagina;
}

describe('la importación de la tienda web', () => {
  /** @comportamiento Sin tienda web elegida, avisa; con ella, cuenta los productos de la web. */
  test('empezar exige una tienda web', () => {
    const sin = paginaDePrueba(MODULO, { valores: { '#tiendaWeb': '0' }, globales: { contarProductosWeb: () => {} } });
    const con = paginaDePrueba(MODULO, { valores: { '#tiendaWeb': '9' } });
    con.contexto.contarProductosWeb = (callback) => { con.llamadas.push(['contarProductosWeb', callback]); };

    sin.contexto.enviarFormulario();
    con.contexto.enviarFormulario();

    expect(sin.avisos).toEqual(['NO HAS SELECCIONADO UNA TIENDA WEB!']);
    expect(con.llamadas[0][1]).toBe(con.contexto.callbackContarRegistrosWeb);
  });

  /**
   * @comportamiento Con los productos de la web contados, cuenta los del TPV, pinta los dos totales y
   *   cuenta los nuevos en el TPV y los que tienen relación; después ofrece actualizar.
   */
  test('los recuentos de la web y del TPV', () => {
    const pagina = paginaDePrueba(MODULO, { valores: { '#tiendaWeb': '9' } });

    pagina.contexto.callbackContarRegistrosWeb(JSON.stringify({ Datos: { item: { productosWeb: 120 } } }));
    pagina.responder({ productosTpv: [{ cantTpv: '150' }] });
    const [nuevosEnTpv, nuevosEnWeb] = pagina.peticiones.slice(1);
    pagina.responder({ productos: [{ cantArticulo: '40' }] }, nuevosEnTpv);
    pagina.responder({ productos: [{ cantArticulo: '110' }] }, nuevosEnWeb);

    expect(pagina.peticiones.map((p) => p.data.pulsado)).toEqual(['contarProductostpv', 'nuevosEnTpv', 'nuevosEnWeb']);
    expect([pagina.pintado['#totalWeb'], pagina.pintado['#totalTpv'], pagina.pintado['#NuevosTpv']]).toEqual([120, '150', '40']);
    expect(pagina.visibles['#DivOpciones']).toBe(true);
  });

  /**
   * @comportamiento Comparar un tramo de productos manda las opciones elegidas; con la respuesta, añade las
   *   filas, suma los nuevos y los modificados y pide el tramo siguiente; en el último, no.
   */
  test('comparar un tramo de productos', () => {
    const pagina = paginaDePrueba(MODULO, { valores: { '#tiendaWeb': '9', '#codBarras option:selected': '2', '#refTienda option:selected': '3' } });
    pagina.contexto.actualizarProductosWeb = (final) => { pagina.llamadas.push(['actualizarProductosWeb', final]); };

    pagina.contexto.comprobarProductos([{ id: 31 }], 100);
    const enviada = pagina.ultima().data;
    pagina.responder({ htmlNuevos: '<tr id="nuevo_0"></tr>', htmlMod: '<tr id="mod_0"></tr>', totalNuevos: 1, totalModificados: 1 });
    pagina.contexto.comprobarProductos([{ id: 32 }], 120, 1);
    pagina.responder({ htmlNuevos: '', htmlMod: '', totalNuevos: 2, totalModificados: 0 });

    expect(enviada).toMatchObject({ pulsado: 'comprobarProductos', idTienda: '9', sel_codigoBarras: '2', sel_referencia: '3', prodModif: 0, prodNuevos: 0 });
    expect([pagina.pintado['#NuevosWeb'], pagina.pintado['#modifTpv']]).toEqual([3, 1]);
    expect(pagina.pintado['#productosNuevos']).toBe('<tr id="nuevo_0"></tr>');
    expect(pagina.llamadas).toEqual([['actualizarProductosWeb', 100]]);
  });

  /** @comportamiento Insertar o modificar un producto en el TPV manda sus datos con las opciones; bien, quita su fila; con error, lo avisa. */
  test('insertar y modificar un producto en el TPV', () => {
    const pagina = paginaDePrueba(MODULO, { valores: { '#tiendaWeb': '9', '#codBarras': '1', '#refTienda': '2', '#estadoNuevo': 'Nuevo', '#estadoMod': 'Activo', '#beneficio': '25', '#ultimoCoste': '1' } });

    pagina.contexto.addProductoWeb('Kiwi', 'KIW-1', '21.00', '2.00', '8400000000031', 33, 4);
    const alta = pagina.ultima().data;
    pagina.responder({ add: {} });
    pagina.contexto.modificarProductosTpvWeb('Pera', 'PER-1', '21.00', '3.00', '', '5', 2);
    const cambio = pagina.ultima().data;
    pagina.responder({ modificar: { error: 'UPDATE' } });

    expect(alta).toMatchObject({ pulsado: 'addProductoTpv', nombre: 'Kiwi', id: 33, optCodBarra: '1', optRefWeb: '2', optEstado: 'Nuevo', beneficio: '25', optCoste: '1', tiendaWeb: '9' });
    expect(cambio).toMatchObject({ pulsado: 'modificarProducto', id: '5', optEstado: 'Activo', optRef: '2' });
    expect(pagina.quitados).toEqual(['#nuevo_4']);
    expect(pagina.avisos).toEqual(['UPDATE']);
  });

  /**
   * @comportamiento Buscar imágenes recorre las relaciones de cien en cien, cuenta las encontradas y pide
   *   el tramo siguiente; sin relaciones, avisa.
   */
  test('buscar las imágenes de los productos', () => {
    const sin = paginaDePrueba(MODULO, { globales: { totalReferenciasWeb: 0, reg_inicial: 0, img_encontradas: 0 } });
    const con = paginaDePrueba(MODULO, { globales: { totalReferenciasWeb: 150, reg_inicial: 0, img_encontradas: 0 } });

    sin.contexto.BuscarImagenes_producto();
    con.contexto.BuscarImagenes_producto();
    const primera = con.ultima().data;
    con.responder({ imagenes: { Datos: Array.from({ length: 100 }, (_, i) => (i < 2 ? { imagenes_insert: 1 } : {})) } });

    expect(sin.avisos).toEqual(['Algo salio mal o no hay productos relacionas en la web']);
    expect(primera).toEqual({ pulsado: 'obtenerProductosRelacionados', reg_inicial: 0, reg_final: 100 });
    expect([con.pintado['#reg_actual'], con.pintado['#img_encontradas']]).toEqual([100, 2]);
    expect(con.ultima().data).toEqual({ pulsado: 'obtenerProductosRelacionados', reg_inicial: 100, reg_final: 150 });
  });

  /** @comportamiento Los campos de peso recorren los productos: con id en la web, se añaden; sin él, se cuentan aparte. */
  test('añadir los campos de peso', () => {
    const pagina = paginaDePrueba(MODULO, { globales: { totaProductoPeso: 2, reg_inicial: 0, SinIDVirtuemart: 0, Ids: ['5', '6'] } });

    pagina.contexto.AnhadirCamposPersonalidosIdPeso();
    const primera = pagina.ultima().data;
    pagina.responder({ idVirtuemart: '31' });
    const campos = pagina.ultima().data;
    pagina.responder({});
    const segunda = pagina.ultima().data;
    pagina.responder({ idVirtuemart: 0 });

    expect(primera).toEqual({ pulsado: 'obtenerIdVirtuemart', idProductoTpv: '5' });
    expect(campos).toEqual({ pulsado: 'anhadirCamposIdVirtuemart', idVirtuemart: '31' });
    expect(segunda).toEqual({ pulsado: 'obtenerIdVirtuemart', idProductoTpv: '6' });
    expect(pagina.pintado['#SinIdVirtuemar']).toBe(1);
    expect(pagina.avisos).toEqual(['Termino']);
  });
});

describe('el complemento de productos', () => {
  /** @comportamiento Modificar el producto en la web pide confirmación y exige nombre, precio e IVA; manda los datos con el stock disponible y, al añadirlo, pone su id. */
  test('modificar o añadir el producto en la web', () => {
    const valores = { '#ivasWeb': '4', '#nombreWeb': 'Pera', '#precioSivaWeb': '2.00', '#stockon': '10', '#stockmin': '2', '#estadosWeb': '1', '#referenciaWeb': 'PER-1', '#codBarrasWeb': '', '#alias': 'pera' };
    const cancelado = paginaDePrueba(PRODUCTOS, { valores, confirmar: false });
    const sinNombre = paginaDePrueba(PRODUCTOS, { valores: { ...valores, '#nombreWeb': '' } });
    const bien = paginaDePrueba(PRODUCTOS, { valores });
    bien.pintado['#idWeb'] = '0';

    cancelado.contexto.modificarProductoWeb(5, 9);
    sinNombre.contexto.modificarProductoWeb(5, 9);
    bien.contexto.modificarProductoWeb(5, 9);
    const datos = JSON.parse(bien.ultima().data.datos);
    bien.responder({ htmlAlerta: '<div>bien</div>', resul: { Datos: { idArticulo: 77 } } });

    expect([cancelado.peticiones.length, sinNombre.avisos]).toEqual([0, ['Campos necesarios vacios, Referencia, Nombre y Precio sin iva']]);
    expect(datos).toEqual({ estado: '1', referencia: 'PER-1', nombre: 'Pera', codBarras: '', precioSiva: '2.00', iva: '4', id: '0', alias: 'pera', stock: 8, idProducto: 5, idTiendaWeb: 9 });
    expect(bien.ultima().url).toBe('/plugins/mod_producto/virtuemart/tareas_virtuemart.php');
    expect([bien.pintado['#alertasWeb'], bien.pintado['#idWeb'], bien.valores['#botonWeb']]).toEqual(['<div>bien</div>', 77, 'Modificar en Web']);
  });

  /** @comportamiento La ventana de responder a un cliente se pide con los datos de su fila; enviar el correo, bien, quita la fila; mal, lo avisa. */
  test('responder a un cliente', () => {
    const pagina = paginaDePrueba(PRODUCTOS, { valores: { '#nombreWeb': 'Pera', '#idNotificacion_1': '7', '#email': 'ana@example.com', '#asunto': 'Pera', '#mensaje': 'Ya hay', '#idNotificacion': '7', '#numLinea': '1' } });
    Object.assign(pagina.pintado, { '#idWeb': '31', '#mail_1': 'ana@example.com', '#nombre_1': 'Ana', '#idProducto': '31' });

    pagina.contexto.ModalNotificacion(1);
    const ventana = pagina.ultima().data;
    pagina.responder({ html: '<div>correo</div>' });
    pagina.contexto.enviarCorreoNotificacion();
    const correo = pagina.ultima().data;
    pagina.responder({ mail: 2, numLinea: '1' });
    pagina.contexto.enviarCorreoNotificacion();
    pagina.responder({ mail: 1 });

    expect(ventana).toEqual({ pulsado: 'mostrarModalNotificacion', datos: { nombreProducto: 'Pera', id: '31', correo: 'ana@example.com', nombreUsuario: 'Ana', idNotificacion: '7', numLinea: 1 } });
    expect(pagina.modales).toEqual([['Enviar correo de Notificacion', '<div>correo</div>']]);
    expect(correo.datos).toEqual({ email: 'ana@example.com', asunto: 'Pera', mensaje: 'Ya hay', idProducto: '31', idNotificacion: '7', numLinea: '1' });
    expect(pagina.quitados).toEqual(['#Linea_1']);
    expect(pagina.avisos).toEqual(['Error no ha enviado la notificación por correo']);
  });

  /** @comportamiento Los precios de la web se calculan uno desde otro con el IVA elegido, y al cambiar el IVA. */
  test('los precios con y sin IVA de la web', () => {
    const sinIva = paginaDePrueba(PRODUCTOS, { valores: { '#precioSivaWeb': '2.00' } });
    sinIva.pintado['#ivasWeb option:selected'] = '21.00%';
    const conIva = paginaDePrueba(PRODUCTOS, { valores: { '#precioCivaWeb': '2.42' } });
    conIva.pintado['#ivasWeb option:selected'] = '21.00%';

    sinIva.contexto.recalcularPvpWeb('precioSivaWeb');
    conIva.contexto.recalcularPvpWeb('precioCivaWeb');
    sinIva.contexto.modificarIvaWeb();

    expect(sinIva.valores['#precioCivaWeb']).toBe('2.42');
    expect(conIva.valores['#precioSivaWeb']).toBe('2.00');
  });

  /** @comportamiento Tomar los datos del producto del TPV copia su referencia, su nombre, sus precios y sus códigos de barras separados por punto y coma. */
  test('tomar los datos del producto del TPV', () => {
    const pagina = paginaDePrueba(PRODUCTOS, { valores: { '#referencia': 'PER-1', '#nombre': 'Pera', '#pvpSiva': '2.00', '#pvpCiva': '2.42', '#codBarras_0': '840001', '#codBarras_1': '840002' }, longitudes: { '[id*=codBarras_]:input': 2 } });

    pagina.contexto.ObtenerDatosProducto();

    expect([pagina.valores['#referenciaWeb'], pagina.valores['#nombreWeb'], pagina.valores['#codBarrasWeb']]).toEqual(['PER-1', 'Pera', '840001;840002']);
  });

  /** @comportamiento Subir los seleccionados avisa de los que ya estaban, de los errores y de cuántos se subieron. */
  test('subir los productos seleccionados', () => {
    const pagina = paginaDePrueba(PRODUCTOS);

    pagina.contexto.subirProductosWeb(9);
    pagina.responder({ productoEnWeb: [{ id: '6' }], errores: [{ tipo: 'danger' }], contadorProductos: 2 });

    expect(pagina.peticiones[0].data).toEqual({ pulsado: 'subirProductosWeb', idTiendaWeb: 9 });
    expect(pagina.avisos).toHaveLength(3);
    expect(pagina.avisos[2]).toBe('Se han subido a la web :2 Productos');
  });

  /**
   * @comportamiento Leer un tramo de la web convierte cada producto a lo que compara la importación y
   *   compara el tramo, pidiendo el siguiente mientras haya.
   */
  test('leer un tramo de productos de la web', () => {
    const pagina = paginaDePrueba(PRODUCTOS, { globales: { regWeb: 120 } });
    pagina.contexto.comprobarProductos = (productos, final, bandera) => { pagina.llamadas.push(['comprobarProductos', productos, final, bandera]); };

    pagina.contexto.actualizarProductosWeb(0);
    pagina.responder({ productos: { Datos: { item: [{ refTienda: 'PER-1', codBarra: '', idIva: '4', iva: '21', articulo_name: 'Pera', precioSiva: '2', idVirtual: 31 }] } } });

    expect(pagina.llamadas.find((l) => l[0] === 'comprobarProductos')).toEqual(['comprobarProductos', [{ refTienda: 'PER-1', codBarra: '', idIva: '4', iva: '21', nombre: 'Pera', precioSiva: '2', id: 31 }], 100, undefined]);
  });

  /**
   * @estado rojo
   * @codigo-afectado plugins/mod_producto/virtuemart/func_plg_virtuemart.js:273-289; plugins/mod_reorganizacion/virtuemart/func_plg_virtuemart.js:273-289
   *
   * @group defecto
   * @group integridad
   * @group medio
   *
   * @que-ocurre-hoy Leer un tramo de productos de la web manda siempre 100 como inicio, sea cual sea el
   *   tramo, y como final el inicio más cien: lo que pide a la web no es el tramo que recorre la
   *   importación.
   * @que-deberia-ocurrir Que cada tramo se pida con su inicio.
   * @por-que-ocurre La petición manda como inicio la cantidad del tramo en lugar del inicio que recibe.
   * @como-deberia-funcionar Mandar el inicio del tramo.
   */
  test('cada tramo se pide desde su inicio', () => {
    const primero = paginaDePrueba(PRODUCTOS);
    const tercero = paginaDePrueba(PRODUCTOS);

    primero.contexto.actualizarProductosWeb(0);
    tercero.contexto.actualizarProductosWeb(200);

    expect([primero.ultima().data.inicio, tercero.ultima().data.inicio]).toEqual([0, 200]);
  });

  /** @comportamiento Restar el stock de un ticket en la web bloquea el botón, lo pide y registra si fue bien o mal; sin productos, avisa. */
  test('restar el stock de un ticket en la web', () => {
    const vacio = paginaDePrueba(PRODUCTOS);
    const bien = paginaDePrueba(PRODUCTOS);
    const mal = paginaDePrueba(PRODUCTOS);

    vacio.contexto.enviarStockWeb({}, [], 7);
    bien.contexto.enviarStockWeb({}, [{ idVirtuemart: '31' }], 7);
    bien.responder({ Datos: { error: '' } });
    mal.contexto.enviarStockWeb({}, [{ idVirtuemart: '31' }], 7);
    mal.responder({ Datos: { error: 'Sin stock' } });

    expect(vacio.avisos).toEqual([' Error intenta envia stock pero no tiene producto ']);
    expect(bien.peticiones[0].data).toEqual({ pulsado: 'RestarStock', productos: [{ idVirtuemart: '31' }] });
    expect(bien.llamadas).toEqual([['RegistrarRestarStockTicket', 7, 'Correcto', { error: '' }]]);
    expect(mal.llamadas[0][2]).toBe('Error');
    expect(bien.propiedades['#DescontarStock:disabled']).toBe(true);
  });
});

describe('el complemento de familias', () => {
  /** @comportamiento Modificar la familia en la web pide confirmación y nombre; manda sus datos y, al añadirla, pone su id en la web. */
  test('modificar o añadir la familia en la web', () => {
    const sinNombre = paginaDePrueba(FAMILIAS, { valores: { '#nombreFamilia': '' } });
    const bien = paginaDePrueba(FAMILIAS, { valores: { '#nombreFamilia': 'Frutas', '#inputidpadreWeb': '0', '#idFamiliaweb': '' } });

    sinNombre.contexto.modificarFamiliaWeb(5, 9);
    bien.contexto.modificarFamiliaWeb(5, 9);
    const datos = JSON.parse(bien.ultima().data.datos);
    bien.responder({ htmlAlerta: '<div>bien</div>', addFamilia: { Datos: { idFamilia: 12 } } });

    expect(sinNombre.avisos).toEqual(['NO has introducido ningún nombre de familia']);
    expect(datos).toEqual({ idFamiliaPadreWeb: '0', idFamiliaTpv: 5, idFamiliaWeb: '', idTienda: 9, nombreFamilia: 'Frutas' });
    expect([bien.valores['#idFamiliaweb'], bien.valores['#botonWeb']]).toEqual(['12', 'Modificar en Web']);
  });

  /** @comportamiento Subir las hijas avisa de las subidas y de las que no; tomar los datos copia el nombre de la familia. */
  test('subir las hijas y tomar los datos', () => {
    const pagina = paginaDePrueba(FAMILIAS, { valores: { '#inputnombre': 'Frutas' } });

    pagina.contexto.subirHijosWeb(5, 9);
    pagina.responder({ familiasSubidas: ['Hueso'], familiasNoSubidas: ['Pepita'] });
    pagina.contexto.ObtenerDatosFamilia();

    expect(pagina.peticiones[0].data).toEqual({ pulsado: 'subirHijosWeb', idPadre: 5, idTienda: 9 });
    expect(pagina.avisos).toEqual(['Familias subidas : ["Hueso"]', 'Familias NO subidas : ["Pepita"]']);
    expect(pagina.valores['#nombreFamilia']).toBe('Frutas');
  });
});
