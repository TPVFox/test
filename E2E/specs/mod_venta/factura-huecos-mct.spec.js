/**
 * Combinaciones que el resto de recorridos de factura no cubría: una factura con dos
 * borradores abiertos, crear una factura desde un albarán de un cliente sin forma de
 * vencimiento, buscar una factura por su número e imprimirla desde el listado.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');

const CLIENTE_DUPLICIDAD = '[E2E venta] Cliente factura duplicidad';
const CLIENTE_SIN_VENCI = '[E2E venta] Cliente factura sin vencimiento';
const CLIENTE_RELACION = '[E2E venta] Cliente factura relacion';

test.describe('Factura — combinaciones que el resto de recorridos no cubría', () => {
  /**
   * La pantalla distingue entre «existe un temporal» y «existen varios»,
   * y emite un aviso distinto para cada caso. El segundo es además el estado que
   * `comprobarTemporalesIdFac()` —el método sin llamador— estaba escrito para detectar.
   */
  test('T1 una factura con dos borradores abiertos avisa de que existen varios', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/facturasListado.php');

    const filaBorrador = page
      .locator('table.table-striped tbody tr')
      .filter({ hasText: CLIENTE_DUPLICIDAD })
      .first();
    await expect(filaBorrador).toBeVisible();
    const idFactura = (await filaBorrador.locator('td').nth(1).innerText()).trim();

    await page.goto(`modulos/mod_venta/factura.php?id=${idFactura}&accion=editar`);

    await expect(page.locator('body')).toContainText('Existen varios temporales de esta factura');
    await expect(page.locator('h2.text-center')).toContainText('ver');
  });

  /**
   * Las dos condiciones que este componente tiene y ningún otro,
   * combinadas: entrar desde el listado de albaranes, sobre un cliente sin forma de
   * vencimiento. La pantalla no llega a montarse, de modo que la acción no lleva a ningún
   * sitio y el operador no recibe explicación.
   */
  test('T2 crear factura desde albarán de un cliente sin vencimiento no llega a la pantalla', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/albaranesListado.php');

    const fila = page
      .locator('table.table-bordered tbody tr')
      .filter({ hasText: CLIENTE_SIN_VENCI })
      .filter({ hasText: 'Guardado' })
      .first();
    await expect(fila).toBeVisible();

    const [respuesta] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('factura.php') && r.request().method() === 'POST'),
      fila.locator('a[title="Crear factura desde albarán"]').click(),
    ]);

    // La acción no lleva a ninguna pantalla: la carga muere al recomponer el vencimiento
    // del cliente, igual que al abrir una factura suya ya emitida. La factura nueva por sí
    // sola sí se abre —lleva un vencimiento por defecto escrito—, de modo que lo que la
    // rompe es haber puesto el cliente.
    expect(respuesta.status()).toBe(500);
    await expect(page.locator('#id_cliente')).toHaveCount(0);
  });

  /**
   * La búsqueda tiene dos campos y solo se había ejercido el del nombre
   * del cliente. El número es el campo por el que el operador busca cuando el cliente
   * reclama un documento fiscal.
   */
  test('T3 buscar por el número de la factura la encuentra', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/facturasListado.php');

    const fila = page
      .locator('table.table-bordered tbody tr')
      .filter({ hasText: CLIENTE_RELACION })
      .first();
    await expect(fila).toBeVisible();
    const numero = (await fila.locator('td').nth(3).innerText()).trim();

    await page.fill('form[name="formBuscar"] input[name="buscar"]', numero);
    await Promise.all([
      page.waitForURL(/buscar=/),
      page.locator('form[name="formBuscar"] input[type="submit"]').click(),
    ]);

    const filas = page.locator('table.table-bordered tbody tr');
    await expect(filas.filter({ hasText: CLIENTE_RELACION })).toHaveCount(1);
  });

  /**
   * Imprimir es la acción que las cuatro pantallas de venta tienen y que
   * solo el pedido llegó a ejercer. Aquí importa más: el imprimible de la factura es el único
   * que compone bloques de líneas bajo cabeceras de otro documento.
   */
  test('T4 el icono de imprimir de una factura abre un PDF real', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/facturasListado.php');

    const icono = page
      .locator('table.table-bordered tbody tr')
      .filter({ hasText: CLIENTE_RELACION })
      .first()
      .locator('a.glyphicon-print');
    await expect(icono).toBeVisible({ timeout: 10000 });

    const [respuestaAjax, popup] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('tareas.php') && (r.request().postData() || '').includes('datosImprimir')),
      page.context().waitForEvent('page'),
      icono.click(),
    ]);

    const rutaPdf = JSON.parse(await respuestaAjax.text());
    expect(rutaPdf).toMatch(/\.pdf$/);

    const descarga = await page.request.get(new URL(rutaPdf, page.url()).toString());
    expect(descarga.ok()).toBeTruthy();
    expect((await descarga.body()).subarray(0, 4).toString('latin1')).toBe('%PDF');

    await popup.close();
  });
});
