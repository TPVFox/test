/**
 * El JavaScript de la pantalla de POSStock (`modulos/mod_informes/informes/posstock/`): la carga por lotes
 * y el pintado de la tabla, los cruces del C7 y el completado de las filas, la configuración, los filtros
 * de familias y proveedores, la exportación, la ordenación, el agrupado por proveedor y el filtro por
 * etiquetas.
 *
 * Los siete ficheros son módulos que se importan entre sí: se cargan juntos en una página de jsdom, sin sus
 * `import` ni sus `export`, con el jQuery del propio producto. Las peticiones se apuntan sin enviarse y se
 * responden desde el caso; la ventana modal, la apertura de una pestaña y el envío de un formulario son de
 * pega.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const TPVFOX = path.resolve(__dirname, '../../../../TPVFox');
const MODULOS = ['PosstockTabla', 'PosstockC7cde', 'PosstockEnriquecedor', 'PosstockLoader', 'PosstockConfig', 'PosstockFiltros', 'PosstockExport'];

let peticiones;
let enviados;

beforeAll(() => {
  jest.spyOn(console, 'log').mockImplementation(() => {});
  window.eval(fs.readFileSync(path.join(TPVFOX, 'jquery/jquery-2.2.5-pre.min.js'), 'utf8'));
  const codigo = MODULOS.map((m) => fs.readFileSync(path.join(TPVFOX, 'modulos/mod_informes/informes/posstock', m + '.js'), 'utf8')
    .replace(/^import[\s\S]*?from\s+"[^"]+";\s*$/gm, '')
    .replace(/^export\s*\{[\s\S]*?\};\s*$/gm, '')).join('\n');
  window.eval(codigo);
  window.$.ajax = (opciones) => { peticiones.push(opciones); };
  window.abrirModal = jest.fn();
  window.cerrarModal = jest.fn();
  window.posstockActualizarNumero = jest.fn();
  window.open = jest.fn();
  HTMLFormElement.prototype.submit = function () { enviados.push(Object.fromEntries(new FormData(this))); };
});

beforeEach(() => {
  peticiones = [];
  enviados = [];
  localStorage.clear();
  window.abrirModal.mockClear();
  window.cerrarModal.mockClear();
  window.posstockActualizarNumero.mockClear();
  window.open.mockClear();
  Object.assign(window, { posstockFamiliasIncluir: [], posstockFamiliasExcluir: [], posstockProveedoresIncluir: [], posstockProveedorTodosProductos: false, posstockTipoActivo: 'mes', posstockPeriodoActivo: null, posstockTipoIncidenciaActivo: '', _posstockBadgeStates: undefined, _posstockFilasVisibles: undefined });
  document.body.innerHTML = `
    <div id="posstockSpinner"></div><div id="posstockProgreso"></div><div id="posstockNavegacion"></div><div id="posstockBotonesWrap"></div>
    <span id="posstockLabelMovimientos"></span><span id="posstockLabelStock"></span>
    <div id="posstockFilaCasos">
      <input type="checkbox" id="posstockChk_caso1" value="caso1" checked>
      <input type="checkbox" id="posstockChk_caso2" value="caso2">
      <input type="checkbox" id="posstockChk_caso7a" value="caso7a">
      <input type="checkbox" id="posstockChk_caso7b" value="caso7b">
    </div>
    <div id="posstockTablaWrap"></div>
    <button id="posstockBtnExportar" style="display:none"></button><button id="posstockBtnImprimir" style="display:none"></button><button id="posstockBtnConteo" style="display:none"></button>
    <span id="posstockFiltroLabel"></span><span id="posstockProveedorLabel"></span><span id="posstockAgruparProvLabel"></span>`;
});

const PERIODO = { fecha_inicio_movimientos: '2019-03-01', fecha_fin_movimientos: '2019-03-31', fecha_inicio_stock: '2019-01-01', fecha_fin_stock: '2019-02-28', min_ventas_c5: 7, label_movimientos: 'Marzo 2019', label_stock: 'Stock base' };

function responder(indice, respuesta) {
  peticiones[indice].success(JSON.stringify(respuesta));
}

/** Una tabla pintada como la del servidor, con una fila por cada [id, orden, proveedor, etiquetas, coste]. */
function tabla(filas) {
  document.getElementById('posstockTablaWrap').innerHTML = '<table id="posstockTabla"><tbody>'
    + filas.map(([id, orden, prov, etiquetas, coste]) => `<tr data-tipo="Inventario en negativo" data-badges="${etiquetas}" data-orden="${orden}" data-prov="${prov}" data-coste="${coste}" data-idarticulo="${id}"><td>${id}</td></tr>`).join('')
    + '</tbody></table>';
}

const ids = () => Array.from(document.querySelectorAll('#posstockTabla tbody tr[data-idarticulo]')).map((tr) => tr.dataset.idarticulo);

describe('cargar las incidencias', () => {
  test('pide los lotes con los filtros y los casos, sigue mientras llegan llenos y pinta la tabla en orden', () => {
    window.posstockFamiliasIncluir = [{ id: 3, nombre: 'Fruta' }];
    window.posstockProveedoresIncluir = [{ id: 7, nombre: 'Frutas SL' }];

    window.cargarDatosPosstock(PERIODO);
    expect(peticiones[0].data).toMatchObject({ pulsado: 'getPOSStockBatch', casos_incluir: 'caso1,caso7b', familias_incluir: '3', proveedores_incluir: '7', proveedor_todos_productos: '0', inicial: 0, pagina: 250, tipo_periodo: 'mes', min_ventas_c5: 7 });
    responder(0, { filas: [{ idArticulo: 2, orden_clave: '1', tipo: 'Entrada con stock alto' }], actual: 250, elementos: 250, pagina_efectiva: 250 });
    expect(peticiones[1].data.inicial).toBe(250);
    responder(1, { filas: [{ idArticulo: 1, orden_clave: '0', tipo: 'Inventario en negativo' }], actual: 251, elementos: 1, pagina_efectiva: 250 });

    expect(peticiones[2].data).toMatchObject({ pulsado: 'renderizarTablaPosstock', modo: 'completo', fecha_fin_movimientos: '2019-03-31' });
    expect(JSON.parse(peticiones[2].data.filas_json).map((f) => f.idArticulo)).toEqual([1, 2]);
    responder(2, { html: '<table id="posstockTabla"><tbody><tr data-badges="" data-idarticulo="1"><td>1</td></tr></tbody></table>' });
    expect(document.querySelector('#posstockTablaWrap table')).not.toBeNull();
    expect(document.getElementById('posstockBtnExportar').style.display).not.toBe('none');
    expect(document.getElementById('posstockLabelMovimientos').textContent).toBe('Marzo 2019');
  });

  test('pinta la tabla en tandas de 150 filas: la primera entera, las siguientes solo filas', () => {
    window.cargarDatosPosstock(PERIODO);
    responder(0, { filas: Array.from({ length: 160 }, (_, i) => ({ idArticulo: i + 1, orden_clave: String(1000 + i) })), actual: 160, elementos: 160, pagina_efectiva: 250 });

    expect(JSON.parse(peticiones[1].data.filas_json)).toHaveLength(150);
    responder(1, { html: '<table id="posstockTabla"><tbody></tbody></table>' });
    expect(peticiones[2].data).toMatchObject({ modo: 'filas' });
    expect(JSON.parse(peticiones[2].data.filas_json)).toHaveLength(10);
    responder(2, { html: '<tr data-badges="" data-idarticulo="151"><td>151</td></tr>' });
    expect(ids()).toEqual(['151']);
  });

  test('con productos del C7a y del C7b pide los cruces y los pone en sus filas; si fallan, pinta sin ellos', () => {
    document.getElementById('posstockChk_caso7a').checked = true;
    document.getElementById('posstockChk_caso7b').checked = true;
    window.cargarDatosPosstock(PERIODO);
    responder(0, { filas: [{ idArticulo: 1, orden_clave: '0', c7_subcaso: 'C7a' }, { idArticulo: 2, orden_clave: '1', c7_subcaso: 'C7b' }], actual: 2, elementos: 2 });

    expect(peticiones[1].data).toMatchObject({ pulsado: 'resolverPOSStockC7cde', ids_c7a: '1', ids_c7b: '2' });
    responder(1, { cruces: { 1: { posible_cruce_con: 2, cruce_score: 0.9, cruce_nivel: 'alto', posible_causa: 'Cruce con 2' } } });
    expect(JSON.parse(peticiones[2].data.filas_json)[0]).toMatchObject({ posible_cruce_con: 2, posible_causa: 'Cruce con 2' });

    peticiones = [];
    window.cargarDatosPosstock(PERIODO);
    responder(0, { filas: [{ idArticulo: 1, c7_subcaso: 'C7a' }, { idArticulo: 2, c7_subcaso: 'C7b' }], actual: 2, elementos: 2 });
    peticiones[1].error();
    expect(peticiones[2].data.pulsado).toBe('renderizarTablaPosstock');
  });

  test('el error del servidor o de la comunicación se pinta en lugar de la tabla', () => {
    window.cargarDatosPosstock(PERIODO);
    responder(0, { error: 'Fechas no válidas o incompletas.' });
    expect(document.querySelector('#posstockTablaWrap .alert-danger').textContent).toBe('Fechas no válidas o incompletas.');

    window.cargarDatosPosstock(PERIODO);
    peticiones[1].error();
    expect(document.querySelector('#posstockTablaWrap .alert-danger').textContent).toBe('Error de comunicación con el servidor.');
  });

  test('las filas del inventario en negativo y del stock alto se completan con el C7, y el C7b no pedido se quita', () => {
    const filas = [{ idArticulo: 1, tipo: 'Inventario en negativo' }, { idArticulo: 1, tipo: 'Entrada no registrada', c7_subcaso: 'C7b', offset_estimado: -4 }, { idArticulo: 2, tipo: 'Entrada con stock alto' }, { idArticulo: 2, tipo: 'Merma acumulada', c7_subcaso: 'C7a', delta_acumulado: 6 }];

    window._posstockEnriquecerC1aConC7b(filas);

    expect(filas[0]).toMatchObject({ c7b_activo: true, c7b_offset_estimado: -4, c7b_tipo_articulo: 'unidad' });
    expect(filas.find((f) => f.tipo === 'Entrada con stock alto')).toMatchObject({ c7a_activo: true, c7a_delta_acumulado: 6 });
    expect(filas.map((f) => f.c7_subcaso)).not.toContain('C7b');
    expect(filas.map((f) => f.c7_subcaso)).not.toContain('C7a');
  });
});

describe('la configuración', () => {
  test('abrir pide la ventana y la abre; guardar con error lo antepone al formulario', () => {
    window.abrirModalConfigPosstock();
    responder(0, { html: '<form id="formConfigPosstock"><input name="inputVentanaDias" value="7"></form>' });
    expect(window.abrirModal).toHaveBeenCalledWith('Configuración POSStock', expect.stringContaining('formConfigPosstock'));

    document.body.insertAdjacentHTML('beforeend', '<form id="formConfigPosstock"><input name="inputVentanaDias" value="5"></form>');
    window.guardarConfigPosstock();
    expect(JSON.parse(peticiones[1].data.datosFormulario)).toEqual([{ name: 'inputVentanaDias', value: '5' }]);
    responder(1, { error: 'Ventana de días debe ser 0 (sin restricción), 7 o 14.' });
    expect(document.querySelector('#formConfigPosstock .alert-danger').textContent).toBe('Ventana de días debe ser 0 (sin restricción), 7 o 14.');
  });

  test('guardar bien pone los valores en la página, rehace el selector si hay periodo, avisa y cierra', () => {
    document.body.insertAdjacentHTML('beforeend', '<form id="formConfigPosstock"></form>');
    window.posstockPeriodoActivo = PERIODO;

    window.guardarConfigPosstock();
    responder(0, { mensaje: 'ok', ventana_dias: 14, c3b_dias_post: 21, mostrar_tecnico: true });

    expect([window.POSSTOCK_VENTANA_DIAS, window.POSSTOCK_C3B_DIAS_POST, window.POSSTOCK_MOSTRAR_TECNICO]).toEqual([14, 21, true]);
    expect(window.posstockActualizarNumero).toHaveBeenCalledWith(true);
    expect(document.getElementById('posstockTablaWrap').textContent).toBe('Configuración guardada.');
    expect(window.cerrarModal).toHaveBeenCalled();
  });

  /**
   * @estado rojo
   * @codigo-afectado modulos/mod_informes/informes/posstock/PosstockConfig.js:91-98; modulos/mod_informes/tareas/abrirModalConfigPosstock.php:106, :147
   * @que-ocurre-hoy Elegir otro modelo estadístico en la ventana de configuración no cambia su
   *   descripción: la función busca el selector y la caja por unos identificadores que la ventana no usa.
   * @que-deberia-ocurrir Que la descripción sea la del modelo elegido.
   * @por-que-ocurre Los identificadores del JavaScript y del HTML de la ventana no coinciden.
   * @como-deberia-funcionar Usar los mismos identificadores en los dos.
   */
  test('defecto: elegir otro modelo cambia su descripción', () => {
    document.body.insertAdjacentHTML('beforeend', '<select id="modalModeloEstadistico"><option value="automatico">a</option><option value="gamma" selected>g</option></select><div id="modalModeloDesc">Automático</div>');
    window._modeloDescPosstock = { automatico: 'Automático', gamma: 'Gamma' };

    window.modalToggleModeloEstadistico();

    expect(document.getElementById('modalModeloDesc').textContent).toBe('Gamma');
  });
});

describe('los filtros de familias y proveedores', () => {
  function ventanaFamilias() {
    document.body.insertAdjacentHTML('beforeend', `
      <input id="posstockBuscar_incluir"><div id="posstockFamiliaError_incluir"></div>
      <table id="posstockTabla_incluir"><tbody></tbody></table><table id="posstockTabla_excluir"><tbody></tbody></table>`);
    window.posstockAbrirFiltroFamilias();
    responder(0, { familias: [{ id: 3, nombre: 'Fruta' }, { id: 4, nombre: 'Fruta seca' }], html: 'ventana' });
  }

  test('las familias se añaden por su nombre exacto o por parte de él, no dos veces, y se aplican con su etiqueta', () => {
    ventanaFamilias();
    const buscar = document.getElementById('posstockBuscar_incluir');

    buscar.value = 'fruta';
    window.posstockAgregarFamilia('incluir');
    buscar.value = 'sec';
    window.posstockAgregarFamilia('incluir');
    buscar.value = 'Fruta';
    window.posstockAgregarFamilia('incluir');
    expect(document.getElementById('posstockFamiliaError_incluir').textContent).toBe('Ya está en la lista.');
    buscar.value = 'Bebida';
    window.posstockAgregarFamilia('incluir');
    expect(document.getElementById('posstockFamiliaError_incluir').textContent).toBe('Familia no encontrada: Bebida');

    window.posstockAplicarFiltroFamilias();
    expect(window.posstockFamiliasIncluir).toEqual([{ id: 3, nombre: 'Fruta' }, { id: 4, nombre: 'Fruta seca' }]);
    expect(document.getElementById('posstockFiltroLabel').textContent).toBe('2 incluidas');
    expect(window.cerrarModal).toHaveBeenCalled();
  });

  test('los proveedores se aplican con la marca de todos los productos', () => {
    document.body.insertAdjacentHTML('beforeend', `
      <table id="posstockTablaProveedores"><tbody><tr data-id="7" data-nombre="Frutas SL"><td>Frutas SL</td></tr></tbody></table>
      <input type="checkbox" id="posstockChkTodosProductos" checked>`);

    window.posstockAplicarFiltroProveedores();

    expect(window.posstockProveedoresIncluir).toEqual([{ id: 7, nombre: 'Frutas SL' }]);
    expect(window.posstockProveedorTodosProductos).toBe(true);
  });

  /**
   * @estado rojo
   * @codigo-afectado modulos/mod_informes/informes/posstock/PosstockFiltros.js:149-162, :284-297
   * @que-ocurre-hoy La fila de una familia añadida al filtro escribe su nombre como HTML: una familia
   *   llamada con etiquetas mete esas etiquetas en la página de quien filtra. Igual con los proveedores.
   * @que-deberia-ocurrir Que el nombre se escriba como texto.
   * @por-que-ocurre El nombre se pega en la celda; solo se escapan las comillas del atributo.
   * @como-deberia-funcionar Escapar el nombre en la celda.
   */
  test('defecto: el nombre de una familia añadida al filtro es texto', () => {
    ventanaFamilias();
    responder(0, { familias: [{ id: 9, nombre: '<img src="x">Bebida' }], html: 'ventana' });
    document.getElementById('posstockBuscar_incluir').value = 'bebida';

    window.posstockAgregarFamilia('incluir');

    expect(document.querySelector('#posstockTabla_incluir img')).toBeNull();
    expect(document.querySelector('#posstockTabla_incluir td').textContent).toBe('<img src="x">Bebida');
  });
});

describe('la exportación', () => {
  test('el CSV se pide con un formulario con el periodo, los casos y los filtros; el PDF se abre al llegar', () => {
    window.posstockPeriodoActivo = PERIODO;

    window.exportarPOSStockCSV();
    window.imprimirPOSStockPDF();
    responder(0, { url: '/tmp/posstock.pdf' });

    expect(enviados[0]).toMatchObject({ pulsado: 'exportarPOSStockCSV', fecha_inicio_movimientos: '2019-03-01', tipo_periodo: 'mes', articulos_filtrados: '' });
    expect(peticiones[0].data.pulsado).toBe('imprimirPOSStockPDF');
    expect(window.open).toHaveBeenCalledWith('/tmp/posstock.pdf', '_blank');
  });

  test('con un filtro de etiquetas activo, se exportan solo los productos visibles', () => {
    window.posstockPeriodoActivo = PERIODO;
    tabla([[1, '0', '', 'Rotura', 0], [2, '1', '', 'Merma', 0]]);
    window._posstockBadgeStates = { Rotura: 1 };
    window._posstockFilasVisibles = [document.querySelector('tr[data-idarticulo="1"]')];

    window.exportarPOSStockCSV();

    expect(enviados[0].articulos_filtrados).toBe('1');
  });

  /**
   * @estado rojo
   * @codigo-afectado modulos/mod_informes/informes/posstock/PosstockExport.js:30-78; modulos/mod_informes/informes/posstock/PosstockLoader.js:231-249
   * @que-ocurre-hoy Con solo el C1 marcado, la tabla quita las filas del C7b que pidió para completar el
   *   C1, pero la exportación pide los mismos casos —con el C7b— y lleva esas filas, que la tabla no
   *   enseña.
   * @que-deberia-ocurrir Que el CSV y el PDF lleven las mismas incidencias que la tabla.
   * @por-que-ocurre La lista de casos añade el C7b siempre que está el C1, y solo la tabla lo vuelve a
   *   quitar.
   * @como-deberia-funcionar Exportar los casos marcados, o filtrar lo exportado como la tabla.
   */
  test('defecto: la exportación pide los casos que enseña la tabla', () => {
    window.posstockPeriodoActivo = PERIODO;

    window.exportarPOSStockCSV();

    expect(enviados[0].casos_incluir).toBe('caso1');
  });
});

describe('la tabla en la página', () => {
  test('la ordenación sigue la clave de orden y, a igual clave, el producto', () => {
    const filas = [{ idArticulo: 3, orden_clave: '1' }, { idArticulo: 2, orden_clave: '1' }, { idArticulo: 1, orden_clave: '0' }];

    filas.sort(window._posstockSortComparator);

    expect(filas.map((f) => f.idArticulo)).toEqual([1, 2, 3]);
  });

  test('agrupar por proveedor pone cada grupo bajo su cabecera con su cuenta y los totales, y lo recuerda', () => {
    tabla([[1, '2', 'Lácteos SA', '', 10], [2, '0', '', '', 0], [3, '1', 'Frutas SL', '', 5], [4, '3', 'Frutas SL', '', 0]]);

    window.posstockToggleAgruparProveedor();

    const cabeceras = Array.from(document.querySelectorAll('tr[data-prov-header]')).map((tr) => tr.getAttribute('data-prov-header'));
    expect(cabeceras).toEqual(['__total__', 'Frutas SL', 'Lácteos SA', '__sinprov__', '__total__']);
    expect(ids()).toEqual(['3', '4', '1', '2']);
    expect(document.querySelector('tr[data-prov-header="Frutas SL"]').textContent).toContain('2 artículos');
    expect(document.querySelector('tr[data-prov-header="__total__"]').textContent).toContain('4 artículos');
    expect(JSON.parse(localStorage.getItem('posstock_ui_v1')).agruparProv).toBe(true);

    window.posstockToggleAgruparProveedor();
    expect(document.querySelectorAll('tr[data-prov-header]')).toHaveLength(0);
    expect(ids()).toEqual(['2', '3', '1', '4']);
  });

  test('el filtro por etiquetas pasa de neutro a incluir y a excluir, oculta las filas y deja las visibles', () => {
    tabla([[1, '0', '', 'Rotura', 0], [2, '1', '', 'Merma', 0], [3, '2', '', '', 0]]);

    window._posstockIniciarFiltroBadges();
    const boton = document.querySelector('[data-badge-filtro="Rotura"]');
    boton.click();
    const visiblesIncluir = window._posstockFilasVisibles.map((tr) => tr.dataset.idarticulo);
    boton.click();
    const visiblesExcluir = window._posstockFilasVisibles.map((tr) => tr.dataset.idarticulo);

    expect(visiblesIncluir).toEqual(['1']);
    expect(visiblesExcluir).toEqual(['2', '3']);
    expect(JSON.parse(localStorage.getItem('posstock_ui_v1')).badgeStates).toMatchObject({ Rotura: 2 });
  });

  /**
   * @estado rojo
   * @codigo-afectado modulos/mod_informes/informes/posstock/PosstockTabla.js:138-151
   * @que-ocurre-hoy Al agrupar por proveedor, la cabecera de cada grupo escribe el nombre del proveedor
   *   como HTML: un proveedor llamado con etiquetas las mete en la página.
   * @que-deberia-ocurrir Que el nombre del proveedor sea texto en su cabecera.
   * @por-que-ocurre El nombre se lee del atributo, ya sin escapar, y se pega en el HTML de la fila.
   * @como-deberia-funcionar Escribir el nombre como texto.
   */
  test('defecto: el nombre del proveedor es texto en la cabecera de su grupo', () => {
    tabla([[1, '0', '&lt;img src=&quot;x&quot;&gt;Frutas', '', 0]]);

    window.posstockToggleAgruparProveedor();

    expect(document.querySelector('tr[data-prov-header] img')).toBeNull();
  });
});
