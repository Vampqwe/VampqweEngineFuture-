<?php
declare(strict_types=1);

namespace App\Core\System\Enums;

enum AppState: string
{
    case NotInstalled = 'not_installed';
    case Installing = 'installing';
    case Maintenance = 'maintenance';
    case Upgrading = 'upgrading';
    case Running = 'running';
    case Error = 'error';
}