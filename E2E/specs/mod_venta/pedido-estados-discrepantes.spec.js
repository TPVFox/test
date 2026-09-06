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
  test('T1 servir un pedido marca el que tiene ese número como identificador, no el servido', async ({ page }) => {
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
  test('T2 un pedido procesado sin albarán avisa y queda en solo lectura', async ({ page }) => {
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
   * `Guardado`— **no llega a avisar: revienta la pantalla**.
   *
   * `pedido.php` compone ese aviso invocando un método sobre una variable que en ese
   * fichero no se define en ningún sitio. La rama nunca se había ejecutado porque hace
   * falta llegar a la discrepancia para entrar en ella; al hacerlo, el servidor responde
   * 500 con el cuerpo vacío y el pedido deja de ser abrible por completo — ni para ver, ni
   * para editar.
   *
   * Y es el estado que T1 acaba de producir: el pedido que realmente se sirvió se queda
   * `Guardado` y con su relación escrita, de modo que servir un pedido puede dejarlo
   * inaccesible.
   */
  test('T3 un pedido guardado con albarán no avisa: la pantalla responde 500', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/pedidosListado.php');

    const pedidos = await pedidosDelCliente(page);
    const guardados = pedidos.filter((p) => p.estado.includes('Guardado'));
    const respuestas = [];

    for (const pedido of guardados) {
      const respuesta = await page.goto(`modulos/mod_venta/pedido.php?id=${pedido.id}&accion=editar`);
      respuestas.push({ id: pedido.id, estado: respuesta.status() });
    }

    const rotas = respuestas.filter((r) => r.estado >= 500);
    expect(rotas.length, `Alguno de los pedidos guardados tiene albarán y su pantalla revienta: ${JSON.stringify(respuestas)}`).toBeGreaterThan(0);

    // Y lo que llega es una página vacía, sin ningún mensaje que oriente al operador.
    await page.goto(`modulos/mod_venta/pedido.php?id=${rotas[0].id}&accion=editar`);
    await expect(page.locator('body')).toBeEmpty();
  });
});
