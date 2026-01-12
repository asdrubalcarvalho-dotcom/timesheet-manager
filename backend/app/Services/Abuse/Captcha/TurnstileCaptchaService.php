<?php

namespace App\Services\Abuse\Captcha;

use Illuminate\Support\Facades\Http;

class TurnstileCaptchaService implements CaptchaService
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function provider(): string
    {
        return 'turnstile';
    }

    public function siteKey(): ?string
    {
        $key = (string) config('captcha.site_key');

        return $key !== '' ? $key : null;
    }

    public function verifyToken(string $token, string $ip): bool
    {
        $secret = (string) config('captcha.secret');
        if ($secret === '' || $token === '') {
            return false;
        }

        $response = Http::asForm()->post(self::VERIFY_URL, [
            'secret' => $secret,
            'response' => $token,
            'remoteip' => $ip,
        ]);

        if (! $response->ok()) {
            return false;
        }

        $json = $response->json();

        return (bool) ($json['success'] ?? false);
    }
}
