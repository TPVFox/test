/**
 * Una factura de un cliente cuya ficha no tiene forma de vencimiento se abre.
 *
 * La columna `formasVenci` del cliente admite nulo y nada obliga a rellenarla. `factura.php`
 * le da entonces la forma de vencimiento por defecto, la misma que usa para una factura
 * nueva. Antes no lo hacía: consultaba el catálogo de vencimientos con un valor vacío, la
 * sentencia no era válida y la pantalla respondía 500, de modo que la factura estaba en el
 * listado y era inalcanzable. Este caso documentaba ese 500; ahora fija que se abre.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');

const NOMBRE_CLIENTE = '[E2E venta] Cliente factura sin vencimiento';

test.describe('Factura — abrir una factura de cliente sin forma de vencimiento', () => {
  test('T1 la factura está en el listado y su propia pantalla se abre', {
    tag: ['@estado-actual', '@factura', '@entrada', '@vencimiento'],
    annotation: [
      { type: 'Comportamiento', description: 'La factura de un cliente cuya ficha no tiene forma de vencimiento se abre desde el listado, con el vencimiento por defecto.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/facturasListado.php');

    const fila = page
      .locator('table.table-bordered tbody tr')
      .filter({ hasText: NOMBRE_CLIENTE })
      .first();
    await expect(fila).toBeVisible();

    const enlace = await fila.locator('a[title="Ver factura"]').getAttribute('href');
    const idFactura = new URL(enlace, 'http://x/').searchParams.get('id');

    const respuesta = await page.goto(`modulos/mod_venta/factura.php?id=${idFactura}&accion=ver`);

    expect(respuesta.status()).toBe(200);
    await expect(page.locator('input[name="fechaVencimiento"]')).not.toHaveValue('');
  });
});
