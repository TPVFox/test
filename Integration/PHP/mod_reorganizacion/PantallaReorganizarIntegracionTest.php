<?php

/**
 * La pantalla de la reorganización (`modulos/mod_reorganizacion/ListadoReorganizar.php`): las filas de
 * las operaciones, cada una según el permiso de su acción —la de subir a la tienda web, solo si hay
 * tienda web—, y los ficheros que carga.
 *
 * La pantalla se pinta en un PHP aparte, desde la carpeta del módulo, con la sesión del usuario de
 * pruebas ya correcta y los permisos del módulo concedidos salvo los que el caso quite, dentro de una
 * transacción que se deshace al terminar. Las filas de la comprobación de existencias y de la aplicación
 * de una relación las verifican sus propias pruebas. La ficha de la tienda web se da de alta fuera de la
 * transacción, porque el complemento la lee con su propia conexión, y se borra al terminar el caso y
 * antes de empezarlo, por si quedó de uno interrumpido; ningún caso se conecta a ella.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModReorganizacion;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class PantallaReorganizarIntegracionTest extends CasoIntegracion
{
    /** Lo que identifica en la página la fila de cada operación de este componente. */
    private const FILAS = [
        'regenerar_stock' => 'id="boton-stock"',
        'cerrar_stock_anual' => 'onclick="modalCerrarStock()"',
        'subir_stock_web' => 'id="boton_subir_stock"',
        'reorganizar' => 'onclick=reorganizarPermisosModulos(0,',
    ];

    private int $idUsuario;

    private ?\mysqli $conexionPropia = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->idUsuario = (new Siembra($this->db))->usuarioPorDefecto();
        $this->borrarLaTiendaWeb();
    }

    protected function tearDown(): void
    {
        $this->borrarLaTiendaWeb();
        $this->conexionPropia->close();
        parent::tearDown();
    }

    /**
     * @comportamiento Con todos los permisos y sin tienda web, la pantalla ofrece regenerar el stock,
     *   cerrarlo y reorganizar los permisos, con el número de usuarios que recorrerá; no ofrece subir a
     *   la tienda web.
     */
    public function test_conTodosLosPermisosOfreceCadaOperacionMenosLaSubidaSinTiendaWeb(): void
    {
        $pantalla = $this->pintar();

        foreach (['regenerar_stock', 'cerrar_stock_anual', 'reorganizar'] as $accion) {
            self::assertStringContainsString(self::FILAS[$accion], $pantalla['html'], $accion);
        }
        self::assertStringNotContainsString(self::FILAS['subir_stock_web'], $pantalla['html']);
        self::assertStringContainsString('reorganizarPermisosModulos(0,' . $pantalla['usuarios'] . ')', $pantalla['html']);
    }

    /**
     * @comportamiento Sin el permiso de una acción, la pantalla no pinta su fila y sí las demás.
     *
     * @dataProvider acciones
     */
    public function test_sinElPermisoDeUnaAccionNoPintaSuFila(string $accion): void
    {
        $pantalla = $this->pintar([$this->quitarPermiso($accion)]);

        self::assertStringNotContainsString(self::FILAS[$accion], $pantalla['html']);
        foreach (array_diff(['regenerar_stock', 'cerrar_stock_anual', 'reorganizar'], [$accion]) as $otra) {
            self::assertStringContainsString(self::FILAS[$otra], $pantalla['html'], $otra);
        }
    }

    /** @return array<string, array{string}> */
    public function acciones(): array
    {
        return ['regenerar' => ['regenerar_stock'], 'cerrar' => ['cerrar_stock_anual'], 'reorganizar' => ['reorganizar']];
    }

    /**
     * @comportamiento Con una tienda web, la pantalla ofrece subir el stock y los precios a quien tiene
     *   el permiso, y no a quien no lo tiene.
     */
    public function test_conTiendaWebOfreceLaSubidaSoloConSuPermiso(): void
    {
        $this->tiendaWeb();

        $conPermiso = $this->pintar();
        $sinPermiso = $this->pintar([$this->quitarPermiso('subir_stock_web')]);

        self::assertStringContainsString(self::FILAS['subir_stock_web'], $conPermiso['html']);
        self::assertStringNotContainsString(self::FILAS['subir_stock_web'], $sinPermiso['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_reorganizacion/ListadoReorganizar.php:32
     *
     * @group defecto
     * @group pantalla
     * @group bajo
     *
     * @que-ocurre-hoy La pantalla carga `js/AccionesDirectas.js` del módulo, que no existe: el navegador
     *   registra un error al abrirla.
     * @que-deberia-ocurrir Que la pantalla solo cargue ficheros que existen.
     * @por-que-ocurre La etiqueta del fichero quedó en la cabecera; las acciones del teclado del módulo
     *   están en `funciones.js`.
     * @como-deberia-funcionar Quitar la etiqueta.
     */
    public function test_laPantallaSoloCargaFicherosQueExisten(): void
    {
        $pantalla = $this->pintar();

        preg_match_all('#<script src="[^"]*/TPVFox(/[^"]+)"#', $pantalla['html'], $scripts);
        self::assertNotEmpty($scripts[1]);
        foreach ($scripts[1] as $script) {
            self::assertFileExists(RUTA_TPVFOX . $script);
        }
    }

    /**
     * Pinta la pantalla en un PHP aparte y devuelve su HTML, los avisos y el número de usuarios de la base.
     *
     * @param list<string> $preparacion
     * @return array{html:string, errores:string, usuarios:string}
     */
    private function pintar(array $preparacion = []): array
    {
        $sesion = [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => $this->idUsuario, 'group_id' => 1, 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => 1, 'razonsocial' => 'Tienda de pruebas', 'ano' => '2026'],
        ];
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["DOCUMENT_ROOT"] = ""; $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = "/TPVFox/modulos/mod_reorganizacion/ListadoReorganizar.php";'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_reorganizacion', true) . ');'
            . ' require "./../../inicial.php";'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ' $BDTpv->query("UPDATE permisos SET permiso = 1 WHERE idUsuario = ' . $this->idUsuario . ' AND modulo = \'mod_reorganizacion\'");'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->query(strtr($sql, [":usuario" => "' . $this->idUsuario . '"])); }'
            . ' $ClasePermisos = $thisTpv->permisos = new ClasePermisos($Usuario, $BDTpv);'
            . ' register_shutdown_function(function () use ($BDTpv) { echo "\n@@USUARIOS@@" . $BDTpv->query("SELECT COUNT(*) FROM usuarios")->fetch_row()[0]; });'
            . ' include "ListadoReorganizar.php";';

        $errores = (string) tempnam(sys_get_temp_dir(), 'reorganizar');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$html, $usuarios] = array_pad(explode('@@USUARIOS@@', implode("\n", $salida), 2), 2, '');
        $pantalla = ['html' => $html, 'errores' => (string) file_get_contents($errores), 'usuarios' => trim($usuarios)];
        unlink($errores);

        return $pantalla;
    }

    /** La sentencia que quita al usuario de pruebas el permiso de una acción de la pantalla. */
    private function quitarPermiso(string $accion): string
    {
        return "UPDATE permisos SET permiso = 0 WHERE idUsuario = :usuario AND modulo = 'mod_reorganizacion' AND vista = 'ListadoReorganizar.php' AND accion = '{$accion}'";
    }

    /**
     * Da de alta una tienda web activa, con una clave de pruebas y sin dirección a la que conectarse. Se
     * guarda con otra conexión del producto, fuera de la transacción, y se borra al terminar el caso.
     */
    private function tiendaWeb(): void
    {
        $this->conexionPropia()->query("INSERT INTO tiendas (tipoTienda, razonsocial, nif, telefono, estado, NombreComercial, direccion, ano, dominio, key_api) VALUES ('web', 'Tienda web de pruebas', 'X0000000X', '000000000', 'Activo', 'Tienda web de pruebas', 'Sin direccion', '2026', 'http://127.0.0.1:1', 'clave-de-pruebas')");
    }

    /** Borra la tienda web de pruebas, también la que pudo dejar un caso interrumpido. */
    private function borrarLaTiendaWeb(): void
    {
        $this->conexionPropia()->query("DELETE FROM tiendas WHERE tipoTienda = 'web' AND key_api = 'clave-de-pruebas'");
    }

    /** Una conexión del producto fuera de la transacción del caso. */
    private function conexionPropia(): \mysqli
    {
        $this->incluirTPVFox('/clases/ClaseConexion.php');

        return $this->conexionPropia ??= (new \ClaseConexion())->getConexion();
    }
}
