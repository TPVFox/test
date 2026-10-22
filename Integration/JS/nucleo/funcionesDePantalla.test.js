/**
 * Las funciones comunes de JavaScript que trabajan sobre la página: leer las casillas marcadas y
 * mover la barra de progreso, en `controllers/global.js` y en su copia `lib/js/tpvfoxSinExport.js`
 * —con el prefijo `Tf`—; los paneles y los filtros de tabla de `controllers/ui-helper.js`; y la
 * ventana modal de `plugins/modal/func_modal.js`.
 *
 * Se cargan en una página de jsdom con el jQuery del propio producto. El diálogo de Bootstrap se
 * sustituye por un registro de las órdenes que recibe: lo que se prueba es qué se le pide.
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
  cargarEnLaPagina('controllers/global.js');
  cargarEnLaPagina('lib/js/tpvfoxSinExport.js');
  cargarEnLaPagina('controllers/ui-helper.js');
  cargarEnLaPagina('plugins/modal/func_modal.js');
  window.$.fn.modal = function (orden) {
    this.each(function () { (this.ordenesDelDialogo = this.ordenesDelDialogo || []).push(orden); });
    return this;
  };
});

describe('casillas marcadas', () => {
  beforeEach(() => {
    document.body.innerHTML = `
      <table>
        <tr class="rowUsuario"><td><input type="checkbox" class="marca" name="checkUsu1" value="7" checked></td></tr>
        <tr class="rowUsuario"><td><input type="checkbox" class="marca" name="checkUsu2" value="8"></td></tr>
        <tr class="rowUsuario"><td><input type="checkbox" class="marca" name="checkUsu3" value="9" checked></td></tr>
      </table>`;
  });

  /**
   * @etiquetas estado-actual listado
   *
   * @comportamiento La lista de usuarios marcados deja en la variable global `checkID` los valores
   *   de las casillas marcadas, recorriendo las filas en orden. Lo hace cuando la página está lista:
   *   con la página ya cargada, en el turno siguiente.
   */
  test('la lista de usuarios marcados queda en checkID', async () => {
    window.VerIdSeleccionado();
    await new Promise((listo) => setTimeout(listo, 0));

    expect(window.checkID).toEqual(['7', '9']);
  });

  /**
   * @etiquetas estado-actual listado
   *
   * @comportamiento Leer las marcadas de una clase devuelve las casillas; su copia `Tf` devuelve sus
   *   valores, y la lista de objetos de una clase, todos.
   */
  test('las casillas marcadas de una clase, como elementos o como valores', () => {
    expect(window.leerChecked('marca').map((casilla) => casilla.value)).toEqual(['7', '9']);
    expect(window.TfObtenerCheck('marca')).toEqual(['7', '9']);
    expect(window.TfObtenerObjetos('marca')).toHaveLength(3);
  });
});

describe('barra de progreso', () => {
  /**
   * @etiquetas estado-actual
   *
   * @comportamiento La barra se pone al porcentaje de la línea sobre el total, redondeado, en ancho
   *   y en texto. La línea 0 cuenta como la 1. Las dos copias hacen lo mismo.
   */
  test.each(['BarraProceso', 'TfBarraProceso'])('%s', (funcion) => {
    document.body.innerHTML = '<div id="bar2"></div>';

    window[funcion](1, 3, 2);
    expect(document.getElementById('bar2').style.width).toBe('33%');
    expect(document.getElementById('bar2').innerHTML).toBe('33%');

    window[funcion](0, 4, 2);
    expect(document.getElementById('bar2').innerHTML).toBe('25%');
  });
});

describe('paneles', () => {
  beforeEach(() => {
    document.body.innerHTML = `
      <button id="boton" data-toggle-panel="config" data-other-panel="plus"
        data-expand-class="col-4" data-collapse-class="col-12"
        data-other-expand-class="col-8" data-other-collapse-class="col-12"
        data-text-show="Mostrar configuración" data-text-hide="Ocultar configuración">Ocultar configuración</button>
      <button id="huerfano" data-toggle-panel="noExiste">Sin panel</button>
      <div id="config" class="col-4"></div><div id="plus" class="col-8"></div>`;
    document.dispatchEvent(new window.Event('DOMContentLoaded'));
  });

  /**
   * @etiquetas estado-actual
   *
   * @comportamiento Un botón con panel oculta el panel y le pone su clase de plegado, ensancha el
   *   otro panel y cambia su propio texto; pulsado otra vez, lo vuelve a mostrar con sus clases de
   *   desplegado. Si el panel no existe, no hace nada.
   */
  test('pliega y despliega el panel y el de al lado', () => {
    const boton = document.getElementById('boton');
    const config = document.getElementById('config');
    const plus = document.getElementById('plus');

    boton.click();
    expect([config.style.display, config.className, plus.className, boton.textContent])
      .toEqual(['none', 'col-12', 'col-12', 'Mostrar configuración']);

    boton.click();
    expect([config.style.display, config.className, plus.className, boton.textContent])
      .toEqual(['', 'col-4', 'col-8', 'Ocultar configuración']);

    expect(() => document.getElementById('huerfano').click()).not.toThrow();
  });
});

describe('filtros de tabla', () => {
  beforeEach(() => {
    document.body.innerHTML = `
      <table id="t" class="tabla-filtrable">
        <thead><tr><th><a class="toggle-filtros">Filtros</a></th></tr>
        <tr class="filtros" style="display:none"><td><input class="filtro-col"></td><td><input class="filtro-col"></td></tr></thead>
        <tbody>
          <tr><td>Lomo</td><td>Cerdo</td></tr>
          <tr><td>Pechuga</td><td>Pollo</td></tr>
          <tr><td>Lomo alto</td><td>Vaca</td></tr>
        </tbody>
      </table>`;
  });

  function visibles() {
    return window.$('#t tbody tr').filter(function () { return this.style.display !== 'none'; })
      .map(function () { return window.$(this).find('td').eq(0).text(); }).get();
  }

  /**
   * @etiquetas estado-actual listado
   *
   * @comportamiento Escribir en el filtro de una columna deja solo las filas que contienen ese texto
   *   en esa columna, sin distinguir mayúsculas; los filtros de varias columnas se suman.
   */
  test('filtra las filas por columna', () => {
    const [primera, segunda] = document.querySelectorAll('.filtro-col');

    primera.value = 'LOMO';
    window.$(primera).trigger('input');
    expect(visibles()).toEqual(['Lomo', 'Lomo alto']);

    segunda.value = 'vaca';
    window.$(segunda).trigger('input');
    expect(visibles()).toEqual(['Lomo alto']);
  });

  /**
   * @etiquetas estado-actual listado
   *
   * @comportamiento El botón de filtros muestra u oculta su fila; al ocultarla vacía los filtros y
   *   vuelve a mostrar todas las filas.
   */
  test('ocultar los filtros los vacía y muestra todas las filas', () => {
    const toggle = document.querySelector('.toggle-filtros');
    const primera = document.querySelector('.filtro-col');

    window.$(toggle).trigger('click');
    primera.value = 'pech';
    window.$(primera).trigger('input');
    expect(visibles()).toEqual(['Pechuga']);

    window.$(toggle).trigger('click');
    expect(primera.value).toBe('');
    expect(visibles()).toEqual(['Lomo', 'Pechuga', 'Lomo alto']);
  });
});

describe('ventana modal', () => {
  beforeEach(() => {
    document.body.innerHTML = `
      <input id="caja">
      <div id="ventanaModal" class="modal"><h3 class="modal-title">T</h3><div class="modal-body"></div></div>`;
  });

  /**
   * @etiquetas estado-actual
   *
   * @comportamiento Abrir la ventana le pone el título y el contenido y la muestra; cerrarla la
   *   oculta. Se puede pedir que, al mostrarse, una caja tome el foco o quede seleccionada.
   */
  test('abrir, cerrar y preparar el foco', () => {
    const modal = document.getElementById('ventanaModal');

    window.abrirModal('Buscar producto', '<p>Resultados</p>');
    window.cerrarPopUp();

    expect(modal.querySelector('.modal-title').innerHTML).toBe('Buscar producto');
    expect(modal.querySelector('.modal-body').innerHTML).toBe('<p>Resultados</p>');
    expect(modal.ordenesDelDialogo).toEqual(['show', 'hide']);

    window.focusAlLanzarModal('caja');
    window.SelectAlLanzarModal('caja');
    window.$(modal).trigger('shown.bs.modal');
    expect(document.activeElement.id).toBe('caja');
  });

  /**
   * @etiquetas estado-actual
   *
   * @comportamiento Abrir una ventana con título crea una copia de la ventana base con el título en
   *   palabras capitalizadas y un identificador en minúsculas con guiones bajos; si ya existe, solo
   *   cambia su contenido. Al ocultarse, la copia se quita de la página.
   */
  test('una ventana con título se crea una vez y se reutiliza', () => {
    const id = window.abrirModalConTitulo('Buscar  proveedor', '<p>Uno</p>');
    window.abrirModalConTitulo('Buscar  proveedor', '<p>Dos</p>');
    const copia = document.getElementById(id);

    expect(id).toBe('buscar_proveedor');
    expect(document.querySelectorAll('#buscar_proveedor')).toHaveLength(1);
    expect(copia.querySelector('.modal-title').innerHTML).toBe('Buscar Proveedor');
    expect(copia.querySelector('.modal-body').innerHTML).toBe('<p>Dos</p>');
    expect(copia.ordenesDelDialogo).toEqual(['show', 'show']);

    window.cerrarPopUpConTitulo('Buscar  proveedor');
    expect(copia.ordenesDelDialogo).toEqual(['show', 'show', 'hide']);
    window.$(copia).trigger('hidden.bs.modal');
    expect(document.getElementById(id)).toBeNull();
  });
});
