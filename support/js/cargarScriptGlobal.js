/**
 * Ejecuta un fichero de TPVFox escrito como script de navegador — funciones globales, sin
 * `module.exports` (CV: JavaScript global y HTML compuesto en servidor) — en un contexto
 * propio de Node, para poder probar sus funciones puras sin cargar un DOM real.
 *
 * `require()` no sirve aqui: Node envuelve cada fichero requerido en su propia funcion, asi
 * que las declaraciones `function foo(){}` de nivel superior del fichero se quedarian
 * atrapadas en ese ambito y nunca llegarian a quien lo importa. `vm.createContext()` monta
 * un objeto global aparte donde esas declaraciones se convierten en sus propiedades — y
 * donde tambien se pueden inyectar de antemano las funciones colaboradoras que el fichero
 * llama (aqui: `ponerFocus`/`ponerSelect`, que tocan DOM real y no son las que se prueban).
 */

'use strict';

const fs = require('fs');
const vm = require('vm');

/**
 * @param {string} rutaAbsoluta
 * @param {Record<string, unknown>} colaboradores Funciones/variables que el script espera
 *   encontrar como globales (mocks incluidos).
 * @returns {Record<string, unknown>} El contexto, con las funciones del script como
 *   propiedades.
 */
function cargarScriptGlobal(rutaAbsoluta, colaboradores = {}) {
  const codigo = fs.readFileSync(rutaAbsoluta, 'utf8');
  const contexto = { console, ...colaboradores };
  vm.createContext(contexto);
  vm.runInContext(codigo, contexto, { filename: rutaAbsoluta });
  return contexto;
}

module.exports = { cargarScriptGlobal };
