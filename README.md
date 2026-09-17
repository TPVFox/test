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

**`Integration/JS` está vacía.** El nivel existe en `jest.config.js` y no tiene ni un fichero:
es andamiaje puesto para cuando haga falta, no una suite que cubra algo. El informe la ejecuta
y no aporta casos. Y `Unit/JS` tiene un único fichero, con 19 casos sobre 5 funciones puras de
un script de 428 líneas: el JavaScript del producto está, hoy, esencialmente sin probar.

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

`TPVFOX_URL` admite un prefijo de ruta —`http://localhost:8080/TPVFox`— cuando la aplicación no
se sirve en la raíz del servidor. La barra final la pone la configuración, así que da igual
escribirla o no.

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

### Dónde vive la siembra

`support/siembra/` reúne todo lo que genera datos, con su propio espacio de nombres
(`TPVFox\Test\Siembra\`). Está separado de `support/php/` —que son los apoyos de la propia
suite: `CasoIntegracion`, `Entorno`— porque la siembra no la consume solo la integración:
también la necesitan los recorridos E2E, que corren contra un despliegue real y no pueden
partir de una base vacía.

| Qué | Dónde |
| --- | --- |
| Primitivas: artículo, familia, proveedor, tienda, movimientos | `support/siembra/Siembra.php` |
| Escenarios de un módulo: qué ocurre en cada caso, y en qué ejercicio | `support/siembra/Escenario*.php` |

La primitiva no sabe de ningún módulo. Un escenario sí, y por eso vive aparte: es lo que
permite que dos despliegues de ejercicios consecutivos se siembren desde un mismo guion en
vez de coordinarse a mano.

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
require_once 'test/support/siembra/Siembra.php';
$db = new mysqli('localhost', 'tpvfox', 'tpvfox', 'tpvfox_test_2026');
$siembra = new TPVFox\Test\Siembra\Siembra($db);
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


### Segundo usuario, opcional

Un recorrido comprueba que el listado de documentos en curso se acota a quien lo mira, y para
eso hacen falta dos sesiones distintas. Se declaran igual que las del primero:

```
TPVFOX_E2E_USUARIO2=<otro usuario>
TPVFOX_E2E_CLAVE2=<su clave>
```

`npm run entorno:preparar` da de alta el segundo usuario solo si las dos variables están. Sin
ellas, el recorrido que las necesita se salta solo y el resto de la suite no se entera: la
fila de índice de cada usuario es independiente, de modo que añadir uno no le quita la suya al
otro.

## Ejecución

```bash
npm run test:php                        # unitario PHP
npm run test:js                         # unitario JS
npm run test:php:int                    # integración PHP  (requiere BD)
npm run test:js:int                     # integración JS
npm run entorno:up && npm run test:e2e  # E2E
```

### Etiquetas y anotaciones de los recorridos E2E

Cada caso E2E lleva etiquetas y anotaciones, y el informe HTML de Playwright las muestra en la
ficha del caso. Las etiquetas se filtran escribiéndolas en el buscador del informe o con `--grep`;
las anotaciones explican el caso en lenguaje llano.

| Grupo | Etiqueta | Qué significa |
| --- | --- | --- |
| Tipo | `@estado-actual` | Documenta lo que el producto hace hoy, y pasa |
| | `@defecto` | Acompaña a `@estado-actual` cuando lo documentado es un defecto: el caso congela el comportamiento incorrecto tal como es |
| | `@esperado` | Afirma lo que el producto debería hacer y hoy no hace, de modo que **está en rojo a propósito**. El fallo lleva por motivo la aserción del propio defecto, y se lee en la primera línea del resultado. El día que el defecto se corrija, el caso pasa a verde y se queda como guardia de regresión |
| | `@control` | Acompaña a un caso `@esperado`: comprueba lo que ya funciona y tiene que seguir funcionando después de la corrección |
| Documento | `@pedido`, `@albaran`, `@factura` | Los documentos que recorre el caso |
| Área | `@entrada`, `@teclado`, `@raton`, `@listado`, `@busqueda`, `@borrador`, `@adjuntos`, `@guardado`, `@estados`, `@numeracion`, `@existencias`, `@importes`, `@impreso`, `@vencimiento`, `@validacion` | La parte del flujo que ejercita |
| Gravedad | `@critico`, `@alto`, `@medio`, `@bajo` | Solo en `@defecto` y `@esperado`: cuánto daño hace el defecto |
| Camino | `@directo` | Solo en `@esperado`: el defecto se alcanza navegando con normalidad |
| | `@forzado` | Solo en `@esperado`: el recorrido tiene que intervenir la petición para provocarlo |

Un caso con defecto lleva cuatro anotaciones: **Qué ocurre hoy**, **Qué debería ocurrir**, **Por qué
ocurre** y **Cómo debería funcionar**. Un caso de comportamiento correcto lleva **Comportamiento**, y
un control añade **Para qué sirve**. Los casos `@esperado` viven además en su propia carpeta,
`E2E/specs/mod_venta/esperado/`.

**Los `@esperado` están en rojo, y así tiene que ser.** No se marcan como fallo esperado: una marca de
fallo esperado da por buena cualquier causa —unas credenciales que faltan, una precondición que no se
cumple, un selector que ya no existe— y deja la suite en verde mientras el recorrido no demuestra nada.
En rojo, cada uno enseña su motivo y ese motivo es la aserción del defecto. La suite que **sí** debe
estar siempre verde es la de todo lo demás:

```bash
npx playwright test E2E/specs/mod_venta --grep-invert @esperado --reporter=line   # debe pasar entera
```

```bash
npx playwright test E2E/specs/mod_venta --grep @esperado --reporter=line             # lo que debería funcionar y no funciona
npx playwright test E2E/specs/mod_venta --grep "@defecto|@esperado" --reporter=line  # todos los defectos
npx playwright test E2E/specs/mod_venta --grep-invert @esperado --reporter=line      # solo el estado actual
```

**El informe HTML es desechable por defecto.** El reporter HTML vacía su carpeta de salida antes de
escribir, y lo hace con cualquier orden de Playwright que no fije otro reporter, incluida `--list`.
Para conservar un informe, genéralo fuera de `E2E/informe-ultimo`:

```bash
PLAYWRIGHT_HTML_OUTPUT_DIR=$HOME/informes-e2e/$(date +%F) npx playwright test E2E/specs/mod_venta --reporter=line,html
npx playwright show-report $HOME/informes-e2e/$(date +%F)
```

## Informe de las pruebas unitarias y de integración

La salida de PHPUnit son puntos en un terminal. Este informe cuenta, por cada caso, **qué
valida**, **qué código de TPVFox recorre** y **qué hizo el dato**, y se consulta como el de
Playwright: con buscador, filtros por etiqueta y el fuente del producto a la vista.

Se navega por páginas, no por paneles: cada vista tiene su dirección —`#/`, `#/codigo`,
`#/caso/<id>`—, con migas para volver y el botón de atrás del navegador funcionando. Un caso
concreto se puede enlazar, y una sección nueva es una ruta nueva en lugar de otra cosa apilada
en el mismo panel.

```bash
npm run informe:pruebas     # genera en informe-pruebas/ (unos 50 s)
npm run informe:ver         # lo sirve en http://127.0.0.1:8081
```

Hace falta servirlo: el navegador bloquea las peticiones de datos desde `file://`. Para
conservar uno, genéralo aparte y sirve esa carpeta:

```bash
php support/informe-pruebas.php --salida=$HOME/informes-pruebas/$(date +%F)
php -S 127.0.0.1:8081 -t $HOME/informes-pruebas/$(date +%F)
```

| Opción | Para qué |
| --- | --- |
| `--suites=unit-php` | Solo una de las suites de PHP |
| `--sin-js` | Se salta las suites de Jest |
| `--salida=<ruta>` | Genera en otro sitio, para conservarlo |
| `--sin-traza` | Genera en la mitad de tiempo, sin cadena de llamadas y con el recorrido incompleto |

**Por qué la traza viene puesta.** Es la única fuente que da **orden**: sin ella el informe
sabe qué ficheros se recorrieron, pero no en qué secuencia, y el apartado «código que recorre»
vuelve a ser una lista suelta. Con ella se lee como un recorrido —`albaranesVentas.php →
ClaseVentas.php → ClaseArticulosStocks.php → claseModeloP.php`— y además aparecen las clases
que no consultan nada, que sin traza no salen en ninguna parte.

Cuesta: **1 minuto frente a 27 segundos**, y disco temporal que se limpia al terminar (un caso
llega a 67 MB de traza en crudo). Con `--sin-traza` se recupera la velocidad y se pierde el
orden.

### De dónde sale cada cosa

| En la ficha | De dónde | Hace falta escribirlo |
| --- | --- | --- |
| La frase del caso | Del nombre del método | No |
| Las etiquetas | Del nombre de la clase y de la carpeta | No |
| «Dado que…» | De los métodos de siembra que el caso llamó | No |
| «Qué hizo el dato» | De las consultas reales, con el método que las pidió y su `fichero:línea` | No |
| El código que recorre | De la cobertura por caso, sobre el fuente real | No |
| La cadena de llamadas | De la traza, que viene puesta | No |
| «Qué valida» en prosa | Del comentario del método | Sí |
| Las cuatro anotaciones del defecto | Declaradas en el comentario | Sí |
| Las etiquetas propias | `@group`, que además filtra por línea de órdenes | Sí |
| `@estado rojo` · `@estado verde` | Declarado en el comentario | Sí |
| `@codigo-afectado ruta.php:18-24` | Declarado en el comentario | Sí |

Lo derivado no pisa lo escrito: si el caso declara algo, manda lo suyo.

### Cómo se anota un caso

Las anotaciones son las mismas seis que usan los recorridos de navegador, con los mismos
nombres, para que lo que se lee en un informe se lea igual en el otro. Un caso de defecto
lleva las cuatro primeras; uno de comportamiento correcto, `@comportamiento`; un control añade
`@para-que-sirve`. Cada una se prolonga hasta la siguiente, así que puede ocupar párrafos.

```php
/**
 * @estado rojo
 * @group defecto
 * @group pedido
 * @group critico
 *
 * @que-ocurre-hoy Pedir el cambio de estado de un pedido cambia también el de un albarán
 *   que no tiene nada que ver, por compartir el mismo identificador.
 * @que-deberia-ocurrir Que el cambio alcance únicamente al documento del tipo pedido.
 * @por-que-ocurre Las tres condiciones que eligen el tipo usan asignación en vez de
 *   comparación, de modo que las tres se cumplen siempre.
 * @como-deberia-funcionar Comparar en vez de asignar en las tres condiciones.
 */
public function test_defecto_modificarEstadoDocumento_pedidoModificaTambienUnAlbaran(): void
```

`@group` es la anotación de PHPUnit, de modo que declarar una etiqueta sirve además para
filtrar sin el informe: `vendor/bin/phpunit --group defecto`.

**En JS es igual, con dos diferencias.** Jest no tiene `@group`, así que las etiquetas van en
una línea `@etiquetas defecto entrada validacion medio`. Y como Jest no da cobertura por caso,
el código que el informe enseña **no es el que se midió sino la función que el caso prueba**:
se deduce del `RUTA_SCRIPT` que el fichero declara y del nombre del `describe`, que por
convención es el de la función de producto. La ficha lo dice con todas las letras para que no
se lea como medido algo que es declarado.

**No hace falta anotar los 532 casos.** Lo derivado ya da frase, etiquetas, flujo y código a
todos; lo que se escribe a mano son los casos donde la causa raíz importa, y lo que se escribe
es prosa que en su mayoría ya existe en el comentario, solo que sin etiquetar. La falta de
anotación **no genera aviso**: los avisos quedan para las contradicciones, o dejarían de
servir.

### Las dos vistas del recorrido

La cobertura pinta un fichero medio verde y no dice **por qué serie de entradas** se llegó
hasta ahí. Por eso la ficha trae dos vistas del recorrido, una encima de la otra:

- **Por dónde pasó** — el camino fichero a fichero, en orden:
  `tareas.php → ClaseIncidencia.php → tareas.php → pedidosVentas.php → …`. Cada llamada aporta
  dos sitios, de dónde salió y dónde está declarado lo que llamó, porque si no se pierde el
  punto de entrada: un despacho como `tareas.php` no declara ninguna clase y desaparecería del
  camino justo el fichero por el que el caso entra. Un tramo que **solo construye** un objeto se
  dice: el dato no pasa por ahí, se monta por estar en la cabecera del fichero. Lo que es
  preparación o comprobación se pliega en un tramo, que es lo que evita que la siembra meta
  decenas de saltos por sus propias escrituras. **Existe siempre**, con traza o sin ella.
- **Pasos, uno a uno** — la cadena de llamadas como árbol, con su profundidad, sus argumentos,
  su valor de retorno y su duración. **Solo TPVFox**: el andamiaje de la prueba va aparte y
  plegado, y las tripas de PHPUnit y el propio instrumento no se guardan. Medido en un caso de
  despacho, de 249 marcos de traza 17 eran del producto; leerlos mezclados hacía pasar por
  recorrido lo que era el montaje de la prueba. Se pierde con `--sin-traza`.

El camino dice por dónde; el árbol, cómo.

### El fuente, por tramos

Abrir un fichero enseña **los tramos que el caso ejecutó, con tres líneas de contexto**, y entre
ellos cuánto se salta. Medido sobre la suite: pintarlos enteros son **10.817 líneas para enseñar
1.819 ejecutadas, el 17 %**; en `PosstockQueryRepository.php` son 114 de 1.891, el 6 %. El
fichero entero sigue a un clic.

**Las ramas de `switch` descartadas se cuentan aparte, no se pintan como recorrido.** PHP evalúa
cada `case` hasta dar con el que coincide, y la cobertura marca esas líneas como ejecutadas. Sin
distinguirlas, un caso que entra en `case 'buscarPedido'` aparenta haber pasado también por
`abririncidencia`, `anhadirTemporal` y `buscarClientes`, en las que no entró.

### Las consultas del montaje, separadas de las del caso

Un despacho construye en su cabecera los objetos que quizá use después, y varios de esos
constructores lanzan un `SELECT count(*)` nada más nacer. Contadas con las demás, un caso que no
llega a hacer nada aparenta haber consultado cinco tablas. El informe las separa por su origen
—una consulta pedida desde un constructor es montaje— y lo dice con todas las letras cuando la
tarea en sí no consultó nada.

### Lo que PHP avisó, que en la ejecución normal no se ve

El despacho de tareas instala `set_error_handler(fn () => true)` para que un aviso de PHP no
tumbe el caso a mitad. Eso silencia los avisos del producto: no salen por pantalla, no llegan a
ningún registro y no dejan rastro en la cobertura. La traza sí los conserva, porque cada aviso
es una llamada a ese manejador con su mensaje, su fichero y su línea. El informe los vuelve a
sacar a la luz.

Medido: **208 avisos en 32 casos**, 157 distintos —47 avisos y 110 usos de algo obsoleto—.
Includes que fallan, propiedades dinámicas creadas al vuelo, pérdida de precisión al pasar de
float a entero, variables no definidas.

Es lo que explica un caso como *«buscarPedido: apunta a un fichero borrado»*. Su cobertura dice
que pasó por `tareas.php` y no nombra `BuscarPedido.php`, y eso descoloca hasta que se ve por
qué: un `include_once` que falla no ejecuta nada, de modo que no hay nada que medir. El aviso sí
lo cuenta, y de paso aparecen las dos líneas que rematan el defecto:

```
tareas.php:77   include_once(…/mod_venta/tareas/BuscarPedido.php): Failed to open stream
                → el fichero no existe
tareas.php:198  Undefined variable $respuesta
tareas.php:199  Undefined variable $respuesta
```

Cuando el aviso nombra un fichero, el informe comprueba si existe, y así distingue el defecto
del producto del artefacto del andamiaje: en ese mismo caso `./../../inicial.php` también falla,
pero por ser una ruta relativa al directorio de trabajo, que el andamiaje cambia. Ese fichero
existe, y el informe lo dice en vez de sumarlo a la cuenta de lo que está roto.

### Un límite medido: los casos que terminan en error no dejan cobertura

PHPUnit descarta la cobertura de un caso que acaba en error —no en fallo de aserción, en
error—. Medido sobre esta suite: los 518 verdes y los 11 fallidos la traen; los 3 con error,
ninguna. En el informe esos casos muestran su recorrido y su flujo, pero no su código, y la
ficha lo dice para que no se lea como «este caso no toca nada».

### Los tres contrastes

Son lo que el informe aporta sobre leer el fuente, y salen solos:

1. **El estado declarado contra el real.** Avisa si un caso dice estar en rojo y pasa, o al
   revés, o si está en rojo sin declararlo.
2. **El código declarado contra el ejecutado.** Avisa si un caso dice cubrir un defecto y no
   pasa por ninguna de esas líneas.
3. **Ningún fichero de pruebas cita identificadores del sistema de calidad**, porque este
   repositorio es público y tiene que sostenerse solo.

### Cómo se instrumenta

Dos piezas, las dos inertes mientras no se genera el informe:

- **La conexión observada** (`support/php/Instrumentacion/ConexionObservada.php`) sustituye a
  la conexión de los casos de integración y anota cada consulta con su SQL, su emisor y lo que
  devolvió. Funciona porque el producto consulta siempre por la conexión que la suite le
  entrega; no se toca ni una línea de TPVFox.
- **El registro de flujo** (`RegistroDeFlujo.php`) arranca una traza por caso. Está declarado
  en `phpunit.xml` y, sin su variable de entorno, todos sus métodos retornan en el acto:
  medido, `npm run test:php:int` tarda lo mismo con la extensión registrada que sin ella.

La carpeta `informe-pruebas/` no entra en el repositorio.

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
npm run cobertura -- <ámbito> --detalle                                  # qué falta
```

**`--detalle` dice qué queda fuera**, línea a línea y método a método. Un umbral cumplido
no distingue si lo no cubierto es accesorio o es justo la rama que nadie probó, y esa es la
diferencia entre una entrega verificada y una que solo lo parece.

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
