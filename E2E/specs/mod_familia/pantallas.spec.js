/**
 * Las pantallas de las familias en el navegador: la carpeta del módulo y el listado.
 *
 * Los recorridos solo leen: no guardan ninguna familia, para no dejar familias en la base de los
 * recorridos. El alta, la modificación y el borrado se prueban en la integración, dentro de una
 * transacción.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');

test.describe('Familias — pantallas', () => {
  test('T1 la carpeta del módulo lleva al listado de familias', {
    tag: ['@estado-actual'],
    annotation: [
      { type: 'Comportamiento', description: 'Abrir la carpeta del módulo redirige al listado de familias, que ofrece añadir una a quien tiene el permiso.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_familia/');

    await expect(page).toHaveURL(/modulos\/mod_familia\/ListaFamilias\.php$/);
    await expect(page.getByRole('heading', { name: 'Familias de Productos' })).toBeVisible();
    await expect(page.locator('#botonnuevo-hijo-0')).toHaveAttribute('href', 'familia.php?id=0');
  });
});
