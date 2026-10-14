<?php
/**
 * Tarea de prueba: mientras se ejecuta, arranca otra pasada del planificador.
 *
 * Reproduce lo que ocurre cuando el cron lanza una pasada nueva antes de que acabe la anterior:
 * la segunda ve la lista de tareas tal como la dejo la primera a medio recorrer. Solo relanza una
 * vez por caso.
 */

class TareaQueRelanza
{
    private int $idTarea;

    public function __construct(int $idTarea = 0)
    {
        $this->idTarea = $idTarea;
    }

    public function execute(): void
    {
        \TPVFox\Test\RegistroDeTareas::anotar($this->idTarea);

        if (\TPVFox\Test\RegistroDeTareas::puedeRelanzar()) {
            (new \TareasCron(\TPVFox\Test\RegistroDeTareas::raiz()))->execute();
        }
    }
}
