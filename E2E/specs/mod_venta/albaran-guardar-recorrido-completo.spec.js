/**
 * El recorrido completo de guardar un albarán, de pantalla vacía a albarán en el listado.
 *
 * Es el flujo que ata las dos mitades del componente: la pantalla compone un temporal por
 * AJAX y, al pulsar Guardar, el POST entra en la rama que llama a `eliminarAlbaranTablas()`
 * y `AddAlbaranGuardado()` de la clase, ya verificadas por separado en `Integration/PHP`.
 *
 * Un detalle del flujo sin el que el recorrido no existiría: al crear el temporal, el
 * cliente no recarga la página, sino que reescribe la URL con `history.pushState` para
 * añadirle `?tActual=<id>`. El formulario postea a la URL actual (`action=""`), así que es
 * ese empujón el que hace que el servidor vea el temporal al recibir el POST. Sin él,
 * Guardar enviaría a `albaran.php` sin `tActual` y la rama de guardado no llegaría a correr.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');
const { seleccionarCliente } = require('../../fixtures/seleccionarCliente');

const ID_CLIENTE = 931; // '[E2E venta] Cliente albaran guardar'
const NOMBRE_CLIENTE = '[E2E venta] Cliente albaran guardar';
const ID_ARTICULO = 14678;

test.describe('Albarán — recorrido completo de guardado', () => {
  test('T1 componer y guardar deja el albarán en el listado', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/albaran.php');

    const erroresDePagina = [];
    page.on('pageerror', (error) => erroresDePagina.push(error.message));

    await seleccionarCliente(page, ID_CLIENTE);
    await expect(page.locator('#idArticulo')).toBeVisible({ timeout: 10000 });

    // Anadir la linea dispara `addTemporal()`, que crea el temporal en el servidor.
    await page.fill('#idArticulo', String(ID_ARTICULO));
    await Promise.all([
      page.waitForResponse(
        (r) => r.url().includes('tareas.php') && (r.request().postData() || '').includes('anhadirTemporal')
      ),
      page.locator('#idArticulo').press('Enter'),
    ]);

    await expect(page.locator(`#tabla tr:has-text("${ID_ARTICULO}")`).first()).toBeVisible({ timeout: 10000 });

    // El temporal existe: la URL lo lleva y los dos botones aparecen.
    await expect(page).toHaveURL(/tActual=\d+/);
    await expect(page.locator('#Guardar')).toBeVisible();
    await expect(page.locator('#Cancelar')).toBeVisible();

    await page.locator('#Guardar').click();

    // Guardar sin errores redirige al listado.
    await expect(page).toHaveURL(/albaranesListado\.php/, { timeout: 15000 });

    const fila = page.locator('table tbody tr').filter({ hasText: NOMBRE_CLIENTE }).first();
    await expect(fila).toBeVisible();
    await expect(fila).toContainText('Guardado');

    expect(erroresDePagina).toEqual([]);
  });
});
