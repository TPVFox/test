/**
 * Defecto: el botón «Cambiar estado» del listado no hace nada.
 *
 * Síntoma: con una fila marcada, el clic no dispara ninguna petición a `tareas.php`, no
 * abre ningún modal ni cambia nada visible — solo un `console.log(ids)` en la consola del
 * navegador. Causa raíz: `cambioEstadoSeleccionDocumentos(dedonde, ObjIds)`
 * (`funciones.js`) recoge los ids de las filas marcadas y los vuelca con `console.log`;
 * su propio comentario dice «Abrir un modal con un selected para seleccione que estado
 * quiere poner y cambie los documentos seleccionados de estado», pero ese modal nunca se
 * escribió — es una función a medio implementar, no rota por un error. El botón que la
 * dispara sí es real y visible (`pedidosListado.php`: `<button onclick="metodoClick(
 * 'cambiarEstado','pedido')">Cambiar estado</button>`, y su equivalente en
 * `albaranesListado.php`/`facturasListado.php`), así que el usuario que marca un
 * documento y pulsa el botón no recibe ningún indicio de que no ha pasado nada.
 * Corrección propuesta: completar el modal que el comentario describe, o retirar el botón
 * si la funcionalidad ya no hace falta.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');

test.describe('Pedido — defecto: el botón «Cambiar estado» no hace nada', () => {
  test('T1 marcar una fila y pulsar el botón debería ofrecer elegir el nuevo estado', {
    tag: ['@esperado', '@pedido', '@listado', '@estados', '@directo', '@critico'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'Con una fila marcada, pulsar «Cambiar estado» no hace nada visible: no se abre ninguna ventana, no se envía ninguna petición y el operador no recibe ningún aviso.' },
      { type: 'Qué debería ocurrir', description: 'Que se abra una ventana para elegir el nuevo estado de los documentos marcados.' },
      { type: 'Por qué ocurre', description: 'La función que atiende el botón recoge los documentos marcados y solo los escribe en la consola del navegador: la ventana que su propio comentario describe nunca se llegó a escribir.' },
      { type: 'Cómo debería funcionar', description: 'Completar esa ventana y el cambio de estado de los documentos marcados, o retirar el botón si la función no hace falta.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/pedidosListado.php');

    const checkbox = page.locator('input.Check').first();
    await expect(checkbox).toBeVisible({ timeout: 10000 });
    await checkbox.check();

    await page.getByRole('button', { name: 'Cambiar estado' }).click();

    // Se espera un modal para elegir el estado nuevo. Hoy no aparece ninguno — la
    // función solo hace console.log(ids) — así que esta espera agota su tiempo. El caso
    // está declarado como fallo esperado hasta que la función se complete.
    await expect(page.locator('.modal.in, .modal.show')).toBeVisible({ timeout: 5000 });
  });
});
