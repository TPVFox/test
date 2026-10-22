/**
 * El JavaScript de las incidencias (`modulos/mod_incidencias/funciones.js`): abrir la ventana de una
 * incidencia, enviarla a las tareas del módulo y abrir la ficha de la que se marca en el listado.
 *
 * Se ejecuta en un contexto propio de Node, con un jQuery de prueba que lee los valores de la ventana
 * de un mapa y guarda la petición en lugar de enviarla: así se ve qué se envía, qué se hace con la
 * respuesta y adónde se navega.
 */

'use strict';

const path = require('path');
const { cargarScriptGlobal } = require('../../../support/js/cargarScriptGlobal');

const FUNCIONES = path.resolve(__dirname, '../../../../TPVFox/modulos/mod_incidencias/funciones.js');

/** Un jQuery mínimo: los valores de los campos salen de `campos`, y la última petición queda en `peticion`. */
function paginaDePrueba(campos = {}) {
  const pagina = { peticion: null, ventana: [] };
  const $ = (selector) => ({
    val: () => campos[selector.replace('#', '')],
    modal: (orden) => pagina.ventana.push(orden),
  });
  $.ajax = (opciones) => { pagina.peticion = opciones; };
  $.parseJSON = JSON.parse;
  pagina.contexto = cargarScriptGlobal(FUNCIONES, {
    $,
    alert: jest.fn(),
    abrirModal: jest.fn(),
    VerIdSeleccionado: jest.fn(),
    checkID: [],
    location: { reload: jest.fn() },
    window: { location: { href: '' } },
    console: { log() {} },
  });

  return pagina;
}

const VENTANA = {
  inci_datos: '{"vista":"listado Incidencias"}',
  inci_dedonde: 'mod_incidencia',
  inci_estado: 'No resuelto',
  inci_mensaje: 'Falta una caja',
  numIncidencia: '0',
  usuarioSelec: '3',
};

describe('enviar una incidencia', () => {
  /**
   * @etiquetas estado-actual
   *
   * @comportamiento Guardar envía a las tareas del módulo la orden de alta con lo que tiene la
   *   ventana: los datos, el origen, el estado, el mensaje, el número y el usuario asignado.
   */
  test('envía a las tareas lo que tiene la ventana', () => {
    const pagina = paginaDePrueba(VENTANA);

    pagina.contexto.enviarIncidencia();

    expect(pagina.peticion.url).toBe('tareas.php');
    expect(pagina.peticion.type).toBe('post');
    expect(pagina.peticion.data).toEqual({
      pulsado: 'nuevaIncidencia',
      datos: VENTANA.inci_datos,
      dedonde: 'mod_incidencia',
      estado: 'No resuelto',
      mensaje: 'Falta una caja',
      numIncidencia: '0',
      usuarioSelec: '3',
    });
  });

  /**
   * @etiquetas estado-actual
   *
   * @comportamiento Si el alta va bien, se cierra la ventana; si la ventana se abrió desde la ficha
   *   de una incidencia, además se recarga la página para ver la respuesta.
   */
  test('si va bien, cierra la ventana y, desde la ficha, recarga la página', () => {
    const desdeElListado = paginaDePrueba(VENTANA);
    desdeElListado.contexto.enviarIncidencia();
    desdeElListado.peticion.success('{"id":12}');

    const desdeLaFicha = paginaDePrueba({ ...VENTANA, inci_dedonde: 'incidencia' });
    desdeLaFicha.contexto.enviarIncidencia();
    desdeLaFicha.peticion.success('{"id":13}');

    expect(desdeElListado.ventana).toEqual(['hide']);
    expect(desdeElListado.contexto.location.reload).not.toHaveBeenCalled();
    expect(desdeLaFicha.contexto.location.reload).toHaveBeenCalledWith(true);
  });
});

describe('enviar una incidencia que no se guarda', () => {
  /**
   * @etiquetas esperado guardado bajo
   * @estado rojo
   * @codigo-afectado modulos/mod_incidencias/funciones.js:28-30
   *
   * @que-ocurre-hoy Si el alta devuelve un error, el aviso sale vacío: enseña «undefined».
   * @que-deberia-ocurrir Que el aviso diga por qué no se guardó.
   * @por-que-ocurre El aviso lee el mensaje de la respuesta, y el alta lo devuelve dentro del error.
   * @como-deberia-funcionar Leer el mensaje del error.
   */
  test('si el alta devuelve un error, el aviso enseña su mensaje', () => {
    const pagina = paginaDePrueba(VENTANA);
    pagina.contexto.enviarIncidencia();

    pagina.peticion.success('{"error":{"tipo":"danger","mensaje":"No se pudo guardar"}}');

    expect(pagina.contexto.alert).toHaveBeenCalledWith(expect.stringContaining('No se pudo guardar'));
  });

  /**
   * @etiquetas esperado guardado bajo
   * @estado rojo
   * @codigo-afectado modulos/mod_incidencias/funciones.js:18-39
   *
   * @que-ocurre-hoy Si la petición de alta falla —un error del servidor—, la ventana sigue abierta y
   *   nadie avisa: quien la escribió no sabe que no se guardó.
   * @que-deberia-ocurrir Que se avise de que la incidencia no se guardó.
   * @por-que-ocurre La petición solo trata la respuesta correcta; no tiene caso de error.
   * @como-deberia-funcionar Tratar el error de la petición con un aviso.
   */
  test('si la petición falla, se avisa', () => {
    const pagina = paginaDePrueba(VENTANA);
    pagina.contexto.enviarIncidencia();

    expect(typeof pagina.peticion.error, 'La petición tiene que tratar su error').toBe('function');
  });
});

describe('abrir la ventana', () => {
  /**
   * @etiquetas estado-actual
   *
   * @comportamiento Abrir la ventana pide a las tareas su contenido con el origen, el número, la
   *   configuración del módulo y el documento, y la titula «Crear incidencia» o, al responder,
   *   «Respuesta a incidencia nº» con su número.
   */
  test('pide la ventana y la titula según sea nueva o respuesta', () => {
    const configuracion = [{ descripcion: 'mostrar_Select', valor: 'Si' }];
    const nueva = paginaDePrueba();
    nueva.contexto.abrirModalIndicencia('listado Incidencias', configuracion);
    nueva.peticion.success('{"html":"<p>ventana</p>"}');

    const respuesta = paginaDePrueba();
    respuesta.contexto.abrirModalIndicencia('incidencia', configuracion, 5, '77');
    respuesta.peticion.success('{"html":"<p>respuesta</p>"}');

    expect(respuesta.peticion.data).toEqual({ pulsado: 'abririncidencia', dedonde: 'incidencia', numIncidencia: 5, configuracion, idReal: '77' });
    expect(nueva.contexto.abrirModal).toHaveBeenCalledWith('Crear incidencia', '<p>ventana</p>');
    expect(respuesta.contexto.abrirModal).toHaveBeenCalledWith('Respuesta a incidencia nº 5', '<p>respuesta</p>');
  });
});

describe('abrir la incidencia marcada', () => {
  /**
   * @etiquetas estado-actual
   *
   * @comportamiento Con una sola incidencia marcada en el listado, «Modificar» lleva a su ficha;
   *   con ninguna o con varias, avisa y no navega.
   */
  test('lleva a la ficha de la única marcada; si no, avisa', () => {
    const una = paginaDePrueba();
    una.contexto.checkID = ['7'];
    una.contexto.metodoClick('Ver', 'incidencia');

    const varias = paginaDePrueba();
    varias.contexto.checkID = ['7', '8'];
    varias.contexto.metodoClick('Ver', 'incidencia');

    expect(una.contexto.window.location.href).toBe('./incidencia.php?id=7');
    expect(varias.contexto.window.location.href).toBe('');
    expect(varias.contexto.alert).toHaveBeenCalled();
  });
});
