/**
 * El JavaScript del etiquetado (`modulos/mod_etiquetado/funciones.js`): en el listado, abrir la ficha
 * del lote marcado o la de crear, y contar e imprimir las etiquetas de los marcados; en la ficha, el tipo,
 * repetir el producto, cambiar una línea y recalcular su código, quitarla o recuperarla, buscar el
 * producto y guardar el temporal.
 *
 * El fichero se ejecuta en un contexto propio de Node, con un jQuery de prueba que guarda lo que se
 * escribe en cada caja y apunta las peticiones sin enviarlas: el caso responde por la petición. La
 * cabecera y las líneas del lote, que en la página escribe el servidor, y la lista de marcados, que llena
 * una función común, las da el caso.
 */

'use strict';

const path = require('path');
const { cargarScriptGlobal } = require('../../../support/js/cargarScriptGlobal');

const FUNCIONES = path.resolve(__dirname, '../../../../TPVFox/modulos/mod_etiquetado/funciones.js');

/** Una página de prueba, con los lotes marcados, la cabecera y las líneas del lote. */
function paginaDePrueba({ marcados = [], cabecera = {}, productos = [], confirma = true } = {}) {
  const pagina = { peticiones: [], avisos: [], confirmaciones: [], modales: [], html: {}, valores: {}, clases: {}, anadido: [], abiertos: [], direccion: '' };
  const $ = (selector) => {
    let contenido = '';
    const caja = {
      val: (valor) => { pagina.valores[selector] = valor; return caja; },
      html: (nuevo) => { contenido = nuevo; pagina.html[selector] = nuevo; return caja; },
      text: () => contenido,
      prop: () => caja,
      select: () => caja,
      focus: () => caja,
      css: () => caja,
      animate: () => caja,
      attr: () => caja,
      addClass: (clase) => { pagina.clases[selector] = clase; return caja; },
      removeClass: () => { pagina.clases[selector] = ''; return caja; },
      append: (filas) => { pagina.anadido.push(filas); return caja; },
    };
    return caja;
  };
  $.ajax = (opciones) => { pagina.peticiones.push(opciones); };
  $.parseJSON = JSON.parse;
  pagina.contexto = cargarScriptGlobal(FUNCIONES, {
    $,
    checkID: [],
    cabecera: { tipo: '1', idTemporal: 0, idReal: 0, estado: 'Activo', idProducto: '5', idTienda: 1, idUsuario: 2, fechaEnv: '2026-10-23 10:00:00', fechaCad: '2026-11-01', numAlb: '', ...cabecera },
    productos,
    alert: (texto) => { pagina.avisos.push(texto); },
    confirm: (texto) => { pagina.confirmaciones.push(texto); return confirma; },
    abrirModal: (titulo, html) => { pagina.modales.push({ titulo, html }); },
    cerrarPopUp: () => {},
    focusAlLanzarModal: () => {},
    history: { pushState: (estado, titulo, direccion) => { pagina.direccion = direccion; } },
    window: { location: { href: '' }, open: (direccion) => { pagina.abiertos.push(direccion); } },
    setTimeout: () => 0,
    console: { log() {} },
  });
  pagina.contexto.VerIdSeleccionado = () => { pagina.contexto.checkID = [...marcados]; };
  pagina.responder = (respuesta) => pagina.peticiones[pagina.peticiones.length - 1].success(JSON.stringify(respuesta));
  pagina.pedidas = () => pagina.peticiones.map((peticion) => peticion.data.pulsado);
  return pagina;
}

/** Una caja de la ficha, con su valor y la fila que le toca. */
function caja(valor, fila = '') {
  return { darValor: () => valor, fila, id_input: `peso_${fila}`, parametros: { prefijo: 'peso_' } };
}

function linea(fila, nombre = 'Pan de molde') {
  return { nombre, peso: '1', precio: '3.5', Fecha: '2026-11-01', NumAlb: '', codBarras: '2112345003504', estado: 'Activo', Nfila: fila, crefTienda: '12345' };
}

describe('en el listado de lotes', () => {
  test('modificar abre la ficha del único lote marcado', () => {
    const pagina = paginaDePrueba({ marcados: ['7'] });

    pagina.contexto.metodoClick('Ver', 'etiquetaCodBarras');

    expect(pagina.contexto.window.location.href).toBe('./etiquetaCodBarras.php?id=7');
  });

  test('modificar sin ningún lote marcado, o con dos, avisa y no abre nada', () => {
    const ninguno = paginaDePrueba();
    const dos = paginaDePrueba({ marcados: ['7', '8'] });

    ninguno.contexto.metodoClick('Ver', 'etiquetaCodBarras');
    dos.contexto.metodoClick('Ver', 'etiquetaCodBarras');

    expect(ninguno.avisos).toHaveLength(1);
    expect(dos.avisos).toHaveLength(1);
    expect(dos.contexto.window.location.href).toBe('');
  });

  test('añadir abre la ficha de crear', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.metodoClick('Agregar', 'etiquetaCodBarras');

    expect(pagina.contexto.window.location.href).toBe('./etiquetaCodBarras.php');
  });

  test('imprimir cuenta las etiquetas de los marcados, avisa de cuántas faltan para la hoja y, si se acepta, abre el PDF', () => {
    const pagina = paginaDePrueba({ marcados: ['7'] });

    pagina.contexto.metodoClick('Imprimir', 'etiquetaCodBarras');
    pagina.responder({ etiquetas: 5 });
    pagina.responder('/tmp/etiquetas.pdf');

    expect(pagina.peticiones[0].data).toEqual({ pulsado: 'contarEtiquetas', lotes: ['7'] });
    expect(pagina.confirmaciones).toEqual(['Te faltan etiquetas 11 para llegar a 16 (hoja entera)']);
    expect(pagina.peticiones[1].data).toEqual({ pulsado: 'imprimirEtiquetas', lotes: ['7'] });
    expect(pagina.abiertos).toEqual(['/tmp/etiquetas.pdf']);
  });

  test('con dieciséis etiquetas avisa de la hoja entera, con más de que se pasa, y sin aceptar no imprime', () => {
    const justas = paginaDePrueba({ marcados: ['7'] });
    const demasiadas = paginaDePrueba({ marcados: ['7'], confirma: false });

    justas.contexto.contarEtiquetasLote(['7']);
    justas.responder({ etiquetas: 16 });
    demasiadas.contexto.contarEtiquetasLote(['7']);
    demasiadas.responder({ etiquetas: 17 });

    expect(justas.confirmaciones).toEqual(['Has seleccionado justo las etiquetas a una página']);
    expect(demasiadas.confirmaciones).toEqual(['Te has sobrepasado de las etiquetas por hoja. El formato de impresión puede que no coincida']);
    expect(demasiadas.pedidas()).toEqual(['contarEtiquetas']);
  });

  /**
   * @etiquetas esperado impreso bajo
   * @estado rojo
   * @codigo-afectado modulos/mod_etiquetado/funciones.js:13-17
   * @codigo-afectado modulos/mod_etiquetado/funciones.js:490-505
   *
   * @que-ocurre-hoy Imprimir sin ningún lote marcado avisa de que faltan dieciséis etiquetas y, si se
   *   acepta, pide la impresión de nada: un PDF con una hoja en blanco.
   * @que-deberia-ocurrir Que avise de que no hay lotes marcados, sin pedir nada.
   * @por-que-ocurre Se cuentan las etiquetas sin mirar si hay marcados, y el aviso de que no hay lotes está
   *   detrás de uno que siempre se cumple.
   * @como-deberia-funcionar Comprobar antes que hay algún lote marcado.
   */
  test('imprimir sin ningún lote marcado avisa y no pide la impresión', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.metodoClick('Imprimir', 'etiquetaCodBarras');
    if (pagina.peticiones.length > 0) {
      pagina.responder({ etiquetas: 0 });
    }

    expect(pagina.pedidas()).not.toContain('imprimirEtiquetas');
    expect([...pagina.avisos, ...pagina.confirmaciones].join(' ')).toContain('No tienes lotes seleccionado');
  });
});

describe('en la ficha de un lote', () => {
  test('cambiar el tipo cambia el título de la columna y recalcula el código de cada línea', () => {
    const pagina = paginaDePrueba({ productos: [linea(1), linea(2)] });

    pagina.contexto.modificarTipo('2');

    expect(pagina.html['#tipoTabla']).toBe('Peso');
    expect(pagina.contexto.cabecera.tipo).toBe('2');
    expect(pagina.peticiones.map((peticion) => [peticion.data.pulsado, peticion.data.tipo])).toEqual([['modificarCodigoBarras', '2'], ['modificarCodigoBarras', '2']]);
  });

  test('repetir el producto lo pide con el tipo elegido; sin tipo o sin cantidad, avisa', () => {
    const pagina = paginaDePrueba();
    const sinTipo = paginaDePrueba({ cabecera: { tipo: '0' } });

    pagina.contexto.controladorAcciones(caja('3'), 'RepetirProducto');
    pagina.contexto.controladorAcciones(caja('0'), 'RepetirProducto');
    sinTipo.contexto.controladorAcciones(caja('3'), 'RepetirProducto');

    expect(pagina.peticiones[0].data).toMatchObject({ pulsado: 'repetirProductos', unidades: '3', idProducto: '5', tipo: '1', fechaCad: '2026-11-01' });
    expect(pagina.avisos).toEqual(['No has escrito ninguna cantidad']);
    expect(sinTipo.avisos).toEqual(['No has seleccionado TIPO']);
  });

  test('las líneas repetidas se añaden a la tabla y a las del lote, y se guardan en el temporal', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.repetirProducto('1', '1');
    pagina.responder({ html: '<tr id="Row1"></tr>', productos: [linea(1)] });

    expect(pagina.anadido).toEqual(['<tr id="Row1"></tr>']);
    expect(pagina.contexto.productos).toHaveLength(1);
    expect(pagina.peticiones[1].data.pulsado).toBe('addEtiquetadoTemporal');
  });

  test('guardar el temporal la primera vez pone su número en la dirección', () => {
    const pagina = paginaDePrueba({ productos: [linea(1)] });

    pagina.contexto.addEtiquetadoTemporal();
    pagina.responder({ existe: 0, idTemporal: 12 });

    expect(pagina.peticiones[0].data).toMatchObject({ pulsado: 'addEtiquetadoTemporal', idProducto: '5', tipo: '1', productos: [linea(1)] });
    expect(pagina.direccion).toBe('?tActual=12');
    expect(pagina.contexto.cabecera.idTemporal).toBe(12);
  });

  test('cambiar la cantidad de una línea la valida y recalcula su código', () => {
    const pagina = paginaDePrueba({ cabecera: { tipo: '2' }, productos: [linea(1)] });

    pagina.contexto.controladorAcciones(caja('1.5', '1'), 'modificarPesoProducto');
    pagina.contexto.controladorAcciones(caja('12.5', '1'), 'modificarPesoProducto');

    expect(pagina.contexto.productos[0].peso).toBe('1.5');
    expect(pagina.pedidas()).toEqual(['modificarCodigoBarras']);
    expect(pagina.avisos).toEqual(['Error en el formato del número']);
  });

  test('el código recalculado se pone en su línea y se guarda el temporal', () => {
    const pagina = paginaDePrueba({ productos: [linea(1)] });

    pagina.contexto.modificarCodigoBarras(0);
    pagina.responder({ codBarras: '2012345015003' });

    expect(pagina.contexto.productos[0].codBarras).toBe('2012345015003');
    expect(pagina.html['#codigoBarras_1']).toBe('2012345015003');
    expect(pagina.pedidas()).toEqual(['modificarCodigoBarras', 'addEtiquetadoTemporal']);
  });

  test('cambiar el nombre o el albarán de una línea se guarda en el temporal', () => {
    const pagina = paginaDePrueba({ productos: [linea(1)] });

    pagina.contexto.controladorAcciones({ ...caja('Pan integral', '1'), id_input: 'nombre_1' }, 'modificarNombreProducto');
    pagina.contexto.controladorAcciones({ ...caja('15', '1'), id_input: 'numAlb_1' }, 'modificarNumeroAlbaranProducto');

    expect([pagina.contexto.productos[0].nombre, pagina.contexto.productos[0].NumAlb]).toEqual(['Pan integral', '15']);
    expect(pagina.pedidas()).toEqual(['addEtiquetadoTemporal', 'addEtiquetadoTemporal']);
  });

  test('quitar una línea la marca como quitada y la tacha; recuperarla la vuelve activa', () => {
    const pagina = paginaDePrueba({ productos: [linea(1)] });

    pagina.contexto.eliminarFila(1, 'etiquetas');
    const quitada = pagina.contexto.productos[0].estado;
    pagina.contexto.retornarFila(1, 'etiquetas');

    expect(quitada).toBe('Eliminado');
    expect(pagina.contexto.productos[0].estado).toBe('Activo');
    expect(pagina.clases['#Row1']).toBe('');
    expect(pagina.pedidas()).toEqual(['addEtiquetadoTemporal', 'addEtiquetadoTemporal']);
  });

  test('buscar un producto con un solo resultado lo elige; con varios, abre la búsqueda', () => {
    const uno = paginaDePrueba({ cabecera: { idProducto: '' } });
    const varios = paginaDePrueba();

    uno.contexto.buscarProducto('5', 'id_producto');
    uno.responder({ Nitem: 1, datos: { idArticulo: 5, articulo_name: 'Pan de molde' } });
    varios.contexto.buscarProducto('pan', 'producto');
    varios.responder({ html: '<table></table>' });

    expect(uno.contexto.cabecera.idProducto).toBe(5);
    expect(uno.valores['#producto']).toBe('Pan de molde');
    expect(varios.modales).toEqual([{ titulo: 'Listado De Productos ', html: '<table></table>' }]);
  });

  /**
   * @etiquetas esperado bajo
   * @estado rojo
   * @codigo-afectado modulos/mod_etiquetado/funciones.js:243-248
   *
   * @que-ocurre-hoy Las líneas repetidas ponen su nombre en las primeras filas de la tabla, no en las
   *   suyas: con líneas ya puestas, se sobrescribe el nombre que enseñaban.
   * @que-deberia-ocurrir Que cada línea ponga su nombre en su propia fila.
   * @por-que-ocurre El nombre se pone en la fila de la posición de la línea en la respuesta, que empieza
   *   por la primera, y no en la de su número.
   * @como-deberia-funcionar Poner el nombre en la fila del número de la línea.
   */
  test('las líneas repetidas ponen su nombre en sus propias filas', () => {
    const pagina = paginaDePrueba({ productos: [linea(1), linea(2)] });

    pagina.contexto.repetirProducto('1', '1');
    pagina.responder({ html: '', productos: [linea(3, 'Pan nuevo')] });

    expect(Object.keys(pagina.valores)).toEqual(['#nombre_3']);
  });

  /**
   * @etiquetas esperado guardado medio
   * @estado rojo
   * @codigo-afectado modulos/mod_etiquetado/funciones.js:285-305
   *
   * @que-ocurre-hoy Si la petición que guarda el temporal falla, la ficha no avisa: el cambio se ve en
   *   pantalla y no está guardado.
   * @que-deberia-ocurrir Que avise de que el cambio no se guardó.
   * @por-que-ocurre La petición solo atiende la respuesta correcta.
   * @como-deberia-funcionar Atender también el fallo de la petición y avisar con un mensaje.
   */
  test('guardar el temporal, si la petición falla, avisa de que no se guardó', () => {
    const pagina = paginaDePrueba({ productos: [linea(1)] });

    pagina.contexto.addEtiquetadoTemporal();
    if (pagina.peticiones[0].error) {
      pagina.peticiones[0].error({ status: 500 }, 'error', 'Internal Server Error');
    }

    expect(pagina.avisos).toHaveLength(1);
  });
});
