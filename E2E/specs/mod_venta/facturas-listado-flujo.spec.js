/**
 * El flujo de `facturasListado.php`: dos listados distintos en la misma pantalla y las
 * formas de acotar el de la derecha.
 *
 * A la izquierda, las facturas abiertas son los temporales —borradores sin guardar—, y cada
 * uno enlaza a `factura.php?tActual=<id>`. A la derecha, las facturas ya emitidas, que pasan
 * por paginación, búsqueda y filtro por estado. Los dos salen de consultas distintas de la
 * clase (`TodosTemporal()` y `TodosFacturaFiltro()`) y no comparten nada.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');

// El nombre evita la palabra «listado»: la busqueda del listado de albaranes la usa
// suelta, y todo cliente que la lleve entra en su recuento.
const NOMBRE_CLIENTE = '[E2E venta] Cliente factura relacion';

test.describe('Facturas — flujo del listado', () => {
  test('T1 el listado muestra las facturas emitidas con su cliente y su estado', {
    tag: ['@estado-actual', '@factura', '@listado'],
    annotation: [
      { type: 'Comportamiento', description: 'El listado muestra las facturas emitidas de cada cliente con su estado.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/facturasListado.php');

    const filasDelCliente = page.locator('table.table-bordered tbody tr').filter({ hasText: NOMBRE_CLIENTE });

    await expect(filasDelCliente.filter({ hasText: 'Guardado' })).toHaveCount(1);
    await expect(filasDelCliente.filter({ hasText: 'Procesado' })).toHaveCount(1);
  });

  test('T2 buscar por el nombre del cliente acota el listado a sus facturas', {
    tag: ['@estado-actual', '@factura', '@listado', '@busqueda'],
    annotation: [
      { type: 'Comportamiento', description: 'Buscar por el nombre del cliente acota el listado a sus facturas.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/facturasListado.php');

    await page.fill('form[name="formBuscar"] input[name="buscar"]', 'factura relacion');
    await Promise.all([
      page.waitForURL(/buscar=/),
      page.locator('form[name="formBuscar"] input[type="submit"]').click(),
    ]);

    const filas = page.locator('table.table-bordered tbody tr');
    await expect(filas).toHaveCount(2);
    await expect(filas.first()).toContainText(NOMBRE_CLIENTE);
  });

  /**
   * Las opciones del filtro no son un catálogo fijo: salen de `getEstadosFacturas()`, que
   * pregunta a la tabla qué estados hay hoy, no de `posiblesEstados()`, que es el catálogo
   * escrito en la clase. El desplegable ofrece lo que hay, no lo que está previsto.
   */
  test('T3 el filtro por estado ofrece los estados que hay en la tabla', {
    tag: ['@estado-actual', '@factura', '@listado', '@estados'],
    annotation: [
      { type: 'Comportamiento', description: 'El filtro por estado ofrece los estados que existen en la tabla, no los de un catálogo fijo.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/facturasListado.php');

    const opciones = page.locator('form[name="formFiltrar"] select[name="filtro"] option');

    await expect(opciones.filter({ hasText: 'Guardado' })).toHaveCount(1);
    await expect(opciones.filter({ hasText: 'Procesado' })).toHaveCount(1);
  });

  test('T4 filtrar por un estado deja solo las facturas que lo tienen', {
    tag: ['@estado-actual', '@factura', '@listado', '@estados'],
    annotation: [
      { type: 'Comportamiento', description: 'Filtrar por un estado deja solo las facturas que lo tienen.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/facturasListado.php');

    await page.selectOption('form[name="formFiltrar"] select[name="filtro"]', 'Procesado');
    await page.waitForLoadState('networkidle');

    const filas = page.locator('table.table-bordered tbody tr');
    await expect(filas.filter({ hasText: 'Guardado' })).toHaveCount(0);
    await expect(filas.first()).toContainText('Procesado');
  });

  /**
   * El aviso de listado vacío dice «albaranes», no «facturas»: el fichero se escribió
   * copiando el del albarán y ese texto no se cambió. Se llega a él buscando algo que no
   * existe.
   */
  test('T5 el aviso de listado vacío habla de albaranes', {
    tag: ['@estado-actual', '@defecto', '@factura', '@listado', '@bajo'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'Buscando algo que no existe, el listado de facturas avisa «No tienes albaranes guardados!».' },
      { type: 'Qué debería ocurrir', description: 'Que el aviso hable de facturas.' },
      { type: 'Por qué ocurre', description: 'La pantalla se escribió copiando la del albarán y ese texto no se adaptó.' },
      { type: 'Cómo debería funcionar', description: 'Cambiar la palabra del aviso.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/facturasListado.php');

    await page.fill('form[name="formBuscar"] input[name="buscar"]', 'zzz-no-existe-zzz');
    await Promise.all([
      page.waitForURL(/buscar=/),
      page.locator('form[name="formBuscar"] input[type="submit"]').click(),
    ]);

    await expect(page.locator('.alert-warning')).toContainText('No tienes albaranes guardados');
  });

  /**
   * La tabla de facturas abiertas no se acota ni por tienda ni por usuario: muestra los
   * borradores de todo el sistema, y desde cualquiera de ellos se puede continuar el
   * documento en curso de otra persona.
   */
  test('T6 las facturas abiertas incluyen borradores de otros clientes y tiendas', {
    tag: ['@estado-actual', '@defecto', '@factura', '@listado', '@borrador', '@alto'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'La lista de facturas abiertas muestra los borradores de todo el sistema, de cualquier tienda y usuario, y desde cualquiera se puede continuar el documento en curso de otra persona.' },
      { type: 'Qué debería ocurrir', description: 'Que cada sesión vea solo los borradores que le corresponden.' },
      { type: 'Por qué ocurre', description: 'La consulta de borradores no filtra ni por tienda ni por usuario.' },
      { type: 'Cómo debería funcionar', description: 'Acotar la consulta por la tienda y el usuario de la sesión.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/facturasListado.php');

    const borradores = page.locator('table.table-striped tbody tr');

    await expect(borradores.first()).toBeVisible();
    await expect(borradores.first().locator('a[href*="tActual="]')).toBeVisible();
    await expect(borradores.filter({ hasText: '[E2E venta] Cliente factura entradas' })).toHaveCount(1);
  });
});
