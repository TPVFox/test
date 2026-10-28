/**
 * Aplicar una relación desde «Reorganizar»: se elige un proveedor especial, un despiece que rige,
 * un periodo y, si se quiere, la cantidad que entra en la receta; la pantalla dice cuánto hay
 * disponible, el coste de partida y qué albaranes de proveedores especiales llevan ya el producto,
 * y enseña el escandallo. «Preparar albarán» deja un albarán temporal
 * de compras, que se revisa y se guarda en la pantalla de albaranes de siempre.
 *
 * Se hace sobre el despliegue del ejercicio vigente, partiendo de la siembra de
 * `support/preparar-e2e-aplicar-relacion.php`: el despiece del pollo, con 200 kg entrados en
 * febrero de 2026 y 20 kg vendidos enteros.
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const { iniciarSesion } = require('../../fixtures/autenticacion');

const PANTALLA = 'modulos/mod_reorganizacion/AplicarRelacion.php';
const LISTADO = 'modulos/mod_reorganizacion/ListadoReorganizar.php';
const PREFIJO = '[E2E aplicación] ';

function preparar(...argumentos) {
  return execFileSync('php', [path.resolve(__dirname, '../../../support/preparar-e2e-aplicar-relacion.php'), ...argumentos, 'vigente'], { stdio: 'pipe' }).toString();
}

/** Elige proveedor, despiece y periodo, y pulsa «Calcular». */
async function calcular(page, escenario, periodo = { tipo: 'mes', valor: '2026-02' }) {
  await page.locator('#idProveedorAplicacion').selectOption(String(escenario.especial));
  await page.locator('#numRelacionAplicacion').selectOption(String(escenario.numRelacion));
  await page.locator('#tipoPeriodoAplicacion').selectOption(periodo.tipo);
  await page.locator('#periodoAplicacion').selectOption(periodo.valor);
  await page.locator('#btnCalcularAplicacion').click();
  await expect(page.locator('#avisosAplicacion .alert').first()).toBeVisible();
}

test.describe.configure({ mode: 'serial' });

test.describe('Reorganizar — aplicar una relación', () => {
  let escenario;

  test.beforeEach(() => {
    escenario = JSON.parse(preparar('escenario'));
  });

  test('T1: sin «ver» no hay fila ni pantalla, y sin «Preparar» no se puede preparar', async ({ page }) => {
    preparar('permiso', 'ver', '0');
    await iniciarSesion(page, LISTADO);
    await expect(page.locator('a', { hasText: 'Aplicar relación' })).toHaveCount(0);
    await page.goto(PANTALLA);
    await expect(page).not.toHaveURL(/AplicarRelacion\.php/);

    preparar('permiso', 'ver', '1');
    preparar('permiso', 'Preparar', '0');
    await page.goto(LISTADO);
    await page.locator('a', { hasText: 'Aplicar relación' }).click();
    await expect(page).toHaveURL(/AplicarRelacion\.php/);
    await expect(page.locator('#btnCalcularAplicacion')).toBeVisible();
    await expect(page.locator('#btnPrepararAplicacion')).toHaveCount(0);
  });

  test('T2: calcular un mes enseña lo que se despieza, el coste de partida con su fecha, el aviso y el escandallo', async ({ page }) => {
    await iniciarSesion(page, PANTALLA);
    await expect(page.locator('#idProveedorAplicacion option', { hasText: PREFIJO + 'Granja' })).toHaveCount(0);

    await calcular(page, escenario);

    await expect(page.locator('#avisosAplicacion .alert-success')).toBeVisible();
    const datos = page.locator('#datosAplicacion');
    await expect(datos).toContainText('28-02-2026');
    await expect(datos).toContainText('180');
    await expect(datos).toContainText('172,8');
    await expect(datos).toContainText('7,2');
    await expect(datos).toContainText('2,90');
    await expect(datos).toContainText('15-01-2026');
    const piezas = page.locator('#escandalloAplicacion tbody tr.pieza');
    await expect(piezas).toHaveCount(4);
    await expect(piezas.nth(0)).toContainText(PREFIJO + 'Pechuga');
    await expect(piezas.nth(0)).toContainText('45');
    await expect(page.locator('#btnPrepararAplicacion')).toBeEnabled();
  });

  test('T3: un albarán de otro especial después y uno del mismo en el periodo avisan en amarillo y dejan preparar sobre lo que queda', async ({ page }) => {
    preparar('albaran', 'amarillo');
    await iniciarSesion(page, PANTALLA);
    await calcular(page, escenario);
    const amarillo = page.locator('#avisosAplicacion .alert-warning');
    await expect(amarillo).toContainText(PREFIJO + 'Otro especial');
    await expect(amarillo).toContainText('10-03-2026');
    await expect(page.locator('#btnPrepararAplicacion')).toBeEnabled();

    preparar('albaran', 'rojo');
    await calcular(page, escenario);
    await expect(page.locator('#avisosAplicacion .alert-warning')).toContainText(PREFIJO + 'Despiece del obrador');
    await expect(page.locator('#avisosAplicacion .alert-danger')).toHaveCount(0);
    await expect(page.locator('#datosAplicacion')).toContainText('Disponible: 170 kg');
    await expect(page.locator('#escandalloAplicacion tbody tr.pieza')).toHaveCount(4);
    await expect(page.locator('#btnPrepararAplicacion')).toBeEnabled();
  });

  test('T4: preparar abre el albarán en compras con sus líneas, y guardarlo mueve las existencias', async ({ page }) => {
    await iniciarSesion(page, PANTALLA);
    await calcular(page, escenario);

    await page.locator('#btnPrepararAplicacion').click();

    await expect(page).toHaveURL(/mod_compras\/albaran\.php\?tActual=\d+/);
    for (let fila = 1; fila <= 5; fila++) {
      await expect(page.locator('#Row' + fila)).toBeVisible();
    }
    await page.locator('#bGuardar').click();
    await expect(page).toHaveURL(/albaranesListado\.php/);
    const existencias = JSON.parse(preparar('existencias'));
    expect(existencias['Pollo entero']).toBeCloseTo(-172.8, 3);
    expect(existencias.Pechuga).toBeCloseTo(45, 3);
    expect(existencias.Carcasa).toBeCloseTo(43.2, 3);
  });

  test('T5: un lote es un día, y el albarán se fecha ese día', async ({ page }) => {
    await iniciarSesion(page, PANTALLA);

    await calcular(page, escenario, { tipo: 'lote', valor: '2026-02-03' });

    await expect(page.locator('#datosAplicacion')).toContainText('03-02-2026');
    await expect(page.locator('#datosAplicacion')).toContainText('100');
  });

  test('T6: un artículo con comillas en su nombre no se prepara, y el aviso dice cuál', async ({ page }) => {
    preparar('comillas');
    await iniciarSesion(page, PANTALLA);
    await calcular(page, escenario);

    await page.locator('#btnPrepararAplicacion').click();

    await expect(page.locator('#avisosAplicacion .alert-warning')).toContainText(PREFIJO + 'Muslo "de corral"');
    await expect(page).toHaveURL(/AplicarRelacion\.php/);
  });

  test('T7: los nombres con marcas se ven como texto', async ({ page }) => {
    preparar('marcas');
    const dialogos = [];
    page.on('dialog', async (dialogo) => {
      dialogos.push(dialogo.message());
      await dialogo.dismiss();
    });
    await iniciarSesion(page, PANTALLA);

    await expect(page.locator('#idProveedorAplicacion option', { hasText: '<b>Otro</b> especial & Cía' })).toHaveCount(1);
    await calcular(page, escenario);
    await expect(page.locator('#escandalloAplicacion tbody tr.pieza').nth(3)).toContainText('Carcasa <i>marcada</i> & Cía');
    expect(dialogos).toEqual([]);
  });

  test('T8: al elegir el despiece se ofrecen los meses o los días con entradas del producto, con sus kg', async ({ page }) => {
    await iniciarSesion(page, PANTALLA);
    await page.locator('#numRelacionAplicacion').selectOption(String(escenario.numRelacion));

    const meses = page.locator('#periodoAplicacion option');
    await expect(meses).toHaveText(['Elige un mes', 'diciembre 2026 — entraron 30 kg', 'febrero 2026 — entraron 200 kg', 'enero 2026 — entraron 50 kg']);

    await page.locator('#tipoPeriodoAplicacion').selectOption('lote');
    await expect(meses).toHaveText(['Elige un día', '10-12-2026 — entraron 30 kg', '17-02-2026 — entraron 100 kg', '03-02-2026 — entraron 100 kg', '01-01-2026 — entraron 50 kg']);
  });

  test('T9: el ajuste es un deslizador cuyas dos partes suman 2, y el proveedor de traspaso solo se ve en diciembre y enero', async ({ page }) => {
    await iniciarSesion(page, PANTALLA);
    await page.locator('#numRelacionAplicacion').selectOption(String(escenario.numRelacion));
    const peso = page.locator('#ajustePesoAplicacion');
    await expect(peso).toHaveAttribute('type', 'range');
    await expect(page.locator('#ajustePesoTexto')).toHaveText('1,00');
    await expect(page.locator('#ajusteValorTexto')).toHaveText('1,00');
    for (const [valor, textoPeso, textoValor] of [['1.3', '1,30', '0,70'], ['0', '0,00', '2,00'], ['2', '2,00', '0,00']]) {
      await peso.fill(valor);
      await expect(page.locator('#ajustePesoTexto')).toHaveText(textoPeso);
      await expect(page.locator('#ajusteValorTexto')).toHaveText(textoValor);
    }

    const traspaso = page.locator('#bloqueTraspasoAplicacion');
    const periodo = page.locator('#periodoAplicacion');
    await periodo.selectOption('2026-02');
    await expect(traspaso).toBeHidden();
    await periodo.selectOption('2026-12');
    await expect(traspaso).toBeVisible();
    await page.locator('#idProveedorTraspasoAplicacion').selectOption(String(escenario.granja));
    await periodo.selectOption('2026-01');
    await expect(traspaso).toBeVisible();
    await periodo.selectOption('2026-02');
    await expect(traspaso).toBeHidden();
    await expect(page.locator('#idProveedorTraspasoAplicacion')).toHaveValue('');

    await page.locator('#tipoPeriodoAplicacion').selectOption('lote');
    await periodo.selectOption('2026-12-10');
    await expect(traspaso).toBeHidden();
    await periodo.selectOption('2026-01-01');
    await expect(traspaso).toBeVisible();

    await page.locator('#tipoPeriodoAplicacion').selectOption('mes');
    await periodo.selectOption('2026-02');
    await peso.fill('1.3');
    await page.locator('#idProveedorAplicacion').selectOption(String(escenario.especial));
    await page.locator('#btnCalcularAplicacion').click();
    await expect(page.locator('#avisosAplicacion .alert-success')).toBeVisible();
    await expect(page.locator('#escandalloAplicacion tbody tr.pieza')).toHaveCount(4);

    // Lo calculado deja de valer en cuanto cambia cualquier dato elegido.
    await expect(page.locator('#btnPrepararAplicacion')).toBeEnabled();
    for (const cambio of [
      () => peso.fill('1.1'),
      () => page.locator('#idProveedorAplicacion').selectOption(String(escenario.otroEspecial)),
      () => periodo.selectOption('2026-01'),
    ]) {
      await page.locator('#btnCalcularAplicacion').click();
      await expect(page.locator('#escandalloAplicacion tbody tr.pieza')).toHaveCount(4);
      await cambio();
      await expect(page.locator('#escandalloAplicacion tbody tr.pieza')).toHaveCount(0);
      await expect(page.locator('#btnPrepararAplicacion')).toBeDisabled();
    }
  });

  test('T10: la versión vigente se aplica a un periodo anterior al alta de la relación, y la pantalla lo dice', async ({ page }) => {
    await iniciarSesion(page, PANTALLA);

    await calcular(page, escenario, { tipo: 'lote', valor: '2026-01-01' });
    await expect(page.locator('#avisosAplicacion .alert-warning')).toContainText('no regía');
    await expect(page.locator('#escandalloAplicacion tbody tr.pieza')).toHaveCount(0);

    await page.locator('#versionAplicacion').selectOption('vigente');
    await expect(page.locator('#avisosAplicacion .alert')).toHaveCount(0);
    await page.locator('#btnCalcularAplicacion').click();
    await expect(page.locator('#avisosAplicacion .alert-info')).toContainText('no existía');
    await expect(page.locator('#datosAplicacion')).toContainText('Versión vigente del despiece: 1.0');
    await expect(page.locator('#escandalloAplicacion tbody tr.pieza')).toHaveCount(4);
    await expect(page.locator('#btnPrepararAplicacion')).toBeEnabled();
  });

  test('T11: el pollo como derivado de otra aplicación del mismo especial en febrero avisa en amarillo y deja preparar', async ({ page }) => {
    execFileSync('php', [path.resolve(__dirname, '../../../support/preparar-e2e-aplicar-relacion-derivado.php'), 'vigente'], { stdio: 'pipe' });
    await iniciarSesion(page, PANTALLA);

    await calcular(page, escenario);

    const amarillo = page.locator('#avisosAplicacion .alert-warning');
    await expect(amarillo).toContainText('como derivado');
    await expect(amarillo).toContainText(PREFIJO + 'Despiece del obrador');
    await expect(page.locator('#avisosAplicacion .alert-danger')).toHaveCount(0);
    await expect(page.locator('#escandalloAplicacion tbody tr.pieza')).toHaveCount(4);
    await expect(page.locator('#btnPrepararAplicacion')).toBeEnabled();
  });

  test('T12: una cantidad escrita entra en la receta, y el escandallo sale de ella', async ({ page }) => {
    await iniciarSesion(page, PANTALLA);
    await page.locator('#cantidadAplicacion').fill('100');

    await calcular(page, escenario);

    const datos = page.locator('#datosAplicacion');
    await expect(datos).toContainText('Disponible: 180 kg');
    await expect(datos).toContainText('Entra en la receta: 100 kg');
    await expect(datos).toContainText('Despiezado: 96 kg');
    await expect(page.locator('#escandalloAplicacion tbody tr.pieza').nth(0)).toContainText('25');
    await expect(page.locator('#btnPrepararAplicacion')).toBeEnabled();
  });

  test('T13: una cantidad mayor que lo disponible se avisa, sin escandallo y sin poder preparar', async ({ page }) => {
    await iniciarSesion(page, PANTALLA);
    await page.locator('#cantidadAplicacion').fill('181');

    await calcular(page, escenario);

    await expect(page.locator('#avisosAplicacion .alert-warning')).toContainText('disponible');
    await expect(page.locator('#escandalloAplicacion tbody tr.pieza')).toHaveCount(0);
    await expect(page.locator('#btnPrepararAplicacion')).toBeDisabled();
  });

  test('T14: preparada una parte, la segunda aplicación del mes ve lo que queda y nombra el temporal como aplicado', async ({ page }) => {
    // Dos cálculos y la apertura de compras: con el equipo cargado pasa de los 30 s por defecto.
    test.setTimeout(60000);
    await iniciarSesion(page, PANTALLA);
    await page.locator('#cantidadAplicacion').fill('100');
    await calcular(page, escenario);
    await page.locator('#btnPrepararAplicacion').click();
    await expect(page).toHaveURL(/mod_compras\/albaran\.php\?tActual=\d+/);

    await page.goto(PANTALLA);
    await calcular(page, escenario);

    await expect(page.locator('#datosAplicacion')).toContainText('Disponible: 80 kg');
    const amarillo = page.locator('#avisosAplicacion .alert-warning');
    await expect(amarillo).toContainText('aplicado');
    await expect(amarillo).toContainText(PREFIJO + 'Despiece del obrador');
    await expect(page.locator('#escandalloAplicacion tbody tr.pieza').nth(0)).toContainText('20');
  });

  test('T15: una pieza que queda en negativo se avisa con el mínimo de la receta, y se puede preparar', async ({ page }) => {
    execFileSync('php', [path.resolve(__dirname, '../../../support/preparar-e2e-aplicar-relacion-desajuste.php'), 'vigente'], { stdio: 'pipe' });
    await iniciarSesion(page, PANTALLA);
    await page.locator('#cantidadAplicacion').fill('100');

    await calcular(page, escenario);

    const amarillo = page.locator('#avisosAplicacion .alert-warning');
    await expect(amarillo).toContainText(PREFIJO + 'Pechuga');
    await expect(amarillo).toContainText('160');
    await expect(page.locator('#datosAplicacion')).toContainText('Mínimo para cubrir las salidas de sus piezas: 160 kg');
    await expect(page.locator('#btnPrepararAplicacion')).toBeEnabled();
  });

  test('T16: la lista ofrece las recetas hermanas; añadir una la deja inactiva y le da su rama, y quitarla la devuelve', async ({ page }) => {
    const { numPicada } = JSON.parse(execFileSync('php', [path.resolve(__dirname, '../../../support/preparar-e2e-aplicar-relacion-hermanas.php'), 'vigente'], { stdio: 'pipe' }).toString());
    await iniciarSesion(page, PANTALLA);
    await page.locator('#numRelacionAplicacion').selectOption(String(escenario.numRelacion));

    const enLaLista = page.locator('#hermanasAplicacion [data-num="' + numPicada + '"]');
    await expect(enLaLista).toContainText(PREFIJO + 'Pollo para picada');
    await enLaLista.locator('button').click();

    await expect(enLaLista.locator('button')).toBeDisabled();
    await expect(enLaLista).toContainText('añadida');
    const rama = page.locator('#arbolAplicacion .rama[data-num="' + numPicada + '"]');
    await expect(rama).toContainText(PREFIJO + 'Pollo para picada');
    await expect(rama.locator('input.cantidadHermana')).toBeVisible();

    await rama.locator('button', { hasText: 'Quitar' }).click();
    await expect(rama).toHaveCount(0);
    await expect(enLaLista.locator('button')).toBeEnabled();
  });

  test('T17: con una hermana, calcular reparte lo disponible y enseña el escandallo de cada receta, y preparar deja las líneas de las dos', async ({ page }) => {
    const { numPicada } = JSON.parse(execFileSync('php', [path.resolve(__dirname, '../../../support/preparar-e2e-aplicar-relacion-hermanas.php'), 'vigente'], { stdio: 'pipe' }).toString());
    await iniciarSesion(page, PANTALLA);
    await page.locator('#numRelacionAplicacion').selectOption(String(escenario.numRelacion));
    await page.locator('#hermanasAplicacion [data-num="' + numPicada + '"] button').click();
    await page.locator('#arbolAplicacion .rama[data-num="' + numPicada + '"] input.cantidadHermana').fill('50');

    await calcular(page, escenario);

    await expect(page.locator('#datosAplicacion')).toContainText('Entra en la receta: 130 kg');
    const rama = page.locator('#arbolAplicacion .rama[data-num="' + numPicada + '"]');
    await expect(rama).toContainText('Entran 50 kg');
    await expect(rama.locator('tr.pieza')).toHaveCount(1);
    await expect(rama.locator('tr.pieza').first()).toContainText(PREFIJO + 'Picada');
    await expect(rama.locator('tr.pieza').first()).toContainText('49');
    await expect(page.locator('#arbolAplicacion .raiz')).toContainText('Sin aplicar: 0 kg');

    await page.locator('#btnPrepararAplicacion').click();
    await expect(page).toHaveURL(/mod_compras\/albaran\.php\?tActual=\d+/);
    for (let fila = 1; fila <= 7; fila++) {
      await expect(page.locator('#Row' + fila)).toBeVisible();
    }
  });

  test('T18: una receta del muslo cuelga del árbol y ofrece las suyas; quitarla quita las que cuelgan de ella; vacía toma lo generado', async ({ page }) => {
    const { numDeshuesado, numFileteado } = JSON.parse(execFileSync('php', [path.resolve(__dirname, '../../../support/preparar-e2e-aplicar-relacion-cadena.php'), 'vigente'], { stdio: 'pipe' }).toString());
    await iniciarSesion(page, PANTALLA);
    await page.locator('#numRelacionAplicacion').selectOption(String(escenario.numRelacion));

    const deshuesadoEnLaLista = page.locator('#hermanasAplicacion [data-num="' + numDeshuesado + '"]');
    const fileteadoEnLaLista = page.locator('#hermanasAplicacion [data-num="' + numFileteado + '"]');
    await expect(deshuesadoEnLaLista).toContainText(PREFIJO + 'Deshuesado del muslo');
    await expect(fileteadoEnLaLista).toHaveCount(0);

    await deshuesadoEnLaLista.locator('button').click();
    await expect(fileteadoEnLaLista).toContainText(PREFIJO + 'Fileteado');
    await fileteadoEnLaLista.locator('button').click();
    await expect(page.locator('#arbolAplicacion .rama[data-num="' + numFileteado + '"]')).toBeVisible();

    await page.locator('#arbolAplicacion .rama[data-num="' + numDeshuesado + '"] button', { hasText: 'Quitar' }).click();
    await expect(page.locator('#arbolAplicacion .rama')).toHaveCount(0);
    await expect(fileteadoEnLaLista).toHaveCount(0);

    await deshuesadoEnLaLista.locator('button').click();
    await calcular(page, escenario);

    const rama = page.locator('#arbolAplicacion .rama[data-num="' + numDeshuesado + '"]');
    await expect(rama).toContainText('Entran 63 kg');
    await expect(rama.locator('tr.pieza')).toHaveCount(2);
    await expect(page.locator('#arbolAplicacion .derivado')).toContainText('genera 63 kg');

    await page.locator('#btnPrepararAplicacion').click();
    await expect(page).toHaveURL(/mod_compras\/albaran\.php\?tActual=\d+/);
    for (let fila = 1; fila <= 8; fila++) {
      await expect(page.locator('#Row' + fila)).toBeVisible();
    }
  });
});
