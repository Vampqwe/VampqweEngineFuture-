<?php
declare(strict_types=1);

namespace App\Core\Module\Doctor\Contracts;

/**
 * Контракт модуля Doctor — самодиагностика движка (п.9 Конституции).
 * Проверяет наличие и здоровье всех модулей, выдаёт понятный вердикт.
 */
interface DoctorInterface
{
    /**
     * Прогнать все проверки.
     *
     * @return array<int,array{name:string,ok:bool,message:string}>
     */
    public function check(): array;

    /** Все ли проверки пройдены? */
    public function isHealthy(): bool;

    /** Человекочитаемый вердикт (для CLI php bin/engine doctor). */
    public function verdict(): string;
}
