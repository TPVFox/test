# Cómo está hecho

Para quien tenga que tocarlo. Cada pieza explica su porqué en su propio docblock; aquí está el mapa.

**Lo que más importa: nada de esto se ejecuta en una pasada normal.** Sin su variable de entorno,
todos los métodos de la instrumentación retornan en el acto, y `npm run test:php:int` tarda lo
mismo con la extensión registrada que sin ella.

## Las cuatro piezas de la instrumentación

| Pieza | Qué hace |
| --- | --- |
| [`ConexionObservada.php`](../support/php/Instrumentacion/ConexionObservada.php) | Sustituye la conexión de los casos de integración y anota cada consulta con su SQL, su emisor y lo que devolvió |
| [`Bitacora.php`](../support/php/Instrumentacion/Bitacora.php) | Escribe cada paso a fichero, no a memoria: hay casos que corren en un proceso aparte |
| [`RegistroDeFlujo.php`](../support/php/Instrumentacion/RegistroDeFlujo.php) | Extensión de PHPUnit que arranca una traza de Xdebug por caso |
| [`Identidad.php`](../support/php/Instrumentacion/Identidad.php) | El identificador estable de un caso, el mismo para la instrumentación y para el informe |

La conexión observada funciona por una particularidad del producto: **TPVFox nunca abre su propia
conexión cuando la suite le entrega una**, y consulta siempre con `query()` sobre ella. Por eso se
captura el SQL real sin tocar una línea de TPVFox y sin dependencias nuevas.

Se activa por `TPVFOX_BITACORA`; la traza, por `TPVFOX_TRAZA`. Las pone el generador del informe.

## Las piezas del informe

| Pieza | Qué hace |
| --- | --- |
| [`informe-pruebas.php`](../support/informe-pruebas.php) | Orquesta: ejecuta las suites, lee los resultados, enriquece y emite |
| [`Flujo.php`](../support/informe/Flujo.php) | Funde consultas y traza en una sucesión: el camino, la puerta de entrada y los avisos de PHP |
| [`LectorDeTraza.php`](../support/informe/LectorDeTraza.php) | Lee la traza de Xdebug en streaming, con tope y recorte declarado |
| [`Declaraciones.php`](../support/informe/Declaraciones.php) | Dónde se declara cada clase y cada función del producto |
| [`Codigo.php`](../support/informe/Codigo.php) | Invierte el eje: de «qué hace este caso» a «qué le pasa a esta función» |
| [`Cobertura.php`](../support/informe/Cobertura.php) | Cruza los niveles: quién cubre cada fichero |
| [`Recorridos.php`](../support/informe/Recorridos.php) | Lee el volcado de Playwright y qué pantallas toca cada recorrido |
| [`Historia.php`](../support/informe/Historia.php) | Registra cada ejecución para poder decir desde cuándo |
| [`Datos.php`](../support/informe/Datos.php) | Emite los JSON que la vista consume |
| [`plantilla/`](../support/informe/plantilla/) | La vista: sin dependencias y sin paso de compilación |

## Tres decisiones que conviene conocer antes de tocar nada

**Los datos van en varias piezas y no en una.** El índice es lo único que se carga al abrir; la
ficha de un caso y el fuente que recorre se piden cuando hacen falta. Con todo junto, el navegador
tendría que analizar decenas de megas antes de pintar.

**Dónde vive una función se lee del producto, no se deduce del recorrido.** Cuesta leer 1.908
ficheros una vez por informe —0,4 s— y evita adivinar, que es peor que no decirlo: quien lee no
tiene forma de saber que es mentira.

**La historia no se poda y vive fuera del informe emitido**, en `.historia-pruebas/`. El informe se
genera donde le digan, y atarla a la carpeta de salida haría que cada copia arrancase su propio
registro desde cero.

## Lo que la instrumentación no debe hacer

No tocar `TPVFox/`. Todo lo que hay aquí funciona sustituyendo lo que la suite ya entrega al
producto —la conexión— o mirando desde fuera —la traza, la cobertura, el volcado de Playwright—.
Una instrumentación que exigiera modificar el producto mediría un producto distinto del que se
despliega.
