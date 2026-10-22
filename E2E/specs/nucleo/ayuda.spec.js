/**
 * Las páginas de ayuda de `estatico/`. Ningún menú ni ninguna pantalla las enlaza: se llega a ellas
 * por su dirección.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');

test.describe('Núcleo — ayuda', () => {
  test('T1 las portadas de la ayuda se sirven con sesión y enlazan sus páginas', {
    tag: ['@estado-actual'],
    annotation: [
      { type: 'Comportamiento', description: 'Con sesión, la portada del manual de usuario y la del manual técnico se sirven con su título y un cuadro por página de ayuda, cada uno con su enlace.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'estatico/manuales/index.php');
    await expect(page.locator('h1')).toHaveText('Manual de ayuda nivel usuario');
    await expect(page.locator('a[href="index.php?cargo_ayuda=1"]')).toHaveCount(1);

    await page.goto('estatico/manualTecnico/index.php');
    await expect(page.locator('a[href^="index.php?cargo_ayuda="]').first()).toBeVisible();
  });
});
