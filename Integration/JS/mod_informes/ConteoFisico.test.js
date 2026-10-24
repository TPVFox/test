/**
 * El JavaScript de la página de la hoja de conteo físico (`modulos/mod_informes/InformeConteoFisico.php`): la
 * elección de una familia en el catálogo, la petición de la hoja de conteo y de la hoja rápida con sus
 * filtros, su modo y su título, y lo que se enseña al volver.
 *
 * El script va dentro de la página: se toma de ella, con la dirección de la aplicación puesta donde la
 * página la escribe, y se ejecuta en una página de jsdom con el jQuery del producto, sobre los elementos de
 * la página que usa. Las peticiones se apuntan sin enviarse y se responden desde el caso; la ventana modal es
 * de pega.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const TPVFOX = path.resolve(__dirname, '../../../../TPVFox');
const SCRIPT = fs.readFileSync(path.join(TPVFOX, 'modulos/mod_informes/InformeConteoFisico.php'), 'utf8')
  .match(/<script>([\s\S]*?)<\/script>/)[1]
  .replace(/<\?php echo \$HostNombre; \?>/g, '/TPVFox');

let peticiones;

beforeAll(() => {
  window.eval(fs.readFileSync(path.join(TPVFOX, 'jquery/jquery-2.2.5-pre.min.js'), 'utf8'));
  window.$.fn.modal = jest.fn();
  window.$.post = (url, data, alVolver) => {
    const peticion = { url, data, alVolver };
    peticiones.push(peticion);
    const cadena = {
      fail(f) { peticion.alFallar = f; return cadena; },
      always(f) { peticion.alTerminar = f; return cadena; },
    };
    return cadena;
  };
});

beforeEach(() => {
  peticiones = [];
  window.$.fn.modal.mockClear();
  document.body.innerHTML = `
    <input id="cfTitulo" value="">
    <div id="cfFamiliasTags"><span id="cfFamiliaPlaceholder">Sin filtro</span></div>
    <input type="hidden" id="cfFamiliasSel" value="">
    <textarea id="cfIdsArticulos"></textarea>
    <input type="checkbox" id="cfSoloActivos" checked>
    <input type="radio" name="cfModo" id="cfModoNormal" value="normal" checked>
    <input type="radio" name="cfModo" id="cfModoCiega" value="ciega">
    <span id="cfModoDesc">Stock y conteo juntos en una sola hoja.</span>
    <button id="cfBtnGenerar"></button><div id="cfMensaje"></div>
    <input type="checkbox" id="hrMostrarStock" checked>
    <button id="hrBtnGenerar"></button><div id="hrMensaje"></div>
    <div id="hrResultadoPDF" style="display:none"><a id="hrEnlacePDF" href="#"></a></div>
    <div id="cfResultadoPDF" style="display:none"><a id="cfEnlacePDF" href="#"></a></div>
    <div id="cfModalFamilias"><div id="cfModalFamiliasBody"></div></div>`;
  window.eval(SCRIPT);
});

function responder(peticion, respuesta) {
  peticion.alVolver(respuesta);
  peticion.alTerminar();
}

describe('la hoja de conteo', () => {
  test('sin familia ni identificadores, las dos hojas avisan y no piden nada', () => {
    window.cfGenerarPDF();
    window.hrGenerarPDF();

    expect(document.getElementById('cfMensaje').textContent).toContain('Selecciona una familia o introduce IDs de artículos.');
    expect(document.getElementById('hrMensaje').textContent).toContain('Selecciona una familia o introduce IDs de artículos en el panel izquierdo.');
    expect(peticiones).toHaveLength(0);
  });

  test('con identificadores pide la hoja con el modo, el título y solo los activos, sin la familia; al volver enseña el enlace y el total', () => {
    window.agregarFamiliaInforme(3, 'Fruta');
    document.getElementById('cfIdsArticulos').value = '101, 205';
    document.getElementById('cfTitulo').value = 'Mi hoja';
    document.getElementById('cfModoCiega').checked = true;
    document.getElementById('cfSoloActivos').checked = false;

    window.cfGenerarPDF();

    expect(peticiones[0].url).toBe('/TPVFox/modulos/mod_informes/tareas.php');
    expect(peticiones[0].data).toEqual({ pulsado: 'imprimirInventarioConteoPDF', solo_activos: '0', titulo: 'Mi hoja', modo: 'ciega', ids_articulos: '101, 205' });
    expect(document.getElementById('cfBtnGenerar').disabled).toBe(true);

    responder(peticiones[0], { url: '/datos/tmp/conteo.pdf', total: 2 });

    expect(document.getElementById('cfEnlacePDF').getAttribute('href')).toBe('/datos/tmp/conteo.pdf');
    expect(document.getElementById('cfResultadoPDF').style.display).not.toBe('none');
    expect(document.getElementById('cfMensaje').textContent).toContain('2 artículo(s) incluidos en la hoja.');
    expect(document.getElementById('cfBtnGenerar').disabled).toBe(false);
  });

  test('elegir familia abre el catálogo y lo pinta; se elige una sola, que se pide, y limpiar la quita', () => {
    window.cfAbrirFamilias();
    expect(window.$.fn.modal).toHaveBeenCalledWith('show');
    expect(peticiones[0].data).toEqual({ pulsado: 'getCatalogoFamilias' });
    peticiones[0].alVolver({ html: '<table id="tablaFamiliasJerarquica"></table>' });
    expect(document.getElementById('tablaFamiliasJerarquica')).not.toBeNull();

    window.agregarFamiliaInforme(3, 'Fruta');
    window.agregarFamiliaInforme(5, 'Bebida');
    expect(document.getElementById('cfFamiliasSel').value).toBe('5');
    expect(document.querySelectorAll('#cfFamiliasTags .cf-tag')).toHaveLength(1);

    window.cfGenerarPDF();
    expect(peticiones[1].data).toEqual({ pulsado: 'imprimirInventarioConteoPDF', solo_activos: '1', titulo: '', modo: 'normal', id_familia: 5 });

    window.cfLimpiarFamilias();
    expect(document.getElementById('cfFamiliasSel').value).toBe('');
    expect(document.querySelectorAll('#cfFamiliasTags .cf-tag')).toHaveLength(0);
  });

  test('el error del servidor se avisa, y el fallo de comunicación también', () => {
    document.getElementById('cfIdsArticulos').value = '7';
    window.cfGenerarPDF();
    window.cfGenerarPDF();

    responder(peticiones[0], { error: 'No se encontraron artículos con los filtros indicados.' });
    expect(document.getElementById('cfMensaje').textContent).toContain('No se encontraron artículos con los filtros indicados.');

    peticiones[1].alFallar();
    expect(document.getElementById('cfMensaje').textContent).toContain('Error de comunicación con el servidor.');
  });

  test('cambiar el modo cambia su descripción', () => {
    document.getElementById('cfModoCiega').checked = true;
    window.$('#cfModoCiega').trigger('change');

    expect(document.getElementById('cfModoDesc').textContent).toContain('Primera sección: lista con stock (referencia interna).');
  });
});

describe('la hoja rápida', () => {
  test('pide con los filtros del panel y con el stock visible o no, y enseña su enlace y el total', () => {
    document.getElementById('cfIdsArticulos').value = '7';
    document.getElementById('hrMostrarStock').checked = false;

    window.hrGenerarPDF();

    expect(peticiones[0].data).toEqual({ pulsado: 'imprimirHojaRapidaConteoPDF', solo_activos: '1', titulo: '', mostrar_stock: '0', ids_articulos: '7' });
    responder(peticiones[0], { url: '/datos/tmp/rapida.pdf', total: 1 });
    expect(document.getElementById('hrEnlacePDF').getAttribute('href')).toBe('/datos/tmp/rapida.pdf');
    expect(document.getElementById('hrMensaje').textContent).toContain('1 artículo(s) en la hoja rápida.');
  });
});

describe('defectos', () => {
  /**
   * @estado rojo
   * @codigo-afectado modulos/mod_informes/InformeConteoFisico.php:277-296
   *
   * @group defecto
   * @group seguridad
   * @group alto
   *
   * @que-ocurre-hoy El nombre de la familia elegida se pega en su etiqueta como HTML: lo que lleve escrito
   *   se ejecuta en la página.
   * @que-deberia-ocurrir Que el nombre se pinte como texto.
   * @por-que-ocurre La etiqueta se arma concatenando el nombre en el HTML.
   * @como-deberia-funcionar Crear la etiqueta y ponerle el nombre como texto.
   */
  test('defecto: el nombre de la familia elegida es texto en su etiqueta', () => {
    window.agregarFamiliaInforme(3, '<b>Fruta</b>');

    const etiqueta = document.querySelector('#cfFamiliasTags .cf-tag');
    expect(etiqueta.querySelector('b')).toBeNull();
    expect(etiqueta.textContent).toContain('<b>Fruta</b>');
  });
});
