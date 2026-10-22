/**
 * Lo que debería pasar con la cookie de la sesión: que un script de la página no pueda leerla.
 *
 * La sesión se abre con la configuración del servidor, sin fijar los parámetros de la cookie. En el
 * servidor de los recorridos, como en uno recién instalado, la cookie queda al alcance del
 * JavaScript de la página.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../../fixtures/autenticacion');

test.describe('Núcleo — cookie de sesión', () => {
  test('T1 un script de la página no puede leer la cookie de la sesión', {
    tag: ['@esperado', '@sesion', '@directo', '@alto'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'Con la sesión abierta, document.cookie devuelve la cookie de la sesión: cualquier script que llegue a ejecutarse en la página puede llevársela.' },
      { type: 'Qué debería ocurrir', description: 'Que la cookie de la sesión no sea legible desde la página.' },
      { type: 'Por qué ocurre', description: 'La sesión se abre con session_start() sin parámetros, y queda con la configuración del servidor, que no la marca como solo HTTP.' },
      { type: 'Cómo debería funcionar', description: 'Fijar al abrir la sesión que la cookie sea solo HTTP, y con SameSite.' },
    ],
  }, async ({ page, context }) => {
    await iniciarSesion(page, 'index.php');
    const nombreSesion = (await context.cookies()).map((c) => c.name).find((n) => /SESS/i.test(n));
    expect(nombreSesion, 'Tiene que haber una cookie de sesión').toBeTruthy();

    const legibles = await page.evaluate(() => document.cookie);

    expect(legibles, 'La cookie de sesión no debería verse desde la página').not.toContain(nombreSesion);
  });
});
