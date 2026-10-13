<?php

/**
 * Una sentencia preparada que, ademas de ejecutarse, anota lo que hizo.
 *
 * La conexion observada ve entera una consulta directa, pero de una sentencia preparada
 * solo veia la plantilla: lo que afecta o devuelve se sabe al ejecutarla, y eso ocurre en
 * este objeto y no en la conexion. Mientras el producto consultaba siempre con `query()`
 * no hacia falta; el codigo nuevo consulta con sentencias preparadas, y sin esto el
 * informe dejaria de poder contar que hizo el dato justo donde el producto lo hace mejor.
 *
 * Solo la recibe el producto. La siembra sigue con la sentencia de siempre: de ella al
 * informe le interesa que metodo se llamo, no cada fila que escribio.
 */

declare(strict_types=1);

namespace TPVFox\Test\Instrumentacion;

use mysqli_result;
use mysqli_stmt;
use Throwable;

final class SentenciaObservada extends mysqli_stmt
{
    private int $inicio = 0;

    public function __construct(private ConexionObservada $conexion, private string $sql)
    {
        parent::__construct($conexion, $sql);
    }

    public function execute(?array $params = null): bool
    {
        $this->inicio = hrtime(true);

        try {
            $hecho = parent::execute($params);
        } catch (Throwable $e) {
            $this->conexion->anotarEjecucion($this->sql, $this->inicio, null, null, null, $e->getMessage());

            throw $e;
        }

        // Una sentencia que no devuelve filas ya ha dicho todo lo que tiene que decir. La
        // que las devuelve se anota al recoger el resultado, que es cuando se sabe cuantas.
        if ($this->field_count === 0) {
            $this->conexion->anotarEjecucion($this->sql, $this->inicio, (int) $this->affected_rows, (int) $this->insert_id, null, null);
        }

        return $hecho;
    }

    public function get_result(): mysqli_result|false
    {
        $resultado = parent::get_result();

        if ($resultado instanceof mysqli_result) {
            $this->conexion->anotarEjecucion($this->sql, $this->inicio, null, null, $resultado->num_rows, null);
        }

        return $resultado;
    }
}
