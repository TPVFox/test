/**
 * La tabla de las listas de etiquetas y del mayor de `mod_producto` (`template/view_tabla.php`, con la
 * plantilla `template/Tpl_Lista_Seleccionado.php`): lo que hace al pulsar su casilla de cabecera.
 *
 * Las dos listas cargan tres ficheros de JavaScript en su cabecera y ningún bloque propio. El caso los
 * ejecuta en un contexto propio de Node, sobre un jQuery que no hace nada, y ejecuta lo que la casilla
 * hace al pulsarla. El resto de lo que hacen las listas en el navegador es de `funciones.js`, con sus
 * propias pruebas.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const TABLA = path.resolve(__dirname, '../../../../TPVFox/modulos/mod_producto/template/view_tabla.php');
const PLANTILLA = path.resolve(__dirname, '../../../../TPVFox/modulos/mod_producto/template/Tpl_Lista_Seleccionado.php');

/** El contexto con los ficheros que la plantilla carga, sobre un jQuery que no hace nada. */
function contextoDeLasListas() {
  const nada = new Proxy(function () {}, { get: (objeto, clave) => (clave === 'ready' ? () => {} : nada), apply: () => nada });
  const contexto = vm.createContext({ $: nada, jQuery: nada, document: {}, window: {}, console: { log() {} } });
  const cargados = [...fs.readFileSync(PLANTILLA, 'utf8').matchAll(/<script src="<\?php echo \$HostNombre; \?>([^"]+)"/g)].map((ficheros) => ficheros[1]);
  cargados
    .map((fichero) => path.resolve(__dirname, '../../../../TPVFox' + fichero))
    .forEach((fichero) => vm.runInContext(fs.readFileSync(fichero, 'utf8'), contexto, { filename: fichero }));
  return { contexto, cargados };
}

describe('en las listas de etiquetas y del mayor de mod_producto', () => {
  test('la plantilla carga los tres ficheros de JavaScript de los productos', () => {
    expect(contextoDeLasListas().cargados).toEqual([
      '/modulos/mod_producto/funciones.js',
      '/modulos/mod_producto/js/AccionesDirectas.js',
      '/lib/js/tpvfoxSinExport.js',
    ]);
  });

  /**
   * @etiquetas esperado bajo
   * @estado rojo
   * @codigo-afectado modulos/mod_producto/template/view_tabla.php:4
   *
   * @que-ocurre-hoy La casilla de la cabecera de las listas de etiquetas y del mayor, la de marcar todos,
   *   no hace nada al pulsarla: la función que llama no existe.
   * @que-deberia-ocurrir Que marque o desmarque todas las casillas de la lista.
   * @por-que-ocurre La casilla llama a una función que no define ninguno de los ficheros que cargan las
   *   listas.
   * @como-deberia-funcionar Definir la función, o llamar a la que marca y desmarca las casillas de una
   *   clase.
   */
  test('la casilla de marcar todos llama a una función que existe', () => {
    const { contexto } = contextoDeLasListas();
    const alPulsar = fs.readFileSync(TABLA, 'utf8').match(/class="checkSelectTodos"[^>]*onclick="([^"]+)"/)[1];

    expect(() => vm.runInContext(alPulsar, contexto)).not.toThrow();
  });
});
