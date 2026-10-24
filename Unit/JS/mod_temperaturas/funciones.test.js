/**
 * El JavaScript del análisis de temperaturas (`modulos/mod_temperaturas/funciones.js`): el botón que
 * cambia entre el desglose por desviaciones y la temperatura directa.
 *
 * El fichero se ejecuta en un contexto propio de Node, con un documento de prueba que guarda la
 * visibilidad de cada celda y el texto del botón; el caso dispara la carga de la página y las
 * pulsaciones.
 */

'use strict';

const path = require('path');
const { cargarScriptGlobal } = require('../../../support/js/cargarScriptGlobal');

const FUNCIONES = path.resolve(__dirname, '../../../../TPVFox/modulos/mod_temperaturas/funciones.js');

/** Un análisis de prueba: dos celdas del desglose, visibles, y una de temperatura directa, oculta. */
function analisisDePrueba() {
  const celda = (visible) => ({ style: { display: visible ? '' : 'none' } });
  const analisis = {
    alCargar: [],
    desglose: [celda(true), celda(true)],
    directa: [celda(false)],
    boton: { textContent: 'Ver temperatura directa', alPulsar: null },
  };
  analisis.boton.addEventListener = (evento, funcion) => { analisis.boton.alPulsar = funcion; };
  const documento = {
    addEventListener: (evento, funcion) => { if (evento === 'DOMContentLoaded') analisis.alCargar.push(funcion); },
    getElementById: (id) => (id === 'toggleVista' ? analisis.boton : null),
    querySelectorAll: (selector) => ({ '.ds': analisis.desglose, '.temperatura-directa': analisis.directa }[selector] || []),
  };
  cargarScriptGlobal(FUNCIONES, { document: documento });
  analisis.alCargar.forEach((funcion) => funcion());
  analisis.pulsar = () => analisis.boton.alPulsar.call(analisis.boton);
  analisis.visibles = () => [...analisis.desglose, ...analisis.directa].map((c) => c.style.display !== 'none');

  return analisis;
}

describe('en el análisis de temperaturas', () => {
  test('el botón cambia al valor directo, oculta el desglose y se ofrece para volver', () => {
    const analisis = analisisDePrueba();

    analisis.pulsar();

    expect(analisis.visibles()).toEqual([false, false, true]);
    expect(analisis.boton.textContent).toBe('Ver desglose por DS');
  });

  test('pulsado otra vez, vuelve al desglose por desviaciones', () => {
    const analisis = analisisDePrueba();

    analisis.pulsar();
    analisis.pulsar();

    expect(analisis.visibles()).toEqual([true, true, false]);
    expect(analisis.boton.textContent).toBe('Ver temperatura directa');
  });
});
