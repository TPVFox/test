/**
 * Lo que la lista de tareas debería hacer y hoy no hace: mostrar el nombre de una tarea como
 * texto, y decir si la ejecución que se le pide no se pudo hacer.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../../fixtures/autenticacion');
const { LISTA, EJECUCION, PREFIJO, filaDeTarea, leerTarea } = require('../../../fixtures/tareasCron');

test.describe('Tareas programadas — la lista', () => {
  test('T1 el nombre de una tarea se muestra como texto, no como parte de la página', {
    tag: ['@esperado', '@tareas', '@listado', '@directo', '@alto'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'Un nombre de tarea con marcas de HTML se pinta como HTML: la lista lo convierte en parte de la página, y un nombre con código JavaScript se ejecutaría en el navegador de quien abra la lista. La ficha admite ese nombre.' },
      { type: 'Qué debería ocurrir', description: 'Que la lista muestre el nombre tal cual, como texto.' },
      { type: 'Por qué ocurre', description: 'La lista escribe el nombre y la clase de cada tarea sin escaparlos, también dentro de los atributos de sus botones; la ficha hace lo mismo en sus campos.' },
      { type: 'Cómo debería funcionar', description: 'Escapar todo lo que la lista y la ficha escriben de una tarea.' },
    ],
  }, async ({ page }) => {
    expect(leerTarea(PREFIJO + '<b id=e2e-inyectado>x</b>'), 'La siembra tiene que dejar la tarea').not.toBeNull();

    await iniciarSesion(page, LISTA);

    await expect(page.locator('#e2e-inyectado'), 'El nombre no puede convertirse en un elemento de la página').toHaveCount(0);
  });

  test('T2 si la ejecución pedida desde la lista no se puede hacer, la pantalla lo dice', {
    tag: ['@esperado', '@tareas', '@listado', '@directo', '@medio'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'Al pulsar «Ejecutar», la lista escribe la respuesta en la consola del navegador y no enseña nada: si la tarea no se pudo ejecutar, quien la pidió no se entera.' },
      { type: 'Qué debería ocurrir', description: 'Que la lista diga si la tarea se ejecutó o por qué no.' },
      { type: 'Por qué ocurre', description: 'Cuando la petición responde, la pantalla solo escribe la respuesta en la consola; solo avisa cuando la petición misma falla.' },
      { type: 'Cómo debería funcionar', description: 'Enseñar el mensaje de la respuesta, y marcarlo como error cuando lo sea.' },
    ],
  }, async ({ page }) => {
    // La respuesta se sirve aquí, igual que la da tareas.php cuando no encuentra el fichero de la
    // tarea: pedir de verdad la ejecución lanzaría una tarea de coste sobre la base de los demás
    // recorridos.
    const enviadas = [];
    await page.route('**/' + EJECUCION, (ruta) => {
      enviadas.push(ruta.request().postData());
      return ruta.fulfill({
        status: 200,
        contentType: 'text/html; charset=UTF-8',
        body: '\n{"mensaje":"NO ejecutable --->./tareas/AcumuladoComprasTarea.php","error":1}',
      });
    });
    await iniciarSesion(page, LISTA);
    const boton = filaDeTarea(page, PREFIJO + 'Para listar').getByRole('button', { name: 'Ejecutar' });
    const id = await boton.getAttribute('data-tareaid');

    await boton.click();

    await expect.poll(() => enviadas, { message: 'La lista pide la ejecución de esa tarea' }).toEqual([`tareaid=${id}`]);
    await expect(page.getByText('NO ejecutable'), 'La lista tiene que enseñar que la tarea no se ejecutó').toBeVisible();
  });
});
