<?php

namespace App\Services\Abuse\Captcha;

use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CaptchaGate
{
    /**
     * IMPORTANT: When tenancy is initialized, Stancl replaces the cache manager with a tagged
     * implementation (CacheTenancyBootstrapper). The default test cache store is "array" which
     * does not support tags, so using Cache::get/put/increment (which are forwarded via __call)
     * can throw "This cache store does not support tagging.".
     *
     * By calling Cache::store() directly we bypass the tag wrapper and keep our own key scoping.
     */
    private function cache()
    {
        return Cache::store();
    }

    public function requiresCaptcha(Request $request, string $context, ?string $email = null, ?string $tenantSlug = null): bool
    {
        if (! (bool) config('captcha.enabled', false)) {
            return false;
        }

        $mode = (string) config('captcha.mode', 'adaptive');
        if ($mode === 'off') {
            return false;
        }

        if ($mode === 'always') {
            return true;
        }

        // adaptive
        if ($context === 'login') {
            return $this->loginRequiresCaptcha($request, $email, $tenantSlug);
        }

        // register/signup contexts
        return $this->registerRequiresCaptcha($request, $context, $email, $tenantSlug);
    }

    public function assertCaptchaIfRequired(Request $request, string $context, ?string $email = null, ?string $tenantSlug = null, ?string $reason = null): void
    {
        if (! $this->requiresCaptcha($request, $context, $email, $tenantSlug)) {
            return;
        }

        $token = (string) $request->input('captcha_token', '');
        if ($token === '') {
            $this->logRequired($request, $email, $tenantSlug, $reason ?? 'captcha_missing');
            $this->reject();
        }

        $service = $this->resolveService();

        $ok = $service->verifyToken($token, (string) $request->ip());
        if (! $ok) {
            $this->logFailed($request, $email, $tenantSlug, $service->provider(), $reason ?? 'captcha_invalid');
            $this->reject();
        }
    }

    public function recordRegisterAttempt(Request $request, string $context, ?string $email = null, ?string $tenantSlug = null): void
    {
        if (! (bool) config('captcha.enabled', false)) {
            return;
        }

        $mode = (string) config('captcha.mode', 'adaptive');
        if ($mode !== 'adaptive') {
            return;
        }

        $ip = (string) $request->ip();
        $emailDomain = $this->extractDomain($email);

        $key = $this->registerAttemptKey($context, $ip, $emailDomain, $tenantSlug);
        $ttl = (int) config('captcha.register.ip_window_seconds', 600);

        $cache = $this->cache();

        if (! $cache->has($key)) {
            $cache->put($key, 1, $ttl);
            return;
        }

        $count = (int) $cache->increment($key);
        $cache->put($key, $count, $ttl);
    }

    public function getLoginFailureCount(Request $request, ?string $email, ?string $tenantSlug): int
    {
        $key = $this->loginFailureKey($request, $email, $tenantSlug);

        return (int) $this->cache()->get($key, 0);
    }

    public function incrementLoginFailure(Request $request, ?string $email, ?string $tenantSlug): int
    {
        $key = $this->loginFailureKey($request, $email, $tenantSlug);
        $ttl = (int) config('captcha.login.failure_window_seconds', 600);

        $cache = $this->cache();

        if (! $cache->has($key)) {
            $cache->put($key, 1, $ttl);
            return 1;
        }

        $count = (int) $cache->increment($key);
        $cache->put($key, $count, $ttl);

        return $count;
    }

    public function clearLoginFailures(Request $request, ?string $email, ?string $tenantSlug): void
    {
        $this->cache()->forget($this->loginFailureKey($request, $email, $tenantSlug));
    }

    private function registerRequiresCaptcha(Request $request, string $context, ?string $email, ?string $tenantSlug): bool
    {
        $emailDomain = $this->extractDomain($email);

        if ($emailDomain !== '' && $this->isRiskDomain($emailDomain)) {
            return true;
        }

        if (! $this->hasBrowserContext($request)) {
            return true;
        }

        $ip = (string) $request->ip();
        $threshold = (int) config('captcha.register.ip_threshold', 3);
        $key = $this->registerAttemptKey($context, $ip, $emailDomain, $tenantSlug);

        $count = (int) $this->cache()->get($key, 0);

        return $count >= $threshold;
    }

    private function loginRequiresCaptcha(Request $request, ?string $email, ?string $tenantSlug): bool
    {
        $threshold = (int) config('captcha.login.failure_threshold', 3);
        $failures = $this->getLoginFailureCount($request, $email, $tenantSlug);

        return $failures >= $threshold;
    }

    private function resolveService(): CaptchaService
    {
        $provider = strtolower((string) config('captcha.provider', 'turnstile'));

        if ($provider === 'turnstile') {
            return app(TurnstileCaptchaService::class);
        }

        // Default to turnstile for now.
        return app(TurnstileCaptchaService::class);
    }

    private function reject(): void
    {
        $service = $this->resolveService();

        throw new HttpResponseException(
            response()->json([
                'message' => 'Please complete the security check.',
                'code' => 'captcha_required',
                'captcha' => [
                    'provider' => $service->provider(),
                    'site_key' => $service->siteKey(),
                ],
            ], 422)
        );
    }

    private function extractDomain(?string $email): string
    {
        $email = strtolower(trim((string) $email));
        if ($email === '' || ! str_contains($email, '@')) {
            return '';
        }

        [, $domain] = explode('@', $email, 2);
        $domain = strtolower(trim($domain));
        $domain = rtrim($domain, '.');

        return $domain;
    }

    private function isRiskDomain(string $domain): bool
    {
        $domain = strtolower(trim($domain));
        $risk = (array) config('captcha.risk_domains', []);
        $risk = array_map(static fn ($value) => strtolower(trim((string) $value)), $risk);

        return $domain !== '' && in_array($domain, $risk, true);
    }

    private function hasBrowserContext(Request $request): bool
    {
        $userAgent = trim((string) $request->userAgent());
        $acceptLanguage = trim((string) $request->header('Accept-Language'));
        $browserMarker = trim((string) $request->header('X-Browser'));

        // Require at least some browser signal; keep conservative to avoid false positives.
        return $userAgent !== '' || $acceptLanguage !== '' || $browserMarker !== '';
    }

    private function registerAttemptKey(string $context, string $ip, string $emailDomain, ?string $tenantSlug): string
    {
        $tenantSlug = strtolower(trim((string) $tenantSlug));
        $emailDomain = strtolower(trim($emailDomain));

        return 'captcha:register:' . $context . ':' . $ip . ':' . ($tenantSlug !== '' ? $tenantSlug : '-') . ':' . ($emailDomain !== '' ? $emailDomain : '-');
    }

    private function loginFailureKey(Request $request, ?string $email, ?string $tenantSlug): string
    {
        $ip = (string) $request->ip();
        $tenantSlug = strtolower(trim((string) $tenantSlug));
        $emailDomain = $this->extractDomain($email);
        $emailHash = $email ? sha1(strtolower(trim((string) $email))) : '-';

        return 'captcha:login_fail:' . $ip . ':' . ($tenantSlug !== '' ? $tenantSlug : '-') . ':' . ($emailDomain !== '' ? $emailDomain : '-') . ':' . $emailHash;
    }

    private function logRequired(Request $request, ?string $email, ?string $tenantSlug, string $reason): void
    {
        Log::info('abuse.captcha_required', $this->safeLogContext($request, $email, $tenantSlug, $reason, required: true));
    }

    private function logFailed(Request $request, ?string $email, ?string $tenantSlug, string $provider, string $reason): void
    {
        $context = $this->safeLogContext($request, $email, $tenantSlug, $reason, required: true);
        $context['provider'] = $provider;

        Log::warning('abuse.captcha_failed', $context);
    }

    private function safeLogContext(Request $request, ?string $email, ?string $tenantSlug, string $reason, bool $required): array
    {
        return array_filter([
            'email_domain' => $this->extractDomain($email) ?: null,
            'tenant' => $tenantSlug ? trim((string) $tenantSlug) : null,
            'ip' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'route' => $request->method() . ' ' . $request->path(),
            'reason' => $reason,
            'captcha_required' => $required,
            'provider' => (string) config('captcha.provider', ''),
        ], static fn ($value) => $value !== null && $value !== '');
    }
}
