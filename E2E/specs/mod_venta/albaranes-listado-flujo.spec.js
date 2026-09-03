/**
 * El flujo de `albaranesListado.php`: dos listados distintos en la misma pantalla y las
 * tres formas de acotar el de la derecha.
 *
 * A la izquierda, «Albaranes Abiertos» son los temporales —borradores sin guardar—, y cada
 * uno enlaza a `albaran.php?tActual=<id>`. A la derecha, los albaranes ya guardados, que
 * pasan por paginación, búsqueda y filtro por estado. Los dos salen de consultas distintas
 * de la clase (`TodosTemporal()` y `TodosAlbaranesFiltro()`) y no comparten nada.
 *
 * El último recorrido cierra el ciclo del temporal —crearlo, verlo aparecer y cancelarlo—
 * para no dejar borradores sueltos en el despliegue, que es persistente entre ejecuciones.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');
const { seleccionarCliente } = require('../../fixtures/seleccionarCliente');

const ID_CLIENTE = 932; // '[E2E venta] Cliente albaran listado'
const NOMBRE_CLIENTE = '[E2E venta] Cliente albaran listado';
const ID_ARTICULO = 14678;

test.describe('Albaranes — flujo del listado', () => {
  test('T1 el listado muestra los albaranes guardados con su cliente y su estado', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/albaranesListado.php');

    const filasDelCliente = page.locator('table.table-bordered tbody tr').filter({ hasText: NOMBRE_CLIENTE });

    // Los dos albaranes sembrados para este cliente, cada uno con su estado.
    await expect(filasDelCliente.filter({ hasText: 'Guardado' })).toHaveCount(1);
    await expect(filasDelCliente.filter({ hasText: 'Procesado' })).toHaveCount(1);
  });

  test('T2 buscar por el nombre del cliente acota el listado a sus albaranes', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/albaranesListado.php');

    await page.fill('form[name="formBuscar"] input[name="buscar"]', 'listado');
    await Promise.all([
      page.waitForURL(/buscar=listado/),
      page.locator('form[name="formBuscar"] input[type="submit"]').click(),
    ]);

    const filas = page.locator('table.table-bordered tbody tr');
    await expect(filas).toHaveCount(2); // los dos albaranes sembrados para este cliente
    await expect(filas.first()).toContainText(NOMBRE_CLIENTE);
  });

  /**
   * Las opciones del filtro no son un catálogo fijo: salen de `getEstadosAlbaranes()`, que
   * pregunta a la tabla qué estados hay hoy (`SELECT DISTINCT estado`), no de
   * `posiblesEstados()`, que es el catálogo escrito en la clase. Son dos fuentes distintas
   * para la misma idea: el desplegable ofrece lo que hay, no lo que está previsto.
   */
  test('T3 el filtro por estado ofrece los estados que hay en la tabla', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/albaranesListado.php');

    const opciones = page.locator('form[name="formFiltrar"] select[name="filtro"] option');

    await expect(opciones.filter({ hasText: '-- Todos --' })).toHaveCount(1);
    await expect(opciones.filter({ hasText: 'Guardado' })).toHaveCount(1);
    await expect(opciones.filter({ hasText: 'Procesado' })).toHaveCount(1);
  });

  test('T4 filtrar por un estado deja solo los albaranes que lo tienen', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/albaranesListado.php');

    await Promise.all([
      page.waitForURL(/filtro=Procesado/),
      page.selectOption('form[name="formFiltrar"] select[name="filtro"]', 'Procesado'),
    ]);

    const estados = page.locator('table.table-bordered tbody tr td:nth-child(10)');
    const total = await estados.count();
    expect(total).toBeGreaterThan(0);
    for (let i = 0; i < total; i++) {
      await expect(estados.nth(i)).toContainText('Procesado');
    }
  });

  test('T5 un temporal aparece en «Albaranes Abiertos» y se cancela desde su propia pantalla', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/albaran.php');

    await seleccionarCliente(page, ID_CLIENTE);
    await expect(page.locator('#idArticulo')).toBeVisible({ timeout: 10000 });

    await page.fill('#idArticulo', String(ID_ARTICULO));
    await Promise.all([
      page.waitForResponse(
        (r) => r.url().includes('tareas.php') && (r.request().postData() || '').includes('anhadirTemporal')
      ),
      page.locator('#idArticulo').press('Enter'),
    ]);

    // La URL la reescribe el `success` del AJAX, no la respuesta: se espera a que llegue.
    await expect(page).toHaveURL(/tActual=\d+/);
    const idTemporal = new URL(page.url()).searchParams.get('tActual');

    // El temporal ya consta como albaran abierto, con enlace a su propia pantalla.
    await page.goto('modulos/mod_venta/albaranesListado.php');
    const abierto = page.locator(`a[href="albaran.php?tActual=${idTemporal}"]`);
    await expect(abierto).toBeVisible();

    // Cancelar no es una llamada directa: el boton postea el formulario, la pantalla se
    // vuelve a servir y es el JS que emite al recargarse quien pide la confirmacion y, ya
    // aceptada, despacha el borrado y devuelve al listado.
    await abierto.click();
    page.on('dialog', (dialogo) => dialogo.accept());
    await page.locator('#Cancelar').click();

    await expect(page).toHaveURL(/albaranesListado\.php/, { timeout: 15000 });
    await expect(page.locator(`a[href="albaran.php?tActual=${idTemporal}"]`)).toHaveCount(0);
  });
});
