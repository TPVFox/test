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

---

## Prerequisitos

Todo lo que hace falta, y de dónde sale. Las órdenes son de Debian y Ubuntu; en otra
distribución cambian los nombres de paquete, no la lista.

| Prerrequisito | Versión | Para qué |
| --- | --- | --- |
| PHP con `mysqli`, `libxml` y `dom` | ≥ 8.0 | Los dos niveles PHP. Por debajo de 8.0 el código de TPVFox ni siquiera analiza |
| Composer | 2.x | Instalar PHPUnit |
| Node y npm | Node ≥ 18.19 y < 20 | Jest y Playwright |
| MariaDB o MySQL | MariaDB ≥ 10.0 / MySQL ≥ 5.6 | Las pruebas de integración. Es requisito de TPVFox igualmente |

```bash
sudo apt install php-cli php-mysql php-xml php-mbstring composer mariadb-server
```

Node conviene instalarlo con un gestor de versiones, porque la horquilla es estrecha:

```bash
# con nvm
nvm install 18.19.1 && nvm use 18.19.1
node -v          # ha de decir v18.19.x
```

Comprobación rápida de que la máquina cumple:

```bash
php -v
php -r 'foreach (["mysqli","libxml","dom"] as $e) printf("%-8s %s\n", $e, extension_loaded($e) ? "ok" : "FALTA");'
composer -V
node -v && npm -v
mariadb --version
```

## Puesta en marcha

```bash
git clone <url-de-test> test
git clone <url-de-TPVFox> TPVFox     # repositorio hermano, al lado de test/

cd test
composer install
npm install
npx playwright install               # navegadores; añade --with-deps si faltan librerías del sistema
```

El código bajo prueba se localiza en `../TPVFox` por defecto. Se puede apuntar a otra ruta con
`TPVFOX_PATH` (Jest y PHPUnit) y `TPVFOX_URL` (Playwright).

Con esto ya corren los niveles unitarios. La integración y el E2E necesitan base de datos.

## La base de pruebas

La integración corre sobre **dos bases de ejercicios consecutivos**, porque hay comportamiento
del producto que compara un ejercicio con el anterior. Cada ejercicio de TPVFox vive en su
propia base y el ejercicio no es un parámetro: es una propiedad del despliegue.

**Las bases de pruebas son propias y su nombre empieza por `tpvfox_test`.** La suite se niega a
arrancar contra cualquier otro nombre: un mismo motor puede alojar bases que no son de pruebas, y
una variable de entorno mal puesta no puede bastar para escribir sobre una de ellas.

### 1. Crear las bases y concederlas

Requiere privilegios de administración del motor, así que no lo hace ningún guion de este
repositorio. Una sola vez:

```sql
CREATE DATABASE tpvfox_test_2025 CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE DATABASE tpvfox_test_2026 CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
GRANT ALL PRIVILEGES ON tpvfox_test_2025.* TO 'tpvfox'@'localhost';
GRANT ALL PRIVILEGES ON tpvfox_test_2026.* TO 'tpvfox'@'localhost';
FLUSH PRIVILEGES;
```

Los años son un ejemplo: sirve cualquier par consecutivo.

### 2. Declarar la configuración

`test/.env`, que no se versiona:

```ini
TPVFOX_TEST_DB_HOST=localhost
TPVFOX_TEST_DB_USER=tpvfox
TPVFOX_TEST_DB_PASS=<contraseña>
TPVFOX_TEST_DB_VIGENTE=tpvfox_test_2026
TPVFOX_TEST_DB_ANTERIOR=tpvfox_test_2025
```

Las mismas claves valen como variables de entorno, y ahí ganan al fichero: en integración
continua no hace falta `.env`.

### 3. Cargar el esquema

```bash
npm run bases:preparar              # respeta lo que ya haya
npm run bases:preparar -- --rehacer # tira los objetos y vuelve a cargar
```

Carga el esquema de referencia de `BD/BDtpv/` del clon de TPVFox: 74 tablas y las 4 vistas, sin
datos. **Los datos de siembra se generan**; no se extraen de ninguna instalación real, y ninguno
entra en este repositorio.

### Aislamiento entre casos

Cada caso se envuelve en transacción con `ROLLBACK`: ningún dato persiste.

La excepción son los casos que ejercitan la apertura de transacción del propio producto. En
MySQL y MariaDB un `START TRANSACTION` dentro de otro confirma el anterior de forma implícita,
de modo que ahí la transacción de la suite dejaría de aislar sin avisar. Esos casos ponen
`$this->aislarPorTransaccion = false` y limpian lo que siembren.

## Entorno con contenedor (opcional)

`entorno/docker-compose.yml` levanta el motor y la aplicación sin tocar la máquina. Es una
comodidad para empezar rápido o para trabajar sin instalar nada, **no el entorno de referencia**:
el motor instalado es requisito de TPVFox de todos modos.

```bash
npm run entorno:up
npm run entorno:down     # -v incluido: destruye también los volúmenes
```

## Ejecución

```bash
npm run test:php                        # unitario PHP
npm run test:js                         # unitario JS
npm run test:php:int                    # integración PHP  (requiere BD)
npm run test:js:int                     # integración JS
npm run entorno:up && npm run test:e2e  # E2E
```

## Versiones fijadas

- **Playwright 1.61.1** sobre **Node 18.19.x**, **Jest 29.7**, **PHPUnit 9.6**.
- `composer.lock` y `package-lock.json` se versionan: son lo que hace reproducible una ejecución.

**Por qué no se sube a Playwright 1.62.** Exige Node ≥ 20, y lo que aporta no toca a esta suite:
el modelo nuevo de component testing no aplica —las pantallas de TPVFox se componen en
servidor—, y `AbortSignal`, capturas WebP, `reporter.preprocess()`, `retryStrategy` y el resto de
API nueva no aparecen en ningún recorrido. Queda el salto de versión de los navegadores, pero lo
que se prueba es una aplicación servida: el navegador no es el objeto de la prueba. Cuando el
equipo de ejecución pase a Node 20 se revisa; hasta entonces no hay motivo.

## Cobertura

Umbral **70%** en líneas, funciones y sentencias, medido sobre `modulos/` del código bajo
prueba — no sobre una lista de ficheros elegidos.
