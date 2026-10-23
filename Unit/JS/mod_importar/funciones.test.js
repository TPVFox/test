/**
 * El JavaScript de la importación (`modulos/mod_importar/funciones.js`): los botones de la pantalla de
 * subida que piden a las tareas borrar la tabla de paso o el registro de la última importación.
 *
 * Se ejecuta en un contexto propio de Node, con un jQuery de prueba que apunta las peticiones a las
 * tareas sin enviarlas: el caso responde por la petición. `alert` y `console` son de pega.
 */

'use strict';

const path = require('path');
const { cargarScriptGlobal } = require('../../../support/js/cargarScriptGlobal');

const FUNCIONES = path.resolve(__dirname, '../../../../TPVFox/modulos/mod_importar/funciones.js');

/** Una página de prueba: guarda las peticiones a las tareas y responde la última cuando el caso quiere. */
function paginaDePrueba() {
  const pagina = { peticiones: [] };
  const $ = {};
  $.ajax = (opciones) => { pagina.peticiones.push(opciones); };
  $.parseJSON = JSON.parse;
  pagina.contexto = cargarScriptGlobal(FUNCIONES, {
    $,
    alert: () => {},
    console: { log() {} },
  });
  pagina.ultima = () => pagina.peticiones[pagina.peticiones.length - 1];
  pagina.responder = (respuesta) => pagina.ultima().success(JSON.stringify(respuesta));

  return pagina;
}

describe('los botones de la pantalla de importación', () => {
  /** @comportamiento Pulsar «Borrar tabla» pide a las tareas borrar la tabla de paso. */
  test('borrar la tabla pide a las tareas borrar la tabla de paso', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.metodoClick('EliminarTabla');

    expect(pagina.ultima().url).toBe('tareas.php');
    expect(pagina.ultima().data).toEqual({ pulsado: 'borrar_tabla' });
  });

  /** @comportamiento Pulsar «Borrar ultimo registro» pide a las tareas borrar ese registro por su id. */
  test('borrar el último registro pide a las tareas borrar ese registro', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.metodoClick('EliminarUltimoRegistro', 7);

    expect(pagina.ultima().url).toBe('tareas.php');
    expect(pagina.ultima().data).toEqual({ pulsado: 'EliminarUltimoRegistro', id_ultimo_registro: 7 });
  });

  /**
   * @estado rojo
   * @codigo-afectado modulos/mod_importar/funciones.js:31,52
   *
   * @group defecto
   * @group importar
   * @group bajo
   *
   * @que-ocurre-hoy Cuando las tareas responden, el botón no enseña el resultado: llama a `console_log`,
   *   que no existe, de modo que la respuesta se pierde con un error en la consola.
   * @que-deberia-ocurrir Que la respuesta de las tareas se muestre o se registre sin error.
   * @por-que-ocurre El `success` del AJAX llama a `console_log(resultado)` en vez de `console.log`.
   * @como-deberia-funcionar Llamar a `console.log`, o enseñar la respuesta en la página.
   */
  test('al responder las tareas, el resultado se muestra sin error', () => {
    const pagina = paginaDePrueba();
    pagina.contexto.metodoClick('EliminarTabla');

    expect(() => pagina.responder(0)).not.toThrow();
  });

  /**
   * @estado rojo
   * @codigo-afectado modulos/mod_importar/funciones.js:7-9
   *
   * @group defecto
   * @group importar
   * @group bajo
   *
   * @que-ocurre-hoy Pulsar «Eliminar fichero» avisa de que va a eliminarlo y no pide nada: no hay petición
   *   que borre el fichero.
   * @que-deberia-ocurrir Que eliminar el fichero pida a las tareas borrarlo, o que no se ofrezca.
   * @por-que-ocurre El botón solo muestra un aviso; su rama no llama a ninguna función que envíe la orden.
   * @como-deberia-funcionar Enviar la orden de borrar el fichero, o retirar el botón.
   */
  test('eliminar el fichero pide a las tareas borrarlo', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.metodoClick('EliminarFichero');

    expect(pagina.peticiones).toHaveLength(1);
  });
});
