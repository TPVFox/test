/**
 * Lo que deberían responder las peticiones de fondo de las incidencias, con la sesión abierta, en
 * los casos que hoy responden de más o de menos.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../../fixtures/autenticacion');

const TAREAS = 'modulos/mod_incidencias/tareas.php';

test.describe('Incidencias — respuestas de las peticiones de fondo', () => {
  test('T1 una orden que no existe recibe un error', {
    tag: ['@esperado', '@forzado', '@bajo'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'Una orden que las tareas del módulo no conocen responde «null» y deja avisos de PHP por una variable sin definir.' },
      { type: 'Qué debería ocurrir', description: 'Que responda un error que diga que la orden no existe.' },
      { type: 'Por qué ocurre', description: 'La respuesta solo se define dentro de las dos órdenes que existen, y se escribe al final sea cual sea la orden.' },
      { type: 'Cómo debería funcionar', description: 'Dar a las demás órdenes una respuesta de error.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_incidencias/ListadoIncidencias.php');

    const respuesta = await page.request.post(TAREAS, { form: { pulsado: 'ordenQueNoExiste' } });

    const cuerpo = await respuesta.json().catch(() => null);
    expect(cuerpo && cuerpo.error, 'La orden desconocida tiene que recibir un error').toBeTruthy();
  });

  test('T2 guardar una incidencia no devuelve la sentencia de la base', {
    tag: ['@esperado', '@directo', '@bajo'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'La respuesta de guardar una incidencia trae, además de su identificador, la sentencia completa con que se insertó en la base.' },
      { type: 'Qué debería ocurrir', description: 'Que la respuesta traiga solo lo que la pantalla necesita.' },
      { type: 'Por qué ocurre', description: 'El alta devuelve la sentencia en su respuesta, y las tareas la envían tal cual al navegador.' },
      { type: 'Cómo debería funcionar', description: 'No incluir la sentencia en la respuesta.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_incidencias/ListadoIncidencias.php');

    const respuesta = await page.request.post(TAREAS, {
      form: { pulsado: 'nuevaIncidencia', datos: '{}', dedonde: 'mod_incidencia', estado: '0', mensaje: `[E2E incidencias] ${Date.now()}`, numIncidencia: '0' },
    });

    const cuerpo = await respuesta.json();
    expect(cuerpo.sql, 'La respuesta no puede llevar la sentencia de la base').toBeUndefined();
  });
});
