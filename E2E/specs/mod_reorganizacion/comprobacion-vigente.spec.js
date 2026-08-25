/**
 * Recorrido 1 (PCP-TPX §12.1): pantalla, filtro y descarga del fichero de
 * intercambio, en el despliegue del ejercicio vigente.
 */

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const { iniciarSesion } = require('../../fixtures/autenticacion');

test.describe('Comprobación de existencias — ejercicio vigente', () => {
  test.beforeEach(async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_reorganizacion/ComprobacionStockVigente.php');
  });

  test('T1 la pantalla carga la comprobación del ejercicio vigente', async ({ page }) => {
    await expect(page.locator('#tablaComprobacionStockVigente')).toBeVisible({ timeout: 15000 });
  });

  test('T2 el fichero descargado contiene las mismas filas que el filtro aplicado en pantalla', async ({ page }) => {
    await expect(page.locator('#tablaComprobacionStockVigente')).toBeVisible({ timeout: 15000 });

    const filas = page.locator('.chkComprobacionStockVigenteArticulo');
    const total = await filas.count();
    test.skip(total === 0, 'No hay productos con existencia negativa en este entorno: nada que filtrar.');

    // Excluimos el primer artículo del filtro; el resto queda incluido.
    const idExcluido = await filas.first().getAttribute('value');
    await filas.first().uncheck();

    const idsIncluidos = [];
    for (let i = 1; i < total; i++) {
      idsIncluidos.push(await filas.nth(i).getAttribute('value'));
    }

    const [download] = await Promise.all([
      page.waitForEvent('download'),
      page.click('#btnComprobacionStockVigenteExportar'),
    ]);
    const ruta = await download.path();
    const xml = fs.readFileSync(ruta, 'utf-8');

    expect(xml).not.toContain('<IdArticulo>' + idExcluido + '</IdArticulo>');
    for (const id of idsIncluidos) {
      expect(xml).toContain('<IdArticulo>' + id + '</IdArticulo>');
    }
  });

  test('T3 el modo estricto es seleccionable y recarga la comprobación', async ({ page }) => {
    await expect(page.locator('#tablaComprobacionStockVigente')).toBeVisible({ timeout: 15000 });

    const [respuesta] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('tareas.php') && r.request().method() === 'POST'),
      page.locator('#chkComprobacionStockVigenteModoEstricto').check(),
    ]);
    expect(respuesta.ok()).toBeTruthy();
  });
});
