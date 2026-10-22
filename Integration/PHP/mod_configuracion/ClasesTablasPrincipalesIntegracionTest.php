<?php

/**
 * Las clases que leen y escriben las tablas principales —tipos de IVA, formas de pago y tipos de
 * vencimiento—: las de `modulos/mod_configuracion/clases/`, que usa la pantalla de tablas principales
 * para listar, leer, dar de alta y modificar; y las de `clases/` que leen esas tablas para el resto del
 * producto: `ClaseTablaIva` (artículos), `iva.php` (cierres), `FormasPago` (clientes y compras) y
 * `TiposVencimiento.php` (clientes).
 *
 * El alta y la modificación por la petición de fondo de la pantalla ya las prueba la gestión de los
 * tipos de relación (`TablasPrincipalesIntegracionTest`, casos T1 a T5); aquí se prueban las clases.
 * Las que heredan de la clase base de acceso a datos usan la conexión del caso; las otras la reciben.
 *
 * Los casos de `ClaseTablaIva` corren en su propio proceso: el producto solo la carga a través de la
 * clase de artículos, que la incluye sin comprobar si ya lo está, y cargarla aquí en el proceso común
 * de la suite haría que esa inclusión la declarase dos veces.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModConfiguracion;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class ClasesTablasPrincipalesIntegracionTest extends CasoIntegracion
{
    protected bool $compartirConexionConElProducto = true;

    private Siembra $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_configuracion/clases/ClaseIva.php');
        $this->incluirTPVFox('/modulos/mod_configuracion/clases/ClaseFormasPago.php');
        $this->incluirTPVFox('/modulos/mod_configuracion/clases/ClaseVencimiento.php');
        $this->incluirTPVFox('/clases/iva.php');
        $this->incluirTPVFox('/clases/FormasPago.php');
        $this->incluirTPVFox('/clases/TiposVencimiento.php');
        $this->siembra = new Siembra($this->db);
    }

    /** @comportamiento Cada clase de la pantalla lista todas las filas de su tabla. */
    public function test_cadaTablaSeListaEntera(): void
    {
        $this->siembra->tipoIva(21.0, 5.2, 'IVA de la lista');
        $this->formaDePago('Pago de la lista');
        $this->vencimiento('Vence de la lista', 30);

        self::assertCount($this->recuento('iva'), (new \ClaseIva($this->db))->cargarDatos()['datos']);
        self::assertCount($this->recuento('formasPago'), (new \ClaseFormasPago($this->db))->cargarDatos()['datos']);
        self::assertCount($this->recuento('tiposVencimiento'), (new \ClaseVencimiento($this->db))->cargarDatos()['datos']);
    }

    /** @comportamiento Cada clase de la pantalla lee una fila de su tabla por su número. */
    public function test_unaFilaSeLeePorSuNumero(): void
    {
        $iva = $this->siembra->tipoIva(10.0, 1.4, 'IVA leido');
        $forma = $this->formaDePago('Pago leido');
        $vencimiento = $this->vencimiento('Vence leido', 45);

        self::assertSame('IVA leido', (new \ClaseIva($this->db))->getDatos($iva)['datos'][0]['descripcionIva']);
        self::assertSame('Pago leido', (new \ClaseFormasPago($this->db))->getDatos($forma)['datos'][0]['descripcion']);
        self::assertSame('45', (new \ClaseVencimiento($this->db))->getDatos($vencimiento)['datos'][0]['dias']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_configuracion/clases/ClaseIva.php:17-32, modulos/mod_configuracion/clases/ClaseFormasPago.php:17-32, modulos/mod_configuracion/clases/ClaseVencimiento.php:16-31
     *
     * @group defecto
     * @group entrada
     * @group alto
     *
     * @que-ocurre-hoy Una descripción con una comilla simple no se guarda en ninguna de las tres tablas:
     *   la petición termina con un error de la base. Lo tecleado entra en la sentencia como parte de
     *   ella.
     * @que-deberia-ocurrir Que se guarde tal como se escribió.
     * @por-que-ocurre El alta y la modificación pegan cada valor entre comillas simples sin escaparlo.
     * @como-deberia-funcionar Pasar los valores a la base como datos, no pegados a la sentencia.
     *
     * @dataProvider tablas
     */
    public function test_unaDescripcionConComillaSeGuardaTalCual(string $clase, string $tabla, string $columna, array $datos): void
    {
        $this->sinErrorDeLaBase(fn () => (new $clase($this->db))->insertarRegistro(['descripcion' => "Pago 'al contado'"] + $datos));

        self::assertSame(1, $this->recuento($tabla, "$columna = 'Pago \\'al contado\\''"));
    }

    /** @return array<string, array{string, string, string, array<string,string>}> */
    public static function tablas(): array
    {
        return [
            'tipo de IVA' => ['ClaseIva', 'iva', 'descripcionIva', ['iva' => '4.00', 'recargo' => '0.50']],
            'forma de pago' => ['ClaseFormasPago', 'formasPago', 'descripcion', []],
            'tipo de vencimiento' => ['ClaseVencimiento', 'tiposVencimiento', 'descripcion', ['dias' => '30']],
        ];
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_configuracion/clases/ClaseIva.php:20-23
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy Guardar devuelve la sentencia que se ejecutó, con los datos, y la petición de
     *   fondo la envía al navegador; en las tres tablas.
     * @que-deberia-ocurrir Que el guardado devuelva si fue bien y, si no, un error propio, sin la
     *   sentencia.
     * @por-que-ocurre La clase base de la pantalla devuelve siempre la sentencia y una clave de error, y
     *   la clase devuelve su respuesta entera porque la clave de error está siempre puesta.
     * @como-deberia-funcionar Devolver solo el resultado o el error.
     */
    public function test_guardarNoDevuelveLaSentencia(): void
    {
        $id = $this->siembra->tipoIva(21.0, 5.2, 'IVA a modificar');

        $respuesta = (new \ClaseIva($this->db))->modificarTabla(['id' => (string) $id, 'descripcion' => 'IVA modificado', 'iva' => '21.00', 'recargo' => '5.20']);

        self::assertFalse(str_contains((string) json_encode($respuesta), 'UPDATE'), 'La respuesta lleva la sentencia');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_configuracion/clases/ClaseIva.php:25-32
     *
     * @group defecto
     * @group guardado
     * @group alto
     *
     * @que-ocurre-hoy Un tipo de IVA con un porcentaje que no es un número termina la petición con un
     *   error de la base, y la pantalla no dice qué ha pasado; en las tres tablas.
     * @que-deberia-ocurrir Que el guardado devuelva un error propio y la pantalla lo enseñe.
     * @por-que-ocurre La base avisa con una excepción y nadie la recoge.
     * @como-deberia-funcionar Comprobar el valor antes de guardarlo y recoger el fallo de la base.
     */
    public function test_unGuardadoQueLaBaseRechazaDevuelveUnError(): void
    {
        $respuesta = $this->sinErrorDeLaBase(fn () => (new \ClaseIva($this->db))->insertarRegistro(['descripcion' => 'IVA erroneo', 'iva' => 'diez', 'recargo' => '0']));

        self::assertNotSame('0', $respuesta['error'] ?? '0');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_configuracion/clases/ClaseIva.php:12-16, clases/FormasPago.php:13-32
     *
     * @group defecto
     * @group entrada
     * @group alto
     *
     * @que-ocurre-hoy Un número de fila con una condición añadida —`0 OR 1=1`— trae todas las filas: la
     *   ventana de la pantalla enseña otra, y la forma de pago que lee compras es otra.
     * @que-deberia-ocurrir Que traiga como mucho la fila de ese número.
     * @por-que-ocurre Las clases pegan el número a la sentencia sin convertirlo.
     * @como-deberia-funcionar Tomar el número como entero, en una sentencia preparada.
     */
    public function test_unNumeroManipuladoNoTraeOtrasFilas(): void
    {
        $this->siembra->tipoIva(21.0, 5.2, 'IVA uno');
        $this->siembra->tipoIva(10.0, 1.4, 'IVA dos');
        $this->formaDePago('Pago uno');

        $ivas = (new \ClaseIva($this->db))->getDatos('0 OR 1=1')['datos'] ?? [];
        $forma = (new \FormasPago())->datosPrincipal('999999 OR 1=1');

        self::assertLessThanOrEqual(1, count($ivas), 'La pantalla trae otras filas');
        self::assertSame(0, $forma, 'Compras lee otra forma de pago');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_configuracion/clases/ClaseIva.php:19, ClaseFormasPago.php:19, ClaseVencimiento.php:18; modulos/mod_configuracion/tareas.php:30, 38, 46
     *
     * @group defecto
     * @group entrada
     * @group alto
     *
     * @que-ocurre-hoy Guardar una fila con un número que lleva una condición añadida —`7 OR 1=1`—
     *   cambia todas las filas de la tabla: todos los tipos de IVA pasan a tener la descripción, el
     *   porcentaje y el recargo de esa fila.
     * @que-deberia-ocurrir Que cambie como mucho la fila de ese número.
     * @por-que-ocurre La modificación pega el número a la condición sin convertirlo, y la petición de
     *   fondo la elige porque el texto compara como mayor que 0.
     * @como-deberia-funcionar Tomar el número como entero, en una sentencia preparada.
     */
    public function test_unaModificacionConUnNumeroConCondicionSoloCambiaEsaFila(): void
    {
        $uno = $this->siembra->tipoIva(21.0, 5.2, 'IVA uno');
        $this->siembra->tipoIva(10.0, 1.4, 'IVA dos');

        $this->sinErrorDeLaBase(fn () => (new \ClaseIva($this->db))->modificarTabla(
            ['id' => $uno . ' OR 1=1', 'descripcion' => 'Cambiadas todas', 'iva' => '4', 'recargo' => '0.5']
        ));

        self::assertLessThanOrEqual(1, $this->recuento('iva', "descripcionIva = 'Cambiadas todas'"), 'Cambian otras filas');
    }

    /** @comportamiento Una modificación con un número que no es de ninguna fila no cambia ni crea ninguna. */
    public function test_unaModificacionConUnNumeroQueNoExisteNoCambiaNinguna(): void
    {
        $antes = $this->recuento('iva');
        $libre = (int) $this->db->query('SELECT COALESCE(MAX(idIva), 0) + 1000 FROM iva')->fetch_row()[0];

        $respuesta = (new \ClaseIva($this->db))->modificarTabla(['id' => $libre, 'descripcion' => 'Ninguna', 'iva' => '4', 'recargo' => '0.5']);

        self::assertSame('0', $respuesta['error']);
        self::assertSame($antes, $this->recuento('iva'));
        self::assertSame(0, $this->recuento('iva', "descripcionIva = 'Ninguna'"));
    }

    /**
     * @comportamiento La clase de IVA de `clases/` cuenta los tipos al crearse, los devuelve todos, da
     *   el porcentaje de uno por su número y los que tienen un porcentaje distinto de uno dado.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_laClaseDeIvaDeClasesLeeLosTipos(): void
    {
        $this->incluirTPVFox('/clases/ClaseTablaIva.php');
        $id = $this->siembra->tipoIva(21.0, 5.2, 'IVA general');
        $ivas = new \ClaseTablaIva($this->db);

        self::assertSame((string) $this->recuento('iva'), (string) $ivas->getNumRows());
        self::assertCount($this->recuento('iva'), $ivas->todoIvas());
        self::assertSame('21.00', $ivas->getIva((string) $id));
        self::assertNotContains('21.00', array_column($ivas->ivasNoPrincipal(21), 'iva'));
    }

    /**
     * @estado rojo
     * @codigo-afectado clases/ClaseTablaIva.php:43-58
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Pedir el porcentaje de un tipo de IVA con su número como entero no lo encuentra:
     *   devuelve nulo, sin aviso.
     * @que-deberia-ocurrir Que lo encuentre llegue el número como texto o como entero.
     * @por-que-ocurre Compara el número recibido con el leído de la base, que es texto, de forma
     *   estricta.
     * @como-deberia-funcionar Convertir los dos a entero antes de compararlos, o buscar el tipo en la
     *   base por su número.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_elPorcentajeDeUnTipoSeEncuentraConSuNumeroComoEntero(): void
    {
        $this->incluirTPVFox('/clases/ClaseTablaIva.php');
        $id = $this->siembra->tipoIva(21.0, 5.2, 'IVA general');

        self::assertSame('21.00', (new \ClaseTablaIva($this->db))->getIva($id));
    }

    /**
     * @comportamiento La clase de IVA de los cierres cuenta los tipos al crearse, los devuelve todos
     *   tal como están, y da los de otro porcentaje con sus datos renombrados.
     */
    public function test_laClaseDeIvaDeLosCierresLeeLosTipos(): void
    {
        $id = $this->siembra->tipoIva(4.0, 0.5, 'IVA superreducido');
        $ivas = new \iva($this->db);

        $otros = $ivas->ivasNoPrincipal(21);

        self::assertSame((string) $this->recuento('iva'), (string) $ivas->getNumRows());
        self::assertContains((string) $id, array_column($ivas->todoIvas(), 'idIva'));
        self::assertContains(['id' => (string) $id, 'descripcion' => 'IVA superreducido', 'iva' => '4.00', 'recargo' => '0.50'], $otros);
    }

    /**
     * @comportamiento Las formas de pago se leen todas, una por su número —o 0 si no existe— y todas
     *   menos una.
     */
    public function test_lasFormasDePagoSeLeen(): void
    {
        $id = $this->formaDePago('Pago leido');
        $formas = new \FormasPago();

        self::assertCount($this->recuento('formasPago'), $formas->todas());
        self::assertSame('Pago leido', $formas->datosPrincipal($id)['descripcion']);
        self::assertSame(0, $formas->datosPrincipal(999999));
        self::assertNotContains((string) $id, array_column($formas->formadePagoSinPrincipal($id), 'id'));
    }

    /**
     * @comportamiento Los tipos de vencimiento se leen todos, uno por su número —como lista de filas— y
     *   todos menos uno.
     */
    public function test_losTiposDeVencimientoSeLeen(): void
    {
        $id = $this->vencimiento('Vence leido', 60);
        $tipos = new \TiposVencimientos();

        self::assertCount($this->recuento('tiposVencimiento'), $tipos->todos()['datos']);
        self::assertSame('60', $tipos->datosPrincipal($id)[0]['dias']);
        self::assertNotContains((string) $id, array_column($tipos->MenosPrincipal($id)['datos'], 'id'));
    }

    /** @comportamiento Las formas de pago sin la principal son todas las demás que haya. */
    public function test_lasFormasDePagoSinLaPrincipalSonLasDemas(): void
    {
        $principal = $this->formaDePago('Pago principal');
        $otra = $this->formaDePago('Pago otro');

        $demas = array_column((new \FormasPago())->formadePagoSinPrincipal($principal), 'id');

        self::assertContains((string) $otra, $demas);
        self::assertNotContains((string) $principal, $demas);
        self::assertCount($this->recuento('formasPago') - 1, $demas);
    }

    private function formaDePago(string $descripcion): int
    {
        $sentencia = $this->db->prepare('INSERT INTO formasPago (descripcion) VALUES (?)');
        $sentencia->bind_param('s', $descripcion);
        $sentencia->execute();

        return (int) $this->db->insert_id;
    }

    private function vencimiento(string $descripcion, int $dias): int
    {
        $sentencia = $this->db->prepare('INSERT INTO tiposVencimiento (descripcion, dias) VALUES (?, ?)');
        $sentencia->bind_param('si', $descripcion, $dias);
        $sentencia->execute();

        return (int) $this->db->insert_id;
    }

    private function recuento(string $tabla, string $condicion = '1'): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM $tabla WHERE $condicion")->fetch_row()[0];
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
}
