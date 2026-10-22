<?php

/**
 * Las clases que leen y escriben la tabla de tiendas: la de su módulo
 * (`modulos/mod_tienda/clases/ClaseTienda.php`), que lista, lee, da de alta y modifica tiendas y
 * reúne los datos del servidor de correo, y la de `clases/ClaseTablaTienda.php`, que lee una tienda
 * y no tiene hoy quien la llame.
 *
 * La clase del módulo hereda de la clase base de acceso a datos: el caso le presta su conexión, para
 * que vea lo que siembra y su escritura se deshaga al terminar. Los datos del correo son de prueba.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModTienda;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class ClaseTiendaIntegracionTest extends CasoIntegracion
{
    protected bool $compartirConexionConElProducto = true;

    private Siembra $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/app/helpers.php');
        $this->incluirTPVFox('/modulos/mod_tienda/clases/ClaseTienda.php');
        $this->incluirTPVFox('/clases/ClaseTablaTienda.php');
        $this->siembra = new Siembra($this->db);
    }

    /** @comportamiento El listado trae todas las tiendas de la tabla. */
    public function test_elListadoTraeTodasLasTiendas(): void
    {
        $fisica = $this->siembra->tienda($this->ano(), 'fisica');

        $datos = (new \ClaseTienda())->obtenerTiendas()['datos'];

        self::assertCount($this->recuento(), $datos);
        self::assertContains((string) $fisica, array_column($datos, 'idTienda'));
    }

    /** @comportamiento Una tienda se lee por su número, con todos sus datos. */
    public function test_unaTiendaSeLeePorSuNumero(): void
    {
        $id = $this->siembra->tienda($this->ano(), 'fisica');

        $datos = (new \ClaseTienda())->obtenerUnaTienda($id)['datos'];

        self::assertCount(1, $datos);
        self::assertSame('fisica', $datos[0]['tipoTienda']);
        self::assertSame('X0000000X', $datos[0]['nif']);
    }

    /** @comportamiento La tienda principal y las web se leen por su tipo. */
    public function test_laPrincipalYLaWebSeLeenPorSuTipo(): void
    {
        $web = $this->siembra->tienda($this->ano(), 'web');
        $tiendas = new \ClaseTienda();

        $principales = $tiendas->tiendaPrincipal()['datos'];
        $webs = $tiendas->tiendasWeb()['datos'];

        self::assertNotEmpty($principales);
        self::assertSame(['principal'], array_values(array_unique(array_column($principales, 'tipoTienda'))));
        self::assertContains((string) $web, array_column($webs, 'idTienda'));
        self::assertSame(['web'], array_values(array_unique(array_column($webs, 'tipoTienda'))));
    }

    /**
     * @comportamiento El alta guarda el tipo, el NIF, la razón social, el nombre comercial, el año, la
     *   dirección y el teléfono, y devuelve el número de la tienda nueva.
     */
    public function test_elAltaGuardaLosDatosComunesYDevuelveSuNumero(): void
    {
        $id = (new \ClaseTienda())->addTienda($this->datosDeAlta());

        self::assertIsInt($id);
        self::assertSame(
            ['tipoTienda' => 'fisica', 'nif' => 'B00000000', 'razonsocial' => 'Tienda nueva S.L.', 'NombreComercial' => 'Tienda nueva',
                'ano' => $this->ano(), 'direccion' => 'Calle de la Prueba 1', 'telefono' => '986000000'],
            $this->fila($id, 'tipoTienda, nif, razonsocial, NombreComercial, ano, direccion, telefono')
        );
    }

    /** @comportamiento La modificación escribe los datos recibidos y devuelve cuántas filas cambió. */
    public function test_laModificacionEscribeLosDatosYDevuelveLasFilasCambiadas(): void
    {
        $id = $this->siembra->tienda($this->ano(), 'fisica');

        $filas = (new \ClaseTienda())->modificarTienda(['idtienda' => (string) $id, 'razonsocial' => 'Otra razón social', 'estado' => 'cerrado']);

        self::assertSame(1, $filas);
        self::assertSame(['razonsocial' => 'Otra razón social', 'estado' => 'cerrado'], $this->fila($id, 'razonsocial, estado'));
    }

    /**
     * @comportamiento Con la base en modo estricto, un número de tienda con una condición añadida no
     *   cambia ninguna tienda: el número también se escribe como columna, y la base rechaza ese valor
     *   antes de llegar a la condición.
     */
    public function test_enModoEstrictoUnNumeroConCondicionNoCambiaNingunaTienda(): void
    {
        $id = $this->siembra->tienda($this->ano(), 'fisica');

        try {
            (new \ClaseTienda())->modificarTienda(['idtienda' => $id . ' OR 1=1', 'razonsocial' => 'Cambiadas todas']);
        } catch (\mysqli_sql_exception) {
            // El rechazo de la base es lo que hoy impide el cambio; que no se recoja es otro caso.
        }

        self::assertSame(0, $this->recuentoDe("razonsocial = 'Cambiadas todas'"));
    }

    /** @comportamiento Una modificación con un número que no es de ninguna tienda no cambia ni crea ninguna. */
    public function test_unaModificacionConUnNumeroQueNoExisteNoCambiaNinguna(): void
    {
        $antes = $this->recuento();
        $libre = (int) $this->db->query('SELECT MAX(idTienda) + 1000 FROM tiendas')->fetch_row()[0];

        $filas = (new \ClaseTienda())->modificarTienda(['idtienda' => $libre, 'razonsocial' => 'Ninguna']);

        self::assertSame(0, $filas);
        self::assertSame($antes, $this->recuento());
        self::assertSame(0, $this->recuentoDe("razonsocial = 'Ninguna'"));
    }

    /** @comportamiento La modificación guarda tal cual una razón social con comillas simples y dobles. */
    public function test_laModificacionGuardaLasComillasTalCual(): void
    {
        $id = $this->siembra->tienda($this->ano(), 'fisica');

        (new \ClaseTienda())->modificarTienda(['idtienda' => $id, 'razonsocial' => 'L\'Hostal "Nou" S.L.']);

        self::assertSame(['razonsocial' => 'L\'Hostal "Nou" S.L.'], $this->fila($id, 'razonsocial'));
    }

    /**
     * @comportamiento Los datos del servidor de correo, que la ficha guarda como una lista de pares
     *   sueltos, se reúnen en un solo array.
     */
    public function test_losDatosDelServidorSeReunenEnUnArray(): void
    {
        $guardado = json_encode([['emailTienda' => 'tienda@ejemplo.invalid'], ['host' => 'smtp.ejemplo.invalid'], ['SMTPPort' => '465']]);

        $datos = (new \ClaseTienda())->obtenerArrayDatosServidor($guardado);

        self::assertSame(['emailTienda' => 'tienda@ejemplo.invalid', 'host' => 'smtp.ejemplo.invalid', 'SMTPPort' => '465'], $datos);
    }

    /**
     * @comportamiento Con dos tiendas principales, la lectura de la principal trae las dos: quien la
     *   llama decide con cuál se queda.
     */
    public function test_conDosPrincipalesLaLecturaDeLaPrincipalTraeLasDos(): void
    {
        $segunda = $this->siembra->tienda($this->ano(), 'principal');

        $principales = (new \ClaseTienda())->tiendaPrincipal()['datos'];

        self::assertSame($this->recuentoDe("tipoTienda = 'principal'"), count($principales));
        self::assertGreaterThanOrEqual(2, count($principales));
        self::assertContains((string) $segunda, array_column($principales, 'idTienda'));
    }

    /** @comportamiento La lectura de la principal la trae por su tipo, esté activa o cerrada. */
    public function test_unaPrincipalCerradaLaSigueLeyendoLaClase(): void
    {
        $this->db->query("UPDATE tiendas SET estado = 'cerrado' WHERE tipoTienda = 'principal'");

        $principales = (new \ClaseTienda())->tiendaPrincipal()['datos'];

        self::assertNotEmpty($principales);
        self::assertSame(['cerrado'], array_values(array_unique(array_column($principales, 'estado'))));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tienda/clases/ClaseTienda.php:110-120
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Reunir los datos del servidor de correo de una tienda principal que no los
     *   tiene guardados —como queda tras darla de alta— deja avisos de PHP.
     * @que-deberia-ocurrir Que dé un resultado vacío, sin avisos.
     * @por-que-ocurre Decodifica y recorre lo guardado sin comprobar que haya algo.
     * @como-deberia-funcionar Comprobar que hay datos antes de decodificarlos y recorrerlos.
     */
    public function test_losDatosDelServidorDeUnaPrincipalSinCorreoSeLeenSinAvisos(): void
    {
        $id = $this->siembra->tienda($this->ano(), 'principal');
        $guardado = $this->fila($id, 'servidor_email')['servidor_email'];

        [$datos, $avisos] = self::recogiendoAvisos(fn () => (new \ClaseTienda())->obtenerArrayDatosServidor($guardado));

        self::assertSame([], $datos);
        self::assertSame([], $avisos, 'Sin avisos de PHP.');
    }

    /** @comportamiento La clase de `clases/` cuenta las tiendas al crearse y lee una por su número. */
    public function test_laClaseDeClasesLeeUnaTienda(): void
    {
        $id = $this->siembra->tienda($this->ano(), 'fisica');

        [$tienda] = self::recogiendoAvisos(fn () => (new \ClaseTablaTienda($this->db))->DatosTienda($id));

        self::assertSame((string) $id, $tienda['idTienda']);
        self::assertSame('fisica', $tienda['tipoTienda']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tienda/clases/ClaseTienda.php:68-82
     *
     * @group defecto
     * @group entrada
     * @group alto
     *
     * @que-ocurre-hoy Una tienda cuya razón social lleva comillas dobles no se da de alta: la petición
     *   termina con un error de la base. Lo tecleado en la ficha entra en la sentencia como parte de
     *   ella.
     * @que-deberia-ocurrir Que se guarde tal como se escribió.
     * @por-que-ocurre El alta pone cada valor entre comillas dobles con una función que no escapa nada.
     * @como-deberia-funcionar Pasar los valores a la base como datos, no pegados a la sentencia.
     */
    public function test_unaRazonSocialConComillasSeGuardaTalCual(): void
    {
        $datos = ['razonsocial' => 'Hermanos "Pérez" S.L.'] + $this->datosDeAlta();

        $id = $this->sinErrorDeLaBase(fn () => (new \ClaseTienda())->addTienda($datos));

        self::assertSame('Hermanos "Pérez" S.L.', $this->fila($id, 'razonsocial')['razonsocial']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tienda/clases/ClaseTienda.php:68-76
     *
     * @group defecto
     * @group guardado
     * @group medio
     *
     * @que-ocurre-hoy El alta no guarda el estado: la tienda nueva queda sin estado, y como la
     *   sesión solo toma tiendas activas, hay que volver a la ficha para activarla.
     * @que-deberia-ocurrir Que el alta guarde el estado que se le da.
     * @por-que-ocurre La línea que escribía el estado está comentada.
     * @como-deberia-funcionar Escribir el estado con el resto de los datos comunes.
     */
    public function test_elAltaGuardaElEstado(): void
    {
        $id = (new \ClaseTienda())->addTienda(['estado' => 'Activo'] + $this->datosDeAlta());

        self::assertSame('Activo', $this->fila($id, 'estado')['estado']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tienda/clases/ClaseTienda.php:82-87
     *
     * @group defecto
     * @group guardado
     * @group alto
     *
     * @que-ocurre-hoy Un alta que la base rechaza —un NIF más largo de lo que admite la columna—
     *   termina la petición con un error de la base, y la ficha no dice qué ha pasado.
     * @que-deberia-ocurrir Que el alta devuelva un error propio y la ficha lo enseñe.
     * @por-que-ocurre El fallo de la base llega como excepción y nadie la recoge; y aunque llegara
     *   como respuesta, la clase busca el error en una clave que la escritura no devuelve.
     * @como-deberia-funcionar Recoger el fallo en la clase y devolverlo como error.
     */
    public function test_unAltaQueLaBaseRechazaDevuelveUnError(): void
    {
        $datos = ['nif' => str_repeat('B', 11)] + $this->datosDeAlta();

        $respuesta = $this->sinErrorDeLaBase(fn () => (new \ClaseTienda())->addTienda($datos));

        self::assertIsArray($respuesta);
        self::assertArrayHasKey('error', $respuesta);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tienda/clases/ClaseTienda.php:98-108
     *
     * @group defecto
     * @group guardado
     * @group alto
     *
     * @que-ocurre-hoy Una modificación que la base rechaza termina la petición con un error de la
     *   base, y la ficha no dice qué ha pasado.
     * @que-deberia-ocurrir Que la modificación devuelva un error propio y la ficha lo enseñe.
     * @por-que-ocurre El fallo de la base llega como excepción y nadie la recoge; y aunque llegara
     *   como respuesta, la clase busca el error en una clave que la escritura no devuelve.
     * @como-deberia-funcionar Recoger el fallo en la clase y devolverlo como error.
     */
    public function test_unaModificacionQueLaBaseRechazaDevuelveUnError(): void
    {
        $id = $this->siembra->tienda($this->ano(), 'fisica');

        $respuesta = $this->sinErrorDeLaBase(
            fn () => (new \ClaseTienda())->modificarTienda(['idtienda' => (string) $id, 'nif' => str_repeat('B', 11)])
        );

        self::assertIsArray($respuesta);
        self::assertArrayHasKey('error', $respuesta);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tienda/clases/ClaseTienda.php:98
     *
     * @group defecto
     * @group entrada
     * @group critico
     *
     * @que-ocurre-hoy El nombre de un campo del formulario entra en la sentencia como parte de ella:
     *   un campo llamado `razonsocial='Cambiada',nif` cambia la razón social de la tienda.
     * @que-deberia-ocurrir Que la modificación solo escriba los campos de la tienda, con su valor.
     * @por-que-ocurre La ficha pasa el formulario entero a la clase, y la clase base escribe cada
     *   nombre recibido como nombre de columna.
     * @como-deberia-funcionar Escribir solo las columnas de la tienda, con una lista cerrada.
     */
    public function test_laModificacionSoloEscribeLosCamposDeLaTienda(): void
    {
        $id = $this->siembra->tienda($this->ano(), 'fisica');

        $this->sinErrorDeLaBase(
            fn () => (new \ClaseTienda())->modificarTienda(['idtienda' => (string) $id, "razonsocial='Cambiada',nif" => 'X0000000X'])
        );

        self::assertSame('Tienda de pruebas', $this->fila($id, 'razonsocial')['razonsocial']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tienda/clases/ClaseTienda.php:23-28
     *
     * @group defecto
     * @group entrada
     * @group alto
     *
     * @que-ocurre-hoy Un número de tienda con una condición añadida —`7 OR 1=1`, en la dirección de la
     *   ficha— trae todas las tiendas.
     * @que-deberia-ocurrir Que traiga como mucho la tienda de ese número.
     * @por-que-ocurre El número se pega a la sentencia sin convertirlo.
     * @como-deberia-funcionar Pasar el número a la base como dato, convertido a entero.
     */
    public function test_unNumeroDeTiendaManipuladoNoTraeOtras(): void
    {
        $id = $this->siembra->tienda($this->ano(), 'fisica');
        self::assertGreaterThan(1, $this->recuento(), 'Hacen falta dos tiendas para ver el efecto.');

        $datos = $this->sinErrorDeLaBase(fn () => (new \ClaseTienda())->obtenerUnaTienda($id . ' OR 1=1')['datos']);

        self::assertLessThanOrEqual(1, count($datos));
    }

    /**
     * @estado rojo
     * @codigo-afectado clases/ClaseTablaTienda.php:5-12, 21-29
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy La clase deja avisos de PHP: al crearse, porque guarda la conexión y el
     *   recuento en propiedades que no declara, y al pedirle una tienda que no existe, porque devuelve
     *   una variable que no ha llegado a definir.
     * @que-deberia-ocurrir Que lea sin avisos, y que una tienda que no existe dé un resultado vacío.
     * @por-que-ocurre Propiedades sin declarar, obsoletas desde PHP 8.2, y un resultado que solo se
     *   asigna si hay fila.
     * @como-deberia-funcionar Declarar las propiedades y dar valor de partida al resultado; o retirar
     *   la clase, que no tiene llamador.
     */
    public function test_laClaseDeClasesLeeSinAvisos(): void
    {
        [$tienda, $avisos] = self::recogiendoAvisos(fn () => (new \ClaseTablaTienda($this->db))->DatosTienda(999999));

        self::assertNull($tienda);
        self::assertSame([], $avisos, 'Sin avisos de PHP.');
    }

    /** @return array<string, string> */
    private function datosDeAlta(): array
    {
        return [
            'tipoTienda' => 'fisica', 'nif' => 'B00000000', 'razonsocial' => 'Tienda nueva S.L.', 'NombreComercial' => 'Tienda nueva',
            'ano' => $this->ano(), 'direccion' => 'Calle de la Prueba 1', 'telefono' => '986000000',
        ];
    }

    private function recuento(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM tiendas')->fetch_row()[0];
    }

    private function recuentoDe(string $condicion): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM tiendas WHERE ' . $condicion)->fetch_row()[0];
    }

    /** @return array<string, string|null> */
    private function fila(int $id, string $columnas): array
    {
        return $this->db->query('SELECT ' . $columnas . ' FROM tiendas WHERE idTienda = ' . $id)->fetch_assoc();
    }

    /** Ejecuta la llamada y convierte un error de la base en un fallo del caso, con su mensaje. */
    private function sinErrorDeLaBase(callable $llamada): mixed
    {
        try {
            return $llamada();
        } catch (\mysqli_sql_exception $error) {
            self::fail('La petición termina con un error de la base: ' . $error->getMessage());
        }
    }

    /**
     * Ejecuta la llamada recogiendo los avisos de PHP en lugar de dejar que la suite los convierta en
     * error.
     *
     * @return array{0:mixed, 1:list<string>}
     */
    private static function recogiendoAvisos(callable $llamada): array
    {
        $avisos = [];
        set_error_handler(static function (int $nivel, string $mensaje) use (&$avisos): bool {
            $avisos[] = $mensaje;

            return true;
        });
        try {
            $resultado = $llamada();
        } finally {
            restore_error_handler();
        }

        return [$resultado, $avisos];
    }
}
