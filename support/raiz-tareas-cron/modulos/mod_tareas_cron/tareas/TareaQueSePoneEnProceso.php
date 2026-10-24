<?php
/**
 * Tarea de prueba: se pone en proceso al empezar y vuelve a activa al acabar, anotando su
 * ejecucion, como la tarea real del coste medio. Mientras se ejecuta arranca otra pasada del
 * planificador, como cuando el cron lanza una antes de que acabe la anterior; solo una vez por caso.
 *
 * Lleva la cuenta de cuantas ejecuciones de su clase hay en curso a la vez.
 */

class TareaQueSePoneEnProceso
{
    private int $idTarea;

    private MTareasCron $tarea;

    public function __construct(int $idTarea = 0)
    {
        $this->idTarea = $idTarea;
        $this->tarea = new MTareasCron();
        $this->tarea->find($idTarea);
    }

    public function execute(): void
    {
        $this->tarea->updateEstado(MTareasCron::ESTADO_EN_PROCESO);
        \TPVFox\Test\RegistroDeTareas::empezar(self::class);
        \TPVFox\Test\RegistroDeTareas::anotar($this->idTarea);

        if (\TPVFox\Test\RegistroDeTareas::puedeRelanzar()) {
            (new \TareasCron(\TPVFox\Test\RegistroDeTareas::raiz()))->execute();
        }

        \TPVFox\Test\RegistroDeTareas::terminar(self::class);
        $this->tarea->updateFechaEjecucion($this->idTarea);
    }
}
