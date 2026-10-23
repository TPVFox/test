/**
 * Lo que debería hacer la ficha de crear una familia cuando aún no hay ninguna.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../../fixtures/autenticacion');

test.describe('Familias — la primera familia', () => {
  test('T1 «Añadir» abre la ficha de crear la primera familia', {
    tag: ['@esperado', '@directo', '@medio'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'Sin ninguna familia, la ficha de crear termina con un error fatal antes de pintarse: no se puede dar de alta la primera.' },
      { type: 'Qué debería ocurrir', description: 'Que la ficha se abra con la raíz como único padre.' },
      { type: 'Por qué ocurre', description: 'La lista de padres no trae datos sin filas, y la ficha la recorre y la convierte en columna sin comprobarla.' },
      { type: 'Cómo debería funcionar', description: 'Tomar una lista vacía cuando la lectura no trae filas.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_familia/familia.php?id=0');

    await expect(page.getByRole('heading', { name: 'Familias:Crear' })).toBeVisible();
    await expect(page.locator('#combopadre option[value="0"]')).toHaveCount(1);
  });
});
