/**
 * El bloque «Tipos de relación» de las tablas principales: el administrador crea, renombra,
 * modifica, da de baja, reactiva y borra los tipos sobre los que la tienda define sus
 * relaciones. Un tipo que ya tiene relaciones solo se puede renombrar o dar de baja.
 *
 * Los tipos de la siembra (`support/sembrar-e2e-relaciones.php`) tienen relaciones; el que
 * crea el recorrido no, y se borra al terminar. Corre en serie: cada paso parte del anterior.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion, usuarioSinPermiso } = require('../../fixtures/autenticacion');

const VISTA = 'modulos/mod_configuracion/tablasPrincipales.php';
const FILAS = '#tablaTiposRelacion tbody tr';

const filaDe = (page, nombre) => page.locator(FILAS).filter({ has: page.locator('td:nth-child(2)', { hasText: new RegExp('^' + nombre + '$') }) });

async function abrirFormularioDe(page, nombre) {
  await filaDe(page, nombre).locator('a.glyphicon-pencil').click();
  await expect(page.locator('#formTipoRelacion')).toBeVisible();
}

test.describe.configure({ mode: 'serial' });

test.describe('Tablas principales — tipos de relación', () => {
  test('T1 el bloque muestra cada tipo con su forma, si lleva cantidad y su estado', {
    tag: ['@estado-actual', '@relaciones', '@listado'],
  }, async ({ page }) => {
    await iniciarSesion(page, VISTA);

    await expect(page.locator('#tablaTiposRelacion thead th')).toHaveText(['ID', 'Nombre', 'Forma', 'Cantidad', 'Estado', '']);
    await expect(filaDe(page, 'Despiece')).toContainText('De uno a varios');
    await expect(filaDe(page, 'Elaboración')).toContainText('De varios a uno');
    await expect(filaDe(page, 'Cruce').locator('td')).toHaveText([/\d+/, 'Cruce', 'De uno a uno', 'No', 'Activo', '']);
  });

  test('T2 el administrador crea un tipo y aparece en la tabla', {
    tag: ['@estado-actual', '@relaciones', '@guardado'],
    annotation: [
      { type: 'Comportamiento', description: 'Un tipo es un dato que crea la tienda: un nombre sobre una de las tres formas. Aparece en la tabla sin instalar ni cambiar nada.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, VISTA);
    if (await filaDe(page, 'E2E tipo renombrado').count() > 0 || await filaDe(page, 'E2E tipo nuevo').count() > 0) {
      test.skip(true, 'Queda un tipo de una pasada anterior: la siembra lo retira en la siguiente.');
    }

    await page.locator('#btnTipoRelacionNuevo').click();
    await page.fill('#tipoRelacionNombre', 'E2E tipo nuevo');
    await page.selectOption('#tipoRelacionForma', 'N:1');
    await Promise.all([page.waitForEvent('load'), page.locator('#btnTipoRelacionGuardar').click()]);

    await expect(filaDe(page, 'E2E tipo nuevo').locator('td')).toHaveText([/\d+/, 'E2E tipo nuevo', 'De varios a uno', 'Sí', 'Activo', '']);
  });

  test('T3 un nombre que ya tiene otro tipo vigente se rechaza con su motivo', {
    tag: ['@estado-actual', '@relaciones', '@validacion'],
  }, async ({ page }) => {
    await iniciarSesion(page, VISTA);

    await page.locator('#btnTipoRelacionNuevo').click();
    await page.fill('#tipoRelacionNombre', 'Despiece');
    const [aviso] = await Promise.all([page.waitForEvent('dialog'), page.locator('#btnTipoRelacionGuardar').click()]);

    expect(aviso.message()).toContain('Ya hay un tipo vigente con ese nombre');
    await aviso.accept();
    await expect(filaDe(page, 'Despiece')).toHaveCount(1);
  });

  test('T4 se renombra y se le cambian la forma y la cantidad de una vez', {
    tag: ['@estado-actual', '@relaciones', '@guardado'],
    annotation: [
      { type: 'Comportamiento', description: 'Guardar un tipo que ya existe son dos operaciones, cada una con su permiso: el nombre por un lado y la forma por otro. La pantalla las encadena.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, VISTA);
    await abrirFormularioDe(page, 'E2E tipo nuevo');

    await page.fill('#tipoRelacionNombre', 'E2E tipo renombrado');
    await page.selectOption('#tipoRelacionForma', '1:1');
    await page.locator('#tipoRelacionCantidad').uncheck();
    await Promise.all([page.waitForEvent('load'), page.locator('#btnTipoRelacionGuardar').click()]);

    await expect(filaDe(page, 'E2E tipo nuevo')).toHaveCount(0);
    await expect(filaDe(page, 'E2E tipo renombrado').locator('td')).toHaveText([/\d+/, 'E2E tipo renombrado', 'De uno a uno', 'No', 'Activo', '']);
  });

  test('T5 se da de baja y se reactiva', {
    tag: ['@estado-actual', '@relaciones', '@estados'],
  }, async ({ page }) => {
    await iniciarSesion(page, VISTA);

    await abrirFormularioDe(page, 'E2E tipo renombrado');
    await expect(page.locator('#btnTipoRelacionReactivar')).toHaveCount(0);
    await Promise.all([page.waitForEvent('load'), page.locator('#btnTipoRelacionBaja').click()]);
    await expect(filaDe(page, 'E2E tipo renombrado')).toContainText('Baja');

    await abrirFormularioDe(page, 'E2E tipo renombrado');
    await expect(page.locator('#btnTipoRelacionBaja')).toHaveCount(0);
    await Promise.all([page.waitForEvent('load'), page.locator('#btnTipoRelacionReactivar').click()]);
    await expect(filaDe(page, 'E2E tipo renombrado')).toContainText('Activo');
  });

  test('T6 de un tipo con relaciones no se ofrece cambiar la forma ni borrarlo', {
    tag: ['@estado-actual', '@relaciones', '@validacion'],
    annotation: [
      { type: 'Comportamiento', description: 'La forma de un tipo es lo que se comprobó al admitir sus relaciones. Mientras tenga alguna, el formulario la muestra bloqueada, dice por qué y no ofrece borrar.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, VISTA);
    await abrirFormularioDe(page, 'Despiece');

    await expect(page.locator('#tipoRelacionForma')).toBeDisabled();
    await expect(page.locator('#tipoRelacionCantidad')).toBeDisabled();
    await expect(page.locator('#tipoRelacionNombre')).toBeEnabled();
    await expect(page.locator('#btnTipoRelacionBorrar')).toHaveCount(0);
    await expect(page.locator('#btnTipoRelacionBaja')).toBeVisible();
    await expect(page.locator('#formTipoRelacion')).toContainText('solo se puede renombrar o dar de baja');
  });

  test('T7 un tipo sin relaciones se borra', {
    tag: ['@estado-actual', '@relaciones', '@guardado'],
  }, async ({ page }) => {
    await iniciarSesion(page, VISTA);
    await abrirFormularioDe(page, 'E2E tipo renombrado');

    page.once('dialog', (confirmacion) => confirmacion.accept());
    await Promise.all([page.waitForEvent('load'), page.locator('#btnTipoRelacionBorrar').click()]);

    await expect(filaDe(page, 'E2E tipo renombrado')).toHaveCount(0);
    await expect(filaDe(page, 'Despiece')).toHaveCount(1);
  });

  test('T8 las otras tres tablas siguen en la pantalla y abren su formulario', {
    tag: ['@estado-actual', '@listado'],
    annotation: [
      { type: 'Comportamiento', description: 'El bloque nuevo ocupa una fila propia y no cambia nada de IVA, formas de pago ni vencimientos.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, VISTA);

    await expect(page.locator('h4', { hasText: 'Tabla IVAS' })).toBeVisible();
    await expect(page.locator('h4', { hasText: 'Formas de Pago' })).toBeVisible();
    await expect(page.locator('h4', { hasText: 'Tipos de Vencimiento' })).toBeVisible();

    await page.locator('h4', { hasText: 'Formas de Pago' }).locator('a.glyphicon-plus').click();
    await expect(page.locator('#descripcion')).toBeVisible();
    await expect(page.locator('#formTipoRelacion')).toHaveCount(0);
  });

  test('T9 quien no es administrador no puede añadir ni cambiar tipos', {
    tag: ['@estado-actual', '@relaciones', '@permisos'],
    annotation: [
      { type: 'Comportamiento', description: 'Sin los permisos de las operaciones, la pantalla no ofrece ninguna: ni el botón de añadir ni, al abrir un tipo, ningún botón de guardar, dar de baja o borrar.' },
    ],
  }, async ({ page }) => {
    const sinPermiso = usuarioSinPermiso();
    test.skip(sinPermiso === null, 'El entorno no declara TPVFOX_E2E_USUARIO_SIN_PERMISO/TPVFOX_E2E_CLAVE_SIN_PERMISO.');

    await iniciarSesion(page, VISTA, sinPermiso);
    test.skip(await page.locator('#bloqueTiposRelacion').count() === 0, 'Ese usuario no puede abrir las tablas principales: no hay bloque que examinar.');

    await expect(page.locator('#btnTipoRelacionNuevo')).toHaveCount(0);
    await abrirFormularioDe(page, 'Despiece');
    await expect(page.locator('#formTipoRelacion a.btn')).toHaveCount(0);
    await expect(page.locator('#tipoRelacionNombre')).toBeDisabled();
  });
});
