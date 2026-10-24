<?php
/**
 * Tarea de prueba: anota que el planificador la ejecuto y falla con una excepcion, como fallaria
 * una tarea real ante un error de la base.
 */

class TareaQueFalla
{
    private int $idTarea;

    public function __construct(int $idTarea = 0)
    {
        $this->idTarea = $idTarea;
    }

    public function execute(): void
    {
        \TPVFox\Test\RegistroDeTareas::anotar($this->idTarea);

        throw new \RuntimeException('Fallo provocado por la tarea de prueba');
    }
}
