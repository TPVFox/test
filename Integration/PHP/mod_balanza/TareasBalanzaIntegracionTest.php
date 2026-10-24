<?php

/**
 * La petición de fondo de las balanzas (`modulos/mod_balanza/tareas.php`): dar de alta, modificar y
 * eliminar balanzas y su configuración, añadir, cambiar y quitar teclas, listar las teclas y los
 * productos al peso, buscar un producto, crear la carpeta de una balanza y el volcado completo.
 *
 * Cada petición se ejecuta en un PHP aparte, desde la carpeta del módulo, con la sesión del usuario de
 * pruebas o sin ninguna, y los permisos del módulo concedidos o con sus acciones quitadas. Tras el
 * arranque común se abre una transacción en su conexión, y las clases escriben por esa misma conexión:
 * lo que siembra el caso y lo que guarda la petición se deshace al terminar el proceso. Antes de
 * terminar, el proceso escribe las balanzas y sus teclas.
 *
 * La carpeta temporal de la configuración, donde vive la carpeta de cada balanza, se cambia por una del
 * caso, que se borra al terminar. Esa carpeta nunca tiene el driver de la balanza: ni el volcado ni
 * ningún otro caso lo ejecuta.
 *
 * La preparación es una lista de sentencias; cada alta de un producto queda con un nombre —`:a1`,
 * `:a2`…, y `:aN` el último— y cada alta de una balanza también —`:b1`, `:b2`…—, que las sentencias
 * siguientes y el formulario pueden usar.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModBalanza;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class TareasBalanzaIntegracionTest extends CasoIntegracion
{
    private int $idUsuario;

    private int $idTienda;

    private string $carpeta;

    protected function setUp(): void
    {
        parent::setUp();
        $siembra = new Siembra($this->db);
        $this->idUsuario = $siembra->usuarioPorDefecto();
        $this->idTienda = $siembra->tiendaPorDefecto();
        $this->carpeta = sys_get_temp_dir() . '/balanzas-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->carpeta . '/tmp', 0755, true);
    }

    protected function tearDown(): void
    {
        $ficheros = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->carpeta, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($ficheros as $fichero) {
            $fichero->isDir() ? rmdir($fichero->getPathname()) : unlink($fichero->getPathname());
        }
        rmdir($this->carpeta);
        parent::tearDown();
    }

    /** @comportamiento Dar de alta una balanza la guarda con su nombre, su modelo, sus secciones, su grupo, su dirección, su IP y si solo recibe teclas. */
    public function test_darDeAltaGuardaLaBalanzaConSusDatos(): void
    {
        $peticion = $this->ejecutar($this->alta(['nombreBalanza' => 'Mostrador', 'soloPLUS' => '1']));

        self::assertSame([[
            'nombreBalanza' => 'Mostrador', 'modelo' => 'Dibal 500', 'conSeccion' => 'si', 'Grupo' => '1', 'Dirección' => '2', 'IP' => '192.168.1.50', 'soloPLUS' => '1',
        ]], array_map(fn (array $b): array => array_diff_key($b, ['idBalanza' => 0]), $peticion['balanzas']));
    }

    /** @comportamiento Dar de alta una balanza responde que se registró. */
    public function test_darDeAltaRespondeQueSeRegistro(): void
    {
        $peticion = $this->ejecutar($this->alta(['nombreBalanza' => 'Mostrador']));

        self::assertCount(1, $peticion['balanzas']);
        self::assertStringContainsString('Balanza registrada', $peticion['respuesta']['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_balanza/tareas.php:1-12
     *
     * @group defecto
     * @group permisos
     * @group alto
     *
     * @que-ocurre-hoy Sin sesión, la petición da de alta la balanza.
     * @que-deberia-ocurrir Que sin sesión no se atienda.
     * @por-que-ocurre La petición no mira la sesión: el arranque común la deja pasar.
     * @como-deberia-funcionar Comprobar la sesión al empezar la petición.
     */
    public function test_sinSesionNoSeDaDeAlta(): void
    {
        $peticion = $this->ejecutar($this->alta(['nombreBalanza' => 'Mostrador']), [], false);

        self::assertSame([], $peticion['balanzas']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_balanza/tareas.php:245-254
     *
     * @group defecto
     * @group permisos
     * @group alto
     *
     * @que-ocurre-hoy Un usuario sin el permiso de crear balanzas, que el listado no deja eliminar, elimina
     *   una balanza pidiéndolo a la petición.
     * @que-deberia-ocurrir Que la petición exija el mismo permiso que el listado.
     * @por-que-ocurre La petición no consulta ningún permiso.
     * @como-deberia-funcionar Consultar el permiso de cada orden que escribe.
     */
    public function test_sinPermisoNoSeEliminaUnaBalanza(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'eliminarBalanza', 'idBalanza' => ':b1'], [$this->balanza('Mostrador')], true, false);

        self::assertCount(1, $peticion['balanzas']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_balanza/clases/ClaseBalanza.php:26-37
     *
     * @group defecto
     * @group alta
     * @group alto
     *
     * @que-ocurre-hoy Un nombre con comillas dobles —«Mostrador "Norte"»— rompe la sentencia del alta: la
     *   balanza no se guarda. Lo que llega en la petición forma parte de la sentencia.
     * @que-deberia-ocurrir Que el nombre se guarde tal cual.
     * @por-que-ocurre Los datos se pegan a la sentencia sin escapar.
     * @como-deberia-funcionar Pasar los datos como parámetros de la sentencia.
     */
    public function test_unNombreConComillasSeGuardaTalCual(): void
    {
        $peticion = $this->ejecutar($this->alta(['nombreBalanza' => 'Mostrador "Norte"']));

        self::assertSame(['Mostrador "Norte"'], array_column($peticion['balanzas'], 'nombreBalanza'));
    }

    /** @comportamiento Modificar una balanza cambia su nombre, su modelo y sus secciones, y nada más. */
    public function test_modificarCambiaElNombreElModeloYLasSecciones(): void
    {
        $peticion = $this->ejecutar(
            ['pulsado' => 'modificarBalanza', 'idBalanza' => ':b1', 'nombreBalanza' => 'Charcutería', 'modeloBalanza' => 'Dibal 700', 'secciones' => 'si', 'IP' => '10.0.0.1'],
            [$this->balanza('Mostrador')]
        );

        self::assertSame('0', $peticion['respuesta']['modif']['error']);
        self::assertSame(['Charcutería', 'Dibal 700', 'si', '192.168.1.50'], [$peticion['balanzas'][0]['nombreBalanza'], $peticion['balanzas'][0]['modelo'], $peticion['balanzas'][0]['conSeccion'], $peticion['balanzas'][0]['IP']]);
    }

    /** @comportamiento La configuración avanzada guarda la IP, el grupo, la dirección y si solo recibe teclas. */
    public function test_laConfiguracionAvanzadaGuardaLaComunicacion(): void
    {
        $peticion = $this->ejecutar(
            ['pulsado' => 'guardarConfigAvanzada', 'idBalanza' => ':b1', 'ipBalanza' => '10.0.0.7', 'grupoBalanza' => '4', 'direccionBalanza' => '9', 'soloPLUS' => '0'],
            [$this->balanza('Mostrador')]
        );

        self::assertSame(['10.0.0.7', '4', '9', '0'], [$peticion['balanzas'][0]['IP'], $peticion['balanzas'][0]['Grupo'], $peticion['balanzas'][0]['Dirección'], $peticion['balanzas'][0]['soloPLUS']]);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_balanza/tareas.php:221-227, :249-253, :272-277; modulos/mod_balanza/clases/ClaseBalanza.php:233-237, :296-299
     *
     * @group defecto
     * @group respuesta
     * @group bajo
     *
     * @que-ocurre-hoy Guardar la configuración avanzada, eliminar una balanza y guardar todas las teclas
     *   se hacen, y responden que fallaron: «Error al guardar la configuración avanzada: 0», «Error al
     *   eliminar la balanza: 0» y un guardado sin éxito.
     * @que-deberia-ocurrir Que lo que se guarda responda que se guardó.
     * @por-que-ocurre La clase devuelve el resultado de la sentencia, que siempre lleva su error —«0» si
     *   no lo hubo—, y la petición toma cualquier error como fallo.
     * @como-deberia-funcionar Tomar como fallo solo un error distinto de «0».
     */
    public function test_loQueSeGuardaRespondeQueSeGuardo(): void
    {
        $configuracion = $this->ejecutar(
            ['pulsado' => 'guardarConfigAvanzada', 'idBalanza' => ':b1', 'ipBalanza' => '10.0.0.7', 'grupoBalanza' => '4', 'direccionBalanza' => '9', 'soloPLUS' => '0'],
            [$this->balanza('Mostrador')]
        );
        $eliminar = $this->ejecutar(['pulsado' => 'eliminarBalanza', 'idBalanza' => ':b1'], [$this->balanza('Mostrador')]);
        $todas = $this->ejecutar(
            ['pulsado' => 'guardarTodosPlus', 'idBalanza' => ':b1', 'datos' => '[{"idArticulo":":a1","plu":"9","seccion":"0"}]'],
            [$this->balanza('Mostrador'), ...$this->producto('Pera', '1001', '2.30'), $this->teclaSembrada('7', '0', ':a1')]
        );

        self::assertSame(['10.0.0.7', [], [[':b1', '9', '0', ':a1']]], [$configuracion['balanzas'][0]['IP'], $eliminar['balanzas'], $this->teclas($todas)]);
        self::assertSame('Configuración avanzada guardada correctamente.', $configuracion['respuesta']['mensaje']);
        self::assertSame('Balanza eliminada correctamente.', $eliminar['respuesta']['mensaje']);
        self::assertTrue($todas['respuesta']['success']);
    }

    /** @comportamiento Añadir una tecla la guarda con su producto y responde su línea. */
    public function test_anadirUnaTeclaLaGuardaYRespondeSuLinea(): void
    {
        $peticion = $this->ejecutar(
            $this->tecla(['plu' => '7']),
            [$this->balanza('Mostrador'), ...$this->producto('Pera conferencia', '1001', '2.30')]
        );

        self::assertSame([[':b1', '7', '0', ':a1']], $this->teclas($peticion));
        self::assertStringContainsString('<tr id="plu_7">', $peticion['respuesta']['html']);
        self::assertStringContainsString('Pera conferencia', $peticion['respuesta']['html']);
    }

    /** @comportamiento En una balanza sin secciones, una tecla ocupada no se añade: se responde con el producto que la tiene. */
    public function test_unaTeclaOcupadaNoSeAnade(): void
    {
        $peticion = $this->ejecutar(
            $this->tecla(['plu' => '7', 'idArticulo' => ':a2']),
            [$this->balanza('Mostrador'), ...$this->producto('Pera', '1001', '2.30'), ...$this->producto('Manzana', '1002', '1.90'), $this->teclaSembrada('7', '0', ':a1')]
        );

        self::assertSame([[':b1', '7', '0', ':a1']], $this->teclas($peticion));
        self::assertStringContainsString('Ya existe el producto con id:' . $peticion['ids'][':a1'], $peticion['respuesta']['error']);
    }

    /** @comportamiento En una balanza con secciones, la misma tecla se añade en otra sección, y no en la suya. */
    public function test_conSeccionesLaTeclaEsDeSuSeccion(): void
    {
        $preparacion = [$this->balanza('Mostrador', 'si'), ...$this->producto('Pera', '1001', '2.30'), ...$this->producto('Manzana', '1002', '1.90'), $this->teclaSembrada('7', '1', ':a1')];

        $otraSeccion = $this->ejecutar($this->tecla(['plu' => '7', 'seccion' => '2', 'idArticulo' => ':a2']), $preparacion);
        $mismaSeccion = $this->ejecutar($this->tecla(['plu' => '7', 'seccion' => '1', 'idArticulo' => ':a2']), $preparacion);

        self::assertSame([[':b1', '7', '1', ':a1'], [':b1', '7', '2', ':a2']], $this->teclas($otraSeccion));
        self::assertSame([[':b1', '7', '1', ':a1']], $this->teclas($mismaSeccion));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_balanza/tareas.php:179-196; modulos/mod_balanza/clases/ClaseBalanza.php:119-130
     *
     * @group defecto
     * @group teclas
     * @group alto
     *
     * @que-ocurre-hoy Cambiar la tecla de un producto a una que ya tiene otro la guarda: la balanza queda
     *   con dos productos en la misma tecla.
     * @que-deberia-ocurrir Que se rechace, como al añadir una tecla.
     * @por-que-ocurre El cambio solo mira si la tecla es distinta de la que tenía el producto.
     * @como-deberia-funcionar Comprobar, al cambiar, que la tecla está libre en la balanza y su sección.
     */
    public function test_cambiarAUnaTeclaOcupadaNoSeGuarda(): void
    {
        $peticion = $this->ejecutar(
            ['pulsado' => 'guardarPlu', 'idBalanza' => ':b1', 'idArticulo' => ':a2', 'plu' => '7'],
            [$this->balanza('Mostrador'), ...$this->producto('Pera', '1001', '2.30'), ...$this->producto('Manzana', '1002', '1.90'), $this->teclaSembrada('7', '0', ':a1'), $this->teclaSembrada('8', '0', ':a2')]
        );

        self::assertSame([[':b1', '7', '0', ':a1'], [':b1', '8', '0', ':a2']], $this->teclas($peticion));
    }

    /** @comportamiento Guardar todas las teclas cambia las que cambiaron. */
    public function test_guardarTodasCambiaLasQueCambiaron(): void
    {
        $peticion = $this->ejecutar(
            ['pulsado' => 'guardarTodosPlus', 'idBalanza' => ':b1', 'datos' => '[{"idArticulo":":a1","plu":"7","seccion":"0"},{"idArticulo":":a2","plu":"9","seccion":"0"}]'],
            [$this->balanza('Mostrador'), ...$this->producto('Pera', '1001', '2.30'), ...$this->producto('Manzana', '1002', '1.90'), $this->teclaSembrada('7', '0', ':a1'), $this->teclaSembrada('8', '0', ':a2')]
        );

        self::assertSame([[':b1', '7', '0', ':a1'], [':b1', '9', '0', ':a2']], $this->teclas($peticion));
    }

    /** @comportamiento Quitar una tecla de una balanza sin secciones la quita. */
    public function test_quitarUnaTeclaLaQuita(): void
    {
        $peticion = $this->ejecutar(
            ['pulsado' => 'eliminarPlu', 'idBalanza' => ':b1', 'plu' => '7'],
            [$this->balanza('Mostrador'), ...$this->producto('Pera', '1001', '2.30'), ...$this->producto('Manzana', '1002', '1.90'), $this->teclaSembrada('7', '0', ':a1'), $this->teclaSembrada('8', '0', ':a2')]
        );

        self::assertTrue($peticion['respuesta']['success']);
        self::assertSame([[':b1', '8', '0', ':a2']], $this->teclas($peticion));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_balanza/tareas.php:119-123; modulos/mod_balanza/clases/ClaseBalanza.php:142-149; modulos/mod_balanza/funciones.php:127
     *
     * @group defecto
     * @group teclas
     * @group alto
     *
     * @que-ocurre-hoy En una balanza con secciones, quitar la tecla 7 de un producto quita también la
     *   tecla 7 de las demás secciones, con sus productos.
     * @que-deberia-ocurrir Que se quite solo la tecla del producto.
     * @por-que-ocurre Se quita por el número de tecla, sin la sección ni el producto.
     * @como-deberia-funcionar Quitar por la balanza y el producto, o por la tecla y su sección.
     */
    public function test_conSeccionesQuitarUnaTeclaSoloQuitaLaSuya(): void
    {
        $peticion = $this->ejecutar(
            ['pulsado' => 'eliminarPlu', 'idBalanza' => ':b1', 'plu' => '7'],
            [$this->balanza('Mostrador', 'si'), ...$this->producto('Pera', '1001', '2.30'), ...$this->producto('Manzana', '1002', '1.90'), $this->teclaSembrada('7', '1', ':a1'), $this->teclaSembrada('7', '2', ':a2')]
        );

        self::assertCount(1, $this->teclas($peticion));
    }

    /** @comportamiento Se responde si una balanza tiene teclas. */
    public function test_seRespondeSiUnaBalanzaTieneTeclas(): void
    {
        $preparacion = [$this->balanza('Mostrador'), $this->balanza('Trastienda'), ...$this->producto('Pera', '1001', '2.30'), $this->teclaSembrada('7', '0', ':a1')];

        $conTeclas = $this->ejecutar(['pulsado' => 'tienePlusAsociados', 'idBalanza' => ':b1'], $preparacion);
        $sinTeclas = $this->ejecutar(['pulsado' => 'tienePlusAsociados', 'idBalanza' => ':b2'], $preparacion);

        self::assertSame([true, false], [$conTeclas['respuesta']['tienePlus'], $sinTeclas['respuesta']['tienePlus']]);
    }

    /** @comportamiento Eliminar una balanza sin teclas la elimina. */
    public function test_eliminarUnaBalanzaSinTeclasLaElimina(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'eliminarBalanza', 'idBalanza' => ':b1'], [$this->balanza('Mostrador')]);

        self::assertSame([], $peticion['balanzas']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_balanza/tareas.php:245-254; modulos/mod_balanza/clases/ClaseBalanza.php:229-238
     *
     * @group defecto
     * @group alta
     * @group medio
     *
     * @que-ocurre-hoy Eliminar una balanza con teclas la elimina y deja sus teclas, sin balanza.
     * @que-deberia-ocurrir Que una balanza con teclas no se elimine, como avisa el listado.
     * @por-que-ocurre Solo el navegador pregunta antes si tiene teclas; la petición de eliminar no lo mira.
     * @como-deberia-funcionar Comprobarlo en la petición de eliminar.
     */
    public function test_unaBalanzaConTeclasNoSeElimina(): void
    {
        $peticion = $this->ejecutar(
            ['pulsado' => 'eliminarBalanza', 'idBalanza' => ':b1'],
            [$this->balanza('Mostrador'), ...$this->producto('Pera', '1001', '2.30'), $this->teclaSembrada('7', '0', ':a1')]
        );

        self::assertCount(1, $peticion['balanzas']);
    }

    /**
     * @comportamiento Los datos de una balanza responden sus teclas en orden, marcan el producto que tiene
     *   dos teclas y avisan de los números que faltan entre una y la siguiente.
     */
    public function test_losDatosDeUnaBalanzaListanSusTeclas(): void
    {
        $peticion = $this->ejecutar(
            ['pulsado' => 'mostrarDatosBalanza', 'idBalanza' => ':b1', 'filtro' => 'a.plu'],
            [$this->balanza('Mostrador'), ...$this->producto('Pera', '1001', '2.30'), ...$this->producto('Manzana', '1002', '1.90'), $this->teclaSembrada('9', '0', ':a1'), $this->teclaSembrada('7', '0', ':a2'), $this->teclaSembrada('6', '0', ':a1')]
        );

        $html = $peticion['respuesta']['html'];
        self::assertStringContainsString('<b>Nombre de balanza: </b>Mostrador', $peticion['respuesta']['htmlDatosBalanza']);
        self::assertSame(['6', '7', '9'], array_column(json_decode($peticion['respuesta']['datosPlu'], true)['datos'], 'plu'));
        self::assertSame(1, substr_count($html, 'Producto duplicado en esta balanza'));
        self::assertSame(1, substr_count($html, 'Faltan números entre el anterior y el siguiente'));
    }

    /** @comportamiento Los productos al peso que se ofrecen son los que no tienen tecla en la balanza. */
    public function test_seOfrecenLosProductosAlPesoSinTecla(): void
    {
        $peticion = $this->ejecutar(
            ['pulsado' => 'mostrarArticulosPeso', 'idBalanza' => ':b1'],
            [$this->balanza('Mostrador'), ...$this->producto('Pera', '1001', '2.30'), ...$this->producto('Manzana', '1002', '1.90'), ...$this->producto('Leche', '1003', '0.99', 'unidad'), $this->teclaSembrada('7', '0', ':a1')]
        );

        $html = $peticion['respuesta']['html'];
        self::assertStringContainsString('Manzana', $html);
        self::assertStringNotContainsString('Pera', $html);
        self::assertStringNotContainsString('Leche', $html);
    }

    /** @comportamiento Buscar un producto por su nombre, con un solo resultado, responde sus datos. */
    public function test_buscarUnProductoConUnResultadoRespondeSusDatos(): void
    {
        $peticion = $this->ejecutar(
            ['pulsado' => 'buscarProducto', 'campo' => 'a.articulo_name', 'busqueda' => 'Pera conferencia balanza', 'idcaja' => 'nombreProducto'],
            [...$this->producto('Pera conferencia balanza', '1001', '2.30')]
        );

        self::assertSame(['idArticulo' => $peticion['ids'][':a1'], 'nombre' => 'Pera conferencia balanza', 'referencia' => '1001', 'codBarras' => null], $peticion['respuesta']['datos']);
    }

    /** @comportamiento Crear la carpeta de una balanza la crea en la carpeta temporal, con su nombre sin espacios y su número. */
    public function test_crearLaCarpetaLaCreaEnLaCarpetaTemporal(): void
    {
        $peticion = $this->ejecutar($this->carpetaDe('Mostrador Norte'), [$this->balanza('Mostrador Norte')]);

        self::assertFileExists($this->carpeta . '/tmp/MostradorNorte' . $peticion['ids'][':b1'] . '/balctrol');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_balanza/tareas.php:172-176
     *
     * @group defecto
     * @group carpeta
     * @group alto
     *
     * @que-ocurre-hoy Con un nombre como «../fuera», la carpeta de la balanza se crea fuera de la carpeta
     *   temporal, con sus ficheros.
     * @que-deberia-ocurrir Que la carpeta de la balanza quede siempre dentro de la temporal.
     * @por-que-ocurre La ruta se compone con el nombre que llega en la petición, sin limpiarlo.
     * @como-deberia-funcionar Componerla con el número de la balanza, o con un nombre limpio.
     */
    public function test_laCarpetaQuedaDentroDeLaTemporal(): void
    {
        $peticion = $this->ejecutar($this->carpetaDe('../fuera'), [$this->balanza('Mostrador')]);

        self::assertDirectoryDoesNotExist($this->carpeta . '/fuera' . $peticion['ids'][':b1']);
    }

    /** @comportamiento Sin IP, el volcado completo responde que hay que configurarla. */
    public function test_elVolcadoSinIpPideConfigurarla(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'volcadoCompleto', 'idBalanza' => ':b1', 'plusBalanza' => '[]'], [$this->balanza('Mostrador', 'no', '')]);

        self::assertTrue($peticion['respuesta']['error']);
        self::assertStringContainsString('no tiene una IP configurada', $peticion['respuesta']['mensaje']);
    }

    /** @comportamiento Sin driver en la carpeta de la balanza, el volcado responde un error y no da el envío por hecho. */
    public function test_elVolcadoSinDriverRespondeUnError(): void
    {
        $peticion = $this->ejecutar($this->volcado(), $this->conDosTeclas(), true, true, true);

        self::assertTrue($peticion['respuesta']['error']);
        self::assertStringContainsString('Fallo al ejecutar el driver', $peticion['respuesta']['mensaje']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_balanza/funciones.php:491-492
     *
     * @group defecto
     * @group volcado
     * @group alto
     *
     * @que-ocurre-hoy El volcado completo prepara los registros de todas las teclas y escribe en el fichero
     *   de envío solo el que tiene por número el de la balanza: con más registros que ese número, uno
     *   cualquiera; si no, ninguno.
     * @que-deberia-ocurrir Que el fichero de envío lleve los dos registros —el del precio y el del tipo—
     *   de cada tecla.
     * @por-que-ocurre Se escribe un elemento de la lista de registros, el del número de la balanza, en
     *   lugar de la lista entera.
     * @como-deberia-funcionar Escribir todos los registros.
     */
    public function test_elVolcadoEscribeLosRegistrosDeTodasLasTeclas(): void
    {
        $peticion = $this->ejecutar($this->volcado(), $this->conDosTeclas(), true, true, true);

        $envio = (string) file_get_contents($this->carpeta . '/tmp/Mostrador' . $peticion['ids'][':b1'] . '/filetx');
        self::assertSame(2, substr_count($envio, 'L2'), 'Los registros del precio');
        self::assertSame(2, substr_count($envio, 'L3'), 'Los registros del tipo');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_balanza/funciones.php:452-484; modulos/mod_balanza/tareas.php:279-298
     *
     * @group defecto
     * @group volcado
     * @group medio
     *
     * @que-ocurre-hoy Un producto sin referencia de tienda termina el volcado con una excepción: la
     *   respuesta no llega, y la ficha no dice nada.
     * @que-deberia-ocurrir Que se responda un error que diga qué producto no se pudo enviar.
     * @por-que-ocurre La traducción rechaza el registro sin código con una excepción, y el volcado no la
     *   recoge.
     * @como-deberia-funcionar Recoger el rechazo de cada producto y responderlo como error.
     */
    public function test_unProductoQueLaBalanzaNoAdmiteSeRespondeComoError(): void
    {
        $peticion = $this->ejecutar(
            ['pulsado' => 'volcadoCompleto', 'idBalanza' => ':b1', 'plusBalanza' => '[{"idArticulo":":a1","crefTienda":null,"pvpCiva":"2.30","plu":"7","seccion":"0"}]'],
            [$this->balanza('Mostrador'), ...$this->producto('Pera', null, '2.30'), $this->teclaSembrada('7', '0', ':a1')],
            true,
            true,
            true
        );

        self::assertIsArray($peticion['respuesta']);
        self::assertTrue($peticion['respuesta']['error']);
    }

    /** @comportamiento El formulario de una tecla esconde la sección en una balanza sin secciones, y la enseña con ellas. */
    public function test_elFormularioDeUnaTeclaEscondeLaSeccionSinSecciones(): void
    {
        $sin = $this->ejecutar(['pulsado' => 'htmlPlu', 'secciones' => 'no', 'idBalanza' => '3']);
        $con = $this->ejecutar(['pulsado' => 'htmlPlu', 'secciones' => 'si', 'idBalanza' => '3']);

        self::assertStringContainsString('<div class="col-md-12" style="display:none"><label>Sección:</label>', $sin['respuesta']['html']);
        self::assertStringNotContainsString('display:none', $con['respuesta']['html']);
        self::assertStringContainsString('onclick="addPlu(3)"', $con['respuesta']['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_balanza/tareas.php:87-90, :107-115; modulos/mod_balanza/funciones.php:193, :206
     *
     * @group defecto
     * @group teclas
     * @group medio
     *
     * @que-ocurre-hoy Añadir una tecla libre responde su línea con cuatro avisos de PHP: la búsqueda de
     *   la tecla sin resultados no trae lista, y la línea pide el tipo y el proveedor del producto, que
     *   la petición no le pasa.
     * @que-deberia-ocurrir Que la tecla se añada sin avisos, y su línea lleve el tipo y el proveedor.
     * @por-que-ocurre La consulta sin filas no devuelve lista, y la petición la recorre sin mirarlo; la
     *   línea se compone con los datos que manda el navegador.
     * @como-deberia-funcionar Tomar como lista vacía la consulta sin filas, y componer la línea con los
     *   datos del producto en la base.
     */
    public function test_anadirUnaTeclaNoDejaAvisos(): void
    {
        $peticion = $this->ejecutar($this->tecla(['plu' => '7']), [$this->balanza('Mostrador'), ...$this->producto('Pera conferencia', '1001', '2.30')]);

        self::assertStringContainsString('<tr id="plu_7">', $peticion['respuesta']['html']);
        self::assertSame('', $peticion['errores']);
    }

    /**
     * @comportamiento Buscar un producto con varios resultados, o por un código de barras que no es
     *   exactamente el suyo, responde el buscador con los productos encontrados.
     */
    public function test_buscarConVariosResultadosRespondeElBuscador(): void
    {
        $preparacion = [...$this->producto('Pera balanza uno', '1001', '2.30'), ...$this->producto('Pera balanza dos', '1002', '2.50'), 'INSERT INTO articulosCodigoBarras (idArticulo, codBarras) VALUES (:a1, \'8400000000017\')'];

        $varios = $this->ejecutar(['pulsado' => 'buscarProducto', 'campo' => 'a.articulo_name', 'busqueda' => 'Pera  balanza', 'idcaja' => 'nombreProducto'], $preparacion);
        $codigo = $this->ejecutar(['pulsado' => 'buscarProducto', 'campo' => 'c.codBarras', 'busqueda' => '840000000001', 'idcaja' => 'codBarras'], $preparacion);

        self::assertCount(2, $varios['respuesta']['buscar']['datos']);
        self::assertStringContainsString('Pera balanza dos', $varios['respuesta']['html']);
        self::assertStringContainsString('seleccionProductoModal(' . $codigo['ids'][':a1'] . ",'Pera balanza uno','1001','8400000000017'", $codigo['respuesta']['html']);
        self::assertArrayNotHasKey('datos', $codigo['respuesta']);
    }

    /** @comportamiento Buscar un producto por su número responde sus datos. */
    public function test_buscarPorNumeroRespondeElProducto(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'buscarProducto', 'campo' => 'a.idArticulo', 'busqueda' => ':a1', 'idcaja' => 'idArticulo'], [...$this->producto('Pera conferencia', '1001', '2.30')]);

        self::assertSame($peticion['ids'][':a1'], $peticion['respuesta']['datos']['idArticulo']);
    }

    /** @comportamiento Guardar una tecla sin cambios no la toca y lo dice. */
    public function test_guardarUnaTeclaSinCambiosNoLaToca(): void
    {
        $peticion = $this->ejecutar(
            ['pulsado' => 'guardarPlu', 'idBalanza' => ':b1', 'idArticulo' => ':a1', 'plu' => '7', 'seccion' => '0'],
            [$this->balanza('Mostrador'), ...$this->producto('Pera', '1001', '2.30'), $this->teclaSembrada('7', '0', ':a1')]
        );

        self::assertSame(['success' => true, 'mensaje' => 'Sin cambios'], $peticion['respuesta']['resultado']);
    }

    /** @comportamiento Ordenadas por tecla de sección, las teclas de una balanza llevan ese orden en el selector. */
    public function test_losDatosOrdenadosPorSeccion(): void
    {
        $peticion = $this->ejecutar(
            ['pulsado' => 'mostrarDatosBalanza', 'idBalanza' => ':b1', 'filtro' => 'a.seccion'],
            [$this->balanza('Mostrador', 'si'), ...$this->producto('Pera', '1001', '2.30'), ...$this->producto('Manzana', '1002', '1.90'), $this->teclaSembrada('7', '2', ':a1'), $this->teclaSembrada('8', '1', ':a2')]
        );

        self::assertSame(['8', '7'], array_column(json_decode($peticion['respuesta']['datosPlu'], true)['datos'], 'plu'));
        self::assertMatchesRegularExpression('/<select id="filtroBalanza" ><option value="a.seccion"/', $peticion['respuesta']['htmlDatosBalanza']);
    }

    /** @comportamiento Sin productos al peso sin tecla, se dice que no hay. */
    public function test_sinProductosAlPesoSeDiceQueNoHay(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'mostrarArticulosPeso', 'idBalanza' => ':b1'], [$this->balanza('Mostrador'), ...$this->producto('Pera', '1001', '2.30'), $this->teclaSembrada('7', '0', ':a1')]);

        self::assertStringContainsString('No hay artículos de peso disponibles.', $peticion['respuesta']['html']);
    }

    /** @comportamiento Modificar una balanza que no existe lo dice, y sin cambios, también. */
    public function test_modificarSinBalanzaOSinCambiosLoDice(): void
    {
        $datos = ['pulsado' => 'modificarBalanza', 'nombreBalanza' => 'Mostrador', 'modeloBalanza' => 'Dibal 500', 'secciones' => 'no'];

        $sinBalanza = $this->ejecutar($datos + ['idBalanza' => '0']);
        $sinCambios = $this->ejecutar($datos + ['idBalanza' => ':b1'], [$this->balanza('Mostrador')]);

        self::assertSame(['error' => 'Balanza no encontrada'], $sinBalanza['respuesta']['modif']);
        self::assertSame(['mensaje' => 'No hay cambios para actualizar'], $sinCambios['respuesta']['modif']);
    }

    /** @comportamiento Sin la carpeta de la balanza, el volcado responde que no pudo escribir el fichero de envío. */
    public function test_elVolcadoSinCarpetaRespondeQueNoPudoEscribir(): void
    {
        $peticion = $this->ejecutar($this->volcado(), $this->conDosTeclas());

        self::assertTrue($peticion['respuesta']['error']);
        self::assertStringContainsString('No se pudo escribir el archivo', $peticion['respuesta']['mensaje']);
    }

    /** @comportamiento Buscar un producto por su código de barras exacto responde sus datos. */
    public function test_buscarPorSuCodigoDeBarrasRespondeElProducto(): void
    {
        $peticion = $this->ejecutar(
            ['pulsado' => 'buscarProducto', 'campo' => 'c.codBarras', 'busqueda' => '8400000000017', 'idcaja' => 'codBarras'],
            [...$this->producto('Pera conferencia', '1001', '2.30'), 'INSERT INTO articulosCodigoBarras (idArticulo, codBarras) VALUES (:a1, \'8400000000017\')']
        );

        self::assertSame(['idArticulo' => $peticion['ids'][':a1'], 'nombre' => 'Pera conferencia', 'referencia' => '1001', 'codBarras' => '8400000000017'], $peticion['respuesta']['datos']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_balanza/tareas.php:49-50, :67-70; modulos/mod_balanza/funciones.php:293-338
     *
     * @group defecto
     * @group teclas
     * @group alto
     *
     * @que-ocurre-hoy Buscar un producto que no existe termina la petición con un error de PHP: no
     *   llega respuesta, y el buscador no se abre.
     * @que-deberia-ocurrir Que responda el buscador diciendo que no hay resultados, como prevé.
     * @por-que-ocurre La consulta sin filas no devuelve lista, y la petición la cuenta.
     * @como-deberia-funcionar Tomar como lista vacía la consulta sin filas.
     */
    public function test_buscarUnProductoQueNoExisteRespondeQueNoHay(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'buscarProducto', 'campo' => 'a.articulo_name', 'busqueda' => 'Producto que no existe en ninguna tienda', 'idcaja' => 'nombreProducto']);

        self::assertIsArray($peticion['respuesta']);
        self::assertStringContainsString('cajaBusquedaProducto', $peticion['respuesta']['html']);
    }

    /** @comportamiento El volcado de una balanza con secciones prepara sus registros y, sin driver, responde el error. */
    public function test_elVolcadoConSeccionesRespondeElErrorDelDriver(): void
    {
        $peticion = $this->ejecutar(
            ['pulsado' => 'volcadoCompleto', 'idBalanza' => ':b1', 'plusBalanza' => '[{"idArticulo":":a1","crefTienda":"1001","pvpCiva":"2.30","plu":"7","seccion":"2"}]'],
            [$this->balanza('Mostrador', 'si'), ...$this->producto('Pera', '1001', '2.30'), $this->teclaSembrada('7', '2', ':a1')],
            true,
            true,
            true
        );

        self::assertTrue($peticion['respuesta']['error']);
        self::assertStringContainsString('Fallo al ejecutar el driver', $peticion['respuesta']['mensaje']);
    }

    /** @return list<string> */
    private function conDosTeclas(): array
    {
        return [$this->balanza('Mostrador'), ...$this->producto('Pera', '1001', '2.30'), ...$this->producto('Manzana', '1002', '1.90'), $this->teclaSembrada('7', '0', ':a1'), $this->teclaSembrada('8', '0', ':a2')];
    }

    /** @return array<string, string> */
    private function volcado(): array
    {
        return ['pulsado' => 'volcadoCompleto', 'idBalanza' => ':b1', 'plusBalanza' => '[{"idArticulo":":a1","crefTienda":"1001","pvpCiva":"2.30","plu":"7","seccion":"0"},{"idArticulo":":a2","crefTienda":"1002","pvpCiva":"1.90","plu":"8","seccion":"0"}]'];
    }

    /**
     * @param array<string, string> $datos
     * @return array<string, string>
     */
    private function alta(array $datos): array
    {
        return $datos + ['pulsado' => 'addBalanza', 'modeloBalanza' => 'Dibal 500', 'secciones' => 'si', 'Grupo' => '1', 'Direccion' => '2', 'IP' => '192.168.1.50'];
    }

    /**
     * @param array<string, string> $datos
     * @return array<string, string>
     */
    private function tecla(array $datos): array
    {
        return $datos + ['pulsado' => 'addPlu', 'idBalanza' => ':b1', 'idArticulo' => ':a1', 'cref' => '1001', 'articulo_name' => 'Pera conferencia', 'pvpCiva' => '2.30'];
    }

    /** @return array<string, string> */
    private function carpetaDe(string $nombre): array
    {
        return ['pulsado' => 'crearDirectorioBalanza', 'nombreBalanza' => $nombre, 'idBalanza' => ':b1', 'ipBalanza' => '192.168.1.50', 'grupoBalanza' => '01', 'direccionBalanza' => '02', 'ipPc' => '192.168.1.10', 'serieH' => '0', 'serieTipo' => '1', 'modoDirectorio' => 'Balctrol'];
    }

    private function balanza(string $nombre, string $secciones = 'no', string $ip = '192.168.1.50'): string
    {
        return "INSERT INTO modulo_balanza (nombreBalanza, modelo, conSeccion, Grupo, `Dirección`, IP, soloPLUS) VALUES ('{$nombre}', 'Dibal 500', '{$secciones}', 1, 2, '{$ip}', 1)";
    }

    /** @return list<string> */
    private function producto(string $nombre, ?string $referencia, string $precio, string $tipo = 'peso'): array
    {
        return [
            "INSERT INTO articulos (iva, articulo_name, estado, fecha_creado, ultimoCoste, tipo) VALUES (10.00, '{$nombre}', 'Activo', NOW(), 1, '{$tipo}')",
            'INSERT INTO articulosTiendas (idArticulo, idTienda, crefTienda, estado) VALUES (:aN, :tienda, ' . ($referencia === null ? 'NULL' : "'{$referencia}'") . ", 'Activo')",
            "INSERT INTO articulosPrecios (idArticulo, pvpCiva, pvpSiva, idTienda) VALUES (:aN, {$precio}, {$precio} / 1.1, :tienda)",
        ];
    }

    private function teclaSembrada(string $plu, string $seccion, string $producto): string
    {
        return "INSERT INTO modulo_balanza_plus (idBalanza, plu, seccion, idArticulo) VALUES (:b1, {$plu}, {$seccion}, {$producto})";
    }

    /**
     * Las teclas que quedaron, con los números sembrados vueltos a su nombre.
     *
     * @return list<list<string>>
     */
    private function teclas(array $peticion): array
    {
        $nombres = array_flip(array_diff_key($peticion['ids'], [':aN' => 0]));

        return array_map(fn (array $t): array => [$nombres[$t['idBalanza']] ?? $t['idBalanza'], $t['plu'], $t['seccion'], $nombres[$t['idArticulo']] ?? $t['idArticulo']], $peticion['teclas']);
    }

    /**
     * Ejecuta la petición de fondo en un PHP aparte y devuelve su respuesta, sus avisos, las balanzas y
     * las teclas que quedaron y los números de lo sembrado.
     *
     * @param array<string, string> $formulario
     * @param list<string> $preparacion
     * @return array{respuesta: mixed, errores: string, balanzas: list<array<string, ?string>>, teclas: list<array<string, string>>, ids: array<string, string>}
     */
    private function ejecutar(array $formulario, array $preparacion = [], bool $conSesion = true, bool $conPermisos = true, bool $carpetas = false): array
    {
        $sesion = $conSesion ? [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => $this->idUsuario, 'group_id' => 1, 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => $this->idTienda, 'razonsocial' => 'Tienda de pruebas'],
        ] : [];
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["DOCUMENT_ROOT"] = ""; $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = "/TPVFox/modulos/mod_balanza/tareas.php";'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_balanza', true) . ');'
            . ' require "./../../inicial.php";'
            . ' $rutatmp = str_repeat("/..", substr_count(rtrim($RutaServidor, "/"), "/")) . ' . var_export($this->carpeta . '/tmp', true) . ';'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ($conSesion ? ' new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' $BDTpv->query("UPDATE permisos SET permiso = IF(accion IS NULL OR accion = \'\', 1, ' . ($conPermisos ? 1 : 0) . ') WHERE idUsuario = ' . $this->idUsuario . ' AND modulo = \'mod_balanza\'");'
            . ' $ids = [":usuario" => "' . $this->idUsuario . '", ":tienda" => "' . $this->idTienda . '"]; $cuentas = ["articulos" => 0, "modulo_balanza" => 0];'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->query(strtr($sql, $ids));'
            . ' if (preg_match("/^INSERT INTO (articulos|modulo_balanza) /", $sql, $t)) { $clave = $t[1] === "articulos" ? ":a" : ":b";'
            . ' $ids[$clave . ++$cuentas[$t[1]]] = (string) $BDTpv->insert_id; if ($clave === ":a") { $ids[":aN"] = (string) $BDTpv->insert_id; } } }'
            . ($carpetas ? ' foreach ($BDTpv->query("SELECT idBalanza, nombreBalanza FROM modulo_balanza") as $b) { @mkdir(' . var_export($this->carpeta . '/tmp', true) . ' . "/" . str_replace(" ", "", $b["nombreBalanza"]) . $b["idBalanza"]); }' : '')
            . ' $_POST = array_map(fn ($valor) => strtr((string) $valor, $ids), ' . var_export($formulario, true) . ');'
            . ($conSesion ? ' $ClasePermisos = $thisTpv->permisos = new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' register_shutdown_function(function () use ($BDTpv, &$ids) { echo "\n@@DATOS@@" . json_encode(["ids" => $ids,'
            . ' "balanzas" => $BDTpv->query("SELECT * FROM modulo_balanza ORDER BY idBalanza")->fetch_all(MYSQLI_ASSOC),'
            . ' "teclas" => $BDTpv->query("SELECT idBalanza, plu, seccion, idArticulo FROM modulo_balanza_plus ORDER BY plu, seccion")->fetch_all(MYSQLI_ASSOC)]); });'
            . ' include "tareas.php";';

        $errores = (string) tempnam(sys_get_temp_dir(), 'balanzas');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -d error_log=/dev/null -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$escrito, $estado] = array_pad(explode('@@DATOS@@', implode("\n", $salida), 2), 2, 'null');
        $estado = json_decode($estado, true) ?? ['ids' => [], 'balanzas' => [], 'teclas' => []];
        $ejecucion = ['respuesta' => json_decode(trim($escrito), true), 'errores' => (string) file_get_contents($errores)] + $estado;
        unlink($errores);

        return $ejecucion;
    }
}
