<?php

namespace App\Enums;

enum ShiftType: string
{
    case MORNING = 'MORNING';
    case AFTERNOON = 'AFTERNOON';

    public function getStartTime(): string
    {
        return match ($this) {
            self::MORNING => '07:00',
            self::AFTERNOON => '13:00',
        };
    }

    public function getEndTime(): string
    {
        return match ($this) {
            self::MORNING => '11:00',
            self::AFTERNOON => '17:00',
        };
    }
}
