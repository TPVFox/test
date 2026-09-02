/**
 * Flujo por teclado en `factura.php`: mismo mecanismo de `cajas_input` que
 * `pedido.php`/`albaran.php`. `$dedonde = "factura"` también tiene rama en
 * `comprobarAdjunto.php`, así que no debería saltar ningún error de página.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');
const { seleccionarCliente } = require('../../fixtures/seleccionarCliente');

const ID_CLIENTE = 928; // '[E2E venta] Cliente factura teclado'
const ID_ARTICULO = 14678;

test.describe('Factura — añadir producto por teclado', () => {
  test('T1 escribir el id exacto y pulsar Intro añade la línea, sin errores de página', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/factura.php');

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
