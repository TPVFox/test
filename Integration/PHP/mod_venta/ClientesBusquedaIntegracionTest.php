<?php

/**
 * Busqueda de cliente para la cabecera del documento. El caso de despacho `buscarClientes`
 * (`tareas/BuscarClientes.php`) solo normaliza la forma de la respuesta; la busqueda misma la hace
 * `Cliente` de `clases/cliente.php`, y es ahi donde viven las combinaciones que el camino sano no
 * toca, enumeradas al analizar las condiciones de test de FS-002.
 *
 * Se prueba la clase directamente y no por el despacho a proposito: `tareas.php` carga cada caso con
 * `include_once`, de modo que una segunda invocacion de `buscarClientes` en el mismo proceso PHP no
 * vuelve a cargar el fichero y devuelve una respuesta vacia. En produccion no ocurre —cada peticion
 * HTTP es un proceso nuevo—, pero en la suite, que corre en un solo proceso, impediria mas de un caso
 * de despacho por clase. La clase no tiene esa limitacion, y los defectos que aqui importan son
 * suyos.
 *
 * Dos casos reproducen defecto y se conservan en rojo: una busqueda por nombre no encuentra un
 * cliente cuyas palabras esten repartidas entre nombre comercial y razon social, y la sustitucion
 * textual del nombre de columna convierte una palabra buscada en el nombre de la columna. Su
 * correccion no es de este PCP.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVenta;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class ClientesBusquedaIntegracionTest extends CasoIntegracion
{
    private \Cliente $cliente;
    private Siembra $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/clases/cliente.php');
        $this->cliente = new \Cliente($this->db);
        $this->siembra = new Siembra($this->db);
    }

    /**
     * Cliente activo x via por id: el camino sano. La via por id devuelve un unico cliente, con su
     * fila plana. Fija que el resto de combinaciones se mide contra este.
     */
    public function test_porIdDevuelveElClienteActivo(): void
    {
        $idCliente = $this->siembra->cliente('Cliente Activo');

        $fila = $this->cliente->DatosClientePorId($idCliente);

        self::assertSame((string) $idCliente, (string) $fila['idClientes']);
        self::assertSame('Activo', $fila['estado']);
    }

    /**
     * Via por id x cliente inexistente: sin fila, la clase deja su variable de retorno sin asignar y
     * devuelve nulo. Es el patron de variable no asignada de FS-003; aqui el despacho lo amortigua
     * despues, pero la clase por si sola no distingue «no existe» de un fallo.
     */
    public function test_porIdInexistenteDevuelveNuloSinFila(): void
    {
        $fila = @$this->cliente->DatosClientePorId(99999999);

        self::assertNull($fila);
    }

    /**
     * Via por nombre x cliente activo con una palabra: el camino sano de la busqueda por nombre.
     * Devuelve la lista con ese cliente.
     */
    public function test_porNombreEncuentraPorUnaPalabra(): void
    {
        $this->siembra->cliente('Panaderia Central');

        $r = $this->cliente->BuscarClientePorNombre('Panaderia');

        self::assertCount(1, $r['datos']);
    }

    /**
     * Via por nombre x palabras repartidas entre los dos campos: la consulta exige todas las
     * palabras en el nombre comercial O todas en la razon social. Un cliente con una palabra en cada
     * campo no cumple ninguno de los dos grupos y no aparece, aunque cada palabra exista en el.
     *
     * Defecto: se conserva en rojo.
     */
    public function test_defecto_nombrePartidoEntreComercialYRazonSocialNoAparece(): void
    {
        $this->siembra->cliente('Panaderia', ['razonsocial' => 'Hermanos Perez SL']);

        $r = $this->cliente->BuscarClientePorNombre('Panaderia Perez');

        self::assertCount(1, $r['datos'], 'el cliente existe: una palabra en cada campo deberia encontrarlo');
    }

    /**
     * Via por nombre x palabra igual al nombre de columna: el segundo grupo se obtiene sustituyendo
     * textualmente «Nombre» por «razonsocial» sobre el primero, de modo que buscar la palabra
     * «Nombre» produce `razonsocial LIKE "%razonsocial%"`. La sustitucion no distingue la columna
     * del valor buscado, y arrastra el termino a un literal que ningun cliente cumple.
     *
     * Defecto: se conserva en rojo.
     */
    public function test_defecto_palabraIgualAlNombreDeColumnaSeSustituye(): void
    {
        // La palabra buscada esta solo en la razon social, no en el nombre comercial: asi el unico
        // camino para encontrar el cliente es la rama de razon social, que es la que la sustitucion
        // rompe. Buscar «Nombre» produce `razonsocial LIKE "%razonsocial%"`, un literal que este
        // cliente no cumple, de modo que no aparece pese a llevar «Nombre» en su razon social.
        $this->siembra->cliente('Distribuciones del Sur', ['razonsocial' => 'Nombre Oficial SL']);

        $r = $this->cliente->BuscarClientePorNombre('Nombre');

        self::assertCount(1, $r['datos'], 'la palabra buscada no debe convertirse en el nombre de la columna');
    }

    /**
     * Via por id x SQL invalido: `consulta()` da por hecho que `mysqli::query()` devuelve `false` en
     * un fallo, y construye `['error']`/`['consulta']` en ese caso. En PHP >= 8.1, sin
     * `mysqli_report()` invocado en ningun punto del producto, un fallo de sintaxis lanza
     * `mysqli_sql_exception` antes de llegar ahi: esa rama no se alcanza nunca.
     *
     * Defecto: se conserva en rojo.
     */
    public function test_defecto_idInvalidoLanzaExcepcionEnVezDeDevolverError(): void
    {
        $this->expectException(\mysqli_sql_exception::class);

        $this->cliente->DatosClientePorId('no_es_un_id');
    }
}
