/**
 * Las funciones comunes de JavaScript que no necesitan página: sacar el número de un identificador,
 * redirigir y el aviso de la barra de progreso sin total. Están repetidas en `controllers/global.js`
 * y en `lib/js/tpvfox.js` —con el prefijo `Tf`—, y `lib/js/tpvfoxSinExport.js` es la copia de este
 * último sin `export`, para las pantallas que no cargan módulos.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const { cargarScriptGlobal } = require('../../../support/js/cargarScriptGlobal');

const TPVFOX = path.resolve(__dirname, '../../../../TPVFox');
const GLOBAL = path.join(TPVFOX, 'controllers/global.js');
const TPVFOX_JS = path.join(TPVFOX, 'lib/js/tpvfox.js');
const SIN_EXPORT = path.join(TPVFOX, 'lib/js/tpvfoxSinExport.js');

describe('sacar el número de un identificador', () => {
  /**
   * @etiquetas estado-actual
   *
   * @comportamiento Quita la base del principio y devuelve lo que queda, como texto; si la base no
   *   está, devuelve -1. Las dos copias hacen lo mismo.
   */
  test.each([
    [GLOBAL, 'ObtenerIdString'],
    [SIN_EXPORT, 'TfObtenerIdString'],
  ])('%s · %s', (fichero, funcion) => {
    const contexto = cargarScriptGlobal(fichero, { console: { log() {} } });

    expect(contexto[funcion]('row', 'row12')).toBe('12');
    expect(contexto[funcion]('Unidad_Fila_', 'Unidad_Fila_3')).toBe('3');
    expect(contexto[funcion]('row', 'linea12')).toBe(-1);
  });
});

describe('redirigir', () => {
  /**
   * @etiquetas estado-actual
   *
   * @comportamiento Redirigir pone la dirección pedida en la ventana. Las dos copias hacen lo mismo.
   */
  test.each([
    [GLOBAL, 'redireccionA'],
    [SIN_EXPORT, 'TfRedireccionA'],
  ])('%s · %s', (fichero, funcion) => {
    const ventana = { location: { href: '' } };
    const contexto = cargarScriptGlobal(fichero, { window: ventana });

    contexto[funcion]('ListaProductos.php');

    expect(ventana.location.href).toBe('ListaProductos.php');
  });
});

describe('barra de progreso sin total', () => {
  /**
   * @etiquetas estado-actual
   *
   * @comportamiento Con un total de cero líneas, la barra no se toca: avisa de que la línea final
   *   es 0 y termina.
   */
  test.each([
    [GLOBAL, 'BarraProceso'],
    [SIN_EXPORT, 'TfBarraProceso'],
  ])('%s · %s', (fichero, funcion) => {
    const alert = jest.fn();
    const $ = jest.fn();
    const contexto = cargarScriptGlobal(fichero, { alert, $ });

    contexto[funcion](3, 0, 1);

    expect(alert).toHaveBeenCalledWith('Linea Final es 0 ');
    expect($).not.toHaveBeenCalled();
  });
});

describe('la copia sin export', () => {
  /**
   * @etiquetas estado-actual
   *
   * @comportamiento `tpvfoxSinExport.js` es `tpvfox.js` sin su bloque `export`: las mismas cinco
   *   funciones, línea a línea.
   */
  test('es el mismo fichero salvo el bloque export', () => {
    const conExport = fs.readFileSync(TPVFOX_JS, 'utf8');
    const sinExport = fs.readFileSync(SIN_EXPORT, 'utf8');

    expect(conExport).toMatch(/export \{TfObtenerObjetos,TfObtenerCheck,\s+TfBarraProceso,TfObtenerIdString,\s+TfRedireccionA\};/);
    expect(conExport.replace(/export \{[^}]*\};\s*$/, '').trimEnd()).toBe(sinExport.replace(/^export\s*$/m, '').trimEnd());
  });
});
