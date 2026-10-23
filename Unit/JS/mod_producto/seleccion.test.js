/**
 * El JavaScript de la pantalla de la selección de `mod_productos` (`template/view_seleccion.php`):
 * quitar un producto de la selección, abrir el mayor de un producto o de los marcados, e imprimir las
 * etiquetas de los marcados. Sustituye, en esa pantalla, a las funciones del mismo nombre de
 * `mod_producto/funciones.js`.
 *
 * El código va escrito en la propia plantilla: el caso toma su último bloque `<script>`, pone una
 * dirección de prueba donde la plantilla escribe la del producto y lo ejecuta en un contexto propio de
 * Node, con un jQuery de prueba que guarda el estado de cada caja y apunta las peticiones sin enviarlas:
 * el caso responde por la petición. Las funciones comunes que la pantalla carga aparte —las casillas
 * marcadas y los objetos de una clase— las da el caso.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const PLANTILLA = path.resolve(__dirname, '../../../../TPVFox/modulos/mod_productos/template/view_seleccion.php');

/** El último bloque `<script>` de la plantilla, con la dirección de prueba en lugar de la del producto. */
function codigoDeLaPlantilla() {
  const plantilla = fs.readFileSync(PLANTILLA, 'utf8');
  const inicio = plantilla.lastIndexOf('<script>') + '<script>'.length;
  const fin = plantilla.indexOf('</script>', inicio);
  return plantilla.slice(inicio, fin).split('<?= $HostNombre ?>').join('/tpv');
}

/**
 * Una pantalla de prueba. `cajas` da el valor de partida de cada caja por su `#id`; `marcados`, los
 * productos de las casillas marcadas; `cantidades`, las cajas de etiquetas con su producto y su número.
 */
function pantallaDePrueba({ cajas = {}, marcados = [], cantidades = [] } = {}) {
  const pantalla = { peticiones: [], abiertas: [], temporizadores: [], filasQuitadas: 0, avisos: [] };
  const fila = {
    fadeOut: (espera, alTerminar) => { alTerminar.call({}); },
  };
  const $ = (selector) => {
    if (typeof selector === 'object') {
      return { closest: () => fila, val: () => selector.value, remove: () => { pantalla.filasQuitadas += 1; } };
    }
    if (selector === '.checkSelect:checked') {
      return { each: (funcion) => marcados.forEach((id) => funcion.call({ value: id })) };
    }
    const id = selector.replace(/^#/, '').replace(/ option:selected$/, '');
    return { val: () => cajas[id] };
  };
  $.post = (url, datos, alResponder, tipo) => { pantalla.peticiones.push({ url, datos, alResponder, tipo }); };
  $.each = (lista, funcion) => lista.forEach((valor, indice) => funcion(indice, valor));
  $.parseJSON = JSON.parse;
  pantalla.window = { location: { href: '' }, open: (direccion, destino) => { pantalla.abiertas.push({ direccion, destino }); } };
  pantalla.contexto = vm.createContext({
    $,
    window: pantalla.window,
    alert: (texto) => { pantalla.avisos.push(texto); },
    setTimeout: (funcion, espera) => { pantalla.temporizadores.push({ funcion, espera }); },
    TfObtenerCheck: () => marcados,
    TfObtenerObjetos: () => ({
      each: (funcion) => cantidades.forEach(([idArticulo, numero]) => funcion.call({ dataset: { idarticulo: idArticulo }, value: numero })),
    }),
  });
  vm.runInContext(codigoDeLaPlantilla(), pantalla.contexto, { filename: PLANTILLA });
  pantalla.responder = (respuesta) => pantalla.peticiones[pantalla.peticiones.length - 1].alResponder(respuesta);

  return pantalla;
}

describe('en la pantalla de la selección de mod_productos', () => {
  test('quitar un producto lo pide a la petición de fondo y quita su fila', () => {
    const pantalla = pantallaDePrueba();

    pantalla.contexto.eliminarDeSeleccion(7, {});
    pantalla.responder({ ok: true, total: 2 });

    expect(pantalla.peticiones[0]).toMatchObject({ url: '/tpv/modulos/mod_productos/tareas.php', datos: { accion: 'quitar', idArticulo: 7 }, tipo: 'json' });
    expect(pantalla.filasQuitadas).toBe(1);
    expect(pantalla.window.location.href).toBe('');
  });

  test('quitar el último producto vuelve al listado', () => {
    const pantalla = pantallaDePrueba();

    pantalla.contexto.eliminarDeSeleccion(7, {});
    pantalla.responder({ ok: true, total: 0 });

    expect(pantalla.window.location.href).toBe('ListaProductos.php');
  });

  test('si la petición no lo quita, la fila se queda', () => {
    const pantalla = pantallaDePrueba();

    pantalla.contexto.eliminarDeSeleccion(7, {});
    pantalla.responder({ ok: false, mensaje: 'idArticulo no válido' });

    expect(pantalla.filasQuitadas).toBe(0);
  });

  test('el mayor de un producto se abre en otra pestaña con las fechas y su existencia inicial, o cero', () => {
    const pantalla = pantallaDePrueba({ cajas: { fecha_inicio: '2026-10-08', fecha_final: '2026-10-23', stkini7: '3' } });

    pantalla.contexto.redirecionarMayor(7, 'DetalleMayor');
    pantalla.contexto.redirecionarMayor(9, 'DetalleMayor');

    expect(pantalla.abiertas).toEqual([
      { direccion: '/tpv/modulos/mod_productos/DetalleMayor.php?idArticulo=7&fecha_inicial=2026-10-08&fecha_final=2026-10-23&stock=3', destino: '_blank' },
      { direccion: '/tpv/modulos/mod_productos/DetalleMayor.php?idArticulo=9&fecha_inicial=2026-10-08&fecha_final=2026-10-23&stock=0', destino: '_blank' },
    ]);
  });

  test('ver el mayor de los marcados abre una pestaña por producto, cada una con su espera', () => {
    const pantalla = pantallaDePrueba({ cajas: { fecha_inicio: '2026-10-08', fecha_final: '2026-10-23', stkini9: '4' }, marcados: ['7', '9'] });

    pantalla.contexto.verMayorSeleccionados();
    pantalla.temporizadores.forEach((temporizador) => temporizador.funcion());

    expect(pantalla.temporizadores.map((temporizador) => temporizador.espera)).toEqual([0, 300]);
    expect(pantalla.abiertas.map((abierta) => abierta.direccion)).toEqual([
      '/tpv/modulos/mod_productos/DetalleMayor.php?idArticulo=7&fecha_inicial=2026-10-08&fecha_final=2026-10-23&stock=0',
      '/tpv/modulos/mod_productos/DetalleMayor.php?idArticulo=9&fecha_inicial=2026-10-08&fecha_final=2026-10-23&stock=4',
    ]);
  });

  test('ver el mayor sin ningún marcado lo avisa y no abre nada', () => {
    const pantalla = pantallaDePrueba();

    pantalla.contexto.verMayorSeleccionados();

    expect(pantalla.avisos).toEqual(['No hay productos seleccionados.']);
    expect(pantalla.temporizadores).toEqual([]);
  });

  test('imprimir las etiquetas envía los marcados con su número a la petición de mod_productos y abre el fichero', () => {
    const pantalla = pantallaDePrueba({
      cajas: { tamanhos: 'A7', teclaOReferencia: '2' },
      marcados: ['7'],
      cantidades: [['7', '2'], ['9', '1']],
    });

    pantalla.contexto.imprimirEtiquetas('ListaEtiquetas');
    pantalla.responder(JSON.stringify({ fichero: '/tmp/Etiquetaetiquetas.pdf' }));

    expect(pantalla.peticiones[0].url).toBe('/tpv/modulos/mod_productos/tareas.php');
    expect(pantalla.peticiones[0].datos).toEqual({
      pulsado: 'imprimirEtiquetas',
      dedonde: 'ListaEtiquetas',
      tamano: 'A7',
      teclaOReferencia: '2',
      productos: JSON.stringify([{ idArticulo: '7', numEtiquetas: '2' }]),
    });
    expect(pantalla.abiertas).toEqual([{ direccion: '/tmp/Etiquetaetiquetas.pdf', destino: undefined }]);
  });
});

describe('con los ficheros que carga la pantalla de la selección', () => {
  /** El contexto con los tres ficheros que la plantilla carga en su cabecera y su propio bloque, sobre un jQuery que no hace nada. */
  function contextoDeLaPantalla() {
    const nada = new Proxy(function () {}, { get: (objeto, clave) => (clave === 'ready' ? () => {} : nada), apply: () => nada });
    const contexto = vm.createContext({ $: nada, jQuery: nada, document: {}, window: {}, console: { log() {} } });
    ['../../../../TPVFox/modulos/mod_producto/funciones.js', '../../../../TPVFox/modulos/mod_producto/js/AccionesDirectas.js', '../../../../TPVFox/lib/js/tpvfoxSinExport.js']
      .map((fichero) => path.resolve(__dirname, fichero))
      .forEach((fichero) => vm.runInContext(fs.readFileSync(fichero, 'utf8'), contexto, { filename: fichero }));
    vm.runInContext(codigoDeLaPlantilla(), contexto, { filename: PLANTILLA });
    return contexto;
  }

  /**
   * @etiquetas esperado bajo
   * @estado rojo
   * @codigo-afectado modulos/mod_productos/template/view_seleccion.php:105-106
   *
   * @que-ocurre-hoy La casilla de la cabecera de la lista, la de marcar todos, no hace nada al pulsarla:
   *   la función que llama no existe.
   * @que-deberia-ocurrir Que marque o desmarque todas las casillas de la lista.
   * @por-que-ocurre La casilla llama a una función que no define ninguno de los ficheros que carga la
   *   pantalla, ni su propio bloque.
   * @como-deberia-funcionar Definir la función, o llamar a la que marca y desmarca las casillas de una
   *   clase.
   */
  test('la casilla de marcar todos llama a una función que existe', () => {
    const contexto = contextoDeLaPantalla();
    const alPulsar = fs.readFileSync(PLANTILLA, 'utf8').match(/class="checkSelectTodos"\s*onclick="([^"]+)"/)[1];

    expect(() => vm.runInContext(alPulsar, contexto)).not.toThrow();
  });
});
