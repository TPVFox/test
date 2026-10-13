<?php
/**
 * Datos de siembra de las relaciones entre productos.
 *
 * Lo que sabe de este modulo y no cabe en las primitivas: un tipo de relacion es
 * vocabulario de cada tienda, de modo que cada caso siembra los que necesita.
 */

declare(strict_types=1);

namespace TPVFox\Test\Siembra;

use mysqli;

final class EscenarioRelaciones
{
    public function __construct(private mysqli $db)
    {
    }

    /** Un tipo de relacion, con su forma y con si sus lineas llevan cantidad. */
    public function tipoDeRelacion(string $nombre, string $forma, int $llevaCantidad = 1, string $estado = 'Activo'): int
    {
        $alta = $this->db->prepare('INSERT INTO relacionesTipos (nombre, forma, llevaCantidad, estado) VALUES (?, ?, ?, ?)');
        $alta->bind_param('ssis', $nombre, $forma, $llevaCantidad, $estado);
        $alta->execute();

        return (int) $this->db->insert_id;
    }

    /**
     * Una relacion que ya rige, con su version 1.0 y sus lineas, tal como la deja una
     * activacion. Se siembra a mano para los casos que parten de una relacion vigente.
     *
     * @param list<array{idArticulo:int,sentido:string,cantidad?:float|null,unidad?:string|null}> $lineas
     * @return array{id:int,numRelacion:int}
     */
    public function relacionVigente(int $idTipo, array $lineas, array $opciones = []): array
    {
        $numRelacion = (int) $this->db->query('SELECT COALESCE(MAX(numRelacion), 0) + 1 AS n FROM relaciones')->fetch_assoc()['n'];
        $nombre = $opciones['nombre'] ?? null;
        $idUsuario = $opciones['idUsuario'] ?? $this->usuario();
        $fecha = $opciones['fecha'] ?? date('Y-m-d H:i:s');

        $alta = $this->db->prepare(
            "INSERT INTO relaciones (numRelacion, idTipo, nombre, version, tipoCambio, idUsuario, fechaCreacion, estado)
             VALUES (?, ?, ?, '1.0', 'alta', ?, ?, 'Activo')"
        );
        $alta->bind_param('iisis', $numRelacion, $idTipo, $nombre, $idUsuario, $fecha);
        $alta->execute();
        $id = (int) $this->db->insert_id;

        foreach ($lineas as $linea) {
            $cantidad = array_key_exists('cantidad', $linea) ? $linea['cantidad'] : 1;
            $unidad = array_key_exists('unidad', $linea) ? $linea['unidad'] : 'kg';
            $fila = $this->db->prepare('INSERT INTO relacionesLineas (idRelacion, idArticulo, sentido, cantidad, unidad) VALUES (?, ?, ?, ?, ?)');
            $fila->bind_param('iisss', $id, $linea['idArticulo'], $linea['sentido'], $cantidad, $unidad);
            $fila->execute();
        }

        return ['id' => $id, 'numRelacion' => $numRelacion];
    }

    /** Un usuario que ya existe en la base: las relaciones no admiten uno inventado. */
    public function usuario(): int
    {
        return (int) $this->db->query('SELECT id FROM usuarios ORDER BY id LIMIT 1')->fetch_assoc()['id'];
    }

    /**
     * Deja vacias las tablas del modulo y retira los articulos que el caso sembro.
     *
     * La necesitan los casos que no pueden aislarse deshaciendo, porque lo que prueban es
     * que el propio modulo abre y cierra su transaccion.
     *
     * @param list<int> $articulos
     */
    public function limpiar(array $articulos = []): void
    {
        $this->db->query('DELETE FROM relacionesLineas');
        $this->db->query('DELETE FROM relaciones');
        $this->db->query('DELETE FROM relacionesTipos');
        foreach ($articulos as $idArticulo) {
            $this->db->query('DELETE FROM articulos WHERE idArticulo = ' . (int) $idArticulo);
        }
    }
}
