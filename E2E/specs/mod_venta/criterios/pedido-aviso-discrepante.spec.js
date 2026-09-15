/**
 * Criterio de aceptación: un pedido con albarán y estado «Guardado» debe avisar, no reventar.
 *
 * Síntoma: abrir ese pedido responde 500 con el cuerpo vacío. El documento deja de ser
 * alcanzable por completo —ni para ver ni para editar—, y es un estado que el propio sistema
 * produce al servir un pedido.
 *
 * Causa raíz: la rama que compone ese aviso invoca `montarAdvertencia()` sobre una variable
 * que `pedido.php` no define en ningún sitio: aparece una sola vez en todo el fichero, en esa
 * llamada, y nunca se le asigna valor. La rama contraria —procesado sin albarán— sí usa el
 * objeto correcto y funciona, de modo que el defecto es de una sola palabra.
 *
 * Corrección esperada: componer el aviso con el objeto del pedido, como hace la rama gemela.
 *
 * Declarado con `test.fail()`: hoy falla, y el día que el cambio se aplique Playwright avisará
 * de que pasa cuando no debería. El «antes» queda documentado, sin tocar, en
 * `pedido-estados-discrepantes.spec.js` T3.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../../fixtures/autenticacion');

const NOMBRE_CLIENTE = '[E2E venta] Cliente pedido estados';

/** Identificador y estado de cada pedido de este cliente, leídos del listado. */
async function pedidosDelCliente(page) {
  const filas = page.locator('table.table-bordered tbody tr').filter({ hasText: NOMBRE_CLIENTE });
  const total = await filas.count();
  const leidos = [];

  for (let i = 0; i < total; i++) {
    const fila = filas.nth(i);
    const enlace = await fila.locator('a[title="Editar pedido"]').getAttribute('href');
    const celdas = await fila.locator('td').allInnerTexts();
    leidos.push({
      id: Number(new URL(enlace, 'http://x/').searchParams.get('id')),
      estado: celdas[9].trim().replace(/\s+/g, ' '),
    });
  }

  return leidos;
}

test.describe('Pedido — aviso de estado discrepante', { tag: '@criterio' }, () => {
  test.fail('T1 un pedido guardado con albarán avisa de la discrepancia en vez de reventar', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/pedidosListado.php');

    const guardados = (await pedidosDelCliente(page)).filter((p) => p.estado.includes('Guardado'));
    expect(guardados.length, 'La siembra deja pedidos guardados de este cliente').toBeGreaterThan(0);

    // Ninguno de los pedidos guardados debe responder con error de servidor: el que tenga
    // albarán tiene que llegar a pintar su aviso.
    const respuestas = [];
    for (const pedido of guardados) {
      const respuesta = await page.goto(`modulos/mod_venta/pedido.php?id=${pedido.id}&accion=editar`);
      respuestas.push({ id: pedido.id, estado: respuesta.status() });
    }

    const rotas = respuestas.filter((r) => r.estado >= 500);
    expect(rotas, `Ningún pedido guardado debería reventar: ${JSON.stringify(respuestas)}`).toHaveLength(0);
  });

  test.fail('T2 la pantalla de ese pedido explica por qué su estado no concuerda', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/pedidosListado.php');

    const guardados = (await pedidosDelCliente(page)).filter((p) => p.estado.includes('Guardado'));

    // Se busca el que tiene albarán —el que hoy revienta— y se comprueba que su pantalla
    // llega a montarse con el aviso que la rama pretende dar.
    let avisos = 0;
    for (const pedido of guardados) {
      await page.goto(`modulos/mod_venta/pedido.php?id=${pedido.id}&accion=editar`);
      if (await page.locator('body').getByText('debería ser PROCESADO').count()) {
        avisos++;
      }
    }

    expect(avisos, 'El pedido guardado con albarán explica su discrepancia').toBeGreaterThan(0);
  });
});
