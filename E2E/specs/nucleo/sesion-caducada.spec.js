/**
 * La sesión que caduca con una pantalla abierta: la petición de fondo llega a `tareas.php` sin sesión,
 * que la rechaza con un 401, y la pantalla vuelve al formulario de acceso en lugar de seguir como si
 * hubiera guardado.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');

test.describe('Núcleo — sesión caducada', () => {
  test('T1 una petición de fondo sin sesión devuelve la pantalla al formulario de acceso', {
    tag: ['@estado-actual', '@sesion'],
    annotation: [
      { type: 'Comportamiento', description: 'Con la portada abierta, se pierde la sesión y la pantalla pide algo a un tareas.php: recibe un 401 y la página se recarga, con lo que enseña el formulario de acceso.' },
    ],
  }, async ({ page, context }) => {
    await iniciarSesion(page, 'index.php');
    await expect(page.locator('h2')).toHaveText('Accesos Directos');

    await context.clearCookies();
    await Promise.all([
      page.waitForEvent('load'),
      page.evaluate(() => { $.ajax({ url: 'modulos/mod_cliente/tareas.php', method: 'POST', data: { pulsado: 'leerCliente' } }); }),
    ]);

    await expect(page.locator('#usr')).toBeVisible();
  });
});
