<?php
/*
 * Ocupa el sitio de la sesion de TPVFox cuando `PrecargaInicial` carga `inicial.php`.
 *
 * La de verdad abre su propia conexion y vuelve a incluir `ClaseConexion.php` sin `_once`,
 * clase que la suite ya tiene cargada: incluirla otra vez es un error fatal. En la suite, cada
 * caso pone su conexion en el modelo, y la sesion no hace falta.
 */
