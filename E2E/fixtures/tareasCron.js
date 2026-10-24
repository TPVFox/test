/**
 * Lo que comparten los recorridos de las tareas programadas: las dos pantallas, el prefijo de las
 * tareas que siembra `support/sembrar-e2e-tareas.php` y la lectura de una tarea de la base.
 */

const { execFileSync } = require('child_process');
const path = require('path');

const LISTA = 'modulos/mod_tareas_cron/ListaTareasCron.php';
const FICHA = 'modulos/mod_tareas_cron/TareaCron.php';
const EJECUCION = 'modulos/mod_tareas_cron/tareas.php';
const PREFIJO = '[E2E tareas] ';

/** La fila de la lista cuya celda de nombre dice exactamente ese nombre. */
function filaDeTarea(page, nombre) {
  return page.locator('table tr').filter({
    has: page.getByRole('cell', { name: nombre, exact: true }),
  });
}

/** La tarea con ese nombre tal como está en la base, o null si no hay ninguna. */
function leerTarea(nombre) {
  const guion = path.resolve(__dirname, '../../support/leer-tarea.php');

  return JSON.parse(execFileSync('php', [guion, nombre], { encoding: 'utf-8' })).tarea;
}

/** Abre la ficha de la tarea desde su botón «Edit» de la lista. */
async function abrirFicha(page, nombre) {
  await page.goto(LISTA);
  await filaDeTarea(page, nombre).getByRole('link', { name: 'Edit' }).click();
  await page.waitForLoadState('domcontentloaded');
}

module.exports = { LISTA, FICHA, EJECUCION, PREFIJO, filaDeTarea, leerTarea, abrirFicha };
