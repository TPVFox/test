/**
 * Las 5 funciones puras de `js/AccionesDirectas.js` (el resto está acoplado a DOM real,
 * AJAX o estado global de página, y se prueba en E2E — ver PCP-TPY-compartida §12.1).
 */

'use strict';

const path = require('path');
const { cargarScriptGlobal } = require('../../../support/js/cargarScriptGlobal');

const RUTA_SCRIPT = path.resolve(__dirname, '../../../../TPVFox/modulos/mod_venta/js/AccionesDirectas.js');

describe('comprobarNumero', () => {
  const ctx = cargarScriptGlobal(RUTA_SCRIPT);

  test.each([
    ['12.5', true],
    ['0.5', true],
    ['-3.20', true],
    ['10', true],
    ['1234567890.5', true],
    ['abc', false],
  ])('comprobarNumero(%j) => %p', (valor, esperado) => {
    expect(ctx.comprobarNumero(valor)).toBe(esperado);
  });

  /**
   * Defecto: un "0" suelto, o cualquier cadena que empiece por "0" sin punto decimal
   * justo detrás, se rechaza como numero invalido.
   *
   * Sintoma: `comprobarNumero("0")` y `comprobarNumero("05")` devuelven `false`, pese a
   * que "0" es un numero valido segun la propia expresion regular de la funcion
   * (`^\-?\d*\.?\d*$`, que "0" cumple). Causa raiz: antes de aplicar esa expresion, la
   * funcion comprueba `valor.substr(-10, 1) === "0"` y, si es cierto, exige que
   * `valor.substr(-10, 2) === "0."` o rechaza. Para una cadena de menos de 10 caracteres
   * —cualquier cantidad o precio real de caja— `substr(-10, ...)` no llega a los 10
   * caracteres hacia atras y JavaScript lo trata como si empezara en la posicion 0: el
   * guarda mira el principio de la cadena, con la intencion (a juzgar por el nombre y por
   * que "0.5" si pasa) de exigir que un "0" inicial vaya seguido de un punto. Para el "0"
   * suelto no hay segundo caracter que comprobar: `substr(0, 2)` de una cadena de un solo
   * caracter solo puede devolver ese caracter, nunca "0.", asi que la comparacion falla
   * siempre. Impacto real: `AccionesDirectas.js` llama a esta funcion desde
   * `recalcular_totalProducto` y `recalcular_precioSiva` (el salto de caja tras teclear
   * cantidad o precio); un cajero que intente poner una cantidad o un precio exactamente a
   * cero recibe "No es correcto el numero" y el campo se resetea. Corrección propuesta:
   * comprobar los dos primeros caracteres de la cadena directamente (`valor.charAt(0)` y
   * `valor.charAt(1)`), no una posicion relativa a una longitud fija de 10. Evidencia: este
   * test, en rojo mientras el defecto siga sin corregirse por CC.
   */
  test.each([
    ['0'],
    ['05'],
  ])('defecto: comprobarNumero(%j) deberia ser un numero valido', (valor) => {
    expect(ctx.comprobarNumero(valor)).toBe(true);
  });
});

describe('ObtenerCajaSiguiente', () => {
  const ctx = cargarScriptGlobal(RUTA_SCRIPT);

  test.each([
    ['idArticulo', 'Referencia'],
    ['Referencia', 'Codbarras'],
    ['Codbarras', 'Descripcion'],
    ['Descripcion', ''],
  ])('ObtenerCajaSiguiente(%j) => %j', (idCaja, esperado) => {
    expect(ctx.ObtenerCajaSiguiente(idCaja)).toBe(esperado);
  });
});

describe('after_constructor', () => {
  const ctx = cargarScriptGlobal(RUTA_SCRIPT);

  test('sustituye id_input por el id real del evento cuando empieza por N_', () => {
    const padre = { id_input: 'N_5' };
    const resultado = ctx.after_constructor(padre, { target: { id: 'N_7' } });

    expect(resultado.id_input).toBe('N_7');
  });

  test('sustituye id_input cuando empieza por Unidad_Fila', () => {
    const padre = { id_input: 'Unidad_Fila_2' };
    const resultado = ctx.after_constructor(padre, { target: { id: 'Unidad_Fila_9' } });

    expect(resultado.id_input).toBe('Unidad_Fila_9');
  });

  test('deja id_input intacto cuando no coincide con ninguno de los tres prefijos', () => {
    const padre = { id_input: 'cajaBusqueda' };
    const resultado = ctx.after_constructor(padre, { target: { id: 'otroId' } });

    expect(resultado.id_input).toBe('cajaBusqueda');
  });
});

describe('mover_up y mover_down', () => {
  /** @returns {{ctx: object, llamadas: Array<[string, ...unknown[]]>}} */
  function contextoConMocks() {
    const ctx = cargarScriptGlobal(RUTA_SCRIPT);
    const llamadas = [];
    ctx.ponerFocus = (...args) => llamadas.push(['ponerFocus', ...args]);
    ctx.ponerSelect = (...args) => llamadas.push(['ponerSelect', ...args]);
    return { ctx, llamadas };
  }

  test('mover_up con prefijo Unidad_Fila_ selecciona en vez de enfocar', () => {
    const { ctx, llamadas } = contextoConMocks();

    ctx.mover_up(3, 'Unidad_Fila_');

    expect(llamadas).toEqual([['ponerSelect', 'Unidad_Fila_3']]);
  });

  test('mover_up con otro prefijo enfoca', () => {
    const { ctx, llamadas } = contextoConMocks();

    ctx.mover_up(3, 'otroPrefijo_');

    expect(llamadas).toEqual([['ponerFocus', 'otroPrefijo_3']]);
  });

  test('mover_down con prefijo Unidad_Fila_ selecciona en vez de enfocar', () => {
    const { ctx, llamadas } = contextoConMocks();

    ctx.mover_down(3, 'Unidad_Fila_');

    expect(llamadas).toEqual([['ponerSelect', 'Unidad_Fila_3']]);
  });

  test('mover_down con otro prefijo enfoca', () => {
    const { ctx, llamadas } = contextoConMocks();

    ctx.mover_down(3, 'otroPrefijo_');

    expect(llamadas).toEqual([['ponerFocus', 'otroPrefijo_3']]);
  });
});
