<?php
/**
 * El recorrido del ejercicio vigente con el bloque de solo lectura abierto.
 *
 * Ningún otro fichero de la suite ejercita las dos cosas a la vez: el bloque se abre
 * en un sitio y la extracción se prueba en otro, siempre fuera de él. La combinación
 * tiene una restricción propia —el componente que resuelve las existencias negativas
 * necesita crear una tabla temporal, y dentro del bloque el motor la rechaza— que solo
 * se ve ejecutando las dos juntas.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModReorganizacion\Comprobacion;

use mysqli_sql_exception;
use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\EscenarioComprobacionStock;
use TPVFox\Test\Siembra\Siembra;

final class ComprobacionStockRecorridoIntegracionTest extends CasoIntegracion
{
    // El sistema abre su propia transacción, de modo que el aislamiento por ROLLBACK
    // de la suite no protege: lo que se siembra aquí se retira a mano.
    protected bool $aislarPorTransaccion = false;
    protected bool $compartirConexionConElProducto = true;

    private Siembra $siembra;
    private EscenarioComprobacionStock $escenario;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION ??= [];
        $this->siembra = new Siembra($this->db);
        $this->escenario = $this->nuevoEscenario($this->siembra);
        $this->incluirTPVFox('/modulos/mod_reorganizacion/clases/ClaseComprobacionStockContexto.php');
        $this->incluirTPVFox('/modulos/mod_reorganizacion/clases/ClaseComprobacionStockExtraccion.php');
    }

    /**
     * Retira lo que este caso haya insertado, en el orden inverso al de las dependencias.
     *
     * Se pregunta a la siembra qué insertó ella, en lugar de llevar la cuenta aquí: los
     * apoyos por debajo —tienda, usuario, cliente— reutilizan los que ya haya en la base
     * en vez de crear otros, y una lista llevada a mano no distingue lo creado de lo
     * encontrado. Borrar por esa lista se lleva por delante filas que ya estaban y de las
     * que cuelgan otras.
     */
    protected function tearDown(): void
    {
        $this->db->query('COMMIT');
        // El desglose de impuestos cuelga de su documento por clave foránea: si no se
        // retira antes, el borrado del documento lo rechaza el motor.
        foreach ($this->siembra->insertadoEn('ticketst') as $id) {
            $this->db->query('DELETE FROM ticketstIva WHERE idticketst = ' . $id);
            $this->db->query('DELETE FROM ticketslinea WHERE idticketst = ' . $id);
            $this->db->query('DELETE FROM ticketst WHERE id = ' . $id);
        }
        foreach ($this->siembra->insertadoEn('albprot') as $id) {
            $this->db->query('DELETE FROM albproIva WHERE idalbpro = ' . $id);
            $this->db->query('DELETE FROM albprolinea WHERE idalbpro = ' . $id);
            $this->db->query('DELETE FROM albprot WHERE id = ' . $id);
        }
        foreach ($this->siembra->insertadoEn('articulos') as $id) {
            $this->db->query('DELETE FROM articulosFamilias WHERE idArticulo = ' . $id);
            $this->db->query('DELETE FROM articulos WHERE idArticulo = ' . $id);
        }
        foreach ($this->siembra->insertadoEn('clientes') as $id) {
            $this->db->query('DELETE FROM clientes WHERE idClientes = ' . $id);
        }
        foreach ($this->siembra->insertadoEn('usuarios') as $id) {
            $this->db->query('DELETE FROM usuarios WHERE id = ' . $id);
        }
        foreach ($this->siembra->insertadoEn('proveedores') as $id) {
            $this->db->query('DELETE FROM proveedores WHERE idProveedor = ' . $id);
        }
        // La tienda va la última: es principal y activa, y dos filas así a la vez impiden
        // entrar a la aplicación. Solo se retira si la creó este caso.
        foreach ($this->siembra->insertadoEn('tiendas') as $id) {
            $this->db->query('DELETE FROM tiendas WHERE idTienda = ' . $id);
        }

        unset($_SESSION['tiendaTpv']);
        parent::tearDown();
    }

    public function test_T1_elBloqueDeLecturaRechazaLaTablaTemporalQueElComponenteConsumidoNecesita(): void
    {
        // Esta es la restricción que ordena el recorrido: mientras el bloque esté
        // abierto, la tabla temporal con que el componente consumido acota la ventana
        // de recepción no puede crearse. En cuanto se cierra, sí.
        $_SESSION['tiendaTpv'] = ['ano' => $this->ano(), 'idTienda' => (string) $this->siembra->tiendaPorDefecto()];
        $contextoClase = new \ClaseComprobacionStockContexto();

        self::assertTrue($contextoClase->abrir()['ok']);

        $rechazada = false;
        try {
            $this->db->query('CREATE TEMPORARY TABLE tmp_comprobacion_recorrido (a INT) ENGINE=MEMORY');
        } catch (mysqli_sql_exception) {
            $rechazada = true;
        }
        self::assertTrue($rechazada, 'Dentro del bloque de solo lectura la tabla temporal tendría que rechazarse');

        $contextoClase->cerrar();

        $this->db->query('CREATE TEMPORARY TABLE tmp_comprobacion_recorrido (a INT) ENGINE=MEMORY');
        $this->db->query('DROP TEMPORARY TABLE tmp_comprobacion_recorrido');
    }

    public function test_T2_laExtraccionCompletaTerminaSobreUnProductoQueTocaNegativoYSeRecupera(): void
    {
        // El producto que se recupera es el que obliga al componente consumido a acotar
        // la ventana de recepción, que es lo que exige la tabla temporal. Sobre él, la
        // extracción entera tiene que llegar hasta el final con el bloque abierto.
        $idArticulo = $this->escenario->E28()['idArticulo'];

        $_SESSION['tiendaTpv'] = ['ano' => $this->ano(), 'idTienda' => (string) $this->siembra->tiendaPorDefecto()];
        $contextoClase = new \ClaseComprobacionStockContexto();
        $apertura = $contextoClase->abrir();
        self::assertTrue($apertura['ok']);
        // El contexto real trae la fecha de corte del día en que se ejecuta. Aquí se
        // sustituye por una fija para que el caso no dependa de cuándo se lance.
        $apertura['fechaCorte'] = $this->ano() . '-06-30';

        $estadoProducto = (new \ClaseComprobacionStockExtraccion())->extraer($apertura);
        $contextoClase->cerrar();

        $fila = null;
        foreach ($estadoProducto as $candidata) {
            if ($candidata['idArticulo'] === $idArticulo) {
                $fila = $candidata;
            }
        }

        self::assertNotNull($fila, 'El producto que tocó negativo tiene que salir en el estado extraído');
        self::assertSame(-3.0, $fila['minimoAlcanzado']);
        self::assertSame(2.0, $fila['saldoAlCorte']);
        self::assertFalse($fila['marcado'], 'Al corte ya no está en negativo');
        self::assertNotNull($fila['tipoIncidencia'], 'El componente consumido tiene que haber emitido su tipo');
    }

    public function test_T3_laExtraccionDejaElBloqueCerradoAntesDeCruzarAlComponenteConsumido(): void
    {
        $_SESSION['tiendaTpv'] = ['ano' => $this->ano(), 'idTienda' => (string) $this->siembra->tiendaPorDefecto()];
        $contextoClase = new \ClaseComprobacionStockContexto();
        $apertura = $contextoClase->abrir();
        self::assertTrue($apertura['ok']);
        $apertura['fechaCorte'] = $this->ano() . '-06-30';

        (new \ClaseComprobacionStockExtraccion())->extraer($apertura);

        $enTransaccion = $this->db->query('SELECT @@in_transaction AS activa')->fetch_assoc();
        self::assertSame('0', $enTransaccion['activa'], 'La extracción tiene que cerrar el bloque antes de cruzar');

        $contextoClase->cerrar();
    }
}
