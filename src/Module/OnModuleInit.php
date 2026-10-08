<?php

namespace MikroApi\Module;

/**
 * Hook de ciclo de vida: se invoca una vez cargados todos los módulos, en
 * orden de dependencias (los módulos importados primero). Aplica a providers
 * de clase/useClass/useValue y a la propia clase del módulo.
 */
interface OnModuleInit
{
    public function onModuleInit(): void;
}
