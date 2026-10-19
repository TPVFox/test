/**
 * El listado de productos relacionados: una fila por relación que rige, y cómo se acota con
 * la búsqueda, el filtro por tipo, el orden y las páginas.
 *
 * Parte de la siembra de `support/sembrar-e2e-relaciones.php`: cuarenta y cinco relaciones en
 * dos páginas, una relación dada de baja y otra que solo tiene un borrador.
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const { iniciarSesion } = require('../../fixtures/autenticacion');

const VISTA = 'modulos/mod_relaciones/ListaRelaciones.php';
const FILAS = '#tablaRelaciones tbody tr';

const URL_ANTERIOR = process.env.TPVFOX_URL_ANTERIOR;

function conBarraFinal(url) {
  return url.endsWith('/') ? url : url + '/';
}

function dejarLasTablas(estado, ejercicio, permiso = 'conPermiso') {
  execFileSync('php', [path.resolve(__dirname, '../../../support/sembrar-e2e-relaciones.php'), estado, ejercicio, permiso], { stdio: 'pipe' });
}

function prepararRelacion(argumentos) {
  execFileSync('php', [path.resolve(__dirname, '../../../support/preparar-e2e-cambio-estado.php'), ...argumentos], { stdio: 'pipe' });
}

test.describe.configure({ mode: 'serial' });

test.describe('Productos relacionados — listado', () => {
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

  test('T13 añadir, ver y modificar abren la vista de relación; la casilla de cada fila y «Cambiar estado» funcionan', {
    tag: ['@estado-actual', '@relaciones', '@listado'],
    annotation: [
      { type: 'Comportamiento', description: 'Las opciones generales están donde las tienen los listados de documentos: añadir, ver y modificar abren la vista de relación, cada una con su permiso, y cada fila lleva sus accesos a editarla y a verla. La casilla de cada fila se puede marcar, y «Cambiar estado» está disponible para quien tiene su permiso.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, VISTA + '?buscar=Pollo+entero');

    for (const nombre of ['Añadir', 'Ver', 'Modificar', 'Cambiar estado']) {
      await expect(page.locator('.col-sm-3 .btn', { hasText: nombre })).toBeEnabled();
    }
    await expect(page.locator('.col-sm-3 a.btn', { hasText: 'Añadir' })).toHaveAttribute('href', /relacion\.php$/);
    await expect(page.locator(FILAS + ' input.Check').first()).toBeEnabled();
    await expect(page.locator(FILAS).first().locator('a[href$="relacion.php?id=1&accion=editar"]')).toHaveCount(1);
    await expect(page.locator(FILAS).first().locator('a[href$="relacion.php?id=1&accion=ver"]')).toHaveCount(1);
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
});

// Los tres estados sin datos se piden sobre el despliegue del ejercicio anterior: es el mismo
// código con otra base, que ningún otro recorrido usa para las relaciones. Sobre el vigente,
// vaciar las tablas se las quitaría a los recorridos que corren a la vez.
test.describe('Productos relacionados — listado sin datos', () => {
  const vistaEnElAnterior = () => new URL(VISTA, conBarraFinal(URL_ANTERIOR)).toString();

  test.beforeEach(() => {
    test.skip(!URL_ANTERIOR, 'El entorno no declara TPVFOX_URL_ANTERIOR.');
  });

  test.afterAll(() => {
    if (URL_ANTERIOR) {
      dejarLasTablas('sinTipos', 'anterior');
    }
  });

  test('T10 sin relaciones el listado sale sin filas y lo dice', {
    tag: ['@estado-actual', '@relaciones', '@listado', '@instalacion'],
    annotation: [
      { type: 'Comportamiento', description: 'Con tipos creados y ninguna relación se presenta un listado sin filas y un aviso, no un error.' },
    ],
  }, async ({ page }) => {
    dejarLasTablas('sinRelaciones', 'anterior');
    await iniciarSesion(page, vistaEnElAnterior());

    await expect(page.locator(FILAS)).toHaveCount(0);
    await expect(page.locator('.alert-info')).toContainText('No hay ninguna relación entre productos');
  });

  test('T11 sin ningún tipo de relación creado, el listado lo indica', {
    tag: ['@estado-actual', '@relaciones', '@listado', '@instalacion'],
    annotation: [
      { type: 'Comportamiento', description: 'Es el estado en que el módulo llega a una tienda: sus tablas vacías. El listado dice que faltan los tipos y dónde se crean.' },
    ],
  }, async ({ page }) => {
    dejarLasTablas('sinTipos', 'anterior');
    await iniciarSesion(page, vistaEnElAnterior());

    await expect(page.locator(FILAS)).toHaveCount(0);
    await expect(page.locator('.alert-info')).toContainText('Todavía no hay ningún tipo de relación');
  });

  test('T12 sin las tablas instaladas la vista avisa y no falla', {
    tag: ['@estado-actual', '@relaciones', '@listado', '@instalacion'],
    annotation: [
      { type: 'Comportamiento', description: 'El código puede llegar a una instalación antes que sus tablas. La vista lo detecta y lo dice, en lugar de terminar en un error de la base.' },
    ],
  }, async ({ page }) => {
    dejarLasTablas('sinEstructura', 'anterior');
    await iniciarSesion(page, vistaEnElAnterior());
    const respuesta = await page.goto(vistaEnElAnterior());

    expect(respuesta.status()).toBe(200);
    await expect(page.locator('.alert-warning')).toContainText('no están instaladas');
    await expect(page.locator(FILAS)).toHaveCount(0);
  });
});

// Cambiar el estado escribe: se hace sobre el despliegue del ejercicio anterior, como los
// estados sin datos, para no quitar relaciones a los recorridos que corren a la vez sobre el
// vigente.
test.describe('Productos relacionados — cambiar el estado desde el listado', () => {
  const vistaEnElAnterior = (consulta = '') => new URL(VISTA + consulta, conBarraFinal(URL_ANTERIOR)).toString();
  const BOTON = '.col-sm-3 button:has-text("Cambiar estado")';

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

  test.beforeEach(() => {
    test.skip(!URL_ANTERIOR, 'El entorno no declara TPVFOX_URL_ANTERIOR.');
  });

  test.afterAll(() => {
    if (URL_ANTERIOR) {
      dejarLasTablas('sinTipos', 'anterior');
    }
  });

  test('T15 el administrador marca dos relaciones, confirma y dejan de aparecer', {
    tag: ['@estado-actual', '@relaciones', '@listado', '@permisos'],
    annotation: [
      { type: 'Comportamiento', description: 'Como «Cambiar estado» en los listados de compras: se marcan las filas, se pulsa la opción general y se confirma. La pregunta nombra cada relación, con su número, y el estado al que pasa. Tras confirmar, el listado se recarga sin ellas.' },
    ],
  }, async ({ page }) => {
    dejarLasTablas('completo', 'anterior');
    await iniciarSesion(page, vistaEnElAnterior());
    const mensajes = atenderDialogos(page);

    await marcar(page, [1, 2]);
    await page.locator(BOTON).click();

    await expect(page.locator('#relacionesEncontradas')).toHaveText('43');
    expect(mensajes).toHaveLength(1);
    expect(mensajes[0]).toContain('Baja');
    expect(mensajes[0]).toContain('1 Pollo entero');
    expect(mensajes[0]).toContain('2 [E2E relaciones] Hamburguesa');
    await expect(filaDeLaRelacion(page, 1)).toHaveCount(0);
    await expect(filaDeLaRelacion(page, 2)).toHaveCount(0);
  });

  test('T16 sin ninguna relación marcada, avisa y no envía nada', {
    tag: ['@estado-actual', '@relaciones', '@listado', '@permisos'],
  }, async ({ page }) => {
    dejarLasTablas('completo', 'anterior');
    await iniciarSesion(page, vistaEnElAnterior());
    const mensajes = atenderDialogos(page);
    const peticiones = peticionesDeCambio(page);

    await page.locator(BOTON).click();

    await expect.poll(() => mensajes.length).toBe(1);
    expect(mensajes[0]).toBe('No tienes items seleccionados');
    expect(peticiones).toHaveLength(0);
    await expect(page.locator('#relacionesEncontradas')).toHaveText('45');
  });

  test('T17 sin el permiso de cambiar el estado, la opción no aparece', {
    tag: ['@estado-actual', '@relaciones', '@listado', '@permisos'],
    annotation: [
      { type: 'Comportamiento', description: 'Quien ve el listado pero no tiene el permiso de cambiar el estado no encuentra la opción. El permiso es de la pantalla desde la que se hace el cambio.' },
    ],
  }, async ({ page }) => {
    dejarLasTablas('completo', 'anterior', 'sinPermiso');
    await iniciarSesion(page, vistaEnElAnterior());

    await expect(page.locator(FILAS).first()).toBeVisible();
    await expect(page.locator(BOTON)).toHaveCount(0);
  });

  test('T18 si no se confirma, no se envía nada y las relaciones siguen', {
    tag: ['@estado-actual', '@relaciones', '@listado'],
  }, async ({ page }) => {
    dejarLasTablas('completo', 'anterior');
    await iniciarSesion(page, vistaEnElAnterior());
    const mensajes = atenderDialogos(page, false);
    const peticiones = peticionesDeCambio(page);

    await marcar(page, [1]);
    await page.locator(BOTON).click();

    await expect.poll(() => mensajes.length).toBe(1);
    expect(peticiones).toHaveLength(0);
    await expect(page.locator('#relacionesEncontradas')).toHaveText('45');
    await expect(filaDeLaRelacion(page, 1)).toHaveCount(1);
  });

  test('T19 una relación con un borrador pendiente se avisa antes de recargar y las demás se dan de baja', {
    tag: ['@estado-actual', '@relaciones', '@listado'],
    annotation: [
      { type: 'Comportamiento', description: 'La baja no descarta el cambio que alguien está preparando: esa relación no se da de baja, y su aviso se ve antes de que el listado se recargue. Un cambio a medias no pasa por completo.' },
    ],
  }, async ({ page }) => {
    dejarLasTablas('completo', 'anterior');
    prepararRelacion(['borrador', '1', 'anterior']);
    await iniciarSesion(page, vistaEnElAnterior());
    const mensajes = atenderDialogos(page);

    await marcar(page, [1, 2]);
    await page.locator(BOTON).click();

    await expect(page.locator('#relacionesEncontradas')).toHaveText('44');
    expect(mensajes).toHaveLength(2);
    expect(mensajes[1]).toContain('borrador pendiente');
    await expect(filaDeLaRelacion(page, 1)).toHaveCount(1);
    await expect(filaDeLaRelacion(page, 2)).toHaveCount(0);
  });

  test('T20 si se retiran todas las filas de la última página, la recarga muestra la última que queda', {
    tag: ['@estado-actual', '@relaciones', '@listado'],
  }, async ({ page }) => {
    dejarLasTablas('completo', 'anterior');
    await iniciarSesion(page, vistaEnElAnterior('?pagina=2'));
    atenderDialogos(page);

    for (const casilla of await page.locator(FILAS + ' input.Check').all()) {
      await casilla.check();
    }
    await page.locator(BOTON).click();

    await expect(page.locator('#relacionesEncontradas')).toHaveText('40');
    await expect(page.locator(FILAS)).toHaveCount(40);
  });

  test('T21 un nombre con comillas o marcas se pregunta tal cual y no rompe la página', {
    tag: ['@estado-actual', '@relaciones', '@listado', '@validacion'],
    annotation: [
      { type: 'Comportamiento', description: 'El nombre que la pregunta muestra lo deja el servidor, escapado, en la propia casilla: se lee como texto, sin ejecutarse ni cortar el atributo.' },
    ],
  }, async ({ page }) => {
    const nombre = 'Pollo "entero" <b>&';
    dejarLasTablas('completo', 'anterior');
    prepararRelacion(['nombre', '1', nombre, 'anterior']);
    await iniciarSesion(page, vistaEnElAnterior());
    const mensajes = atenderDialogos(page, false);

    await marcar(page, [1]);
    await page.locator(BOTON).click();

    await expect.poll(() => mensajes.length).toBe(1);
    expect(mensajes[0]).toContain('1 ' + nombre);
    await expect(filaDeLaRelacion(page, 1).locator('td').nth(4)).toHaveText(nombre);
    await expect(filaDeLaRelacion(page, 1).locator('b')).toHaveCount(0);
  });
});

// Abrir la vista de relación desde el listado, y las relaciones abiertas: sobre el despliegue
// del ejercicio anterior, porque los casos dejan cambios en preparación.
test.describe('Productos relacionados — abrir la vista de relación desde el listado', () => {
  const vistaEnElAnterior = (consulta = '') => new URL(VISTA + consulta, conBarraFinal(URL_ANTERIOR)).toString();
  const ABIERTAS = '#relacionesAbiertas tbody tr';

  function filaDeLaRelacion(page, numero) {
    return page.locator(FILAS).filter({ has: page.locator('td:nth-child(4)', { hasText: new RegExp('^' + numero + '$') }) });
  }

  function prepararVista(argumentos) {
    execFileSync('php', [path.resolve(__dirname, '../../../support/preparar-e2e-vista-relacion.php'), ...argumentos, 'anterior'], { stdio: 'pipe' });
  }

  function atenderDialogos(page) {
    const mensajes = [];
    page.on('dialog', async (dialogo) => {
      mensajes.push(dialogo.message());
      await dialogo.accept();
    });
    return mensajes;
  }

  test.beforeEach(() => {
    test.skip(!URL_ANTERIOR, 'El entorno no declara TPVFOX_URL_ANTERIOR.');
  });

  test.afterAll(() => {
    if (URL_ANTERIOR) {
      dejarLasTablas('sinTipos', 'anterior');
    }
  });

  test('T22 «Ver» y «Modificar» abren la relación marcada, y avisan si no hay una sola', {
    tag: ['@estado-actual', '@relaciones', '@listado'],
    annotation: [
      { type: 'Comportamiento', description: 'Como en los listados de compras: ver y modificar actúan sobre una sola fila marcada. Sin ninguna, o con más de una, avisan y no abren nada.' },
    ],
  }, async ({ page }) => {
    dejarLasTablas('completo', 'anterior');
    await iniciarSesion(page, vistaEnElAnterior());
    const mensajes = atenderDialogos(page);

    await page.locator('.col-sm-3 .btn', { hasText: 'Ver' }).click();
    await filaDeLaRelacion(page, 1).locator('input.Check').check();
    await filaDeLaRelacion(page, 2).locator('input.Check').check();
    await page.locator('.col-sm-3 .btn', { hasText: 'Modificar' }).click();
    await expect.poll(() => mensajes.length).toBe(2);
    expect(mensajes[0]).toContain('una sola');
    expect(mensajes[1]).toContain('una sola');
    await expect(page).toHaveURL(/ListaRelaciones\.php/);

    await filaDeLaRelacion(page, 2).locator('input.Check').uncheck();
    await page.locator('.col-sm-3 .btn', { hasText: 'Ver' }).click();
    await expect(page).toHaveURL(/relacion\.php\?id=1&accion=ver/);
  });

  test('T23 las relaciones abiertas dicen quién y cuándo, y se retoman desde ahí', {
    tag: ['@estado-actual', '@relaciones', '@listado'],
    annotation: [
      { type: 'Comportamiento', description: 'Como los albaranes abiertos de compras: cada cambio en preparación, con el número de su relación, quién lo guardó por última vez y cuándo. Pulsarlo abre la vista de relación con él.' },
    ],
  }, async ({ page }) => {
    dejarLasTablas('completo', 'anterior');
    prepararVista(['temporal', '1']);
    await iniciarSesion(page, vistaEnElAnterior());

    await expect(page.locator(ABIERTAS)).toHaveCount(1);
    await expect(page.locator(ABIERTAS)).toContainText('1');
    await expect(page.locator(ABIERTAS)).toContainText(process.env.TPVFOX_E2E_USUARIO);
    await expect(page.locator(ABIERTAS)).toContainText('2026-10-01 10:00');
    await page.locator(ABIERTAS).first().click();
    await expect(page).toHaveURL(/relacion\.php\?tActual=\d+/);
  });

  test('T24 sin los permisos de crear y modificar no se ofrecen ni las relaciones abiertas', {
    tag: ['@estado-actual', '@relaciones', '@listado', '@permisos'],
  }, async ({ page }) => {
    dejarLasTablas('completo', 'anterior', 'sinPermiso');
    prepararVista(['temporal', '1']);
    await iniciarSesion(page, vistaEnElAnterior());

    await expect(page.locator('.col-sm-3 .btn', { hasText: 'Añadir' })).toHaveCount(0);
    await expect(page.locator('.col-sm-3 .btn', { hasText: 'Modificar' })).toHaveCount(0);
    await expect(page.locator('.col-sm-3 .btn', { hasText: 'Ver' })).toBeEnabled();
    await expect(page.locator('#relacionesAbiertas')).toHaveCount(0);
    await expect(page.locator(FILAS).first().locator('a[href*="accion=editar"]')).toHaveCount(0);
  });

  test('T25 una relación con un cambio en preparación no se da de baja y se avisa', {
    tag: ['@estado-actual', '@relaciones', '@listado'],
    annotation: [
      { type: 'Comportamiento', description: 'La baja no se lleva por delante lo que otro está preparando: esa relación sigue, con su aviso, y las demás marcadas se dan de baja.' },
    ],
  }, async ({ page }) => {
    dejarLasTablas('completo', 'anterior');
    prepararVista(['temporal', '1']);
    await iniciarSesion(page, vistaEnElAnterior());
    const mensajes = atenderDialogos(page);

    await filaDeLaRelacion(page, 1).locator('input.Check').check();
    await filaDeLaRelacion(page, 2).locator('input.Check').check();
    await page.locator('.col-sm-3 button:has-text("Cambiar estado")').click();

    await expect(page.locator('#relacionesEncontradas')).toHaveText('44');
    expect(mensajes).toHaveLength(2);
    expect(mensajes[1]).toContain('cambio en preparación');
    await expect(filaDeLaRelacion(page, 1)).toHaveCount(1);
    await expect(filaDeLaRelacion(page, 2)).toHaveCount(0);
  });
});
