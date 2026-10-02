# Instalación

Del clon a la primera ejecución completa. Los pasos 1 y 2 valen para todos los niveles; del 3 en
adelante solo hacen falta para integración y recorridos.

## 1. Prerrequisitos

| Prerrequisito | Versión | Para qué |
| --- | --- | --- |
| PHP con `mysqli`, `libxml` y `dom` | ≥ 8.0 | Los dos niveles de PHP |
| Composer | 2.x | Instalar PHPUnit |
| Node y npm | ≥ 18.19 y < 20 | Jest y Playwright |
| MariaDB o MySQL | MariaDB ≥ 10.0 / MySQL ≥ 5.6 | Integración y recorridos |

```bash
sudo apt install php-cli php-mysql php-xml php-mbstring composer mariadb-server
nvm install 18.19.1 && nvm use 18.19.1     # la horquilla de Node es estrecha
```

Comprobación de que la máquina cumple:

```bash
php -v && composer -V && node -v && mariadb --version
php -r 'foreach (["mysqli","libxml","dom"] as $e) printf("%-8s %s\n", $e, extension_loaded($e) ? "ok" : "FALTA");'
```

## 2. Clonar e instalar

```bash
git clone <url-de-test> test
git clone <url-de-TPVFox> TPVFox     # hermano, al lado de test/

cd test && composer install && npm install
npx playwright install
```

El código bajo prueba se busca en `../TPVFox`. Para apuntar a otra ruta: `TPVFOX_PATH` (PHPUnit y
Jest) y `TPVFOX_URL` (Playwright). `TPVFOX_URL` admite un prefijo —`http://localhost:8080/TPVFox`—
cuando la aplicación no se sirve en la raíz.

Con esto ya pasan `npm run test:php` y `npm run test:js`.

## 3. Crear las dos bases

La integración corre sobre **dos bases de ejercicios consecutivos**, porque hay comportamiento del
producto que compara un ejercicio con el anterior. En TPVFox cada ejercicio vive en su propia base
y no es un parámetro: es una propiedad del despliegue.

**El nombre tiene que empezar por `tpvfox_test`.** La suite se niega a arrancar contra cualquier
otro: un mismo motor puede alojar bases que no son de pruebas, y una variable mal puesta no puede
bastar para escribir sobre una de ellas.

Requiere privilegios de administración, así que no lo hace ningún guion. Una sola vez:

```sql
CREATE DATABASE tpvfox_test_2025 CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE DATABASE tpvfox_test_2026 CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
GRANT ALL PRIVILEGES ON tpvfox_test_2025.* TO 'tpvfox'@'localhost';
GRANT ALL PRIVILEGES ON tpvfox_test_2026.* TO 'tpvfox'@'localhost';
FLUSH PRIVILEGES;
```

Los años son un ejemplo: sirve cualquier par consecutivo.

## 4. Declarar la configuración

`test/.env`, que no se versiona:

```ini
TPVFOX_TEST_DB_HOST=localhost
TPVFOX_TEST_DB_USER=tpvfox
TPVFOX_TEST_DB_PASS=<contraseña>
TPVFOX_TEST_DB_VIGENTE=tpvfox_test_2026
TPVFOX_TEST_DB_ANTERIOR=tpvfox_test_2025

TPVFOX_E2E_USUARIO=<usuario de la aplicación>
TPVFOX_E2E_CLAVE=<su clave>

TPVFOX_URL=http://localhost:8080/TPVFox/
TPVFOX_URL_ANTERIOR=http://localhost:8081/TPVFox/
```

Las mismas claves valen como variables de entorno, y ahí ganan al fichero: en integración continua
no hace falta `.env`.

**Segundo usuario, opcional.** Un recorrido comprueba que el listado de documentos en curso se
acota a quien lo mira, y para eso hacen falta dos sesiones. Se declara igual, con
`TPVFOX_E2E_USUARIO2` y `TPVFOX_E2E_CLAVE2`. Sin ellas ese recorrido se salta solo y el resto no se
entera.

## 5. Preparar el entorno

```bash
npm run entorno:preparar                          # todo lo de abajo, de una vez
npm run entorno:preparar -- --rehacer             # tira los objetos y vuelve a cargar
npm run entorno:preparar -- --ejercicio=anterior  # el puerto por defecto sirve el anterior
```

Hace, en este orden: el esquema de las dos bases, la tienda por la que selecciona el cierre, el
usuario de los recorridos con su fila de índice, el `TPVFox/configuracion.php` del clon, y la
siembra de los escenarios que cruzan de un ejercicio a otro.

**Lo que no toca, a propósito**: la copia de `cache/parametros.xml`, que es la que gobierna el
cálculo. Fijarla sería cualificar una configuración que la instalación real no tiene por qué
compartir.

Con esto ya pasa `npm run test:php:int`.

### Si algo falla, los pasos por separado

| Orden | Qué parte hace |
| --- | --- |
| `npm run bases:preparar` | Solo el esquema: 74 tablas y 4 vistas, sin datos |
| `npm run bases:preparar -- --rehacer` | Tira los objetos antes de cargar |
| `npm run escenarios:sembrar` | Solo los escenarios que cruzan entre ejercicios |

**Los datos de siembra se generan.** No se extraen de ninguna instalación real y ninguno entra en
este repositorio.

`TPVFox/configuracion.php` lo necesita el código del producto que abre su propia conexión, distinta
de la que usa la suite. TPVFox no lo trae —cada despliegue real tiene el suyo— y no se versiona:

```php
<?php
$servidorMysql = 'localhost';
$nombrebdMysql = 'tpvfox_test_2026';   // el mismo valor que TPVFOX_TEST_DB_VIGENTE
$usuarioMysql  = 'tpvfox';
$passwordMysql = '<contraseña>';
```

## 6. Servir la aplicación, en dos puertos

**Hacen falta los dos ejercicios a la vez.** La mayoría de los recorridos corre contra el ejercicio
vigente, pero la pantalla de comprobación del anterior solo admite un fichero que declare el
ejercicio *siguiente* al suyo: con un único despliegue, uno de los dos grupos corre siempre contra
la base equivocada.

Se sirve **el mismo árbol de ficheros** en dos puertos. `entorno:preparar` deja
`TPVFox/configuracion.php` eligiendo la base por el puerto de la petición, así que no hay un segundo
clon que mantener —y, sobre todo, no hay dos copias que puedan quedar en commits distintos sin que
nadie se entere—:

```bash
php -S 127.0.0.1:8080 -t ..    # ejercicio vigente   -> TPVFOX_URL
php -S 127.0.0.1:8081 -t ..    # ejercicio anterior  -> TPVFOX_URL_ANTERIOR
```

Sin `TPVFOX_URL_ANTERIOR`, los tres recorridos que la necesitan **se saltan solos y dicen por qué**.
No se quedan en rojo: un rojo ahí se confundiría con un defecto del producto, que es justo lo que
pasó antes de que esto existiera.

El usuario de `.env` tiene que existir en las dos bases: **ningún guion lo inventa**,
`entorno:preparar` lo da de alta con `group_id = 9` —administrador, sin permisos fila a fila— y crea
su fila en `indices`.

```bash
npm run test:e2e
npm run test:e2e:ui     # con la interfaz de Playwright, para depurar
```

Los recorridos de la comprobación de existencias suben además un fichero de ejemplo, generado con
el propio código de emisión:

```bash
php support/generar-fixture-e2e.php <ano-vigente> <idTienda>
```

El fichero declara el ejercicio vigente y su tienda; el despliegue del anterior tiene que ser el del
ejercicio inmediatamente previo, con esa misma tienda.

## Qué debe salir

| Orden | Resultado esperado hoy |
| --- | --- |
| `npm run test:php` | 116 casos, 17 en rojo |
| `npm run test:php:int` | 448 casos, 3 errores y 17 fallos |
| `npm run test:js` | 22 casos, 2 en rojo |
| `npm run test:e2e` | 109 recorridos, 30 en rojo y 1 omitido |

**Los rojos son deliberados** y están documentados uno a uno: son casos que afirman lo que el
producto debería hacer y hoy no hace. El omitido es el que espera el segundo usuario de recorrido. Ver [escribir-pruebas.md](escribir-pruebas.md#estado-y-código-afectado)
y el registro de defectos del [informe](informe.md).
