/**
 * El JavaScript de la pantalla del recálculo de precios de `mod_productos`
 * (`template/view_recalculo.php`): descartar y recuperar un producto, imprimir el recálculo guardado,
 * marcar los productos de la vista guardada y llevarlos a la selección, y vaciarla.
 *
 * El código va escrito en la propia plantilla: el caso toma su último bloque `<script>`, pone una
 * dirección de prueba donde la plantilla escribe la del producto y lo ejecuta en un contexto propio de
 * Node, con un jQuery de prueba que guarda el estado de cada elemento —sus clases, su contenido, si se
 * ve y sus propiedades— y apunta las peticiones sin enviarlas: el caso responde por la petición. Las
 * casillas de los productos las da el caso.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const PLANTILLA = path.resolve(__dirname, '../../../../TPVFox/modulos/mod_productos/template/view_recalculo.php');
const TAREAS = '/tpv/modulos/mod_productos/tareas.php';

/** El último bloque `<script>` de la plantilla, con la dirección de prueba en lugar de la del producto. */
function codigoDeLaPlantilla() {
  const plantilla = fs.readFileSync(PLANTILLA, 'utf8');
  const inicio = plantilla.lastIndexOf('<script>') + '<script>'.length;
  const fin = plantilla.indexOf('</script>', inicio);
  return plantilla.slice(inicio, fin).split('<?= $HostNombre ?>').join('/tpv');
}

/**
 * Una pantalla de prueba. `casillas` da el producto de cada casilla de la vista guardada y si está
 * marcada. Cada elemento pedido por su selector guarda lo que se le hace.
 */
function pantallaDePrueba({ casillas = [] } = {}) {
  const pantalla = { peticiones: [], abiertas: [], avisos: [], elementos: {}, manejadores: {} };
  const elemento = (selector) => {
    if (!pantalla.elementos[selector]) {
      const estado = { clases: new Set(), html: undefined, texto: undefined, visible: undefined, propiedades: {}, modal: undefined };
      const objeto = {
        estado,
        addClass: (clase) => { estado.clases.add(clase); return objeto; },
        removeClass: (clase) => { estado.clases.delete(clase); return objeto; },
        html: (contenido) => { estado.html = contenido; return objeto; },
        text: (texto) => { estado.texto = texto; return objeto; },
        show: () => { estado.visible = true; return objeto; },
        hide: () => { estado.visible = false; return objeto; },
        modal: (orden) => { estado.modal = orden; return objeto; },
        on: (evento, funcion) => { pantalla.manejadores[`${selector} ${evento}`] = funcion; return objeto; },
        prop: (nombre, valor) => {
          if (valor === undefined) {
            return estado.propiedades[nombre];
          }
          estado.propiedades[nombre] = valor;
          return objeto;
        },
      };
      pantalla.elementos[selector] = objeto;
    }
    return pantalla.elementos[selector];
  };
  const documento = {
    ready: (funcion) => funcion(),
    on: (evento, selector, funcion) => { pantalla.manejadores[`${selector} ${evento}`] = funcion; },
  };
  const $ = (selector) => {
    if (typeof selector === 'object') {
      return documento;
    }
    if (selector === '.chk-articulo') {
      return {
        length: casillas.length,
        prop: (nombre, valor) => { casillas.forEach((casilla) => { casilla[nombre] = valor; }); },
      };
    }
    if (selector === '.chk-articulo:checked') {
      const marcadas = casillas.filter((casilla) => casilla.checked);
      return { length: marcadas.length, each: (funcion) => marcadas.forEach((casilla) => funcion.call(casilla)) };
    }
    return elemento(selector);
  };
  $.post = (url, datos, alResponder) => { pantalla.peticiones.push({ url, datos, alResponder }); };
  $.ajax = ({ url, data, success }) => { pantalla.peticiones.push({ url, datos: data, alResponder: success }); };
  $.parseJSON = JSON.parse;
  pantalla.casillas = casillas;
  pantalla.contexto = vm.createContext({
    $,
    window: { open: (direccion) => { pantalla.abiertas.push(direccion); } },
    alert: (texto) => { pantalla.avisos.push(texto); },
    document: {},
  });
  // La casilla que el caso pulsa responde a `$(this)` con su valor.
  casillas.forEach((casilla) => { casilla.val = () => casilla.value; });
  pantalla.contexto.$ = (selector) => (casillas.includes(selector) ? selector : $(selector));
  Object.assign(pantalla.contexto.$, $);
  vm.runInContext(codigoDeLaPlantilla(), pantalla.contexto, { filename: PLANTILLA });
  pantalla.responder = (respuesta) => pantalla.peticiones[pantalla.peticiones.length - 1].alResponder(respuesta);

  return pantalla;
}

describe('en la pantalla del recálculo de precios de mod_productos', () => {
  test('al abrirse, si hay productos en la selección, enseña cuántos y el panel de acciones; si no, nada', () => {
    const conSeleccion = pantallaDePrueba();
    const sinSeleccion = pantallaDePrueba();

    conSeleccion.responder(JSON.stringify({ total: 3 }));
    sinSeleccion.responder(JSON.stringify({ total: 0 }));

    expect(conSeleccion.peticiones[0]).toMatchObject({ url: TAREAS, datos: { accion: 'obtener' } });
    expect(conSeleccion.elementos['#cntSeleccion'].estado.texto).toBe(3);
    expect(conSeleccion.elementos['#panelAccionesSeleccion'].estado.visible).toBe(true);
    expect(sinSeleccion.elementos['#panelAccionesSeleccion']).toBeUndefined();
  });

  test('descartar un producto lo pide a la petición de mod_productos y tacha su fila, con el botón de recuperarlo', () => {
    const pantalla = pantallaDePrueba();

    pantalla.contexto.cambiarEstadoRecalculo(7, 'albaran', 12, 'compras', 1, 'eliminar');
    pantalla.responder(JSON.stringify({ accion: 'eliminar' }));

    expect(pantalla.peticiones[1]).toMatchObject({
      url: TAREAS,
      datos: { pulsado: 'cambiarEstadoRecalculo', idArticulo: 7, dedonde: 'albaran', id: 12, tipo: 'compras', operacion: 'eliminar' },
    });
    expect(pantalla.elementos['#Row1'].estado.clases.has('tachado')).toBe(true);
    expect(pantalla.elementos['#Row1 > .eliminar'].estado.html).toContain("cambiarEstadoRecalculo(7, 'albaran', 12, 'compras', 1, 'retorno')");
    expect(pantalla.elementos['#Row1 > .eliminar'].estado.html).toContain('glyphicon-export');
  });

  test('recuperar un producto quita la tachadura de su fila y vuelve a ofrecer descartarlo', () => {
    const pantalla = pantallaDePrueba();
    pantalla.contexto.$('#Row2').addClass('tachado');

    pantalla.contexto.cambiarEstadoRecalculo(9, 'albaran', 12, 'compras', 2, 'retorno');
    pantalla.responder(JSON.stringify({ accion: 'retorno' }));

    expect(pantalla.peticiones[1].datos.operacion).toBe('retorno');
    expect(pantalla.elementos['#Row2'].estado.clases.has('tachado')).toBe(false);
    expect(pantalla.elementos['#Row2 > .eliminar'].estado.html).toContain("cambiarEstadoRecalculo(9, 'albaran', 12, 'compras', 2, 'eliminar')");
    expect(pantalla.elementos['#Row2 > .eliminar'].estado.html).toContain('glyphicon-trash');
  });

  test('imprimir pide el recálculo guardado y abre su fichero; si la petición responde un error, lo avisa', () => {
    const guardado = pantallaDePrueba();
    const sinGuardar = pantallaDePrueba();

    guardado.contexto.imprimirRecalculo(12);
    guardado.responder(JSON.stringify({ fichero: '/tmp/Recalculorecalculo.pdf' }));
    sinGuardar.contexto.imprimirRecalculo(12);
    sinGuardar.responder(JSON.stringify({ error: 'No hay datos guardados para imprimir.' }));

    expect(guardado.peticiones[1]).toMatchObject({ url: TAREAS, datos: { pulsado: 'imprimirRecalculo', id: 12 } });
    expect(guardado.abiertas).toEqual(['/tmp/Recalculorecalculo.pdf']);
    expect(sinGuardar.avisos).toEqual(['No hay datos guardados para imprimir.']);
    expect(sinGuardar.abiertas).toEqual([]);
  });

  test('la casilla de todos marca o desmarca todas, y el botón de los seleccionados se activa si hay alguna', () => {
    const pantalla = pantallaDePrueba({ casillas: [{ value: '7', checked: false }, { value: '9', checked: false }] });

    pantalla.manejadores['#selTodos change'].call({ checked: true });
    const conTodas = pantalla.elementos['#btnSeleccionados'].estado.propiedades.disabled;
    pantalla.manejadores['#selTodos change'].call({ checked: false });

    expect(conTodas).toBe(false);
    expect(pantalla.casillas.map((casilla) => casilla.checked)).toEqual([false, false]);
    expect(pantalla.elementos['#btnSeleccionados'].estado.propiedades.disabled).toBe(true);
  });

  test('con unas casillas marcadas y otras no, la de todos queda a medias', () => {
    const pantalla = pantallaDePrueba({ casillas: [{ value: '7', checked: true }, { value: '9', checked: false }] });

    pantalla.manejadores['.chk-articulo change']();

    expect(pantalla.elementos['#selTodos'].estado.propiedades).toEqual({ indeterminate: true, checked: false });
    expect(pantalla.elementos['#btnSeleccionados'].estado.propiedades.disabled).toBe(false);
  });

  test('llevar los marcados a la selección: si está vacía, los añade y enseña cuántos hay', () => {
    const pantalla = pantallaDePrueba({ casillas: [{ value: '7', checked: true }, { value: '9', checked: true }] });

    pantalla.contexto.procesarSeleccion();
    pantalla.responder(JSON.stringify({ total: 0 }));
    pantalla.responder(JSON.stringify({ total: 2 }));

    expect(pantalla.peticiones[2]).toMatchObject({ url: TAREAS, datos: { accion: 'agregarASeleccion', ids: ['7', '9'] } });
    expect(pantalla.elementos['#cntSeleccion'].estado.texto).toBe(2);
    expect(pantalla.elementos['#panelAccionesSeleccion'].estado.visible).toBe(true);
  });

  test('llevar los marcados a una selección con productos pregunta si añadirlos o reemplazarla; reemplazar la establece con ellos', () => {
    const pantalla = pantallaDePrueba({ casillas: [{ value: '7', checked: true }, { value: '9', checked: true }] });

    pantalla.contexto.procesarSeleccion();
    pantalla.responder(JSON.stringify({ total: 3 }));
    const pregunta = pantalla.elementos['#modalSelConflicto .modal-body p'].estado.texto;
    const modal = pantalla.elementos['#modalSelConflicto'].estado.modal;
    pantalla.contexto.ejecutarSeleccion('reemplazar');

    expect(pregunta).toBe('Tienes 3 producto(s) en la selección actual. ¿Qué deseas hacer con los 2 producto(s) nuevos?');
    expect(modal).toBe('show');
    expect(pantalla.peticiones[2]).toMatchObject({ url: TAREAS, datos: { accion: 'establecerSeleccion', ids: ['7', '9'] } });
  });

  test('llevar a la selección sin ningún marcado no pide nada', () => {
    const pantalla = pantallaDePrueba({ casillas: [{ value: '7', checked: false }] });

    pantalla.contexto.procesarSeleccion();

    expect(pantalla.peticiones).toHaveLength(1);
  });

  test('eliminar la selección la vacía, esconde el panel y desmarca las casillas', () => {
    const pantalla = pantallaDePrueba({ casillas: [{ value: '7', checked: true }] });

    pantalla.contexto.limpiarSeleccionRecalculo();
    pantalla.responder('');

    expect(pantalla.peticiones[1]).toMatchObject({ url: TAREAS, datos: { accion: 'limpiar' } });
    expect(pantalla.elementos['#panelAccionesSeleccion'].estado.visible).toBe(false);
    expect(pantalla.elementos['#btnSeleccionados'].estado.propiedades.disabled).toBe(true);
    expect(pantalla.casillas[0].checked).toBe(false);
    expect(pantalla.elementos['#selTodos'].estado.propiedades).toEqual({ checked: false, indeterminate: false });
  });

  test('llevar los marcados a una selección con productos y elegir agregarlos los añade a la existente', () => {
    const pantalla = pantallaDePrueba({ casillas: [{ value: '7', checked: true }] });

    pantalla.contexto.procesarSeleccion();
    pantalla.responder(JSON.stringify({ total: 3 }));
    pantalla.contexto.ejecutarSeleccion('agregar');
    pantalla.responder(JSON.stringify({ total: 4 }));

    expect(pantalla.peticiones[2]).toMatchObject({ url: TAREAS, datos: { accion: 'agregarASeleccion', ids: ['7'] } });
    expect(pantalla.elementos['#modalSelConflicto'].estado.modal).toBe('hide');
    expect(pantalla.elementos['#cntSeleccion'].estado.texto).toBe(4);
  });

  /**
   * @estado rojo
   * @codigo-afectado modulos/mod_productos/template/view_recalculo.php:296-312
   *
   * @que-ocurre-hoy Recuperar un producto que la pantalla pintó descartado le quita la tachadura, pero su
   *   caja del precio sigue desactivada: al guardar sin volver a cargar la página, su precio no se envía,
   *   y el guardado lo toma como cero.
   * @que-deberia-ocurrir Que recuperarlo vuelva a dejar cambiar su precio, y que se envíe al guardar.
   * @por-que-ocurre La pantalla pinta desactivada la caja de un producto descartado, y recuperarlo solo
   *   cambia la fila y su botón.
   * @como-deberia-funcionar Activar la caja del precio al recuperar el producto, y desactivarla al
   *   descartarlo.
   */
  test('recuperar un producto descartado vuelve a dejar cambiar su precio', () => {
    const pantalla = pantallaDePrueba();
    pantalla.contexto.$('#Row2').addClass('tachado');
    pantalla.contexto.$('#pvpRecomendado_2').prop('disabled', true);

    pantalla.contexto.cambiarEstadoRecalculo(9, 'albaran', 12, 'compras', 2, 'retorno');
    pantalla.responder(JSON.stringify({ accion: 'retorno' }));

    expect(pantalla.elementos['#pvpRecomendado_2'].estado.propiedades.disabled).toBe(false);
  });
});
