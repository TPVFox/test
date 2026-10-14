/**
 * Lo que la ficha de una tarea debería conservar al guardarla sin tocar nada, y hoy no conserva.
 *
 * Cada recorrido abre la ficha de una tarea sembrada por `support/sembrar-e2e-tareas.php`, pulsa
 * Guardar sin cambiar ningún campo y comprueba adónde vuelve o lee de la base cómo quedó.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../../fixtures/autenticacion');
const { LISTA, PREFIJO, abrirFicha, leerTarea } = require('../../../fixtures/tareasCron');

test.describe('Tareas programadas — guardar la ficha sin tocarla', () => {
  test('T1 guardar una tarea sin cambios vuelve a la lista', {
    tag: ['@esperado', '@tareas', '@guardado', '@directo', '@bajo'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'Guardar la ficha de una tarea sin cambiar nada deja en la ficha el aviso «Por favor complete correctamente el formulario», aunque no hay ningún campo mal.' },
      { type: 'Qué debería ocurrir', description: 'Que vuelva a la lista, como cuando se guarda con cambios.' },
      { type: 'Por qué ocurre', description: 'Al modificar, el guardado devuelve cuántas filas cambió, y la ficha toma un cero como fallo: sin cambios, la base no cambia ninguna.' },
      { type: 'Cómo debería funcionar', description: 'Dar el guardado por bueno cuando no hay errores, cambie o no alguna fila.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, LISTA);
    await abrirFicha(page, PREFIJO + 'Guardada sin cambios');

    await page.getByRole('button', { name: 'Guardar' }).click();

    await expect(page, 'Guardar sin errores tiene que volver a la lista').toHaveURL(/ListaTareasCron\.php$/);
  });

  test('T2 guardar una tarea sin tocar la fecha conserva su fecha de inicio', {
    tag: ['@esperado', '@tareas', '@guardado', '@directo', '@alto'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'La ficha propone siempre como fecha de inicio la de hoy, no la guardada, y al guardar sin tocarla la tarea pasa a empezar hoy. Si ya se había ejecutado antes de hoy, el planificador deja de lanzarla: compara la fecha de inicio con la última ejecución, que queda antes.' },
      { type: 'Qué debería ocurrir', description: 'Que la ficha proponga la fecha guardada y la tarea la conserve.' },
      { type: 'Por qué ocurre', description: 'El campo de la fecha se rellena siempre con la de hoy, en lugar de con la de la tarea.' },
      { type: 'Cómo debería funcionar', description: 'Rellenar el campo con la fecha de inicio guardada.' },
    ],
  }, async ({ page }) => {
    const nombre = PREFIJO + 'Con inicio pasado';
    await iniciarSesion(page, LISTA);
    await abrirFicha(page, nombre);

    await page.getByRole('button', { name: 'Guardar' }).click();
    await expect(page).toHaveURL(/ListaTareasCron\.php$/);

    expect(leerTarea(nombre).inicio_ejecucion, 'La fecha de inicio no puede cambiar').toBe('2020-01-01');
  });

  test('T3 guardar una tarea sin tocar el período conserva su tipo de período', {
    tag: ['@esperado', '@tareas', '@guardado', '@directo', '@alto'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'La ficha no marca el tipo de período guardado y propone el primero, minutos. Al guardar sin tocarlo, una tarea de cada dos horas pasa a ser de cada dos minutos.' },
      { type: 'Qué debería ocurrir', description: 'Que la ficha marque el tipo de período guardado y la tarea lo conserve.' },
      { type: 'Por qué ocurre', description: 'Las opciones del tipo de período se pintan sin marcar ninguna como elegida.' },
      { type: 'Cómo debería funcionar', description: 'Marcar como elegida la opción del tipo de período de la tarea.' },
    ],
  }, async ({ page }) => {
    const nombre = PREFIJO + 'Cada dos horas';
    await iniciarSesion(page, LISTA);
    await abrirFicha(page, nombre);

    await page.getByRole('button', { name: 'Guardar' }).click();
    await expect(page).toHaveURL(/ListaTareasCron\.php$/);

    expect(leerTarea(nombre).tipo_periodo, 'El tipo de período no puede cambiar').toBe(2);
  });
});
