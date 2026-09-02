/**
 * Flujo solo por ratón: el icono de eliminar/retornar una línea de producto no tiene
 * ninguna tecla asignada en `parametros.xml` — es alcanzable únicamente con el `onclick`
 * inline que `htmlLineaProductos()` compone (`eliminarFila()`/`retornarFila()`).
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');
const { seleccionarCliente } = require('../../fixtures/seleccionarCliente');

const ID_CLIENTE = 925; // '[E2E venta] Cliente eliminar raton'
const ID_ARTICULO = 14678;

test.describe('Pedido — eliminar y retornar línea por ratón', () => {
  test('T1 el icono tacha la línea y el segundo clic la devuelve a activa', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/pedido.php');

    await seleccionarCliente(page, ID_CLIENTE);
    await expect(page.locator('#idArticulo')).toBeVisible({ timeout: 10000 });

    await page.fill('#idArticulo', String(ID_ARTICULO));
    await page.locator('#idArticulo').press('Enter');

    const fila = page.locator('#tabla tr[id^="Row"]:not(#Row0)').first();
    await expect(fila).toBeVisible({ timeout: 10000 });
    await expect(fila).not.toHaveClass(/tachado/);

    await fila.locator('.eliminar a').click();
    await expect(fila).toHaveClass(/tachado/);
    await expect(fila.locator('.eliminar a')).toHaveAttribute('onclick', /retornarFila/);

    await fila.locator('.eliminar a').click();
    await expect(fila).not.toHaveClass(/tachado/);
    await expect(fila.locator('.eliminar a')).toHaveAttribute('onclick', /eliminarFila/);
  });
});
