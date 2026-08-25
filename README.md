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

### 4. Conectar el propio TPVFox a la base de pruebas

Necesario solo para los casos de integración que ejercitan código que hereda de `TFModelo`: ese
código abre su propia conexión —independiente de la que usa la suite para sembrar y comprobar—, a
través de `TPVFox/configuracion.php`. TPVFox no trae ese fichero (cada despliegue real tiene el
suyo, con sus propias credenciales) y no se versiona.

`TPVFox/configuracion.php`, en el clon de TPVFox contra el que corre la suite:

```php
<?php
$servidorMysql = 'localhost';
$nombrebdMysql = 'tpvfox_test_2026';   // el mismo valor que TPVFOX_TEST_DB_VIGENTE
$usuarioMysql  = 'tpvfox';
$passwordMysql = '<contraseña>';
```

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

## Usuario para los recorridos E2E

Playwright inicia sesión como un usuario real del despliegue contra el que corre: no hay ningún
mecanismo en este repositorio que lo siembre — ni `Siembra` (que no toca `usuarios`) ni ningún otro. Hay
que crearlo a mano, una vez por base de pruebas, y exportar `TPVFOX_E2E_USUARIO`/`TPVFOX_E2E_CLAVE`
antes de `npm run test:e2e`.

Con un grupo `group_id = 9` el usuario es administrador y no hace falta dar de alta permisos fila a
fila: `ClasePermisos` los resuelve todos a 1 automáticamente. Hace falta también una tienda con
`tipoTienda = 'principal'` — solo puede haber una activa — y su fila en `indices`:

```php
<?php
require_once 'test/support/php/Siembra.php';
$db = new mysqli('localhost', 'tpvfox', 'tpvfox', 'tpvfox_test_2026');
$siembra = new TPVFox\Test\Siembra($db);
$idTienda = $siembra->tienda('2026');
// usuarioPorDefecto() no sirve aquí: crea group_id=1 y una contraseña que no es un hash válido.
// Hace falta un INSERT propio en usuarios (password = MD5(clave), group_id = 9, estado = 'activo')
// y otro en indices (idTienda, idUsuario) para que el login lo acepte como sesión completa.
```

Los recorridos de la comprobación de existencias en el cambio de año suben además un fichero de
ejemplo, generado con el propio código de emisión en vez de a mano:

```bash
php support/generar-fixture-e2e.php <ano-vigente> <idTienda>
```

`<ano-vigente>` es el ejercicio del despliegue contra el que corre el recorrido del vigente, y
`<idTienda>` la tienda sembrada en esa base. El recorrido del anterior necesita correr contra el
despliegue del ejercicio inmediatamente anterior a ese, con su propio usuario y su propia tienda
sembrados igual.

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

El objetivo es **70%** en líneas y en métodos. Lo que se declara en cada ejecución no es el
umbral sino **el ámbito**: un porcentaje solo significa algo si se dice sobre qué se mide.

```bash
npm run cobertura -- modulos/mod_reorganizacion                          # una carpeta
npm run cobertura -- clases/ClaseTFModelo.php                            # un fichero
npm run cobertura -- mod_reorganizacion/clases/ClaseComprobacionStock    # un prefijo
npm run cobertura -- modulos/mod_informes --umbral=80
npm run cobertura -- <ámbito> --suites=unit-php
```

El ámbito **no tiene valor por defecto**, a propósito: este repositorio prueba TPVFox entero,
y un ámbito por defecto acabaría midiendo siempre lo de una entrega concreta. El guion sale
con error si el ámbito no casa con ningún fichero medido, si la suite no pasa, o si no se
alcanza el umbral. Cuando falla, lista los cinco ficheros que más lastran.

**Qué se mide y qué no** (`phpunit.xml`, bloque `<coverage>`): entra el código propio del
producto —`modulos/`, `clases/`, `controllers/`, `app/`—, y no solo los módulos: las clases
base de `clases/` son las que el código de los módulos hereda y consume. Queda fuera `lib/`,
que es de terceros, y `plugins/`, `jquery/` y `estatico/`.

**Medir un módulo que ya tenía código.** Un módulo con código anterior a las pruebas arrastra
su cobertura hacia abajo aunque lo nuevo esté verificado del todo. Ahí el ámbito se declara
por prefijo de ruta, de forma que mida el código que la entrega produce; la cobertura del
código anterior es un objetivo aparte y no la decide una entrega que no lo tocó.

**En JavaScript** el umbral sigue configurado como global en `jest.config.js`. Cuando existan
pruebas JS habrá que darle el mismo tratamiento; hoy no hay ninguna.
