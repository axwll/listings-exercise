<?php

namespace App\Enums;

enum Tenure: string
{
    case Freehold = 'freehold';
    case Leasehold = 'leasehold';
    case ShareOfFreehold = 'share_of_freehold';

    /**
     * Value/label pairs for building a select. Keeps the option list in one
     * place rather than duplicated in the front end.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $tenure) => ['value' => $tenure->value, 'label' => $tenure->label()],
            self::cases(),
        );
    }

    public function label(): string
    {
        return match ($this) {
            self::Freehold => 'Freehold',
            self::Leasehold => 'Leasehold',
            self::ShareOfFreehold => 'Share of freehold',
        };
    }
}
