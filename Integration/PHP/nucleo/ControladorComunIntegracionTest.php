<?php

/**
 * Lo que el controlador común de TPVFox (`controllers/Controladores.php`) hace contra la base: la
 * información de una tabla, contar y leer registros, y la configuración de cada módulo por usuario,
 * que se guarda en `modulos_configuracion` y se combina con la de por defecto del módulo.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\Nucleo;

use TPVFox\Test\CasoIntegracion;

final class ControladorComunIntegracionTest extends CasoIntegracion
{
    private const MODULO = 'mod_prueba_configuracion';

    private \ControladorComun $controlador;
    private int $usuario = 0;

    protected function setUp(): void
    {
        parent::setUp();
        require_once RUTA_TPVFOX . '/controllers/Controladores.php';
        $this->controlador = new \ControladorComun();
        $this->controlador->loadDbtpv($this->db);
        $this->usuario = $this->usuarioNuevo();
    }

    /**
     * @comportamiento La información de una tabla trae su estado y sus columnas, con su tipo o solo
     *   el nombre; de una tabla que no existe, un error que la nombra.
     */
    public function test_C1_laInformacionDeUnaTablaTraeSuEstadoYSusColumnas(): void
    {
        $conTipo = $this->controlador->InfoTabla($this->db, 'modulos_configuracion');
        $soloNombre = $this->controlador->InfoTabla($this->db, 'modulos_configuracion', 'no');
        $inexistente = $this->controlador->InfoTabla($this->db, 'tabla_que_no_existe');

        self::assertSame('modulos_configuracion', $conTipo['info']['Name']);
        self::assertContains('configuracion longtext', $conTipo['campos']);
        self::assertSame(['id', 'idusuario', 'nombre_modulo', 'configuracion', 'fecha'], $soloNombre['campos']);
        self::assertSame('Error tabla no encontrada - tabla_que_no_existe', $inexistente['error']);
    }

    /**
     * @comportamiento Contar devuelve el número de filas que cumplen la condición; leer las devuelve
     *   con su número, y sin filas no trae la lista.
     */
    public function test_C2_contarYLeerRegistros(): void
    {
        $this->db->query("INSERT INTO modulos_configuracion (idusuario, nombre_modulo, configuracion, fecha) VALUES ({$this->usuario}, 'mod_a', '{}', NOW()), ({$this->usuario}, 'mod_b', '{}', NOW())");
        $donde = "WHERE idusuario = {$this->usuario}";

        self::assertSame(2, $this->controlador->contarRegistro($this->db, 'modulos_configuracion', $donde));
        $leidos = $this->controlador->consultaRegistro($this->db, 'modulos_configuracion', $donde . ' ORDER BY nombre_modulo');
        self::assertSame(2, $leidos['NItems']);
        self::assertSame(['mod_a', 'mod_b'], array_column($leidos['Items'], 'nombre_modulo'));
        self::assertSame(['NItems' => 0], $this->controlador->consultaRegistro($this->db, 'modulos_configuracion', 'WHERE idusuario = 0 AND nombre_modulo = "ninguno"'));
    }

    /**
     * @comportamiento Sin configuración guardada, la del usuario es la de por defecto del módulo,
     *   marcada como del módulo. Guardarla la da de alta para él; desde entonces se lee la suya,
     *   marcada como del usuario, encima de la de por defecto, y volver a guardarla la reescribe.
     */
    public function test_C3_laConfiguracionDelUsuarioSeDaDeAltaYDespuesSeReescribe(): void
    {
        $porDefecto = ['columnas' => 5, 'orden' => 'nombre'];

        $antes = $this->controlador->obtenerConfiguracion($porDefecto, self::MODULO, $this->usuario);
        self::assertSame(['columnas' => 5, 'orden' => 'nombre', 'tipo_configuracion' => 'Modulo', 'nombre_modulo' => self::MODULO, 'idUsuario' => $this->usuario], $antes);

        $alta = $this->controlador->GrabarConfiguracionModulo(self::MODULO, $this->usuario, ['orden' => 'precio', 'tipo_configuracion' => 'Modulo']);
        self::assertSame(1, $alta['affectado']);

        $despues = $this->controlador->obtenerConfiguracion($porDefecto, self::MODULO, $this->usuario);
        self::assertSame('precio', $despues['orden']);
        self::assertSame(5, $despues['columnas']);
        self::assertSame('Usuario', $despues['tipo_configuracion']);

        $this->controlador->GrabarConfiguracionModulo(self::MODULO, $this->usuario, ['orden' => 'familia', 'tipo_configuracion' => 'Usuario']);
        self::assertSame('familia', $this->controlador->obtenerConfiguracion($porDefecto, self::MODULO, $this->usuario)['orden']);
        self::assertSame(1, $this->filasDeConfiguracion($this->usuario));
    }

    /** @comportamiento Guardar la configuración de un usuario no cambia la de otro en el mismo módulo. */
    public function test_C4_guardarLaConfiguracionDeUnUsuarioNoCambiaLaDeOtro(): void
    {
        $otro = $this->usuarioNuevo();
        $this->controlador->GrabarConfiguracionModulo(self::MODULO, $otro, ['orden' => 'nombre', 'tipo_configuracion' => 'Modulo']);

        $this->controlador->GrabarConfiguracionModulo(self::MODULO, $this->usuario, ['orden' => 'precio', 'tipo_configuracion' => 'Modulo']);
        $this->controlador->GrabarConfiguracionModulo(self::MODULO, $this->usuario, ['orden' => 'familia', 'tipo_configuracion' => 'Usuario']);

        self::assertSame('nombre', $this->controlador->obtenerConfiguracion([], self::MODULO, $otro)['orden']);
    }

    /**
     * @estado rojo
     * @codigo-afectado controllers/Controladores.php:217-223
     *
     * @group esperado
     * @group configuracion
     * @group medio
     *
     * @que-ocurre-hoy Una configuración con una comilla simple en un valor no se guarda: rompe la
     *   sentencia, y el error llega como excepción.
     * @que-deberia-ocurrir Que se guarde tal como llega.
     * @por-que-ocurre La configuración se convierte en JSON y se pega entre comillas simples sin
     *   escaparla; el JSON no escapa la comilla simple.
     * @como-deberia-funcionar Pasar la configuración como parámetro de una sentencia preparada.
     */
    public function test_C5_unaConfiguracionConComillaSimpleSeGuarda(): void
    {
        $fallo = null;
        try {
            $this->controlador->GrabarConfiguracionModulo(self::MODULO, $this->usuario, ['proveedor' => "D'Ancona", 'tipo_configuracion' => 'Modulo']);
        } catch (\mysqli_sql_exception $excepcion) {
            $fallo = $excepcion->getMessage();
        }

        self::assertNull($fallo, 'Una comilla en la configuración no puede romper la sentencia.');
        self::assertSame("D'Ancona", $this->controlador->obtenerConfiguracion([], self::MODULO, $this->usuario)['proveedor']);
    }

    /**
     * @estado rojo
     * @codigo-afectado controllers/Controladores.php:242-254
     *
     * @group esperado
     * @group configuracion
     * @group medio
     *
     * @que-ocurre-hoy Con dos configuraciones guardadas para el mismo usuario y módulo —dos altas a
     *   la vez, desde dos pantallas— su configuración no se lee nunca más: se le da la de por defecto,
     *   sin marcar de quién es, y cada vez que la guarda se da de alta otra fila.
     * @que-deberia-ocurrir Que se lea la configuración guardada del usuario, y que guardarla la
     *   reescriba.
     * @por-que-ocurre Solo se lee la tabla si hay exactamente una fila; con más, no se marca el tipo,
     *   y guardar sin tipo da de alta. La tabla no impide dos filas para el mismo usuario y módulo.
     * @como-deberia-funcionar Una clave única por usuario y módulo, y leer y reescribir sobre ella.
     */
    public function test_C6_conDosConfiguracionesGuardadasSeLeeLaDelUsuario(): void
    {
        $this->db->query("INSERT INTO modulos_configuracion (idusuario, nombre_modulo, configuracion, fecha) VALUES ({$this->usuario}, '" . self::MODULO . "', '{\"orden\":\"precio\"}', NOW()), ({$this->usuario}, '" . self::MODULO . "', '{\"orden\":\"precio\"}', NOW())");

        $leida = $this->controlador->obtenerConfiguracion(['orden' => 'nombre'], self::MODULO, $this->usuario);

        self::assertSame('precio', $leida['orden'], 'Tiene que leerse la configuración del usuario.');
        self::assertSame('Usuario', $leida['tipo_configuracion'] ?? null);
    }

    private function usuarioNuevo(): int
    {
        $nombre = 'prueba_config_' . bin2hex(random_bytes(3));
        $this->db->query("INSERT INTO usuarios (username, password, fecha, group_id, estado, nombre) VALUES ('$nombre', MD5('x'), CURDATE(), 1, 'activo', 'Prueba')");

        return (int) $this->db->insert_id;
    }

    private function filasDeConfiguracion(int $usuario): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM modulos_configuracion WHERE idusuario = $usuario AND nombre_modulo = '" . self::MODULO . "'")->fetch_row()[0];
    }
}
