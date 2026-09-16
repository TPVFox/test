/**
 * Login compartido por los recorridos E2E. Sin credenciales explicitas usa
 * TPVFOX_E2E_USUARIO/TPVFOX_E2E_CLAVE; pasando `{ usuario, clave }` entra con otro, que es
 * como se comprueba que un listado se acota a quien lo mira. Son
 * un usuario real del despliegue contra el que corre Playwright, con permiso sobre
 * las vistas de mod_reorganizacion que cada recorrido visita.
 *
 * El formulario de acceso no vive en una URL propia navegable: cada pantalla lo
 * incrusta ella misma mientras no hay sesión válida. Por eso este helper visita
 * directamente la pantalla de destino, rellena el formulario si aparece, y la
 * vuelve a visitar tras enviarlo para asegurarse de que se sirve ya autenticada.
 */

async function iniciarSesion(page, rutaDestino, credenciales) {
  const usuario = (credenciales && credenciales.usuario) || process.env.TPVFOX_E2E_USUARIO;
  const clave = (credenciales && credenciales.clave) || process.env.TPVFOX_E2E_CLAVE;
  if (!usuario || !clave) {
    throw new Error(
      'Faltan TPVFOX_E2E_USUARIO/TPVFOX_E2E_CLAVE: hacen falta credenciales de un usuario real del despliegue contra el que corre este recorrido.'
    );
  }

  await page.goto(rutaDestino);
  const campoUsuario = page.locator('#usr');
  if ((await campoUsuario.count()) > 0) {
    await campoUsuario.fill(usuario);
    await page.fill('#pwd', clave);
    await page.click('input[type="submit"]');
    await page.waitForLoadState('networkidle');
    await page.goto(rutaDestino);
  }
}

/** Las credenciales del segundo usuario, si el entorno las declara. */
function segundoUsuario() {
  const usuario = process.env.TPVFOX_E2E_USUARIO2;
  const clave = process.env.TPVFOX_E2E_CLAVE2;

  return usuario && clave ? { usuario, clave } : null;
}

module.exports = { iniciarSesion, segundoUsuario };
