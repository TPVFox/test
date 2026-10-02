/**
 * Adjuntar a un documento otro cuya línea lleva una cantidad o un precio de mil o más.
 *
 * Al traer las líneas de un pedido a un albarán —o de un albarán a una factura— el servidor
 * formatea la cantidad y el precio con separador de miles: 1000 llega al navegador como
 * «1,000». El navegador lo devuelve tal cual al guardar, y la escritura de la línea falla.
 * Para entonces el navegador ya ha pedido marcar el documento de origen como procesado, de
 * modo que el pedido deja de ofrecerse y no se puede volver a cargar.
 *
 * Cada recorrido parte de un documento de origen recién sembrado por
 * `support/sembrar-e2e-venta.php`, con su propio cliente, porque adjuntarlo lo consume. El
 * número del documento de origen se lee del listado: la siembra lo iguala a su
 * identificador, que cambia al rehacer la base.
 *
 * Lo que no se ve desde la pantalla queda en integración: que una cantidad con decimales
 * llega redondeada a entero. La pantalla enseña las unidades, que no se alteran; lo que se
 * altera es la cantidad con la que se mueven las existencias.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../../fixtures/autenticacion');
const { seleccionarCliente } = require('../../../fixtures/seleccionarCliente');

const PEDIDO_A_ALBARAN = {
  origen: 'pedido',
  listadoOrigen: 'pedidosListado.php',
  destino: 'albarán',
  elDestino: 'El albarán',
  pantalla: 'albaran.php',
  listado: 'albaranesListado.php',
};

const ALBARAN_A_FACTURA = {
  origen: 'albarán',
  listadoOrigen: 'albaranesListado.php',
  destino: 'factura',
  elDestino: 'La factura',
  pantalla: 'factura.php',
  listado: 'facturasListado.php',
};

const CASOS_QUE_HOY_FALLAN = [
  {
    ...PEDIDO_A_ALBARAN,
    titulo: 'T1 un pedido de mil unidades se guarda como albarán con su línea',
    idCliente: 968,
    nombre: '[E2E venta] Esperado adjunto mil unidades',
    tag: ['@esperado', '@pedido', '@albaran', '@adjuntos', '@guardado', '@directo', '@critico'],
    hoy: 'Un pedido con una línea de mil unidades no se puede convertir en albarán: al guardar queda un albarán con cabecera y sin ninguna línea.',
    porque: 'Al traer las líneas del pedido, la cantidad se formatea con separador de miles y ese texto vuelve como dato al guardar: la coma parte el valor en dos dentro de la sentencia de la línea.',
  },
  {
    ...PEDIDO_A_ALBARAN,
    titulo: 'T2 un pedido con un precio de mil o más se guarda como albarán con su línea',
    idCliente: 970,
    nombre: '[E2E venta] Esperado adjunto precio mil',
    tag: ['@esperado', '@pedido', '@albaran', '@adjuntos', '@importes', '@directo', '@critico'],
    hoy: 'Un pedido de una sola unidad cuyo precio sin impuestos es de mil o más tampoco se puede convertir en albarán.',
    porque: 'El precio recibe el mismo formato con separador de miles que la cantidad, y se escribe igual.',
  },
  {
    ...ALBARAN_A_FACTURA,
    titulo: 'T3 un albarán de mil unidades se factura con su línea',
    idCliente: 972,
    nombre: '[E2E venta] Esperado adjunto albaran mil',
    tag: ['@esperado', '@albaran', '@factura', '@adjuntos', '@guardado', '@directo', '@critico'],
    hoy: 'Un albarán con una línea de mil unidades no se puede facturar: la factura queda con cabecera y sin ninguna línea.',
    porque: 'Las líneas de un albarán llegan a la factura por la misma función que las de un pedido al albarán.',
  },
];

/** Los identificadores de los documentos que un cliente tiene en un listado. */
async function documentosDelCliente(page, listado, nombre) {
  await page.goto(`modulos/mod_venta/${listado}`);
  await page.waitForLoadState('load');

  const filas = page.locator('table.table-bordered tbody tr').filter({ hasText: nombre });
  const total = await filas.count();
  const ids = [];

  for (let i = 0; i < total; i++) {
    const enlace = await filas.nth(i).locator('a[href*="id="]').first().getAttribute('href');
    ids.push(new URL(enlace, 'http://x/').searchParams.get('id'));
  }

  return ids;
}

/** Teclea el número del documento de origen y espera a que sus líneas lleguen a la pantalla. */
async function adjuntar(page, numero) {
  await expect(page.locator('#numAdjunto')).toBeVisible({ timeout: 10000 });
  await page.fill('#numAdjunto', String(numero));
  await Promise.all([
    page.waitForResponse(
      (r) => r.url().includes('tareas.php') && (r.request().postData() || '').includes('buscarAdjunto')
    ),
    page.locator('#numAdjunto').press('Enter'),
  ]);
}

/**
 * Abre un documento nuevo, le adjunta el de origen y pulsa guardar. Devuelve los documentos
 * de destino que el cliente tiene de más tras el intento.
 */
async function adjuntarYGuardar(page, caso) {
  await iniciarSesion(page, `modulos/mod_venta/${caso.listadoOrigen}`);

  const origen = await documentosDelCliente(page, caso.listadoOrigen, caso.nombre);
  expect(origen, `La siembra tiene que dejar un ${caso.origen} para este cliente`).toHaveLength(1);

  const antes = await documentosDelCliente(page, caso.listado, caso.nombre);

  await page.goto(`modulos/mod_venta/${caso.pantalla}`);
  await seleccionarCliente(page, caso.idCliente);

  const temporalEscrito = page.waitForResponse(
    (r) => r.url().includes('tareas.php') && (r.request().postData() || '').includes('anhadirTemporal')
  );
  await adjuntar(page, origen[0]);
  await expect(page.locator('#tabla tr[id^="Row"]:not(#Row0)').first()).toBeVisible({ timeout: 10000 });
  await temporalEscrito;

  await expect(page.locator('#Guardar')).toBeVisible({ timeout: 10000 });
  await page.locator('#Guardar').click();
  await page.waitForLoadState('load');

  const despues = await documentosDelCliente(page, caso.listado, caso.nombre);

  return { numeroOrigen: origen[0], nuevos: despues.filter((id) => !antes.includes(id)) };
}

/** Cuántas líneas enseña la pantalla de un documento guardado. */
async function lineasDe(page, caso, id) {
  const respuesta = await page.goto(`modulos/mod_venta/${caso.pantalla}?id=${id}&accion=ver`);
  await page.waitForLoadState('load');
  expect(respuesta.status(), `La pantalla de ${caso.destino} ${id} tiene que abrirse`).toBe(200);

  return page.locator('#tabla tr[id^="Row"]:not(#Row0)').count();
}

for (const caso of CASOS_QUE_HOY_FALLAN) {
  test.describe(`Venta — adjuntar un ${caso.origen} con cantidad o precio de mil o más`, () => {
    // Cuatro navegaciones de listado, una composición y un guardado: no caben en 30 s con la
    // suite entera en marcha, y un recorrido que agota el tiempo cuenta como error, no como fallo.
    test.setTimeout(90000);

    test(caso.titulo, {
      tag: caso.tag,
      annotation: [
        { type: 'Qué ocurre hoy', description: caso.hoy },
        { type: 'Qué debería ocurrir', description: `Que ${caso.elDestino.toLowerCase()} se guarde con la línea que traía el ${caso.origen}.` },
        { type: 'Por qué ocurre', description: caso.porque },
        { type: 'Cómo debería funcionar', description: 'Que la cantidad y el precio viajen como números, sin formato de presentación.' },
      ],
    }, async ({ page }) => {
      const { nuevos } = await adjuntarYGuardar(page, caso);

      expect(nuevos, `Guardar tiene que dejar un documento nuevo: ${caso.destino}`).toHaveLength(1);
      expect(
        await lineasDe(page, caso, nuevos[0]),
        `${caso.elDestino} ${nuevos[0]} quedó sin ninguna línea`
      ).toBeGreaterThan(0);
    });
  });
}

test.describe('Venta — un pedido adjuntado no se pierde si el albarán no llega a guardarse', () => {
  test.setTimeout(120000);

  const caso = {
    ...PEDIDO_A_ALBARAN,
    idCliente: 969,
    nombre: '[E2E venta] Esperado adjunto se recarga',
  };

  test('T4 si el albarán no se guarda, el pedido se puede volver a adjuntar', {
    tag: ['@esperado', '@pedido', '@albaran', '@adjuntos', '@estados', '@directo', '@critico'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'El pedido queda marcado como procesado aunque el albarán que lo incorporaba no llegue a guardarse, y deja de ofrecerse para adjuntar: no se puede volver a cargar.' },
      { type: 'Qué debería ocurrir', description: 'Que el pedido solo deje de ofrecerse cuando el albarán que lo incorpora ha quedado guardado con sus líneas.' },
      { type: 'Por qué ocurre', description: 'El navegador pide el cambio de estado del pedido en cuanto recibe sus líneas, en una petición aparte y anterior al guardado, y nada lo deshace si el guardado falla.' },
      { type: 'Cómo debería funcionar', description: 'Cambiar el estado del pedido dentro del mismo guardado que escribe el albarán.' },
    ],
  }, async ({ page }) => {
    const { numeroOrigen, nuevos } = await adjuntarYGuardar(page, caso);

    let guardadoConLineas = false;
    for (const id of nuevos) {
      guardadoConLineas = guardadoConLineas || (await lineasDe(page, caso, id)) > 0;
    }

    // Segundo intento, desde un albarán nuevo.
    await page.goto(`modulos/mod_venta/${caso.pantalla}`);
    await seleccionarCliente(page, caso.idCliente);
    await adjuntar(page, numeroOrigen);
    const seOfrece = (await page.locator('#tabla tr[id^="Row"]:not(#Row0)').count()) > 0;

    // Las dos mitades de la misma regla: disponible si y solo si no quedó incorporado. Así el
    // caso sigue valiendo el día que el guardado funcione y el pedido deba dejar de ofrecerse.
    expect(
      seOfrece,
      guardadoConLineas
        ? 'El albarán quedó guardado con sus líneas: el pedido ya no debería ofrecerse'
        : 'El albarán no quedó guardado con sus líneas: el pedido debería seguir ofreciéndose'
    ).toBe(!guardadoConLineas);
  });
});

test.describe('Venta — adjuntar un pedido por debajo de mil unidades', () => {
  test.setTimeout(90000);

  const caso = {
    ...PEDIDO_A_ALBARAN,
    idCliente: 971,
    nombre: '[E2E venta] Esperado adjunto control 999',
  };

  test('T5 un pedido de 999 unidades se guarda como albarán con su línea', {
    tag: ['@control', '@pedido', '@albaran', '@adjuntos', '@guardado'],
    annotation: [
      { type: 'Para qué sirve', description: 'Recorre el mismo camino con una cantidad que no lleva separador de miles. Demuestra que lo que rompe los otros casos es el valor y no el recorrido, y tiene que seguir pasando cuando se corrija.' },
    ],
  }, async ({ page }) => {
    const { nuevos } = await adjuntarYGuardar(page, caso);

    expect(nuevos).toHaveLength(1);
    expect(await lineasDe(page, caso, nuevos[0])).toBeGreaterThan(0);
  });
});
