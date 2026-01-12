<?php

namespace App\Services\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AbuseGate
{
    public function shouldRequireCaptcha(Request $request, array $signals): bool
    {
        return (bool) ($signals['disposable_email_domain'] ?? false)
            || (bool) ($signals['high_attempt_rate'] ?? false)
            || (bool) ($signals['no_js'] ?? false);
    }

    public function recordSignal(Request $request, array $signals): void
    {
        if (!$this->shouldRequireCaptcha($request, $signals)) {
            return;
        }

        $context = array_filter([
            'email_domain' => $signals['email_domain'] ?? null,
            'ip' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'tenant_slug' => $signals['tenant_slug'] ?? null,
            'provider' => $signals['provider'] ?? null,
            'reason' => $signals['reason'] ?? 'captcha_suggested',
            'endpoint' => $request->method() . ' ' . $request->path(),
            'request_id' => $this->getRequestId($request),
        ], static fn ($value) => $value !== null && $value !== '');

        Log::info('captcha.suggested', $context);
    }

    private function getRequestId(Request $request): ?string
    {
        $id = $request->header('X-Request-Id') ?? $request->header('X-Request-ID');
        $id = is_string($id) ? trim($id) : '';

        return $id !== '' ? $id : null;
    }
}
