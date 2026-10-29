<?php
declare(strict_types=1);

namespace App\Core\Module\Exception\Contracts;

use App\Core\System\Http\Request;
use App\Core\System\Http\Response;
use Throwable;

interface ExceptionHandlerInterface
{
    public function report(Throwable $exception): void;
    public function render(Request $request, Throwable $exception): Response;
}