/**
 * Comportamiento esperado: el servidor no escribe lo que le mandan sin contrastarlo.
 *
 * Síntoma: el precio de las líneas y el importe de la cabecera se escriben tal como llegan del
 * navegador, cada uno por su lado. Un documento puede quedar guardado con una cabecera que dice
 * un importe y unas líneas que suman otro, y en el listado es indistinguible de uno correcto.
 * En la factura, con efecto fiscal.
 *
 * Causa raíz: el guardado escribe las líneas y, por separado, el total que recibe. No hay ningún
 * punto en que se comparen: el servidor confía en el cálculo del cliente.
 *
 * Corrección esperada: que el importe y el desglose salgan de las líneas que el propio guardado
 * acaba de escribir, no del cuerpo de la petición.
 *
 * Por qué este recorrido interviene la petición. El defecto no se puede provocar tecleando: la
 * pantalla calcula bien. Lo que se comprueba es que el servidor no se fíe de lo que le llega, y
 * para eso hay que hacer que le llegue algo que no cuadre. Playwright reescribe el precio de la
 * línea en el POST que crea el documento en curso —es la única intervención— y el resto es
 * navegación normal. Es el único recorrido de este grupo que interviene la petición.
 *
 * Medido al escribirlo: la línea queda guardada a 0,01 y la cabecera a 1,82. Las dos vistas del
 * documento enseñan cosas distintas: el listado pinta el total guardado en la cabecera, y la
 * pantalla del albarán recalcula el total sumando sus líneas al pintarse, de modo que dentro
 * del documento siempre cuadra. La discrepancia solo es visible comparando listado y documento.
 *
 * **Se conserva en rojo**, con su propia aserción por motivo. No hay «antes» en la suite E2E: el defecto solo estaba cubierto
 * por pruebas de integración.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../../fixtures/autenticacion');
const { seleccionarCliente } = require('../../../fixtures/seleccionarCliente');

const ID_CLIENTE = 955; // '[E2E venta] Esperado inyeccion en busqueda', sin documentos propios
const NOMBRE_CLIENTE = '[E2E venta] Esperado inyeccion en busqueda';
const ID_ARTICULO = 14678; // '[E2E venta] Manzana Golden'
const PRECIO_FALSEADO = 0.01;

/** Convierte un importe pintado en pantalla a número, admita coma o punto decimal. */
function importe(texto) {
  return parseFloat(String(texto).trim().replace(',', '.'));
}

/**
 * Reescribe el precio de las líneas en el cuerpo del POST que crea el documento en curso.
 * Actúa una sola vez, para que el guardado posterior viaje limpio.
 */
async function falsearElPrecioUnaVez(page) {
  let yaActuo = false;

  await page.route('**/tareas.php', async (ruta) => {
    const peticion = ruta.request();
    const cuerpo = peticion.method() === 'POST' ? peticion.postData() : null;

    if (yaActuo || !cuerpo || !cuerpo.includes('anhadirTemporal')) {
      return ruta.continue();
    }

    const datos = new URLSearchParams(cuerpo);
    const productos = JSON.parse(datos.get('productos') || '[]');
    if (productos.length === 0) {
      return ruta.continue();
    }

    for (const producto of productos) {
      producto.precioCiva = PRECIO_FALSEADO;
      producto.pvpSiva = PRECIO_FALSEADO;
    }
    datos.set('productos', JSON.stringify(productos));
    yaActuo = true;

    return ruta.continue({ postData: datos.toString() });
  });
}

test.describe('Venta — el servidor contrasta el importe con sus líneas', () => {
  test('T1 la cabecera guardada suma lo mismo que sus líneas', {
    tag: ['@esperado', '@albaran', '@importes', '@guardado', '@forzado', '@alto'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'Si el precio de la línea llega manipulado en la petición, el albarán se guarda con la línea a 0,01 y la cabecera a 1,82: el listado y el propio documento muestran importes distintos.' },
      { type: 'Qué debería ocurrir', description: 'Que el importe guardado salga de las líneas que el propio guardado escribe.' },
      { type: 'Por qué ocurre', description: 'El servidor escribe las líneas y, por separado, el total que recibe del navegador, sin compararlos.' },
      { type: 'Cómo debería funcionar', description: 'Calcular el importe y el desglose en el servidor a partir de las líneas guardadas.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/albaran.php');
    await seleccionarCliente(page, ID_CLIENTE);
    await expect(page.locator('#idArticulo')).toBeVisible({ timeout: 10000 });

    await falsearElPrecioUnaVez(page);

    await page.fill('#idArticulo', String(ID_ARTICULO));
    await Promise.all([
      page.waitForResponse(
        (r) => r.url().includes('tareas.php') && (r.request().postData() || '').includes('anhadirTemporal')
      ),
      page.locator('#idArticulo').press('Enter'),
    ]);

    await expect(page.locator('#Guardar')).toBeVisible({ timeout: 10000 });
    await page.unroute('**/tareas.php');

    await Promise.all([
      page.waitForURL(/albaranesListado\.php/, { timeout: 15000 }),
      page.locator('#Guardar').click(),
    ]);

    const fila = page.locator('table.table-bordered tbody tr').filter({ hasText: NOMBRE_CLIENTE }).first();
    await expect(fila).toBeVisible({ timeout: 10000 });
    const enlace = await fila.locator('a[title="Ver albarán"]').getAttribute('href');
    const idAlbaran = new URL(enlace, 'http://x/').searchParams.get('id');

    // El listado pinta el total guardado en la cabecera: tres celdas de acciones, número,
    // fecha, cliente, base, IVA y total.
    const celdas = await fila.locator('td').allInnerTexts();
    const totalGuardado = importe(celdas[8]);

    // La pantalla del documento, en cambio, no lee ese total: lo recalcula a partir de las
    // líneas al pintarse. Por eso dentro del documento línea y total cuadran siempre, y la
    // discrepancia solo aparece al comparar las dos vistas del mismo albarán.
    await page.goto(`modulos/mod_venta/albaran.php?id=${idAlbaran}&accion=ver`);
    await page.waitForLoadState('networkidle');
    const totalDesdeLasLineas = importe(await page.locator('.totalImporte').first().innerText());

    expect(
      Math.abs(totalGuardado - totalDesdeLasLineas),
      `El listado dice ${totalGuardado} y el propio albarán, sumando sus líneas, ${totalDesdeLasLineas}`
    ).toBeLessThan(0.005);
  });
});
