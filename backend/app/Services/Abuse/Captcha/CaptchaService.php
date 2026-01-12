<?php

namespace App\Services\Abuse\Captcha;

interface CaptchaService
{
    public function provider(): string;

    public function siteKey(): ?string;

    public function verifyToken(string $token, string $ip): bool;
}
