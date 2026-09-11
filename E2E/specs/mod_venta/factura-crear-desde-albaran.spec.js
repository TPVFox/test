/**
 * «Crear factura desde albarán», la acción del listado de albaranes que abre una factura
 * nueva con ese albarán ya incorporado.
 *
 * El formulario envía el **identificador** del albarán, y la búsqueda que la factura
 * ejecuta con ese valor consulta por el **número**. Mientras número e identificador
 * coincidan la acción funciona; cuando no, la factura se abre con el cliente puesto y sin el
 * albarán que se pidió facturar, sin nada que lo señale.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');

const NOMBRE_CLIENTE = '[E2E venta] Cliente factura desde albaran';

test.describe('Factura — crear desde albarán', () => {
  test('T1 la acción abre la factura del cliente pero sin el albarán que se pidió facturar', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/albaranesListado.php');

    const fila = page
      .locator('table.table-bordered tbody tr')
      .filter({ hasText: NOMBRE_CLIENTE })
      .first();
    await expect(fila).toBeVisible();

    // Lo que el formulario envía es el identificador; el número visible es otro.
    const idEnviado = await fila.locator('input[name="albaranes[]"]').inputValue();
    const numeroVisible = (await fila.locator('td').nth(3).innerText()).trim();
    expect(idEnviado).not.toBe(numeroVisible);

    await Promise.all([
      page.waitForURL(/factura\.php/),
      fila.locator('a[title="Crear factura desde albarán"]').click(),
    ]);

    // El cliente sí llega: la pantalla lo pone y queda bloqueado.
    await expect(page.locator('#id_cliente')).toHaveValue('941', { timeout: 15000 });

    // El albarán, no: ni fila de adjunto ni líneas de producto.
    await expect(page.locator('#tablaAdjunto .eliminar a[onclick*="eliminarAdjunto"]')).toHaveCount(0);
    await expect(page.locator('#tabla tr[id^="Row"]:not(#Row0)')).toHaveCount(0);
  });
});
