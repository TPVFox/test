/**
 * Una factura de un cliente cuya ficha no tiene forma de vencimiento no se puede abrir: la
 * pantalla responde 500.
 *
 * `factura.php` decodifica `formasVenci` del cliente y lee la propiedad `vencimiento` de lo
 * que salga. La columna admite nulo y nada obliga a rellenarla, de modo que para un cliente
 * recién creado se decodifica nulo, se consulta el catálogo de vencimientos con un valor
 * vacío y la sentencia resultante no es válida. La factura existe, está en el listado y es
 * inalcanzable desde su propia pantalla.
 *
 * Es la misma familia que el pedido inaccesible del componente 3 (issue TPVFox #155), aquí
 * sobre el documento fiscal.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');

const NOMBRE_CLIENTE = '[E2E venta] Cliente factura sin vencimiento';

test.describe('Factura — abrir una factura de cliente sin forma de vencimiento', () => {
  test('T1 la factura está en el listado y su propia pantalla responde 500', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/facturasListado.php');

    const fila = page
      .locator('table.table-bordered tbody tr')
      .filter({ hasText: NOMBRE_CLIENTE })
      .first();
    await expect(fila).toBeVisible();

    const enlace = await fila.locator('a[title="Ver factura"]').getAttribute('href');
    const idFactura = new URL(enlace, 'http://x/').searchParams.get('id');

    const respuesta = await page.goto(`modulos/mod_venta/factura.php?id=${idFactura}&accion=ver`);

    expect(respuesta.status()).toBe(500);
  });
});
