<?php

namespace MikroApi\Module;

/**
 * Hook de ciclo de vida: se invoca en App::close() (que App::run() llama
 * tras enviar la respuesta), en orden inverso al de inicialización. Solo
 * para providers ya instanciados (no fuerza la creación de ninguno).
 */
interface OnApplicationShutdown
{
    public function onApplicationShutdown(): void;
}
