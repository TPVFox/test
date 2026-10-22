/**
 * Las pantallas de las incidencias en el navegador: la carpeta del módulo, el listado y el paso del
 * listado a la ficha de la incidencia marcada.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');

const TAREAS = 'modulos/mod_incidencias/tareas.php';

test.describe('Incidencias — pantallas', () => {
  test('T1 la carpeta del módulo lleva al listado', {
    tag: ['@estado-actual'],
    annotation: [
      { type: 'Comportamiento', description: 'Abrir la carpeta del módulo redirige al listado de incidencias.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_incidencias/');

    await expect(page).toHaveURL(/modulos\/mod_incidencias\/ListadoIncidencias\.php$/);
    await expect(page.getByRole('heading', { name: 'Listado de incidencias' })).toBeVisible();
  });

  test('T2 «Modificar» con una incidencia marcada abre su ficha', {
    tag: ['@estado-actual'],
    annotation: [
      { type: 'Comportamiento', description: 'En el listado, marcar una incidencia y pulsar «Modificar» abre la ficha de su número, con sus anotaciones.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_incidencias/ListadoIncidencias.php');
    await page.request.post(TAREAS, {
      form: { pulsado: 'nuevaIncidencia', datos: '{}', dedonde: 'mod_incidencia', estado: '0', mensaje: `[E2E incidencias] ${Date.now()}`, numIncidencia: '0' },
    });
    await page.reload();

    const casilla = page.locator('td.rowUsuario input[type="checkbox"]').first();
    const numero = await casilla.getAttribute('value');
    await casilla.check();
    await page.getByRole('link', { name: 'Modificar' }).click();

    await expect(page).toHaveURL(new RegExp(`incidencia\\.php\\?id=${numero}$`));
    await expect(page.getByRole('heading', { name: `Datos de la incidencia Nº ${numero}` })).toBeVisible();
    await expect(page.getByText('Responder incidencia')).toBeVisible();
  });
});
