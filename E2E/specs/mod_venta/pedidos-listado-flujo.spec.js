/**
 * El flujo de `pedidosListado.php`: dos listados distintos en la misma pantalla y las tres
 * formas de acotar el de la derecha.
 *
 * A la izquierda, los pedidos abiertos son los temporales —borradores sin guardar—, y cada
 * uno enlaza a `pedido.php?tActual=<id>`. A la derecha, los pedidos ya guardados, que pasan
 * por paginación, búsqueda y filtro por estado. Los dos salen de consultas distintas de la
 * clase (`TodosTemporal()` y `TodosPedidosFiltro()`) y no comparten nada.
 *
 * El último recorrido cierra el ciclo del temporal —crearlo, verlo aparecer y cancelarlo—
 * para no dejar borradores sueltos en el despliegue, que es persistente entre ejecuciones.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');
const { seleccionarCliente } = require('../../fixtures/seleccionarCliente');

const ID_CLIENTE = 934; // '[E2E venta] Cliente pedido listado'
const NOMBRE_CLIENTE = '[E2E venta] Cliente pedido listado';

test.describe('Pedidos — flujo del listado', () => {
  test('T1 el listado muestra los pedidos guardados con su cliente y su estado', {
    tag: ['@estado-actual', '@pedido', '@listado'],
    annotation: [
      { type: 'Comportamiento', description: 'El listado muestra los pedidos guardados de cada cliente con su estado.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/pedidosListado.php');

    const filasDelCliente = page.locator('table.table-bordered tbody tr').filter({ hasText: NOMBRE_CLIENTE });

    await expect(filasDelCliente.filter({ hasText: 'Guardado' })).toHaveCount(1);
    await expect(filasDelCliente.filter({ hasText: 'Procesado' })).toHaveCount(1);
  });

  test('T2 buscar por el nombre del cliente acota el listado a sus pedidos', {
    tag: ['@estado-actual', '@pedido', '@listado', '@busqueda'],
    annotation: [
      { type: 'Comportamiento', description: 'Buscar por el nombre del cliente acota el listado a sus pedidos.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/pedidosListado.php');

    await page.fill('form[name="formBuscar"] input[name="buscar"]', 'pedido listado');
    await Promise.all([
      page.waitForURL(/buscar=/),
      page.locator('form[name="formBuscar"] input[type="submit"]').click(),
    ]);

    const filas = page.locator('table.table-bordered tbody tr');
    await expect(filas).toHaveCount(2);
    await expect(filas.first()).toContainText(NOMBRE_CLIENTE);
  });

  /**
   * Las opciones del filtro no son un catálogo fijo: salen de `getEstadosPedidos()`, que
   * pregunta a la tabla qué estados hay hoy (`SELECT DISTINCT estado`), no de
   * `posiblesEstados()`, que es el catálogo escrito en la clase. Son dos fuentes distintas
   * para la misma idea: el desplegable ofrece lo que hay, no lo que está previsto.
   */
  test('T3 el filtro por estado ofrece los estados que hay en la tabla', {
    tag: ['@estado-actual', '@pedido', '@listado', '@estados'],
    annotation: [
      { type: 'Comportamiento', description: 'El filtro por estado ofrece los estados que existen en la tabla, no los de un catálogo fijo.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/pedidosListado.php');

    const opciones = page.locator('form[name="formFiltrar"] select[name="filtro"] option');

    await expect(opciones.filter({ hasText: 'Guardado' })).toHaveCount(1);
    await expect(opciones.filter({ hasText: 'Procesado' })).toHaveCount(1);
  });

  test('T4 filtrar por un estado deja solo los pedidos que lo tienen', {
    tag: ['@estado-actual', '@pedido', '@listado', '@estados'],
    annotation: [
      { type: 'Comportamiento', description: 'Filtrar por un estado deja solo los pedidos que lo tienen.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/pedidosListado.php');

    await page.selectOption('form[name="formFiltrar"] select[name="filtro"]', 'Procesado');
    await page.waitForLoadState('networkidle');

    const filas = page.locator('table.table-bordered tbody tr');
    await expect(filas.filter({ hasText: 'Guardado' })).toHaveCount(0);
    await expect(filas.first()).toContainText('Procesado');
  });

  /**
   * Búsqueda y filtro se concatenan en el mismo fragmento de SQL, con el operador que la
   * paginación decide. Combinarlos es la condición que ninguno de los dos casos anteriores
   * recorre por separado.
   */
  test('T6 buscar y filtrar a la vez acota por las dos condiciones', {
    tag: ['@estado-actual', '@pedido', '@listado', '@busqueda', '@estados'],
    annotation: [
      { type: 'Comportamiento', description: 'Buscar y filtrar a la vez acota por las dos condiciones.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/pedidosListado.php');

    await page.fill('form[name="formBuscar"] input[name="buscar"]', 'pedido listado');
    await Promise.all([
      page.waitForURL(/buscar=/),
      page.locator('form[name="formBuscar"] input[type="submit"]').click(),
    ]);
    await page.selectOption('form[name="formFiltrar"] select[name="filtro"]', 'Procesado');
    await page.waitForLoadState('networkidle');

    const filas = page.locator('table.table-bordered tbody tr');
    await expect(filas).toHaveCount(1);
    await expect(filas.first()).toContainText(NOMBRE_CLIENTE);
    await expect(filas.first()).toContainText('Procesado');
  });

  /**
   * El ciclo completo del borrador desde el listado: se crea al añadir la primera línea en
   * `pedido.php`, aparece en el listado de pedidos abiertos con enlace propio, y se retira
   * con Cancelar. Que el borrador aparezca aquí es lo que hace que el pedido del que
   * procede quede bloqueado mientras exista.
   */
  test('T5 un borrador aparece en el listado de abiertos y se retira al cancelarlo', {
    tag: ['@estado-actual', '@pedido', '@listado', '@borrador'],
    annotation: [
      { type: 'Comportamiento', description: 'Un documento en curso aparece en la lista de pedidos abiertos con su enlace, y desaparece al cancelarlo desde su pantalla.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/pedido.php');
    await seleccionarCliente(page, ID_CLIENTE);

    await expect(page.locator('#idArticulo')).toBeVisible({ timeout: 10000 });
    await page.fill('#idArticulo', '14678');
    await Promise.all([
      page.waitForResponse(
        (r) => r.url().includes('tareas.php') && (r.request().postData() || '').includes('anhadirTemporal')
      ),
      page.locator('#idArticulo').press('Enter'),
    ]);

    await expect(page).toHaveURL(/tActual=\d+/);
    const idTemporal = new URL(page.url()).searchParams.get('tActual');

    // El borrador ya consta como pedido abierto, con enlace a su propia pantalla.
    await page.goto('modulos/mod_venta/pedidosListado.php');
    const enlaceDelBorrador = page.locator(`a[href="pedido.php?tActual=${idTemporal}"]`);
    await expect(enlaceDelBorrador).toBeVisible();

    // Cancelar no es una llamada directa: el boton postea el formulario, la pantalla se
    // vuelve a servir y es el JS que emite al recargarse quien pide la confirmacion y, ya
    // aceptada, despacha el borrado y devuelve al listado.
    await enlaceDelBorrador.click();
    page.on('dialog', (dialogo) => dialogo.accept());
    await page.locator('#Cancelar').click();

    await expect(page).toHaveURL(/pedidosListado\.php/, { timeout: 15000 });
    await expect(page.locator(`a[href="pedido.php?tActual=${idTemporal}"]`)).toHaveCount(0);
  });
});
