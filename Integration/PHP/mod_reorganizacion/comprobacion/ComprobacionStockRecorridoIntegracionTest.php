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
use TPVFox\Test\Siembra\Siembra;

final class ComprobacionStockRecorridoIntegracionTest extends CasoIntegracion
{
    // El sistema abre su propia transacción, de modo que el aislamiento por ROLLBACK
    // de la suite no protege: lo que se siembra aquí se retira a mano.
    protected bool $aislarPorTransaccion = false;
    protected bool $compartirConexionConElProducto = true;

    private Siembra $siembra;

    /** Lo sembrado por este caso, para retirarlo en el orden inverso. */
    private array $sembrado = [
        'ticketst' => [],
        'albprot' => [],
        'articulos' => [],
        'tiendas' => [],
        'usuarios' => [],
        'clientes' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION ??= [];
        $this->siembra = new Siembra($this->db);
        $this->incluirTPVFox('/modulos/mod_reorganizacion/clases/ClaseComprobacionStockContexto.php');
        $this->incluirTPVFox('/modulos/mod_reorganizacion/clases/ClaseComprobacionStockExtraccion.php');
    }

    protected function tearDown(): void
    {
        $this->db->query('COMMIT');
        foreach ($this->sembrado['ticketst'] as $id) {
            $this->db->query('DELETE FROM ticketslinea WHERE idticketst = ' . (int) $id);
            $this->db->query('DELETE FROM ticketst WHERE id = ' . (int) $id);
        }
        foreach ($this->sembrado['albprot'] as $id) {
            $this->db->query('DELETE FROM albprolinea WHERE idalbpro = ' . (int) $id);
            $this->db->query('DELETE FROM albprot WHERE id = ' . (int) $id);
        }
        foreach ($this->sembrado['articulos'] as $id) {
            $this->db->query('DELETE FROM articulosFamilias WHERE idArticulo = ' . (int) $id);
            $this->db->query('DELETE FROM articulos WHERE idArticulo = ' . (int) $id);
        }
        foreach ($this->sembrado['clientes'] as $id) {
            $this->db->query('DELETE FROM clientes WHERE idClientes = ' . (int) $id);
        }
        foreach ($this->sembrado['usuarios'] as $id) {
            $this->db->query('DELETE FROM usuarios WHERE id = ' . (int) $id);
        }
        // La tienda va la última y es la que más importa: se siembra como principal y
        // activa, y dos filas así a la vez impiden entrar a la aplicación.
        foreach ($this->sembrado['tiendas'] as $id) {
            $this->db->query('DELETE FROM tiendas WHERE idTienda = ' . (int) $id);
        }

        unset($_SESSION['tiendaTpv']);
        parent::tearDown();
    }

    /**
     * Tienda, usuario y cliente los crea la siembra por debajo, la primera vez que se le
     * pide sembrar cualquier cosa. En un caso con retroceso desaparecen solos; aquí no
     * hay retroceso, así que se piden de forma explícita y por adelantado para poder
     * anotar sus identificadores y retirarlos al terminar. La tienda es la que importa:
     * se siembra como principal y activa, y la aplicación no deja entrar si hay dos.
     */
    private function anotarLoQueSiembraPorDebajo(): void
    {
        $this->sembrado['tiendas'][] = $this->siembra->tiendaPorDefecto();
        $this->sembrado['usuarios'][] = $this->siembra->usuarioPorDefecto();
        $this->sembrado['clientes'][] = $this->siembra->clientePorDefecto();
    }

    public function test_T1_elBloqueDeLecturaRechazaLaTablaTemporalQueElComponenteConsumidoNecesita(): void
    {
        // Esta es la restricción que ordena el recorrido: mientras el bloque esté
        // abierto, la tabla temporal con que el componente consumido acota la ventana
        // de recepción no puede crearse. En cuanto se cierra, sí.
        $_SESSION['tiendaTpv'] = ['ano' => '2026', 'idTienda' => '1'];
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
        $this->anotarLoQueSiembraPorDebajo();

        $idArticulo = $this->siembra->articulo('Producto que toca negativo y se recupera');
        $this->sembrado['articulos'][] = $idArticulo;
        $this->sembrado['albprot'][] = $this->siembra->entradaProveedor($idArticulo, 5.0, '2026-03-01');
        $this->sembrado['ticketst'][] = $this->siembra->ventaTicket($idArticulo, 8.0, '2026-03-02');
        $this->sembrado['albprot'][] = $this->siembra->entradaProveedor($idArticulo, 5.0, '2026-03-03');

        $_SESSION['tiendaTpv'] = ['ano' => '2026', 'idTienda' => '1'];
        $contextoClase = new \ClaseComprobacionStockContexto();
        $apertura = $contextoClase->abrir();
        self::assertTrue($apertura['ok']);

        $estadoProducto = (new \ClaseComprobacionStockExtraccion())->extraer($apertura, false, '2026-06-30');
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
        $_SESSION['tiendaTpv'] = ['ano' => '2026', 'idTienda' => '1'];
        $contextoClase = new \ClaseComprobacionStockContexto();
        $apertura = $contextoClase->abrir();
        self::assertTrue($apertura['ok']);

        (new \ClaseComprobacionStockExtraccion())->extraer($apertura, false, '2026-06-30');

        $enTransaccion = $this->db->query('SELECT @@in_transaction AS activa')->fetch_assoc();
        self::assertSame('0', $enTransaccion['activa'], 'La extracción tiene que cerrar el bloque antes de cruzar');

        $contextoClase->cerrar();
    }
}
