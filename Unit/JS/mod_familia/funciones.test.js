/**
 * El JavaScript de las familias (`modulos/mod_familia/funciones.js`): guardar la ficha, marcar y
 * borrar familias, expandir y compactar el árbol, quitar productos de una familia y relacionarla con
 * la tienda web.
 *
 * Se ejecuta en un contexto propio de Node, con un jQuery de prueba que guarda el estado de cada
 * elemento —valor, clases, si se ve, su contenido y sus datos— y apunta las peticiones a las tareas
 * sin enviarlas: el caso responde por la petición. Su `ready` ejecuta la función en el acto, como
 * hace el jQuery que sirve el producto con la página cargada.
 */

'use strict';

const path = require('path');
const { cargarScriptGlobal } = require('../../../support/js/cargarScriptGlobal');

const FUNCIONES = path.resolve(__dirname, '../../../../TPVFox/modulos/mod_familia/funciones.js');

/** Una página de prueba; `elementos` da el estado de partida de los que el caso necesita, por su selector `#id`. */
function paginaDePrueba(elementos = {}, globales = {}) {
  const pagina = { peticiones: [], desplegables: {}, elementos: {} };
  const elemento = (id) => {
    if (!pagina.elementos[id]) {
      pagina.elementos[id] = { id, valor: '', clases: new Set(), visible: true, html: '', datos: {}, marcado: false, padre: null, alPulsar: null };
    }
    return pagina.elementos[id];
  };
  Object.entries(elementos).forEach(([id, estado]) => {
    const nuevo = elemento(id);
    Object.assign(nuevo, estado, { clases: new Set(estado.clases || []) });
  });
  const envoltorio = (lista) => ({
    length: lista.length,
    val: (valor) => (valor === undefined ? lista[0] && lista[0].valor : lista.forEach((e) => { e.valor = valor; })),
    prop: (nombre, valor) => (valor === undefined ? lista[0] && lista[0].marcado : lista.forEach((e) => { e.marcado = valor; })),
    hasClass: (clase) => lista.some((e) => e.clases.has(clase)),
    addClass: (clase) => lista.forEach((e) => e.clases.add(clase)),
    removeClass: (clase) => lista.forEach((e) => e.clases.delete(clase)),
    show: () => lista.forEach((e) => { e.visible = true; }),
    hide: () => lista.forEach((e) => { e.visible = false; }),
    html: (contenido) => (contenido === undefined ? lista[0] && lista[0].html : lista.forEach((e) => { e.html = contenido; })),
    parent: () => envoltorio(lista.map((e) => elemento(e.padre))),
    data: (clave) => lista[0] && lista[0].datos[clave],
    attr: () => lista[0] && lista[0].id,
    click: () => lista.forEach((e) => e.alPulsar && e.alPulsar()),
    each: (funcion) => lista.forEach((e, indice) => funcion(indice, e)),
    combobox: (opciones) => lista.forEach((e) => { pagina.desplegables[e.id] = opciones; }),
  });
  const $ = (selector) => {
    if (typeof selector === 'function') {
      selector();
      return undefined;
    }
    if (typeof selector === 'object') {
      return envoltorio([selector]);
    }
    if (selector.startsWith('#')) {
      return envoltorio([elemento(selector.slice(1))]);
    }
    const clase = selector.slice(1);
    return envoltorio(Object.values(pagina.elementos).filter((e) => e.clases.has(clase)));
  };
  $.ajax = (opciones) => { pagina.peticiones.push(opciones); };
  $.parseJSON = JSON.parse;
  pagina.contexto = cargarScriptGlobal(FUNCIONES, {
    $,
    alert: jest.fn(),
    confirm: jest.fn(() => true),
    abrirModal: jest.fn(),
    location: { reload: jest.fn() },
    window: { location: { href: '' } },
    console: { log() {} },
    ...globales,
  });
  pagina.responder = (respuesta) => pagina.peticiones[pagina.peticiones.length - 1].success(JSON.stringify(respuesta));

  return pagina;
}

describe('la ficha de una familia', () => {
  /**
   * @etiquetas estado-actual
   *
   * @comportamiento Al cargarse, los desplegables del padre y del padre en la web guardan en su campo
   *   oculto el padre elegido.
   */
  test('los desplegables del padre guardan el elegido', () => {
    const pagina = paginaDePrueba();

    pagina.desplegables.combopadre.select({}, { item: { value: '7' } });
    pagina.desplegables.combopadreWeb.select({}, { item: { value: '77' } });

    expect(pagina.elementos.inputidpadre.valor).toBe('7');
    expect(pagina.elementos.inputidpadreWeb.valor).toBe('77');
  });

  /**
   * @etiquetas estado-actual
   *
   * @comportamiento Guardar sin padre elegido, o sin nombre, avisa de lo que falta y no envía nada.
   */
  test('guardar sin padre o sin nombre avisa y no envía', () => {
    const pagina = paginaDePrueba({ idfamilia: { valor: '0' }, inputidpadre: { valor: -1 }, inputnombre: { valor: '' }, inputbeneficio: { valor: '25' } });

    pagina.contexto.guardarFamilia();

    expect(pagina.contexto.alert).toHaveBeenCalledWith('Por favor seccione un padre de la lista');
    expect(pagina.contexto.alert).toHaveBeenCalledWith('Por favor da un nombre a la familia');
    expect(pagina.peticiones).toHaveLength(0);
  });

  /**
   * @etiquetas estado-actual
   *
   * @comportamiento Guardar envía a las tareas el número, el nombre, el padre, el beneficio y la marca
   *   del TPV; si responde sin error, avisa y vuelve al listado.
   */
  test('guardar envía la familia y, si va bien, vuelve al listado', () => {
    const pagina = paginaDePrueba({ idfamilia: { valor: '0' }, inputidpadre: { valor: '3' }, inputnombre: { valor: 'Lácteos' }, inputbeneficio: { valor: '25' }, marcar_tpv: { marcado: true } });

    pagina.contexto.guardarFamilia();
    pagina.responder({ insert: 'INSERT ...', error: '0' });

    expect(pagina.peticiones[0].url).toBe('./tareas.php');
    expect(pagina.peticiones[0].data).toEqual({ pulsado: 'grabarFamilia', id: '0', nombrefamilia: 'Lácteos', idpadre: '3', beneficiomedio: '25', mostrar_tpv: 1 });
    expect(pagina.contexto.alert).toHaveBeenCalledWith('Grabado correctamente');
    expect(pagina.contexto.window.location.href).toBe('./ListaFamilias.php');
  });

  /**
   * @etiquetas esperado medio
   * @estado rojo
   * @codigo-afectado modulos/mod_familia/funciones.js:123-134
   *
   * @que-ocurre-hoy Si el guardado responde un error, el aviso enseña el error seguido de la sentencia
   *   que llegó en la respuesta, con la tabla, sus columnas y los datos.
   * @que-deberia-ocurrir Que el aviso diga que no se guardó, sin la sentencia.
   * @por-que-ocurre El aviso pega a su texto lo que llega en la respuesta como sentencia.
   * @como-deberia-funcionar Enseñar solo el motivo del error.
   */
  test('guardar, si responde un error, avisa sin enseñar la sentencia', () => {
    const pagina = paginaDePrueba({ idfamilia: { valor: '5' }, inputidpadre: { valor: '0' }, inputnombre: { valor: 'Lácteos' }, inputbeneficio: { valor: '25' } });

    pagina.contexto.guardarFamilia();
    pagina.responder({ insert: "UPDATE familias SET familiaNombre='Lácteos' WHERE idFamilia=5", error: 'Error de la base' });

    const avisos = pagina.contexto.alert.mock.calls.map((llamada) => String(llamada[0]));
    expect(avisos).toHaveLength(1);
    expect(avisos[0]).not.toContain('UPDATE familias');
  });

  /**
   * @etiquetas esperado alto
   * @estado rojo
   * @codigo-afectado modulos/mod_familia/funciones.js:257-268
   *
   * @que-ocurre-hoy Si la petición de guardar falla —el servidor termina con un error—, la ficha solo
   *   lo apunta en la consola: quien guarda no sabe que no se guardó.
   * @que-deberia-ocurrir Que avise de que no se pudo guardar.
   * @por-que-ocurre La petición común solo escribe el fallo en la consola.
   * @como-deberia-funcionar Avisar al usuario cuando la petición falla.
   */
  test('guardar, si la petición falla, avisa', () => {
    const pagina = paginaDePrueba({ idfamilia: { valor: '0' }, inputidpadre: { valor: '0' }, inputnombre: { valor: 'Lácteos' }, inputbeneficio: { valor: '' } });

    pagina.contexto.guardarFamilia();
    pagina.peticiones[0].error({ status: 500 }, 'error', 'Internal Server Error');

    expect(pagina.contexto.alert).toHaveBeenCalled();
  });

  /**
   * @etiquetas estado-actual
   *
   * @comportamiento Quitar productos de la familia envía los marcados, y sin ninguno marcado no envía.
   */
  test('quitar productos envía los marcados', () => {
    const pagina = paginaDePrueba({
      idfamilia: { valor: '5' },
      tr_8: { clases: ['seleccionado'], datos: { idproducto: 8 } },
      tr_9: { clases: ['seleccionado'], datos: { idproducto: 9 } },
    });

    pagina.contexto.borrarProductoFamilia();

    expect(pagina.peticiones[0].data).toEqual({ pulsado: 'borrarFamiliaProducto', idfamilia: '5', idsproductos: [8, 9] });
  });

  /**
   * @etiquetas estado-actual
   *
   * @comportamiento Marcar un producto lo selecciona y enseña el botón de quitarlo; desmarcar el último
   *   lo oculta.
   */
  test('marcar un producto enseña el botón de quitarlo', () => {
    const pagina = paginaDePrueba({ 'btn-borrarfamilia': { visible: false } });

    pagina.contexto.seleccionarProductos(8);
    const tras = pagina.elementos['btn-borrarfamilia'].visible;
    pagina.contexto.seleccionarProductos(8);

    expect(tras).toBe(true);
    expect(pagina.elementos['btn-borrarfamilia'].visible).toBe(false);
  });

  /**
   * @etiquetas estado-actual
   *
   * @comportamiento Añadir una relación con la web abre una ventana para su número; guardarla envía la
   *   familia, la tienda y el número, y recarga si la comprobación es correcta.
   */
  test('relacionar con la web envía el número y recarga si se comprueba', () => {
    const pagina = paginaDePrueba({ idFamiliaTienda: { valor: '77' } }, { idTiendaWeb: 4, familia: { idFamilia: 5 } });

    pagina.contexto.HtmlRefFamiliaTienda();
    pagina.contexto.addRefFamiliaTienda();
    pagina.responder({ comprobacion: 'OK' });

    expect(pagina.contexto.abrirModal).toHaveBeenCalledWith('Añadir referencia directa de familia de tienda', expect.stringContaining('id="idFamiliaTienda"'));
    expect(pagina.peticiones[0].data).toEqual({ pulsado: 'anhadirRefTiendaWebDirecta', idFamiliaTienda: '77', idTienda: 4, idFamilia: 5 });
    expect(pagina.contexto.location.reload).toHaveBeenCalled();
  });

  /**
   * @etiquetas estado-actual
   *
   * @comportamiento Quitar una relación con la web pide confirmación, la envía y vacía su celda.
   */
  test('quitar una relación con la web la envía y vacía su celda', () => {
    const pagina = paginaDePrueba();
    const celda = { innerHTML: 'icono', parentNode: {} };

    pagina.contexto.EliminarReferenciaTienda(5, 4, { parentNode: celda });
    pagina.responder({ consulta: 'DELETE ...', error: '0' });

    expect(pagina.peticiones[0].data).toEqual({ pulsado: 'eliminarReferenciaFamiliaTienda', idFamilia: 5, idTienda: 4 });
    expect(celda.innerHTML).toBe('');
  });

  /**
   * @etiquetas esperado bajo
   * @estado rojo
   * @codigo-afectado modulos/mod_familia/funciones.js:138-160
   *
   * @que-ocurre-hoy Quitar productos de una familia, cuando va bien, termina con un error de
   *   JavaScript: tras avisar, llama a una función que no existe.
   * @que-deberia-ocurrir Que avise y deje la página con la tabla nueva, sin error.
   * @por-que-ocurre La respuesta correcta llama a `borrarFamilia()`, que no está definida.
   * @como-deberia-funcionar Quitar la llamada.
   */
  test('quitar productos, si va bien, no termina con un error', () => {
    const pagina = paginaDePrueba({ idfamilia: { valor: '5' }, tr_8: { clases: ['seleccionado'], datos: { idproducto: 8 } }, tproductos: { padre: 'panelproductos' } });
    pagina.contexto.borrarProductoFamilia();

    expect(() => pagina.responder({ html: '<table id="tproductos"></table>', error: false })).not.toThrow();
  });

  /**
   * @etiquetas esperado bajo
   * @estado rojo
   * @codigo-afectado modulos/mod_familia/funciones.js:299-324
   *
   * @que-ocurre-hoy Quitar una relación con la web vacía su celda aunque la petición no la quite.
   * @que-deberia-ocurrir Que solo la vacíe si la respuesta dice que se quitó, como pide su comentario.
   * @por-que-ocurre La respuesta no se mira: la celda se vacía en cuanto llega.
   * @como-deberia-funcionar Comprobar el error de la respuesta antes de vaciar la celda, y avisar si lo
   *   hay.
   */
  test('quitar una relación que no se quita no vacía su celda', () => {
    const pagina = paginaDePrueba();
    const celda = { innerHTML: 'icono', parentNode: {} };

    pagina.contexto.EliminarReferenciaTienda(5, 4, { parentNode: celda });
    pagina.responder({ consulta: 'DELETE ...', error: 'Error de la base' });

    expect(celda.innerHTML).toBe('icono');
  });
});

describe('el listado de familias', () => {
  /**
   * @etiquetas estado-actual
   *
   * @comportamiento Marcar para borrar una familia sin productos la selecciona, marca su casilla y
   *   enseña «Eliminar marcados»; desmarcarla lo oculta.
   */
  test('marcar una familia sin productos enseña eliminar marcados', () => {
    const pagina = paginaDePrueba({ 'fila0-3': { datos: { idfamilia: 3 } }, 'btn-eliminar': { visible: false } });

    pagina.contexto.marcarFamiliaEliminar(0, 3);
    const marcada = { fila: pagina.elementos['fila0-3'].clases.has('seleccionado'), casilla: pagina.elementos.check3.marcado, boton: pagina.elementos['btn-eliminar'].visible };
    pagina.contexto.marcarFamiliaEliminar(0, 3);

    expect(marcada).toEqual({ fila: true, casilla: true, boton: true });
    expect(pagina.elementos['btn-eliminar'].visible).toBe(false);
  });

  /**
   * @etiquetas estado-actual
   *
   * @comportamiento Una familia con productos no se marca: se avisa de que los dejaría huérfanos.
   */
  test('una familia con productos no se marca y se avisa', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.marcarFamiliaEliminar(4, 3);

    expect(pagina.contexto.alert).toHaveBeenCalledWith('Si borras esta familia dejarías huerfanos 4 productos.');
    expect(pagina.elementos['fila0-3']).toBeUndefined();
  });

  /**
   * @etiquetas estado-actual
   *
   * @comportamiento Eliminar las marcadas envía sus números y, si responde sin error, avisa y recarga;
   *   sin ninguna marcada no envía.
   */
  test('eliminar las marcadas envía sus números y recarga', () => {
    const vacia = paginaDePrueba();
    vacia.contexto.EliminarFamiliasSeleccionadas();
    const pagina = paginaDePrueba({ 'fila0-3': { clases: ['seleccionado'], datos: { idfamilia: 3 } } });

    pagina.contexto.EliminarFamiliasSeleccionadas();
    pagina.responder({ error: false, listaError: [] });

    expect(vacia.peticiones).toHaveLength(0);
    expect(pagina.peticiones[0].data).toEqual({ pulsado: 'borrarFamilias', idsfamilias: [3] });
    expect(pagina.contexto.alert).toHaveBeenCalledWith('borrado correctamente');
    expect(pagina.contexto.location.reload).toHaveBeenCalled();
  });

  /**
   * @etiquetas esperado bajo
   * @estado rojo
   * @codigo-afectado modulos/mod_familia/funciones.js:75-92
   *
   * @que-ocurre-hoy Si de las marcadas alguna no se puede borrar, avisa de un error sin decir cuál y no
   *   recarga: el listado sigue enseñando las que sí se borraron.
   * @que-deberia-ocurrir Que el listado enseñe lo que quedó y diga qué familias no se borraron.
   * @por-que-ocurre Ante un error, solo avisa; la lista de las que no se borraron, que llega en la
   *   respuesta, no se usa.
   * @como-deberia-funcionar Recargar el listado y avisar de las que no se borraron y por qué.
   */
  test('eliminar, si alguna no se borra, recarga el listado', () => {
    const pagina = paginaDePrueba({ 'fila0-3': { clases: ['seleccionado'], datos: { idfamilia: 3 } }, 'fila0-4': { clases: ['seleccionado'], datos: { idfamilia: 4 } } });

    pagina.contexto.EliminarFamiliasSeleccionadas();
    pagina.responder({ error: true, listaError: [[4, '0', '1']] });

    expect(pagina.contexto.location.reload).toHaveBeenCalled();
  });

  /**
   * @etiquetas estado-actual
   *
   * @comportamiento Expandir una familia pide sus hijas a las tareas, las pinta en su sección y cambia
   *   expandir por compactar; compactar lo deshace.
   */
  test('expandir pinta las hijas y compactar las oculta', () => {
    const pagina = paginaDePrueba({ 'botonexpandir-3': { clases: ['btn-expandir'] }, 'botoncompactar-3': { visible: false }, 'fila-3': { visible: false } });

    pagina.contexto.expandir(3);
    pagina.responder({ padre: 3, datos: [], html: '<tr>hijas</tr>' });
    const expandida = { seccion: pagina.elementos['seccion-3'].html, fila: pagina.elementos['fila-3'].visible, compactar: pagina.elementos['botoncompactar-3'].visible };
    pagina.contexto.compactar(3);

    expect(pagina.peticiones[0].data).toEqual({ pulsado: 'leerFamilias', idpadre: 3 });
    expect(expandida).toEqual({ seccion: '<tr>hijas</tr>', fila: true, compactar: true });
    expect(pagina.elementos['fila-3'].visible).toBe(false);
    expect(pagina.elementos['botonexpandir-3'].visible).toBe(true);
  });

  /**
   * @etiquetas estado-actual
   *
   * @comportamiento Expandir todo pulsa cada botón de expandir y avisa de cuántos quedan; compactar todo
   *   pulsa cada botón de compactar.
   */
  test('expandir y compactar todo pulsan sus botones', () => {
    const pulsados = [];
    const pagina = paginaDePrueba({
      'botonexpandir-1': { clases: ['btn-expandir'], alPulsar: () => pulsados.push('expandir-1') },
      'botoncompactar-2': { clases: ['btn-compactar'], alPulsar: () => pulsados.push('compactar-2') },
    });

    pagina.contexto.expandirTodos();
    pagina.contexto.compactarTodos();

    expect(pulsados).toEqual(['expandir-1', 'compactar-2']);
    expect(pagina.contexto.alert).toHaveBeenCalledWith('quedan por expandir 1');
  });

  /**
   * @etiquetas estado-actual
   *
   * @comportamiento Leer el padre de una familia pide a las tareas la orden `leerFamiliaPadre`; ninguna
   *   pantalla la llama.
   */
  test('leer el padre pide su orden a las tareas', () => {
    const pagina = paginaDePrueba();
    const alResponder = jest.fn();

    pagina.contexto.leerFamiliaPadre(3, alResponder);

    expect(pagina.peticiones[0].data).toEqual({ pulsado: 'leerFamiliaPadre', idpadre: 3 });
    expect(pagina.peticiones[0].success).toBe(alResponder);
  });
});
