<?php
/**
 * Generador de datos de siembra.
 *
 * Los datos se generan, nunca se extraen de una instalacion real. Cada caso compone el
 * escenario que necesita en lugar de partir de un juego de datos comun: asi una prueba
 * no depende de lo que otra sembro, y el escenario se lee en el propio caso.
 *
 * Los valores por defecto colocan cada movimiento en los estados que el calculo de saldo
 * de TPVFox cuenta —proveedor en 'Guardado', ticket en 'Cerrado', albaran de cliente en
 * 'Guardado', y siempre 'Activo' en la linea—. Un caso que necesite lo contrario lo pide
 * por el arreglo de opciones.
 */

declare(strict_types=1);

namespace TPVFox\Test;

use mysqli;
use RuntimeException;

final class Siembra
{
    private int $contador = 0;

    /** Referencias de apoyo, creadas la primera vez que hacen falta. */
    private ?int $idTienda = null;
    private ?int $idUsuario = null;
    private ?int $idCliente = null;

    public function __construct(private mysqli $db)
    {
    }

    // --- Estructura ---------------------------------------------------------

    /**
     * Crea la tienda sobre la que opera el ejercicio.
     *
     * @param string $ano Ejercicio que declara la tienda, en cuatro cifras
     */
    public function tienda(string $ano, string $tipo = 'principal'): int
    {
        $this->idTienda = $this->insertar('tiendas', [
            'tipoTienda'      => $tipo,
            'razonsocial'     => 'Tienda de pruebas',
            'nif'             => 'X0000000X',
            'telefono'        => '000000000',
            'estado'          => 'Activo',
            'NombreComercial' => 'Tienda de pruebas',
            'direccion'       => 'Sin direccion',
            'ano'             => $ano,
        ]);

        return $this->idTienda;
    }

    public function familia(string $nombre, int $padre = 0): int
    {
        return $this->insertar('familias', [
            'familiaNombre'  => $nombre,
            'familiaPadre'   => $padre,
            'beneficiomedio' => 0,
            'mostrar_tpv'    => 1,
        ]);
    }

    /**
     * Crea un articulo del catalogo.
     *
     * @param array{familia?:int,tipo?:string,estado?:string,ultimoCoste?:float,idProveedor?:string} $opciones
     */
    public function articulo(string $nombre, array $opciones = []): int
    {
        $id = $this->insertar('articulos', [
            'articulo_name' => $nombre,
            'estado'        => $opciones['estado'] ?? 'Activo',
            'fecha_creado'  => '2020-01-01 00:00:00',
            'ultimoCoste'   => $opciones['ultimoCoste'] ?? 1.0,
            'tipo'          => $opciones['tipo'] ?? 'unidad',
            'iva'           => 0,
            'idProveedor'   => $opciones['idProveedor'] ?? null,
            'costepromedio' => 0,
            'beneficio'     => 0,
        ]);

        if (isset($opciones['familia'])) {
            $this->insertar('articulosFamilias', [
                'idArticulo' => $id,
                'idFamilia'  => $opciones['familia'],
            ]);
        }

        return $id;
    }

    /**
     * Fija la existencia registrada del articulo.
     *
     * El sistema bajo prueba no la lee, de modo que sirve para comprobar precisamente
     * que alterarla no cambia el resultado.
     */
    public function existenciaRegistrada(int $idArticulo, float $stockOn): int
    {
        return $this->insertar('articulosStocks', [
            'idArticulo' => $idArticulo,
            'idTienda'   => $this->tiendaPorDefecto(),
            'stockOn'    => $stockOn,
            'stockMin'   => 0,
            'stockMax'   => 0,
        ]);
    }

    // --- Movimientos --------------------------------------------------------

    /**
     * Entrada de proveedor: suma existencias.
     *
     * @param array{estado?:string,estadoLinea?:string,idProveedor?:int} $opciones
     * @return int Identificador del albaran, para anadirle mas lineas
     */
    public function entradaProveedor(int $idArticulo, float $unidades, string $fecha, array $opciones = []): int
    {
        $numero = ++$this->contador;

        $idAlbaran = $this->insertar('albprot', [
            'Numalbpro'    => $numero,
            'Fecha'        => $this->momento($fecha),
            'idTienda'     => $this->tiendaPorDefecto(),
            'idUsuario'    => $this->usuarioPorDefecto(),
            'idProveedor'  => $opciones['idProveedor'] ?? 1,
            'estado'       => $opciones['estado'] ?? 'Guardado',
            'total_siniva' => 0,
            'total'        => 0,
        ]);

        $this->lineaEntrada($idAlbaran, $idArticulo, $unidades, $opciones);

        return $idAlbaran;
    }

    /** Anade una linea a un albaran de proveedor ya creado. */
    public function lineaEntrada(int $idAlbaran, int $idArticulo, float $unidades, array $opciones = []): int
    {
        return $this->insertar('albprolinea', [
            'idalbpro'    => $idAlbaran,
            'Numalbpro'   => $this->valorDe('albprot', 'Numalbpro', $idAlbaran),
            'idArticulo'  => $idArticulo,
            'ncant'       => $unidades,
            'nunidades'   => $unidades,
            'estadoLinea' => $opciones['estadoLinea'] ?? 'Activo',
            'ref_prov'    => '',
            'costeSiva'   => 0,
            'iva'         => 0,
            'nfila'       => 1,
        ]);
    }

    /**
     * Salida por ticket: resta existencias.
     *
     * @param array{estado?:string,estadoLinea?:string} $opciones
     */
    public function ventaTicket(int $idArticulo, float $unidades, string $fecha, array $opciones = []): int
    {
        $numero = ++$this->contador;

        $idTicket = $this->insertar('ticketst', [
            'Numticket'     => $numero,
            'Numtempticket' => $numero,
            'Fecha'         => $this->momento($fecha),
            'idTienda'      => $this->tiendaPorDefecto(),
            'idUsuario'     => $this->usuarioPorDefecto(),
            'idCliente'     => $this->clientePorDefecto(),
            'estado'        => $opciones['estado'] ?? 'Cerrado',
            'formaPago'     => 'Efectivo',
            'entregado'     => 0,
            'total'         => 0,
        ]);

        $this->insertar('ticketslinea', [
            'idticketst'  => $idTicket,
            'Numticket'   => $numero,
            'idArticulo'  => $idArticulo,
            'cref'        => '',
            'ccodbar'     => '',
            'cdetalle'    => '',
            'ncant'       => $unidades,
            'nunidades'   => $unidades,
            'precioCiva'  => 0,
            'iva'         => 0,
            'nfila'       => 1,
            'estadoLinea' => $opciones['estadoLinea'] ?? 'Activo',
        ]);

        return $idTicket;
    }

    /**
     * Salida por albaran de cliente: resta existencias.
     *
     * @param array{estado?:string,estadoLinea?:string} $opciones
     */
    public function ventaAlbaranCliente(int $idArticulo, float $unidades, string $fecha, array $opciones = []): int
    {
        $numero = ++$this->contador;

        $idAlbaran = $this->insertar('albclit', [
            'Numalbcli' => $numero,
            'Fecha'     => $this->momento($fecha),
            'idTienda'  => $this->tiendaPorDefecto(),
            'idUsuario' => $this->usuarioPorDefecto(),
            'idCliente' => $this->clientePorDefecto(),
            'estado'    => $opciones['estado'] ?? 'Guardado',
            'entregado' => 0,
            'total'     => 0,
        ]);

        $this->insertar('albclilinea', [
            'idalbcli'    => $idAlbaran,
            'Numalbcli'   => $numero,
            'idArticulo'  => $idArticulo,
            'ncant'       => $unidades,
            'nunidades'   => $unidades,
            'estadoLinea' => $opciones['estadoLinea'] ?? 'Activo',
            'precioCiva'  => 0,
            'iva'         => 0,
            'nfila'       => 1,
        ]);

        return $idAlbaran;
    }

    // --- Apoyos -------------------------------------------------------------

    public function tiendaPorDefecto(): int
    {
        return $this->idTienda ??= $this->tienda('2026');
    }

    public function usuarioPorDefecto(): int
    {
        return $this->idUsuario ??= $this->insertar('usuarios', [
            'username' => 'pruebas',
            'password' => 'sin-uso',
            'fecha'    => '2020-01-01',
            'group_id' => 1,
            'estado'   => 'Activo',
            'nombre'   => 'Usuario de pruebas',
        ]);
    }

    public function clientePorDefecto(): int
    {
        return $this->idCliente ??= $this->insertar('clientes', [
            'Nombre' => 'Cliente de pruebas',
            'estado' => 'Activo',
        ]);
    }

    /** Crea un proveedor y devuelve su identificador, para el proveedor de cierre. */
    public function proveedor(string $razonSocial = 'Proveedor de pruebas'): int
    {
        return $this->insertar('proveedores', [
            'razonsocial'  => $razonSocial,
            'nif'          => 'X0000000X',
            'direccion'    => 'Sin direccion',
            'fecha_creado' => '2020-01-01 00:00:00',
            'estado'       => 'Activo',
        ]);
    }

    // --- Interno ------------------------------------------------------------

    /** Admite tanto '2026-01-31' como '2026-01-31 13:45:00'. */
    private function momento(string $fecha): string
    {
        return strlen($fecha) === 10 ? "$fecha 00:00:00" : $fecha;
    }

    private function valorDe(string $tabla, string $campo, int $id): mixed
    {
        $sentencia = $this->db->prepare("SELECT `$campo` FROM `$tabla` WHERE `id` = ?");
        $sentencia->bind_param('i', $id);
        $sentencia->execute();

        return $sentencia->get_result()->fetch_row()[0];
    }

    /** @param array<string,mixed> $valores */
    private function insertar(string $tabla, array $valores): int
    {
        $campos = array_keys($valores);
        $huecos = implode(', ', array_fill(0, count($campos), '?'));
        $lista  = '`' . implode('`, `', $campos) . '`';

        $sentencia = $this->db->prepare("INSERT INTO `$tabla` ($lista) VALUES ($huecos)");
        if ($sentencia === false) {
            throw new RuntimeException("No se pudo preparar la insercion en «{$tabla}»: {$this->db->error}");
        }

        $sentencia->bind_param(str_repeat('s', count($valores)), ...array_values($valores));
        if (!$sentencia->execute()) {
            throw new RuntimeException("Fallo al sembrar en «{$tabla}»: {$sentencia->error}");
        }

        return (int) $this->db->insert_id;
    }
}
