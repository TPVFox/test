/**
 * Flujo por teclado: adjuntar un albarán «Guardado» a una factura nueva, tecleando su
 * número en la caja `numAdjunto` (`tecla 13` → `buscarAdjunto`, `dedonde=factura` →
 * `AlbaranClienteGuardado()`). Cierra la cadena de adjunción de todo el módulo:
 * pedido→albarán (`albaran-adjuntar-pedido-teclado.spec.js`) y ahora albarán→factura.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');
const { seleccionarCliente } = require('../../fixtures/seleccionarCliente');

const ID_CLIENTE = 929; // '[E2E venta] Cliente factura adjunto albaran'
const NUM_ALBARAN_GUARDADO = 1; // support/sembrar-e2e-venta.php

test.describe('Factura — adjuntar albarán guardado por teclado', () => {
  test('T1 teclear el número del albarán y pulsar Intro trae sus líneas a la factura', {
    tag: ['@estado-actual', '@factura', '@albaran', '@teclado', '@adjuntos'],
    annotation: [
      { type: 'Comportamiento', description: 'Tecleando el número de un albarán guardado en la caja de adjuntos, sus líneas pasan a la factura y el albarán queda como adjunto con su icono de retirar.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/factura.php');

    await seleccionarCliente(page, ID_CLIENTE);
    await expect(page.locator('#numAdjunto')).toBeVisible({ timeout: 10000 });

    await page.fill('#numAdjunto', String(NUM_ALBARAN_GUARDADO));
    await Promise.all([
      page.waitForResponse((r) => r.url().includes('tareas.php') && (r.request().postData() || '').includes('buscarAdjunto')),
      page.locator('#numAdjunto').press('Enter'),
    ]);

    const filaProducto = page.locator('#tabla tr[id^="Row"]:not(#Row0)').first();
    await expect(filaProducto).toBeVisible({ timeout: 10000 });
    await expect(filaProducto).toContainText('[E2E venta] Manzana Golden');

    const filaAdjunto = page.locator('.eliminar a[onclick*="eliminarAdjunto"]');
    await expect(filaAdjunto.first()).toBeVisible();
  });
});
