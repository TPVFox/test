/**
 * Lo que debería hacer el enlace «Créditos» del pie común, que apunta a la carpeta de la ayuda.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../../fixtures/autenticacion');

test.describe('Núcleo — créditos', () => {
  test('T1 el enlace Créditos del pie lleva a una página', {
    tag: ['@esperado', '@directo', '@bajo'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'El enlace Créditos del pie de la portada lleva a la carpeta de la ayuda, que no tiene página de inicio: el servidor responde que no la encuentra.' },
      { type: 'Qué debería ocurrir', description: 'Que lleve a una página de créditos.' },
      { type: 'Por qué ocurre', description: 'El pie enlaza la carpeta estatico/, y en ella no hay index.php.' },
      { type: 'Cómo debería funcionar', description: 'Enlazar una página que exista, o crear la de créditos.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'index.php');
    const destino = await page.locator('footer a', { hasText: 'Créditos' }).getAttribute('href');

    const respuesta = await page.request.get(destino);

    expect(respuesta.status(), 'El enlace tiene que llevar a una página').toBe(200);
  });
});
