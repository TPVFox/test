/**
 * Flujo por teclado: adjuntar un pedido «Guardado» a un albarán nuevo, tecleando su
 * número en la caja `numAdjunto` (única en `parametros.xml` con `dedonde=albaran` propio,
 * `tecla 13` → `buscarAdjunto`). Trae las líneas del pedido y las copia al albarán
 * (`tareas/BuscarAdjunto.php`), y añade una fila en la tabla de adjuntos con su propio
 * icono de eliminar (`eliminarAdjunto`).
 *
 * El pedido lo deja `support/sembrar-e2e-venta.php`, con estado 'Guardado' — el único que
 * `PedidosClienteGuardado()` encuentra.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');
const { seleccionarCliente } = require('../../fixtures/seleccionarCliente');

const ID_CLIENTE = 927; // '[E2E venta] Cliente albaran adjunto pedido'
const NUM_PEDIDO_GUARDADO = 1; // support/sembrar-e2e-venta.php

test.describe('Albarán — adjuntar pedido guardado por teclado', () => {
  test('T1 teclear el número del pedido y pulsar Intro trae sus líneas al albarán', {
    tag: ['@estado-actual', '@albaran', '@pedido', '@teclado', '@adjuntos'],
    annotation: [
      { type: 'Comportamiento', description: 'Tecleando el número de un pedido guardado en la caja de adjuntos, sus líneas pasan al albarán y el pedido queda como adjunto con su icono de retirar.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/albaran.php');

    await seleccionarCliente(page, ID_CLIENTE);
    await expect(page.locator('#numAdjunto')).toBeVisible({ timeout: 10000 });

    await page.fill('#numAdjunto', String(NUM_PEDIDO_GUARDADO));
    await Promise.all([
      page.waitForResponse((r) => r.url().includes('tareas.php') && r.request().postData()?.includes('buscarAdjunto')),
      page.locator('#numAdjunto').press('Enter'),
    ]);

    const filaProducto = page.locator('#tabla tr[id^="Row"]:not(#Row0)').first();
    await expect(filaProducto).toBeVisible({ timeout: 10000 });
    await expect(filaProducto).toContainText('[E2E venta] Manzana Golden');

    const filaAdjunto = page.locator('.eliminar a[onclick*="eliminarAdjunto"]');
    await expect(filaAdjunto.first()).toBeVisible();
  });
});
