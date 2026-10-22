/**
 * El JavaScript de las tiendas (`modulos/mod_tienda/funciones.js`): las órdenes del listado —añadir
 * una tienda y abrir la marcada— y poner o quitar el obligatorio de unos campos.
 *
 * Se ejecuta en un contexto propio de Node, con un jQuery de prueba que lee las casillas del listado
 * de una lista y apunta qué campos quedan obligatorios. Su `ready` ejecuta la función en el acto,
 * como hace el jQuery que sirve el producto cuando la página ya está cargada.
 */

'use strict';

const path = require('path');
const { cargarScriptGlobal } = require('../../../support/js/cargarScriptGlobal');

const FUNCIONES = path.resolve(__dirname, '../../../../TPVFox/modulos/mod_tienda/funciones.js');

/**
 * Un listado de prueba: cada fila es `{ numero, marcada }`, con su casilla `checkUsu1`, `checkUsu2`…
 * Los campos que se hacen obligatorios quedan en `obligatorios`.
 */
function listadoDePrueba(filas = []) {
  const pagina = { obligatorios: {} };
  const casillas = {};
  filas.forEach((fila, indice) => { casillas[`input[name=checkUsu${indice + 1}]`] = fila; });
  const $ = (selector) => ({
    ready: (funcion) => funcion(),
    each: (funcion) => { if (selector === '.rowTienda') { filas.forEach((_, indice) => funcion(indice)); } },
    is: () => Boolean(casillas[selector] && casillas[selector].marcada),
    val: () => (casillas[selector] ? casillas[selector].numero : undefined),
    prop: (nombre, valor) => { pagina.obligatorios[selector.replace('#', '')] = valor; },
  });
  pagina.contexto = cargarScriptGlobal(FUNCIONES, {
    $,
    document: {},
    alert: jest.fn(),
    checkID: [],
    window: { location: { href: '' } },
    console: { log() {} },
  });

  return pagina;
}

describe('las órdenes del listado', () => {
  /**
   * @etiquetas estado-actual
   *
   * @comportamiento «Modificar» con una tienda marcada lleva a la ficha de esa tienda.
   */
  test('modificar con una tienda marcada lleva a su ficha', () => {
    const pagina = listadoDePrueba([{ numero: '1', marcada: false }, { numero: '4', marcada: true }]);

    pagina.contexto.metodoClick('VerTienda');

    expect(pagina.contexto.window.location.href).toBe('./tienda.php?id=4');
    expect(pagina.contexto.alert).not.toHaveBeenCalled();
  });

  /**
   * @etiquetas estado-actual
   *
   * @comportamiento «Modificar» sin ninguna tienda marcada, o con más de una, avisa de que hay que
   *   marcar una sola y no navega.
   */
  test('modificar sin una sola tienda marcada avisa y no navega', () => {
    const ninguna = listadoDePrueba([{ numero: '1', marcada: false }]);
    const dos = listadoDePrueba([{ numero: '1', marcada: true }, { numero: '4', marcada: true }]);

    ninguna.contexto.metodoClick('VerTienda');
    dos.contexto.metodoClick('VerTienda');

    expect(ninguna.contexto.alert).toHaveBeenCalledWith(expect.stringContaining('Solo puedes tener uno seleccionado'));
    expect(dos.contexto.alert).toHaveBeenCalledWith(expect.stringContaining('Solo puedes tener uno seleccionado'));
    expect(ninguna.contexto.window.location.href).toBe('');
    expect(dos.contexto.window.location.href).toBe('');
  });

  /**
   * @etiquetas estado-actual
   *
   * @comportamiento «Añadir» lleva a la ficha vacía, la de crear una tienda.
   */
  test('añadir lleva a la ficha de crear', () => {
    const pagina = listadoDePrueba([{ numero: '1', marcada: true }]);

    pagina.contexto.metodoClick('AgregarTienda');

    expect(pagina.contexto.window.location.href).toBe('./tienda.php');
  });

  /**
   * @etiquetas estado-actual
   *
   * @comportamiento Las casillas marcadas se reúnen en la lista global de números marcados, en el
   *   orden del listado.
   */
  test('las casillas marcadas se reúnen en la lista de marcadas', () => {
    const pagina = listadoDePrueba([{ numero: '1', marcada: true }, { numero: '2', marcada: false }, { numero: '4', marcada: true }]);

    pagina.contexto.VerTiendasSeleccionado();

    expect(pagina.contexto.checkID).toEqual(['1', '4']);
  });
});

describe('los campos obligatorios', () => {
  /**
   * @etiquetas estado-actual
   *
   * @comportamiento Se pone, o se quita, el obligatorio de los campos cuyos identificadores se dan.
   */
  test('pone y quita el obligatorio de los campos indicados', () => {
    const pagina = listadoDePrueba();

    pagina.contexto.camposFormRequired(['direccion', 'telefono']);
    pagina.contexto.camposFormQuitarRequired(['telefono']);

    expect(pagina.obligatorios).toEqual({ direccion: true, telefono: false });
  });
});
