import React, { useEffect, useMemo, useRef, useState } from 'react';
import { Alert, Box, Typography } from '@mui/material';

export type CaptchaChallenge = {
  provider: string;
  site_key: string;
};

type CaptchaWidgetProps = {
  challenge: CaptchaChallenge;
  onVerifying?: () => void;
  onToken: (token: string) => void;
  onExpire?: () => void;
};

declare global {
  interface Window {
    turnstile?: {
      render: (container: HTMLElement, options: Record<string, unknown>) => string;
      remove: (widgetId: string) => void;
    };
  }
}

const loadScriptOnce = (src: string, id: string): Promise<void> => {
  return new Promise((resolve, reject) => {
    const existing = document.getElementById(id) as HTMLScriptElement | null;
    if (existing) {
      if (existing.getAttribute('data-loaded') === '1') {
        resolve();
      } else {
        existing.addEventListener('load', () => resolve(), { once: true });
        existing.addEventListener('error', () => reject(new Error('Failed to load CAPTCHA script')), { once: true });
      }
      return;
    }

    const script = document.createElement('script');
    script.id = id;
    script.src = src;
    script.async = true;
    script.defer = true;
    script.addEventListener('load', () => {
      script.setAttribute('data-loaded', '1');
      resolve();
    });
    script.addEventListener('error', () => reject(new Error('Failed to load CAPTCHA script')));
    document.head.appendChild(script);
  });
};

export const CaptchaWidget: React.FC<CaptchaWidgetProps> = ({ challenge, onVerifying, onToken, onExpire }) => {
  const containerRef = useRef<HTMLDivElement | null>(null);
  const widgetIdRef = useRef<string | null>(null);
  const [error, setError] = useState<string>('');

  const provider = useMemo(() => (challenge?.provider || '').toLowerCase(), [challenge]);

  useEffect(() => {
    setError('');
    widgetIdRef.current = null;

    if (!containerRef.current) return;
    containerRef.current.innerHTML = '';

    if (provider !== 'turnstile') {
      setError('Unsupported CAPTCHA provider.');
      onExpire?.();
      return;
    }

    const siteKey = challenge.site_key;
    if (!siteKey) {
      setError('Missing CAPTCHA site key.');
      onExpire?.();
      return;
    }

    onVerifying?.();

    const scriptUrl = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

    let cancelled = false;

    loadScriptOnce(scriptUrl, 'turnstile-api')
      .then(() => {
        if (cancelled) return;
        if (!window.turnstile || !containerRef.current) {
          setError('CAPTCHA failed to initialize.');
          onExpire?.();
          return;
        }

        widgetIdRef.current = window.turnstile.render(containerRef.current, {
          sitekey: siteKey,
          callback: (token: unknown) => {
            if (typeof token === 'string' && token) {
              onToken(token);
            }
          },
          'expired-callback': () => {
            onExpire?.();
          },
          'error-callback': () => {
            setError('CAPTCHA error. Please try again.');
            onExpire?.();
          },
        });
      })
      .catch((e: unknown) => {
        if (cancelled) return;
        setError(e instanceof Error ? e.message : 'Failed to load CAPTCHA.');
        onExpire?.();
      });

    return () => {
      cancelled = true;
      if (provider === 'turnstile' && window.turnstile && widgetIdRef.current) {
        try {
          window.turnstile.remove(widgetIdRef.current);
        } catch {
          // ignore
        }
      }
    };
  }, [challenge.provider, challenge.site_key, onExpire, onToken, onVerifying, provider]);

  return (
    <Box sx={{ mt: 2 }}>
      <Typography variant="body2" color="text.secondary" sx={{ mb: 1 }}>
        Security check required
      </Typography>

      {error && (
        <Alert severity="error" sx={{ mb: 1 }}>
          {error}
        </Alert>
      )}

      <Box ref={containerRef} />
    </Box>
  );
};
