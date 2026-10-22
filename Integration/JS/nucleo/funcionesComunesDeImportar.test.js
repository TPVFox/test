/**
 * `controllers/funcionesComunes.js`, que nació para cargarse siempre y hoy solo cargan las pantallas
 * de la tienda web: su barra de progreso, que escribe en un `#bar` fijo, y el ciclo de importación por
 * tramos de líneas.
 *
 * Va en su propia página de jsdom porque su `BarraProceso` se llama igual que la de
 * `controllers/global.js`, con otra firma.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const TPVFOX = path.resolve(__dirname, '../../../../TPVFox');

function cargarEnLaPagina(relativa) {
  window.eval(fs.readFileSync(path.join(TPVFOX, relativa), 'utf8'));
}

beforeAll(() => {
  jest.spyOn(console, 'log').mockImplementation(() => {});
  cargarEnLaPagina('jquery/jquery-2.2.5-pre.min.js');
  cargarEnLaPagina('controllers/funcionesComunes.js');
});

beforeEach(() => {
  document.body.innerHTML = '<div id="bar"></div>';
  window.alert = jest.fn();
  window.consultaDatos = jest.fn();
  window.ciclo = 1;
  window.clearInterval = jest.fn();
});

/**
 * @etiquetas estado-actual
 *
 * @comportamiento La barra de este fichero escribe siempre en `#bar` el porcentaje de la línea sobre
 *   el total; la línea 0 cuenta como la 1, y un total de 0 solo avisa.
 */
test('la barra escribe el porcentaje en #bar', () => {
  window.BarraProceso(0, 8);
  expect(document.getElementById('bar').innerHTML).toBe('13%');
  expect(document.getElementById('bar').style.width).toBe('13%');

  window.BarraProceso(5, 0);
  expect(window.alert).toHaveBeenCalledWith('Linea Final es 0 ');
});

/**
 * @etiquetas estado-actual
 *
 * @comportamiento Cada vuelta del ciclo pide los datos de un tramo de hasta 400 líneas y mueve la
 *   barra; cuando alcanza el total, avisa de que terminó y para el ciclo. Con un fichero que no es de
 *   referencias cruzadas ni de lista de precios, no redirige.
 */
test('el ciclo pide tramos de 400 líneas y para al llegar al total', () => {
  window.bucleProceso(1000, 0, 'catalogo.csv');
  expect(window.consultaDatos).toHaveBeenLastCalledWith(0, 400, 'catalogo.csv');
  expect(window.clearInterval).not.toHaveBeenCalled();

  window.bucleProceso(1000, 800, 'catalogo.csv');
  expect(window.consultaDatos).toHaveBeenLastCalledWith(800, 1001, 'catalogo.csv');
  expect(document.getElementById('bar').innerHTML).toBe('100%');
  expect(window.alert).toHaveBeenCalledWith('terminamos');
  expect(window.clearInterval).toHaveBeenCalledWith(1);

  window.consultaDatos.mockClear();
  window.bucleProceso(1000, 1000, 'catalogo.csv');
  expect(window.consultaDatos).not.toHaveBeenCalled();
});
