/**
 * Quién puede abrir el listado de productos relacionados. Solo el administrador: los demás
 * no lo ven en el menú y, si escriben su dirección, vuelven al inicio.
 *
 * El usuario sin permiso es el que declara el entorno (`TPVFOX_E2E_USUARIO_SIN_PERMISO`). Si
 * no está declarado, sus recorridos se saltan.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion, usuarioSinPermiso } = require('../../fixtures/autenticacion');

const VISTA = 'modulos/mod_relaciones/ListaRelaciones.php';
const ENLACE = 'a[href$="/modulos/mod_relaciones/ListaRelaciones.php"]';

test.describe('Productos relacionados — acceso', () => {
  test('T1 el administrador lo encuentra en el menú y lo abre', {
    tag: ['@estado-actual', '@relaciones', '@permisos'],
    annotation: [
      { type: 'Comportamiento', description: 'El administrador recibe el permiso del módulo al entrar, sin que nadie se lo asigne: la entrada aparece en el menú y abre el listado.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'index.php');

    await expect(page.locator(ENLACE)).toHaveText('Productos relacionados');
    await page.goto(VISTA);
    await expect(page.locator('h2')).toHaveText('Productos relacionados');
    await expect(page.locator('#tablaRelaciones')).toBeVisible();
  });

  test('T2 quien no es administrador no lo ve en el menú', {
    tag: ['@estado-actual', '@relaciones', '@permisos'],
  }, async ({ page }) => {
    const sinPermiso = usuarioSinPermiso();
    test.skip(sinPermiso === null, 'El entorno no declara TPVFOX_E2E_USUARIO_SIN_PERMISO/TPVFOX_E2E_CLAVE_SIN_PERMISO.');

    await iniciarSesion(page, 'index.php', sinPermiso);

    await expect(page.locator('#usr')).toHaveCount(0);
    await expect(page.locator(ENLACE)).toHaveCount(0);
  });

  test('T3 quien no es administrador y escribe la dirección vuelve al inicio', {
    tag: ['@estado-actual', '@relaciones', '@permisos'],
    annotation: [
      { type: 'Comportamiento', description: 'Que el menú no muestre la entrada no basta: la propia vista comprueba el permiso y devuelve al inicio a quien no lo tiene, sin enseñar el listado.' },
    ],
  }, async ({ page }) => {
    const sinPermiso = usuarioSinPermiso();
    test.skip(sinPermiso === null, 'El entorno no declara TPVFOX_E2E_USUARIO_SIN_PERMISO/TPVFOX_E2E_CLAVE_SIN_PERMISO.');

    await iniciarSesion(page, 'index.php', sinPermiso);
    await page.goto(VISTA);

    await expect(page).toHaveURL(/index\.php$/);
    await expect(page.locator('#tablaRelaciones')).toHaveCount(0);
  });

  test('T4 sin sesión no se muestra el listado', {
    tag: ['@estado-actual', '@relaciones', '@permisos'],
  }, async ({ page }) => {
    await page.goto(VISTA);

    await expect(page.locator('#tablaRelaciones')).toHaveCount(0);
  });
});
