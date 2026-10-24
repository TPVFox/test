/**
 * Las pantallas de las tareas programadas, tal como las usa el administrador: la lista, el alta
 * desde la ficha y el borrado.
 *
 * Las tareas llevan el prefijo `[E2E tareas]` y las siembra `support/sembrar-e2e-tareas.php` antes
 * de cada pasada. Lo que queda escrito se lee de la base con `support/leer-tarea.php`.
 *
 * Ningún recorrido pide de verdad la ejecución de una tarea: lanzaría una tarea de coste sobre la
 * base de los demás recorridos. Lo que hace `tareas.php` se prueba en integración.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');
const { LISTA, PREFIJO, filaDeTarea, leerTarea } = require('../../fixtures/tareasCron');

const ENLACE = 'a[href$="/modulos/mod_tareas_cron/index.php"]';

test.describe('Tareas programadas — pantallas', () => {
  test('T1 el administrador abre la lista desde el menú y ve cada tarea con su período, su clase, sus fechas y su estado', {
    tag: ['@estado-actual', '@tareas', '@listado'],
  }, async ({ page }) => {
    await iniciarSesion(page, 'index.php');
    await expect(page.locator(ENLACE)).toHaveText('Tareas');

    await page.goto(await page.locator(ENLACE).getAttribute('href'));

    await expect(page).toHaveURL(/ListaTareasCron\.php$/);
    await expect(page.locator('h2')).toHaveText('Listados de tareas');
    const celdas = filaDeTarea(page, PREFIJO + 'Para listar').locator('td');
    await expect(celdas.nth(2)).toHaveText('1 Días');
    await expect(celdas.nth(3)).toHaveText('AcumuladoComprasTarea');
    await expect(celdas.nth(4)).toHaveText('01/01/2020');
    await expect(celdas.nth(5)).toHaveText('');
    await expect(celdas.nth(6)).toHaveText('activo');
  });

  test('T2 una tarea nueva se da de alta desde la ficha y aparece en la lista', {
    tag: ['@estado-actual', '@tareas', '@guardado'],
  }, async ({ page }) => {
    const nombre = PREFIJO + 'Creada desde la ficha';
    await iniciarSesion(page, LISTA);

    await page.getByRole('link', { name: 'Nueva' }).click();
    await expect(page.locator('h2')).toHaveText('Crear Tarea');
    await page.fill('#nombre', nombre);
    await page.fill('#periodo', '3');
    await page.selectOption('#select_tipo_periodo', '2');
    await page.fill('#fecha_inicio', '2030-01-15');
    await page.fill('#nombre_clase', 'UltimoPrecioCompraTarea');
    await page.getByRole('button', { name: 'Guardar' }).click();

    await expect(page).toHaveURL(/ListaTareasCron\.php$/);
    await expect(filaDeTarea(page, nombre)).toHaveCount(1);
    expect(leerTarea(nombre)).toMatchObject({
      cantidad_periodo: 3,
      tipo_periodo: 2,
      inicio_ejecucion: '2030-01-15',
      nombre_clase: 'UltimoPrecioCompraTarea',
      estado: 1,
    });
  });

  test('T3 «Ejecutar» solo está disponible en las tareas activas', {
    tag: ['@estado-actual', '@tareas', '@estados'],
    annotation: [
      { type: 'Comportamiento', description: 'La lista desactiva «Ejecutar» en una tarea que no está activa. Es la única comprobación de estado de la ejecución manual: tareas.php no la repite.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, LISTA);

    await expect(filaDeTarea(page, PREFIJO + 'Para listar').getByRole('button', { name: 'Ejecutar' })).toBeEnabled();
    await expect(filaDeTarea(page, PREFIJO + 'De baja').getByRole('button', { name: 'Ejecutar' })).toBeDisabled();
  });

  test('T4 una tarea se borra desde la lista, tras confirmarlo', {
    tag: ['@estado-actual', '@tareas', '@listado'],
  }, async ({ page }) => {
    const nombre = PREFIJO + 'Para borrar';
    expect(leerTarea(nombre), 'La siembra tiene que dejar la tarea').not.toBeNull();
    await iniciarSesion(page, LISTA);

    page.once('dialog', (dialogo) => dialogo.accept());
    await filaDeTarea(page, nombre).getByRole('button', { name: 'Eliminar' }).click();

    await expect(filaDeTarea(page, nombre)).toHaveCount(0);
    expect(leerTarea(nombre)).toBeNull();
  });
});
