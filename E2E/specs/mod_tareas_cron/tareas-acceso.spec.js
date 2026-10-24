/**
 * Quién llega a las tareas programadas: el menú solo enseña la entrada al administrador, y sin
 * sesión la lista pide iniciarla. Lo que esas comprobaciones dejan pasar está en
 * `esperado/tareas-acceso.spec.js`.
 *
 * El usuario sin permiso es el que declara el entorno (`TPVFOX_E2E_USUARIO_SIN_PERMISO`). Si no
 * está declarado, su recorrido se salta.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion, usuarioSinPermiso } = require('../../fixtures/autenticacion');
const { LISTA } = require('../../fixtures/tareasCron');

const ENLACE = 'a[href$="/modulos/mod_tareas_cron/index.php"]';

test.describe('Tareas programadas — acceso', () => {
  test('T1 quien no es administrador no ve «Tareas» en el menú', {
    tag: ['@estado-actual', '@tareas', '@permisos'],
  }, async ({ page }) => {
    const sinPermiso = usuarioSinPermiso();
    test.skip(sinPermiso === null, 'El entorno no declara TPVFOX_E2E_USUARIO_SIN_PERMISO/TPVFOX_E2E_CLAVE_SIN_PERMISO.');

    await iniciarSesion(page, 'index.php', sinPermiso);

    await expect(page.locator('#usr')).toHaveCount(0);
    await expect(page.locator(ENLACE)).toHaveCount(0);
  });

  test('T2 sin sesión, la lista pide iniciar sesión y no enseña las tareas', {
    tag: ['@estado-actual', '@tareas', '@permisos'],
  }, async ({ page }) => {
    await page.goto(LISTA);

    await expect(page.locator('#usr')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Listados de tareas' })).toHaveCount(0);
  });
});
