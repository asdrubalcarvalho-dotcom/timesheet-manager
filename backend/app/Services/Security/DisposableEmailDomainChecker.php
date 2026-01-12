<?php

namespace App\Services\Security;

class DisposableEmailDomainChecker
{
    /**
     * Minimal curated list of common disposable email domains.
     *
     * Policy: docs/EMAIL_POLICY.md
     * Note: Keep additive and configurable later if needed.
     */
    private const DISPOSABLE_DOMAINS = [
        '10minutemail.com',
        '10minutemail.net',
        'guerrillamail.com',
        'guerrillamail.net',
        'mailinator.com',
        'mailinator.net',
        'tempmail.com',
        'temp-mail.org',
        'yopmail.com',
        'yopmail.fr',
        'yopmail.net',
        'trashmail.com',
        'trashmail.net',
        'dispostable.com',
        'getnada.com',
        'fakeinbox.com',
        'sharklasers.com',
        'spamgourmet.com',
        'spamgourmet.net',
        'maildrop.cc',
        'mintemail.com',
        'throwawaymail.com',
    ];

    public function isDisposable(?string $domain): bool
    {
        if (!$domain) {
            return false;
        }

        $normalized = strtolower(trim($domain));

        return in_array($normalized, self::DISPOSABLE_DOMAINS, true);
    }
}
