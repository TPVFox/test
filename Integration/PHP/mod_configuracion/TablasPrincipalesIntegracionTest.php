<?php
/**
 * Las tablas principales —IVA, formas de pago y tipos de vencimiento— tal como se gestionan
 * desde `mod_configuracion/tareas.php`: abrir el formulario, añadir y modificar. Fijan lo que
 * hacen hoy, para que añadir otro bloque a esa pantalla no las cambie sin que se note.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModConfiguracion;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\DespachoTareas;

final class TablasPrincipalesIntegracionTest extends CasoIntegracion
{
    /** Las clases de estas tablas abren su conexión por la base común del producto. */
    protected bool $compartirConexionConElProducto = true;

    private const RUTA_TAREAS = RUTA_TPVFOX . '/modulos/mod_configuracion/tareas.php';

    public function test_T1_unTipoDeIvaSeAnadeYSeModifica(): void
    {
        $alta = $this->despachar(['pulsado' => 'ModificarTabla', 'dedonde' => 'iva', 'id' => '0', 'descripcion' => 'Prueba superreducido', 'iva' => '4.00', 'recargo' => '0.50']);
        $fila = $this->db->query("SELECT * FROM iva WHERE descripcionIva = 'Prueba superreducido'")->fetch_assoc();

        $cambio = $this->despachar(['pulsado' => 'ModificarTabla', 'dedonde' => 'iva', 'id' => $fila['idIva'], 'descripcion' => 'Prueba reducido', 'iva' => '10.00', 'recargo' => '1.40']);
        $cambiada = $this->db->query('SELECT * FROM iva WHERE idIva = ' . (int) $fila['idIva'])->fetch_assoc();

        self::assertSame('0', $alta['error']);
        self::assertSame(['4.00', '0.50'], [$fila['iva'], $fila['recargo']]);
        self::assertSame('0', $cambio['error']);
        self::assertSame(['Prueba reducido', '10.00', '1.40'], [$cambiada['descripcionIva'], $cambiada['iva'], $cambiada['recargo']]);
    }

    public function test_T2_unaFormaDePagoSeAnadeYSeModifica(): void
    {
        $alta = $this->despachar(['pulsado' => 'ModificarTabla', 'dedonde' => 'forma', 'id' => '0', 'descripcion' => 'Prueba pagare', 'iva' => '', 'recargo' => '']);
        $fila = $this->db->query("SELECT * FROM formasPago WHERE descripcion = 'Prueba pagare'")->fetch_assoc();

        $cambio = $this->despachar(['pulsado' => 'ModificarTabla', 'dedonde' => 'forma', 'id' => $fila['id'], 'descripcion' => 'Prueba pagare a 30', 'iva' => '', 'recargo' => '']);
        $cambiada = $this->db->query('SELECT * FROM formasPago WHERE id = ' . (int) $fila['id'])->fetch_assoc();

        self::assertSame('0', $alta['error']);
        self::assertSame('0', $cambio['error']);
        self::assertSame('Prueba pagare a 30', $cambiada['descripcion']);
    }

    public function test_T3_unTipoDeVencimientoSeAnadeYSeModificaConSusDias(): void
    {
        $alta = $this->despachar(['pulsado' => 'ModificarTabla', 'dedonde' => 'vencimiento', 'id' => '0', 'descripcion' => 'Prueba a 45', 'iva' => '', 'recargo' => '', 'dias' => '45']);
        $fila = $this->db->query("SELECT * FROM tiposVencimiento WHERE descripcion = 'Prueba a 45'")->fetch_assoc();

        $cambio = $this->despachar(['pulsado' => 'ModificarTabla', 'dedonde' => 'vencimiento', 'id' => $fila['id'], 'descripcion' => 'Prueba a 60', 'iva' => '', 'recargo' => '', 'dias' => '60']);
        $cambiada = $this->db->query('SELECT * FROM tiposVencimiento WHERE id = ' . (int) $fila['id'])->fetch_assoc();

        self::assertSame('0', $alta['error']);
        self::assertSame(45, (int) $fila['dias']);
        self::assertSame('0', $cambio['error']);
        self::assertSame(['Prueba a 60', 60], [$cambiada['descripcion'], (int) $cambiada['dias']]);
    }

    public function test_T4_elFormularioDeCadaTablaTraeLosDatosDeLaFilaQueSeEdita(): void
    {
        $this->despachar(['pulsado' => 'ModificarTabla', 'dedonde' => 'vencimiento', 'id' => '0', 'descripcion' => 'Prueba a 90', 'iva' => '', 'recargo' => '', 'dias' => '90']);
        $id = $this->db->query("SELECT id FROM tiposVencimiento WHERE descripcion = 'Prueba a 90'")->fetch_assoc()['id'];

        $respuesta = $this->despachar(['pulsado' => 'abrirModalModificar', 'dedonde' => 'vencimiento', 'id' => $id]);

        self::assertStringContainsString('value="Prueba a 90"', $respuesta['html']);
        self::assertStringContainsString('value="90"', $respuesta['html']);
        self::assertStringContainsString("modificarTabla('vencimiento')", $respuesta['html']);
    }

    public function test_T5_elFormularioParaAnadirSaleVacioEnLasTresTablas(): void
    {
        foreach (['iva', 'forma', 'vencimiento'] as $tabla) {
            $respuesta = $this->despachar(['pulsado' => 'abrirModalModificar', 'dedonde' => $tabla, 'id' => '0']);

            self::assertStringContainsString('id="descripcion" type="text" value=""', $respuesta['html'], $tabla);
            self::assertStringContainsString("modificarTabla('$tabla')", $respuesta['html'], $tabla);
        }
    }

    /**
     * @comportamiento Cada operación sobre un tipo de relación tiene su permiso. El
     *   administrador las tiene todas sin que nadie se las asigne.
     */
    public function test_T6_elAdministradorCreaRenombraModificaDaDeBajaReactivaYBorraUnTipo(): void
    {
        $permisos = $this->permisosDeUnUsuarioDelGrupo(9);

        $id = $this->despachar(['pulsado' => 'crearTipoRelacion', 'nombre' => 'Prueba de tipo', 'forma' => '1:N', 'llevaCantidad' => '1'], $permisos)['datos']['id'];
        $renombrar = $this->despachar(['pulsado' => 'renombrarTipoRelacion', 'id' => $id, 'nombre' => 'Prueba renombrada'], $permisos);
        $modificar = $this->despachar(['pulsado' => 'modificarTipoRelacion', 'id' => $id, 'forma' => 'N:1', 'llevaCantidad' => '0'], $permisos);
        $baja = $this->despachar(['pulsado' => 'bajaTipoRelacion', 'id' => $id], $permisos);
        $trasLaBaja = $this->tipo($id);
        $reactivar = $this->despachar(['pulsado' => 'reactivarTipoRelacion', 'id' => $id], $permisos);
        $trasReactivar = $this->tipo($id);
        $borrar = $this->despachar(['pulsado' => 'borrarTipoRelacion', 'id' => $id], $permisos);

        foreach ([$renombrar, $modificar, $baja, $reactivar, $borrar] as $respuesta) {
            self::assertArrayNotHasKey('error', $respuesta);
        }
        self::assertSame(['Prueba renombrada', 'N:1', 0, 'Baja'], [$trasLaBaja['nombre'], $trasLaBaja['forma'], (int) $trasLaBaja['llevaCantidad'], $trasLaBaja['estado']]);
        self::assertSame('Activo', $trasReactivar['estado']);
        self::assertNull($this->tipo($id));
    }

    /**
     * @comportamiento Sin el permiso de una operación, la petición se rechaza antes de tocar
     *   nada, aunque llegue directamente a tareas.php sin pasar por la pantalla.
     */
    public function test_T7_sinPermisoNingunaDeLasSeisOperacionesDeTiposSeEjecuta(): void
    {
        $this->db->query("INSERT INTO relacionesTipos (nombre, forma, estado) VALUES ('Prueba vigente', '1:N', 'Activo'), ('Prueba retirada', 'N:1', 'Baja')");
        $vigente = (int) $this->db->query("SELECT id FROM relacionesTipos WHERE nombre = 'Prueba vigente'")->fetch_assoc()['id'];
        $retirada = (int) $this->db->query("SELECT id FROM relacionesTipos WHERE nombre = 'Prueba retirada'")->fetch_assoc()['id'];
        $permisos = $this->permisosDeUnUsuarioDelGrupo(1);

        $peticiones = [
            ['pulsado' => 'crearTipoRelacion', 'nombre' => 'Prueba colada', 'forma' => '1:N', 'llevaCantidad' => '1'],
            ['pulsado' => 'renombrarTipoRelacion', 'id' => $vigente, 'nombre' => 'Prueba cambiada'],
            ['pulsado' => 'modificarTipoRelacion', 'id' => $vigente, 'forma' => 'N:1', 'llevaCantidad' => '0'],
            ['pulsado' => 'bajaTipoRelacion', 'id' => $vigente],
            ['pulsado' => 'reactivarTipoRelacion', 'id' => $retirada],
            ['pulsado' => 'borrarTipoRelacion', 'id' => $vigente],
        ];
        foreach ($peticiones as $peticion) {
            $respuesta = $this->despachar($peticion, $permisos);

            self::assertStringContainsString('No tienes permiso', $respuesta['error']['mensaje'], $peticion['pulsado']);
        }
        $tras = $this->tipo($vigente);
        self::assertSame(['Prueba vigente', '1:N', 'Activo'], [$tras['nombre'], $tras['forma'], $tras['estado']]);
        self::assertSame('Baja', $this->tipo($retirada)['estado']);
        self::assertSame(0, (int) $this->db->query("SELECT COUNT(*) AS n FROM relacionesTipos WHERE nombre = 'Prueba colada'")->fetch_assoc()['n']);
    }

    public function test_T8_unNombreOUnaFormaQueNoSeAdmitenLleganRechazadosDesdeLaPeticion(): void
    {
        $permisos = $this->permisosDeUnUsuarioDelGrupo(9);

        $conMarcas = $this->despachar(['pulsado' => 'crearTipoRelacion', 'nombre' => '<script>alert(1)</script>', 'forma' => '1:N', 'llevaCantidad' => '1'], $permisos);
        $conOtraForma = $this->despachar(['pulsado' => 'crearTipoRelacion', 'nombre' => 'Prueba de forma', 'forma' => "1:N' OR '1'='1", 'llevaCantidad' => '1'], $permisos);

        self::assertStringContainsString('solo admite letras', $conMarcas['error']['mensaje']);
        self::assertStringContainsString('forma', $conOtraForma['error']['mensaje']);
    }

    /**
     * @comportamiento El formulario de un tipo que ya tiene relaciones no deja cambiar la
     *   forma ni borrarlo, y solo ofrece los botones de lo que quien lo abre puede hacer.
     */
    public function test_T9_elFormularioDeUnTipoOfreceSoloLoQueElTipoYElPermisoAdmiten(): void
    {
        $this->db->query("INSERT INTO relacionesTipos (nombre, forma, estado) VALUES ('Prueba libre', '1:N', 'Activo'), ('Prueba en uso', 'N:1', 'Activo')");
        $libre = (int) $this->db->query("SELECT id FROM relacionesTipos WHERE nombre = 'Prueba libre'")->fetch_assoc()['id'];
        $enUso = (int) $this->db->query("SELECT id FROM relacionesTipos WHERE nombre = 'Prueba en uso'")->fetch_assoc()['id'];
        $usuario = (int) $this->db->query('SELECT id FROM usuarios ORDER BY id LIMIT 1')->fetch_assoc()['id'];
        $this->db->query("INSERT INTO relaciones (numRelacion, idTipo, version, tipoCambio, idUsuario, fechaCreacion, estado) VALUES (990001, $enUso, '1.0', 'alta', $usuario, NOW(), 'Activo')");
        $administrador = $this->permisosDeUnUsuarioDelGrupo(9);

        $delLibre = $this->despachar(['pulsado' => 'abrirModalTipoRelacion', 'id' => $libre], $administrador)['html'];
        $delEnUso = $this->despachar(['pulsado' => 'abrirModalTipoRelacion', 'id' => $enUso], $administrador)['html'];
        $nuevo = $this->despachar(['pulsado' => 'abrirModalTipoRelacion', 'id' => '0'], $administrador)['html'];
        $sinPermiso = $this->despachar(['pulsado' => 'abrirModalTipoRelacion', 'id' => $libre], $this->permisosDeUnUsuarioDelGrupo(1))['html'];

        self::assertStringContainsString('btnTipoRelacionBorrar', $delLibre);
        self::assertStringContainsString('<select id="tipoRelacionForma">', $delLibre);
        self::assertStringNotContainsString('btnTipoRelacionBorrar', $delEnUso);
        self::assertStringContainsString('<select id="tipoRelacionForma" disabled>', $delEnUso);
        self::assertStringContainsString('btnTipoRelacionBaja', $delEnUso);
        self::assertStringContainsString('btnTipoRelacionGuardar', $nuevo);
        self::assertStringNotContainsString('btnTipoRelacionBaja', $nuevo);
        self::assertStringNotContainsString('<a class="btn', $sinPermiso);
    }

    /**
     * @comportamiento Las acciones que se añaden a una pantalla que ya existe no llegan solas
     *   a quien ya la tiene: las reparte «Reorganizar permisos», de Sistema, Reorganizar.
     *   Tras pasarlo, el administrador las tiene concedidas y el resto, denegadas.
     *
     * Corre en un proceso aparte: la tarea de reorganizar carga una clase de complemento que
     * se llama igual que otra del módulo de productos, y las dos no caben en el mismo proceso.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_T10_reorganizarPermisosDaLasSeisAccionesDeTiposAQuienYaTeniaLaPantalla(): void
    {
        $this->incluirTPVFox('/modulos/mod_reorganizacion/clases/ClaseReorganizar.php');
        $this->db->query("DELETE FROM permisos WHERE modulo = 'mod_configuracion' AND accion LIKE '%tipo_relacion'");
        $permisos = $this->permisosDeUnUsuarioDelGrupo(9);
        $usuarios = $this->db->query('SELECT id, group_id FROM usuarios ORDER BY id')->fetch_all(MYSQLI_ASSOC);
        $this->db->query("DELETE FROM permisos WHERE modulo = 'mod_configuracion' AND accion LIKE '%tipo_relacion'");

        foreach (array_keys($usuarios) as $posicion) {
            DespachoTareas::invocar(RUTA_TPVFOX . '/modulos/mod_reorganizacion/tareas.php', $this->db, ['pulsado' => 'reorganizarPermisosModulos', 'inicial' => (string) $posicion, 'total' => (string) count($usuarios)], $permisos);
        }

        foreach ($usuarios as $usuario) {
            $filas = $this->db->query("SELECT permiso FROM permisos WHERE idUsuario = {$usuario['id']} AND modulo = 'mod_configuracion' AND accion LIKE '%tipo_relacion'")->fetch_all(MYSQLI_ASSOC);

            self::assertCount(6, $filas, 'usuario ' . $usuario['id']);
            self::assertSame([$usuario['group_id'] == 9 ? '1' : '0'], array_values(array_unique(array_column($filas, 'permiso'))), 'usuario ' . $usuario['id']);
        }
    }

    /**
     * Los permisos de un usuario nuevo de ese grupo, tal como los deja el producto al
     * iniciar su sesión: el grupo 9 es el de administradores.
     *
     * @return array{ClasePermisos:object}
     */
    private function permisosDeUnUsuarioDelGrupo(int $grupo): array
    {
        require_once RUTA_TPVFOX . '/clases/ClasePermisos.php';
        $nombre = 'prueba_tablas_' . $grupo . '_' . bin2hex(random_bytes(3));
        $this->db->query("INSERT INTO usuarios (username, password, fecha, group_id, estado, nombre) VALUES ('$nombre', MD5('x'), CURDATE(), $grupo, 'activo', 'Prueba')");
        $usuario = ['id' => (int) $this->db->insert_id, 'group_id' => $grupo];

        return ['ClasePermisos' => new \ClasePermisos($usuario, $this->db)];
    }

    private function tipo(int $id): ?array
    {
        return $this->db->query("SELECT * FROM relacionesTipos WHERE id = $id")->fetch_assoc();
    }

    private function despachar(array $post, array $variables = []): mixed
    {
        return DespachoTareas::invocar(self::RUTA_TAREAS, $this->db, $post, $variables);
    }
}
