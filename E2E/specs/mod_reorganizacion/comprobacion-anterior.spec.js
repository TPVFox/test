/**
 * Recorrido 2 (PCP-TPX §12.1): admisión del fichero, clasificación en pantalla y
 * descarga del informe final, en el despliegue del ejercicio anterior.
 *
 * Sube el fixture generado con «php support/generar-fixture-e2e.php»: hay que
 * regenerarlo con el ejercicio vigente real del par de despliegues contra el que
 * corre este recorrido (ver la cabecera de ese guion).
 */

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { iniciarSesion } = require('../../fixtures/autenticacion');

const FICHERO_EJEMPLO = path.join(__dirname, '..', '..', 'fixtures', 'comprobacion-vigente-ejemplo.xml');

test.describe('Comprobación de existencias — ejercicio anterior', () => {
  test.beforeEach(async ({ page }) => {
    test.skip(
      !fs.existsSync(FICHERO_EJEMPLO),
      'Falta el fixture: genera «php support/generar-fixture-e2e.php» antes de correr este recorrido.'
    );
    await iniciarSesion(page, 'modulos/mod_reorganizacion/ComprobacionStockAnterior.php');
  });

  test('T1 admite el fichero de intercambio y clasifica el producto en pantalla', async ({ page }) => {
    await page.setInputFiles('#ficheroComprobacionStock', FICHERO_EJEMPLO);
    await page.click('#btnComprobacionStockAnteriorAdmitir');

    const fila = page.locator('#areaComprobacionStockAnterior table tbody tr', { hasText: '9001' });
    await expect(fila).toBeVisible({ timeout: 15000 });
    // El estado nunca viaja solo: siempre va acompañado de la existencia exigida.
    await expect(fila.locator('td').nth(4)).not.toHaveText('');
  });

  test('T2 descarga el informe final con los dos contextos de cálculo', async ({ page }) => {
    await page.setInputFiles('#ficheroComprobacionStock', FICHERO_EJEMPLO);
    await page.click('#btnComprobacionStockAnteriorAdmitir');
    await expect(page.locator('#areaComprobacionStockAnterior table')).toBeVisible({ timeout: 15000 });

    const [download] = await Promise.all([
      page.waitForEvent('download'),
      page.click('#btnComprobacionStockAnteriorExportar'),
    ]);
    const ruta = await download.path();
    let contenido = fs.readFileSync(ruta, 'utf-8');
    if (contenido.charCodeAt(0) === 0xfeff) {
      contenido = contenido.slice(1);
    }

    expect(contenido).toContain('Contexto;Anterior');
    expect(contenido).toContain('Contexto;Vigente');
    expect(contenido).toContain('9001');
  });
});
