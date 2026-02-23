<?php
// core/Repository/RepositoryInterface.php

namespace MikroApi\Repository;

interface RepositoryInterface
{
    public function findAll(): array;
    public function findById(int $id): ?array;
    public function create(array $data): array;
    public function update(int $id, array $data): ?array;
    public function delete(int $id): bool;
}
