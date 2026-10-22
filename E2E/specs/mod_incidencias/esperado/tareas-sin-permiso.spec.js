/**
 * Lo que deberían hacer las peticiones de fondo de las incidencias con quien no tiene el permiso de
 * la acción que pide.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion, usuarioSinPermiso } = require('../../../fixtures/autenticacion');

const TAREAS = 'modulos/mod_incidencias/tareas.php';
const LISTADO = 'modulos/mod_incidencias/ListadoIncidencias.php';

test.describe('Incidencias — peticiones de fondo sin permiso', () => {
  test('T1 sin el permiso de responder, las tareas no registran una respuesta', {
    tag: ['@esperado', '@permisos', '@forzado', '@medio'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'Un usuario sin el permiso de responder incidencias registra una respuesta enviándola directamente a las tareas del módulo.' },
      { type: 'Qué debería ocurrir', description: 'Que las tareas rechacen la respuesta de quien no tiene el permiso.' },
      { type: 'Por qué ocurre', description: 'Las tareas no consultan el permiso de la acción que atienden.' },
      { type: 'Cómo debería funcionar', description: 'Comprobar en las tareas el permiso de la acción antes de dar el alta.' },
    ],
  }, async ({ page }) => {
    const credenciales = usuarioSinPermiso();
    test.skip(!credenciales, 'Faltan TPVFOX_E2E_USUARIO_SIN_PERMISO/TPVFOX_E2E_CLAVE_SIN_PERMISO');

    // Una incidencia a la que responder, abierta por quien sí puede.
    await iniciarSesion(page, LISTADO);
    await page.request.post(TAREAS, {
      form: { pulsado: 'nuevaIncidencia', datos: '{}', dedonde: 'mod_incidencia', estado: '0', mensaje: `[E2E incidencias] ${Date.now()}`, numIncidencia: '0' },
    });
    await page.reload();
    const numero = await page.locator('td.rowUsuario input[type="checkbox"]').first().getAttribute('value');
    await page.goto('plugins/controlUser/modalUsuario.php?tipo=cerrar');

    await iniciarSesion(page, LISTADO, credenciales);
    const respuesta = await page.request.post(TAREAS, {
      form: { pulsado: 'nuevaIncidencia', datos: '{}', dedonde: 'incidencia', estado: '1', mensaje: `[E2E incidencias] respuesta sin permiso ${Date.now()}`, numIncidencia: numero },
    });

    const cuerpo = await respuesta.json().catch(() => ({}));
    expect(cuerpo.id, 'Sin el permiso de responder no se registra la respuesta').toBeUndefined();
  });
});
