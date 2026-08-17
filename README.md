# TPVFox — Suite de pruebas

Pruebas de TPVFox en tres niveles: **PHPUnit** (PHP), **Jest** (JS) y **Playwright** (E2E).

## Por qué está en un repositorio aparte

`TPVFox/TPVFox` se despliega tal cual: no existe un paso de empaquetado, de modo que todo lo
que contiene acaba en el servidor de cada instalación. Por eso el repositorio principal lleva
únicamente lo que se ejecuta en producción.

Las pruebas, sus dependencias de desarrollo y su configuración viven aquí. Se ejecutan contra
un clon de `TPVFox` situado como repositorio hermano.

Esta separación responde a cómo se distribuye hoy el proyecto, no a una preferencia de
organización. Si en el futuro el despliegue incorpora un paso de empaquetado, deja de ser
necesaria.

## Los tres niveles

| Nivel | Herramienta | Qué verifica | Requiere para ejecutarse |
| --- | --- | --- | --- |
| `Unit/PHP` | PHPUnit | Funciones y clases aisladas: cálculo, validación, saneado | Nada |
| `Unit/JS` | Jest (`node`) | Lógica JS pura: cálculo de líneas, formato, validación de entrada | Node |
| `Integration/PHP` | PHPUnit | Consultas y flujos que tocan base de datos | Base de datos de pruebas |
| `Integration/JS` | Jest (`jsdom`) | Interacción entre módulos JS y DOM, sin navegador real | Node |
| `E2E` | Playwright | Recorridos completos en navegador real: sesión, AJAX, impresión, formularios | Aplicación en marcha |

**Criterio de pertenencia**: un caso baja al nivel más simple que pueda demostrarlo. Si no
necesita base de datos, es unitario. Si no necesita navegador, no es E2E.

## Requisitos por entorno

**Unitario (PHP y JS)** — sin infraestructura. Corre en CI en cada push.

**Integración PHP** — base de datos de pruebas, desechable. Cada test se envuelve en
transacción con `ROLLBACK`: ningún dato persiste. Nunca se ejecuta contra una base de una
instalación en uso.

**E2E** — la aplicación servida y accesible, con base sembrada y un usuario conocido. Se
levanta con `npm run entorno:up` y se destruye con `npm run entorno:down`.

**Datos de prueba** — los datos de siembra se **generan**; no se extraen de una instalación
real. Ningún dato de una instalación en uso entra en este repositorio.

## Ejecución

```bash
composer install && npm install
npx playwright install --with-deps      # solo la primera vez

npm run test:php                        # unitario PHP
npm run test:js                         # unitario JS
npm run test:php:int                    # integración PHP  (requiere BD)
npm run test:js:int                     # integración JS
npm run entorno:up && npm run test:e2e  # E2E
```

El código bajo prueba se localiza en `../TPVFox` por defecto. Se puede apuntar a otra ruta con
la variable `TPVFOX_PATH` (Jest/PHPUnit) y `TPVFOX_URL` (Playwright).

## Versiones fijadas

- **Playwright 1.61.1** sobre **Node 18.19.x**. La 1.62 exige Node ≥ 20; mientras el equipo de
  ejecución siga en 18, no se sube.
- **Jest 29.7**, **PHPUnit 9.6**.

## Cobertura

Umbral **70%** en líneas, funciones y sentencias, medido sobre `modulos/` del código bajo
prueba — no sobre una lista de ficheros elegidos.
