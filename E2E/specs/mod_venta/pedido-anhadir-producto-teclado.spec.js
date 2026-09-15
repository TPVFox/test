/**
 * Flujo por teclado: buscar un artículo por su identificador exacto en la caja de
 * `idArticulo` y confirmar con Intro añade la línea directamente, sin pasar por listado
 * (`cajas_input`: tecla 13 en `cajaidArticulo` → `controlEventos()` → `controladorAcciones`
 * → `buscarProductos()`).
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');
const { seleccionarCliente } = require('../../fixtures/seleccionarCliente');

const ID_CLIENTE = 921; // '[E2E venta] Cliente teclado', support/sembrar-e2e-venta.php
const ID_ARTICULO = 14678; // '[E2E venta] Manzana Golden'

test.describe('Pedido — añadir producto por teclado', () => {
  test('T1 escribir el id exacto y pulsar Intro añade la línea', {
    tag: ['@estado-actual', '@pedido', '@teclado', '@entrada'],
    annotation: [
      { type: 'Comportamiento', description: 'Tecleando el identificador exacto de un artículo y pulsando Intro, la línea se añade directamente, sin listado, y el foco vuelve a la caja del artículo para seguir tecleando.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/pedido.php');

    await seleccionarCliente(page, ID_CLIENTE);
    await expect(page.locator('#idArticulo')).toBeVisible({ timeout: 10000 });

    await page.fill('#idArticulo', String(ID_ARTICULO));
    await page.locator('#idArticulo').press('Enter');

    const filaNueva = page.locator(`#tabla tr:has-text("${ID_ARTICULO}")`).first();
    await expect(filaNueva).toBeVisible({ timeout: 10000 });
    await expect(filaNueva).toContainText('[E2E venta] Manzana Golden');

    // El salto de foco tras anadir vuelve a idArticulo (funciones.js::buscarProductos),
    // listo para seguir tecleando sin usar el raton.
    await expect(page.locator('#idArticulo')).toBeFocused();
  });
});
