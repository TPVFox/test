<?php
/**
 * Tarea de prueba: solo anota que el planificador la ejecuto.
 *
 * No anota su ultima ejecucion en `tareas_cron`, para que se vea lo que el planificador hace
 * por su cuenta y lo que deja a cada tarea.
 */

class TareaQueAnota
{
    private int $idTarea;

    public function __construct(int $idTarea = 0)
    {
        $this->idTarea = $idTarea;
    }

    public function execute(): void
    {
        \TPVFox\Test\RegistroDeTareas::anotar($this->idTarea);
    }
}
