/**
 * Reunir despieces en el albarán del mes: al calcular un mes se ofrece añadir las líneas a un
 * albarán del mismo proveedor, mes y tipo de relación, o crear uno nuevo. Un lote no ofrece nada.
 *
 * Se hace sobre el despliegue del ejercicio vigente, partiendo de la siembra de
 * `support/preparar-e2e-aplicar-relacion.php`, a la que `support/preparar-e2e-aplicar-relacion-reunir.php`
 * añade un albarán temporal abierto con otro despiece.
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const { iniciarSesion } = require('../../fixtures/autenticacion');

const PANTALLA = 'modulos/mod_reorganizacion/AplicarRelacion.php';

function preparar(...argumentos) {
  return execFileSync('php', [path.resolve(__dirname, '../../../support/preparar-e2e-aplicar-relacion.php'), ...argumentos, 'vigente'], { stdio: 'pipe' }).toString();
}

function prepararReunir(...argumentos) {
  return execFileSync('php', [path.resolve(__dirname, '../../../support/preparar-e2e-aplicar-relacion-reunir.php'), ...argumentos, 'vigente'], { stdio: 'pipe' }).toString();
}

async function calcular(page, escenario, periodo = { tipo: 'mes', valor: '2026-02' }) {
  await page.locator('#idProveedorAplicacion').selectOption(String(escenario.especial));
  await page.locator('#numRelacionAplicacion').selectOption(String(escenario.numRelacion));
  await page.locator('#tipoPeriodoAplicacion').selectOption(periodo.tipo);
  await page.locator('#periodoAplicacion').selectOption(periodo.valor);
  await page.locator('#btnCalcularAplicacion').click();
  await expect(page.locator('#avisosAplicacion .alert').first()).toBeVisible();
}

test.describe.configure({ mode: 'serial' });

test.describe('Reorganizar — reunir despieces en el albarán del mes', () => {
  let escenario;
  let temporal;

  test.beforeEach(() => {
    escenario = JSON.parse(preparar('escenario'));
    temporal = JSON.parse(prepararReunir('temporal'));
  });

  test('T1: un mes con un albarán del mismo proveedor y tipo ofrece añadir o crear uno nuevo', async ({ page }) => {
    await iniciarSesion(page, PANTALLA);
    await expect(page.locator('#destinoAplicacion')).toHaveCount(0);

    await calcular(page, escenario);

    const destino = page.locator('#destinoAplicacion');
    await expect(destino).toBeVisible();
    await expect(destino.locator('option')).toHaveCount(2);
    await expect(destino.locator('option').nth(0)).toContainText('Albarán nuevo');
    await expect(destino.locator('option').nth(1)).toContainText('28-02-2026');
    await expect(destino.locator('option').nth(1)).toContainText('Ternera');
    await expect(destino).toHaveValue('nuevo');
  });

  test('T2: elegir el albarán abierto añade el despiece a él y lo abre en compras', async ({ page }) => {
    await iniciarSesion(page, PANTALLA);
    await calcular(page, escenario);

    await page.locator('#destinoAplicacion').selectOption('temporal:' + temporal.idTemporal);
    await page.locator('#btnPrepararAplicacion').click();

    await expect(page).toHaveURL(new RegExp('mod_compras/albaran\\.php\\?tActual=' + temporal.idTemporal + '$'));
    for (let fila = 1; fila <= 6; fila++) {
      await expect(page.locator('#Row' + fila)).toBeVisible();
    }
  });

  test('T3: un lote no ofrece reunir', async ({ page }) => {
    await iniciarSesion(page, PANTALLA);

    await calcular(page, escenario, { tipo: 'lote', valor: '2026-02-03' });

    await expect(page.locator('#destinoAplicacion')).toHaveCount(0);
  });

  test('T4: elegir «Albarán nuevo» crea otro temporal y deja el abierto como estaba', async ({ page }) => {
    await iniciarSesion(page, PANTALLA);
    await calcular(page, escenario);

    await page.locator('#btnPrepararAplicacion').click();

    await expect(page).toHaveURL(/mod_compras\/albaran\.php\?tActual=\d+$/);
    await expect(page).not.toHaveURL(new RegExp('tActual=' + temporal.idTemporal + '$'));
    await expect(page.locator('#Row5')).toBeVisible();
    await expect(page.locator('#Row6')).toHaveCount(0);
  });
});
