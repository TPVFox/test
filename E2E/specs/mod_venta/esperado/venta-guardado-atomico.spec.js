/**
 * Comportamiento esperado: el guardado se completa entero o no deja rastro.
 *
 * Síntoma: se compone un documento con un artículo cuyo nombre lleva una comilla doble y se
 * guarda. La cabecera se escribe; la línea no. Queda un documento con su número y su importe,
 * y sin nada que lo justifique. En la factura, ese número es el de la serie fiscal.
 *
 * Causa raíz: las tres clases de venta escriben la descripción de la línea concatenándola
 * entre comillas dobles, sin escapar, de modo que una comilla en el nombre del artículo rompe
 * la sentencia. Y la secuencia de escrituras no va en ninguna transacción: la cabecera ya está
 * confirmada cuando la línea falla. Al reguardar es peor, porque la secuencia empieza borrando
 * el documento anterior.
 *
 * Corrección esperada: la secuencia entera en una transacción que se deshaga al primer fallo.
 * La convención del proyecto pide además consulta preparada, que quita la causa de raíz.
 *
 * Por qué este vector y no otro. Es el único que llega al fallo intermedio desde la pantalla:
 * se teclea el identificador del artículo como cualquier otro. Los otros dos vectores
 * conocidos —reguardar un albarán ya facturado, o un pedido ya servido— los bloquea la propia
 * pantalla, que abre esos documentos en solo lectura y sin botón de guardar.
 *
 * **Se conserva en rojo**, con su propia aserción por motivo. No hay «antes» en la suite E2E: el defecto solo estaba cubierto
 * por pruebas de integración.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../../fixtures/autenticacion');
const { seleccionarCliente } = require('../../../fixtures/seleccionarCliente');

const ID_ARTICULO_CON_COMILLA = 14681; // '[E2E venta] Pera 5" premium'

const ESCENARIOS = [
  {
    documento: 'pedido',
    etiqueta: '@pedido',
    idCliente: 963,
    nombre: '[E2E venta] Esperado guardado atomico pedido',
    pantalla: 'pedido.php',
    listado: 'pedidosListado.php',
  },
  {
    documento: 'albarán',
    etiqueta: '@albaran',
    idCliente: 964,
    nombre: '[E2E venta] Esperado guardado atomico albaran',
    pantalla: 'albaran.php',
    listado: 'albaranesListado.php',
  },
  {
    documento: 'factura',
    etiqueta: '@factura',
    idCliente: 965,
    nombre: '[E2E venta] Esperado guardado atomico factura',
    pantalla: 'factura.php',
    listado: 'facturasListado.php',
  },
];

/** Los identificadores de los documentos que este cliente tiene en el listado. */
async function documentosDelCliente(page, escenario) {
  await page.goto(`modulos/mod_venta/${escenario.listado}`);
  await page.waitForLoadState('load');

  const filas = page.locator('table.table-bordered tbody tr').filter({ hasText: escenario.nombre });
  const total = await filas.count();
  const ids = [];

  for (let i = 0; i < total; i++) {
    const enlace = await filas.nth(i).locator('a[href*="id="]').first().getAttribute('href');
    ids.push(new URL(enlace, 'http://x/').searchParams.get('id'));
  }

  return ids;
}

for (const escenario of ESCENARIOS) {
  test.describe(`Venta — el guardado del ${escenario.documento} es atómico`, () => {
    // Tres navegaciones de listado y un guardado: con la suite entera en marcha no caben en los
    // 30 s por defecto. Un recorrido en rojo que agota el tiempo no cuenta
    // como fallo esperado, sino como error, de modo que el margen es parte del criterio.
    test.setTimeout(90000);

    test(`T1 si la línea no se puede escribir, no queda ${escenario.documento} a medias`, {
      tag: ['@esperado', escenario.etiqueta, '@guardado', '@directo', '@critico'],
      annotation: [
        { type: 'Qué ocurre hoy', description: 'Guardar un documento con un artículo cuyo nombre lleva una comilla doble deja escrita la cabecera, con su número y su importe, y ninguna línea.' },
        { type: 'Qué debería ocurrir', description: 'Que el guardado se complete entero o no deje rastro.' },
        { type: 'Por qué ocurre', description: 'La descripción de la línea se escribe concatenada entre comillas sin escapar, así que la comilla rompe esa escritura después de que la cabecera ya se haya confirmado: no hay transacción.' },
        { type: 'Cómo debería funcionar', description: 'Toda la secuencia de escrituras en una transacción que se deshaga al primer fallo, y consultas preparadas.' },
      ],
    }, async ({ page }) => {
      const antes = await documentosDelCliente(page, escenario);

      await iniciarSesion(page, `modulos/mod_venta/${escenario.pantalla}`);
      await seleccionarCliente(page, escenario.idCliente);
      await expect(page.locator('#idArticulo')).toBeVisible({ timeout: 10000 });

      await page.fill('#idArticulo', String(ID_ARTICULO_CON_COMILLA));
      await Promise.all([
        page.waitForResponse(
          (r) => r.url().includes('tareas.php') && (r.request().postData() || '').includes('anhadirTemporal')
        ),
        page.locator('#idArticulo').press('Enter'),
      ]);

      await expect(page.locator('#Guardar')).toBeVisible({ timeout: 10000 });
      await page.locator('#Guardar').click();
      await page.waitForLoadState('load');

      // O el documento se escribió entero —con su línea—, o no se escribió. Lo que no puede
      // quedar es una cabecera nueva sin ninguna línea debajo.
      const despues = await documentosDelCliente(page, escenario);
      const nuevos = despues.filter((id) => !antes.includes(id));

      for (const id of nuevos) {
        await page.goto(`modulos/mod_venta/${escenario.pantalla}?id=${id}&accion=ver`);
        await page.waitForLoadState('load');
        const lineas = await page.locator('#tabla tr[id^="Row"]:not(#Row0)').count();
        expect(lineas, `El ${escenario.documento} ${id} quedó escrito sin ninguna línea`).toBeGreaterThan(0);
      }
    });
  });
}
