/**
 * Los estados en que se puede abrir `factura.php`, y qué decide cada uno.
 *
 * La pantalla resuelve al cargar una acción («», 'ver' o 'editar') y de ella salen la
 * visibilidad de la fila de entrada de productos, la de los botones Guardar/Cancelar y si
 * el cliente se puede cambiar. Hay además un estado que la pantalla degrada: una factura
 * con un borrador abierto no se deja editar desde aquí.
 *
 * A la factura ya guardada se llega desde el listado, como llega el operador, y no
 * escribiendo su id en la URL: el id de `facclit` es autoincremental y cambia al rehacer la
 * base, mientras que el número visible de la factura es otro campo distinto.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');

const NOMBRE_CLIENTE = '[E2E venta] Cliente factura entradas';

/** Las filas del listado que corresponden a facturas de este recorrido. */
function filasDeFactura(page) {
  return page.locator('table.table-bordered tbody tr').filter({ hasText: NOMBRE_CLIENTE });
}

/** El identificador de la factura que tiene un borrador abierto, leído de la tabla de borradores. */
async function idDeLaFacturaConBorrador(page) {
  const fila = page
    .locator('table.table-striped tbody tr')
    .filter({ hasText: NOMBRE_CLIENTE })
    .first();

  return (await fila.locator('td').nth(1).innerText()).trim();
}

test.describe('Factura — estados de entrada de la pantalla', () => {
  test('T1 sin parámetros: factura nueva, sin fila de entrada ni botones, con el cliente por elegir', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/factura.php');

    await expect(page.locator('#estado')).toHaveValue('Nuevo');
    await expect(page.locator('#Row0')).toBeHidden();
    await expect(page.locator('#Guardar')).toBeHidden();
    await expect(page.locator('#Cancelar')).toBeHidden();
    await expect(page.locator('#id_cliente')).toBeEditable();
  });

  test('T2 abierta desde el enlace de ver: todo en solo lectura', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/facturasListado.php');
    const idConBorrador = await idDeLaFacturaConBorrador(page);

    const fila = filasDeFactura(page)
      .filter({ has: page.locator(`a[href*="id=${idConBorrador}&"]`) })
      .first();
    await expect(fila).toBeVisible();

    await filasDeFactura(page)
      .filter({ hasNot: page.locator(`a[href*="id=${idConBorrador}&"]`) })
      .first()
      .locator('a[title="Ver factura"]')
      .click();

    await expect(page.locator('h2.text-center')).toContainText('ver');
    await expect(page.locator('#estado')).toHaveValue('Guardado');
    await expect(page.locator('#Row0')).toBeHidden();
    await expect(page.locator('#Guardar')).toBeHidden();
    await expect(page.locator('#id_cliente')).not.toBeEditable();
  });

  /**
   * Entrar a editar una factura guardada abre la fila de entrada de productos, pero no los
   * botones de Guardar y Cancelar: `factura.php` los oculta mientras no exista temporal, y
   * el temporal no nace hasta que se añade la primera línea o el primer albarán.
   */
  test('T3 abierta para editar: se puede escribir, pero no hay todavía con qué guardar', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/facturasListado.php');
    const idConBorrador = await idDeLaFacturaConBorrador(page);

    await filasDeFactura(page)
      .filter({ hasNot: page.locator(`a[href*="id=${idConBorrador}&"]`) })
      .first()
      .locator('a[title="Editar factura"]')
      .click();

    await expect(page.locator('h2.text-center')).toContainText('editar');
    await expect(page.locator('#Row0')).toBeVisible();
    await expect(page.locator('#Guardar')).toBeHidden();
    await expect(page.locator('#Cancelar')).toBeHidden();
    await expect(page.locator('#id_cliente')).not.toBeEditable();
  });

  /**
   * Una factura con un borrador abierto no se edita desde aquí: la pantalla lo detecta,
   * degrada la acción a 'ver' y ofrece el enlace al borrador. Es el mismo control que el
   * pedido tiene para el documento ya servido, aplicado a otra condición.
   */
  test('T4 una factura con borrador abierto se abre en solo lectura y enlaza al borrador', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/facturasListado.php');
    const idConBorrador = await idDeLaFacturaConBorrador(page);

    await page.goto(`modulos/mod_venta/factura.php?id=${idConBorrador}&accion=editar`);

    await expect(page.locator('body')).toContainText('Existe un temporal');
    await expect(page.locator('h2.text-center')).toContainText('ver');
    await expect(page.locator('#Row0')).toBeHidden();
    await expect(page.locator('a[href*="tActual="]')).toBeVisible();
  });

  /**
   * La misma factura se nombra de dos maneras según la pantalla: el listado la titula con
   * su número de serie y la vista del documento con el identificador de su fila. Mientras
   * ambos coincidan nadie lo nota; no coinciden.
   */
  test('T5 el listado y la vista de la factura la nombran con dos números distintos', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/facturasListado.php');
    const idConBorrador = await idDeLaFacturaConBorrador(page);

    const fila = filasDeFactura(page)
      .filter({ hasNot: page.locator(`a[href*="id=${idConBorrador}&"]`) })
      .first();
    const numeroEnElListado = (await fila.locator('td').nth(3).innerText()).trim();
    const enlace = await fila.locator('a[title="Ver factura"]').getAttribute('href');
    const idEnLaUrl = new URL(enlace, 'http://x/').searchParams.get('id');

    await page.goto(`modulos/mod_venta/factura.php?id=${idEnLaUrl}&accion=ver`);

    const titulo = await page.locator('h2.text-center').innerText();
    expect(titulo).toContain(idEnLaUrl);
    expect(idEnLaUrl).not.toBe(numeroEnElListado);
    expect(titulo).not.toContain(`Cliente ${numeroEnElListado}-`);
  });
});
