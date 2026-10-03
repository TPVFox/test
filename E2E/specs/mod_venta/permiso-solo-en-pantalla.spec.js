/**
 * Lo que un permiso denegado impide en el módulo de venta, y lo que no.
 *
 * Los permisos se declaran en `acces.xml` por módulo, vista y acción, y cada usuario que no es
 * administrador recibe los valores por defecto la primera vez que entra. Pero en el módulo de
 * venta solo se consultan para pintar: el menú decide qué entradas enseña y los listados qué
 * botones. Ninguna pantalla comprueba el permiso de su propia vista, y `tareas.php` no comprueba
 * el de ninguna acción.
 *
 * Los recorridos entran con un usuario de la siembra que no es administrador
 * (`e2e_sin_permisos`, con la misma clave que el de los recorridos). Antes de cada comprobación
 * leen de la base que el permiso está denegado de verdad, con `support/leer-permiso-de-usuario.php`.
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const { iniciarSesion } = require('../../fixtures/autenticacion');

const SIN_PERMISOS = { usuario: 'e2e_sin_permisos', clave: process.env.TPVFOX_E2E_CLAVE };

function permiso(vista, accion) {
  const guion = path.resolve(__dirname, '../../../support/leer-permiso-de-usuario.php');
  const argumentos = [guion, SIN_PERMISOS.usuario, 'mod_venta', vista].concat(accion ? [accion] : []);

  return JSON.parse(execFileSync('php', argumentos, { encoding: 'utf-8' })).permiso;
}

function traza(documento, idCliente) {
  const guion = path.resolve(__dirname, '../../../support/leer-traza-de-documento.php');

  return JSON.parse(execFileSync('php', [guion, documento, String(idCliente)], { encoding: 'utf-8' }));
}

test.describe('Venta — permiso denegado', () => {
  test('T1 el listado de albaranes no le ofrece cambiar el estado a quien no tiene ese permiso', {
    tag: ['@estado-actual', '@albaran', '@listado', '@estados'],
    annotation: [
      { type: 'Comportamiento', description: 'Sin el permiso de cambiar el estado de un albarán, el listado no pinta el botón «Cambiar estado». Es lo único que ese permiso gobierna.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/albaranesListado.php', SIN_PERMISOS);
    expect(permiso('albaranesListado.php', 'CambiarEstadoAlbaran'), 'El permiso está denegado').toBe(0);

    await expect(page.getByRole('button', { name: 'Cambiar estado' })).toHaveCount(0);
  });

  test('T2 sin el permiso de cambiar el estado, la petición de fondo lo cambia igual', {
    tag: ['@estado-actual', '@defecto', '@albaran', '@estados', '@alto'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'Un usuario sin permiso para cambiar el estado de un albarán puede cambiarlo con su sesión, pidiéndolo a las tareas del módulo.' },
      { type: 'Qué debería ocurrir', description: 'Que el servidor rechace el cambio de estado de quien no tiene ese permiso.' },
      { type: 'Por qué ocurre', description: 'El permiso solo decide si se pinta el botón; tareas.php no lo consulta. Y la misma petición la usa la pantalla del documento para pasarlo a «Sin guardar» al editarlo, de modo que hoy no podría exigir el permiso sin romper la edición.' },
      { type: 'Cómo debería funcionar', description: 'Separar el cambio de estado que pide el operador del que hace la propia aplicación, y comprobar en el servidor el permiso del primero.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/albaranesListado.php', SIN_PERMISOS);
    expect(permiso('albaranesListado.php', 'CambiarEstadoAlbaran'), 'El permiso está denegado').toBe(0);

    const antes = traza('albaran', 981);
    expect(antes.documento.estado).toBe('Guardado');

    await page.request.post('modulos/mod_venta/tareas.php', {
      form: { pulsado: 'modificarEstadoDocumento', dedonde: 'albaran', idModificar: antes.documento.id, estado: 'Procesado' },
    });

    expect(traza('albaran', 981).documento.estado, 'El estado cambia pese al permiso denegado').toBe('Procesado');
  });

  test('T3 la pantalla del albarán, que tiene denegada, se le sirve para crear un albarán', {
    tag: ['@estado-actual', '@defecto', '@albaran', '@entrada', '@medio'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'Un usuario sin permiso sobre la pantalla del albarán no la ve en el menú, pero escribiendo su dirección se le sirve entera y puede crear y guardar albaranes.' },
      { type: 'Qué debería ocurrir', description: 'Que la pantalla compruebe el permiso de su vista y no se sirva a quien no lo tiene.' },
      { type: 'Por qué ocurre', description: 'El permiso de una vista solo lo consulta el menú para decidir si la enseña; la pantalla no lo consulta.' },
      { type: 'Cómo debería funcionar', description: 'Comprobar el permiso de la vista al cargar cada pantalla, antes de procesar nada.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/albaran.php', SIN_PERMISOS);
    expect(permiso('albaran.php'), 'El permiso de la vista está denegado').toBe(0);

    await expect(page.locator('#Cliente'), 'Se le sirve el alta, con el cliente editable').toBeEditable();
  });
});
