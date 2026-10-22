/**
 * Las pantallas de las tiendas en el navegador: la carpeta del módulo, el listado y el paso del
 * listado a la ficha de una tienda o a la de crear una nueva.
 *
 * Los recorridos solo leen: no guardan ninguna ficha, para no dejar tiendas en la base de los
 * recorridos. El alta y la modificación se prueban en la integración, dentro de una transacción.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');

test.describe('Tiendas — pantallas', () => {
  test('T1 la carpeta del módulo lleva al listado de tiendas', {
    tag: ['@estado-actual'],
    annotation: [
      { type: 'Comportamiento', description: 'Abrir la carpeta del módulo redirige al listado de tiendas.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_tienda/');

    await expect(page).toHaveURL(/modulos\/mod_tienda\/ListaTiendas\.php$/);
    await expect(page.getByRole('heading', { name: 'Tiendas: Editar y Añadir Tiendas' })).toBeVisible();
  });

  test('T2 «Modificar» con una tienda marcada abre su ficha', {
    tag: ['@estado-actual'],
    annotation: [
      { type: 'Comportamiento', description: 'En el listado, marcar una tienda y pulsar «Modificar» abre la ficha de esa tienda, con su número.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_tienda/ListaTiendas.php');

    const casilla = page.locator('td.rowTienda input[type="checkbox"]').first();
    const numero = await casilla.getAttribute('value');
    await casilla.check();
    await page.getByRole('link', { name: 'Modificar' }).click();

    await expect(page).toHaveURL(new RegExp(`tienda\\.php\\?id=${numero}$`));
    await expect(page.getByRole('heading', { name: /^Modificar Tienda:/ })).toBeVisible();
    await expect(page.locator('#idtienda')).toHaveValue(numero);
  });

  test('T3 «Modificar» sin ninguna tienda marcada avisa y se queda en el listado', {
    tag: ['@estado-actual'],
    annotation: [
      { type: 'Comportamiento', description: 'Sin ninguna tienda marcada, «Modificar» avisa de que hay que marcar una sola y no sale del listado.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_tienda/ListaTiendas.php');
    let aviso = '';
    page.once('dialog', async (dialogo) => {
      aviso = dialogo.message();
      await dialogo.dismiss();
    });

    await page.getByRole('link', { name: 'Modificar' }).click();

    await expect.poll(() => aviso).toContain('Solo puedes tener uno seleccionado');
    await expect(page).toHaveURL(/ListaTiendas\.php/);
  });

  test('T4 «Añadir» abre la ficha de crear una tienda física', {
    tag: ['@estado-actual'],
    annotation: [
      { type: 'Comportamiento', description: '«Añadir» abre la ficha vacía, con el tipo físico marcado y el principal desactivado porque ya hay una tienda principal.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_tienda/ListaTiendas.php');

    await page.getByRole('link', { name: 'Añadir' }).click();

    await expect(page).toHaveURL(/modulos\/mod_tienda\/tienda\.php$/);
    await expect(page.getByRole('heading', { name: /^Crear Tienda:/ })).toBeVisible();
    await expect(page.locator('input[name="tipoTienda"][value="fisica"]')).toBeChecked();
    await expect(page.locator('input[name="tipoTienda"][value="principal"]')).toBeDisabled();
  });
});
