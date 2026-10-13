/**
 * El listado de productos relacionados: una fila por relación que rige, y cómo se acota con
 * la búsqueda, el filtro por tipo, el orden y las páginas.
 *
 * Parte de la siembra de `support/sembrar-e2e-relaciones.php`: cuarenta y cinco relaciones en
 * dos páginas, una relación dada de baja y otra que solo tiene un borrador. Los últimos
 * recorridos cambian el estado de las tablas —sin relaciones, sin tipos, sin instalar— y lo
 * devuelven al terminar, por eso el fichero corre en serie.
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const { iniciarSesion } = require('../../fixtures/autenticacion');

const VISTA = 'modulos/mod_relaciones/ListaRelaciones.php';
const FILAS = '#tablaRelaciones tbody tr';

function dejarLasTablas(estado) {
  execFileSync('php', [path.resolve(__dirname, '../../../support/sembrar-e2e-relaciones.php'), estado], { stdio: 'pipe' });
}

test.describe.configure({ mode: 'serial' });

test.describe('Productos relacionados — listado', () => {
  test.afterAll(() => {
    dejarLasTablas('completo');
  });

  test('T1 cada relación ocupa una fila con su número, nombre, tipo, versión, fecha y productos', {
    tag: ['@estado-actual', '@relaciones', '@listado'],
    annotation: [
      { type: 'Comportamiento', description: 'El listado es de relaciones, como el de albaranes es de albaranes: una fila por relación que rige, no una por producto.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, VISTA + '?buscar=Pollo+entero');

    await expect(page.locator('#tablaRelaciones thead th')).toHaveText(['', '', '', /^Nº/, 'RELACIÓN', 'TIPO', 'VERSIÓN', /^FECHA/, 'PRODUCTOS']);
    await expect(page.locator(FILAS)).toHaveCount(1);
    await expect(page.locator(FILAS + ' td')).toHaveText(['', '', '', '1', 'Pollo entero', 'Despiece', '1.0', '2026-03-01', '3']);
  });

  test('T2 una relación sin nombre se presenta con el del producto que se elabora', {
    tag: ['@estado-actual', '@relaciones', '@listado'],
  }, async ({ page }) => {
    await iniciarSesion(page, VISTA + '?filtro=Elaboraci%C3%B3n');

    await expect(page.locator(FILAS)).toHaveCount(1);
    await expect(page.locator(FILAS + ' td').nth(4)).toHaveText('[E2E relaciones] Hamburguesa');
  });

  test('T3 buscar un producto que solo es ingrediente encuentra su relación', {
    tag: ['@estado-actual', '@relaciones', '@listado', '@busqueda'],
    annotation: [
      { type: 'Comportamiento', description: 'La búsqueda casa con el nombre de la relación y con el de cualquiera de sus productos, entren o salgan. Así se sabe desde el listado en qué relaciones está un producto.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, VISTA);

    await page.fill('form[name="formBuscar"] input[name="buscar"]', 'cerdo picado');
    await Promise.all([
      page.waitForURL(/buscar=/),
      page.locator('form[name="formBuscar"] input[type="submit"]').click(),
    ]);

    await expect(page.locator(FILAS)).toHaveCount(1);
    await expect(page.locator('#relacionesEncontradas')).toHaveText('1');
    await expect(page.locator(FILAS)).toContainText('Elaboración');
  });

  test('T4 una relación dada de baja y una que solo tiene borrador no aparecen', {
    tag: ['@estado-actual', '@relaciones', '@listado', '@estados'],
    annotation: [
      { type: 'Comportamiento', description: 'Solo se listan las relaciones que rigen: ni la dada de baja ni la que todavía es un borrador, tampoco al buscar por sus productos.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, VISTA + '?buscar=Conejo');
    await expect(page.locator(FILAS)).toHaveCount(0);
    await expect(page.locator('.alert-warning')).toContainText('Ninguna relación casa con lo buscado');

    await page.goto(VISTA + '?buscar=Pavo');
    await expect(page.locator(FILAS)).toHaveCount(0);
  });

  test('T5 filtrar por tipo deja solo las relaciones de ese tipo', {
    tag: ['@estado-actual', '@relaciones', '@listado'],
  }, async ({ page }) => {
    await iniciarSesion(page, VISTA);

    await Promise.all([
      page.waitForURL(/filtro=Cruce/),
      page.selectOption('form[name="formFiltrar"] select', 'Cruce'),
    ]);

    await expect(page.locator(FILAS)).toHaveCount(1);
    await expect(page.locator(FILAS)).toContainText('Naranja de mesa');
  });

  test('T6 las cuarenta y cinco relaciones se reparten en dos páginas', {
    tag: ['@estado-actual', '@relaciones', '@listado'],
    annotation: [
      { type: 'Comportamiento', description: 'El recuento es el de todas las relaciones, no el de la página. Lo que no cabe en la primera está en la segunda.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, VISTA);
    await expect(page.locator('#relacionesEncontradas')).toHaveText('45');
    await expect(page.locator(FILAS)).toHaveCount(40);

    await page.goto(VISTA + '?pagina=2');
    await expect(page.locator(FILAS)).toHaveCount(5);
  });

  test('T7 el listado sale por número de relación y se puede ordenar por número o por fecha', {
    tag: ['@estado-actual', '@relaciones', '@listado'],
  }, async ({ page }) => {
    const primera = page.locator(FILAS).first();

    await iniciarSesion(page, VISTA);
    await expect(primera).toContainText('Pollo entero');

    await page.goto(VISTA + '?orden=numRelacion:DESC');
    await expect(primera).toContainText('Lote 42');

    await page.goto(VISTA + '?orden=fecha:ASC');
    await expect(primera).toContainText('2026-01-10');

    await page.goto(VISTA + '?orden=fecha:DESC');
    await expect(primera).toContainText('Lote 42');
  });

  test('T8 una página que no existe muestra la última que hay', {
    tag: ['@estado-actual', '@relaciones', '@listado'],
    annotation: [
      { type: 'Comportamiento', description: 'Quien llega con un número de página que ya no existe ve la última, no un listado vacío.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, VISTA + '?pagina=9999');

    await expect(page.locator(FILAS)).toHaveCount(5);
  });

  test('T9 los parámetros manipulados en la dirección no rompen la página ni se ejecutan', {
    tag: ['@estado-actual', '@relaciones', '@listado', '@validacion'],
    annotation: [
      { type: 'Comportamiento', description: 'Una página que no es un número se trata como la primera. Un texto con marcas no se ejecuta en el navegador ni aparece tal cual en los enlaces del paginador.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, VISTA);

    const conPaginaRara = await page.goto(VISTA + '?pagina=abc&orden=inventado&filtro=inventado');
    expect(conPaginaRara.status()).toBe(200);
    await expect(page.locator(FILAS)).toHaveCount(40);

    const conMarcas = await page.goto(VISTA + '?buscar=' + encodeURIComponent('"><script>window.inyectado = 1</script>') + '&orden=' + encodeURIComponent('"><script>window.inyectado = 1</script>'));
    expect(conMarcas.status()).toBe(200);
    expect(await page.evaluate(() => window.inyectado)).toBeUndefined();
    expect(await page.content()).not.toContain('<script>window.inyectado');
    await expect(page.locator('h2')).toHaveText('Productos relacionados');
  });

  test('T13 las acciones tienen su sitio, como en los demás listados, y están desactivadas', {
    tag: ['@estado-actual', '@relaciones', '@listado'],
    annotation: [
      { type: 'Comportamiento', description: 'El listado reserva el sitio de añadir, ver y modificar y el selector de cada fila, igual que el de albaranes. Mientras no exista la pantalla de relación están desactivados: ninguno lleva a ninguna parte ni envía nada.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, VISTA);

    const opciones = page.locator('.col-sm-3 button');
    await expect(opciones).toHaveText(['Añadir', 'Ver', 'Modificar']);
    for (const boton of await opciones.all()) {
      await expect(boton).toBeDisabled();
    }
    await expect(page.locator(FILAS + ' input.Check').first()).toBeDisabled();
    await expect(page.locator('#tablaRelaciones tbody a')).toHaveCount(0);
  });

  test('T14 al cambiar de página se conservan el orden, la búsqueda y el filtro', {
    tag: ['@estado-actual', '@relaciones', '@listado'],
    annotation: [
      { type: 'Comportamiento', description: 'Los enlaces del paginador llevan el orden pedido, lo buscado y el tipo filtrado. Sin eso, pasar a la segunda página devolvería el listado a su orden de partida.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, VISTA + '?orden=numRelacion:DESC');
    await expect(page.locator(FILAS).first()).toContainText('Lote 42');

    await page.locator('ul.pagination a', { hasText: 'Ultima' }).click();

    await expect(page).toHaveURL(/orden=numRelacion:DESC/);
    await expect(page).toHaveURL(/pagina=2/);
    await expect(page.locator(FILAS)).toHaveCount(5);
    await expect(page.locator(FILAS).first()).toContainText('Lote 02');
    await expect(page.locator(FILAS).last()).toContainText('Pollo entero');

    await page.locator('ul.pagination a', { hasText: 'Inicio' }).click();

    await expect(page).toHaveURL(/orden=numRelacion:DESC/);
    await expect(page).toHaveURL(/pagina=1$/);
    await expect(page.locator(FILAS)).toHaveCount(40);
    await expect(page.locator(FILAS).first()).toContainText('Lote 42');

    await page.goto(VISTA + '?buscar=Lote&filtro=Despiece&orden=fecha:ASC');
    await page.locator('ul.pagination a', { hasText: 'Ultima' }).click();

    await expect(page).toHaveURL(/buscar=Lote/);
    await expect(page).toHaveURL(/filtro=Despiece/);
    await expect(page).toHaveURL(/orden=fecha:ASC/);
    await expect(page.locator(FILAS)).toHaveCount(2);
    await expect(page.locator(FILAS).last()).toContainText('Lote 42');
  });

  test('T10 sin relaciones el listado sale sin filas y lo dice', {
    tag: ['@estado-actual', '@relaciones', '@listado', '@instalacion'],
    annotation: [
      { type: 'Comportamiento', description: 'Es el estado en que el módulo llega a una tienda: con sus tablas vacías se presenta un listado sin filas y un aviso, no un error.' },
    ],
  }, async ({ page }) => {
    dejarLasTablas('sinRelaciones');
    await iniciarSesion(page, VISTA);

    await expect(page.locator(FILAS)).toHaveCount(0);
    await expect(page.locator('.alert-info')).toContainText('No hay ninguna relación entre productos');
  });

  test('T11 sin ningún tipo de relación creado, el listado lo indica', {
    tag: ['@estado-actual', '@relaciones', '@listado', '@instalacion'],
  }, async ({ page }) => {
    dejarLasTablas('sinTipos');
    await iniciarSesion(page, VISTA);

    await expect(page.locator(FILAS)).toHaveCount(0);
    await expect(page.locator('.alert-info')).toContainText('Todavía no hay ningún tipo de relación');
  });

  test('T12 sin las tablas instaladas la vista avisa y no falla', {
    tag: ['@estado-actual', '@relaciones', '@listado', '@instalacion'],
    annotation: [
      { type: 'Comportamiento', description: 'El código puede llegar a una instalación antes que sus tablas. La vista lo detecta y lo dice, en lugar de terminar en un error de la base.' },
    ],
  }, async ({ page }) => {
    dejarLasTablas('sinEstructura');
    await iniciarSesion(page, VISTA);
    const respuesta = await page.goto(VISTA);

    expect(respuesta.status()).toBe(200);
    await expect(page.locator('.alert-warning')).toContainText('no están instaladas');
    await expect(page.locator(FILAS)).toHaveCount(0);
  });
});
