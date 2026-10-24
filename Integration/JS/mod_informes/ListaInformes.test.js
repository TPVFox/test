/**
 * El JavaScript de la lista de informes clásicos (`modulos/mod_informes/informes/classic/ListaInformesUI.js`):
 * la selección de familias para la opción 4, el catálogo de familias, la apertura del informe elegido y la
 * fluctuación del coste en la propia lista.
 *
 * El fichero es un módulo que importa las funciones comunes: se carga en una página de jsdom, sin su
 * `import` ni su `export`, con el jQuery y las funciones comunes del producto puestas donde el módulo las
 * busca. Las peticiones se apuntan sin enviarse y se responden desde el caso; la ventana modal, los avisos y
 * la pestaña nueva son de pega, y el reloj se fija donde el caso lo necesita.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const TPVFOX = path.resolve(__dirname, '../../../../TPVFox');

let peticiones;

beforeAll(() => {
  jest.spyOn(console, 'log').mockImplementation(() => {});
  window.eval(fs.readFileSync(path.join(TPVFOX, 'jquery/jquery-2.2.5-pre.min.js'), 'utf8'));
  window.eval(fs.readFileSync(path.join(TPVFOX, 'lib/js/tpvfoxSinExport.js'), 'utf8'));
  window.JSTpv = { TfObtenerCheck: window.TfObtenerCheck };
  window.eval(fs.readFileSync(path.join(TPVFOX, 'modulos/mod_informes/informes/classic/ListaInformesUI.js'), 'utf8')
    .replace(/^import .*$/m, '')
    .replace(/^export\s*\{[\s\S]*?\};\s*$/m, ''));
  window.$.ajax = (opciones) => { peticiones.push(opciones); };
  window.abrirModal = jest.fn();
  window.alert = jest.fn();
  window.open = jest.fn();
});

beforeEach(() => {
  peticiones = [];
  window.abrirModal.mockClear();
  window.alert.mockClear();
  window.open.mockClear();
  Object.keys(window._familiasSel || {}).forEach((id) => window._eliminarFamiliaInforme(id));
  document.body.innerHTML = `
    <input id="idFechaInicio" value=""><input id="idFechaFinal" value="">
    <table>
      <tr><td><input type="checkbox" class="rowCheck" id="2" name="checkUsu2" value="2"></td><td><select id="opcion2"><option value="1">1</option><option value="4">4</option></select></td></tr>
      <tr><td><input type="checkbox" class="rowCheck" id="7" name="checkUsu7" value="7"></td><td><select id="opcion7"><option value="3">3</option><option value="4">4</option></select></td></tr>
    </table>
    <div id="panelFamilias2" style="display:none"></div><div id="tagsFamilias2"></div><input type="hidden" id="familiasSel2">
    <div id="tagsFamilias4"></div><input type="hidden" id="familiasSel4">
    <input id="cfMinRecepciones" value="2"><input id="cfMinMeses" value="3"><input type="checkbox" id="cfIncluirEspecial">
    <div id="cfResumen"></div><table id="cfTablaResultados"><thead><tr id="cfTablaHeadRow"></tr></thead><tbody id="cfTablaBody"></tbody></table>`;
});

afterEach(() => {
  jest.useRealTimers();
});

function responder(indice, respuesta) {
  peticiones[indice].success(JSON.stringify(respuesta));
}

function marcar(id) {
  document.getElementById(String(id)).checked = true;
}

describe('la selección de familias', () => {
  test('la opción 4 enseña el panel de familias; las otras lo esconden', () => {
    document.getElementById('opcion2').value = '4';
    window._onOpcionChange(2);
    expect(document.getElementById('panelFamilias2').style.display).toBe('');

    document.getElementById('opcion2').value = '1';
    window._onOpcionChange(2);
    expect(document.getElementById('panelFamilias2').style.display).toBe('none');
  });

  test('cada familia elegida se pone una vez en los paneles de los informes 2, 4 y 6, y se quita', () => {
    window.agregarFamiliaInforme(3, 'Fruta');
    window.agregarFamiliaInforme(5, 'Bebida');
    window.agregarFamiliaInforme(3, 'Fruta');

    expect(document.getElementById('familiasSel2').value).toBe('3,5');
    expect(document.getElementById('familiasSel4').value).toBe('3,5');
    expect(document.getElementById('tagsFamilias2').textContent).toContain('Fruta');

    window._eliminarFamiliaInforme(3);
    expect(document.getElementById('familiasSel2').value).toBe('5');
    window._eliminarFamiliaInforme(5);
    expect(document.getElementById('tagsFamilias2').textContent).toBe('Ninguna familia seleccionada');
  });

  test('el catálogo se pide y se abre; contraer deja las de primer nivel y desplegar enseña las hijas directas', () => {
    window.abrirCatalogoFamilias();
    responder(0, { html: 'catalogo' });
    expect(peticiones[0].data).toEqual({ pulsado: 'getCatalogoFamilias' });
    expect(window.abrirModal).toHaveBeenCalledWith('Seleccionar familias', 'catalogo');

    document.body.insertAdjacentHTML('beforeend', `<table id="tablaFamiliasJerarquica">
      <tr class="FilaFamilia nivel-1" data-ruta="1"><td><span class="glyphicon glyphicon-folder-open btn-desplegar-icono" id="icono1"></span></td></tr>
      <tr class="FilaFamilia nivel-2" data-ruta="1-2"><td></td></tr>
      <tr class="FilaFamilia nivel-3" data-ruta="1-2-3"><td></td></tr></table>`);
    window.colapsarTodoCatalogo();
    const filas = document.querySelectorAll('#tablaFamiliasJerarquica tr');
    expect([filas[1].style.display, filas[2].style.display]).toEqual(['none', 'none']);
    expect(document.getElementById('icono1').classList.contains('glyphicon-folder-close')).toBe(true);

    window.toggleHijosDirectosCat(document.getElementById('icono1'));
    expect([filas[1].style.display, filas[2].style.display]).toEqual(['', 'none']);
  });

  /**
   * @estado rojo
   * @codigo-afectado modulos/mod_informes/informes/classic/ListaInformesUI.js:73-102, :104-129
   * @que-ocurre-hoy La etiqueta de una familia elegida escribe su nombre como HTML: una familia con
   *   etiquetas en el nombre las mete en la lista de informes.
   * @que-deberia-ocurrir Que el nombre sea texto en su etiqueta.
   * @por-que-ocurre El nombre se pega en el HTML del panel.
   * @como-deberia-funcionar Escapar el nombre.
   */
  test('defecto: el nombre de una familia elegida es texto en su etiqueta', () => {
    window.agregarFamiliaInforme(9, '<img src="x">Bebida');
    const imagen = document.querySelector('#tagsFamilias2 img');
    window._eliminarFamiliaInforme(9);

    expect(imagen).toBeNull();
  });
});

describe('abrir el informe elegido', () => {
  test('sin uno marcado o con dos, avisa; con la opción 4 sin familias, también', () => {
    window.metodoClick();
    marcar(2);
    marcar(7);
    window.metodoClick();
    document.getElementById('7').checked = false;
    document.getElementById('opcion2').value = '4';
    window.metodoClick();

    expect(window.alert.mock.calls.map((c) => c[0].split('\n')[0])).toEqual(['Que items tienes seleccionados? ', 'Que items tienes seleccionados? ', 'Selecciona al menos una familia antes de ejecutar el informe.']);
    expect(peticiones).toHaveLength(0);
  });

  test('con uno marcado, enseña la espera y a los cinco segundos abre el informe con su periodo, su opción y sus familias', () => {
    jest.useFakeTimers();
    marcar(2);
    document.getElementById('opcion2').value = '4';
    window.agregarFamiliaInforme(3, 'Fruta');
    document.getElementById('idFechaInicio').value = '2019-03-01';
    document.getElementById('idFechaFinal').value = '2019-03-31';

    window.metodoClick();
    responder(0, { html: 'espera' });
    expect(window.abrirModal).toHaveBeenCalledWith('Procesando', 'espera');
    jest.advanceTimersByTime(5000);

    expect(window.open).toHaveBeenCalledWith('./informes.php?id=2&Finicio=2019-03-01&Ffinal=2019-03-31&opcion=4&familias=3', '_blank');
    window._eliminarFamiliaInforme(3);
  });

  test('la fluctuación lleva además los mínimos de recepciones y de meses y el proveedor especial', () => {
    jest.useFakeTimers();
    marcar(7);
    document.getElementById('idFechaInicio').value = '2019-01-01';
    document.getElementById('idFechaFinal').value = '2019-03-31';

    window.metodoClick();
    responder(0, { html: 'espera' });
    jest.advanceTimersByTime(5000);

    expect(window.open.mock.calls[0][0]).toBe('./informes.php?id=7&Finicio=2019-01-01&Ffinal=2019-03-31&opcion=3&min_recepciones=2&min_meses=3&incluir_proveedor_especial=0');
  });

  /**
   * @estado rojo
   * @codigo-afectado modulos/mod_informes/informes/classic/ListaInformesUI.js:242-268
   * @que-ocurre-hoy Sin fecha final, la de hoy se escribe sin ceros —«2026-3-5»— y se compara como texto
   *   con la inicial: un inicio posterior a hoy en el mismo mes, como el 10 de marzo, no se avisa.
   * @que-deberia-ocurrir Que la fecha de hoy vaya como AAAA-MM-DD y un inicio posterior se avise.
   * @por-que-ocurre El mes y el día se concatenan sin rellenar con ceros.
   * @como-deberia-funcionar Rellenar con ceros, como hace la fluctuación.
   */
  test('defecto: sin fecha final, un inicio posterior a hoy se avisa', () => {
    jest.useFakeTimers().setSystemTime(new Date('2026-03-05T12:00:00'));
    marcar(2);
    document.getElementById('idFechaInicio').value = '2026-03-10';

    window.metodoClick();

    expect(window.alert).toHaveBeenCalled();
  });
});

describe('la fluctuación del coste en la lista', () => {
  test('sin fechas toma el año en curso; con el inicio posterior al final, avisa y no pide nada', () => {
    jest.useFakeTimers().setSystemTime(new Date('2026-03-05T12:00:00'));
    window.ejecutarInformeCosteFluctuacion();
    expect(peticiones[0].data).toMatchObject({ pulsado: 'getCosteFluctuacionData', fecha_inicio: '2026-01-01', fecha_final: '2026-03-05', min_recepciones: 2, min_meses: 3, incluir_proveedor_especial: 0, agrupacion: 'articulo', familias: '' });

    document.getElementById('idFechaInicio').value = '2026-04-01';
    document.getElementById('idFechaFinal').value = '2026-03-01';
    window.ejecutarInformeCosteFluctuacion();
    expect(window.alert).toHaveBeenCalledWith('La fecha inicio no puede ser posterior a la fecha final.');
    expect(peticiones).toHaveLength(1);
  });

  test('pinta el resumen y, por artículos, cada familia, subfamilia y producto con su coste de cada mes, como texto', () => {
    document.getElementById('idFechaInicio').value = '2019-01-01';
    document.getElementById('idFechaFinal').value = '2019-02-28';
    window.ejecutarInformeCosteFluctuacion();
    responder(0, { coste_fluctuacion: { resumen: { articulos_con_fluctuacion: 1, meses_rango: ['2019-01', '2019-02'] }, articulos: [
      { idArticulo: 7, articulo_name: '<b>Picual</b>', nombreN1: 'Aceite', idN1: 1, nombreN2: 'Virgen', idN2: 2, meses: [{ ym: '2019-01', coste_promedio: 2, cumple_min_recepciones: true }, { ym: '2019-02', coste_promedio: 2.5, cumple_min_recepciones: false }] },
    ], familias: [] } });

    expect(document.getElementById('cfResumen').textContent).toContain('Con fluctuacion: 1');
    expect(document.getElementById('cfTablaHeadRow').children).toHaveLength(3);
    expect(document.getElementById('cfTablaBody').textContent).toContain('<b>Picual</b>');
    expect(document.querySelector('#cfTablaBody b')).toBeNull();
    expect(document.querySelector('#cfTablaBody td[title="Recepciones por debajo del minimo"] em').textContent).toBe('2,5000');
  });

  test('el error del servidor se avisa', () => {
    window.ejecutarInformeCosteFluctuacion();
    responder(0, { error: 'Error SQL en fluctuación de costes: x' });

    expect(window.alert).toHaveBeenCalledWith('Error SQL en fluctuación de costes: x');
  });
});
