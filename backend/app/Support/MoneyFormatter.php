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

        $symbol = $currency === $this->context->currency
            ? $this->context->currencySymbol
            : $currency;

        $formattedNumber = number_format(
            (float) $amount,
            2,
            $this->context->decimalSeparator,
            $this->context->thousandsSeparator
        );

        // Match expected spacing for EU example ("€ 1.234,56") and US example ("$1,234.56").
        $space = $this->context->decimalSeparator === ',' ? ' ' : '';

        return $symbol . $space . $formattedNumber;
    }
}
