/**
 * El JavaScript de los productos (`modulos/mod_producto/funciones.js` y `js/AccionesDirectas.js`), que
 * cargan las pantallas de los dos módulos de productos: precios de la ficha, códigos de barras,
 * proveedores, configuración del listado, selección, familias, estados, regularización, quitar líneas
 * de la ficha, borrar productos, recálculo, impresión, mayor y el control de las cajas.
 *
 * Los dos ficheros se ejecutan en un mismo contexto propio de Node —el segundo usa funciones del
 * primero—, con un jQuery y un documento de prueba que guardan el estado de cada elemento y apuntan
 * las peticiones a las tareas sin enviarlas: el caso responde por la petición. Su `ready` ejecuta la
 * función en el acto, como hace el jQuery que sirve el producto con la página cargada.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const { cargarScriptGlobal } = require('../../../support/js/cargarScriptGlobal');

const FUNCIONES = path.resolve(__dirname, '../../../../TPVFox/modulos/mod_producto/funciones.js');
const ACCIONES = path.resolve(__dirname, '../../../../TPVFox/modulos/mod_producto/js/AccionesDirectas.js');

/**
 * Una página de prueba. `elementos` da el estado de partida de los que el caso necesita, por su `#id`;
 * `consultas`, los ids que devuelve un selector que no es un `#id` ni una clase; `nodos`, los del
 * documento que se piden por `getElementById`.
 */
function paginaDePrueba({ elementos = {}, consultas = {}, nodos = {}, globales = {} } = {}) {
  const pagina = { peticiones: [], desplegables: {}, elementos: {}, manejadores: {}, temporizadores: [], nodos: {} };
  const elemento = (id) => {
    if (!pagina.elementos[id]) {
      pagina.elementos[id] = { id, valor: '', clases: new Set(), visible: true, html: '', datos: {}, marcado: false, css: {}, attrs: {}, antepuesto: [] };
    }
    return pagina.elementos[id];
  };
  Object.entries(elementos).forEach(([id, estado]) => {
    Object.assign(elemento(id), estado, { clases: new Set(estado.clases || []) });
  });
  const envoltorio = (lista) => {
    const objeto = {
      length: lista.length,
      val: (valor) => (valor === undefined ? lista[0] && lista[0].valor : lista.forEach((e) => { e.valor = valor; })),
      prop: (nombre, valor) => (valor === undefined ? lista[0] && lista[0].marcado : lista.forEach((e) => { e.marcado = valor; })),
      hasClass: (clase) => lista.some((e) => e.clases.has(clase)),
      addClass: (clase) => { lista.forEach((e) => clase.split(' ').forEach((c) => e.clases.add(c))); return objeto; },
      removeClass: (clase) => { lista.forEach((e) => clase.split(' ').forEach((c) => e.clases.delete(c))); return objeto; },
      show: () => lista.forEach((e) => { e.visible = true; }),
      hide: () => lista.forEach((e) => { e.visible = false; }),
      toggle: () => lista.forEach((e) => { e.visible = !e.visible; }),
      html: (contenido) => (contenido === undefined ? lista[0] && lista[0].html : lista.forEach((e) => { e.html = contenido; })),
      css: (clave, valor) => lista.forEach((e) => { e.css[clave] = valor; }),
      animate: () => objeto,
      attr: (clave, valor) => (valor === undefined ? lista[0] && (lista[0].attrs[clave] ?? lista[0].id) : lista.forEach((e) => { e.attrs[clave] = valor; })),
      removeAttr: (clave) => lista.forEach((e) => { delete e.attrs[clave]; }),
      data: (clave) => (clave === undefined ? lista[0] && lista[0].datos : lista[0] && lista[0].datos[clave]),
      on: (evento, funcion) => lista.forEach((e) => { pagina.manejadores[`${e.id}:${evento}`] = funcion; }),
      keyup: (funcion) => lista.forEach((e) => { pagina.manejadores[`${e.id}:keyup`] = funcion; }),
      each: (funcion) => lista.forEach((e, indice) => funcion.call(e, indice, e)),
      combobox: (opciones) => lista.forEach((e) => { pagina.desplegables[e.id] = opciones; }),
      prepend: (contenido) => lista.forEach((e) => e.antepuesto.push(contenido)),
      find: (selector) => $(`${lista[0] && '#' + lista[0].id} ${selector}`),
      focus: () => objeto,
      select: () => objeto,
      ready: (funcion) => funcion(),
    };
    lista.forEach((e, indice) => { objeto[indice] = e; });
    return objeto;
  };
  const documento = {
    getElementById: (id) => pagina.nodos[id] || null,
    body: { contains: (nodo) => Boolean(nodo) },
  };
  Object.entries(nodos).forEach(([id, estado]) => {
    pagina.nodos[id] = {
      id,
      value: '',
      style: {},
      atributos: {},
      innerHTML: '',
      setAttribute(nombre, valor) { this.atributos[nombre] = valor; },
      removeAttribute(nombre) { delete this.atributos[nombre]; },
      ...estado,
    };
  });
  const $ = (selector) => {
    if (typeof selector === 'function') {
      selector();
      return undefined;
    }
    if (selector === documento) {
      return envoltorio([]);
    }
    if (typeof selector === 'object') {
      return envoltorio([selector]);
    }
    if (consultas[selector] !== undefined) {
      return envoltorio(consultas[selector].map(elemento));
    }
    if (selector.startsWith('#')) {
      return envoltorio([elemento(selector.slice(1))]);
    }
    if (selector.startsWith('.')) {
      const clase = selector.slice(1);
      return envoltorio(Object.values(pagina.elementos).filter((e) => e.clases.has(clase)));
    }
    return envoltorio([]);
  };
  $.ajax = (opciones) => { pagina.peticiones.push(opciones); };
  $.parseJSON = JSON.parse;
  $.trim = (texto) => String(texto).trim();
  pagina.contexto = cargarScriptGlobal(FUNCIONES, {
    $,
    jQuery: $,
    document: documento,
    alert: jest.fn(),
    confirm: jest.fn(() => true),
    abrirModal: jest.fn(),
    cerrarPopUp: jest.fn(),
    focusAlLanzarModal: jest.fn(),
    setTimeout: (funcion, espera, ...argumentos) => { pagina.temporizadores.push({ funcion, espera, argumentos }); return pagina.temporizadores.length; },
    location: { reload: jest.fn(), replace: jest.fn(), href: '' },
    window: { location: { href: '' }, open: jest.fn() },
    console: { log() {} },
    ...globales,
  });
  vm.runInContext(fs.readFileSync(ACCIONES, 'utf8'), pagina.contexto, { filename: ACCIONES });
  pagina.responder = (respuesta) => pagina.peticiones[pagina.peticiones.length - 1].success(JSON.stringify(respuesta));
  pagina.peticion = (indice = pagina.peticiones.length - 1) => pagina.peticiones[indice].data;

  return pagina;
}

/** Una línea de una tabla de la ficha: el enlace pulsado, dentro de su celda, dentro de su fila, dentro de la tabla, que anota el nombre de la fila que se le quita. */
function lineaDeTabla() {
  const tabla = { quitadas: [], removeChild(hijo) { this.quitadas.push(hijo.nombre); } };
  const fila = { nombre: 'fila', parentNode: tabla };
  const celda = { parentNode: fila };
  return { enlace: { id: 'eliminarRefProv_12', parentNode: celda }, tabla };
}

const IVAS = [{ idIva: '1', iva: '21.00' }, { idIva: '2', iva: '10.00' }];

describe('los precios de la ficha', () => {
  /** @comportamiento El precio sin IVA es el coste más el beneficio, y el precio con IVA le suma el IVA elegido. */
  test('recalcular desde el coste y el beneficio', () => {
    const pagina = paginaDePrueba({ elementos: { coste: { valor: '10' }, beneficio: { valor: '25' }, 'idIva option:selected': { valor: '1' } }, globales: { ivas: IVAS } });

    pagina.contexto.recalcularPrecioSegunCosteBeneficio({});

    expect(pagina.elementos.pvpSiva.valor).toBe('12.50');
    expect(pagina.elementos.pvpCiva.valor).toBe('15.13');
    expect(pagina.elementos.pvpCiva.css['outline-color']).toBe('coral');
  });

  /** @comportamiento Cambiar el precio sin IVA recalcula el de con IVA, y al revés. */
  test('recalcular un precio desde el otro', () => {
    const pagina = paginaDePrueba({ elementos: { pvpSiva: { valor: '10' }, pvpCiva: { valor: '' }, 'idIva option:selected': { valor: '2' } }, globales: { ivas: IVAS } });

    pagina.contexto.recalcularPvp('pvpSiva');
    expect(pagina.elementos.pvpCiva.valor).toBe('11.00');

    pagina.elementos.pvpCiva.valor = '22';
    pagina.contexto.recalcularPvp('pvpCiva');
    expect(pagina.elementos.pvpSiva.valor).toBe('20.00');
  });

  /** @comportamiento Un IVA que no está en la lista es cero. */
  test('un iva que no está en la lista es cero', () => {
    const pagina = paginaDePrueba({ elementos: { 'idIva option:selected': { valor: '9' } }, globales: { ivas: IVAS } });

    expect(pagina.contexto.obtenerIva()).toBe(0);
  });

  /** @comportamiento Un número decimal, positivo o negativo, es válido; un texto, no. */
  test('comprobar un número', () => {
    const { comprobarNumero } = paginaDePrueba().contexto;

    expect([comprobarNumero('12.5'), comprobarNumero('-3'), comprobarNumero('0'), comprobarNumero('a1')]).toEqual([true, true, true, false]);
  });

  /**
   * @etiquetas esperado bajo
   * @estado rojo
   * @codigo-afectado modulos/mod_producto/funciones.js:540-548
   *
   * @que-ocurre-hoy Una caja vacía pasa por número válido: un coste o un precio borrado se recalcula
   *   y la ficha enseña «NaN».
   * @que-deberia-ocurrir Que una caja vacía no sea un número.
   * @por-que-ocurre La expresión acepta que falten todas sus partes.
   * @como-deberia-funcionar Exigir al menos una cifra.
   */
  test('una caja vacía no es un número', () => {
    expect(paginaDePrueba().contexto.comprobarNumero('')).toBe(false);
  });
});

describe('los códigos de barras de la ficha', () => {
  /** @comportamiento Con todas las cajas llenas, añadir un código pide la línea siguiente; con una vacía, no pide nada. */
  test('añadir un código solo si no hay una caja vacía', () => {
    const llenas = paginaDePrueba({ consultas: { '#tcodigo>tbody>tr': ['f0', 'f1'] }, elementos: { codBarras_0: { valor: '841' }, codBarras_1: { valor: '842' } },
      nodos: { codBarras_0: {}, codBarras_1: {} } });
    const conVacia = paginaDePrueba({ consultas: { '#tcodigo>tbody>tr': ['f0'] }, elementos: { codBarras_0: { valor: '' } }, nodos: { codBarras_0: {} } });

    llenas.contexto.AnhadirCodbarras();
    llenas.responder({ html: '<tr>nueva</tr>' });
    conVacia.contexto.AnhadirCodbarras();

    expect(llenas.peticion()).toEqual({ pulsado: 'HtmlLineaCodigoBarras', fila: 2 });
    expect(llenas.elementos['tcodigo>tbody'].antepuesto).toEqual(['<tr>nueva</tr>']);
    expect(conVacia.peticiones).toHaveLength(0);
  });

  /**
   * @etiquetas esperado medio
   * @estado rojo
   * @codigo-afectado modulos/mod_producto/funciones.js:353-391
   *
   * @que-ocurre-hoy Tras quitar un código de barras, añadir otro crea una caja con el mismo nombre
   *   que una de las que quedan: al guardar la ficha, uno de los dos códigos se pierde.
   * @que-deberia-ocurrir Que cada caja nueva tenga un nombre que no use ninguna otra.
   * @por-que-ocurre El número de la caja nueva es el número de filas, que tras quitar una ya no es
   *   el siguiente libre.
   * @como-deberia-funcionar Tomar el número siguiente al mayor de las cajas que hay.
   */
  test('añadir un código tras quitar otro no repite una caja', () => {
    const pagina = paginaDePrueba({ consultas: { '#tcodigo>tbody>tr': ['f1', 'f2'] }, elementos: { codBarras_1: { valor: '841' }, codBarras_2: { valor: '842' } },
      nodos: { codBarras_1: {}, codBarras_2: {} } });

    pagina.contexto.AnhadirCodbarras();

    expect(['1', '2']).not.toContain(String(pagina.peticion().fila));
  });

  /** @comportamiento Un código repetido en el mismo producto se avisa y se borra; uno de otro producto, se avisa al responder la petición. */
  test('controlar un código de barras', () => {
    const pagina = paginaDePrueba({ consultas: { '#tcodigo :input': ['codBarras_0', 'codBarras_1'] }, elementos: { codBarras_0: { valor: '841' }, codBarras_1: { valor: '841' } },
      globales: { producto: { idArticulo: '15' } } });
    const caja = { id_input: 'codBarras_1', darValor: () => '841' };

    pagina.contexto.controlCodBarras(caja);
    pagina.responder({ NItems: 1, Items: [{ idArticulo: '20' }] });

    expect(pagina.contexto.alert).toHaveBeenCalledWith('No puedes repetir el mismo codbarras en el mismo producto');
    expect(pagina.elementos.codBarras_1.valor).toBe('');
    expect(pagina.peticion()).toEqual({ pulsado: 'ComprobarSiExisteCodbarras', codBarras: '841' });
    expect(pagina.contexto.alert).toHaveBeenCalledWith('Existe este codbarras en 1 productos. \n Estas segura que quiere añadirlo.');
  });

  /** @comportamiento La línea de un código vacío se pide con una orden propia; ninguna pantalla la usa. */
  test('pedir una línea vacía', () => {
    const pagina = paginaDePrueba({ nodos: { tcodigo: { childElementCount: 3 } } });

    pagina.contexto.agregoCodBarrasVacio();

    expect(pagina.peticion()).toEqual({ pulsado: 'HtmlCodigoBarrasVacio', filas: 3 });
  });
});

describe('las cajas de la ficha', () => {
  /** @comportamiento La entrada de un nombre quita las comillas dobles. */
  test('quitar las comillas de un nombre', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.validarEntradaNombre({ id_input: 'articulo_name', darValor: () => 'Kiwi "gold' });

    expect(pagina.elementos.articulo_name.valor).toBe('Kiwi gold');
  });

  /**
   * @etiquetas esperado medio
   * @estado rojo
   * @codigo-afectado modulos/mod_producto/funciones.js:633-639
   *
   * @que-ocurre-hoy La entrada de un nombre solo quita la primera comilla doble: un nombre con dos
   *   sigue llevando una, que el guardado no admite.
   * @que-deberia-ocurrir Que no quede ninguna comilla doble, o que el guardado las admita.
   * @por-que-ocurre La sustitución se hace una sola vez, no en todo el texto.
   * @como-deberia-funcionar Sustituir todas las apariciones.
   */
  test('quitar todas las comillas de un nombre', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.validarEntradaNombre({ id_input: 'articulo_name', darValor: () => 'Kiwi "gold"' });

    expect(pagina.elementos.articulo_name.valor).not.toContain('"');
  });

  /** @comportamiento El coste se desbloquea para editarlo, y las cajas de un proveedor, con su marca de principal. */
  test('desbloquear el coste y un proveedor', () => {
    const pagina = paginaDePrueba({ elementos: { coste: { attrs: { readonly: 'true' } }, prov_coste_12: { attrs: { readonly: 'true' } }, check_pro_12: { attrs: { disabled: 'true' } } } });

    pagina.contexto.desActivarCoste({ target: {} });
    pagina.contexto.desActivarCajasProveedor({ id: 'desActivarProv_12' });
    pagina.contexto.bloquearCajaProveedor({ name_cja: 'prov_cref_12' });

    expect(pagina.elementos.coste.attrs.readonly).toBeUndefined();
    expect(pagina.elementos.prov_coste_12.attrs.readonly).toBeUndefined();
    expect(pagina.elementos.prov_coste_12.attrs.onkeydown).toBe('controlEventos(event)');
    expect(pagina.elementos.check_pro_12.attrs.onclick).toBe('cambioEstadoProvPrincipal(this)');
    expect(pagina.elementos.prov_cref_12.attrs.readonly).toBe('true');
  });

  /** @comportamiento Marcar un proveedor como principal desmarca los demás. */
  test('un solo proveedor principal', () => {
    const pagina = paginaDePrueba({ consultas: { 'input:checkbox[name=check_pro]:checkbox': ['check_pro_1', 'check_pro_2'] },
      elementos: { check_pro_1: { marcado: true, attrs: { checked: 'true' } }, check_pro_2: { attrs: { checked: 'true' } } } });

    pagina.contexto.cambioEstadoProvPrincipal({ id: 'check_pro_1' });

    expect(pagina.elementos.check_pro_2.attrs.checked).toBeUndefined();
    expect(pagina.elementos.check_pro_1.attrs.checked).toBe('true');
  });

  /** @comportamiento La tecla de entrada no envía el formulario. */
  test('anular la tecla de entrada', () => {
    const { anular } = paginaDePrueba().contexto;

    expect(anular({ which: 13 })).toBe(false);
    expect(anular({ which: 65 })).toBe(true);
  });
});

describe('los proveedores de la ficha', () => {
  /** @comportamiento Buscar un proveedor pide el buscador con los proveedores que ya tiene, y lo abre en una ventana. */
  test('buscar un proveedor', () => {
    const pagina = paginaDePrueba({ elementos: { p3: { valor: '3', clases: ['idProveedor'] }, p4: { valor: '4', clases: ['idProveedor'] } } });

    pagina.contexto.BuscarProveedor('popup', 'huerta');
    pagina.responder({ html: '<input id="cajaBusquedaproveedor">' });

    expect(pagina.peticion()).toEqual({ pulsado: 'HtmlCajaBuscarProveedor', dedonde: 'popup', busqueda: 'huerta', idsProveedores: ['3', '4'] });
    expect(pagina.contexto.abrirModal).toHaveBeenCalledWith('Buscar Proveedor Nuevo para este producto', '<input id="cajaBusquedaproveedor">');
    expect(pagina.contexto.focusAlLanzarModal).toHaveBeenCalledWith('cajaBusquedaproveedor');
  });

  /** @comportamiento Elegir un proveedor añade su fila a la tabla; si la petición responde un error, se avisa. */
  test('elegir un proveedor', () => {
    const pagina = paginaDePrueba({ globales: { producto: { idArticulo: '15' } } });

    pagina.contexto.seleccionProveedor('popup', '3');
    pagina.responder({ htmlFilaProveedor: '<tr>huerta</tr>' });
    pagina.contexto.seleccionProveedor('popup', '4');
    pagina.responder({ error: 'sin datos' });

    expect(pagina.peticion(0)).toEqual({ pulsado: 'obtenerCostesProveedor', idProveedor: '3', idProducto: '15' });
    expect(pagina.elementos.tproveedor.antepuesto).toEqual(['<tr>huerta</tr>']);
    expect(pagina.contexto.alert).toHaveBeenCalledWith(' Hubo un error al obtener los datos del proveedor ');
  });

  /** @comportamiento Una referencia ya registrada se avisa; una nueva, no. */
  test('comprobar una referencia', () => {
    const pagina = paginaDePrueba({ elementos: { referencia: { valor: 'KIWI-1' } } });

    pagina.contexto.comprobarReferencia();
    pagina.responder([{ idArticulo: '20' }]);
    pagina.contexto.comprobarReferencia();
    pagina.responder([]);

    expect(pagina.peticion()).toEqual({ pulsado: 'comprobarReferencia', referencia: 'KIWI-1' });
    expect(pagina.contexto.alert).toHaveBeenCalledTimes(1);
  });

  /**
   * @etiquetas esperado bajo
   * @estado rojo
   * @codigo-afectado modulos/mod_producto/funciones.js:750-775
   *
   * @que-ocurre-hoy La referencia del propio producto se avisa como ya registrada al revisarla en su
   *   ficha.
   * @que-deberia-ocurrir Que solo se avise la referencia que tiene otro producto.
   * @por-que-ocurre La comprobación avisa si la petición encuentra algún producto, sin mirar si es el
   *   de la ficha.
   * @como-deberia-funcionar Descartar el producto de la ficha antes de avisar.
   */
  test('la referencia del propio producto no se avisa', () => {
    const pagina = paginaDePrueba({ elementos: { referencia: { valor: 'KIWI-1' } }, globales: { producto: { idArticulo: '15' } } });

    pagina.contexto.comprobarReferencia();
    pagina.responder([{ idArticulo: '15' }]);

    expect(pagina.contexto.alert).not.toHaveBeenCalled();
  });
});

describe('quitar líneas de la ficha', () => {
  /** @comportamiento Quitar un histórico, un proveedor, una referencia de tienda o de balanza pide confirmación, lo envía y quita la línea. */
  test('quitar una línea la envía y la quita', () => {
    const pagina = paginaDePrueba({ globales: { producto: { idArticulo: '15' } } });
    const lineas = [lineaDeTabla(), lineaDeTabla(), lineaDeTabla(), lineaDeTabla()];

    pagina.contexto.EliminarHistorico(7, lineas[0].enlace);
    pagina.responder({ NAfectados: 1 });
    pagina.contexto.EliminarRefProveedor(lineas[1].enlace);
    pagina.responder({ NAfectados: 1 });
    pagina.contexto.EliminarReferenciaTienda(9, lineas[2].enlace);
    pagina.responder({ NAfectados: 1 });
    pagina.contexto.EliminarReferenciaBalanza(5, lineas[3].enlace);
    pagina.responder({ NAfectados: 1 });

    expect(pagina.peticiones.map((p) => p.data)).toEqual([
      { pulsado: 'eliminarHistorico', idHistorico: 7 }, { pulsado: 'eliminarRefProveedor', idProveedor: '12', idArticulo: '15' },
      { pulsado: 'eliminarReferenciaTienda', idCruce: 9 }, { pulsado: 'eliminarReferenciaBalanza', id: 5 },
    ]);
    lineas.forEach((linea) => expect(linea.tabla.quitadas).toEqual(['fila']));
  });

  /** @comportamiento Sin confirmar, no se envía nada. */
  test('sin confirmar no se quita', () => {
    const pagina = paginaDePrueba({ globales: { confirm: jest.fn(() => false) } });

    pagina.contexto.EliminarHistorico(7, lineaDeTabla().enlace);

    expect(pagina.peticiones).toHaveLength(0);
  });

  /**
   * @etiquetas esperado bajo
   * @estado rojo
   * @codigo-afectado modulos/mod_producto/funciones.js:1017-1135
   *
   * @que-ocurre-hoy Quitar una línea que la petición no borra la quita igual de la pantalla.
   * @que-deberia-ocurrir Que la línea solo se quite si la petición la borró.
   * @por-que-ocurre Se mira si la respuesta trae un error, no cuántas filas borró.
   * @como-deberia-funcionar Quitar la línea solo si la respuesta dice que borró una.
   */
  test('una línea que no se borra no se quita', () => {
    const pagina = paginaDePrueba();
    const linea = lineaDeTabla();

    pagina.contexto.EliminarReferenciaBalanza(5, linea.enlace);
    pagina.responder({ NAfectados: 0 });

    expect(linea.tabla.quitadas).toEqual([]);
  });

  /** @comportamiento Quitar una familia o un código quita su fila. */
  test('quitar una familia o un código quita su fila', () => {
    const pagina = paginaDePrueba();
    const familia = lineaDeTabla();
    const codigo = lineaDeTabla();

    pagina.contexto.eliminarFamiliaProducto(familia.enlace);
    pagina.contexto.eliminarCodBarras(codigo.enlace);

    expect(familia.tabla.quitadas).toEqual(['fila']);
    expect(codigo.tabla.quitadas).toEqual(['fila']);
  });
});

describe('el listado', () => {
  /** @comportamiento Cambiar una columna guarda su nuevo valor en la configuración, la graba y recarga. */
  test('cambiar una columna', () => {
    const configuracion = { mostrar_lista: [{ nombre: 'codBarras', valor: 'Si' }], filtro: { valor: 'No' } };
    const pagina = paginaDePrueba({ elementos: { caja: { valor: 'Si', attrs: { name: 'codBarras' } } }, globales: { configuracion } });

    pagina.contexto.GuardarConfiguracion(pagina.elementos.caja);
    pagina.temporizadores[0].funcion();

    expect(configuracion.mostrar_lista[0].valor).toBe('No');
    expect(pagina.peticion()).toEqual({ pulsado: 'Grabar_configuracion', configuracion });
    expect(pagina.temporizadores.map((t) => t.espera)).toEqual([500, 1000]);
  });

  /**
   * @etiquetas esperado bajo
   * @estado rojo
   * @codigo-afectado modulos/mod_producto/funciones.js:393-408
   *
   * @que-ocurre-hoy Cambiar una columna recarga el listado a un tiempo fijo, haya terminado o no de
   *   grabarse la configuración: si tarda, el listado vuelve sin el cambio.
   * @que-deberia-ocurrir Que el listado se recargue cuando la configuración ya se grabó.
   * @por-que-ocurre La grabación y la recarga se programan cada una con su espera, sin relación entre
   *   ellas.
   * @como-deberia-funcionar Recargar al responder la grabación.
   */
  test('la recarga espera a que la configuración se grabe', () => {
    const configuracion = { mostrar_lista: [{ nombre: 'codBarras', valor: 'Si' }] };
    const pagina = paginaDePrueba({ elementos: { caja: { valor: 'Si', attrs: { name: 'codBarras' } } }, globales: { configuracion } });

    pagina.contexto.GuardarConfiguracion(pagina.elementos.caja);

    expect(pagina.temporizadores.map((t) => t.funcion)).not.toContain(pagina.contexto.refresh);
  });

  /** @comportamiento La columna de búsqueda por defecto se marca en la configuración y la caja de búsqueda se vacía. */
  test('cambiar la columna de búsqueda', () => {
    const configuracion = { mostrar_lista: [{ nombre: 'articulo_name', valor: 'Si', buscar_default: 'Si' }, { nombre: 'codBarras', valor: 'Si' }] };
    const pagina = paginaDePrueba({ consultas: { 'input:text[name=buscar]': ['buscar'] }, elementos: { buscar: { valor: 'kiwi' } }, globales: { configuracion } });

    pagina.contexto.GuardarBusqueda({ target: { value: 'codBarras' } });

    expect(configuracion.mostrar_lista).toEqual([{ nombre: 'articulo_name', valor: 'Si' }, { nombre: 'codBarras', valor: 'Si', buscar_default: 'Si' }]);
    expect(pagina.elementos.buscar.valor).toBe('');
  });

  /** @comportamiento El filtro por estado se guarda, o se quita con «Sin Filtrar», y el listado se recarga. */
  test('filtrar por estado', () => {
    const configuracion = { mostrar_lista: [] };
    const pagina = paginaDePrueba({ globales: { configuracion } });

    pagina.contexto.GuardarFiltroEstado({ target: { value: 'Baja' } });
    expect(configuracion.estado_filtro).toBe('Baja');
    pagina.contexto.GuardarFiltroEstado({ target: { value: 'Sin Filtrar' } });
    expect(configuracion.estado_filtro).toBeUndefined();
    expect(pagina.peticiones).toHaveLength(2);
  });

  /** @comportamiento Marcar un producto lo envía a la selección; sin ninguno en el listado, este se recarga; con alguno, se enseña cuántos. */
  test('marcar un producto', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.selecionarItemProducto(4, 'listaProductos', 'seleccionar');
    pagina.responder({ Nitems: 2 });
    pagina.contexto.selecionarItemProducto(4, 'listaProductos', 'NoSeleccionar');
    pagina.responder({ Nitems: 0 });

    expect(pagina.peticion(0)).toEqual({ pulsado: 'productosSesion', id: 4, seleccionar: 'seleccionar' });
    expect(pagina.elementos.textoCantidad).toBeUndefined();
    expect(pagina.contexto.location.href).toBe('ListaProductos.php');
  });

  /** @comportamiento Marcar todos envía a la selección cada producto de la página. */
  test('marcar todos', () => {
    const pagina = paginaDePrueba({ elementos: { checkUsuTodos: { marcado: true }, checkUsu1: { valor: '4' }, checkUsu2: { valor: '5' } } });

    pagina.contexto.seleccionarTodo();

    expect(pagina.peticiones.map((p) => p.data.id)).toEqual(['4', '5']);
    expect(pagina.elementos.checkUsu1.marcado).toBe(true);
  });

  /** @comportamiento Desmarcar todos los quita de la selección uno a uno; se recorren las cuarenta filas de una página, no más. */
  test('desmarcar todos recorre las cuarenta filas de la página', () => {
    const pagina = paginaDePrueba({ elementos: { checkUsuTodos: { marcado: false }, checkUsu1: { valor: '4', marcado: true }, checkUsu41: { valor: '9', marcado: true } } });

    pagina.contexto.seleccionarTodo();

    expect(pagina.peticiones.map((p) => [p.data.id, p.data.seleccionar])).toEqual([['4', 'NoSeleccionar']]);
    expect(pagina.elementos.checkUsu1.marcado).toBe(false);
    expect(pagina.elementos.checkUsu41.marcado).toBe(true);
  });

  /** @comportamiento Filtrar por la selección la guarda en la configuración; quitar el filtro también. */
  test('filtrar por la selección', () => {
    const configuracion = { filtro: { valor: 'No' } };
    const pagina = paginaDePrueba({ elementos: { checkSeleccion: { marcado: true } }, globales: { configuracion } });

    pagina.contexto.seleccionProductos();
    expect(configuracion.filtro.valor).toBe('Si');
    pagina.elementos.checkSeleccion.marcado = false;
    pagina.contexto.seleccionProductos();
    expect(configuracion.filtro.valor).toBe('No');
  });

  /** @comportamiento Vaciar la selección la envía, quita el filtro y vuelve al listado. */
  test('vaciar la selección', () => {
    const configuracion = { filtro: { valor: 'Si' } };
    const pagina = paginaDePrueba({ globales: { configuracion } });

    pagina.contexto.eliminarSeleccionProductos();
    pagina.responder({});

    expect(pagina.peticion(0)).toEqual({ pulsado: 'eliminarSeleccion' });
    expect(configuracion.filtro.valor).toBe('No');
    expect(pagina.contexto.location.href).toBe('ListaProductos.php');
  });

  /** @comportamiento Los botones del listado: ver un producto con uno marcado, añadir uno nuevo y buscar. */
  test('los botones del listado', () => {
    const pagina = paginaDePrueba({ consultas: { 'input[name=buscar': ['buscar'] }, elementos: { buscar: { valor: ' kiwi ' } },
      globales: { checkID: [], VerIdSeleccionado: jest.fn() } });

    pagina.contexto.checkID = ['15'];
    pagina.contexto.metodoClick('VerProducto', 'producto');
    expect(pagina.contexto.window.location.href).toBe('./producto.php?id=15');
    pagina.contexto.metodoClick('AgregarProducto');
    expect(pagina.contexto.window.location.href).toBe('./producto.php');
    pagina.contexto.metodoClick('NuevaBusqueda', 'ListaProductos');
    expect(pagina.contexto.window.location.href).toBe('./ListaProductos.php?buscar=kiwi');
    pagina.contexto.UnProductoClick(20);
    expect(pagina.contexto.window.location.href).toBe('./producto.php?id=20');
  });

  /** @comportamiento El estado en la web de cada producto cambia su icono: publicado o con error. */
  test('el estado en la web', () => {
    const pagina = paginaDePrueba({ elementos: { idProducto_estadoWeb_4: { clases: ['icono_web', 'despublicado'] }, idProducto_estadoWeb_5: { clases: ['icono_web', 'despublicado'] } } });

    pagina.contexto.obtenerEstadoProductoWeb([4, 5], 9);
    pagina.responder([{ idArticulo: 4, estado: '1' }, { idArticulo: 5, estado: 'Error' }]);

    expect([...pagina.elementos.idProducto_estadoWeb_4.clases]).toEqual([]);
    expect([...pagina.elementos.idProducto_estadoWeb_5.clases]).toEqual(['icono_web', 'error_estadoWeb']);
  });
});

describe('familias, estados y borrado', () => {
  /** @comportamiento El modal de familias se abre con su título según venga del listado o de la ficha, y el de estados igual. */
  test('los modales de familias y de estados', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.modalFamiliaProducto(0, 'ListadoProductos');
    pagina.responder({ html: 'familias' });
    pagina.contexto.modalFamiliaProducto(15);
    pagina.responder({ html: 'familias' });
    pagina.contexto.modalEstadoProductos();
    pagina.responder({ html: 'estados' });

    expect(pagina.contexto.abrirModal.mock.calls).toEqual([['Añadir familia a todos los seleccionados', 'familias'], ['Añadir familia 15', 'familias'], ['Cambio de estado de productos', 'estados']]);
  });

  /** @comportamiento Desde el listado, la familia se pone a los seleccionados y se avisa de cuántos; desde la ficha, añade su fila o avisa si ya la tenía. */
  test('poner una familia', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.guardarProductoFamilia(7, 0, 'ListadoProductos');
    pagina.responder({ productosEnFamilia: ['5'], contadorProductos: 1 });
    pagina.contexto.guardarProductoFamilia(7, 15);
    pagina.responder({ html: '<tr>Frutas</tr>' });
    pagina.contexto.guardarProductoFamilia(7, 15);
    pagina.responder({ error: 1 });

    expect(pagina.contexto.alert).toHaveBeenCalledWith('Productos guardados en familia: 1');
    expect(pagina.contexto.location.reload).toHaveBeenCalled();
    expect(pagina.elementos.tfamilias.antepuesto).toEqual(['<tr>Frutas</tr>']);
    expect(pagina.contexto.alert).toHaveBeenCalledWith('No puedes añadir esa familia al producto ya que ya está añadida');
  });

  /** @comportamiento Los productos de una familia o de un proveedor se marcan uno a uno; sin ninguno, se avisa y se recarga. */
  test('buscar los productos de una familia o de un proveedor', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.buscarProductosFamilia(7);
    pagina.responder({ Productos: ['4', '5'] });
    pagina.contexto.buscarProductosProveedor(3);
    pagina.responder({ Productos: [] });

    expect(pagina.peticiones.map((p) => p.data.pulsado)).toEqual(['buscarProductosDeFamilia', 'productosSesion', 'productosSesion', 'buscarProductosProveedor']);
    expect(pagina.contexto.alert).toHaveBeenCalledWith('Esta proveedor no tiene productos, busca en los hijos, si tiene');
  });

  /** @comportamiento Cambiar el estado de varios productos lo envía y recarga. */
  test('cambiar el estado de varios productos', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.modificarEstadoProductos('Baja', '4,5');
    pagina.responder({ consulta: { Consulta: { NAfectados: 2 } } });

    expect(pagina.peticion()).toEqual({ pulsado: 'cambiarEstadoProductos', estado: 'Baja', productos: '4,5' });
    expect(pagina.contexto.location.reload).toHaveBeenCalled();
  });

  /** @comportamiento Borrar los seleccionados pide confirmación, enseña el resumen y recarga al cerrarlo. */
  test('borrar los seleccionados', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.eliminarProductos(9);
    pagina.responder({ html: 'resumen' });
    pagina.manejadores['ventanaModal:hidden.bs.modal']();

    expect(pagina.peticion()).toEqual({ idTiendaWeb: 9, pulsado: 'eliminarProductos' });
    expect(pagina.contexto.abrirModal).toHaveBeenCalledWith('Resultado de eliminar productos', 'resumen');
    expect(pagina.contexto.location.reload).toHaveBeenCalled();
  });
});

describe('regularización, recálculo, impresión y mayor', () => {
  /** @comportamiento La regularización se pide en una ventana, y la diferencia entre la existencia y la real se calcula al escribir. */
  test('preparar la regularización', () => {
    const pagina = paginaDePrueba({ elementos: { stockactual: { valor: '5' }, stockcolocar: { valor: '8' } } });

    pagina.contexto.RegularizarStock(15);
    pagina.responder({ html: 'formulario' });
    pagina.manejadores['stockcolocar:keyup']({});

    expect(pagina.peticion()).toEqual({ pulsado: 'datosRegularizar', idarticulo: 15 });
    expect(pagina.contexto.abrirModal).toHaveBeenCalledWith('Regularizacion de Stock ', 'formulario');
    expect(pagina.elementos.stocksumar.valor).toBe(3);
  });

  /**
   * @etiquetas esperado bajo
   * @estado rojo
   * @codigo-afectado modulos/mod_producto/funciones.js:802-831
   *
   * @que-ocurre-hoy Grabar la regularización abre una ventana de respuesta sin contenido.
   * @que-deberia-ocurrir Que la ventana diga cómo quedó la existencia.
   * @por-que-ocurre La ventana enseña el HTML de la respuesta, y la petición no lo devuelve.
   * @como-deberia-funcionar Montar el mensaje con lo que responde la petición.
   */
  test('la respuesta de la regularización dice cómo quedó', () => {
    const pagina = paginaDePrueba({ elementos: { articuloid: { valor: '15' }, stockcolocar: { valor: '8' } } });

    pagina.contexto.grabarRegularizacion();
    pagina.responder({ cambioStock: true, registroRegularizacionStock: 1 });

    expect(pagina.peticion()).toEqual({ pulsado: 'grabarRegularizacion', idarticulo: '15', stockReal: '8' });
    expect(pagina.contexto.abrirModal.mock.calls[0][1]).toBeDefined();
  });

  /** @comportamiento Descartar un recálculo tacha su fila y ofrece volver a él; volver la destacha. */
  test('descartar y recuperar un recálculo', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.cambiarEstadoRecalculo(15, 'albaran', 12, 'compras', 3, 'eliminar');
    pagina.responder({ accion: 'eliminar' });
    expect(pagina.elementos.Row3.clases.has('tachado')).toBe(true);
    expect(pagina.elementos['Row3 > .eliminar'].html).toContain("'retorno'");
    pagina.contexto.cambiarEstadoRecalculo(15, 'albaran', 12, 'compras', 3, 'retorno');
    pagina.responder({ accion: 'retorno' });

    expect(pagina.elementos.Row3.clases.has('tachado')).toBe(false);
    expect(pagina.peticion(0)).toEqual({ pulsado: 'cambiarEstadoRecalculo', idArticulo: 15, dedonde: 'albaran', id: 12, tipo: 'compras', accion: 'eliminar' });
  });

  /** @comportamiento Imprimir los precios se confirma, abre el fichero y vuelve a los albaranes; sin confirmar, vuelve sin imprimir. */
  test('imprimir los precios del recálculo', () => {
    const pagina = paginaDePrueba({ globales: { comunicacionBalanzas: 'OK' } });
    const sinImprimir = paginaDePrueba({ globales: { confirm: jest.fn(() => false) } });

    pagina.contexto.mensajeImprimir(12, 'Recalculo');
    pagina.responder({ fichero: '/tmp/recalculo.pdf' });
    sinImprimir.contexto.mensajeImprimir(12, 'Recalculo');

    expect(pagina.peticion()).toEqual({ pulsado: 'imprimir', dedonde: 'Recalculo', id: 12, mensaje: 'OK' });
    expect(pagina.contexto.window.open).toHaveBeenCalledWith('/tmp/recalculo.pdf');
    expect(pagina.contexto.location.href).toBe('../mod_compras/albaranesListado.php');
    expect(sinImprimir.peticiones).toHaveLength(0);
  });

  /** @comportamiento Imprimir etiquetas envía los marcados con su número de etiquetas, el tamaño y la tecla o referencia. */
  test('imprimir etiquetas', () => {
    const cantidades = [{ dataset: { idarticulo: '4' }, value: '2' }, { dataset: { idarticulo: '5' }, value: '1' }];
    const pagina = paginaDePrueba({ elementos: { 'tamanhos option:selected': { valor: 'A7' }, 'teclaOReferencia option:selected': { valor: '2' } },
      globales: { TfObtenerCheck: () => ['4'], TfObtenerObjetos: () => ({ each: (funcion) => cantidades.forEach((c) => funcion.call(c)) }) } });

    pagina.contexto.imprimirEtiquetas('ListaEtiquetas');
    pagina.responder({ fichero: '/tmp/etiquetas.pdf' });

    expect(pagina.peticion()).toEqual({ pulsado: 'imprimirEtiquetas', dedonde: 'ListaEtiquetas', tamano: 'A7', teclaOReferencia: '2', productos: '[{"idArticulo":"4","numEtiquetas":"2"}]' });
    expect(pagina.contexto.window.open).toHaveBeenCalledWith('/tmp/etiquetas.pdf');
  });

  /** @comportamiento El mayor de un producto se abre con sus fechas y su existencia de partida. */
  test('abrir el mayor', () => {
    const pagina = paginaDePrueba({ elementos: { fecha_inicio: { valor: '2026-01-01' }, fecha_final: { valor: '2026-03-31' }, stkini15: { valor: '4' } } });

    pagina.contexto.redirecionarMayor(15, 'DetalleMayor');

    expect(pagina.contexto.window.open).toHaveBeenCalledWith('./DetalleMayor.php?idArticulo=15&fecha_inicial=2026-01-01&fecha_final=2026-03-31&stock=4', '_blank');
  });

  /**
   * @etiquetas esperado bajo
   * @estado rojo
   * @codigo-afectado modulos/mod_producto/funciones.js:1323-1348
   *
   * @que-ocurre-hoy Con la fecha de inicio posterior a la final, abrir el mayor avisa y después
   *   termina con un error de JavaScript.
   * @que-deberia-ocurrir Que avise y no siga.
   * @por-que-ocurre La lectura de las fechas avisa y no devuelve nada, y quien la llama usa lo
   *   devuelto como una lista.
   * @como-deberia-funcionar Comprobar lo devuelto antes de usarlo.
   */
  test('abrir el mayor con las fechas al revés avisa y no sigue', () => {
    const pagina = paginaDePrueba({ elementos: { fecha_inicio: { valor: '2026-04-01' }, fecha_final: { valor: '2026-03-31' } } });

    expect(() => pagina.contexto.redirecionarMayor(15, 'DetalleMayor')).not.toThrow();
    expect(pagina.contexto.alert).toHaveBeenCalledWith('La fecha inicio no puede ser mayor que la fecha final');
  });
});

describe('las acciones directas', () => {
  /** @comportamiento El control de las cajas de la ficha despacha cada acción a su función. */
  test('el control de las cajas', () => {
    const pagina = paginaDePrueba({ elementos: { coste: { valor: '10' }, beneficio: { valor: '0' }, 'idIva option:selected': { valor: '1' }, referencia: { valor: 'K' } },
      globales: { ivas: IVAS } });
    const { controladorAcciones } = pagina.contexto;

    controladorAcciones({ id_input: 'articulo_name', darValor: () => 'a"b' }, 'revisar_contenido');
    controladorAcciones({ darValor: () => '10' }, 'salto_recalcular');
    controladorAcciones({}, 'controlReferencia');
    controladorAcciones({ name_cja: 'prov_coste_3', darValor: () => 'x' }, 'controlCosteProv');
    controladorAcciones({ name_cja: 'prov_coste_3', darValor: () => '2.5' }, 'controlCosteProv');
    controladorAcciones({ darValor: () => 'huerta' }, 'buscarProveedor');
    controladorAcciones({ fila: '2', darParametro: () => 'pvpRecomendado_' }, 'mover_down');
    controladorAcciones({ fila: '2', darParametro: () => 'pvpRecomendado_' }, 'mover_up');
    controladorAcciones({}, 'salto');
    controladorAcciones({}, 'otra');

    expect(pagina.elementos.articulo_name.valor).toBe('ab');
    expect(pagina.elementos.pvpCiva.valor).toBe('12.10');
    expect(pagina.contexto.alert).toHaveBeenCalledWith('Error en el coste, fijate bien');
    expect(pagina.elementos.prov_coste_3.attrs.readonly).toBe('true');
    expect(pagina.peticiones.map((p) => p.data.pulsado)).toEqual(['comprobarReferencia', 'HtmlCajaBuscarProveedor']);
    expect(pagina.temporizadores.slice(-2).map((t) => t.espera)).toEqual([50, 50]);
  });

  /** @comportamiento Recalcular el precio desde su caja y controlar un código de barras pasan por el control de las cajas. */
  test('el recálculo del precio y el código de barras', () => {
    const pagina = paginaDePrueba({ elementos: { pvpSiva: { valor: '10' }, 'idIva option:selected': { valor: '2' } }, consultas: { '#tcodigo :input': [] }, globales: { ivas: IVAS, producto: { idArticulo: '1' } } });

    pagina.contexto.controladorAcciones({ id_input: 'pvpSiva', darValor: () => '10' }, 'recalcularPvp');
    pagina.contexto.controladorAcciones({ name_cja: 'codBarras_0', darValor: () => '841' }, 'controlCodBarras');
    pagina.contexto.controladorAcciones({ name_cja: 'codBarras_1', darValor: () => '' }, 'controlCodBarras');

    expect(pagina.elementos.pvpCiva.valor).toBe('11.00');
    expect(pagina.peticiones.map((p) => p.data.pulsado)).toEqual(['ComprobarSiExisteCodbarras']);
  });

  /** @comportamiento Antes y después de construir una caja de precio recomendado, se toma su identificador y su fila. */
  test('las cajas del precio recomendado', () => {
    const { after_constructor: despues, before_constructor: antes } = paginaDePrueba().contexto;

    expect(despues({ id_input: 'pvpRecomendado' }, { target: { id: 'pvpRecomendado_7' } }).id_input).toBe('pvpRecomendado_7');
    expect(antes({ id_input: 'pvpRecomendado_7' }).fila).toBe('7');
  });

  /** @comportamiento En el mayor, el botón de buscar se enseña al cambiar las fechas y se desactiva si van al revés; borrar vacía el producto. */
  test('el botón de buscar del mayor', () => {
    const pagina = paginaDePrueba({ nodos: { fecha_inicial: { value: '2026-02-01' }, fecha_final: { value: '2026-01-01' }, buscar: {}, id_producto: { value: '15' } },
      globales: { fechaInicio: '2026-01-01', fechaFin: '2026-03-31' } });

    pagina.contexto.visibleButton();
    expect(pagina.nodos.buscar.style.display).toBe('block');
    expect(pagina.nodos.buscar.atributos.disabled).toBe('true');
    pagina.nodos.fecha_inicial.value = '2026-01-01';
    pagina.nodos.fecha_final.value = '2026-03-31';
    pagina.contexto.visibleButton();
    expect(pagina.nodos.buscar.style.display).toBe('none');
    pagina.contexto.borrar();
    expect(pagina.nodos.id_producto.value).toBe('');
  });

  /** @comportamiento En el mayor, un producto que existe lo rehace con intro; uno que no, se avisa y se vuelve al de antes; vacío, se repone. */
  test('cambiar el producto del mayor', () => {
    const pagina = paginaDePrueba({ nodos: { id_producto: { value: '20' }, error: {}, fecha_inicial: { value: '2026-01-01' }, fecha_final: { value: '2026-03-31' } },
      globales: { id: '15', reserva: '', fechaInicio: '2026-01-01', fechaFin: '2026-03-31', window: { location: 'http://x/DetalleMayor.php?idArticulo=15&f=1' } } });

    pagina.contexto.clikMayor({});
    pagina.responder(true);
    expect(pagina.peticion()).toEqual({ pulsado: 'idExiste', id: '20' });
    expect(pagina.contexto.location.replace).toHaveBeenCalledWith('http://x/DetalleMayor.php?idArticulo=20&fecha_inicial=2026-01-01&fecha_final=2026-03-31');

    pagina.nodos.id_producto.value = '99';
    pagina.contexto.AccionExisteIdProductoDetalleMayor({ tecla: '9' });
    pagina.responder(false);
    expect(pagina.nodos.error.innerHTML).toBe('No se encontró un producto con este id: 99');
    expect(pagina.nodos.id_producto.value).toBe('20');

    pagina.nodos.id_producto.value = '';
    pagina.contexto.AccionExisteIdProductoDetalleMayor({});
    expect(pagina.nodos.id_producto.value).toBe('20');
  });

  /**
   * @etiquetas esperado bajo
   * @estado rojo
   * @codigo-afectado modulos/mod_producto/js/AccionesDirectas.js:85-112
   *
   * @que-ocurre-hoy Al rehacer el mayor con una fecha de inicio igual a la final anterior, la fecha
   *   final nueva no se toma y el mayor vuelve con la de antes.
   * @que-deberia-ocurrir Que el mayor se rehaga con las dos fechas de las cajas.
   * @por-que-ocurre La fecha final se cambia solo si es distinta de la de inicio nueva, no de la
   *   final nueva.
   * @como-deberia-funcionar Compararla con la fecha final de la caja.
   */
  test('rehacer el mayor toma las dos fechas nuevas', () => {
    const pagina = paginaDePrueba({ nodos: { id_producto: { value: '15' }, fecha_inicial: { value: '2026-03-31' }, fecha_final: { value: '2026-04-30' } },
      globales: { id: '15', fechaInicio: '2026-01-01', fechaFin: '2026-03-31', window: { location: 'http://x/DetalleMayor.php?idArticulo=15' } } });

    pagina.contexto.rehacerMayor();

    expect(pagina.contexto.location.replace).toHaveBeenCalledWith('http://x/DetalleMayor.php?idArticulo=15&fecha_inicial=2026-03-31&fecha_final=2026-04-30');
  });
});

describe('al cargarse la página', () => {
  /** @comportamiento Los botones de regularizar abren la regularización de su producto, y los desplegables de familias y estados preparan su botón. */
  test('los botones y desplegables de la página', () => {
    const pagina = paginaDePrueba({ elementos: { regularizar: { clases: ['boton-regularizar'], datos: { idarticulo: 15 } }, 'select': {}, ProductoModalDedonde: { valor: 'ListadoProductos' },
      idProductoModal: { valor: '0' }, idProductosModal: { valor: '4,5' }, familias: { clases: ['familias'] }, estados: { clases: ['estados'] }, stockactual: { valor: '5' }, stocksumar: { valor: '2' } } });

    pagina.manejadores['regularizar:click']({ stopPropagation() {}, preventDefault() {}, currentTarget: pagina.elementos.regularizar });
    pagina.manejadores['ventanaModal:shown.bs.modal']();
    pagina.desplegables.familias.select({}, { item: { value: '7' } });
    pagina.desplegables.estados.select({}, { item: { value: 'Baja' } });
    pagina.manejadores['stocksumar:keyup']({ preventDefault() {} });

    expect(pagina.peticion()).toEqual({ pulsado: 'datosRegularizar', idarticulo: 15 });
    expect(pagina.elementos.botonEnviar2.html).toContain("guardarProductoFamilia(7, 0, 'ListadoProductos')");
    expect(pagina.elementos.botonEnviarEstados.html).toContain("modificarEstadoProductos('Baja', '4,5')");
    expect(pagina.elementos.stockcolocar.valor).toBe(7);
  });
});
