<?php
/*
 * Fichero de prueba fuera de la carpeta de tareas: anota que alguien lo incluyo. Es otro fichero
 * que `FicheroDeFuera.php` porque `include_once` no repite un fichero en el mismo proceso, y cada
 * camino de lanzamiento tiene su caso.
 */

\TPVFox\Test\RegistroDeTareas::anotarInclusion(basename(__FILE__));
