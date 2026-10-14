<?php
/**
 * Datos de siembra de las tareas programadas.
 *
 * Lo que sabe de este modulo y no cabe en las primitivas: una tarea es una fila de
 * `tareas_cron` con el nombre de la clase que se ejecuta, cada pasada del planificador
 * deja filas en `diario_cron`, y las tareas de coste escriben `acumulado_compras` y el
 * coste del articulo.
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

    /**
     * Da de baja, dentro de la transaccion del caso, las tareas activas que ya hubiera en la base:
     * el planificador recorre todas las activas, y el caso solo debe ver las suyas.
     */
    public function soloLasDelCaso(): void
    {
        $this->db->query('UPDATE tareas_cron SET estado = 0 WHERE estado = 1');
    }

    /** El identificador de la ultima entrada del diario, o 0 si esta vacio. */
    public function ultimaEntradaDelDiario(): int
    {
        return (int) $this->db->query('SELECT COALESCE(MAX(id), 0) FROM diario_cron')->fetch_row()[0];
    }

    /**
     * Las entradas del diario posteriores a la indicada, en el orden en que se escribieron.
     *
     * @return list<array{descripcion:string,tarea_id:?int}>
     */
    public function diarioDesde(int $idEntrada): array
    {
        $consulta = $this->db->prepare('SELECT descripcion, tarea_id FROM diario_cron WHERE id > ? ORDER BY id');
        $consulta->bind_param('i', $idEntrada);
        $consulta->execute();

        $entradas = [];
        foreach ($consulta->get_result()->fetch_all(MYSQLI_ASSOC) as $fila) {
            $entradas[] = [
                'descripcion' => $fila['descripcion'],
                'tarea_id' => $fila['tarea_id'] === null ? null : (int) $fila['tarea_id'],
            ];
        }

        return $entradas;
    }

    /**
     * Los acumulados de compra del articulo, uno por mes, en orden.
     *
     * @return list<array{year:int,month:int,cantidad:float,costemedio:float}>
     */
    public function acumuladoDe(int $idArticulo): array
    {
        $consulta = $this->db->prepare(
            'SELECT year, month, cantidad, costemedio FROM acumulado_compras WHERE idArticulo = ? ORDER BY year, month'
        );
        $consulta->bind_param('i', $idArticulo);
        $consulta->execute();

        $meses = [];
        foreach ($consulta->get_result()->fetch_all(MYSQLI_ASSOC) as $fila) {
            $meses[] = [
                'year' => (int) $fila['year'],
                'month' => (int) $fila['month'],
                'cantidad' => (float) $fila['cantidad'],
                'costemedio' => (float) $fila['costemedio'],
            ];
        }

        return $meses;
    }

    /** El coste medio que tiene el articulo en su ficha. */
    public function costeMedio(int $idArticulo): float
    {
        return (float) $this->db->query('SELECT costepromedio FROM articulos WHERE idArticulo = ' . $idArticulo)->fetch_row()[0];
    }

    /** El ultimo coste que tiene el articulo en su ficha. */
    public function ultimoCoste(int $idArticulo): float
    {
        return (float) $this->db->query('SELECT ultimoCoste FROM articulos WHERE idArticulo = ' . $idArticulo)->fetch_row()[0];
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
