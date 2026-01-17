<?php

declare(strict_types=1);

namespace App\Support;

use App\Tenancy\TenantContext;

final class NumberFormatter
{
    public function __construct(private readonly TenantContext $context)
    {
    }

    public function decimal(float|int $value, int $decimals = 2): string
    {
        if (class_exists(\NumberFormatter::class)) {
            $formatter = new \NumberFormatter($this->context->numberLocale, \NumberFormatter::DECIMAL);
            $formatter->setAttribute(\NumberFormatter::FRACTION_DIGITS, $decimals);
            $formatted = $formatter->format($value);
            if ($formatted !== false) {
                return (string) $formatted;
            }
        }

        return number_format(
            (float) $value,
            $decimals,
            $this->context->decimalSeparator,
            $this->context->thousandsSeparator
        );
    }
}
