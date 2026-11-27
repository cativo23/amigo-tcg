<?php

declare(strict_types=1);

namespace App\Modules\Collection\Services;

use App\Modules\Catalog\Models\Card;

/** One card's 7-day move: its single-copy headline price now and a week earlier, in one currency. */
final readonly class Mover
{
    public function __construct(
        public Card $card,
        public int $currentMinor,
        public int $previousMinor,
        public int $deltaMinor,
        /** The move over the earlier price, in percent and unrounded (-11.25 for -$1.08 on $9.60). */
        public float $percent,
        public string $currency,
    ) {}
}
