/**
 * Lo que debería hacer la portada del manual de usuario cuando se le pide una ayuda que no está en
 * su lista.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../../fixtures/autenticacion');

test.describe('Núcleo — ayuda desconocida', () => {
  test('T1 una ayuda que no está en la lista lleva a la portada del manual', {
    tag: ['@esperado', '@directo', '@bajo'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'Con un número de ayuda que no está en la lista, la página no muestra ni la portada ni ninguna ayuda: el contenido queda vacío, y PHP deja avisos.' },
      { type: 'Qué debería ocurrir', description: 'Que un número desconocido lleve a la portada del manual, sin avisos.' },
      { type: 'Por qué ocurre', description: 'Con un número mayor que cero se incluye la página de esa ayuda sin comprobar que está en la lista; si no está, la ruta queda vacía y no se incluye nada.' },
      { type: 'Cómo debería funcionar', description: 'Comprobar que la ayuda pedida está en la lista y, si no, pintar la portada.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'estatico/manuales/index.php');

    await page.goto('estatico/manuales/index.php?cargo_ayuda=999');

    await expect(page.locator('h1'), 'Tiene que verse la portada del manual').toHaveText('Manual de ayuda nivel usuario');
  });
});
