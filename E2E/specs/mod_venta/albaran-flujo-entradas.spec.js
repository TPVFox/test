/**
 * Los tres estados en que se puede abrir `albaran.php`, y qué decide cada uno.
 *
 * La pantalla resuelve al cargar una acción («», 'ver' o 'editar') y de ella salen la
 * visibilidad de la fila de entrada de productos, la de los botones Guardar/Cancelar y si
 * el cliente se puede cambiar. Este recorrido fija esa correspondencia: es el flujo de
 * entrada del componente, no un caso de error.
 *
 * Al albarán ya guardado se llega desde el listado, como llega el operador, y no
 * escribiendo su id en la URL: el id de `albclit` es autoincremental y cambia al rehacer la
 * base, mientras que el número visible del albarán es otro campo distinto —para el albarán
 * sembrado aquí, id 1494 y número 2—, de modo que un id escrito en el spec dejaría de valer.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');

const NOMBRE_CLIENTE = '[E2E venta] Cliente albaran entradas';

/** La fila del listado que corresponde al albarán de este recorrido. */
function filaDelAlbaran(page) {
  return page.locator('table tbody tr').filter({ hasText: NOMBRE_CLIENTE }).first();
}

test.describe('Albarán — estados de entrada de la pantalla', () => {
  test('T1 sin parámetros: albarán nuevo, sin fila de entrada ni botones, con el cliente por elegir', {
    tag: ['@estado-actual', '@albaran', '@entrada'],
    annotation: [
      { type: 'Comportamiento', description: 'Abierta sin parámetros, la pantalla ofrece un albarán nuevo: sin fila de entrada ni botones, y con el cliente por elegir.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/albaran.php');

    await expect(page.locator('h2.text-center')).toContainText('Sin Guardar');
    await expect(page.locator('#estado')).toHaveValue('Nuevo');
    await expect(page.locator('#Row0')).toBeHidden();
    await expect(page.locator('#Guardar')).toBeHidden();
    await expect(page.locator('#Cancelar')).toBeHidden();
    await expect(page.locator('#id_cliente')).toBeEditable();
  });

  test('T2 abierto desde el enlace de ver: acción «ver», todo en solo lectura', {
    tag: ['@estado-actual', '@albaran', '@entrada'],
    annotation: [
      { type: 'Comportamiento', description: 'Desde el enlace de ver del listado, el albarán se abre en solo lectura: sin fila de entrada, sin Guardar y con el cliente bloqueado.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/albaranesListado.php');

    await filaDelAlbaran(page).locator('a[title="Ver albarán"]').click();

    await expect(page.locator('h2.text-center')).toContainText('ver');
    await expect(page.locator('#estado')).toHaveValue('Guardado');
    await expect(page.locator('#Row0')).toBeHidden();
    await expect(page.locator('#Guardar')).toBeHidden();
    await expect(page.locator('#id_cliente')).not.toBeEditable();
  });

  /**
   * El enlace de ver del listado envía `&estado=ver`, un parámetro que `albaran.php` no
   * lee en ningún sitio: la pantalla solo consulta `$_GET['accion']`. Acaba en 'ver' de
   * todas formas porque esa es la resolución por defecto de `?id=` sin acción, así que el
   * enlace funciona por coincidencia entre lo que envía y lo que la pantalla decide por su
   * cuenta. T2 comprueba el resultado; este caso fija que el parámetro viaja y se ignora.
   */
  test('T3 el enlace de ver envía un parámetro que la pantalla no lee', {
    tag: ['@estado-actual', '@albaran', '@entrada'],
    annotation: [
      { type: 'Comportamiento', description: 'El enlace de ver envía un parámetro que la pantalla no lee: el albarán acaba en modo ver porque ese es el modo por defecto, no por el parámetro.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/albaranesListado.php');

    const enlace = filaDelAlbaran(page).locator('a[title="Ver albarán"]');

    await expect(enlace).toHaveAttribute('href', /estado=ver/);
    await expect(enlace).not.toHaveAttribute('href', /accion=/);
  });

  test('T4 abierto desde el enlace de editar: acción «editar», con fila de entrada pero sin poder guardar', {
    tag: ['@estado-actual', '@albaran', '@entrada'],
    annotation: [
      { type: 'Comportamiento', description: 'Desde el enlace de editar se abre la fila de entrada, pero Guardar y Cancelar no aparecen hasta que la primera modificación crea el documento en curso.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/albaranesListado.php');

    await filaDelAlbaran(page).locator('a[title="Editar albarán"]').click();

    await expect(page.locator('h2.text-center')).toContainText('editar');
    await expect(page.locator('#Row0')).toBeVisible();
    await expect(page.locator('#id_cliente')).not.toBeEditable();
    // Sin temporal abierto no hay nada que guardar todavia: el boton solo aparece cuando
    // la primera modificacion crea el temporal.
    await expect(page.locator('#Guardar')).toBeHidden();
    await expect(page.locator('#Cancelar')).toBeHidden();
  });
});
