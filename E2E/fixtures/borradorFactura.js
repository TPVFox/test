/**
 * Apoyos para los recorridos del borrador de una factura ya emitida.
 *
 * Las facturas de esos recorridos llevan identificador fijo, sembrado por
 * `support/sembrar-e2e-venta.php`, de modo que se abren por su identificador y no desde el
 * listado.
 */

const { expect } = require('@playwright/test');

const ID_ARTICULO = 14678; // support/sembrar-e2e-venta.php
const AVISO_DISCREPANCIA = 'estado de factura es distinto al sin guardar';

/** Espera la respuesta de una tarea de `tareas.php`, y si se indica, con un valor enviado. */
function esperarTarea(page, pulsado, contiene = '') {
  return page.waitForResponse((respuesta) => {
    const datos = respuesta.request().postData() || '';
    return respuesta.url().includes('tareas.php') && datos.includes(pulsado) && datos.includes(contiene);
  });
}

async function abrirFacturaParaEditar(page, idFactura) {
  await page.goto(`modulos/mod_venta/factura.php?id=${idFactura}&accion=editar`);
  await expect(page.locator('#Row0')).toBeVisible({ timeout: 10000 });
}

/**
 * Ejecuta la acción que crea el borrador y espera a las dos peticiones que la siguen: la que
 * crea el borrador y la que marca la factura como no guardada, que sale de la respuesta de la
 * primera. Devuelve el identificador del borrador.
 */
async function esperarBorradorMarcado(page, accion) {
  const creado = esperarTarea(page, 'anhadirTemporal');
  const marcado = esperarTarea(page, 'modificarEstadoDocumento', 'Sin+guardar');
  await accion();
  await creado;
  await marcado;
  await expect(page).toHaveURL(/tActual=\d+/);

  return new URL(page.url()).searchParams.get('tActual');
}

/** La entrada más directa al borrador: añadir un producto por teclado. */
async function crearBorradorAnadiendoProducto(page, idFactura) {
  await abrirFacturaParaEditar(page, idFactura);
  await page.fill('#idArticulo', String(ID_ARTICULO));

  return esperarBorradorMarcado(page, () => page.locator('#idArticulo').press('Enter'));
}

/** El estado de la factura tal como lo muestra su propia pantalla, abierta para ver. */
async function estadoDeLaFactura(page, idFactura) {
  await page.goto(`modulos/mod_venta/factura.php?id=${idFactura}&accion=ver`);

  return page.locator('#estado').inputValue();
}

/** Los borradores atados a una factura, leídos de la columna de facturas abiertas del listado. */
async function borradoresDeLaFactura(page, idFactura) {
  await page.goto('modulos/mod_venta/facturasListado.php');

  return page.$$eval(
    'table.table-striped tbody tr',
    (filas, id) =>
      filas
        .filter((fila) => (fila.cells[1]?.textContent || '').trim() === String(id))
        .map((fila) =>
          new URL(fila.querySelector('a[href*="tActual="]').getAttribute('href'), 'http://x/').searchParams.get('tActual')
        ),
    idFactura
  );
}

/** El borrador se abre sin aviso grave y con Guardar y Cancelar disponibles. */
async function comprobarBorradorSinAviso(page, idTemporal) {
  await page.goto(`modulos/mod_venta/factura.php?tActual=${idTemporal}`);
  await expect(page.locator('#Guardar')).toBeVisible();
  await expect(page.locator('#Cancelar')).toBeVisible();
  await expect(page.locator('body')).not.toContainText(AVISO_DISCREPANCIA);
}

/** El borrador se abre con el aviso grave de discrepancia y sin ninguno de los dos botones. */
async function comprobarBorradorSinSalida(page, idTemporal) {
  await page.goto(`modulos/mod_venta/factura.php?tActual=${idTemporal}`);
  await expect(page.locator('body')).toContainText(AVISO_DISCREPANCIA);
  await expect(page.locator('#Guardar')).toBeHidden();
  await expect(page.locator('#Cancelar')).toBeHidden();
}

module.exports = {
  AVISO_DISCREPANCIA,
  abrirFacturaParaEditar,
  borradoresDeLaFactura,
  comprobarBorradorSinAviso,
  comprobarBorradorSinSalida,
  crearBorradorAnadiendoProducto,
  esperarBorradorMarcado,
  esperarTarea,
  estadoDeLaFactura,
};
