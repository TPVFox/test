/**
 * El JavaScript de la reorganización (`modulos/mod_reorganizacion/funciones.js`): las operaciones por
 * tandas —regenerar el stock, subirlo a la tienda web, reorganizar los permisos y cerrar el stock
 * anual—, la validación antes de cerrar, la configuración del cierre con sus familias excluidas y la
 * búsqueda de una familia.
 *
 * Se ejecuta en un contexto propio de Node, con un jQuery de prueba que guarda el estado de cada
 * elemento —valor, si se ve, si está desactivado o marcado, su contenido y sus clases— y apunta las
 * peticiones sin enviarlas: el caso responde, o hace fallar, la última. La barra de progreso, la
 * ventana modal y los avisos se sustituyen por funciones que apuntan sus llamadas, y el foco se pone en
 * el acto.
 */

'use strict';

const path = require('path');
const { cargarScriptGlobal } = require('../../../support/js/cargarScriptGlobal');

const FUNCIONES = path.resolve(__dirname, '../../../../TPVFox/modulos/mod_reorganizacion/funciones.js');

/**
 * Una página de prueba; `elementos` da el estado de partida de los que el caso necesita, por su `id`, y
 * `excluidas`, las filas de la tabla de familias excluidas, como `{ id, nombre }`. Las filas que añade
 * la página quedan en `filasNuevas`.
 */
function paginaDePrueba(elementos = {}, excluidas = []) {
  const pagina = { peticiones: [], elementos: {}, filasNuevas: [] };
  const elemento = (id) => {
    if (!pagina.elementos[id]) {
      pagina.elementos[id] = { id, valor: '', visible: false, desactivado: false, marcado: false, html: '', clases: new Set() };
    }
    return pagina.elementos[id];
  };
  Object.entries(elementos).forEach(([id, estado]) => {
    Object.assign(elemento(id), estado, { clases: new Set(estado.clases || []) });
  });
  const envoltorio = (e) => ({
    val: (valor) => {
      if (valor === undefined) {
        return e.valor;
      }
      e.valor = valor;
      return undefined;
    },
    prop: (nombre, valor) => {
      if (valor === undefined) {
        return e.desactivado;
      }
      e.desactivado = valor;
      return undefined;
    },
    is: () => e.marcado,
    show: () => { e.visible = true; },
    hide: () => { e.visible = false; },
    html: (contenido) => { e.html = contenido; },
    hasClass: (clase) => e.clases.has(clase),
    addClass: (clase) => { e.clases.add(clase); },
    removeClass: (clase) => { e.clases.delete(clase); },
    css: () => {},
    removeAttr: () => {},
    focus: () => {},
  });
  const documento = {
    getElementById: (id) => ({
      get value() { return elemento(id).valor; },
      set value(valor) { elemento(id).valor = valor; },
    }),
    querySelector: (selector) => {
      const repetida = selector.match(/data-id="(.*)"\]$/);
      if (repetida) {
        const ids = excluidas.map((fila) => fila.id).concat(pagina.filasNuevas.map((fila) => fila.atributos['data-id']));
        return ids.includes(repetida[1]) ? {} : null;
      }
      return { appendChild: (fila) => pagina.filasNuevas.push(fila) };
    },
    createElement: () => {
      const fila = { atributos: {}, innerHTML: '' };
      fila.setAttribute = (nombre, valor) => { fila.atributos[nombre] = valor; };
      return fila;
    },
  };
  const $ = (selector) => {
    if (selector === documento) {
      return { on: () => {} };
    }
    if (excluidas.includes(selector)) {
      return { find: (celda) => ({ text: () => (celda === 'td:first' ? selector.id : selector.nombre) }) };
    }
    if (selector === '#tablaFamiliasExcluidas tbody tr') {
      return { each: (funcion) => excluidas.forEach((fila) => funcion.call(fila)) };
    }
    return envoltorio(elemento(selector.slice(1)));
  };
  $.ajax = (opciones) => { pagina.peticiones.push(opciones); };
  $.parseJSON = (texto) => JSON.parse(texto);
  pagina.contexto = cargarScriptGlobal(FUNCIONES, {
    $,
    jQuery: $,
    document: documento,
    alert: jest.fn(),
    BarraProceso: jest.fn(),
    abrirModalConTitulo: jest.fn(),
    cerrarPopUpConTitulo: jest.fn(),
    focusAlLanzarModal: jest.fn(),
    setTimeout: (funcion) => funcion(),
    V_JS: { dedonde: 'reorganizarTPV' },
    cabecera: {},
    console: { log() {} },
  });
  pagina.responder = (respuesta) => pagina.peticiones[pagina.peticiones.length - 1].success(JSON.stringify(respuesta));
  pagina.fallar = () => pagina.peticiones[pagina.peticiones.length - 1].error({ status: 500 }, 'error', 'Internal Server Error');

  return pagina;
}

describe('las operaciones por tandas', () => {
  /**
   * @etiquetas estado-actual
   *
   * @comportamiento Regenerar el stock pide a la petición de fondo una tanda tras otra, cada una desde
   *   la posición que respondió la anterior, y al llegar una tanda vacía vuelve a activar el botón.
   */
  test('regenerar el stock pide tanda tras tanda y termina con una vacía', () => {
    const pagina = paginaDePrueba({ 'boton-stock': { desactivado: true } });

    pagina.contexto.RegenerarStock(0, 100, 250, '0');
    pagina.responder({ elementos: 100, actual: 100, totalProductos: 250, pagina: 100 });
    pagina.responder({ totalProductos: 250, pagina: 100 });

    expect(pagina.peticiones.map((peticion) => peticion.data)).toEqual([
      { pulsado: 'generastock', inicial: 0, pagina: 100, totalProductos: 250 },
      { pulsado: 'generastock', inicial: 100, pagina: 100, totalProductos: 250 },
    ]);
    expect(pagina.peticiones[0].url).toBe('./tareas.php');
    expect(pagina.elementos['boton-stock'].desactivado).toBe(false);
  });

  /**
   * @etiquetas estado-actual
   *
   * @comportamiento Subir a la tienda web cuenta sus productos y los sube en tandas de 900 como mucho,
   *   cada una desde la posición que respondió la anterior, hasta llegar al total.
   */
  test('subir a la tienda web cuenta sus productos y los sube en tandas de 900 como mucho', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.contarProductosWeb();
    pagina.responder({ totalProductos: 1000 });
    pagina.responder({ elementos: ['1'], elementos_precios: ['1'], actual: 900, totalProductos: 1000 });
    pagina.responder({ elementos: ['2'], elementos_precios: ['2'], actual: 1000, totalProductos: 1000 });

    expect(pagina.peticiones.map((peticion) => peticion.data)).toEqual([
      { pulsado: 'contarproductos', tipo: 'web' },
      { pulsado: 'subirStockYPrecio', inicial: 0, cantidad: 900, totalProductos: 1000 },
      { pulsado: 'subirStockYPrecio', inicial: 900, cantidad: 100, totalProductos: 1000 },
    ]);
    expect(pagina.contexto.BarraProceso).toHaveBeenLastCalledWith(1000, 1000, '1');
  });

  /**
   * @etiquetas estado-actual
   *
   * @comportamiento Reorganizar los permisos pide un usuario tras otro, por su posición, del primero al
   *   último, cada uno cuando respondió el anterior.
   */
  test('reorganizar los permisos pide un usuario tras otro, del primero al último', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.reorganizarPermisosModulos(0, 3);
    pagina.responder({ eliminado: 0, creados: 1 });
    pagina.responder({ eliminado: 0, creados: 0 });
    pagina.responder({ eliminado: 2, creados: 0 });

    expect(pagina.peticiones.map((peticion) => peticion.data)).toEqual([0, 1, 2].map((inicial) => ({ pulsado: 'reorganizarPermisosModulos', inicial, total: 3 })));
    expect(pagina.contexto.BarraProceso).toHaveBeenLastCalledWith(3, 3, 2);
  });

  /**
   * @etiquetas estado-actual
   *
   * @comportamiento Cerrar el stock envía las familias, el proveedor y la configuración, y sigue desde
   *   la familia que respondió la anterior hasta la última.
   */
  test('cerrar el stock envía las familias, el proveedor y la configuración, y sigue hasta la última', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.CerrarStockAnoActual(0, 1, ['3', '7'], '-cerrar-stock', '56', { modo: 'automatico' });
    pagina.responder({ actual: 1, pagina: 1, idProveedor: '56' });
    pagina.responder({ actual: 2, pagina: 1, idProveedor: '56' });

    expect(pagina.peticiones.map((peticion) => peticion.data)).toEqual([0, 1].map((inicial) => ({
      pulsado: 'cerrarStockAnoActual', inicial, pagina: 1, familias: '["3","7"]', idProveedor: '56', configuracion: '{"modo":"automatico"}',
    })));
    expect(pagina.contexto.BarraProceso).toHaveBeenLastCalledWith(2, 2, '-cerrar-stock');
  });

  /**
   * @etiquetas esperado validacion medio
   * @estado rojo
   * @codigo-afectado modulos/mod_reorganizacion/funciones.js:83-110
   *
   * @que-ocurre-hoy Si la subida responde un error de la tienda web, la pantalla no lo dice: se para, o
   *   sigue con la tanda siguiente, según lo que llegue con él.
   * @que-deberia-ocurrir Que avise del error y pare la subida.
   * @por-que-ocurre La respuesta de cada tanda no se mira por su error.
   * @como-deberia-funcionar Comprobar el error de la respuesta, avisar y parar.
   */
  test('si la subida responde un error, la pantalla avisa y para', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.SubirStockWeb(0, 900, 1000, '1');
    pagina.responder({ elementos: [], elementos_precios: [], actual: 1, totalProductos: 1000, error: { Datos: { error: 'Clave no válida' } } });

    expect(pagina.contexto.alert).toHaveBeenCalled();
    expect(pagina.peticiones).toHaveLength(1);
  });

  /**
   * @etiquetas esperado validacion alto
   * @estado rojo
   * @codigo-afectado modulos/mod_reorganizacion/funciones.js:210-220
   *
   * @que-ocurre-hoy Si una petición de las operaciones por tandas falla —el servidor termina con un
   *   error—, la pantalla solo lo apunta en la consola: el cierre, la regeneración, la subida o la
   *   reorganización se quedan a medias sin decirlo.
   * @que-deberia-ocurrir Que avise de que la operación no terminó.
   * @por-que-ocurre La función común de las peticiones solo escribe el fallo en la consola.
   * @como-deberia-funcionar Avisar del fallo y dejar la pantalla lista para repetir.
   */
  test('si una petición del cierre falla, la pantalla avisa', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.CerrarStockAnoActual(0, 1, ['3', '7'], '-cerrar-stock', '56', { modo: 'automatico' });
    pagina.fallar();

    expect(pagina.contexto.alert).toHaveBeenCalled();
  });
});

describe('la validación antes de cerrar', () => {
  /**
   * @etiquetas estado-actual
   *
   * @comportamiento En el modo base, validar pide la validación de la configuración guardada; si es
   *   válida, lo dice en la alerta del modo, esconde el botón de validar y enseña el de iniciar.
   */
  test('en el modo base, con la configuración válida, ofrece iniciar el cierre', () => {
    const pagina = paginaDePrueba({ modoBase: { marcado: true }, alertaModoBase: { clases: ['alert-danger'] }, btnValidarCierre: { visible: true } });

    pagina.contexto.validarProceso('Cierre Stock Anual');
    pagina.responder({ error: false, mensaje: 'La configuración XML es válida.' });

    expect(pagina.peticiones[0].data).toEqual({ pulsado: 'validarConfiguracionXML', seccion: 'cierre_stock_anual' });
    expect(pagina.elementos.alertaModoBase.html).toBe("<p class='text-info'>La configuración XML es válida.</p>");
    expect([...pagina.elementos.alertaModoBase.clases]).toEqual(['alert-info']);
    expect([pagina.elementos.btnValidarCierre.visible, pagina.elementos.btnIniciarCierre.visible]).toEqual([false, true]);
  });

  /**
   * @etiquetas estado-actual
   *
   * @comportamiento En el modo base, si la configuración no es válida, la alerta lo dice en rojo con el
   *   motivo y no se ofrece iniciar el cierre.
   */
  test('en el modo base, con la configuración no válida, lo dice y no ofrece iniciar el cierre', () => {
    const pagina = paginaDePrueba({ modoBase: { marcado: true }, alertaModoBase: { clases: ['alert-info'] }, btnValidarCierre: { visible: true }, btnIniciarCierre: { visible: false } });

    pagina.contexto.validarProceso('Cierre Stock Anual');
    pagina.responder({ error: true, mensaje: 'El proveedor con ID: 9 y Nombre: Cierres no es válido.' });

    expect(pagina.elementos.alertaModoBase.html).toContain('El proveedor con ID: 9 y Nombre: Cierres no es válido.');
    expect([...pagina.elementos.alertaModoBase.clases]).toEqual(['alert-danger']);
    expect([pagina.elementos.btnValidarCierre.visible, pagina.elementos.btnIniciarCierre.visible]).toEqual([true, false]);
  });

  /**
   * @etiquetas estado-actual
   *
   * @comportamiento En el modo manual, validar envía el proveedor y la familia elegidos; si son válidos,
   *   los deja fijos y ofrece iniciar el cierre.
   */
  test('en el modo manual, con el proveedor y la familia válidos, los fija y ofrece iniciar el cierre', () => {
    const pagina = paginaDePrueba({ modoManual: { marcado: true }, id_proveedor: { valor: '56' }, Proveedor: { valor: 'Cierres' }, id_familia: { valor: '3' }, Familia: { valor: 'Frutas' } });

    pagina.contexto.validarProceso('Cierre Stock Anual');
    pagina.responder({ error: false, mensaje: 'Los datos del formulario son válidos.' });

    expect(pagina.peticiones[0].data).toEqual({ pulsado: 'validarDatosFormulario', seccion: 'cierre_stock_anual', datos: '{"idProveedor":"56","proveedor":"Cierres","idFamilia":"3","familia":"Frutas"}' });
    expect(['id_proveedor', 'Proveedor', 'id_familia', 'Familia'].map((id) => pagina.elementos[id].desactivado)).toEqual([true, true, true, true]);
    expect(pagina.elementos.btnIniciarCierre.visible).toBe(true);
  });

  /**
   * @etiquetas esperado pantalla alto
   * @estado rojo
   * @codigo-afectado modulos/mod_reorganizacion/funciones.js:820-847,871-893
   *
   * @que-ocurre-hoy El mensaje de la validación se escribe en la alerta como HTML, con los nombres del
   *   proveedor y de las familias que lleva: un nombre con código lo ejecuta en la pantalla.
   * @que-deberia-ocurrir Que el mensaje se escriba como texto.
   * @por-que-ocurre El mensaje se pega dentro de un párrafo sin escaparlo.
   * @como-deberia-funcionar Escribir el mensaje como texto del párrafo.
   */
  test('el mensaje de la validación se escribe como texto', () => {
    const pagina = paginaDePrueba({ modoManual: { marcado: true } });

    pagina.contexto.validarProceso('Cierre Stock Anual');
    pagina.responder({ error: true, mensaje: 'La familia seleccionada con ID: 3 y Nombre: <img src=x onerror=alert(1)> no es válida.' });

    expect(pagina.elementos.alertaModoManual.html).not.toContain('<img');
  });
});

describe('la configuración del cierre', () => {
  /**
   * @etiquetas estado-actual
   *
   * @comportamiento Guardar la configuración envía el proveedor, si se reescribe el albarán, los
   *   productos por albarán, las series y las familias excluidas de la tabla; guardada, avisa y cierra
   *   la ventana de la configuración.
   */
  test('guardar la configuración envía el formulario y las familias excluidas, y avisa de que se guardó', () => {
    const pagina = paginaDePrueba(
      { id_proveedor: { valor: '56' }, Proveedor: { valor: 'Cierres' }, num_productos: { valor: '50' }, serie_apertura: { valor: 'A' }, serie_cierre: { valor: 'C' } },
      [{ id: ' 3 ', nombre: ' Frutas ' }],
    );

    pagina.contexto.guardarConfiguracionXML('cierre_stock_anual');
    pagina.responder({ guardado: true });

    expect(pagina.peticiones[0].data.pulsado).toBe('guardarConfiguracionXML');
    expect(JSON.parse(pagina.peticiones[0].data.datos)).toEqual({
      idProveedor: '56', proveedor: 'Cierres', reescribirAlbaran: false, numProductos: '50', serieApertura: 'A', serieCierre: 'C', familiasExcluidas: [{ id: '3', nombre: 'Frutas' }],
    });
    expect(pagina.contexto.alert).toHaveBeenCalledWith('Configuración guardada correctamente');
    expect(pagina.contexto.cerrarPopUpConTitulo).toHaveBeenCalledWith('Configuración XML - cierre_stock_anual');
  });

  /**
   * @etiquetas estado-actual
   *
   * @comportamiento Si la respuesta del guardado trae un error, avisa con su motivo y deja abierta la
   *   ventana de la configuración.
   */
  test('si el guardado responde un error, avisa del motivo y deja abierta la configuración', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.guardarConfiguracionXML('cierre_stock_anual');
    pagina.responder({ error: true, mensaje: 'Falta el número de productos' });

    expect(pagina.contexto.alert).toHaveBeenCalledWith('Error al guardar configuración: Falta el número de productos');
    expect(pagina.contexto.cerrarPopUpConTitulo).not.toHaveBeenCalled();
  });

  /**
   * @etiquetas estado-actual
   *
   * @comportamiento Añadir una familia excluida pide su número y su nombre, no repite una que ya está y
   *   la añade a la tabla; después vacía y vuelve a activar las cajas de la familia.
   */
  test('añadir una familia excluida pide número y nombre, no la repite y la añade a la tabla', () => {
    const vacia = paginaDePrueba({ id_familia: { valor: '' }, Familia: { valor: '' } });
    const repetida = paginaDePrueba({ id_familia: { valor: '3' }, Familia: { valor: 'Frutas' } }, [{ id: '3', nombre: 'Frutas' }]);
    const nueva = paginaDePrueba({ id_familia: { valor: '7' }, Familia: { valor: 'Lácteos', desactivado: true } });

    vacia.contexto.agregarFamilia();
    repetida.contexto.agregarFamilia();
    nueva.contexto.agregarFamilia();

    expect(vacia.contexto.alert).toHaveBeenCalledWith('Debe ingresar ID y Nombre');
    expect(repetida.contexto.alert).toHaveBeenCalledWith('Ese ID ya existe');
    expect(repetida.filasNuevas).toHaveLength(0);
    expect(nueva.filasNuevas.map((fila) => fila.atributos['data-id'])).toEqual(['7']);
    expect(nueva.filasNuevas[0].innerHTML).toContain('>Lácteos</td>');
    expect([nueva.elementos.id_familia.valor, nueva.elementos.Familia.valor, nueva.elementos.Familia.desactivado]).toEqual(['', '', false]);
  });

  /**
   * @etiquetas esperado pantalla alto
   * @estado rojo
   * @codigo-afectado modulos/mod_reorganizacion/funciones.js:446-457
   *
   * @que-ocurre-hoy El nombre de la familia excluida se pega tal cual en la fila nueva: una familia con
   *   código en el nombre lo ejecuta en la pantalla de quien configura el cierre.
   * @que-deberia-ocurrir Que el número y el nombre se escriban como texto.
   * @por-que-ocurre La fila se compone como HTML con el número y el nombre sin escapar.
   * @como-deberia-funcionar Escribir el número y el nombre como texto de sus celdas.
   */
  test('añadir una familia excluida escribe su nombre como texto', () => {
    const pagina = paginaDePrueba({ id_familia: { valor: '7' }, Familia: { valor: '<img src=x onerror=alert(1)>' } });

    pagina.contexto.agregarFamilia();

    expect(pagina.filasNuevas[0].innerHTML).not.toContain('<img');
  });
});

describe('la búsqueda de una familia', () => {
  /**
   * @etiquetas estado-actual
   *
   * @comportamiento Buscar una familia con varias coincidencias abre su lista en la ventana modal, con
   *   el foco en la caja de búsqueda.
   */
  test('con varias coincidencias abre la lista en la ventana modal', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.buscarFamilia('reorganizarTPV', 'Familia', 'fru');
    pagina.responder({ Nitems: 2, id: 0, html: { html: '<table>familias</table>' } });

    expect(pagina.peticiones[0].data).toEqual({ pulsado: 'buscarFamilias', busqueda: 'fru', dedonde: 'reorganizarTPV', idcaja: 'Familia' });
    expect(pagina.contexto.abrirModalConTitulo).toHaveBeenCalledWith('Listado Familias ', '<table>familias</table>');
    expect(pagina.contexto.focusAlLanzarModal).toHaveBeenCalledWith('cajaBusquedafamilia');
  });

  /**
   * @etiquetas esperado pantalla bajo
   * @estado rojo
   * @codigo-afectado modulos/mod_reorganizacion/funciones.js:578-583,704-706
   *
   * @que-ocurre-hoy Buscar una familia —o un proveedor— por su número la elige, y termina con un error
   *   en la consola sin pasar el foco.
   * @que-deberia-ocurrir Que la elija y pase el foco sin errores.
   * @por-que-ocurre Después de elegirla, pide el foco de la línea de productos a una variable que esta
   *   pantalla no declara.
   * @como-deberia-funcionar Pasar el foco a la caja que corresponde en esta pantalla.
   */
  test('por su número la elige sin errores', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.buscarFamilia('reorganizarTPV', 'id_familia', '3');
    let error = null;
    try {
      pagina.responder({ Nitems: 1, id: '3', nombre: 'Frutas' });
    } catch (lanzado) {
      error = lanzado;
    }

    expect([pagina.elementos.id_familia.valor, pagina.elementos.Familia.valor]).toEqual(['3', 'Frutas']);
    expect(error).toBeNull();
  });
});
