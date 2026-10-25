/**
 * Las cabeceras de seguridad que la cabecera común de las pantallas manda con cada página: que el
 * navegador no adivine el tipo de lo que recibe, que la página no se cargue dentro de otra web y
 * que no se envíe la dirección de origen a otros sitios.
 */

const { test, expect } = require('@playwright/test');

test.describe('Núcleo — cabeceras de seguridad', () => {
  test('T1 la portada llega con las tres cabeceras de seguridad', {
    tag: ['@estado-actual', '@pantalla'],
    annotation: [
      { type: 'Comportamiento', description: 'La portada, aun sin sesión, llega con X-Content-Type-Options: nosniff, X-Frame-Options: SAMEORIGIN y Referrer-Policy: same-origin.' },
    ],
  }, async ({ page }) => {
    const respuesta = await page.goto('index.php');
    const cabeceras = respuesta.headers();

    expect(cabeceras['x-content-type-options']).toBe('nosniff');
    expect(cabeceras['x-frame-options']).toBe('SAMEORIGIN');
    expect(cabeceras['referrer-policy']).toBe('same-origin');
  });
});
