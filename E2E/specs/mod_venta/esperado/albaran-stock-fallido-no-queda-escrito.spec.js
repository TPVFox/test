/**
 * Comportamiento esperado: un albarán cuyo movimiento de existencias falla no queda escrito.
 *
 * Síntoma: se compone un albarán con un artículo cuyo saldo está en el límite inferior de la
 * columna y se guarda. La cabecera y la línea se escriben; el movimiento de existencias, que va
 * después, falla. Queda un albarán con su mercancía y sin la salida de inventario que le
 * corresponde.
 *
 * Causa raíz: el movimiento de existencias va después de escribir la línea y no comparte
 * transacción con ella. Cuando falla, la cabecera y la línea ya están confirmadas.
 *
 * Corrección esperada: el guardado entero en una transacción que abarque también el inventario.
 *
 * Por qué este vector. Es el que falla justo en el movimiento de existencias y no antes: restar
 * una unidad a un saldo en el límite lo saca de rango y la base rechaza la sentencia. El artículo
 * lo deja la siembra, que repone el saldo en cada pasada. Se teclea como cualquier otro.
 *
 * **Se conserva en rojo** hasta que el guardado vaya en una transacción.
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const { iniciarSesion } = require('../../../fixtures/autenticacion');
const { seleccionarCliente } = require('../../../fixtures/seleccionarCliente');

const ID_ARTICULO_SALDO_LIMITE = 14683; // '[E2E venta] Kiwi con el saldo en el limite'
const ID_CLIENTE = 987;

/** Lo que la base dice del último albarán de ese cliente. */
function traza() {
  const guion = path.resolve(__dirname, '../../../../support/leer-traza-de-documento.php');

  return JSON.parse(execFileSync('php', [guion, 'albaran', String(ID_CLIENTE)], { encoding: 'utf-8' }));
}

test.describe('Venta — el albarán y su salida de existencias van juntos', () => {
  test.setTimeout(90000);

  test('T1 si el movimiento de existencias falla, no queda albarán', {
    tag: ['@esperado', '@albaran', '@guardado', '@existencias', '@directo', '@alto'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'Guardar un albarán de un artículo cuyo movimiento de existencias falla deja escritos el albarán y su línea, sin la salida de inventario.' },
      { type: 'Qué debería ocurrir', description: 'Que no quede albarán: o se escribe con su salida de existencias, o no se escribe.' },
      { type: 'Por qué ocurre', description: 'El movimiento de existencias va después de escribir la línea y no comparte transacción con ella.' },
      { type: 'Cómo debería funcionar', description: 'El guardado entero en una transacción que abarque también el inventario.' },
    ],
  }, async ({ page }) => {
    expect(traza().documento, 'El cliente parte sin albaranes').toBeNull();

    await iniciarSesion(page, 'modulos/mod_venta/albaran.php');
    await seleccionarCliente(page, ID_CLIENTE);
    await expect(page.locator('#idArticulo')).toBeVisible({ timeout: 10000 });

    await page.fill('#idArticulo', String(ID_ARTICULO_SALDO_LIMITE));
    await Promise.all([
      page.waitForResponse(
        (r) => r.url().includes('tareas.php') && (r.request().postData() || '').includes('anhadirTemporal')
      ),
      page.locator('#idArticulo').press('Enter'),
    ]);

    await expect(page.locator('#Guardar')).toBeVisible({ timeout: 10000 });
    const [respuesta] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('albaran.php') && r.request().method() === 'POST'),
      page.locator('#Guardar').click(),
    ]);
    await page.waitForLoadState('load');

    expect(respuesta.status(), 'El guardado tiene que fallar para llegar al estado que este recorrido comprueba').toBe(500);

    const despues = traza();
    expect(despues.documento, 'El albarán no puede quedar escrito sin su salida de existencias').toBeNull();
    expect(despues.borradores, 'El documento en curso sobrevive, para no perder lo que el operador compuso').toBeGreaterThan(0);
  });
});
