/**
 * Recorrido 2: admisión del fichero, clasificación en pantalla y
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

    // Y la pantalla dice de dónde vino lo que muestra. Es el único recorrido en que
    // ese bloque se compone de un fichero realmente subido y admitido: en todo lo
    // demás el contexto llega puesto a mano.
    await expect(page.locator('#contextoComprobacionStockAnteriorOrigen')).toBeVisible();
    await expect(page.locator('#contextoComprobacionStockAnteriorOrigen')).toContainText('Emitido el');
    await expect(page.locator('#contextoComprobacionStockAnteriorOrigen')).toContainText('Autor');
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
    // Un informe que se archiva tiene que decir si el resultado del otro ejercicio
    // era todo o una parte, y con qué proveedor se identificó el traspaso.
    expect(contenido).toContain('ConjuntoPedido;');
    expect(contenido).toContain('ProveedorCierre;');
    expect(contenido).toContain('9001');
  });

  test('T3 no entrega el informe si el resultado no vuelve como salió', async ({ page }) => {
    await page.setInputFiles('#ficheroComprobacionStock', FICHERO_EJEMPLO);
    await page.click('#btnComprobacionStockAnteriorAdmitir');
    await expect(page.locator('#areaComprobacionStockAnterior table')).toBeVisible({ timeout: 15000 });

    // Es el único recorrido donde el resultado sale de verdad del servidor, viaja al
    // navegador y vuelve en otra petición. Tocarlo aquí es tocarlo donde puede
    // tocarse: entre las dos peticiones no hay nada en servidor que lo recuerde.
    await page.evaluate(() => {
      window.comprobacionStockAnteriorComposicion.filas[0].stockJustificado = 99999;
    });

    await page.click('#btnComprobacionStockAnteriorExportar');

    // No hay descarga: la respuesta es el motivo, y llega en lugar del documento.
    await expect(page.locator('body')).toContainText('no es el que se calculó', { timeout: 15000 });
  });
});
