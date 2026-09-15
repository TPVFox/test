/**
 * Flujo por ratón: una búsqueda con varias coincidencias abre un listado
 * (`htmlListadoProductos()`), y cada fila lleva su propio `onclick` inline que llama a
 * `buscarProductos()` directamente — sin pasar por `controlEventos()` ni por ninguna
 * tecla de `cajas_input`.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');
const { seleccionarCliente } = require('../../fixtures/seleccionarCliente');

const ID_CLIENTE = 922; // '[E2E venta] Cliente raton', support/sembrar-e2e-venta.php

test.describe('Pedido — añadir producto por ratón', () => {
  test('T1 una búsqueda con varias coincidencias abre listado; clic en una fila añade la línea', {
    tag: ['@estado-actual', '@pedido', '@raton', '@busqueda'],
    annotation: [
      { type: 'Comportamiento', description: 'Una búsqueda por descripción con varias coincidencias abre un listado; al pulsar una fila, el artículo se añade como línea del pedido y el listado se cierra.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/pedido.php');

    await seleccionarCliente(page, ID_CLIENTE);
    await expect(page.locator('#Descripcion')).toBeVisible({ timeout: 10000 });

    await page.fill('#Descripcion', '[E2E venta] Manzana');
    await page.locator('#Descripcion').press('Enter');

    const filasDelListado = page.locator('.FilaModal');
    await expect(filasDelListado.first()).toBeVisible({ timeout: 10000 });
    await expect(filasDelListado).toHaveCount(2); // Golden y Reineta

    const textoElegido = await filasDelListado.first().innerText();
    await filasDelListado.first().click();

    const filaEnPedido = page.locator('#tabla tr[id^="Row"]').first();
    await expect(filaEnPedido).toBeVisible({ timeout: 10000 });
    // El listado no lleva el nombre completo (solo referencia/id); solo se confirma que
    // el clic cerro el modal y compuso una fila nueva, con datos.
    expect(textoElegido.length).toBeGreaterThan(0);
    await expect(page.locator('.modal.in, .modal.show')).toHaveCount(0);
  });
});
