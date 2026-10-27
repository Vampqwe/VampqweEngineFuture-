<?php
declare(strict_types=1);

namespace App\Core\System\Container\Contracts;

interface ContainerInterface
{
    public function instance(string $abstract, mixed $instance): void;
    public function get(string $abstract): mixed;
    public function has(string $abstract): bool;
}