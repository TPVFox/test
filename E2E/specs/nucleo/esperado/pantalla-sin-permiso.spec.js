/**
 * Lo que debería pasar cuando un usuario con sesión abre, por su dirección, una pantalla sobre la que
 * no tiene permiso.
 *
 * El permiso de cada pantalla está en la tabla de permisos, sembrado desde el `acces.xml` de su
 * módulo, pero solo lo consulta el menú, para decidir qué entradas pinta: la pantalla no lo mira.
 * El recorrido entra con el usuario sin permisos del banco de pruebas, que tiene denegada la de
 * usuarios, y lo comprueba en la base antes de pedirla.
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const { iniciarSesion, usuarioSinPermiso } = require('../../../fixtures/autenticacion');

/** El permiso guardado del usuario sobre la vista, leído de la base de pruebas. */
function permisoDeLaVista(usuario, modulo, vista) {
  const guion = path.resolve(__dirname, '../../../../support/leer-permiso-de-usuario.php');

  return JSON.parse(execFileSync('php', [guion, usuario, modulo, vista], { encoding: 'utf-8' })).permiso;
}

test.describe('Núcleo — pantalla sin permiso', () => {
  test('T1 una pantalla sin permiso de vista no se sirve aunque se pida por su dirección', {
    tag: ['@esperado', '@permisos', '@forzado', '@alto'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'Un usuario con la pantalla de usuarios denegada la abre escribiendo su dirección, y ve la lista de usuarios del programa con sus nombres de acceso.' },
      { type: 'Qué debería ocurrir', description: 'Que la pantalla no se sirva a quien no tiene su permiso de vista.' },
      { type: 'Por qué ocurre', description: 'El permiso de vista solo lo consulta el menú para pintar sus entradas; ninguna pantalla lo comprueba al servirse.' },
      { type: 'Cómo debería funcionar', description: 'Que el arranque común de las pantallas compruebe el permiso de la vista que se sirve y, sin él, no la pinte.' },
    ],
  }, async ({ page }) => {
    const credenciales = usuarioSinPermiso();
    test.skip(!credenciales, 'Faltan TPVFOX_E2E_USUARIO_SIN_PERMISO/TPVFOX_E2E_CLAVE_SIN_PERMISO');
    expect(permisoDeLaVista(credenciales.usuario, 'mod_usuario', 'ListaUsuarios.php'), 'El usuario tiene que tener la pantalla denegada').toBe(0);

    await iniciarSesion(page, 'modulos/mod_usuario/ListaUsuarios.php', credenciales);

    await expect(page.getByText('Usuarios: Editar y Añadir Usuarios'), 'Sin permiso, la pantalla no se sirve').toHaveCount(0);
  });
});
