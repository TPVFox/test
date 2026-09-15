/**
 * Criterio de aceptación: un documento que tiene una versión en curso lo advierte siempre.
 *
 * Síntoma: se abre un pedido en modo ver y la pantalla no dice nada, aunque ese pedido tenga
 * un documento en curso abierto con contenido distinto. El operador consulta un documento
 * creyendo que es lo vigente, y lo vigente está en otro sitio.
 *
 * Causa raíz: la comprobación de documentos en curso solo corre en la rama de edición. La rama
 * de consulta no la ejecuta, de modo que la misma pantalla detecta el borrador o lo ignora
 * según con qué acción se haya entrado.
 *
 * Corrección esperada: que la comprobación sea del documento, no de la acción con que se abre.
 * Ver no tiene por qué impedir nada, pero sí decirlo.
 *
 * Alcance de este recorrido, y lo que deja fuera a propósito. El cambio al que sirve agrupa
 * además que un documento en curso sobreviva al documento que lo originó. Esa parte **no es
 * alcanzable desde el navegador**: ninguno de los tres listados tiene acción de borrar, y el
 * borrado de tablas solo se invoca dentro del propio guardado, que reescribe el documento en
 * el sitio. Queda cubierta por las pruebas de integración, y así consta en su ficha.
 *
 * Declarado con `test.fail()`. El «antes» queda documentado, sin tocar, en
 * `pedido-flujo-entradas.spec.js` T6, que afirma la ausencia del aviso tal como es hoy.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../../fixtures/autenticacion');

const NOMBRE_CLIENTE = '[E2E venta] CC borrador huerfano';
const ID_ARTICULO = 14678;

test.describe('Documento con versión en curso — la consulta también lo advierte', { tag: '@criterio' }, () => {
  test.fail('T1 ver un pedido que tiene un documento en curso lo señala', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/pedido.php');

    // Se compone y guarda un pedido propio, para no depender de los que sirven a otros
    // recorridos ni dejarles un borrador encima.
    await page.locator('#id_cliente').fill('959');
    await Promise.all([
      page.waitForResponse((r) => r.url().includes('tareas.php')),
      page.locator('#id_cliente').press('Enter'),
    ]);
    await expect(page.locator('#idArticulo')).toBeVisible({ timeout: 10000 });

    await page.fill('#idArticulo', String(ID_ARTICULO));
    await Promise.all([
      page.waitForResponse(
        (r) => r.url().includes('tareas.php') && (r.request().postData() || '').includes('anhadirTemporal')
      ),
      page.locator('#idArticulo').press('Enter'),
    ]);
    await expect(page.locator('#Guardar')).toBeVisible({ timeout: 10000 });
    await Promise.all([
      page.waitForURL(/pedidosListado\.php/, { timeout: 15000 }),
      page.locator('#Guardar').click(),
    ]);

    // Ya en el listado, se localiza el pedido recién guardado.
    const fila = page.locator('table.table-bordered tbody tr').filter({ hasText: NOMBRE_CLIENTE }).first();
    await expect(fila).toBeVisible({ timeout: 10000 });
    const enlace = await fila.locator('a[title="Editar pedido"]').getAttribute('href');
    const idPedido = new URL(enlace, 'http://x/').searchParams.get('id');

    // Se le abre una versión en curso, que es lo que debería advertirse después.
    await page.goto(`modulos/mod_venta/pedido.php?id=${idPedido}&accion=editar`);
    await expect(page.locator('#idArticulo')).toBeVisible({ timeout: 10000 });
    await page.fill('#idArticulo', String(ID_ARTICULO));
    await Promise.all([
      page.waitForResponse(
        (r) => r.url().includes('tareas.php') && (r.request().postData() || '').includes('anhadirTemporal')
      ),
      page.locator('#idArticulo').press('Enter'),
    ]);
    await expect(page).toHaveURL(/tActual=\d+/);

    // Y se consulta el pedido: la pantalla tiene que decir que hay algo en curso.
    await page.goto(`modulos/mod_venta/pedido.php?id=${idPedido}&estado=ver`);
    await expect(page.locator('h2.text-center')).toContainText('ver');
    await expect(page.locator('body')).toContainText('temporal', { timeout: 3000 });
  });
});
