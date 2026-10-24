/**
 * El JavaScript de las balanzas (`modulos/mod_balanza/funciones.js`): las opciones del listado, dar de
 * alta una balanza y su configuración, las comprobaciones de los datos, añadir y guardar teclas,
 * eliminar una balanza, crear su carpeta y el volcado completo.
 *
 * Se ejecuta en un contexto propio de Node, con un jQuery de prueba que apunta lo que se le pide —las
 * peticiones a las tareas, el HTML que se pinta y las filas que se añaden— sin una página real. `alert`,
 * `confirm`, `prompt`, la dirección de la página y la función del núcleo que da las balanzas marcadas
 * (`TfObtenerCheck`) son de pega.
 */

'use strict';

const path = require('path');
const { cargarScriptGlobal } = require('../../../support/js/cargarScriptGlobal');

const FUNCIONES = path.resolve(__dirname, '../../../../TPVFox/modulos/mod_balanza/funciones.js');

/** Un elemento de pega de jQuery: apunta lo que se le hace. */
function elemento(selector, pagina) {
  const el = {
    selector,
    length: selector in pagina.valores ? 1 : 0,
    val(valor) { if (valor === undefined) { return pagina.valores[selector] ?? ''; } pagina.valores[selector] = valor; return el; },
    is() { return pagina.marcados.includes(selector); },
    html(contenido) { pagina.pintado[selector] = contenido; return el; },
    after(contenido) { pagina.anadidas.push(contenido); return el; },
    remove() { pagina.quitadas.push(selector); return el; },
    each(funcion) { (pagina.filas[selector] || []).forEach((fila) => funcion.call(fila)); return el; },
    prop(nombre, valor) {
      if (valor === undefined) { return (pagina.estado[selector] || {})[nombre]; }
      pagina.props.push([selector, nombre, valor]);
      (pagina.estado[selector] = pagina.estado[selector] || {})[nombre] = valor;
      return el;
    },
    data(nombre, valor) {
      const datos = (pagina.datos[selector] = pagina.datos[selector] || {});
      if (valor === undefined) { return datos[nombre]; }
      datos[nombre] = valor;
      return el;
    },
    show() { pagina.visibles[selector] = true; return el; },
    hide() { pagina.visibles[selector] = false; return el; },
    toggle() { pagina.visibles[selector] = !pagina.visibles[selector]; return el; },
    add(otro) { return grupo([el, otro], pagina); },
    off() { return el; },
    on(evento, manejador) { pagina.manejadores[selector + ' ' + evento] = manejador; return el; },
    empty() { pagina.pintado[selector] = ''; return el; },
    addClass() { return el; },
    append(contenido) { pagina.pintado[selector] = (pagina.pintado[selector] || '') + contenido; return el; },
  };
  return el;
}

/** Varios elementos de pega a la vez, como los junta `add()`. */
function grupo(elementos, pagina) {
  const g = {
    off() { return g; },
    on(evento, manejador) { elementos.forEach((e) => { pagina.manejadores[e.selector + ' ' + evento] = manejador; }); return g; },
  };
  return g;
}

function paginaDePrueba({ valores = {}, marcados = [], marcadas = ['7'], confirma = true, tecla = '12' } = {}) {
  const pagina = { peticiones: [], avisos: [], pintado: {}, anadidas: [], quitadas: [], props: [], valores: { ...valores }, marcados, filas: {}, estado: {}, datos: {}, visibles: {}, manejadores: {}, modales: [], cerradas: 0, elementos: {} };
  const $ = (selector) => elemento(selector, pagina);
  $.ajax = (opciones) => { pagina.peticiones.push(opciones); };
  $.parseJSON = JSON.parse;
  pagina.window = { location: { href: 'http://tpv/modulos/mod_balanza/balanza.php?id=3', pathname: '/modulos/mod_balanza/balanza.php', reload() { pagina.recargada = true; } } };
  pagina.contexto = cargarScriptGlobal(FUNCIONES, {
    $,
    alert: (texto) => { pagina.avisos.push(String(texto)); },
    confirm: () => confirma,
    prompt: () => tecla,
    console: { log() {}, error() {} },
    window: pagina.window,
    location: pagina.window.location,
    document: {
      addEventListener() {},
      getElementById: (id) => pagina.elementos[id] || null,
      querySelectorAll: (selector) => Object.values(pagina.elementos).filter((e) => selector === '.' + e.clase),
    },
    abrirModal: (titulo, html) => { pagina.modales.push([titulo, html]); },
    cerrarPopUp: () => { pagina.cerradas++; },
    TfObtenerCheck: () => marcadas.slice(),
    plusBalanza: [{ idArticulo: '5', plu: '7' }],
  });
  pagina.ultima = () => pagina.peticiones[pagina.peticiones.length - 1];
  pagina.responder = (respuesta) => pagina.ultima().success(JSON.stringify(respuesta));

  return pagina;
}

const BALANZA = { '#nombreBalanza': 'Mostrador', '#modeloBalanza': 'Dibal 500', '#secciones': 'no', '#grupoBalanza': '1', '#direccionBalanza': '2', '#ipBalanza': '192.168.1.50' };
const CONFIGURACION = { ...BALANZA, '#modoDirectorio': 'Balctrol', '#ipPc': '192.168.1.10', '#serieH': '0', '#serieTipo': '1' };

describe('las opciones del listado de balanzas', () => {
  /** @comportamiento «Añadir» abre la ficha vacía; «Modificar», la de la balanza marcada, y sin ninguna marcada avisa. */
  test('añadir y modificar abren la ficha', () => {
    const anadir = paginaDePrueba();
    const modificar = paginaDePrueba();
    const ninguna = paginaDePrueba({ marcadas: [] });

    anadir.contexto.metodoClick('AgregarBalanza');
    modificar.contexto.metodoClick('VerBalanza', 'balanza');
    ninguna.contexto.metodoClick('VerBalanza', 'balanza');

    expect(anadir.window.location.href).toBe('./balanza.php');
    expect(modificar.window.location.href).toBe('./balanza.php?id=7');
    expect(ninguna.avisos).toEqual(['Debe seleccionar una balanza.']);
  });

  /** @comportamiento «Eliminar» pregunta antes si la balanza marcada tiene teclas: con teclas avisa y no la elimina; sin ellas, confirmado, la elimina. */
  test('eliminar pregunta antes si la balanza tiene teclas', () => {
    const conTeclas = paginaDePrueba({ confirma: false });
    const sinTeclas = paginaDePrueba();

    conTeclas.contexto.metodoClick('EliminarBalanza');
    conTeclas.responder({ tienePlus: true, mensaje: 'La balanza tiene PLUs asociados, no se puede eliminar.' });
    sinTeclas.contexto.metodoClick('EliminarBalanza');
    sinTeclas.responder({ tienePlus: false });

    expect(conTeclas.peticiones.map((p) => p.data.pulsado)).toEqual(['tienePlusAsociados']);
    expect(conTeclas.avisos).toEqual(['La balanza tiene PLUs asociados, no se puede eliminar.']);
    expect(sinTeclas.peticiones.map((p) => p.data)).toEqual([{ pulsado: 'tienePlusAsociados', idBalanza: '7' }, { pulsado: 'eliminarBalanza', idBalanza: '7' }]);
  });
});

describe('las comprobaciones de los datos de una balanza', () => {
  /** @comportamiento La IP son cuatro números de 0 a 255 sin ceros a la izquierda; grupo y dirección, de una o dos cifras; y los tres van juntos. */
  test('la IP, el grupo y la dirección', () => {
    const { contexto } = paginaDePrueba();

    expect(contexto.validarFormatoIp('192.168.4.23').Texto).toBeUndefined();
    expect(contexto.validarFormatoIp('192.168.04.23').Texto).toBeDefined();
    expect(contexto.validarFormatoIp('256.1.1.1').Texto).toBeDefined();
    expect(contexto.validarNumeroDosDigitos('12', 'Grupo').Texto).toBeUndefined();
    expect(contexto.validarNumeroDosDigitos('123', 'Grupo').Texto).toBe('El Grupo debe ser un número de 1 o 2 dígitos.');
    expect(contexto.validarGrupoDireccionIp('', '', '').Texto).toBeUndefined();
    expect(contexto.validarGrupoDireccionIp('1', '', '192.168.1.5').Texto).toBeDefined();
    expect(contexto.validarCamposBalanza('Mostrador', '', 'si').Texto).toBeDefined();
  });

  /** @comportamiento Dar de alta con datos que no pasan las comprobaciones avisa y no envía nada; con los datos bien, envía el alta. */
  test('dar de alta comprueba y envía los datos', () => {
    const mal = paginaDePrueba({ valores: { ...BALANZA, '#nombreBalanza': '', '#ipBalanza': '192.168.1' } });
    const bien = paginaDePrueba({ valores: BALANZA, marcados: ['#soloPLUS'] });

    mal.contexto.AgregarBalanza();
    bien.contexto.AgregarBalanza();

    expect(mal.peticiones).toHaveLength(0);
    expect(mal.avisos[0].split('\n')).toHaveLength(2);
    expect(bien.ultima().data).toEqual({ pulsado: 'addBalanza', nombreBalanza: 'Mostrador', modeloBalanza: 'Dibal 500', secciones: 'no', Grupo: '1', Direccion: '2', IP: '192.168.1.50', soloPLUS: 1 });
  });

  /**
   * @estado rojo
   * @codigo-afectado modulos/mod_balanza/funciones.js:72-77
   *
   * @que-ocurre-hoy Si el alta responde un error de la base, la ficha vuelve al listado igual, sin
   *   avisar: la balanza no está, y nadie lo dijo.
   * @que-deberia-ocurrir Que avise de que no se dio de alta y deje la ficha con sus datos.
   * @por-que-ocurre La respuesta se lee y no se mira.
   * @como-deberia-funcionar Mirar el error de la respuesta antes de volver al listado.
   */
  test('dar de alta, si responde un error, avisa y no vuelve al listado', () => {
    const pagina = paginaDePrueba({ valores: BALANZA });

    pagina.contexto.AgregarBalanza();
    pagina.responder({ html: '<div class="alert alert-danger"><strong>Danger!</strong> Error de sql</div>', balanza: { error: 'You have an error in your SQL syntax' } });

    expect(pagina.avisos).toHaveLength(1);
    expect(pagina.window.location.href).toBe('http://tpv/modulos/mod_balanza/balanza.php?id=3');
  });

  /** @comportamiento La configuración avanzada comprueba la IP, el grupo y la dirección antes de enviarlos, con si solo recibe teclas. */
  test('la configuración avanzada comprueba y envía los datos', () => {
    const mal = paginaDePrueba({ valores: { ...CONFIGURACION, '#grupoBalanza': 'A1' } });
    const bien = paginaDePrueba({ valores: CONFIGURACION });

    mal.contexto.guardarConfigAvanzada(3);
    bien.contexto.guardarConfigAvanzada(3);

    expect(mal.peticiones).toHaveLength(0);
    expect(mal.avisos).toEqual(['El Grupo debe ser un número de 1 o 2 dígitos.']);
    expect(bien.ultima().data).toEqual({ pulsado: 'guardarConfigAvanzada', idBalanza: 3, ipBalanza: '192.168.1.50', grupoBalanza: '1', direccionBalanza: '2', soloPLUS: 0 });
  });

  /**
   * @estado rojo
   * @codigo-afectado modulos/mod_balanza/funciones.js:793-795
   *
   * @que-ocurre-hoy Guardar la configuración avanzada no dice nada cuando responde: quien la guarda no
   *   sabe si se guardó.
   * @que-deberia-ocurrir Que diga si se guardó o no.
   * @por-que-ocurre La respuesta se lee y no se enseña.
   * @como-deberia-funcionar Enseñar el mensaje de la respuesta.
   */
  test('guardar la configuración avanzada dice si se guardó', () => {
    const pagina = paginaDePrueba({ valores: CONFIGURACION });

    pagina.contexto.guardarConfigAvanzada(3);
    pagina.responder({ mensaje: 'Configuración avanzada guardada correctamente.' });

    expect(pagina.avisos.concat(Object.values(pagina.pintado)).join(' ')).toContain('Configuración avanzada guardada correctamente.');
  });

  /** @comportamiento Crear la carpeta envía el nombre, el número, la comunicación y el modo, y vuelve al listado. */
  test('crear la carpeta envía la comunicación de la balanza', () => {
    const pagina = paginaDePrueba({ valores: CONFIGURACION });

    pagina.contexto.CrearDirectorioBalanza(3);
    pagina.responder({ balanzaCom: { mensaje: 'No hay cambios para actualizar' } });

    expect(pagina.peticiones[0].data).toEqual({ pulsado: 'crearDirectorioBalanza', nombreBalanza: 'Mostrador', idBalanza: 3, ipBalanza: '192.168.1.50', grupoBalanza: '1', direccionBalanza: '2', ipPc: '192.168.1.10', serieH: '0', serieTipo: '1', modoDirectorio: 'Balctrol' });
    expect(pagina.window.location).toBe('ListaBalanzas.php');
  });
});

describe('las teclas de una balanza', () => {
  /** @comportamiento Añadir una tecla sin producto o sin número avisa; con ellos la envía y, si responde un error, lo avisa; si no, añade su línea y vacía los campos. */
  test('añadir una tecla', () => {
    const vacia = paginaDePrueba({ valores: { '#idArticulo': '', '#plu': '7' } });
    const ocupada = paginaDePrueba({ valores: { '#idArticulo': '5', '#plu': '7', '#referencia': '1001', '#nombreProducto': 'Pera', '#seccion': '', '#precioConIva': '2.30' } });
    const libre = paginaDePrueba({ valores: { '#idArticulo': '5', '#plu': '7', '#referencia': '1001', '#nombreProducto': 'Pera', '#seccion': '', '#precioConIva': '2.30' } });

    vacia.contexto.addPlu(3);
    ocupada.contexto.addPlu(3);
    ocupada.responder({ error: 'Ya existe el producto con id:9 ese mismo plu en la balanza' });
    libre.contexto.addPlu(3);
    libre.responder({ html: '<tr id="plu_7"></tr>' });

    expect(vacia.peticiones).toHaveLength(0);
    expect(vacia.avisos).toEqual(['Quedan campos sin cubrir']);
    expect(ocupada.ultima().data).toEqual({ pulsado: 'addPlu', idArticulo: '5', plu: '7', cref: '1001', articulo_name: 'Pera', seccion: '', idBalanza: 3, pvpCiva: '2.30' });
    expect(ocupada.avisos).toEqual(['Ya existe el producto con id:9 ese mismo plu en la balanza']);
    expect(libre.anadidas).toEqual(['<tr id="plu_7"></tr>']);
    expect(libre.valores['#plu']).toBe('');
  });

  /**
   * @estado rojo
   * @codigo-afectado modulos/mod_balanza/funciones.js:661-664
   *
   * @que-ocurre-hoy Añadir un producto al peso con una tecla que ya tiene otro no avisa: la petición
   *   responde que existe, y la ficha recarga sus tablas como si se hubiera añadido.
   * @que-deberia-ocurrir Que avise de que la tecla está ocupada.
   * @por-que-ocurre La respuesta no se mira.
   * @como-deberia-funcionar Avisar del error de la respuesta, como al añadir una tecla.
   */
  test('añadir un producto al peso con una tecla ocupada avisa', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.addArticuloPeso(5, 3);
    pagina.responder({ error: 'Ya existe el producto con id:9 ese mismo plu en la balanza' });

    expect(pagina.peticiones[0].data).toEqual({ pulsado: 'addPlu', idArticulo: 5, plu: '12', idBalanza: 3 });
    expect(pagina.avisos).toEqual(['Ya existe el producto con id:9 ese mismo plu en la balanza']);
  });

  /** @comportamiento Guardar todas las teclas envía la tecla y la sección de cada fila, y recarga la página. */
  test('guardar todas envía cada fila', () => {
    const pagina = paginaDePrueba({ valores: { '#editPlu_5': '7', '#editTecla_5': '1', '#editPlu_6': '8' } });
    pagina.filas['tr[id^="plu_"]'] = [{ id: 'plu_5' }, { id: 'plu_6' }];

    pagina.contexto.guardarTodos(3);
    pagina.ultima().success('{}');

    expect(pagina.ultima().data).toEqual({ pulsado: 'guardarTodosPlus', datos: [{ idArticulo: '5', plu: '7', seccion: '1' }, { idArticulo: '6', plu: '8', seccion: null }], idBalanza: 3 });
    expect(pagina.recargada).toBe(true);
  });
});

describe('los datos de una balanza en el listado', () => {
  /** @comportamiento Pulsar una balanza la marca, pide sus datos con el orden elegido y pinta su resumen y sus teclas. */
  test('pulsar una balanza pinta sus datos', () => {
    const pagina = paginaDePrueba({ valores: { '#filtroBalanza': 'a.seccion' } });

    pagina.contexto.mostrarDatosBalanza(7);
    pagina.responder({ htmlDatosBalanza: '<p>Mostrador</p>', html: '<tr><td>7</td></tr>' });

    expect(pagina.props).toEqual([['.check_balanza', 'checked', false], ['.check_balanza[value="7"]', 'checked', true]]);
    expect(pagina.peticiones[0].data).toEqual({ pulsado: 'mostrarDatosBalanza', idBalanza: 7, filtro: 'a.seccion' });
    expect(pagina.pintado['#infoBalanza']).toBe('<p>Mostrador</p>');
    expect(pagina.pintado['.tablaPrincipal tbody']).toBe('<tr><td>7</td></tr>');
  });
});

describe('el volcado completo', () => {
  /** @comportamiento El volcado envía las teclas de la página y pinta el mensaje de la respuesta, de error o de éxito. */
  test('el volcado envía las teclas y pinta el resultado', () => {
    const fallo = paginaDePrueba();
    const exito = paginaDePrueba();

    fallo.contexto.volcadoCompleto(3);
    fallo.responder({ error: true, mensaje: 'Error grave de Comunicación' });
    exito.contexto.volcadoCompleto(3);
    exito.responder({ error: false, mensaje: 'Volcado completo realizado con éxito.' });

    expect(fallo.peticiones[0].data).toEqual({ pulsado: 'volcadoCompleto', idBalanza: 3, plusBalanza: '[{"idArticulo":"5","plu":"7"}]' });
    expect(fallo.pintado['#divAlertas']).toContain('alert-danger');
    expect(exito.pintado['#divAlertas']).toContain('alert-success');
  });
});

describe('lo que la ficha hace en la página', () => {
  /** @comportamiento Modificar una balanza comprueba sus datos, envía el nombre, el modelo y las secciones, y vuelve al listado. */
  test('modificar una balanza envía sus datos y vuelve al listado', () => {
    const mal = paginaDePrueba({ valores: { ...BALANZA, '#modeloBalanza': '' } });
    const bien = paginaDePrueba({ valores: BALANZA });

    mal.contexto.ModificarBalanza(3);
    bien.contexto.ModificarBalanza(3);
    bien.responder({ modif: { error: '0' } });

    expect(mal.peticiones).toHaveLength(0);
    expect(mal.avisos).toEqual(['Debe completar Nombre Balanza, Modelo Balanza y Secciones.']);
    expect(bien.peticiones[0].data).toEqual({ pulsado: 'modificarBalanza', idBalanza: 3, nombreBalanza: 'Mostrador', modeloBalanza: 'Dibal 500', secciones: 'no' });
    expect(bien.window.location).toBe('ListaBalanzas.php');
  });

  /** @comportamiento Quitar una tecla la pide, quita su línea y recarga la página. */
  test('quitar una tecla la pide y recarga', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.eliminarPlu('7', 3);
    pagina.responder({ success: true });

    expect(pagina.peticiones[0].data).toEqual({ pulsado: 'eliminarPlu', plu: '7', idBalanza: 3 });
    expect(pagina.quitadas).toEqual(['#plu_7']);
    expect(pagina.recargada).toBe(true);
  });

  /** @comportamiento Guardar una tecla envía su número y, si la balanza usa secciones, su sección; y recarga la página. */
  test('guardar una tecla envía su número y su sección', () => {
    const conSeccion = paginaDePrueba({ valores: { '#editPlu_5': '9', '#editTecla_5': '2' } });
    const sinSeccion = paginaDePrueba({ valores: { '#editPlu_5': '9' } });

    conSeccion.contexto.guardarPlu(5, 3);
    sinSeccion.contexto.guardarPlu(5, 3);
    sinSeccion.ultima().success('{}');

    expect(conSeccion.peticiones[0].data).toEqual({ pulsado: 'guardarPlu', idArticulo: 5, plu: '9', seccion: '2', idBalanza: 3 });
    expect(sinSeccion.peticiones[0].data.seccion).toBeNull();
    expect(sinSeccion.recargada).toBe(true);
  });

  /** @comportamiento Modificar una tecla la hace editable; al cambiarla ofrece guardar, y con Intro la guarda; cancelar devuelve su valor. */
  test('modificar una tecla la hace editable y ofrece guardarla', () => {
    const pagina = paginaDePrueba({ valores: { '#editPlu_5': '7' } });
    pagina.estado['#editPlu_5'] = { readonly: true };

    pagina.contexto.modificarPlu(5, 3);
    pagina.valores['#editPlu_5'] = '9';
    pagina.manejadores['#editPlu_5 input._pluEdit']();
    const ofreceGuardar = pagina.visibles['#guardar_5'];
    pagina.manejadores['#editPlu_5 keydown._pluEdit']({ key: 'Enter' });
    pagina.contexto.cancelarEdicionPlu(5);

    expect(ofreceGuardar).toBe(true);
    expect(pagina.peticiones[0].data).toEqual({ pulsado: 'guardarPlu', idArticulo: 5, plu: '9', seccion: null, idBalanza: 3 });
    expect(pagina.valores['#editPlu_5']).toBe('7');
    expect(pagina.estado['#editPlu_5'].readonly).toBe(true);
  });

  /** @comportamiento Modificar todas hace editables todas las teclas y ofrece guardarlas; otra vez, las deja como estaban. */
  test('modificar todas alterna la edición', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.toggleEditarTodos();
    const editando = [pagina.estado['input[id^="editPlu_"]'].readonly, pagina.visibles['#btnGuardarTodos']];
    pagina.contexto.toggleEditarTodos();

    expect(editando).toEqual([false, true]);
    expect([pagina.estado['input[id^="editPlu_"]'].readonly, pagina.visibles['#btnGuardarTodos']]).toEqual([true, false]);
  });

  /** @comportamiento Buscar un producto rellena sus datos si hay uno, o abre el buscador; elegir uno del buscador rellena sus datos y lo cierra. */
  test('buscar un producto rellena sus datos o abre el buscador', () => {
    const caja = { id_input: 'nombreProducto', darParametro: () => 'a.articulo_name' };
    const uno = paginaDePrueba({ valores: { '#nombreProducto': 'Pera' } });
    const varios = paginaDePrueba({ valores: { '#nombreProducto': 'Pe' } });

    uno.contexto.controladorAcciones(caja, 'BuscarProducto');
    uno.responder({ datos: { idArticulo: '5', nombre: 'Pera', referencia: '1001', codBarras: '8400000000017' } });
    varios.contexto.controladorAcciones(caja, 'BuscarProducto');
    varios.responder({ html: '<table></table>' });
    varios.contexto.seleccionProductoModal('6', 'Pera limonera', '1002', '', '2.50');

    expect(uno.peticiones[0].data).toEqual({ pulsado: 'buscarProducto', busqueda: 'Pera', idcaja: 'nombreProducto', campo: 'a.articulo_name' });
    expect([uno.valores['#idArticulo'], uno.valores['#referencia'], uno.valores['#codBarras']]).toEqual(['5', '1001', '8400000000017']);
    expect(varios.modales).toEqual([['Listado Productos ', '<table></table>']]);
    expect([varios.valores['#idArticulo'], varios.valores['#precioConIva'], varios.cerradas]).toEqual(['6', '2.50', 1]);
  });

  /** @comportamiento Añadir una tecla pide su formulario con las secciones de la balanza; añadir un producto al peso pide los productos al peso. */
  test('los formularios de añadir se piden y se pintan', () => {
    const tecla = paginaDePrueba({ valores: { '#secciones': 'si' } });
    const peso = paginaDePrueba();

    tecla.contexto.mostrarTablaPluAdd(3);
    tecla.responder({ html: '<th>formulario</th>' });
    tecla.contexto.toggleTablaPlus();
    peso.contexto.mostrarTablaArtPesoAdd(3);
    peso.responder({ html: '<table>peso</table>' });
    peso.contexto.toggleTablaArtPeso();

    expect(tecla.peticiones[0].data).toEqual({ pulsado: 'htmlPlu', secciones: 'si', idBalanza: 3 });
    expect([tecla.pintado['#addPlu'], tecla.visibles['#agregar'], tecla.visibles['#addPlu']]).toEqual(['<th>formulario</th>', false, false]);
    expect(peso.peticiones[0].data).toEqual({ pulsado: 'mostrarArticulosPeso', idBalanza: 3 });
    expect([peso.pintado['#addArticuloPeso'], peso.visibles['#addArticuloPeso']]).toEqual(['<table>peso</table>', false]);
  });

  /** @comportamiento Añadir un producto al peso sin escribir su tecla no envía nada; con ella, al responder, vuelve a pedir las teclas y los productos. */
  test('añadir un producto al peso sin tecla no envía nada', () => {
    const sinTecla = paginaDePrueba({ tecla: '' });
    const conTecla = paginaDePrueba();

    sinTecla.contexto.addArticuloPeso(5, 3);
    conTecla.contexto.addArticuloPeso(5, 3);
    conTecla.responder({ html: '<tr></tr>' });

    expect(sinTecla.peticiones).toHaveLength(0);
    expect(conTecla.peticiones.map((p) => p.data.pulsado)).toEqual(['addPlu', 'mostrarDatosBalanza', 'mostrarArticulosPeso']);
  });

  /** @comportamiento La configuración avanzada se abre y se cierra; la IP del PC solo se enseña en el modo «Balctrol»; y el botón de ocultar la lista aparece al pulsar una balanza. */
  test('el panel de la configuración y la IP del PC', () => {
    const pagina = paginaDePrueba();
    pagina.elementos = {
      panelConfigAvanzada: { style: {} },
      modoDirectorio: { value: 'automatico' },
      ipPc: { clase: 'ipPcGroup', style: {} },
      btnOcultarLista: { style: { display: 'none' } },
    };

    pagina.contexto.abrirConfigAvanzada();
    const abierto = pagina.elementos.panelConfigAvanzada.style.display;
    pagina.contexto.cerrarConfigAvanzada();
    pagina.contexto.toggleIpPcInput();
    const automatico = pagina.elementos.ipPc.style.display;
    pagina.elementos.modoDirectorio.value = 'Balctrol';
    pagina.contexto.toggleIpPcInput();
    pagina.contexto.mostrarBotonOcultarLista();

    expect([abierto, pagina.elementos.panelConfigAvanzada.style.display]).toEqual(['block', 'none']);
    expect([automatico, pagina.elementos.ipPc.style.display]).toEqual(['none', '']);
    expect(pagina.elementos.btnOcultarLista.style.display).toBe('');
  });
});

