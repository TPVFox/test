/**
 * Flujo por teclado: flechas arriba/abajo entre las cajas de cantidad de dos líneas ya
 * añadidas (`cajas_input`, caja `Unidad_Fila`).
 *
 * Curiosidad, no defecto: en `parametros.xml` la tecla 40 (flecha abajo) dispara la
 * acción `mover_up` y la tecla 38 (flecha arriba) dispara `mover_down` — los nombres de
 * acción están invertidos respecto a la tecla física. La tabla añade cada línea nueva al
 * principio (`AgregarFilaProductosAl()`: `$("#tabla").prepend(...)`), así que la fila de
 * número más alto queda arriba visualmente; con ese orden, "mover_up" (que resta uno al
 * número de fila) es lo que de verdad mueve el foco hacia abajo en pantalla, y viceversa.
 * El resultado que ve el usuario es correcto — flecha abajo baja, flecha arriba sube—,
 * así que esto se documenta como comportamiento real, no como hallazgo.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');
const { seleccionarCliente } = require('../../fixtures/seleccionarCliente');

const ID_CLIENTE = 924; // '[E2E venta] Cliente navegacion teclado'
const ID_ARTICULO_1 = 14678;
const ID_ARTICULO_2 = 14679;

test.describe('Pedido — navegar filas por teclado', () => {
  test('T1 la flecha abajo baja a la fila anterior y la flecha arriba vuelve a subir', {
    tag: ['@estado-actual', '@pedido', '@teclado'],
    annotation: [
      { type: 'Comportamiento', description: 'Con dos líneas, la flecha abajo lleva el foco a la línea de abajo y la flecha arriba lo devuelve. Las acciones de teclado tienen los nombres invertidos respecto a la tecla, pero el resultado que ve el operador es el correcto.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/pedido.php');

    await seleccionarCliente(page, ID_CLIENTE);
    await expect(page.locator('#idArticulo')).toBeVisible({ timeout: 10000 });

    await page.fill('#idArticulo', String(ID_ARTICULO_1));
    await page.locator('#idArticulo').press('Enter');
    await expect(page.locator('#tabla tr:has-text("' + ID_ARTICULO_1 + '")')).toBeVisible({ timeout: 10000 });

    await page.fill('#idArticulo', String(ID_ARTICULO_2));
    await page.locator('#idArticulo').press('Enter');
    await expect(page.locator('#tabla tr:has-text("' + ID_ARTICULO_2 + '")')).toBeVisible({ timeout: 10000 });

    // No se asume el total de filas: como no hay limpieza entre ejecuciones (el
    // temporal del cliente persiste, sin rollback), otra ejecucion anterior puede haber
    // dejado filas propias. Las dos que interesan son siempre las dos primeras del DOM
    // -las recien anadidas-, porque AgregarFilaProductosAl() antepone cada fila nueva.
    const cajasUnidad = page.locator('input[id^="Unidad_Fila_"]');
    await expect(cajasUnidad.first()).toBeVisible({ timeout: 10000 });
    const [filaSuperior, filaInferior] = await Promise.all([
      cajasUnidad.nth(0).getAttribute('id'),
      cajasUnidad.nth(1).getAttribute('id'),
    ]);

    await page.locator('#' + filaSuperior).focus();
    await page.keyboard.press('ArrowDown');
    await expect(page.locator('#' + filaInferior)).toBeFocused();

    await page.keyboard.press('ArrowUp');
    await expect(page.locator('#' + filaSuperior)).toBeFocused();
  });
});
