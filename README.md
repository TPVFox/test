# TPVFox — Suite de pruebas

Pruebas de TPVFox en tres niveles: **PHPUnit** (PHP), **Jest** (JS) y **Playwright** (E2E).

## Por qué está en un repositorio aparte

`TPVFox` se despliega tal cual: no hay paso de empaquetado, de modo que todo lo que contiene
acaba en el servidor de cada instalación. Por eso el repositorio principal lleva únicamente lo
que se ejecuta en producción, y las pruebas viven aquí.

Se ejecutan contra un clon de `TPVFox` situado como repositorio hermano. Es una consecuencia de
cómo se distribuye hoy el proyecto; si algún día el despliegue incorpora empaquetado, deja de
hacer falta.

## Los tres niveles

| Nivel | Herramienta | Qué verifica | Requiere |
| --- | --- | --- | --- |
| `Unit/PHP` | PHPUnit | Funciones y clases aisladas: cálculo, validación, saneado | Nada |
| `Unit/JS` | Jest (`node`) | Lógica JS pura: cálculo de líneas, formato, validación | Node |
| `Integration/PHP` | PHPUnit | Consultas y flujos que tocan base de datos | Base de pruebas |
| `Integration/JS` | Jest (`jsdom`) | Módulos JS contra el DOM, sin navegador real | Node |
| `E2E` | Playwright | Recorridos en navegador real: sesión, AJAX, impresión | Aplicación en marcha |

**Criterio de pertenencia**: un caso baja al nivel más simple que pueda demostrarlo. Si no
necesita base de datos, es unitario; si no necesita navegador, no es E2E.

**`Integration/JS` está vacía** y `Unit/JS` tiene un solo fichero: el JavaScript del producto
está hoy esencialmente sin probar. Se dice aquí para que nadie lo deduzca de un verde.

## Puesta en marcha

```bash
git clone <url-de-test> test
git clone <url-de-TPVFox> TPVFox     # repositorio hermano, al lado de test/

cd test && composer install && npm install
npx playwright install               # añade --with-deps si faltan librerías del sistema

npm run test:php                     # 96 casos, 2 en rojo — no necesitan base de datos
npm run test:js                      # 19 casos, 2 en rojo
```

**Esos rojos son deliberados**: son casos que afirman lo que el producto debería hacer y hoy no
hace. La suite no está verde a propósito, y cada rojo está documentado.

Los otros dos niveles necesitan base de datos y un usuario de la aplicación.
**→ [docs/instalacion.md](docs/instalacion.md)**

## Órdenes

| Orden | Qué hace |
| --- | --- |
| `npm run test:php` | Unitario PHP |
| `npm run test:php:int` | Integración PHP — requiere base de datos |
| `npm run test:js` | Unitario JS |
| `npm run test:js:int` | Integración JS |
| `npm run test:e2e` | Recorridos de navegador — requiere la aplicación en marcha |
| `npm run test:e2e:ui` | Lo mismo, con la interfaz de Playwright para depurar |
| `npm run cobertura -- <ámbito>` | Cobertura sobre el ámbito que se declare |
| `npm run informe:pruebas` | Genera el informe consultable de los tres niveles |
| `npm run informe:ver` | Lo sirve en `http://127.0.0.1:8081` |
| `npm run entorno:preparar` | Deja la máquina lista de una vez: esquema, tienda, usuario y siembra |
| `npm run bases:preparar` | Solo el esquema de las dos bases |
| `npm run escenarios:sembrar` | Siembra persistente de los escenarios que cruzan de un ejercicio a otro |
| `npm run esfuerzo:medir` | Siembra de volumen y medida de los tres tramos que más cuestan |

## Documentación

| | |
| --- | --- |
| [docs/instalacion.md](docs/instalacion.md) | Del clon a la primera ejecución |
| [docs/escribir-pruebas.md](docs/escribir-pruebas.md) | Dónde va un caso, cómo se siembra y cómo se anota |
| [docs/informe.md](docs/informe.md) | Qué enseña el informe y qué no puede enseñar |
| [docs/instrumentacion.md](docs/instrumentacion.md) | Cómo está hecho, para quien tenga que tocarlo |

El porqué de cada pieza está en el docblock de su clase, no aquí.

## Versiones fijadas

**Playwright 1.61.1** sobre **Node 18.19.x**, **Jest 29.7**, **PHPUnit 9.6**. `composer.lock` y
`package-lock.json` se versionan: son lo que hace reproducible una ejecución.

**No se sube a Playwright 1.62** porque exige Node ≥ 20 y lo que aporta no toca a esta suite: el
modelo nuevo de component testing no aplica —las pantallas de TPVFox se componen en servidor— y el
resto de API nueva no aparece en ningún recorrido. Queda el salto de versión de los navegadores,
pero lo que se prueba es una aplicación servida: el navegador no es el objeto de la prueba.
