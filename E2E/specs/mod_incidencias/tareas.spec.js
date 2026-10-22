/**
 * Las peticiones de fondo de las incidencias (`modulos/mod_incidencias/tareas.php`) con la sesión
 * abierta: abrir la ventana con que se escribe una incidencia y guardarla.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');

const TAREAS = 'modulos/mod_incidencias/tareas.php';

test.describe('Incidencias — peticiones de fondo con sesión', () => {
  test('T1 abrir la ventana devuelve el formulario con los datos de la pantalla de origen', {
    tag: ['@estado-actual'],
    annotation: [
      { type: 'Comportamiento', description: 'La ventana trae la fecha, el origen, el estado fijo de una incidencia nueva, el mensaje por escribir y los datos de la pantalla de la que se abre; sin el selector configurado, no ofrece usuarios.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_incidencias/ListadoIncidencias.php');

    const respuesta = await page.request.post(TAREAS, {
      form: {
        pulsado: 'abririncidencia',
        numIncidencia: '0',
        dedonde: 'listado Incidencias',
        'configuracion[0][descripcion]': 'mostrar_Select',
        'configuracion[0][valor]': 'No',
        'configuracion[1][descripcion]': 'usuario_Defecto',
        'configuracion[1][valor]': '1',
      },
    });

    const cuerpo = await respuesta.json();
    expect(cuerpo.datos).toBe('{"vista":"listado Incidencias"}');
    expect(cuerpo.html).toContain('id="inci_mensaje"');
    expect(cuerpo.html).toContain('id="inci_estado" value="No resuelto" readonly=""');
    expect(cuerpo.html).not.toContain('usuarioSelec');
  });

  test('T2 guardar una incidencia la registra', {
    tag: ['@estado-actual'],
    annotation: [
      { type: 'Comportamiento', description: 'Con un mensaje, guardar una incidencia nueva la registra y devuelve su identificador.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_incidencias/ListadoIncidencias.php');

    const respuesta = await page.request.post(TAREAS, {
      form: {
        pulsado: 'nuevaIncidencia',
        datos: '{"vista":"listado Incidencias"}',
        dedonde: 'mod_incidencia',
        estado: '0',
        mensaje: `[E2E incidencias] ${Date.now()}`,
        numIncidencia: '0',
      },
    });

    const cuerpo = await respuesta.json();
    expect(Number(cuerpo.id)).toBeGreaterThan(0);
  });

  test('T3 sin mensaje no se registra nada', {
    tag: ['@estado-actual'],
    annotation: [
      { type: 'Comportamiento', description: 'Guardar sin mensaje no registra la incidencia y responde vacío.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_incidencias/ListadoIncidencias.php');

    const respuesta = await page.request.post(TAREAS, {
      form: { pulsado: 'nuevaIncidencia', datos: '{}', dedonde: 'mod_incidencia', estado: '0', mensaje: '', numIncidencia: '0' },
    });

    expect(await respuesta.json()).toEqual([]);
  });
});
