<?php

namespace App\Enums;

enum HogBreed: string
{
    case LargeWhite = 'Large White';
    case Landrace = 'Landrace';
    case Duroc = 'Duroc';
    case Pietrain = 'Pietrain';
    case Berkshire = 'Berkshire';
    case Hampshire = 'Hampshire';
    case Yorkshire = 'Yorkshire';
    case Other = 'Other';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
