<?php
/**
 * Datos de siembra de las tareas programadas.
 *
 * Lo que sabe de este modulo y no cabe en las primitivas: una tarea es una fila de
 * `tareas_cron` con el nombre de la clase que se ejecuta, y cada pasada del planificador
 * deja filas en `diario_cron`.
 */

declare(strict_types=1);

namespace TPVFox\Test\Siembra;

use mysqli;

final class EscenarioTareasCron
{
    public function __construct(private mysqli $db)
    {
    }

    /**
     * Una tarea programada, tal como la deja el formulario de la pantalla.
     *
     * Por omision: cada 60 minutos, desde hoy, nunca ejecutada y activa.
     *
     * @param array{cantidad?:int,tipo?:int,inicio?:string,ultima?:?string,estado?:int} $opciones
     */
    public function tarea(string $nombre, string $clase, array $opciones = []): int
    {
        $cantidad = $opciones['cantidad'] ?? 60;
        $tipo = $opciones['tipo'] ?? 1;
        $inicio = $opciones['inicio'] ?? date('Y-m-d');
        $ultima = $opciones['ultima'] ?? null;
        $estado = $opciones['estado'] ?? 1;

        $alta = $this->db->prepare(
            'INSERT INTO tareas_cron (nombre, cantidad_periodo, tipo_periodo, nombre_clase, inicio_ejecucion, ultima_ejecucion, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $alta->bind_param('siisssi', $nombre, $cantidad, $tipo, $clase, $inicio, $ultima, $estado);
        $alta->execute();

        return (int) $this->db->insert_id;
    }

    /** Una entrada del diario de ejecuciones, atada a una tarea. */
    public function entradaDeDiario(int $idTarea, string $descripcion = 'ejecutar'): int
    {
        $alta = $this->db->prepare('INSERT INTO diario_cron (descripcion, tarea_id) VALUES (?, ?)');
        $alta->bind_param('si', $descripcion, $idTarea);
        $alta->execute();

        return (int) $this->db->insert_id;
    }

    /** La fila de una tarea tal como esta en la base, o null si no existe. */
    public function filaDeTarea(int $idTarea): ?array
    {
        $fila = $this->db->query('SELECT * FROM tareas_cron WHERE id = ' . $idTarea)->fetch_assoc();

        return $fila ?: null;
    }

    /** Cuantas tareas hay con ese nombre. */
    public function cuantasSeLlaman(string $nombre): int
    {
        $consulta = $this->db->prepare('SELECT COUNT(*) FROM tareas_cron WHERE nombre = ?');
        $consulta->bind_param('s', $nombre);
        $consulta->execute();

        return (int) $consulta->get_result()->fetch_row()[0];
    }
}
