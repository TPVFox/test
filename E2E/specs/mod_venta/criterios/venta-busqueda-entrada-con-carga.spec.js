/**
 * Criterio de aceptación: lo que el operador teclea es un dato, nunca parte de la consulta.
 *
 * Síntoma: la caja de descripción de la fila de entrada compone su búsqueda concatenando el
 * término dentro de las comillas de un `LIKE`. Un término que cierre esa comilla deja de
 * acotar y pasa a mandar sobre la consulta: se teclea algo que no existe y aparece el
 * catálogo entero.
 *
 * Causa raíz: la búsqueda monta la sentencia con el término pegado al SQL, sin parametrizar y
 * sin escapar. Es una instancia de un patrón que alcanza a 42 sentencias del módulo, ninguna
 * de ellas preparada.
 *
 * Corrección esperada: consulta preparada, con el término viajando como parámetro. La
 * convención del proyecto ya lo exige para toda lectura nueva.
 *
 * Declarado con `test.fail()`. No hay «antes» en la suite E2E: el defecto solo estaba cubierto
 * por pruebas de integración, que lo alcanzan por la clase y no por la pantalla.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../../fixtures/autenticacion');
const { seleccionarCliente } = require('../../../fixtures/seleccionarCliente');

const ID_CLIENTE = 955; // '[E2E venta] CC inyeccion en busqueda'

/**
 * Un término que no casa con ningún artículo y que además cierra la comilla del `LIKE`.
 * La condición que llega a la base queda como «nombre LIKE "%zzz…%" or "1" like "1%"»: la
 * primera mitad no encuentra nada y la segunda es siempre cierta, de modo que devuelve el
 * catálogo entero.
 *
 * Va sin espacios a propósito: la búsqueda trocea el término por espacios y compone un
 * `LIKE` por palabra unido con `AND`, así que una carga con espacios se neutraliza sola.
 * Medido en la base de pruebas: con el término limpio, 0 artículos; con la carga, los 5.
 */
const TERMINO_CON_CARGA = 'zzzNoExisteZzz%"or"1"like"1';

/** El mismo término sin la carga: el control de que la búsqueda legítima sigue acotando. */
const TERMINO_LIMPIO = 'zzzNoExisteZzz';

/** Abre un pedido del cliente y deja la fila de entrada lista para buscar. */
async function pedidoConFilaDeEntrada(page) {
  await iniciarSesion(page, 'modulos/mod_venta/pedido.php');
  await seleccionarCliente(page, ID_CLIENTE);
  await expect(page.locator('#Descripcion')).toBeVisible({ timeout: 10000 });
}

test.describe('Venta — una entrada con carga no amplía la búsqueda', { tag: '@criterio' }, () => {
  test.fail('T1 un término que cierra la comilla no devuelve el catálogo entero', async ({ page }) => {
    await pedidoConFilaDeEntrada(page);

    await page.fill('#Descripcion', TERMINO_CON_CARGA);
    await page.locator('#Descripcion').press('Enter');

    // Con la entrada tratada como dato, no hay ningún artículo que se llame así, de modo que
    // el listado de coincidencias no debería llegar a abrirse.
    await page.waitForTimeout(1500);
    await expect(page.locator('.FilaModal')).toHaveCount(0, { timeout: 2000 });
  });

  test('T2 el mismo término sin carga tampoco encuentra nada', async ({ page }) => {
    await pedidoConFilaDeEntrada(page);

    await page.fill('#Descripcion', TERMINO_LIMPIO);
    await page.locator('#Descripcion').press('Enter');

    // Control positivo: este sí pasa hoy, y tiene que seguir pasando después de la
    // corrección. Sin él, «no aparece nada» podría significar que la búsqueda se rompió.
    await page.waitForTimeout(1500);
    await expect(page.locator('.FilaModal')).toHaveCount(0, { timeout: 2000 });
  });

  test('T3 una búsqueda legítima sigue encontrando sus artículos', async ({ page }) => {
    await pedidoConFilaDeEntrada(page);

    await page.fill('#Descripcion', '[E2E venta] Manzana');
    await page.locator('#Descripcion').press('Enter');

    // El otro control positivo: la corrección no puede dejar la búsqueda sin encontrar nada.
    await expect(page.locator('.FilaModal').first()).toBeVisible({ timeout: 10000 });
    await expect(page.locator('.FilaModal')).toHaveCount(2);
  });
});
