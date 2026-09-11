/**
 * El borrador de una factura ya guardada no se puede confirmar.
 *
 * Al abrir un borrador atado a una factura, `factura.php` comprueba que el estado de esa
 * factura sea «Sin guardar» y avisa como error grave si no lo es. Ninguna escritura del
 * producto deja una factura en ese estado —el guardado escribe siempre «Guardado»—, de modo
 * que la comprobación se cumple como discrepancia siempre. Y un aviso grave oculta los
 * botones de Guardar y Cancelar: el borrador queda abierto, sin forma de confirmarlo ni de
 * descartarlo desde la pantalla.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');

const NOMBRE_CLIENTE = '[E2E venta] Cliente factura entradas';

test.describe('Factura — borrador de una factura ya guardada', () => {
  test('T1 abrir el borrador avisa de estado discrepante y deja la pantalla sin botones', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/facturasListado.php');

    const filaBorrador = page
      .locator('table.table-striped tbody tr')
      .filter({ hasText: NOMBRE_CLIENTE })
      .first();
    await expect(filaBorrador).toBeVisible();

    await filaBorrador.locator('a[href*="tActual="]').click();

    await expect(page.locator('body')).toContainText('estado de factura es distinto al sin guardar');
    await expect(page.locator('#Guardar')).toBeHidden();
    await expect(page.locator('#Cancelar')).toBeHidden();
  });

  /**
   * El aviso no depende del estado concreto de la factura sino de que no sea el que nunca
   * se escribe: la misma pantalla avisa igual con la factura en «Guardado» y en cualquier
   * otro estado del catálogo.
   */
  test('T2 el aviso no distingue en qué estado está realmente la factura', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/facturasListado.php');

    const filaBorrador = page
      .locator('table.table-striped tbody tr')
      .filter({ hasText: NOMBRE_CLIENTE })
      .first();
    const numeroFactura = (await filaBorrador.locator('td').nth(1).innerText()).trim();

    await page.goto(`modulos/mod_venta/factura.php?id=${numeroFactura}&accion=ver`);
    await expect(page.locator('#estado')).toHaveValue('Guardado');

    await filaBorrador.locator('a[href*="tActual="]');
    await page.goto('modulos/mod_venta/facturasListado.php');
    await filaBorrador.locator('a[href*="tActual="]').click();

    await expect(page.locator('body')).toContainText('Avisa al administrador del sistema');
  });
});
