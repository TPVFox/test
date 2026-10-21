/**
 * Las relaciones dadas de baja a la vista en el listado: su estado, el orden por estado, el botón
 * «Cambiar estado» que lleva al estado contrario, «Ver» una relación de baja y la reactivación
 * que se rechaza porque hay otra igual vigente.
 *
 * Todo escribe, así que se hace sobre el despliegue del ejercicio anterior, partiendo de la
 * siembra de `support/sembrar-e2e-relaciones.php`: cuarenta y cinco relaciones vigentes y la
 * relación 4, el despiece del conejo, de baja.
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const { iniciarSesion } = require('../../fixtures/autenticacion');

const VISTA = 'modulos/mod_relaciones/ListaRelaciones.php';
const FILAS = '#tablaRelaciones tbody tr';
const BOTON = '.col-sm-3 button:has-text("Cambiar estado")';
const URL_ANTERIOR = process.env.TPVFOX_URL_ANTERIOR;

function conBarraFinal(url) {
  return url.endsWith('/') ? url : url + '/';
}

function enElAnterior(ruta) {
  return new URL(ruta, conBarraFinal(URL_ANTERIOR)).toString();
}

function dejarLasTablas(estado, permiso = 'conPermiso') {
  execFileSync('php', [path.resolve(__dirname, '../../../support/sembrar-e2e-relaciones.php'), estado, 'anterior', permiso], { stdio: 'pipe' });
}

function preparar(argumentos) {
  execFileSync('php', [path.resolve(__dirname, '../../../support/preparar-e2e-cambio-estado.php'), ...argumentos, 'anterior'], { stdio: 'pipe' });
}

function filaDeLaRelacion(page, numero) {
  return page.locator(FILAS).filter({ has: page.locator('td:nth-child(4)', { hasText: new RegExp('^' + numero + '$') }) });
}

async function marcar(page, numeros) {
  for (const numero of numeros) {
    await filaDeLaRelacion(page, numero).locator('input.Check').check();
  }
}

/** Acepta los avisos y responde a la confirmación; devuelve el texto de cada diálogo. */
function atenderDialogos(page, confirmar = true) {
  const mensajes = [];
  page.on('dialog', async (dialogo) => {
    mensajes.push(dialogo.message());
    if (dialogo.type() === 'confirm' && !confirmar) {
      await dialogo.dismiss();
    } else {
      await dialogo.accept();
    }
  });
  return mensajes;
}

function peticionesDeCambio(page) {
  const peticiones = [];
  page.on('request', (peticion) => {
    if (peticion.url().includes('mod_relaciones/tareas.php')) {
      peticiones.push(peticion);
    }
  });
  return peticiones;
}

/** La columna que lleva el estado, por su cabecera. */
function celdaDeEstado(page, numero) {
  return filaDeLaRelacion(page, numero).locator('td:nth-child(8)');
}

test.describe.configure({ mode: 'serial' });

test.describe('Productos relacionados — relaciones de baja', () => {
  test.beforeEach(() => {
    test.skip(!URL_ANTERIOR, 'El entorno no declara TPVFOX_URL_ANTERIOR.');
  });

  test.afterAll(() => {
    if (URL_ANTERIOR) {
      dejarLasTablas('sinTipos');
    }
  });

  test('T1 el listado trae las relaciones de baja junto a las vigentes, cada una con su estado', {
    tag: ['@estado-actual', '@relaciones', '@listado', '@estados'],
    annotation: [
      { type: 'Comportamiento', description: 'El listado muestra todas las relaciones, vigentes y de baja, con una columna de estado. Sin verlas no se podrían reactivar.' },
    ],
  }, async ({ page }) => {
    dejarLasTablas('completo');
    await iniciarSesion(page, enElAnterior(VISTA + '?buscar=Conejo'));

    await expect(page.locator('#tablaRelaciones thead th', { hasText: 'ESTADO' })).toHaveCount(1);
    await expect(page.locator(FILAS)).toHaveCount(1);
    await expect(celdaDeEstado(page, 4)).toHaveText('Baja');

    await page.goto(enElAnterior(VISTA + '?buscar=Pollo+entero'));
    await expect(celdaDeEstado(page, 1)).toHaveText('Activo');
    await page.goto(enElAnterior(VISTA));
    await expect(page.locator('#relacionesEncontradas')).toHaveText('46');
  });

  test('T2 ordenar por estado junta las relaciones de baja, de las dos maneras', {
    tag: ['@estado-actual', '@relaciones', '@listado', '@estados'],
    annotation: [
      { type: 'Comportamiento', description: 'No hay filtro por estado; la columna de estado se ordena, y así las de baja quedan juntas, arriba o abajo.' },
    ],
  }, async ({ page }) => {
    dejarLasTablas('completo');
    await iniciarSesion(page, enElAnterior(VISTA));

    await page.locator('#tablaRelaciones thead th', { hasText: 'ESTADO' }).locator('a').first().click();
    await expect(page).toHaveURL(/orden=estado/);
    const ascendente = await page.locator(FILAS).first().locator('td:nth-child(8)').innerText();
    await page.locator('#tablaRelaciones thead th', { hasText: 'ESTADO' }).locator('a').first().click();
    const primeraFila = page.locator(FILAS).first().locator('td:nth-child(8)');
    await expect(primeraFila).not.toHaveText(ascendente.trim());
    const descendente = await primeraFila.innerText();

    expect(new Set([ascendente.trim(), descendente.trim()])).toEqual(new Set(['Activo', 'Baja']));
  });

  test('T3 marcadas relaciones de baja, «Cambiar estado» pregunta por Activo y las reactiva', {
    tag: ['@estado-actual', '@relaciones', '@listado', '@estados', '@permisos'],
    annotation: [
      { type: 'Comportamiento', description: 'El botón lleva las relaciones marcadas al estado contrario: las de baja, a Activo.' },
    ],
  }, async ({ page }) => {
    dejarLasTablas('completo');
    await iniciarSesion(page, enElAnterior(VISTA));
    const mensajes = atenderDialogos(page);

    await marcar(page, [4]);
    await page.locator(BOTON).click();

    await expect.poll(() => mensajes.length).toBeGreaterThanOrEqual(1);
    expect(mensajes[0]).toContain('Activo');
    expect(mensajes[0]).toContain('4 ');
    await expect(celdaDeEstado(page, 4)).toHaveText('Activo');
  });

  test('T4 marcadas de las dos clases, avisa de que no se pueden mezclar y no envía nada', {
    tag: ['@estado-actual', '@relaciones', '@listado', '@estados'],
  }, async ({ page }) => {
    dejarLasTablas('completo');
    await iniciarSesion(page, enElAnterior(VISTA));
    const mensajes = atenderDialogos(page);
    const peticiones = peticionesDeCambio(page);

    await marcar(page, [1, 4]);
    await page.locator(BOTON).click();

    await expect.poll(() => mensajes.length).toBe(1);
    expect(mensajes[0]).toMatch(/vigentes y de baja|las dos clases|mezcl/i);
    expect(peticiones).toHaveLength(0);
    await expect(celdaDeEstado(page, 1)).toHaveText('Activo');
    await expect(celdaDeEstado(page, 4)).toHaveText('Baja');

    mensajes.length = 0;
    await filaDeLaRelacion(page, 4).locator('input.Check').uncheck();
    await page.locator(BOTON).click();
    await expect.poll(() => mensajes.length).toBeGreaterThanOrEqual(1);
    expect(mensajes[0]).toContain('Baja');
  });

  test('T5 sin el permiso de cambiar el estado no aparece la opción, tampoco para reactivar', {
    tag: ['@estado-actual', '@relaciones', '@listado', '@permisos'],
  }, async ({ page }) => {
    dejarLasTablas('completo', 'sinPermiso');
    await iniciarSesion(page, enElAnterior(VISTA));

    await expect(page.locator(FILAS).first()).toBeVisible();
    await expect(celdaDeEstado(page, 4)).toHaveText('Baja');
    await expect(page.locator(BOTON)).toHaveCount(0);
  });

  test('T6 «Ver» una relación de baja abre su última versión con un aviso y sin botones de escritura', {
    tag: ['@estado-actual', '@relaciones', '@listado', '@estados'],
    annotation: [
      { type: 'Comportamiento', description: 'La fila de una relación de baja no ofrece «Modificar». Verla enseña la última versión que tuvo y dice que está de baja.' },
    ],
  }, async ({ page }) => {
    dejarLasTablas('completo');
    await iniciarSesion(page, enElAnterior(VISTA));

    await expect(filaDeLaRelacion(page, 4).locator('a[title="Modificar"]')).toHaveCount(0);
    await expect(filaDeLaRelacion(page, 1).locator('a[title="Modificar"]')).toHaveCount(1);
    await filaDeLaRelacion(page, 4).locator('a[title="Ver"]').click();

    await expect(page).toHaveURL(/relacion\.php\?id=4&accion=ver/);
    await expect(page.locator('body')).toContainText('de baja');
    await expect(page.locator('body')).toContainText('Paletilla de conejo');
    await expect(page.locator('#botonGuardar, #botonActivar, #botonCancelar')).toHaveCount(0);
  });

  test('T7 reactivar con otra igual vigente se avisa, nombra a la otra y la relación sigue de baja', {
    tag: ['@estado-actual', '@relaciones', '@listado', '@estados'],
  }, async ({ page }) => {
    dejarLasTablas('completo');
    preparar(['repetida', '4']);
    await iniciarSesion(page, enElAnterior(VISTA));
    const mensajes = atenderDialogos(page);

    await marcar(page, [4]);
    await page.locator(BOTON).click();

    await expect.poll(() => mensajes.length).toBeGreaterThanOrEqual(2);
    expect(mensajes.join(' ')).toMatch(/la 4[7-9]|la [5-9]\d/);
    await expect(celdaDeEstado(page, 4)).toHaveText('Baja');
  });
});
