/**
 * El JavaScript de las tablas principales (`modulos/mod_configuracion/funciones.js`): abrir la
 * ventana de una fila de los tipos de IVA, las formas de pago o los tipos de vencimiento, y guardar lo
 * que tiene la ventana.
 *
 * Se ejecuta en un contexto propio de Node, con un jQuery de prueba que lee los campos de la ventana
 * de un mapa y guarda la petición en lugar de enviarla: así se ve qué se envía y qué se hace con la
 * respuesta.
 */

'use strict';

const path = require('path');
const { cargarScriptGlobal } = require('../../../support/js/cargarScriptGlobal');

const FUNCIONES = path.resolve(__dirname, '../../../../TPVFox/modulos/mod_configuracion/funciones.js');

/** Un jQuery mínimo: los campos salen de `campos`, y la última petición queda en `peticion`. */
function ventanaDePrueba(campos = {}) {
  const pagina = { peticion: null };
  const $ = (selector) => ({ val: () => campos[selector.replace('#', '')] });
  $.ajax = (opciones) => { pagina.peticion = opciones; };
  $.parseJSON = JSON.parse;
  pagina.contexto = cargarScriptGlobal(FUNCIONES, {
    $,
    alert: jest.fn(),
    abrirModal: jest.fn(),
    cerrarPopUp: jest.fn(),
    location: { reload: jest.fn() },
    console: { log() {} },
  });

  return pagina;
}

describe('abrir la ventana de una fila', () => {
  /**
   * @etiquetas estado-actual
   *
   * @comportamiento Pide a las tareas del módulo la ventana de la tabla y el número de fila, y la abre
   *   con el título de la tabla y el HTML recibido.
   */
  test('pide la ventana a las tareas y la abre', () => {
    const pagina = ventanaDePrueba();

    pagina.contexto.abrirmodal(3, 'iva');
    pagina.peticion.success('{"html":"<div>ventana</div>"}');

    expect(pagina.peticion.url).toBe('tareas.php');
    expect(pagina.peticion.data).toEqual({ pulsado: 'abrirModalModificar', dedonde: 'iva', id: 3 });
    expect(pagina.contexto.abrirModal).toHaveBeenCalledWith('Modificar Elemento de tabla iva', '<div>ventana</div>');
  });
});

describe('guardar la ventana', () => {
  /**
   * @etiquetas estado-actual
   *
   * @comportamiento Un tipo de IVA se envía con su descripción, su porcentaje y su recargo, y los días
   *   a 0.
   */
  test('un tipo de IVA envía su porcentaje y su recargo', () => {
    const pagina = ventanaDePrueba({ id: '2', descripcion: 'General', iva: '21', recargo: '5.2' });

    pagina.contexto.modificarTabla('iva');

    expect(pagina.peticion.data).toEqual({ pulsado: 'ModificarTabla', dedonde: 'iva', iva: '21', recargo: '5.2', id: '2', descripcion: 'General', dias: 0 });
  });

  /**
   * @etiquetas estado-actual
   *
   * @comportamiento Un tipo de vencimiento se envía con su descripción y sus días, y el porcentaje y
   *   el recargo vacíos.
   */
  test('un tipo de vencimiento envía sus días', () => {
    const pagina = ventanaDePrueba({ id: '', descripcion: 'A 30 días', dias: '30' });

    pagina.contexto.modificarTabla('vencimiento');

    expect(pagina.peticion.data).toEqual({ pulsado: 'ModificarTabla', dedonde: 'vencimiento', iva: '', recargo: '', id: '', descripcion: 'A 30 días', dias: '30' });
  });

  /**
   * @etiquetas estado-actual
   *
   * @comportamiento Sin descripción, avisa y no envía nada.
   */
  test('sin descripción avisa y no envía', () => {
    const pagina = ventanaDePrueba({ id: '', descripcion: '' });

    pagina.contexto.modificarTabla('forma');

    expect(pagina.contexto.alert).toHaveBeenCalledWith('No has escrito ninguna descripción');
    expect(pagina.peticion).toBeNull();
  });

  /**
   * @etiquetas estado-actual
   *
   * @comportamiento Si el guardado va bien, cierra la ventana y recarga la página.
   */
  test('si va bien, cierra la ventana y recarga', () => {
    const pagina = ventanaDePrueba({ id: '', descripcion: 'Contado' });
    pagina.contexto.modificarTabla('forma');

    pagina.peticion.success('{"consulta":"INSERT …","error":"0"}');

    expect(pagina.contexto.cerrarPopUp).toHaveBeenCalled();
    expect(pagina.contexto.location.reload).toHaveBeenCalled();
  });

  /**
   * @etiquetas esperado guardado bajo
   * @estado rojo
   * @codigo-afectado modulos/mod_configuracion/funciones.js:66-71
   *
   * @que-ocurre-hoy Si el guardado devuelve un error, el aviso enseña la sentencia que se intentó, no
   *   el motivo.
   * @que-deberia-ocurrir Que el aviso diga por qué no se guardó, sin la sentencia.
   * @por-que-ocurre El aviso escribe la sentencia que devuelve el guardado.
   * @como-deberia-funcionar Enseñar el error, y que el guardado no devuelva la sentencia.
   */
  test('si el guardado devuelve un error, el aviso enseña su motivo y no la sentencia', () => {
    const pagina = ventanaDePrueba({ id: '', descripcion: 'Contado' });
    pagina.contexto.modificarTabla('forma');

    pagina.peticion.success('{"consulta":"INSERT INTO `formasPago`(`descripcion`) VALUES (\'Contado\')","error":"Duplicate entry"}');

    expect(pagina.contexto.alert).toHaveBeenCalledWith(expect.stringContaining('Duplicate entry'));
    expect(pagina.contexto.alert).not.toHaveBeenCalledWith(expect.stringContaining('INSERT'));
  });

  /**
   * @etiquetas esperado guardado medio
   * @estado rojo
   * @codigo-afectado modulos/mod_configuracion/funciones.js:52-73
   *
   * @que-ocurre-hoy Si la petición de guardado falla —la base rechaza el valor y el servidor responde
   *   con un error—, la ventana sigue abierta y nadie avisa: quien guardó no sabe que no se guardó.
   * @que-deberia-ocurrir Que se avise de que no se guardó.
   * @por-que-ocurre La petición solo trata la respuesta correcta; no tiene caso de error.
   * @como-deberia-funcionar Tratar el error de la petición con un aviso.
   */
  test('si la petición falla, se avisa', () => {
    const pagina = ventanaDePrueba({ id: '', descripcion: 'Contado' });
    pagina.contexto.modificarTabla('forma');

    expect(typeof pagina.peticion.error).toBe('function');
    pagina.peticion.error({ status: 500 }, 'error', 'Internal Server Error');
    expect(pagina.contexto.alert).toHaveBeenCalled();
  });
});
