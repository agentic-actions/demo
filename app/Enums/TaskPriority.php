<?php

namespace App\Enums;

enum TaskPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';

    /**
     * Get the display label for the priority.
     */
    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * The backing values, lowest first.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
