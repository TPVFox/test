<?php

/**
 * Las clases base de acceso a datos de TPVFox: `ModeloP` (`modulos/claseModeloP.php`), con la
 * conexión compartida y las sentencias estáticas; `Modelo` (`modulos/claseModelo.php`) y `TFModelo`
 * (`clases/ClaseTFModelo.php`), que heredan de ella y de las que heredan las clases de los módulos.
 *
 * Cada caso las usa sobre la tabla de permisos, con un usuario que crea el propio caso, a través de
 * una subclase de prueba que solo hace públicos los métodos protegidos. La conexión del producto es
 * la del caso: nada queda escrito.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\Nucleo;

use TPVFox\Test\CasoIntegracion;

final class AccesoDatosIntegracionTest extends CasoIntegracion
{
    protected bool $compartirConexionConElProducto = true;

    private int $usuario = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/app/helpers.php');
        $this->incluirTPVFox('/clases/ClaseTFModelo.php');
        $this->incluirTPVFox('/modulos/claseModelo.php');

        $nombre = 'prueba_datos_' . bin2hex(random_bytes(3));
        $this->db->query("INSERT INTO usuarios (username, password, fecha, group_id, estado, nombre) VALUES ('$nombre', MD5('x'), CURDATE(), 1, 'activo', 'Prueba')");
        $this->usuario = (int) $this->db->insert_id;
    }

    // --- TFModelo ---------------------------------------------------------

    /**
     * @comportamiento Una consulta devuelve la sentencia y sus filas; sin filas, los datos son null
     *   y no hay error.
     */
    public function test_D1_unaConsultaDevuelveLaSentenciaYSusFilas(): void
    {
        $id = $this->permiso('mod_prueba', 'vista.php');
        $modelo = self::tfModelo();

        $con = $modelo->consulta("SELECT modulo FROM permisos WHERE id = $id");
        $sin = $modelo->consulta('SELECT modulo FROM permisos WHERE id = 0');

        self::assertSame(['consulta' => "SELECT modulo FROM permisos WHERE id = $id", 'datos' => [['modulo' => 'mod_prueba']]], $con);
        self::assertSame(['consulta' => 'SELECT modulo FROM permisos WHERE id = 0', 'datos' => null], $sin);
    }

    /**
     * @comportamiento Insertar devuelve el identificador de la fila nueva, sin escribir los campos
     *   nulos; pedido solo el texto, devuelve la sentencia sin ejecutarla. Una comilla simple en un
     *   valor se guarda tal cual.
     */
    public function test_D2_insertarDevuelveElIdentificadorYOmiteLosNulos(): void
    {
        $modelo = self::tfModelo();
        $datos = ['idUsuario' => $this->usuario, 'modulo' => "mod_d'Prueba", 'vista' => null, 'permiso' => 1];

        $sentencia = $modelo->insertar($datos, true);
        $id = $modelo->insertar($datos);

        self::assertSame("INSERT permisos SET idUsuario = '{$this->usuario}', modulo = 'mod_d\\'Prueba', permiso = '1'", $sentencia);
        self::assertSame(['modulo' => "mod_d'Prueba", 'vista' => null], $this->fila($id, 'modulo, vista'));
    }

    /**
     * @comportamiento Actualizar devuelve las filas afectadas; con la condición como columnas y
     *   valores, la compone como igualdades unidas por AND, y como lista de textos, los une por AND.
     *   Los datos pueden llegar ya compuestos. Pedido solo el texto, devuelve la sentencia.
     */
    public function test_D3_actualizarDevuelveLasFilasAfectadas(): void
    {
        $id = $this->permiso('mod_prueba', 'vista.php');
        $modelo = self::tfModelo();

        $sentencia = $modelo->actualizar(['permiso' => 0], ['id' => $id, 'modulo' => 'mod_prueba'], true, false, true);
        $afectadas = $modelo->actualizar(['permiso' => 0], ['id' => $id, 'modulo' => 'mod_prueba'], false, false, true);
        $conTexto = $modelo->actualizar(['permiso' => 1], "id = $id");
        $conListas = $modelo->actualizar('permiso = 0', ["id = $id", "modulo = 'mod_prueba'"], true);

        self::assertSame("UPDATE permisos SET permiso = '0' WHERE id = \"$id\" AND modulo = \"mod_prueba\"", $sentencia);
        self::assertSame(1, $afectadas);
        self::assertSame(1, $conTexto);
        self::assertSame("UPDATE permisos SET permiso = 0 WHERE id = $id AND modulo = 'mod_prueba'", $conListas, 'Los datos como texto y la condición como lista de textos');
        self::assertSame(['permiso' => '1'], $this->fila($id, 'permiso'));
    }

    /**
     * @estado rojo
     * @codigo-afectado clases/ClaseTFModelo.php:157
     *
     * @group esperado
     * @group datos
     * @group medio
     *
     * @que-ocurre-hoy Un texto con una barra invertida se guarda cambiado: «C:\nuevo» queda con un
     *   salto de línea en lugar de la barra y la ene, y una barra al final rompe la sentencia.
     * @que-deberia-ocurrir Que el texto se guarde tal como llega.
     * @por-que-ocurre Al componer la sentencia solo se escapa la comilla simple; la base interpreta
     *   la barra invertida como el comienzo de una secuencia de escape.
     * @como-deberia-funcionar Pasar los valores como parámetros de una sentencia preparada, o
     *   escaparlos con la función de la conexión.
     */
    public function test_D4_unTextoConBarraInvertidaSeGuardaTalCual(): void
    {
        $id = self::tfModelo()->insertar(['idUsuario' => $this->usuario, 'modulo' => 'C:\nuevo', 'permiso' => 1]);

        self::assertSame('C:\nuevo', $this->fila($id, 'modulo')['modulo'], 'El texto tiene que guardarse tal como llega.');
    }

    /**
     * @estado rojo
     * @codigo-afectado clases/ClaseTFModelo.php:203
     *
     * @group esperado
     * @group datos
     * @group medio
     *
     * @que-ocurre-hoy Una condición dada como columnas y valores con unas comillas dobles en un valor
     *   rompe la sentencia: la escritura no se hace y el error llega como excepción.
     * @que-deberia-ocurrir Que la condición busque ese valor tal como es.
     * @por-que-ocurre Cada valor se pone entre comillas dobles sin escaparlo.
     * @como-deberia-funcionar Pasar los valores como parámetros de una sentencia preparada.
     */
    public function test_D5_unaCondicionConComillasDoblesEnUnValorSeCumple(): void
    {
        $id = $this->permiso('mod_"comillas"', 'vista.php');

        $fallo = null;
        try {
            $afectadas = self::tfModelo()->actualizar(['permiso' => 0], ['modulo' => 'mod_"comillas"'], false, false, true);
        } catch (\mysqli_sql_exception $excepcion) {
            $fallo = $excepcion->getMessage();
        }

        self::assertNull($fallo, 'Unas comillas en el valor no pueden romper la sentencia.');
        self::assertSame(1, $afectadas);
        self::assertSame(['permiso' => '0'], $this->fila($id, 'permiso'));
    }

    /**
     * @comportamiento Insertar o actualizar cuenta las filas que cumplen la clave: si hay alguna, las
     *   actualiza sin tocar los campos nulos; si no, inserta la clave con los datos. Devuelve la
     *   sentencia del recuento, no lo que hizo.
     */
    public function test_D6_insertarOActualizarSegunExistaLaClave(): void
    {
        $modelo = self::tfModelo();
        $clave = ['idUsuario' => $this->usuario, 'modulo' => 'mod_clave', 'vista' => 'vista.php'];

        $primera = $modelo->insertOrUpdate($clave, ['permiso' => 1, 'accion' => null]);
        $segunda = $modelo->insertOrUpdate($clave, ['permiso' => 0, 'accion' => null]);

        $recuento = " SELECT count(id) as contador FROM permisos WHERE idUsuario = \"{$this->usuario}\" AND modulo = \"mod_clave\" AND vista = \"vista.php\"";
        self::assertSame($recuento, $primera);
        self::assertSame($recuento, $segunda);
        self::assertSame(
            [['permiso' => '0']],
            $this->db->query("SELECT permiso FROM permisos WHERE idUsuario = {$this->usuario} AND modulo = 'mod_clave'")->fetch_all(MYSQLI_ASSOC)
        );
    }

    /** @comportamiento Existe devuelve el recuento de filas con ese identificador, como texto; eliminar la borra y devuelve las afectadas. */
    public function test_D7_existeYEliminarPorIdentificador(): void
    {
        $id = $this->permiso('mod_prueba', 'vista.php');
        $modelo = self::tfModelo();

        self::assertSame('1', $modelo->existe($id));
        self::assertSame(['affected_rows' => 1, 'insert_id' => 0], $modelo->eliminar($id));
        self::assertSame('0', $modelo->existe($id));
    }

    /**
     * @comportamiento La información de una tabla trae su estado y sus columnas, con su tipo o solo
     *   el nombre; de una tabla que no existe, un error que la nombra.
     */
    public function test_D8_laInformacionDeUnaTablaTraeSuEstadoYSusColumnas(): void
    {
        $modelo = self::tfModelo();

        $conTipo = $modelo->InfoTabla('permisos');
        $soloNombre = $modelo->InfoTabla('permisos', 'no');
        $inexistente = $modelo->InfoTabla('tabla_que_no_existe');

        self::assertSame('permisos', $conTipo['info']['Name']);
        self::assertContains('id int(11)', $conTipo['campos']);
        self::assertSame(['id', 'idUsuario', 'modulo', 'vista', 'accion', 'permiso'], $soloNombre['campos']);
        self::assertSame('Error tabla no encontrada - tabla_que_no_existe', $inexistente['error']);
    }

    /** @comportamiento La conexión de la clase es la compartida por todas las que heredan de la base. */
    public function test_D9_laConexionEsLaCompartida(): void
    {
        self::assertSame($this->db, self::tfModelo()->conexionBDTPV());
        self::assertSame(['descripcion' => 0, 'consulta' => '', 'time_error' => ''], self::tfModelo()->getFallo());
    }

    /**
     * @estado rojo
     * @codigo-afectado clases/ClaseTFModelo.php:49-57
     *
     * @group esperado
     * @group datos
     * @group alto
     *
     * @que-ocurre-hoy Una escritura que la base rechaza no devuelve false ni deja el fallo para
     *   consultarlo, como dice la clase: el error llega como excepción, y quien llama no lo recoge.
     * @que-deberia-ocurrir Que devuelva false y deje el fallo, con su sentencia, en `getFallo()`.
     * @por-que-ocurre Desde PHP 8.1 `mysqli` lanza el error de la base como excepción, y TPVFox no
     *   cambia ese modo: la comprobación del resultado de la consulta no llega a hacerse.
     * @como-deberia-funcionar Recoger la excepción en la escritura y dejar el fallo, o fijar el modo
     *   de informe de `mysqli` que el código espera.
     */
    public function test_D10_unaEscrituraRechazadaDevuelveFalseYDejaElFallo(): void
    {
        $modelo = self::tfModelo();

        $fallo = null;
        try {
            $respuesta = $modelo->escribir('UPDATE tabla_que_no_existe SET a = 1');
        } catch (\mysqli_sql_exception $excepcion) {
            $fallo = $excepcion->getMessage();
        }

        self::assertNull($fallo, 'El error de la base no puede escapar como excepción.');
        self::assertFalse($respuesta);
        self::assertSame('UPDATE tabla_que_no_existe SET a = 1', $modelo->getFallo()['consulta']);
    }

    // --- ModeloP ----------------------------------------------------------

    /** @comportamiento Una consulta devuelve sus filas y deja la sentencia, sin error. */
    public function test_P1_unaConsultaDevuelveSusFilasYDejaLaSentencia(): void
    {
        $id = $this->permiso('mod_prueba', 'vista.php');
        $modelo = self::modeloP();

        $filas = $modelo->consultar("SELECT vista FROM permisos WHERE id = $id");

        self::assertSame([['vista' => 'vista.php']], $filas);
        self::assertSame("SELECT vista FROM permisos WHERE id = $id", \ModeloP::getSQLConsulta());
        self::assertFalse(\ModeloP::hayErrorConsulta());
    }

    /**
     * @comportamiento Insertar devuelve el identificador nuevo; pedido solo el texto, devuelve true y
     *   deja la sentencia para consultarla.
     */
    public function test_P2_insertarDevuelveElIdentificadorOTrueSiSoloSePideElTexto(): void
    {
        $modelo = self::modeloP();
        $datos = ['idUsuario' => $this->usuario, 'modulo' => 'mod_estatico', 'permiso' => 1];

        self::assertTrue($modelo->insertar('permisos', $datos, true));
        self::assertSame("INSERT permisos SET idUsuario = '{$this->usuario}', modulo = 'mod_estatico', permiso = '1'", \ModeloP::getSQLConsulta());

        $id = $modelo->insertar('permisos', $datos);
        self::assertSame(['modulo' => 'mod_estatico'], $this->fila($id, 'modulo'));

        $otro = $modelo->insertar('permisos', "idUsuario = {$this->usuario}, modulo = 'mod_texto', permiso = 1");
        self::assertSame(['modulo' => 'mod_texto'], $this->fila($otro, 'modulo'), 'Los datos pueden llegar ya compuestos');
    }

    /** @comportamiento Actualizar y borrar devuelven true si la base acepta la sentencia; la condición puede ser texto o una lista unida por AND. */
    public function test_P3_actualizarYBorrarDevuelvenTrue(): void
    {
        $id = $this->permiso('mod_prueba', 'vista.php');
        $modelo = self::modeloP();

        self::assertTrue($modelo->actualizar('permisos', ['permiso' => 0], ["id = $id", "modulo = 'mod_prueba'"]));
        self::assertSame(['permiso' => '0'], $this->fila($id, 'permiso'));
        self::assertTrue($modelo->actualizar('permisos', 'permiso = 1', "id = $id"));
        self::assertSame(['permiso' => '1'], $this->fila($id, 'permiso'));
        self::assertTrue($modelo->actualizar('permisos', ['permiso' => 0], "id = $id", true));
        self::assertTrue($modelo->borrar('permisos', ["id = $id", "modulo = 'mod_prueba'"], true));
        self::assertSame("DELETE FROM permisos WHERE id = $id AND modulo = 'mod_prueba'", \ModeloP::getSQLConsulta());
        self::assertSame(['permiso' => '1'], $this->fila($id, 'permiso'), 'Pedido solo el texto, no se escribe');
        self::assertTrue($modelo->borrar('permisos', "id = $id"));
        self::assertNull($this->fila($id, 'permiso'));
    }

    /** @comportamiento Leer compone la consulta con columnas, uniones, condiciones, límite y desplazamiento. */
    public function test_P4_leerComponeLaConsulta(): void
    {
        $modelo = self::modeloP();

        self::assertTrue($modelo->leer('permisos p', ['p.idUsuario = 0', "p.modulo = 'mod_venta'"], ['p.id', 'u.username'], ['usuarios u ON u.id = p.idUsuario'], 5, 10, true));
        self::assertSame(
            "SELECT p.id,u.username FROM permisos p JOIN usuarios u ON u.id = p.idUsuario WHERE  p.idUsuario = 0 AND p.modulo = 'mod_venta' LIMIT 5 OFFSET 10",
            \ModeloP::getSQLConsulta()
        );
        self::assertSame([], $modelo->leer('permisos', 'id = 0'));
        self::assertTrue($modelo->leer('permisos p', '', [], 'usuarios u ON u.id = p.idUsuario', 0, 0, true));
        self::assertSame('SELECT * FROM permisos p JOIN usuarios u ON u.id = p.idUsuario', \ModeloP::getSQLConsulta());
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/claseModeloP.php:121-123
     * @codigo-afectado modulos/claseModeloP.php:175-177
     *
     * @group esperado
     * @group datos
     * @group medio
     *
     * @que-ocurre-hoy Un valor con una comilla simple, como «D'Ancona», rompe la inserción o la
     *   actualización de las clases que heredan de las dos bases más antiguas: no se escribe nada, y
     *   el error llega como excepción.
     * @que-deberia-ocurrir Que el valor se guarde tal como llega.
     * @por-que-ocurre Los valores se pegan entre comillas simples sin escapar nada.
     * @como-deberia-funcionar Pasar los valores como parámetros de una sentencia preparada.
     */
    public function test_P5_unValorConComillaSimpleSeGuardaTalCual(): void
    {
        $fallo = null;
        try {
            $id = self::modeloP()->insertar('permisos', ['idUsuario' => $this->usuario, 'modulo' => "D'Ancona", 'permiso' => 1]);
        } catch (\mysqli_sql_exception $excepcion) {
            $fallo = $excepcion->getMessage();
        }

        self::assertNull($fallo, 'Una comilla en el valor no puede romper la sentencia.');
        self::assertSame(['modulo' => "D'Ancona"], $this->fila($id, 'modulo'));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/claseModeloP.php:81-88
     *
     * @group esperado
     * @group datos
     * @group alto
     *
     * @que-ocurre-hoy Una consulta que la base rechaza no devuelve false ni deja el error para
     *   `hayErrorConsulta()` y `getErrorConsulta()`: llega como excepción.
     * @que-deberia-ocurrir Que devuelva false y deje el error y la sentencia para consultarlos.
     * @por-que-ocurre La misma que la del caso D10: `mysqli` lanza el error como excepción.
     * @como-deberia-funcionar Recoger la excepción en la consulta, o fijar el modo de informe de
     *   `mysqli` que el código espera.
     */
    public function test_P6_unaConsultaRechazadaDevuelveFalseYDejaElError(): void
    {
        $fallo = null;
        try {
            $respuesta = self::modeloP()->consultar('SELECT * FROM tabla_que_no_existe');
        } catch (\mysqli_sql_exception $excepcion) {
            $fallo = $excepcion->getMessage();
        }

        self::assertNull($fallo, 'El error de la base no puede escapar como excepción.');
        self::assertFalse($respuesta);
        self::assertTrue(\ModeloP::hayErrorConsulta());
    }

    /**
     * @comportamiento Sin conexión fijada, la primera petición de la conexión la abre con los datos
     *   de `configuracion.php` y la guarda para las siguientes.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_P7_laPrimeraPeticionAbreLaConexionConfiguradaYLaGuarda(): void
    {
        $propiedad = new \ReflectionProperty('ModeloP', 'db');
        $propiedad->setAccessible(true);
        $propiedad->setValue(null, null);

        $conexion = \ModeloP::getDbo();

        self::assertInstanceOf(\mysqli::class, $conexion);
        self::assertNotSame($this->db, $conexion);
        self::assertSame($conexion, \ModeloP::getDbo());
        self::assertSame(
            (static function (): string {
                include RUTA_TPVFOX . '/configuracion.php';

                return $nombrebdMysql;
            })(),
            $conexion->query('SELECT DATABASE()')->fetch_row()[0]
        );
    }

    // --- Modelo -----------------------------------------------------------

    /**
     * @comportamiento Una consulta devuelve la sentencia y sus filas; sin filas no trae la clave de
     *   los datos, a diferencia de `TFModelo`, que la trae a null. Una escritura devuelve la sentencia
     *   y un error «0» si la base la acepta.
     */
    public function test_M1_unaConsultaSinFilasNoTraeDatos(): void
    {
        $id = $this->permiso('mod_prueba', 'vista.php');
        $modelo = self::modelo();

        self::assertSame(['consulta' => "SELECT vista FROM permisos WHERE id = $id", 'datos' => [['vista' => 'vista.php']]], $modelo->leer("SELECT vista FROM permisos WHERE id = $id"));
        self::assertSame(['consulta' => 'SELECT vista FROM permisos WHERE id = 0'], $modelo->leer('SELECT vista FROM permisos WHERE id = 0'));
        self::assertSame(['consulta' => "UPDATE permisos SET permiso = 0 WHERE id = $id", 'error' => '0'], $modelo->escribir("UPDATE permisos SET permiso = 0 WHERE id = $id"));
    }

    /** @comportamiento Insertar y actualizar devuelven la sentencia que ejecutaron, no el identificador ni las filas afectadas. */
    public function test_M2_insertarYActualizarDevuelvenLaSentencia(): void
    {
        $modelo = self::modelo();

        $insertada = $modelo->insertar(['idUsuario' => $this->usuario, 'modulo' => 'mod_modelo', 'permiso' => 1]);
        $id = (int) $this->db->insert_id;
        $actualizada = $modelo->actualizar(['permiso' => 0], "id = $id");

        self::assertSame("INSERT permisos SET idUsuario = '{$this->usuario}', modulo = 'mod_modelo', permiso = '1'", $insertada);
        self::assertSame("UPDATE permisos SET permiso = '0' WHERE id = $id", $actualizada);
        self::assertSame(['permiso' => '0'], $this->fila($id, 'permiso'));
    }

    // --- Apoyos -----------------------------------------------------------

    private static function tfModelo(): \TFModelo
    {
        return new class extends \TFModelo {
            public function __construct()
            {
                $this->setTabla('permisos');
            }

            public function insertar(array $datos, bool $soloSQL = false)
            {
                return $this->insert($datos, $soloSQL);
            }

            public function actualizar($datos, $condicion, bool $soloSQL = false, bool $quitarNulos = false, bool $desglosar = false)
            {
                return $this->update($datos, $condicion, $soloSQL, $quitarNulos, $desglosar);
            }

            public function escribir(string $sql)
            {
                return $this->consultaDML($sql);
            }
        };
    }

    private static function modeloP(): \ModeloP
    {
        return new class extends \ModeloP {
            public function consultar(string $sql)
            {
                return self::_consulta($sql);
            }

            public function insertar(string $tabla, $datos, bool $soloSQL = false)
            {
                return self::_insert($tabla, $datos, $soloSQL);
            }

            public function actualizar(string $tabla, $datos, $condicion, bool $soloSQL = false)
            {
                return self::_update($tabla, $datos, $condicion, $soloSQL);
            }

            public function borrar(string $tabla, $condicion, bool $soloSQL = false)
            {
                return self::_delete($tabla, $condicion, $soloSQL);
            }

            public function leer(string $tabla, $condiciones = '', array $columnas = [], $uniones = [], int $limite = 0, int $desplazamiento = 0, bool $soloSQL = false)
            {
                return self::_leer($tabla, $condiciones, $columnas, $uniones, $limite, $desplazamiento, $soloSQL);
            }
        };
    }

    private static function modelo(): \Modelo
    {
        return new class extends \Modelo {
            public function __construct()
            {
                $this->tabla = 'permisos';
            }

            public function leer(string $sql)
            {
                return $this->consulta($sql);
            }

            public function escribir(string $sql)
            {
                return $this->consultaDML($sql);
            }

            public function insertar(array $datos)
            {
                return $this->insert($datos);
            }

            public function actualizar($datos, $condicion)
            {
                return $this->update($datos, $condicion);
            }
        };
    }

    /** Una fila de permisos del usuario del caso, escrita por la conexión del caso. */
    private function permiso(string $modulo, string $vista): int
    {
        $sentencia = $this->db->prepare('INSERT INTO permisos (idUsuario, modulo, vista, permiso) VALUES (?, ?, ?, 1)');
        $sentencia->bind_param('iss', $this->usuario, $modulo, $vista);
        $sentencia->execute();

        return (int) $this->db->insert_id;
    }

    /** @return array<string,mixed>|null las columnas pedidas de la fila de permisos, o null si no existe */
    private function fila(int $id, string $columnas): ?array
    {
        return $this->db->query("SELECT $columnas FROM permisos WHERE id = $id")->fetch_assoc();
    }
}
