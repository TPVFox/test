/**
 * Comportamiento esperado: la factura de un cliente sin forma de vencimiento debe abrirse.
 *
 * Síntoma: la pantalla responde 500 para cualquier cliente cuya ficha no tenga forma de
 * vencimiento. El documento fiscal existe, está en el listado, y es inalcanzable desde el
 * sistema que lo emitió. La columna admite nulo y la mayoría de los clientes de una base real
 * la tienen vacía, así que no es un caso de borde.
 *
 * Causa raíz: la pantalla decodifica la forma de vencimiento de la ficha del cliente y lee una
 * propiedad del resultado sin comprobar que haya resultado. Con la ficha vacía, la decodificación
 * no devuelve objeto y la lectura de la propiedad es un fatal.
 *
 * Corrección esperada: aplicar el valor por defecto que la propia pantalla ya tiene escrito diez
 * líneas más arriba para el caso de factura nueva. Es la Crítica más barata del componente.
 *
 * **Se conserva en rojo**, con su propia aserción por motivo. El «antes» queda documentado, sin tocar, en
 * `factura-defecto-abrir-sin-vencimiento.spec.js` y en `factura-combinaciones-no-cubiertas.spec.js` T2.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../../fixtures/autenticacion');

const NOMBRE_CLIENTE = '[E2E venta] Cliente factura sin vencimiento';

/**
 * El identificador de la factura de este cliente, leído del listado. Se toma del enlace y se
 * arma la URL con la acción explícita: el `href` sin acción resuelve por otro camino que no
 * llega a montar la pantalla del documento.
 */
async function idDeSuFactura(page) {
  const fila = page.locator('table.table-bordered tbody tr').filter({ hasText: NOMBRE_CLIENTE }).first();
  await expect(fila).toBeVisible();

  const enlace = await fila.locator('a[title="Ver factura"]').getAttribute('href');

  return new URL(enlace, 'http://x/').searchParams.get('id');
}

test.describe('Factura — cliente sin forma de vencimiento', () => {
  test('T1 su factura se abre desde el listado en vez de responder 500', {
    tag: ['@esperado', '@factura', '@entrada', '@vencimiento', '@directo', '@critico'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'La factura de un cliente sin forma de vencimiento responde con error de servidor al abrirla.' },
      { type: 'Qué debería ocurrir', description: 'Que la factura se abra.' },
      { type: 'Por qué ocurre', description: 'La pantalla lee la forma de vencimiento de la ficha del cliente sin comprobar que exista, y la columna admite nulo.' },
      { type: 'Cómo debería funcionar', description: 'Aplicar el vencimiento por defecto que la propia pantalla ya usa para una factura nueva.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/facturasListado.php');

    const idFactura = await idDeSuFactura(page);
    const respuesta = await page.goto(`modulos/mod_venta/factura.php?id=${idFactura}&accion=ver`);

    expect(respuesta.status()).toBe(200);
    await expect(page.locator('#estado')).toBeVisible({ timeout: 3000 });
  });

  test('T2 esa factura muestra una fecha de vencimiento, la que sea por defecto', {
    tag: ['@esperado', '@factura', '@entrada', '@vencimiento', '@directo', '@critico'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'La pantalla no llega a montarse, así que no muestra ninguna fecha de vencimiento.' },
      { type: 'Qué debería ocurrir', description: 'Que muestre una fecha de vencimiento, la que resulte del valor por defecto.' },
      { type: 'Por qué ocurre', description: 'La pantalla lee la forma de vencimiento de la ficha del cliente sin comprobar que exista, y la columna admite nulo.' },
      { type: 'Cómo debería funcionar', description: 'Aplicar el vencimiento por defecto que la propia pantalla ya usa para una factura nueva.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/facturasListado.php');

    const idFactura = await idDeSuFactura(page);
    await page.goto(`modulos/mod_venta/factura.php?id=${idFactura}&accion=ver`);

    // No se fija cuál debe ser el vencimiento —eso lo decide la corrección—, solo que la
    // pantalla resuelve alguno en vez de morir por no tener ninguno.
    // Por nombre y no por identificador: la pantalla repite `id="fechaVencimiento"` en la caja
    // y en el bloque que la contiene. Mientras la pantalla respondía 500 este paso nunca llegó
    // a ejecutarse, y el selector ambiguo no se veía.
    await expect(page.locator('input[name="fechaVencimiento"]')).not.toHaveValue('', { timeout: 3000 });
  });
});
