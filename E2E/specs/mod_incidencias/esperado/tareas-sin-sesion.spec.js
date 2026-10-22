/**
 * Lo que deberían responder las peticiones de fondo de las incidencias cuando llegan sin sesión.
 *
 * `tareas.php` carga el arranque común, que deja seguir sin sesión con el usuario invitado, y no la
 * cabecera de las pantallas, que es la que corta el paso.
 */

const { test, expect } = require('@playwright/test');

const TAREAS = 'modulos/mod_incidencias/tareas.php';

test.describe('Incidencias — peticiones de fondo sin sesión', () => {
  test('T1 sin sesión, abrir la ventana no entrega la lista de usuarios', {
    tag: ['@esperado', '@sesion', '@forzado', '@alto'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'Sin sesión, pedir la ventana de una incidencia devuelve el formulario con un desplegable con el nombre de usuario de todos los trabajadores.' },
      { type: 'Qué debería ocurrir', description: 'Que una petición sin sesión no reciba datos de los usuarios.' },
      { type: 'Por qué ocurre', description: 'La petición de fondo no comprueba la sesión, y sin la configuración de la pantalla la ventana muestra el selector de usuarios, que es el valor por defecto.' },
      { type: 'Cómo debería funcionar', description: 'Rechazar la petición sin sesión válida antes de componer la ventana.' },
    ],
  }, async ({ request }) => {
    const respuesta = await request.post(TAREAS, {
      form: { pulsado: 'abririncidencia', numIncidencia: '0', dedonde: 'listado Incidencias' },
    });

    const cuerpo = await respuesta.json().catch(() => ({}));
    expect(cuerpo.html || '', 'Sin sesión no se entregan los usuarios').not.toContain('usuarioSelec');
  });

  test('T2 sin sesión, guardar una incidencia recibe un rechazo y no un error del servidor', {
    tag: ['@esperado', '@sesion', '@forzado', '@medio'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'Sin sesión, guardar una incidencia termina con un error del servidor: el alta compone la sentencia sin usuario, la base la rechaza y la excepción no se recoge.' },
      { type: 'Qué debería ocurrir', description: 'Que la petición sin sesión se rechace con una respuesta controlada, sin llegar a la base.' },
      { type: 'Por qué ocurre', description: 'La petición de fondo no comprueba la sesión, y el alta toma el usuario de ella sin comprobar que exista.' },
      { type: 'Cómo debería funcionar', description: 'Rechazar la petición sin sesión válida antes de dar el alta.' },
    ],
  }, async ({ request }) => {
    const respuesta = await request.post(TAREAS, {
      form: { pulsado: 'nuevaIncidencia', datos: '{}', dedonde: 'mod_incidencia', estado: '0', mensaje: '[E2E incidencias] sin sesión', numIncidencia: '0' },
    });

    expect(respuesta.status(), 'La respuesta no puede ser un error del servidor').toBeLessThan(500);
  });
});
