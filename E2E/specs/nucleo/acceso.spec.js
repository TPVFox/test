/**
 * La entrada a TPVFox: el formulario de acceso, la portada con sus accesos directos y el cierre de
 * la sesión.
 *
 * El formulario no tiene dirección propia: lo pinta la cabecera común de las pantallas mientras no
 * hay sesión válida, y termina la página. Cerrar la sesión es pedir el propio formulario con
 * `tipo=cerrar`.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');

const ACCESOS_DE_LA_PORTADA = 18;

test.describe('Núcleo — acceso', () => {
  test('T1 sin sesión la portada pide entrar y no enseña nada más', {
    tag: ['@estado-actual', '@sesion'],
    annotation: [
      { type: 'Comportamiento', description: 'Sin sesión, la portada pinta el formulario de acceso —nombre y clave— y termina: no llegan los accesos directos.' },
    ],
  }, async ({ page }) => {
    await page.goto('index.php');

    await expect(page.locator('h1')).toHaveText('Inicio de sesion');
    await expect(page.locator('#usr')).toBeVisible();
    await expect(page.locator('#pwd')).toBeVisible();
    await expect(page.getByText('Accesos Directos')).toHaveCount(0);
  });

  test('T2 con una clave equivocada avisa y vuelve a pedir los datos', {
    tag: ['@estado-actual', '@sesion'],
    annotation: [
      { type: 'Comportamiento', description: 'Con una clave que no es la del usuario, el formulario avisa de que el usuario o la contraseña son incorrectos y se vuelve a pintar.' },
    ],
  }, async ({ page }) => {
    await page.goto('index.php');
    await page.fill('#usr', process.env.TPVFOX_E2E_USUARIO);
    await page.fill('#pwd', 'una-clave-que-no-es-la-suya');
    await page.click('input[type="submit"]');

    await expect(page.locator('.alert-warning')).toHaveText('Usuario o contraseña incorrecta');
    await expect(page.locator('#usr')).toBeVisible();
  });

  test('T3 con su clave entra y la portada enseña los accesos directos que su permiso abre', {
    tag: ['@estado-actual', '@sesion', '@permisos'],
    annotation: [
      { type: 'Comportamiento', description: 'Con la sesión abierta, la portada enseña los dieciocho accesos directos. Un administrador tiene permiso sobre todas sus pantallas: todos son enlaces y ninguno sale atenuado. La página lleva su título y el pie común.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'index.php');

    await expect(page).toHaveTitle('TPVFox-index');
    await expect(page.locator('h2')).toHaveText('Accesos Directos');
    await expect(page.locator('a.text-center[title]')).toHaveCount(ACCESOS_DE_LA_PORTADA);
    await expect(page.locator('img[style*="opacity:0.2"]')).toHaveCount(0);
    await expect(page.locator('footer')).toContainText('Tpvfox - Gestión de empresas');
  });

  test('T4 cerrar la sesión despide al usuario y la siguiente pantalla vuelve a pedir entrar', {
    tag: ['@estado-actual', '@sesion'],
    annotation: [
      { type: 'Comportamiento', description: 'Pedir el formulario con tipo=cerrar despide al usuario por su nombre y cierra la sesión: la siguiente pantalla vuelve a pintar el formulario de acceso.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'index.php');

    await page.goto('plugins/controlUser/modalUsuario.php?tipo=cerrar');
    await expect(page.locator('h1')).toHaveText('Cierre sesion');
    await expect(page.getByText(/Cerramos session,/)).toBeVisible();

    await page.goto('index.php');
    await expect(page.locator('#usr')).toBeVisible();
  });

  test('T5 pedir la carpeta de los módulos lleva a la portada', {
    tag: ['@estado-actual'],
    annotation: [
      { type: 'Comportamiento', description: 'La carpeta de los módulos no se lista: su índice responde con una redirección a la portada.' },
    ],
  }, async ({ request }) => {
    const respuesta = await request.get('modulos/index.php', { maxRedirects: 0 });

    expect(respuesta.status()).toBe(302);
    expect(respuesta.headers()['location']).toBe('../index.php');
  });
});
