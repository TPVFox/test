/**
 * Lo que el servidor hace con una petición que llega sin sesión.
 *
 * El control de sesión de la aplicación vive en `head.php`: si no hay sesión válida, pinta el
 * formulario de acceso y termina. Pero cada pantalla lo incluye cuando empieza a pintar, después
 * de haber procesado lo que se le envió, y `tareas.php` —por donde pasan todas las peticiones de
 * fondo del módulo— no lo incluye nunca. Sin sesión, `inicial.php` sigue adelante con un usuario
 * «invitado» de identificador 0 y una tienda sin identificador.
 *
 * Los recorridos no inician sesión, o la pierden a propósito borrando la cookie, como pasa cuando
 * caduca con la pantalla abierta. Lo que queda escrito se lee de la base con
 * `support/leer-traza-de-documento.php`.
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const { iniciarSesion } = require('../../fixtures/autenticacion');

const ID_ARTICULO = 14679; // '[E2E venta] Manzana Reineta'

/** Lo que la base dice del último documento de ese cliente y de sus borradores. */
function traza(documento, idCliente) {
  const guion = path.resolve(__dirname, '../../../support/leer-traza-de-documento.php');

  return JSON.parse(execFileSync('php', [guion, documento, String(idCliente)], { encoding: 'utf-8' }));
}

test.describe('Venta — petición sin sesión', () => {
  test('T1 las peticiones de fondo entregan la ficha de los clientes a quien no ha iniciado sesión', {
    tag: ['@estado-actual', '@defecto', '@busqueda', '@critico'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'Una petición sin sesión a las tareas del módulo de venta devuelve la ficha de los clientes que coinciden con la búsqueda: nombre, NIF, dirección, teléfono y correo.' },
      { type: 'Qué debería ocurrir', description: 'Que una petición sin sesión válida se rechace sin ejecutar nada.' },
      { type: 'Por qué ocurre', description: 'El control de sesión está en la cabecera de las pantallas, y tareas.php no la incluye: se ejecuta con el usuario invitado.' },
      { type: 'Cómo debería funcionar', description: 'Comprobar la sesión en el punto de entrada de tareas.php, antes de despachar ningún caso.' },
    ],
  }, async ({ request }) => {
    const respuesta = await request.post('modulos/mod_venta/tareas.php', {
      form: { pulsado: 'buscarClientes', dedonde: 'albaran', idcaja: 'Cliente', valor: 'Guardar albaran sin sesion' },
    });

    expect(respuesta.ok()).toBe(true);
    const cuerpo = await respuesta.json();
    const nombres = (cuerpo.datos || []).map((cliente) => cliente.Nombre);
    expect(nombres, 'La búsqueda sin sesión devuelve la ficha del cliente').toContain('[E2E venta] Guardar albaran sin sesion');
    expect(Object.keys(cuerpo.datos[0]), 'Con sus datos de contacto').toEqual(expect.arrayContaining(['nif', 'direccion', 'telefono', 'email']));
  });

  test('T2 las peticiones de fondo cambian el estado de un albarán a petición de quien no ha iniciado sesión', {
    tag: ['@estado-actual', '@defecto', '@albaran', '@estados', '@critico'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'Una petición sin sesión puede poner cualquier estado a cualquier albarán, pedido o factura.' },
      { type: 'Qué debería ocurrir', description: 'Que una petición sin sesión válida se rechace sin escribir nada.' },
      { type: 'Por qué ocurre', description: 'tareas.php no comprueba la sesión, y el caso que cambia el estado escribe el que reciba sobre el documento que reciba.' },
      { type: 'Cómo debería funcionar', description: 'Comprobar la sesión en el punto de entrada de tareas.php, antes de despachar ningún caso.' },
    ],
  }, async ({ request }) => {
    const antes = traza('albaran', 980);
    expect(antes.documento, 'La siembra tiene que dejar el albarán').not.toBeNull();
    expect(antes.documento.estado).toBe('Guardado');

    await request.post('modulos/mod_venta/tareas.php', {
      form: { pulsado: 'modificarEstadoDocumento', dedonde: 'albaran', idModificar: antes.documento.id, estado: 'Procesado' },
    });

    expect(traza('albaran', 980).documento.estado, 'El albarán queda como facturado sin factura ninguna').toBe('Procesado');
  });

  for (const caso of [
    { documento: 'albaran', elDocumento: 'el albarán', pantalla: 'albaran.php', idCliente: 978 },
    { documento: 'pedido', elDocumento: 'el pedido', pantalla: 'pedido.php', idCliente: 979 },
  ]) {
    test(`T3 guardar ${caso.elDocumento} con la sesión caducada se ejecuta igual, sin constancia de quién lo hizo`, {
      tag: ['@estado-actual', '@defecto', `@${caso.documento}`, '@guardado', '@critico'],
      annotation: [
        { type: 'Qué ocurre hoy', description: `Si la sesión caduca con ${caso.elDocumento} abierto para editar, pulsar Guardar lo reescribe igual, como usuario invitado, y después pide iniciar sesión. Nada registra que la modificación llegó sin sesión.` },
        { type: 'Qué debería ocurrir', description: `Que sin sesión no se ejecute el guardado, y que ${caso.elDocumento} siga como estaba hasta que se vuelva a entrar.` },
        { type: 'Por qué ocurre', description: 'La pantalla procesa el guardado antes de incluir la cabecera que comprueba la sesión. Sin sesión, la aplicación sigue con el usuario invitado y la tienda principal, y el guardado tiene todo lo que necesita.' },
        { type: 'Cómo debería funcionar', description: 'Comprobar la sesión antes de procesar nada de lo que la pantalla recibe.' },
      ],
    }, async ({ page }) => {
      test.setTimeout(60000);

      const antes = traza(caso.documento, caso.idCliente);
      expect(antes.documento, `La siembra tiene que dejar ${caso.elDocumento}`).not.toBeNull();
      expect(antes.documento.lineas, 'Con una sola línea').toBe(1);

      await iniciarSesion(page, `modulos/mod_venta/${caso.pantalla}?id=${antes.documento.id}&accion=editar`);
      await expect(page.locator('#idArticulo')).toBeVisible({ timeout: 10000 });

      await page.fill('#idArticulo', String(ID_ARTICULO));
      await Promise.all([
        page.waitForResponse(
          (r) => r.url().includes('tareas.php') && (r.request().postData() || '').includes('anhadirTemporal')
        ),
        page.locator('#idArticulo').press('Enter'),
      ]);
      await expect(page.locator('#Guardar')).toBeVisible({ timeout: 10000 });

      // La sesión caduca con la pantalla abierta.
      await page.context().clearCookies();
      await page.locator('#Guardar').click();
      await page.waitForLoadState('load');

      await expect(page.locator('#usr'), 'La pantalla pide iniciar sesión...').toBeVisible();
      const despues = traza(caso.documento, caso.idCliente);
      expect(despues.documento.id, `... pero ${caso.elDocumento} ya se ha reescrito...`).toBe(antes.documento.id);
      expect(despues.documento.lineas, '... con la línea añadida...').toBe(2);
      expect(despues.borradores, '... y su borrador cerrado, como en un guardado normal').toBe(0);
    });
  }
});
