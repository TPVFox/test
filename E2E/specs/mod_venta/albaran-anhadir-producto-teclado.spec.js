/**
 * Flujo por teclado en `albaran.php`: mismo mecanismo de `cajas_input` que `pedido.php`
 * (`idArticulo` exacto + Intro añade la línea directamente). Sirve además de contraste con
 * el defecto de `pedido-defecto-comprobar-adjuntos.spec.js`: aquí `$dedonde = "albaran"`
 * SÍ tiene rama en `comprobarAdjunto.php`, así que no debería saltar ningún error.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');
const { seleccionarCliente } = require('../../fixtures/seleccionarCliente');

const ID_CLIENTE = 926; // '[E2E venta] Cliente albaran teclado'
const ID_ARTICULO = 14678;

test.describe('Albarán — añadir producto por teclado', () => {
  test('T1 escribir el id exacto y pulsar Intro añade la línea, sin errores de página', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/albaran.php');

    const erroresDePagina = [];
    page.on('pageerror', (error) => erroresDePagina.push(error.message));

    await seleccionarCliente(page, ID_CLIENTE);
    await expect(page.locator('#idArticulo')).toBeVisible({ timeout: 10000 });

    await page.fill('#idArticulo', String(ID_ARTICULO));
    await page.locator('#idArticulo').press('Enter');

    const filaNueva = page.locator(`#tabla tr:has-text("${ID_ARTICULO}")`).first();
    await expect(filaNueva).toBeVisible({ timeout: 10000 });
    await expect(filaNueva).toContainText('[E2E venta] Manzana Golden');

    expect(erroresDePagina).toEqual([]);
  });
});
