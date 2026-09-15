/**
 * Los estados en que se puede abrir `pedido.php`, y qué decide cada uno.
 *
 * La pantalla resuelve al cargar una acción («», 'ver' o 'editar') y de ella salen la
 * visibilidad de la fila de entrada de productos, la de los botones Guardar/Cancelar y si
 * el cliente se puede cambiar. A diferencia del albarán, aquí hay además un estado que la
 * pantalla rechaza: un pedido ya servido no se deja editar.
 *
 * Al pedido ya guardado se llega desde el listado, como llega el operador, y no escribiendo
 * su id en la URL: el id de `pedclit` es autoincremental y cambia al rehacer la base,
 * mientras que el número visible del pedido es otro campo distinto.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');

const NOMBRE_CLIENTE = '[E2E venta] Cliente pedido entradas';

/** La fila del listado que corresponde a un pedido de este recorrido, por su estado. */
function filaDelPedido(page, estado) {
  return page
    .locator('table.table-bordered tbody tr')
    .filter({ hasText: NOMBRE_CLIENTE })
    .filter({ hasText: estado })
    .first();
}

test.describe('Pedido — estados de entrada de la pantalla', () => {
  test('T1 sin parámetros: pedido nuevo, sin fila de entrada ni botones, con el cliente por elegir', {
    tag: ['@estado-actual', '@pedido', '@entrada'],
    annotation: [
      { type: 'Comportamiento', description: 'Abierta sin parámetros, la pantalla ofrece un pedido nuevo: sin fila de entrada ni botones, y con el cliente por elegir.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/pedido.php');

    await expect(page.locator('#estado')).toHaveValue('Nuevo');
    await expect(page.locator('#Row0')).toBeHidden();
    await expect(page.locator('#Guardar')).toBeHidden();
    await expect(page.locator('#Cancelar')).toBeHidden();
    await expect(page.locator('#id_cliente')).toBeEditable();
  });

  test('T2 abierto desde el enlace de ver: todo en solo lectura', {
    tag: ['@estado-actual', '@pedido', '@entrada'],
    annotation: [
      { type: 'Comportamiento', description: 'Desde el enlace de ver del listado, el pedido se abre en solo lectura: sin fila de entrada, sin Guardar y con el cliente bloqueado.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/pedidosListado.php');

    await filaDelPedido(page, 'Guardado').locator('a[title="Ver pedido"]').click();

    await expect(page.locator('h2.text-center')).toContainText('ver');
    await expect(page.locator('#estado')).toHaveValue('Guardado');
    await expect(page.locator('#Row0')).toBeHidden();
    await expect(page.locator('#Guardar')).toBeHidden();
    await expect(page.locator('#id_cliente')).not.toBeEditable();
  });

  /**
   * Entrar a editar un pedido guardado abre la fila de entrada de productos, pero no los
   * botones de Guardar y Cancelar: `pedido.php` los oculta mientras no exista temporal, y
   * el temporal no nace hasta que se añade la primera línea. La pantalla queda por tanto en
   * un estado intermedio en el que se puede escribir pero no confirmar; lo que devuelve los
   * botones es la primera línea, no la acción de editar.
   */
  test('T3 abierto para editar: se puede escribir, pero no hay todavía con qué guardar', {
    tag: ['@estado-actual', '@pedido', '@entrada'],
    annotation: [
      { type: 'Comportamiento', description: 'Desde el enlace de editar se abre la fila de entrada, pero Guardar y Cancelar no aparecen hasta que la primera línea crea el documento en curso.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/pedidosListado.php');

    await filaDelPedido(page, 'Guardado').locator('a[title="Editar pedido"]').click();

    await expect(page.locator('h2.text-center')).toContainText('editar');
    await expect(page.locator('#Row0')).toBeVisible();
    await expect(page.locator('#Guardar')).toBeHidden();
    await expect(page.locator('#Cancelar')).toBeHidden();
    await expect(page.locator('#id_cliente')).not.toBeEditable();
  });

  /**
   * Un pedido que ya tiene albarán no se puede editar: la pantalla fuerza la acción a 'ver'
   * y lo dice. Es el único control que hoy protege un pedido servido, y es de pantalla —no
   * de servidor—: la clase acepta igual cualquier escritura sobre ese mismo documento.
   */
  test('T4 un pedido ya servido se abre en solo lectura y avisa de por qué', {
    tag: ['@estado-actual', '@pedido', '@entrada', '@estados'],
    annotation: [
      { type: 'Comportamiento', description: 'Un pedido que ya tiene albarán se abre en solo lectura y la pantalla explica que ya se sirvió. Es una protección de pantalla: el servidor aceptaría igual cualquier escritura sobre él.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/pedidosListado.php');

    await filaDelPedido(page, 'Procesado').locator('a[title="Editar pedido"]').click();

    await expect(page.locator('body')).toContainText('INTENTAS EDITAR UN PEDIDO YA CREO ALBARAN');
    await expect(page.locator('h2.text-center')).toContainText('ver');
    await expect(page.locator('#Row0')).toBeHidden();
  });

  /**
   * El enlace de ver del listado envía `&estado=ver`, un parámetro que `pedido.php` no lee
   * en ningún sitio: la pantalla solo consulta `accion`. Acaba en «ver» de todas formas
   * porque esa es la resolución por defecto de un identificador sin acción, así que el
   * enlace funciona por coincidencia entre lo que envía y lo que la pantalla decide por su
   * cuenta. T2 comprueba el resultado; este caso fija que el parámetro viaja y se ignora.
   */
  test('T5 el parámetro que envía el enlace de ver viaja y no se lee', {
    tag: ['@estado-actual', '@pedido', '@entrada'],
    annotation: [
      { type: 'Comportamiento', description: 'El enlace de ver envía un parámetro que la pantalla no lee: el pedido se abre en modo ver con él y sin él, porque ese es el modo por defecto.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/pedidosListado.php');
    const enlace = await filaDelPedido(page, 'Guardado').locator('a[title="Ver pedido"]').getAttribute('href');
    const idPedido = new URL(enlace, 'http://x/').searchParams.get('id');

    // Con el parámetro que el enlace envía.
    await page.goto(`modulos/mod_venta/pedido.php?id=${idPedido}&estado=ver`);
    await expect(page.locator('h2.text-center')).toContainText('ver');

    // Y sin él: el resultado es el mismo, porque no es lo que lo decide.
    await page.goto(`modulos/mod_venta/pedido.php?id=${idPedido}`);
    await expect(page.locator('h2.text-center')).toContainText('ver');
    await expect(page.locator('#Row0')).toBeHidden();
  });

  /**
   * La comprobación de borradores abiertos solo corre en la rama de edición: entrar a ver un
   * pedido que tiene uno no lo detecta ni lo advierte, de modo que el operador puede estar
   * mirando un documento cuya versión en curso dice otra cosa.
   *
   * El recorrido cierra el borrador que crea, para no dejar bloqueado el pedido del que se
   * sirven los demás casos de este fichero.
   */
  test('T6 abrir en modo ver un pedido con borrador abierto no lo advierte', {
    tag: ['@estado-actual', '@defecto', '@pedido', '@borrador', '@medio'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'Consultar un pedido que tiene un documento en curso no lo advierte: la pantalla no dice nada, aunque la versión en curso sea distinta.' },
      { type: 'Qué debería ocurrir', description: 'Que la consulta advierta de que existe una versión en curso, igual que lo hace la edición.' },
      { type: 'Por qué ocurre', description: 'La comprobación de documentos en curso solo corre cuando se entra a editar, no cuando se entra a ver.' },
      { type: 'Cómo debería funcionar', description: 'Que la comprobación dependa del documento, no de la acción con que se abre.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/pedidosListado.php');
    const enlace = await filaDelPedido(page, 'Guardado').locator('a[title="Editar pedido"]').getAttribute('href');
    const idPedido = new URL(enlace, 'http://x/').searchParams.get('id');

    // Se crea el borrador añadiendo la primera línea, que es lo que lo hace nacer.
    await page.goto(`modulos/mod_venta/pedido.php?id=${idPedido}&accion=editar`);
    await expect(page.locator('#idArticulo')).toBeVisible({ timeout: 10000 });
    await page.fill('#idArticulo', '14678');
    await Promise.all([
      page.waitForResponse(
        (r) => r.url().includes('tareas.php') && (r.request().postData() || '').includes('anhadirTemporal')
      ),
      page.locator('#idArticulo').press('Enter'),
    ]);
    await expect(page).toHaveURL(/tActual=\d+/);
    const idTemporal = new URL(page.url()).searchParams.get('tActual');

    // Abrir el mismo pedido en modo ver: ni aviso, ni enlace al borrador abierto.
    await page.goto(`modulos/mod_venta/pedido.php?id=${idPedido}&estado=ver`);
    await expect(page.locator('h2.text-center')).toContainText('ver');
    await expect(page.locator('body')).not.toContainText('temporal');

    // Y en modo edición sí lo detecta, que es lo que hace visible la asimetría.
    await page.goto(`modulos/mod_venta/pedido.php?id=${idPedido}&accion=editar`);
    await expect(page.locator('#Row0')).toBeHidden();

    // Se retira el borrador para dejar el despliegue como estaba.
    await page.goto(`modulos/mod_venta/pedido.php?tActual=${idTemporal}`);
    page.on('dialog', (dialogo) => dialogo.accept());
    await page.locator('#Cancelar').click();
    await expect(page).toHaveURL(/pedidosListado\.php/, { timeout: 15000 });
  });
});
