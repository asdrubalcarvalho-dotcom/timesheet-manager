<?php

declare(strict_types=1);

namespace App\Support;

use App\Tenancy\TenantContext;

final class MoneyFormatter
{
    public function __construct(private readonly TenantContext $context)
    {
    }

    public function format(float|int $amount, ?string $currency = null): string
    {
        $currency ??= $this->context->currency;

        if (class_exists(\NumberFormatter::class)) {
            $formatter = new \NumberFormatter($this->context->numberLocale, \NumberFormatter::CURRENCY);
            $formatted = $formatter->formatCurrency((float) $amount, $currency);
            if ($formatted !== false) {
                return (string) $formatted;
            }
        }

        return $currency . ' ' . number_format((float) $amount, 2, '.', ',');
    }
}
