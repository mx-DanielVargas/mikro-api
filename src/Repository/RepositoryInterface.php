<?php

namespace MikroApi\Repository;

interface RepositoryInterface
{
    public function findAll(): array;
    public function findById(mixed $id): ?array;
    public function create(array $data): array;
    public function update(mixed $id, array $data): ?array;
    public function delete(mixed $id): bool;
}
