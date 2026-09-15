/**
 * Criterio de aceptación: vender más de lo disponible no deja el inventario en negativo sin
 * decirlo.
 *
 * Síntoma: un artículo con dos unidades registradas se vende de cinco en un albarán, y el
 * saldo queda en -3. No hay aviso en ningún momento: ni al teclear la cantidad, ni al guardar.
 * El operador se entera cuando alguien mira el inventario, si mira.
 *
 * Causa raíz: el movimiento de existencias resta lo que se le pida sin comprobar contra el
 * saldo disponible. No hay suelo en ningún punto del camino.
 *
 * Corrección esperada: advertir antes de dejar el inventario en un valor imposible. Queda
 * abierto si además se impide —es decisión de negocio, porque vender sin existencias
 * registradas puede ser legítimo mientras el inventario no esté al día—, de modo que este
 * recorrido afirma lo mínimo común: que el operador reciba el aviso.
 *
 * El artículo de este recorrido es propio y su ficha se repone a dos unidades en cada pasada:
 * el recorrido la deja en negativo, y sin reponerla la segunda pasada partiría de un saldo ya
 * negativo.
 *
 * Declarado con `test.fail()`. No hay «antes» en la suite E2E: el defecto solo estaba cubierto
 * por pruebas de integración.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../../fixtures/autenticacion');
const { seleccionarCliente } = require('../../../fixtures/seleccionarCliente');

const ID_CLIENTE = 961; // '[E2E venta] CC suelo existencias'
const ID_ARTICULO_ESCASO = 14680; // '[E2E venta] CC Pera escasa', sembrado con 2 unidades
const UNIDADES_DISPONIBLES = 2;
const UNIDADES_QUE_SE_VENDEN = 5;

test.describe('Albarán — suelo de existencias', { tag: '@criterio' }, () => {
  test.fail('T1 vender más de lo disponible avisa al operador', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/albaran.php');
    await seleccionarCliente(page, ID_CLIENTE);
    await expect(page.locator('#idArticulo')).toBeVisible({ timeout: 10000 });

    // Los avisos del navegador se recogen en vez de aceptarse a ciegas: si la corrección los
    // usa para advertir, este recorrido tiene que verlos.
    const avisos = [];
    page.on('dialog', (dialogo) => {
      avisos.push(dialogo.message());
      dialogo.accept();
    });

    await page.fill('#idArticulo', String(ID_ARTICULO_ESCASO));
    await Promise.all([
      page.waitForResponse(
        (r) => r.url().includes('tareas.php') && (r.request().postData() || '').includes('anhadirTemporal')
      ),
      page.locator('#idArticulo').press('Enter'),
    ]);

    await expect(page.locator('#Unidad_Fila_1')).toBeVisible({ timeout: 10000 });
    await page.fill('#Unidad_Fila_1', String(UNIDADES_QUE_SE_VENDEN));
    await page.locator('#Unidad_Fila_1').press('Enter');

    await expect(page.locator('#Guardar')).toBeVisible({ timeout: 10000 });
    await page.locator('#Guardar').click();
    await page.waitForLoadState('networkidle');

    // El aviso puede llegar por cualquiera de las dos vías que el producto ya usa: un diálogo
    // del navegador, o una advertencia pintada en la pantalla. Vale cualquiera de las dos.
    const avisoEnPantalla = await page
      .locator('.alert-warning, .alert-danger')
      .filter({ hasText: /existencia|stock|disponible/i })
      .count();

    expect(
      avisos.length + avisoEnPantalla,
      `Vender ${UNIDADES_QUE_SE_VENDEN} de un artículo con ${UNIDADES_DISPONIBLES} debería advertirse`
    ).toBeGreaterThan(0);
  });
});
