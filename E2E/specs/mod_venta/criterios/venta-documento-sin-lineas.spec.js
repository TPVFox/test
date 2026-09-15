/**
 * Criterio de aceptación: un documento sin ninguna línea activa no se emite.
 *
 * Síntoma: se compone un documento, se retiran todas sus líneas y se pulsa Guardar. El
 * documento se emite igual: queda una cabecera con su número —en la factura, con su número
 * fiscal— y sin nada que justifique su importe. En el listado es indistinguible de uno real.
 *
 * Causa raíz: el guardado escribe la cabecera primero y las líneas después, y no comprueba en
 * ningún punto que haya quedado alguna. Ninguna de las tres pantallas lo impide ni lo advierte.
 *
 * Corrección esperada: impedirlo o advertirlo. **Exige decisión de negocio previa**, porque
 * caben las dos: rechazar el guardado, o admitirlo con aviso explícito. Este recorrido afirma
 * lo mínimo común a las dos salidas — que no se emita en silencio — sin fijar cuál se elige.
 *
 * Declarado con `test.fail()`. No hay «antes» en la suite E2E: el defecto solo estaba cubierto
 * por pruebas de integración, que lo alcanzan por la clase y no desde la pantalla.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../../fixtures/autenticacion');
const { seleccionarCliente } = require('../../../fixtures/seleccionarCliente');

const ID_ARTICULO = 14678;

// La pantalla va escrita, no derivada del listado: los tres nombres no siguen la misma regla
// («albaranesListado.php» no da «albaran.php» quitando el plural).
const ESCENARIOS = [
  {
    documento: 'pedido',
    idCliente: 956,
    nombre: '[E2E venta] CC sin lineas pedido',
    pantalla: 'pedido.php',
    listado: 'pedidosListado.php',
  },
  {
    documento: 'albarán',
    idCliente: 957,
    nombre: '[E2E venta] CC sin lineas albaran',
    pantalla: 'albaran.php',
    listado: 'albaranesListado.php',
  },
  {
    documento: 'factura',
    idCliente: 958,
    nombre: '[E2E venta] CC sin lineas factura',
    pantalla: 'factura.php',
    listado: 'facturasListado.php',
  },
];

/** Cuántas filas del listado corresponden a este cliente. */
async function documentosDelCliente(page, listado, nombre) {
  await page.goto(`modulos/mod_venta/${listado}`);
  await page.waitForLoadState('networkidle');

  return page.locator('table.table-bordered tbody tr').filter({ hasText: nombre }).count();
}

for (const escenario of ESCENARIOS) {
  test.describe(`Venta — ${escenario.documento} sin ninguna línea`, { tag: '@criterio' }, () => {
    test.fail(`T1 retirar la única línea y guardar no emite el ${escenario.documento}`, async ({ page }) => {
      const antes = await documentosDelCliente(page, escenario.listado, escenario.nombre);

      await iniciarSesion(page, `modulos/mod_venta/${escenario.pantalla}`);
      await seleccionarCliente(page, escenario.idCliente);
      await expect(page.locator('#idArticulo')).toBeVisible({ timeout: 10000 });

      // Añadir la línea crea el documento en curso y hace aparecer los botones.
      await page.fill('#idArticulo', String(ID_ARTICULO));
      await Promise.all([
        page.waitForResponse(
          (r) => r.url().includes('tareas.php') && (r.request().postData() || '').includes('anhadirTemporal')
        ),
        page.locator('#idArticulo').press('Enter'),
      ]);

      const fila = page.locator('#tabla tr[id^="Row"]:not(#Row0)').first();
      await expect(fila).toBeVisible({ timeout: 10000 });

      // Se retira: la fila queda tachada y deja de contar como línea activa.
      await fila.locator('.eliminar a').click();
      await expect(fila).toHaveClass(/tachado/);

      await expect(page.locator('#Guardar')).toBeVisible();
      await page.locator('#Guardar').click();
      await page.waitForLoadState('networkidle');

      // El documento no debe haberse emitido: el listado del cliente sigue igual.
      const despues = await documentosDelCliente(page, escenario.listado, escenario.nombre);
      expect(despues, `Un ${escenario.documento} sin líneas no debería llegar al listado`).toBe(antes);
    });
  });
}
