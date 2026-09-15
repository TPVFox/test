/**
 * Criterio de aceptación: un albarán incorporado a una factura no aparece para otra.
 *
 * Síntoma: se incorpora un albarán a una factura y, desde una segunda factura del mismo
 * cliente, se teclea su número: vuelve a aparecer y entra. La misma mercancía queda en dos
 * facturas.
 *
 * Por qué ocurre. La protección existe y está en el navegador: al incorporar el albarán lo
 * marca como procesado, y la búsqueda por número solo ofrece albaranes en «Guardado». Pero esa
 * marca viaja con el **número** del albarán y el servidor la aplica por **identificador**.
 * Mientras los dos coinciden, la protección funciona por casualidad; cuando no coinciden, la
 * marca va a parar a otro documento —o a ninguno— y el albarán incorporado sigue ofreciéndose.
 * La base no lo impide tampoco: nada comprueba si un albarán ya está enlazado a otra factura.
 *
 * Corrección esperada: que el cambio de estado se aplique al albarán que se incorporó, por su
 * identificador, y que el guardado de la factura compruebe que el albarán no esté ya facturado.
 * Comparte causa con la numeración (número frente a identificador), pero el síntoma que se mide
 * aquí es propio: facturar dos veces lo mismo.
 *
 * El recorrido no llega a guardar la primera factura. Si la guardara, el enlace con un número
 * que no es identificador lo rechazaría la clave ajena, que es otro defecto distinto; basta con
 * que el albarán esté incorporado en un documento en curso para que la marca tenga que actuar.
 *
 * T2 es el control positivo: con número e identificador iguales, la protección funciona hoy y
 * tiene que seguir funcionando después de la corrección.
 *
 * T1 está declarado con `test.fail()`. No hay «antes» en la suite E2E: el defecto solo estaba
 * cubierto por pruebas de integración, que lo alcanzan por la clase y no por este camino.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../../fixtures/autenticacion');
const { seleccionarCliente } = require('../../../fixtures/seleccionarCliente');
const { esperarTarea } = require('../../../fixtures/borradorFactura');

const ID_CLIENTE = 960; // '[E2E venta] CC albaran facturado'
const NOMBRE_CLIENTE = '[E2E venta] CC albaran facturado';
const NUMERO_DESALINEADO = 830960; // sembrado: su número no es el identificador de ningún documento

/** Abre una factura nueva del cliente, con la caja de adjuntos lista. */
async function facturaNueva(page) {
  await page.goto('modulos/mod_venta/factura.php');
  await seleccionarCliente(page, ID_CLIENTE);
  await expect(page.locator('#numAdjunto')).toBeVisible({ timeout: 10000 });
}

/** Teclea un número de albarán en la factura abierta y espera la respuesta del servidor. */
async function teclearAlbaran(page, numero) {
  await page.fill('#numAdjunto', String(numero));
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('tareas.php')),
    page.locator('#numAdjunto').press('Enter'),
  ]);
  await page.waitForTimeout(1500);
}

/** El número del albarán sembrado cuyo número coincide con su identificador. */
async function numeroDelAlbaranAlineado(page) {
  await page.goto('modulos/mod_venta/albaranesListado.php');
  await page.waitForLoadState('networkidle');

  const filas = page.locator('table.table-bordered tbody tr').filter({ hasText: NOMBRE_CLIENTE });
  const total = await filas.count();

  for (let i = 0; i < total; i++) {
    const fila = filas.nth(i);
    const enlace = await fila.locator('a[title="Ver albarán"]').getAttribute('href');
    const id = Number(new URL(enlace, 'http://x/').searchParams.get('id'));
    const numero = Number((await fila.locator('td').allInnerTexts())[3].trim());
    if (id === numero) {
      return numero;
    }
  }

  throw new Error('La siembra deja un albarán de este cliente con número igual a su identificador');
}

test.describe('Albarán — no se incorpora a dos facturas', { tag: '@criterio' }, () => {
  test.beforeEach(async ({ page }) => {
    // Si la búsqueda no encuentra el albarán, la pantalla puede avisar con un diálogo: se
    // acepta para que no bloquee el recorrido.
    page.on('dialog', (dialogo) => dialogo.accept());
  });

  test.fail('T1 un albarán con número distinto de su identificador no reaparece para otra factura', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/facturasListado.php');

    // Primera factura: el albarán entra y el navegador pide marcarlo como procesado.
    await facturaNueva(page);
    await Promise.all([
      esperarTarea(page, 'modificarEstadoDocumento', 'Procesado'),
      teclearAlbaran(page, NUMERO_DESALINEADO),
    ]);
    await expect(page.locator('#tablaAdjunto tbody tr'), 'El albarán entra en la primera factura').toHaveCount(1);

    // Segunda factura del mismo cliente: ese albarán ya no debería ofrecerse.
    await facturaNueva(page);
    await teclearAlbaran(page, NUMERO_DESALINEADO);

    await expect(page.locator('#tablaAdjunto tbody tr')).toHaveCount(0, { timeout: 2000 });
  });

  test('T2 un albarán con número igual a su identificador no reaparece para otra factura', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/facturasListado.php');
    const numero = await numeroDelAlbaranAlineado(page);

    await facturaNueva(page);
    await Promise.all([
      esperarTarea(page, 'modificarEstadoDocumento', 'Procesado'),
      teclearAlbaran(page, numero),
    ]);
    await expect(page.locator('#tablaAdjunto tbody tr'), 'El albarán entra en la primera factura').toHaveCount(1);

    await facturaNueva(page);
    await teclearAlbaran(page, numero);

    await expect(page.locator('#tablaAdjunto tbody tr')).toHaveCount(0, { timeout: 2000 });
  });
});
