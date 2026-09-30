<?php

namespace MikroApi;

/**
 * Implementada por clases que necesitan recibir una referencia al
 * Container tras ser resueltas por autowiring, sin acoplar el Container
 * a una capa específica del framework (Repository, Service, etc.).
 *
 * Container::autowire() inyecta automáticamente el container a cualquier
 * instancia que implemente esta interfaz.
 */
interface ContainerAwareInterface
{
    public function setContainer(Container $container): static;
}
