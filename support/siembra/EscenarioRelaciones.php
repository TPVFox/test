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
}
