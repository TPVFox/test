/**
 * Los estados en que un pedido puede quedar y que el sistema no debería permitir, recorridos
 * desde la pantalla como los recorre el operador.
 *
 * El primero es el de más peso del componente: al incorporar un pedido a un albarán, la
 * petición que marca el pedido como servido lleva su **número**, y el servidor la aplica
 * **por identificador**. Mientras los dos campos coinciden acierta por casualidad; la
 * siembra deja un pedido en el que no coinciden, y este recorrido comprueba qué documento
 * queda marcado de verdad.
 *
 * Los otros dos parten de discrepancias que la base admite —procesado sin albarán, y
 * guardado con albarán— y comprueban qué hace la pantalla cuando se las encuentra.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');
const { seleccionarCliente } = require('../../fixtures/seleccionarCliente');

const ID_CLIENTE = 936; // '[E2E venta] Cliente pedido estados'
const NOMBRE_CLIENTE = '[E2E venta] Cliente pedido estados';

/**
 * Lee del listado los pedidos de este cliente: identificador —del enlace de editar—,
 * número visible y estado. Se deriva de la pantalla en vez de fijarlo en el spec porque los
 * identificadores son autoincrementales y cambian al rehacer la base.
 */
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
      numero: Number(celdas[3].trim()),
      estado: celdas[9].trim().replace(/\s+/g, ' '),
    });
  }

  return leidos;
}

test.describe('Pedido — estados discrepantes', () => {
  /**
   * El recorrido completo del defecto de mayor impacto del componente. La siembra deja un
   * pedido cuyo número coincide con el identificador de otro; al adjuntar el primero por su
   * número, el segundo es el que queda marcado como servido.
   */
  test('T1 servir un pedido marca el que tiene ese número como identificador, no el servido', {
    tag: ['@estado-actual', '@defecto', '@pedido', '@albaran', '@estados', '@numeracion', '@critico'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'Al incorporar un pedido a un albarán queda marcado como servido otro pedido —el que tiene por identificador el número del incorporado—, y el que de verdad se sirvió sigue disponible.' },
      { type: 'Qué debería ocurrir', description: 'Que quede marcado como servido el pedido que se incorporó, y solo ese.' },
      { type: 'Por qué ocurre', description: 'El navegador pide el cambio de estado con el número del pedido y el servidor lo aplica buscando por identificador. Mientras número e identificador coinciden, acierta por casualidad.' },
      { type: 'Cómo debería funcionar', description: 'Que el cambio de estado viaje y se aplique con el identificador del documento incorporado.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/pedidosListado.php');

    const antes = await pedidosDelCliente(page);
    const servido = antes.find((p) => antes.some((otro) => otro.id === p.numero && otro.id !== p.id));
    expect(servido, 'La siembra debe dejar un pedido cuyo número sea el identificador de otro').toBeTruthy();
    const ajeno = antes.find((p) => p.id === servido.numero);

    expect(servido.estado).toContain('Guardado');
    expect(ajeno.estado).toContain('Guardado');

    // Se adjunta al albarán tecleando el número del pedido, que es como lo hace el operador.
    await page.goto('modulos/mod_venta/albaran.php');
    await seleccionarCliente(page, ID_CLIENTE);
    await expect(page.locator('#numAdjunto')).toBeVisible({ timeout: 10000 });

    await page.fill('#numAdjunto', String(servido.numero));
    await Promise.all([
      page.waitForResponse(
        (r) => r.url().includes('tareas.php') && (r.request().postData() || '').includes('modificarEstadoDocumento')
      ),
      page.locator('#numAdjunto').press('Enter'),
    ]);

    await page.goto('modulos/mod_venta/pedidosListado.php');
    const despues = await pedidosDelCliente(page);

    const servidoAhora = despues.find((p) => p.id === servido.id);
    const ajenoAhora = despues.find((p) => p.id === ajeno.id);

    expect(ajenoAhora.estado, 'El pedido ajeno es el que queda marcado como servido').toContain('Procesado');
    expect(servidoAhora.estado, 'Y el que realmente se sirvió sigue disponible').toContain('Guardado');
  });

  // El estado que T1 deja no se puede deshacer desde la pantalla: no hay acción que
  // devuelva un pedido de 'Procesado' a 'Guardado' sin pasar por el albarán temporal que lo
  // adjuntó. Lo restituye `support/sembrar-e2e-venta.php` antes de cada ejecución, que es
  // donde vive la preparación del escenario.

  /**
   * Un pedido `Procesado` sin ninguna relación con un albarán: la pantalla lo detecta y lo
   * dice, pero el pedido queda inutilizable — no se puede editar ni volver a ofrecer.
   */
  test('T2 un pedido procesado sin albarán avisa y queda en solo lectura', {
    tag: ['@estado-actual', '@pedido', '@estados'],
    annotation: [
      { type: 'Comportamiento', description: 'Un pedido «Procesado» sin ningún albarán relacionado se abre en solo lectura y la pantalla avisa de que no existe la relación. El pedido queda sin poder editarse ni volver a servirse.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/pedidosListado.php');

    const pedidos = await pedidosDelCliente(page);
    const huerfano = pedidos.find((p) => p.estado.includes('Procesado'));
    expect(huerfano, 'La siembra deja un pedido procesado sin relación').toBeTruthy();

    await page.goto(`modulos/mod_venta/pedido.php?id=${huerfano.id}&accion=editar`);

    await expect(page.locator('body')).toContainText('no existe relacion de ninguna albaran');
    await expect(page.locator('#Row0')).toBeHidden();
    await expect(page.locator('#Guardar')).toBeHidden();
  });

  /**
   * La discrepancia en el sentido contrario —un pedido enlazado a un albarán que sigue en
   * `Guardado`— también avisa y deja el pedido en solo lectura.
   *
   * Es el estado que T1 acaba de producir: el pedido que realmente se sirvió se queda
   * `Guardado` y con su relación escrita. La pantalla lo abre, dice que su estado no concuerda
   * con tener albarán y no deja editarlo. Antes no llegaba a avisar: componía el aviso con un
   * objeto que la pantalla no tiene, respondía con error de servidor y el pedido no se podía
   * abrir.
   */
  test('T3 un pedido guardado con albarán avisa y queda en solo lectura', {
    tag: ['@estado-actual', '@pedido', '@estados'],
    annotation: [
      { type: 'Comportamiento', description: 'Un pedido «Guardado» que ya tiene albarán se abre, avisa de que su estado debería ser «Procesado» y queda en solo lectura.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/pedidosListado.php');

    const pedidos = await pedidosDelCliente(page);
    const guardados = pedidos.filter((p) => p.estado.includes('Guardado'));
    const conAviso = [];

    for (const pedido of guardados) {
      const respuesta = await page.goto(`modulos/mod_venta/pedido.php?id=${pedido.id}&accion=editar`);
      expect(respuesta.status(), `El pedido ${pedido.id} se abre`).toBe(200);
      if ((await page.locator('body').innerText()).includes('cuando debería ser PROCESADO')) {
        conAviso.push(pedido.id);
      }
    }

    expect(conAviso.length, 'Alguno de los pedidos guardados tiene albarán, y su pantalla lo dice').toBeGreaterThan(0);

    await page.goto(`modulos/mod_venta/pedido.php?id=${conAviso[0]}&accion=editar`);
    await expect(page.locator('body')).toContainText('cuando debería ser PROCESADO');
    await expect(page.locator('#Row0')).toBeHidden();
    await expect(page.locator('#Guardar')).toBeHidden();
  });
});
