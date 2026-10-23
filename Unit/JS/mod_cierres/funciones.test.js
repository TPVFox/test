/**
 * El JavaScript de los cierres (`modulos/mod_cierres/funciones.js`): «Aceptar» de la pantalla del cierre,
 * que envía a las tareas el cierre que la pantalla dejó en la página, y las opciones del listado —ver un
 * cierre y eliminar el último—.
 *
 * Se ejecuta en un contexto propio de Node, con un jQuery de prueba que apunta las peticiones a las
 * tareas sin enviarlas: el caso responde por la petición. `alert`, `confirm`, `console`, la dirección de
 * la página y la selección de filas del listado (`VerIdSeleccionado`, de `controllers/global.js`) son de
 * pega.
 */

'use strict';

const path = require('path');
const { cargarScriptGlobal } = require('../../../support/js/cargarScriptGlobal');

const FUNCIONES = path.resolve(__dirname, '../../../../TPVFox/modulos/mod_cierres/funciones.js');

/** Una página de prueba: guarda las peticiones y los avisos, y responde la última petición cuando el caso quiere. */
function paginaDePrueba({ seleccionados = [], confirma = true } = {}) {
  const pagina = { peticiones: [], avisos: [] };
  const $ = {};
  $.ajax = (opciones) => { pagina.peticiones.push(opciones); };
  $.parseJSON = JSON.parse;
  pagina.window = { location: { href: '' } };
  pagina.document = { location: { href: '' } };
  pagina.contexto = cargarScriptGlobal(FUNCIONES, {
    $,
    alert: (texto) => { pagina.avisos.push(String(texto)); },
    confirm: () => confirma,
    console: { log() {} },
    window: pagina.window,
    document: pagina.document,
    checkID: [],
    Ccierre: { totalcaja: 23.1, rangoTickets: ['7', '8'] },
  });
  pagina.contexto.VerIdSeleccionado = () => { pagina.contexto.checkID = seleccionados.slice(); };
  pagina.ultima = () => pagina.peticiones[pagina.peticiones.length - 1];
  pagina.responder = (respuesta) => pagina.ultima().success(JSON.stringify(respuesta));

  return pagina;
}

const GUARDADO_CORRECTO = {
  Proceso1: { insertarCierre: 'Correcto', idCierre: 5, update_estado: 'Correcto' },
  Proceso2: { insertar_ivas_cierre: 'Correcto' },
  Proceso3: { insertarTickets_usuarios: 'Correcto' },
};

describe('«Aceptar» en la pantalla del cierre', () => {
  /** @comportamiento «Aceptar» envía a las tareas, para guardarlo, el cierre que la pantalla dejó en la página. */
  test('aceptar envía a las tareas el cierre de la página', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.guardarCierreCaja();

    expect(pagina.ultima().url).toBe('tareas.php');
    expect(pagina.ultima().type).toBe('post');
    expect(pagina.ultima().data).toEqual({ datos_cierre: { totalcaja: 23.1, rangoTickets: ['7', '8'] }, pulsado: 'insertarCierre' });
  });

  /** @comportamiento Si el guardado responde cada paso sin error, la página vuelve al listado de cierres. */
  test('con el cierre guardado, vuelve al listado', () => {
    const pagina = paginaDePrueba();
    pagina.contexto.guardarCierreCaja();

    pagina.responder(GUARDADO_CORRECTO);

    expect(pagina.document.location.href).toBe('ListaCierres.php');
  });

  /**
   * @estado rojo
   * @codigo-afectado modulos/mod_cierres/funciones.js:10
   *
   * @group defecto
   * @group cierres
   * @group bajo
   *
   * @que-ocurre-hoy Al pulsar «Aceptar», antes de enviar nada, sale un aviso que solo dice «guardar».
   * @que-deberia-ocurrir Que «Aceptar» envíe el cierre sin avisos de depuración.
   * @por-que-ocurre Quedó un `alert("guardar")` al empezar la función.
   * @como-deberia-funcionar Quitar el aviso.
   */
  test('aceptar no saca un aviso de depuración', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.guardarCierreCaja();

    expect(pagina.avisos).toEqual([]);
  });

  /**
   * @estado rojo
   * @codigo-afectado modulos/mod_cierres/funciones.js:41-55
   *
   * @group defecto
   * @group cierres
   * @group medio
   *
   * @que-ocurre-hoy Si falla el guardado de los usuarios del cierre —el tercer paso—, la página no lo avisa
   *   y vuelve al listado como si el cierre se hubiera guardado entero.
   * @que-deberia-ocurrir Que un error en cualquier paso se avise y la página no dé el cierre por hecho.
   * @por-que-ocurre Se comprueba dos veces el error del segundo paso y ninguna el del tercero.
   * @como-deberia-funcionar Comprobar el error de cada paso que responde el guardado.
   */
  test('un error al guardar los usuarios se avisa y no vuelve al listado', () => {
    const pagina = paginaDePrueba();
    pagina.contexto.guardarCierreCaja();
    pagina.avisos.length = 0;

    pagina.responder({ ...GUARDADO_CORRECTO, Proceso3: { error: 'Error en Insert de cierres USUARIOS tickets Numero error:1452' } });

    expect(pagina.avisos.length).toBeGreaterThan(0);
    expect(pagina.document.location.href).toBe('');
  });

  /**
   * @estado rojo
   * @codigo-afectado modulos/mod_cierres/funciones.js:36-46
   *
   * @group defecto
   * @group cierres
   * @group medio
   *
   * @que-ocurre-hoy Si el guardado se rechaza en su primer paso —por ejemplo, porque sus tickets ya no están
   *   cobrados—, la página avisa con «[object Object]» en lugar del motivo, y después se rompe con un error
   *   al buscar el segundo paso, que la respuesta no trae.
   * @que-deberia-ocurrir Que avise con el motivo del rechazo y se quede en la pantalla sin errores.
   * @por-que-ocurre El aviso recibe el objeto del paso, no su texto de error, y se lee el error del segundo
   *   paso sin comprobar que venga.
   * @como-deberia-funcionar Avisar con `resultado.Proceso1.error` y mirar cada paso solo si viene.
   */
  test('un rechazo en el primer paso se avisa con su motivo y sin romper la página', () => {
    const pagina = paginaDePrueba();
    pagina.contexto.guardarCierreCaja();
    pagina.avisos.length = 0;

    expect(() => pagina.responder({ Proceso1: { error: 'No tienes tiquets para cerrar' } })).not.toThrow();
    expect(pagina.avisos).toContain('No tienes tiquets para cerrar');
  });
});

describe('las opciones del listado de cierres', () => {
  /** @comportamiento «Ver Cierre» con un cierre marcado abre su vista. */
  test('ver un cierre marcado abre su vista', () => {
    const pagina = paginaDePrueba({ seleccionados: ['12'] });

    pagina.contexto.metodoClick('VerCierre');

    expect(pagina.window.location.href).toBe('./VistaCierre.php?id=12');
  });

  /** @comportamiento «Ver Cierre» sin ninguno marcado, o con más de uno, avisa y no abre nada. */
  test('ver sin uno solo marcado avisa y no abre nada', () => {
    const ninguno = paginaDePrueba({ seleccionados: [] });
    const dos = paginaDePrueba({ seleccionados: ['12', '13'] });

    ninguno.contexto.metodoClick('VerCierre');
    dos.contexto.metodoClick('VerCierre');

    expect(ninguno.avisos).toHaveLength(1);
    expect(dos.avisos).toHaveLength(1);
    expect([ninguno.window.location.href, dos.window.location.href]).toEqual(['', '']);
  });

  /**
   * @comportamiento «Eliminar Cierre» con un cierre marcado pide confirmación y envía a las tareas borrarlo;
   *   sin confirmar no envía nada, y sin uno solo marcado avisa.
   */
  test('eliminar un cierre marcado pide confirmación y envía borrarlo', () => {
    const pagina = paginaDePrueba({ seleccionados: ['12'] });
    const sinConfirmar = paginaDePrueba({ seleccionados: ['12'], confirma: false });
    const sinMarcar = paginaDePrueba({ seleccionados: [] });

    pagina.contexto.metodoClick('EliminarCierre');
    sinConfirmar.contexto.metodoClick('EliminarCierre');
    sinMarcar.contexto.metodoClick('EliminarCierre');

    expect(pagina.ultima().url).toBe('tareas.php');
    expect(pagina.ultima().data).toEqual({ pulsado: 'BorrarCierre', idSeleccionado: '12' });
    expect(sinConfirmar.peticiones).toHaveLength(0);
    expect(sinMarcar.avisos).toEqual(['Debes seleccionar el ultimo cierre']);
  });

  /**
   * @estado rojo
   * @codigo-afectado modulos/mod_cierres/funciones.js:279-284
   *
   * @group defecto
   * @group cierres
   * @group medio
   *
   * @que-ocurre-hoy Si el cierre marcado no es el último, las tareas responden que no lo borran, y la página
   *   vuelve al listado sin decir nada: quien lo pidió no sabe que no se borró.
   * @que-deberia-ocurrir Que, si el borrado no se hace, se avise.
   * @por-que-ocurre La respuesta solo se escribe en la consola antes de recargar el listado.
   * @como-deberia-funcionar Mirar el estado de la respuesta y avisar si no es «Ok».
   */
  test('si el borrado no se hace, se avisa', () => {
    const pagina = paginaDePrueba({ seleccionados: ['12'] });
    pagina.contexto.metodoClick('EliminarCierre');

    pagina.responder({ estado: 'KO' });

    expect(pagina.avisos.length).toBeGreaterThan(0);
  });
});
