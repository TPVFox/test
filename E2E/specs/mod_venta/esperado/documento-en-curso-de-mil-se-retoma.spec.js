/**
 * Retomar un albarán en curso que tiene una línea de mil unidades.
 *
 * El operador compone un albarán, teclea mil unidades en una línea, sale sin guardar y vuelve
 * a entrar en el documento en curso. A diferencia de adjuntar o de abrir un documento ya
 * guardado, aquí las líneas no pasan por la función que les pone separador de miles: se leen
 * del documento en curso tal como el navegador las dejó. Lo que sí se formatea es la caja de
 * unidades de cada fila, que se pinta como «1,000.00».
 *
 * Los dos casos separan lo que se ve de lo que se guarda.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../../fixtures/autenticacion');
const { seleccionarCliente } = require('../../../fixtures/seleccionarCliente');

const ID_ARTICULO = 14678; // '[E2E venta] Manzana Golden'

function peticion(nombre) {
  return (r) => r.url().includes('tareas.php') && (r.request().postData() || '').includes(nombre);
}

/** Compone un albarán con una línea de mil unidades, sale y vuelve al documento en curso. */
async function componerSalirYVolver(page, idCliente) {
  await iniciarSesion(page, 'modulos/mod_venta/albaran.php');
  await seleccionarCliente(page, idCliente);
  await expect(page.locator('#idArticulo')).toBeVisible({ timeout: 10000 });

  await page.fill('#idArticulo', String(ID_ARTICULO));
  await Promise.all([page.waitForResponse(peticion('anhadirTemporal')), page.locator('#idArticulo').press('Enter')]);

  await expect(page.locator('#Unidad_Fila_1')).toBeVisible({ timeout: 10000 });
  await page.fill('#Unidad_Fila_1', '1000');
  await Promise.all([page.waitForResponse(peticion('anhadirTemporal')), page.locator('#Unidad_Fila_1').press('Enter')]);

  await expect(page).toHaveURL(/tActual=\d+/);
  const idTemporal = new URL(page.url()).searchParams.get('tActual');

  await page.goto('modulos/mod_venta/albaranesListado.php');
  await page.waitForLoadState('load');
  await page.goto(`modulos/mod_venta/albaran.php?tActual=${idTemporal}`);
  await page.waitForLoadState('load');
  await expect(page.locator('#Unidad_Fila_1')).toBeVisible({ timeout: 10000 });
}

/** Los identificadores de los albaranes guardados que el cliente tiene en el listado. */
async function albaranesDelCliente(page, nombre) {
  await page.goto('modulos/mod_venta/albaranesListado.php');
  await page.waitForLoadState('load');

  const filas = page.locator('table.table-bordered tbody tr').filter({ hasText: nombre });
  const ids = [];
  for (let i = 0; i < (await filas.count()); i++) {
    const enlace = await filas.nth(i).locator('a[href*="id="]').first().getAttribute('href');
    ids.push(new URL(enlace, 'http://x/').searchParams.get('id'));
  }

  return ids;
}

test.describe('Albarán — retomar un documento en curso con una línea de mil unidades', () => {
  test.setTimeout(90000);

  test('T1 la caja de unidades de la línea trae un número, sin separador de miles', {
    tag: ['@esperado', '@albaran', '@borrador', '@entrada', '@directo', '@alto'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'Al volver a un albarán en curso, la caja de unidades de una línea de mil se pinta como «1,000.00».' },
      { type: 'Qué debería ocurrir', description: 'Que la caja traiga el número tal como se tecleó, sin separador de miles.' },
      { type: 'Por qué ocurre', description: 'El valor de la caja se compone con el formato de presentación, y una caja de entrada no es presentación.' },
      { type: 'Cómo debería funcionar', description: 'Sin separador de miles en el valor de una caja editable.' },
    ],
  }, async ({ page }) => {
    await componerSalirYVolver(page, 973);

    const valor = await page.locator('#Unidad_Fila_1').inputValue();
    expect(valor, 'La caja de unidades no debería llevar separador de miles').not.toContain(',');
    expect(parseFloat(valor)).toBe(1000);
  });

  test('T2 pasar por la caja de unidades sin cambiarla y guardar conserva las mil unidades', {
    tag: ['@esperado', '@albaran', '@borrador', '@guardado', '@directo', '@critico'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'Al pasar por la caja de unidades de una línea de mil sin tocarla, el navegador avisa de que el número no es correcto y deja la caja en 1.' },
      { type: 'Qué debería ocurrir', description: 'Que pasar por una caja sin cambiarla no avise de nada ni altere la línea, y que el albarán se guarde con sus mil unidades.' },
      { type: 'Por qué ocurre', description: 'La caja trae «1,000.00», y la validación del navegador rechaza un valor con separador de miles aunque el operador no lo haya tecleado.' },
      { type: 'Cómo debería funcionar', description: 'Que la caja traiga un número que su propia validación acepte.' },
    ],
  }, async ({ page }) => {
    const nombre = '[E2E venta] Esperado retomar y guardar mil';
    await componerSalirYVolver(page, 974);
    const antes = await albaranesDelCliente(page, nombre);
    await page.goBack();
    await expect(page.locator('#Unidad_Fila_1')).toBeVisible({ timeout: 10000 });

    const avisos = [];
    page.on('dialog', async (dialogo) => {
      avisos.push(dialogo.message());
      await dialogo.accept();
    });

    await page.locator('#Unidad_Fila_1').focus();
    await page.locator('#Unidad_Fila_1').press('Enter');
    await page.waitForTimeout(1500);

    await expect(page.locator('#Guardar')).toBeVisible({ timeout: 10000 });
    await page.locator('#Guardar').click();
    await page.waitForLoadState('load');

    const nuevos = (await albaranesDelCliente(page, nombre)).filter((id) => !antes.includes(id));
    expect(nuevos, 'Guardar tiene que dejar un albarán nuevo').toHaveLength(1);

    await page.goto(`modulos/mod_venta/albaran.php?id=${nuevos[0]}&accion=ver`);
    await page.waitForLoadState('load');
    const cajas = page.locator('input[id^="Unidad_Fila_"]');
    await expect(cajas, 'El albarán guardado tiene que tener su línea').toHaveCount(1);
    // Se quita el separador para medir lo guardado y no cómo se pinta, que es el otro caso.
    const guardadas = parseFloat((await cajas.first().inputValue()).replace(/,/g, ''));

    expect.soft(avisos, 'Pasar por la caja sin cambiarla no debería avisar de nada').toEqual([]);
    expect(guardadas, 'El albarán guardado tiene que conservar las mil unidades').toBe(1000);
  });
});
