/**
 * El menú superior en una pantalla real: enlaza las pantallas que el permiso del usuario abre, y solo
 * esas.
 *
 * Entra con el usuario de los recorridos, que es administrador, y con el usuario sin permisos del banco
 * de pruebas, que tiene denegada la pantalla de usuarios.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion, usuarioSinPermiso } = require('../../fixtures/autenticacion');

const PANTALLA_DE_USUARIOS = 'a[href$="/modulos/mod_usuario/ListaUsuarios.php"]';

test.describe('Núcleo — menú', () => {
  test('T1 al administrador el menú le enlaza la pantalla de usuarios', {
    tag: ['@estado-actual', '@permisos'],
    annotation: [
      { type: 'Comportamiento', description: 'El administrador tiene permiso sobre la pantalla de usuarios, y el menú la enlaza en el grupo Sistema.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'index.php');

    await expect(page.locator(PANTALLA_DE_USUARIOS)).toHaveCount(1);
  });

  test('T2 a quien no tiene la pantalla de usuarios el menú no se la enlaza', {
    tag: ['@estado-actual', '@permisos'],
    annotation: [
      { type: 'Comportamiento', description: 'El usuario sin permisos del banco de pruebas tiene denegada la pantalla de usuarios: el menú no la enlaza, aunque la pantalla se sirva si se escribe su dirección.' },
    ],
  }, async ({ page }) => {
    const credenciales = usuarioSinPermiso();
    test.skip(!credenciales, 'Faltan TPVFOX_E2E_USUARIO_SIN_PERMISO/TPVFOX_E2E_CLAVE_SIN_PERMISO');

    await iniciarSesion(page, 'index.php', credenciales);

    await expect(page.locator('#usr')).toHaveCount(0);
    await expect(page.locator(PANTALLA_DE_USUARIOS)).toHaveCount(0);
  });
});
