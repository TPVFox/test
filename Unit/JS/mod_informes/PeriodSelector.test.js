/**
 * El selector de periodos de los informes (`modulos/mod_informes/informes/shared/PeriodSelector.js`):
 * rellenar el número de periodo, calcular el elegido y cargar el informe fuera de la ventana de
 * consolidación, pintar la barra de periodos y navegar con ella.
 *
 * El fichero es un módulo: el caso quita su `import` y su `export` y lo ejecuta en un contexto propio de
 * Node, donde las dos funciones que importa —cargar los datos y pintar un error— son de pega, igual que
 * jQuery, que apunta las peticiones sin enviarlas, y el documento, que guarda sus elementos.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const SELECTOR = path.resolve(__dirname, '../../../../TPVFox/modulos/mod_informes/informes/shared/PeriodSelector.js');

/** Un elemento de pega: guarda su valor, su estado, su estilo y su HTML; las opciones salen del HTML. */
function elemento() {
  const el = { value: '', disabled: false, style: { display: '' }, textContent: '', className: '', options: [], escuchas: {} };
  let html = '';
  Object.defineProperty(el, 'innerHTML', {
    get: () => html,
    set: (contenido) => {
      html = contenido;
      el.options = [...contenido.matchAll(/<option value="([^"]*)"( disabled)?/g)].map((m) => ({ value: m[1], disabled: Boolean(m[2]) }));
    },
  });
  el.addEventListener = (evento, funcion) => { el.escuchas[evento] = funcion; };
  return el;
}

function paginaDePrueba({ valores = {}, ventana = 0, inactivo = false, botones = {} } = {}) {
  const pagina = { peticiones: [], cargas: [], errores: [], elementos: {}, listos: [] };
  const documento = {
    getElementById: (id) => (pagina.elementos[id] ??= elemento()),
    querySelectorAll: () => Object.entries(pagina.elementos).filter(([id]) => id.startsWith('posstockBtn_')).map(([, el]) => el),
    addEventListener: (evento, funcion) => { if (evento === 'DOMContentLoaded') { pagina.listos.push(funcion); } },
  };
  Object.entries(valores).forEach(([id, valor]) => { documento.getElementById(id).value = valor; });
  Object.entries(botones).forEach(([id, disabled]) => { documento.getElementById(id).disabled = disabled; });
  const $ = { ajax: (opciones) => { pagina.peticiones.push(opciones); } };
  pagina.window = { POSSTOCK_VENTANA_DIAS: ventana, POSSTOCK_INCLUIR_STOCK_INACTIVO: inactivo };
  const codigo = fs.readFileSync(SELECTOR, 'utf8').replace(/^import .*$/m, '').replace(/export \{[^}]*\};\s*$/, '');
  pagina.contexto = {
    $,
    document: documento,
    window: pagina.window,
    console: { log() {}, error() {} },
    cargarDatosPosstock: (...args) => { pagina.cargas.push(args); },
    _posstockMostrarError: (mensaje) => { pagina.errores.push(mensaje); },
  };
  vm.createContext(pagina.contexto);
  vm.runInContext(codigo, pagina.contexto, { filename: SELECTOR });
  pagina.responder = (indice, respuesta) => { pagina.peticiones[indice].success(JSON.stringify(respuesta)); };

  return pagina;
}

/** Hoy en la forma de las fechas de los periodos, desplazado los días que se pidan. */
function dia(desplazamiento = 0) {
  const fecha = new Date();
  fecha.setDate(fecha.getDate() + desplazamiento);

  return fecha.toISOString().slice(0, 10);
}

describe('rellenar el número de periodo', () => {
  /** @comportamiento Elegir tipo y año vacía el número y deshabilita generar, esconde el aviso y la barra, y pide las opciones con la ventana y el stock inactivo. */
  test('pide las opciones del tipo y el año, y las pinta con la primera vacía', () => {
    const pagina = paginaDePrueba({ valores: { posstockTipo: 'mes', posstockAnio: '2025' }, ventana: 14, inactivo: true });

    pagina.contexto.posstockActualizarNumero(false);
    const numero = pagina.elementos.posstockNumero;

    expect([numero.disabled, pagina.elementos.posstockBtnGenerar.disabled, pagina.elementos.posstockAvisoVentana.style.display, pagina.elementos.posstockBarraBotones.style.display]).toEqual([true, true, 'none', 'none']);
    expect(pagina.elementos.posstockLabelNumero.textContent).toBe('Periodo');
    expect(pagina.elementos.posstockFilaCasos.style.display).toBe('');
    expect(pagina.peticiones[0].data).toEqual({ pulsado: 'getOpcionesPeriodo', tipo: 'mes', anio: 2025, ventana_dias: 14, incluir_stock_inactivo: '1' });
    pagina.responder(0, { html: '<option value="1">Ene 2025</option>' });
    expect([numero.innerHTML, numero.disabled]).toEqual(['<option value="">— seleccionar —</option><option value="1">Ene 2025</option>', false]);
  });

  /** @comportamiento El tipo anual llama al número «Tipo de análisis» y esconde la fila de casos; sin tipo o sin año no pide nada; una respuesta con error no pinta nada. */
  test('el tipo anual, sin tipo y con error', () => {
    const anual = paginaDePrueba({ valores: { posstockTipo: 'anual', posstockAnio: '2025' } });
    const sinTipo = paginaDePrueba({ valores: { posstockTipo: '', posstockAnio: '2025' } });

    anual.contexto.posstockActualizarNumero(false);
    sinTipo.contexto.posstockActualizarNumero(false);
    anual.responder(0, { error: 'algo' });

    expect([anual.elementos.posstockLabelNumero.textContent, anual.elementos.posstockFilaCasos.style.display]).toEqual(['Tipo de análisis', 'none']);
    expect(anual.elementos.posstockNumero.disabled).toBe(true);
    expect(sinTipo.peticiones).toEqual([]);
  });

  /** @comportamiento Manteniendo el número, si sigue entre las opciones habilitadas se vuelve a elegir y se genera el informe; si viene deshabilitado, no. */
  test('mantener el número elegido', () => {
    const sigue = paginaDePrueba({ valores: { posstockTipo: 'mes', posstockAnio: '2025', posstockNumero: '2' } });
    const bloqueado = paginaDePrueba({ valores: { posstockTipo: 'mes', posstockAnio: '2025', posstockNumero: '2' } });

    sigue.contexto.posstockActualizarNumero(true);
    sigue.elementos.posstockNumero.value = '2';
    sigue.responder(0, { html: '<option value="1">Ene</option><option value="2">Feb</option>' });
    bloqueado.contexto.posstockActualizarNumero(true);
    bloqueado.responder(0, { html: '<option value="2" disabled>Feb</option>' });

    expect(sigue.elementos.posstockBtnGenerar.disabled).toBe(false);
    expect(sigue.peticiones[1].data.pulsado).toBe('calcularPeriodoPosstock');
    expect(bloqueado.peticiones).toHaveLength(1);
  });
});

describe('generar el informe del periodo', () => {
  const elegido = { posstockTipo: 'mes', posstockNumero: '2', posstockAnio: '2025' };

  /** @comportamiento Fuera de la ventana, calcula el periodo, lo guarda como activo, carga el informe y pide la barra con el número activo. */
  test('calcula, carga y pide la barra', () => {
    const pagina = paginaDePrueba({ valores: elegido, ventana: 7 });
    const periodo = { fecha_fin_movimientos: '2025-02-28' };

    pagina.contexto.posstockGenerar();
    pagina.responder(0, periodo);

    expect(pagina.peticiones[0].data).toEqual({ pulsado: 'calcularPeriodoPosstock', tipo: 'mes', numero: '2', anio: '2025' });
    expect(pagina.cargas).toEqual([[periodo, '']]);
    expect([pagina.window.posstockTipoActivo, pagina.window.posstockAnioActivo, pagina.window._posstockAnualEnVentana]).toEqual(['mes', 2025, false]);
    expect(pagina.elementos.posstockAvisoVentana.style.display).toBe('none');
    expect(pagina.peticiones[1].data).toEqual({ pulsado: 'getVistaBarraPeriodos', tipo: 'mes', anio: 2025, numero_activo: 2, ventana_dias: 7, en_ventana: '0', incluir_stock_inactivo: '0' });
    pagina.responder(1, { html: '<button>S1</button>' });
    expect([pagina.elementos.posstockBotonesPeriodo.innerHTML, pagina.elementos.posstockBarraBotones.style.display]).toEqual(['<button>S1</button>', '']);
  });

  /** @comportamiento Un periodo que termina dentro de la ventana enseña el aviso y no carga; el anual con el C6b, que la admite, carga y lo marca en ventana. */
  test('la ventana de consolidación', () => {
    const enVentana = paginaDePrueba({ valores: elegido, ventana: 7 });
    const c6b = paginaDePrueba({ valores: { posstockTipo: 'anual', posstockNumero: 'caso6b', posstockAnio: '2025' }, ventana: 7 });

    enVentana.contexto.posstockGenerar();
    enVentana.responder(0, { fecha_fin_movimientos: dia(-2) });
    c6b.contexto.posstockGenerar();
    c6b.responder(0, { fecha_fin_movimientos: dia(-2) });

    expect([enVentana.elementos.posstockAvisoVentana.style.display, enVentana.cargas]).toEqual(['', []]);
    expect(c6b.cargas[0][1]).toBe('caso6b');
    expect(c6b.window._posstockAnualEnVentana).toBe(true);
    expect(c6b.peticiones[1].data.en_ventana).toBe('1');
  });

  /** @comportamiento Sin tipo, número o año no pide nada; un error del cálculo o de la comunicación se pinta. */
  test('los errores', () => {
    const sinNumero = paginaDePrueba({ valores: { posstockTipo: 'mes', posstockAnio: '2025' } });
    const pagina = paginaDePrueba({ valores: elegido });

    sinNumero.contexto.posstockGenerar();
    pagina.contexto.posstockGenerar();
    pagina.responder(0, { error: 'Tipo de periodo no válido: x' });
    pagina.peticiones[0].error();

    expect(sinNumero.peticiones).toEqual([]);
    expect(pagina.errores).toEqual(['Error al calcular periodo: Tipo de periodo no válido: x', 'Error de comunicación al calcular el periodo.']);
  });
});

describe('navegar con la barra', () => {
  /**
   * @comportamiento En el tipo anual cambia el tipo de incidencia activo, resalta su botón y carga el
   *   informe del periodo activo; en la ventana, solo el C6b.
   */
  test('el tipo anual', () => {
    const pagina = paginaDePrueba({ botones: { posstockBtn_caso1: false, posstockBtn_caso2: false } });
    Object.assign(pagina.window, { posstockTipoActivo: 'anual', posstockAnioActivo: 2025, posstockPeriodoActivo: { id: 'p' } });

    pagina.contexto.posstockNavegar('caso2');
    pagina.window._posstockAnualEnVentana = true;
    pagina.contexto.posstockNavegar('caso1');

    expect(pagina.window.posstockTipoIncidenciaActivo).toBe('caso2');
    expect([pagina.elementos.posstockBtn_caso2.className, pagina.elementos.posstockBtn_caso1.className]).toEqual(['btn btn-primary btn-xs', 'btn btn-default btn-xs']);
    expect(pagina.cargas).toEqual([[{ id: 'p' }, 'caso2']]);
  });

  /**
   * @comportamiento En los demás tipos, un botón deshabilitado no navega; si no, resalta el botón, calcula
   *   el periodo y carga su informe; un error se pinta. Sin tipo activo no hace nada.
   */
  test('los demás tipos', () => {
    const pagina = paginaDePrueba({ botones: { posstockBtn_1: false, posstockBtn_2: false, posstockBtn_3: true } });
    Object.assign(pagina.window, { posstockTipoActivo: 'mes', posstockAnioActivo: 2025 });
    const sinTipo = paginaDePrueba();

    pagina.contexto.posstockNavegar(3);
    pagina.contexto.posstockNavegar(2);
    pagina.responder(0, { fecha_fin_movimientos: '2025-02-28' });
    pagina.contexto.posstockNavegar(1);
    pagina.responder(1, { error: 'Mal' });
    pagina.peticiones[1].error();
    sinTipo.contexto.posstockNavegar(1);

    expect(pagina.peticiones.map((p) => p.data.numero)).toEqual([2, 1]);
    expect(pagina.window.posstockPeriodoActivo).toEqual({ fecha_fin_movimientos: '2025-02-28' });
    expect(pagina.cargas).toEqual([[{ fecha_fin_movimientos: '2025-02-28' }]]);
    expect(pagina.errores).toEqual(['Mal', 'Error de comunicación al navegar entre periodos.']);
    expect(sinTipo.peticiones).toEqual([]);
  });
});

describe('al cargar la página', () => {
  /** @comportamiento Elegir un número de periodo habilita el botón de generar; dejarlo vacío lo deshabilita. Las tres funciones quedan en la ventana. */
  test('el botón de generar sigue al número', () => {
    const pagina = paginaDePrueba();

    pagina.listos.forEach((listo) => listo());
    const cambio = pagina.elementos.posstockNumero.escuchas.change;
    cambio.call({ value: '3' });
    const conNumero = pagina.elementos.posstockBtnGenerar.disabled;
    cambio.call({ value: '' });

    expect([conNumero, pagina.elementos.posstockBtnGenerar.disabled]).toEqual([false, true]);
    expect(['posstockActualizarNumero', 'posstockGenerar', 'posstockNavegar'].every((f) => typeof pagina.window[f] === 'function')).toBe(true);
  });
});
