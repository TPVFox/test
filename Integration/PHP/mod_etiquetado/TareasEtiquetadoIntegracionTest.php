<?php

/**
 * La petición de fondo de `mod_etiquetado` (`modulos/mod_etiquetado/tareas.php`) y el guion que imprime
 * las etiquetas (`controllers/planImprimirCodBarras.php`): repetir las líneas de un producto, crear y
 * modificar el temporal de un lote, buscar el producto, recalcular el código de barras de una línea,
 * contar las etiquetas de los lotes marcados e imprimirlas en un PDF.
 *
 * Cada petición se ejecuta en un PHP aparte, desde la carpeta del módulo, con la sesión del usuario de
 * pruebas o sin ninguna, los permisos del módulo concedidos salvo los que el caso quite, y una
 * transacción que se deshace al terminar. Los productos, los lotes y los temporales que siembra el caso
 * quedan con un nombre —`:p1`, `:l1`, `:t1`…— para el formulario. La carpeta temporal de la instalación
 * se sustituye por una propia del caso, donde se escribe el PDF, que se lee con `pdftotext`. Al
 * terminar, el proceso responde lo que dan las consultas del caso, también si la petición acaba con un
 * error.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModEtiquetado;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class TareasEtiquetadoIntegracionTest extends CasoIntegracion
{
    private int $idUsuario;

    private int $idTienda;

    private string $carpeta = '';

    protected function setUp(): void
    {
        parent::setUp();
        $siembra = new Siembra($this->db);
        $this->idUsuario = $siembra->usuarioPorDefecto();
        $this->idTienda = $siembra->tiendaPorDefecto();
        $this->carpeta = sys_get_temp_dir() . '/tpvfox-etiquetado-' . bin2hex(random_bytes(4));
        mkdir($this->carpeta);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->carpeta . '/*') ?: [] as $fichero) {
            unlink($fichero);
        }
        @rmdir($this->carpeta);
        parent::tearDown();
    }

    /**
     * @comportamiento Repetir un producto responde las líneas nuevas, numeradas detrás de las que ya
     *   tenía el lote, y sus filas; sin producto elegido, avisa.
     */
    public function test_repetirProductosRespondeLasLineasNuevas(): void
    {
        $repetir = $this->ejecutar(['pulsado' => 'repetirProductos', 'unidades' => '2', 'idProducto' => ':p1', 'idTienda' => ':tienda', 'fechaCad' => '2026-11-01', 'tipo' => '1', 'productos' => [['Nfila' => '1']]], $this->producto('Pan de molde'));
        $sinProducto = $this->ejecutar(['pulsado' => 'repetirProductos', 'unidades' => '2', 'idProducto' => '0', 'idTienda' => ':tienda', 'fechaCad' => '2026-11-01', 'tipo' => '1']);

        self::assertSame(1, $repetir['respuesta']['numProd']);
        self::assertSame([2, 3], array_column($repetir['respuesta']['productos'], 'Nfila'));
        self::assertSame(2, substr_count($repetir['respuesta']['html'], '<tr id="Row'));
        self::assertSame('No has seleccionado el producto', $sinProducto['respuesta']['error']);
    }

    /**
     * @comportamiento Sin número de temporal, la petición crea el temporal del lote y responde su número;
     *   con él, lo modifica.
     */
    public function test_unTemporalSeCreaYDespuesSeModifica(): void
    {
        $crear = $this->ejecutar($this->temporal(), $this->producto('Pan de molde'), true, ['temporal' => 'SELECT COUNT(*) FROM modulo_etiquetado_temporal WHERE idArticulo = :p1']);
        $modificar = $this->ejecutar(['idTemporal' => ':t1', 'fechaCad' => '2026-12-31'] + $this->temporal(), [...$this->producto('Pan de molde'), $this->temporalSembrado()], true, ['fecha' => 'SELECT fecha_cad FROM modulo_etiquetado_temporal WHERE id = :t1']);

        self::assertGreaterThan(0, (int) $crear['respuesta']['idTemporal']);
        self::assertSame('1', $crear['consultas']['temporal']);
        self::assertSame('2026-12-31', $modificar['consultas']['fecha']);
    }

    /** @comportamiento Cambiar el temporal de un lote guardado lo deja «Sin guardar». */
    public function test_cambiarUnLoteGuardadoLoDejaSinGuardar(): void
    {
        $cambio = $this->ejecutar(['idReal' => ':l1'] + $this->temporal(), [...$this->producto('Pan de molde'), $this->lote(2)], true, ['estado' => 'SELECT estado FROM modulo_etiquetado WHERE id = :l1']);

        self::assertSame('Sin guardar', $cambio['consultas']['estado']);
    }

    /**
     * @comportamiento Buscar un producto por su número responde sus datos; por su nombre, las filas de la
     *   búsqueda; una referencia con guion no se admite.
     */
    public function test_buscarUnProductoPorSuNumeroOPorSuNombre(): void
    {
        $porNumero = $this->ejecutar(['pulsado' => 'buscarProducto', 'valor' => ':p1', 'caja' => 'id_producto', 'idTienda' => ':tienda'], $this->producto('Pan de molde'));
        $porNombre = $this->ejecutar(['pulsado' => 'buscarProducto', 'valor' => 'molde', 'caja' => 'producto', 'idTienda' => ':tienda'], $this->producto('Pan de molde'));
        $conGuion = $this->ejecutar(['pulsado' => 'buscarProducto', 'valor' => ':p1', 'caja' => 'id_producto', 'idTienda' => ':tienda'], $this->producto('Pan de molde', '123-4'));

        self::assertSame([1, 'Pan de molde', '12345'], [$porNumero['respuesta']['Nitem'], $porNumero['respuesta']['datos']['articulo_name'], $porNumero['respuesta']['datos']['crefTienda']]);
        self::assertStringContainsString('<td>Pan de molde</td>', $porNombre['respuesta']['html']);
        self::assertSame('Error', $conGuion['respuesta']['error']);
    }

    /**
     * @comportamiento El código de una línea se recalcula: por unidad, con el precio por la cantidad; por
     *   peso, con el peso.
     */
    public function test_elCodigoDeUnaLineaSeRecalcula(): void
    {
        $porUnidad = $this->ejecutar(['pulsado' => 'modificarCodigoBarras', 'tipo' => '1', 'producto' => ['peso' => '2', 'precio' => '1.5', 'crefTienda' => '12345']]);
        $porPeso = $this->ejecutar(['pulsado' => 'modificarCodigoBarras', 'tipo' => '2', 'producto' => ['peso' => '0.75', 'precio' => '10', 'crefTienda' => '12345']]);

        self::assertSame('211234500300' . $this->digito('211234500300'), $porUnidad['respuesta']['codBarras']);
        self::assertSame('201234500750' . $this->digito('201234500750'), $porPeso['respuesta']['codBarras']);
    }

    /** @comportamiento Contar las etiquetas de los lotes marcados suma las líneas de cada uno. */
    public function test_contarEtiquetasSumaLasDeLosLotes(): void
    {
        $contar = $this->ejecutar(['pulsado' => 'contarEtiquetas', 'lotes' => [':l1', ':l2']], [...$this->producto('Pan de molde'), $this->lote(2), $this->lote(3)]);

        self::assertSame(5, $contar['respuesta']['etiquetas']);
    }

    /**
     * @comportamiento Imprimir escribe en la carpeta temporal un PDF con una etiqueta por línea: el lote y
     *   su fecha de envasado, el nombre, por peso el precio por kilo y el peso, el PVP, la caducidad y el
     *   código de barras; y responde dónde está.
     */
    public function test_imprimirEscribeUnaEtiquetaPorLinea(): void
    {
        $imprimir = $this->ejecutar(['pulsado' => 'imprimirEtiquetas', 'lotes' => [':l1']], [...$this->producto('Queso tierno'), $this->lote(2, 2, '0.5', '10')]);
        $texto = $this->texto();

        self::assertStringEndsWith('/etiquetas.pdf', $imprimir['respuesta']);
        self::assertMatchesRegularExpression('/Lote: ' . $imprimir['ids'][':l1'] . '\s+Fecha Env: 23-10-2026/', $texto);
        self::assertSame(2, substr_count($texto, 'PVP: 5.00€'));
        self::assertStringContainsString('Precio kilo: 10.00€', $texto);
        self::assertStringContainsString('Peso: 0.5kg', $texto);
        self::assertStringContainsString('Fecha cad: 01-11-2026', $texto);
        self::assertStringContainsString($this->codigoPeso('0.5'), str_replace(' ', '', $texto));
    }

    /** @comportamiento Diecisiete etiquetas ocupan dos hojas: caben dieciséis por hoja. */
    public function test_diecisieteEtiquetasOcupanDosHojas(): void
    {
        $this->ejecutar(['pulsado' => 'imprimirEtiquetas', 'lotes' => [':l1']], [...$this->producto('Pan de molde'), $this->lote(17)]);

        self::assertSame(2, substr_count($this->texto(), "\f"));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_etiquetado/tareas.php:1-11
     *
     * @group defecto
     * @group permisos
     * @group alto
     *
     * @que-ocurre-hoy Una petición sin sesión crea el temporal de un lote.
     * @que-deberia-ocurrir Que sin sesión la petición no haga nada.
     * @por-que-ocurre La petición carga el arranque común sin la cabecera, que es la que pide la sesión, y no
     *   la comprueba; la causa común es la del núcleo.
     * @como-deberia-funcionar Comprobar la sesión antes de atender la orden.
     */
    public function test_sinSesionNoSeCreaUnTemporal(): void
    {
        $crear = $this->ejecutar($this->temporal(), $this->producto('Pan de molde'), false, ['temporal' => 'SELECT COUNT(*) FROM modulo_etiquetado_temporal WHERE idArticulo = :p1']);

        self::assertSame('0', $crear['consultas']['temporal']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_etiquetado/tareas.php:14-167
     *
     * @group defecto
     * @group permisos
     * @group alto
     *
     * @que-ocurre-hoy Un usuario con sesión pero sin permiso en el módulo de etiquetado crea el temporal de
     *   un lote por la petición de fondo.
     * @que-deberia-ocurrir Que sin el permiso de crear o de modificar lotes no se cree ni se cambie ninguno.
     * @por-que-ocurre La petición no consulta ningún permiso, y el listado tampoco consulta las acciones que
     *   el módulo declara.
     * @como-deberia-funcionar Consultar el permiso de la acción antes de atender cada orden.
     */
    public function test_sinPermisoNoSeCreaUnTemporal(): void
    {
        $crear = $this->ejecutar($this->temporal(), [
            ...$this->producto('Pan de molde'),
            "UPDATE permisos SET permiso = 0 WHERE idUsuario = :usuario AND modulo = 'mod_etiquetado'",
        ], true, ['temporal' => 'SELECT COUNT(*) FROM modulo_etiquetado_temporal WHERE idArticulo = :p1']);

        self::assertSame('0', $crear['consultas']['temporal']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_etiquetado/tareas.php:131-135
     *
     * @group defecto
     * @group importes
     * @group alto
     *
     * @que-ocurre-hoy Por unidad, una cantidad con decimales da un código con el precio de la parte entera:
     *   la etiqueta imprime el PVP de 1,5 unidades y el TPV cobra el de una.
     * @que-deberia-ocurrir Que el código lleve el mismo precio que la etiqueta imprime.
     * @por-que-ocurre El recálculo convierte la cantidad en entero antes de multiplicarla por el precio; la
     *   impresión no.
     * @como-deberia-funcionar Multiplicar la cantidad tal como es, o no admitir decimales por unidad.
     */
    public function test_porUnidadElCodigoLlevaElPrecioDeLaCantidad(): void
    {
        $porUnidad = $this->ejecutar(['pulsado' => 'modificarCodigoBarras', 'tipo' => '1', 'producto' => ['peso' => '1.5', 'precio' => '2', 'crefTienda' => '12345']]);

        self::assertSame('211234500300' . $this->digito('211234500300'), $porUnidad['respuesta']['codBarras']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_etiquetado/tareas.php:125-142
     *
     * @group defecto
     * @group impreso
     * @group medio
     *
     * @que-ocurre-hoy Recalcular el código sin tipo elegido responde un código vacío y deja un aviso de PHP.
     * @que-deberia-ocurrir Que responda un error: sin tipo no hay código.
     * @por-que-ocurre El recálculo solo atiende los tipos 1 y 2, y responde el código sin haberlo dado.
     * @como-deberia-funcionar Responder un error para cualquier otro tipo.
     */
    public function test_sinTipoElRecalculoRespondeUnError(): void
    {
        $sinTipo = $this->ejecutar(['pulsado' => 'modificarCodigoBarras', 'tipo' => '0', 'producto' => ['peso' => '1', 'precio' => '2', 'crefTienda' => '12345']]);

        self::assertSame('', $sinTipo['errores']);
        self::assertArrayHasKey('error', (array) $sinTipo['respuesta']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_etiquetado/tareas.php:144-155
     *
     * @group defecto
     * @group impreso
     * @group medio
     *
     * @que-ocurre-hoy Contar las etiquetas de un lote que no existe termina la petición con un error de PHP,
     *   y la pantalla no imprime ni avisa.
     * @que-deberia-ocurrir Que el lote que no existe no cuente, o se diga.
     * @por-que-ocurre La lectura del lote no trae filas, y se cuentan sus líneas sin comprobarlo.
     * @como-deberia-funcionar Comprobar que la lectura trae el lote antes de contar sus líneas.
     */
    public function test_contarUnLoteQueNoExisteResponde(): void
    {
        $contar = $this->ejecutar(['pulsado' => 'contarEtiquetas', 'lotes' => ['999999']]);

        self::assertStringNotContainsString('Fatal error', $contar['errores']);
        self::assertIsArray($contar['respuesta']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_etiquetado/tareas.php:97-112
     *
     * @group defecto
     * @group busqueda
     * @group medio
     *
     * @que-ocurre-hoy Buscar un número que no es de ningún producto con precio y referencia en la tienda
     *   responde que lo encontró, sin datos: la ficha queda con el producto sin número.
     * @que-deberia-ocurrir Que diga que no encuentra el producto.
     * @por-que-ocurre La lectura no trae filas, y la búsqueda da por encontrado lo que responde.
     * @como-deberia-funcionar Comprobar que la lectura trae el producto antes de responderlo.
     */
    public function test_buscarUnNumeroSinProductoEnLaTiendaAvisa(): void
    {
        $buscar = $this->ejecutar(['pulsado' => 'buscarProducto', 'valor' => '999999', 'caja' => 'id_producto', 'idTienda' => ':tienda']);

        self::assertArrayHasKey('error', (array) $buscar['respuesta']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_etiquetado/tareas.php:61-67
     * @codigo-afectado modulos/mod_etiquetado/clases/modulo_etiquetado.php:65-91
     *
     * @group defecto
     * @group entrada
     * @group alto
     *
     * @que-ocurre-hoy Un número de temporal con una condición añadida cambia todos los temporales abiertos,
     *   de cualquier usuario.
     * @que-deberia-ocurrir Que solo cambie el temporal cuyo número llega.
     * @por-que-ocurre El número se pega a la sentencia sin convertirlo.
     * @como-deberia-funcionar Convertir el número en entero antes de componer la sentencia, o pasarlo como
     *   dato.
     */
    public function test_unNumeroDeTemporalConUnaCondicionNoCambiaOtros(): void
    {
        $cambio = $this->ejecutar(['idTemporal' => ':t1 OR 1=1', 'fechaCad' => '2026-12-31'] + $this->temporal(), [...$this->producto('Pan de molde'), $this->temporalSembrado(), $this->temporalSembrado()], true, ['otro' => 'SELECT fecha_cad FROM modulo_etiquetado_temporal WHERE id = :t2']);

        self::assertSame('2026-11-01', $cambio['consultas']['otro']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_etiquetado/tareas.php:156-165
     * @codigo-afectado controllers/planImprimirCodBarras.php:74
     *
     * @group defecto
     * @group impreso
     * @group bajo
     *
     * @que-ocurre-hoy Todas las impresiones escriben el mismo fichero: la de un usuario sustituye a la de
     *   otro que imprima a la vez, y puede abrir las etiquetas del otro.
     * @que-deberia-ocurrir Que cada impresión escriba su propio fichero.
     * @por-que-ocurre El nombre del PDF es fijo.
     * @como-deberia-funcionar Dar a cada impresión un nombre propio, y abrir ese.
     */
    public function test_dosImpresionesNoEscribenElMismoFichero(): void
    {
        $una = $this->ejecutar(['pulsado' => 'imprimirEtiquetas', 'lotes' => [':l1']], [...$this->producto('Pan de molde'), $this->lote(1)]);
        $otra = $this->ejecutar(['pulsado' => 'imprimirEtiquetas', 'lotes' => [':l1']], [...$this->producto('Queso tierno'), $this->lote(1)]);

        self::assertNotSame($una['respuesta'], $otra['respuesta']);
    }

    /** @return array<string, mixed> */
    private function temporal(): array
    {
        return [
            'pulsado' => 'addEtiquetadoTemporal', 'estado' => 'Activo', 'idTemporal' => '0', 'idReal' => '0',
            'fechaEnv' => '2026-10-23 10:00:00', 'fechaCad' => '2026-11-01', 'idProducto' => ':p1', 'idUsuario' => ':usuario',
            'tipo' => '1', 'NumAlb' => '0',
            'productos' => [['nombre' => 'Pan de molde', 'peso' => '1', 'precio' => '3.5', 'Fecha' => '2026-11-01', 'NumAlb' => '', 'codBarras' => '2112345003504', 'estado' => 'Activo', 'Nfila' => '1', 'crefTienda' => '12345']],
        ];
    }

    private function temporalSembrado(): string
    {
        return "INSERT INTO modulo_etiquetado_temporal (num_lote, tipo, fecha_env, fecha_cad, idArticulo, numAlb, estado, productos, idUsuario) VALUES (0, '1', '2026-10-23 10:00:00', '2026-11-01', :pN, 0, 'Activo', '[]', :usuario)";
    }

    /** @return list<string> */
    private function producto(string $nombre, string $referencia = '12345'): array
    {
        return [
            "INSERT INTO articulos (articulo_name, estado, fecha_creado, ultimoCoste, iva, beneficio, tipo) VALUES ('{$nombre}', 'Activo', '2026-01-01', 1, 10, 0, 'unidad')",
            'INSERT INTO articulosPrecios (idArticulo, pvpCiva, pvpSiva, idTienda) VALUES (:pN, 3.5, 3.18, :tienda)',
            "INSERT INTO articulosTiendas (idArticulo, idTienda, crefTienda, estado) VALUES (:pN, :tienda, '{$referencia}', 'Activo')",
        ];
    }

    /** Un lote guardado del último producto sembrado, con tantas líneas como se pidan. */
    private function lote(int $lineas, int $tipo = 1, string $peso = '1', string $precio = '3.5'): string
    {
        $codigo = $tipo === 2 ? $this->codigoPeso($peso) : '2112345003504';
        $productos = array_map(fn ($i) => ['nombre' => "Producto {$i}", 'peso' => $peso, 'precio' => $precio, 'Fecha' => '2026-11-01', 'NumAlb' => '', 'codBarras' => $codigo, 'estado' => 'Activo', 'Nfila' => (string) $i, 'crefTienda' => '12345'], range(1, $lineas));

        return "INSERT INTO modulo_etiquetado (num_lote, tipo, fecha_env, fecha_cad, idArticulo, numAlb, estado, productos, idUsuario) VALUES (0, '{$tipo}', '2026-10-23 10:00:00', '2026-11-01', :pN, 0, 'Guardado', '" . addslashes((string) json_encode($productos)) . "', :usuario)";
    }

    private function codigoPeso(string $peso): string
    {
        [$entero, $decimal] = array_pad(explode('.', $peso), 2, '');
        $doce = '2012345' . str_pad($entero, 2, '0', STR_PAD_LEFT) . str_pad($decimal, 3, '0');

        return $doce . $this->digito($doce);
    }

    /** El dígito de control de un EAN-13: pesos 1 y 3 alternos desde la izquierda. */
    private function digito(string $doce): string
    {
        $suma = 0;
        foreach (str_split($doce) as $posicion => $cifra) {
            $suma += (int) $cifra * ($posicion % 2 === 0 ? 1 : 3);
        }

        return (string) ((10 - $suma % 10) % 10);
    }

    /** El texto del PDF que dejó la última impresión, con un salto de página por hoja. */
    private function texto(): string
    {
        $pdf = $this->carpeta . '/etiquetas.pdf';
        self::assertFileExists($pdf);

        return (string) shell_exec('pdftotext -layout ' . escapeshellarg($pdf) . ' - 2>/dev/null');
    }

    /**
     * Ejecuta la petición de fondo en un PHP aparte y devuelve su respuesta, sus avisos, los nombres de lo
     * sembrado y lo que responden las consultas del caso al terminar.
     *
     * @param array<string, mixed> $formulario
     * @param list<string> $preparacion
     * @param array<string, string> $consultas
     * @return array{respuesta:mixed, errores:string, ids:array<string, string>, consultas:array<string, ?string>}
     */
    private function ejecutar(array $formulario, array $preparacion = [], bool $conSesion = true, array $consultas = []): array
    {
        $sesion = $conSesion ? [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => $this->idUsuario, 'group_id' => 1, 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => $this->idTienda, 'razonsocial' => 'Tienda de pruebas'],
        ] : [];
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["DOCUMENT_ROOT"] = ""; $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = "/TPVFox/modulos/mod_etiquetado/tareas.php";'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_etiquetado', true) . ');'
            . ' require "./../../inicial.php";'
            . ' $rutatmp = str_repeat("/..", substr_count(rtrim($RutaServidor, "/"), "/")) . ' . var_export($this->carpeta, true) . ';'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ($conSesion ? ' new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' $BDTpv->query("UPDATE permisos SET permiso = 1 WHERE idUsuario = ' . $this->idUsuario . ' AND modulo = \'mod_etiquetado\'");'
            . ' $sembrados = [":usuario" => "' . $this->idUsuario . '", ":tienda" => "' . $this->idTienda . '"]; $cuenta = ["p" => 0, "l" => 0, "t" => 0];'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->query(strtr($sql, $sembrados));'
            . ' foreach (["INSERT INTO articulos " => "p", "INSERT INTO modulo_etiquetado " => "l", "INSERT INTO modulo_etiquetado_temporal " => "t"] as $inicio => $letra) {'
            . ' if (str_starts_with($sql, $inicio)) { $sembrados[":" . $letra . ++$cuenta[$letra]] = $sembrados[":" . $letra . "N"] = (string) $BDTpv->insert_id; } } }'
            . ' $_POST = ' . var_export($formulario, true) . ';'
            . ' array_walk_recursive($_POST, function (&$valor) use ($sembrados) { $valor = strtr((string) $valor, $sembrados); });'
            . ($conSesion ? ' $ClasePermisos = $thisTpv->permisos = new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' register_shutdown_function(function () use ($BDTpv, &$sembrados) { $resultados = [];'
            . ' foreach (' . var_export($consultas, true) . ' as $nombre => $sql) { $fila = $BDTpv->query(strtr($sql, $sembrados))->fetch_row(); $resultados[$nombre] = $fila === null ? null : (string) $fila[0]; }'
            . ' echo "\n@@ESTADO@@" . json_encode(["ids" => $sembrados, "consultas" => $resultados]); });'
            . ' include "tareas.php";';

        $errores = (string) tempnam(sys_get_temp_dir(), 'etiquetado');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$escrito, $estado] = array_pad(explode('@@ESTADO@@', implode("\n", $salida), 2), 2, 'null');
        $estado = json_decode($estado, true) ?? ['ids' => [], 'consultas' => []];
        $ejecucion = ['respuesta' => json_decode(trim($escrito), true), 'errores' => (string) file_get_contents($errores)] + $estado;
        unlink($errores);

        return $ejecucion;
    }
}
