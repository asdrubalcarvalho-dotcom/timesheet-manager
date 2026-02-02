import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { LoginForm } from '../LoginForm';

const mockLogin = vi.fn();

vi.mock('../AuthContext', () => ({
  useAuth: () => ({
    login: mockLogin,
  }),
}));

// Stub CAPTCHA widget so we can deterministically emit a token without loading Turnstile.
let emitToken: ((token: string) => void) | null = null;
vi.mock('../CaptchaWidget', () => ({
  CaptchaWidget: ({ onToken, onVerifying }: { onToken: (t: string) => void; onVerifying?: () => void }) => {
    emitToken = onToken;
    React.useEffect(() => {
      onVerifying?.();
    }, [onVerifying]);
    return <div data-testid="captcha-widget" />;
  },
}));

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom');
  return {
    ...actual,
    useNavigate: () => vi.fn(),
  };
});

describe('LoginForm (CAPTCHA state machine)', () => {
  beforeEach(() => {
    mockLogin.mockReset();
    emitToken = null;
    localStorage.clear();

    vi.stubGlobal('fetch', vi.fn(async (input: unknown) => {
      const url = String(input);
      if (url.includes('/api/tenants/check-slug')) {
        return new Response(JSON.stringify({ require_sso: false, exists: true, available: false }), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        });
      }
      return new Response(null, { status: 404 });
    }));
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('does not auto-submit when CAPTCHA resolves; user can click Sign In', async () => {
    const user = userEvent.setup();

    // 1) First submit triggers captcha_required.
    mockLogin.mockResolvedValueOnce({
      ok: false,
      captchaRequired: true,
      captcha: { provider: 'turnstile', site_key: 'test-site-key' },
    });

    render(
      <MemoryRouter>
        <LoginForm />
      </MemoryRouter>
    );

    await user.type(screen.getByLabelText(/workspace/i), 'acme');
    await user.type(screen.getByLabelText(/email address/i), 'user@acme.test');
    await user.type(screen.getByLabelText(/password/i), 'secret');

    await user.click(screen.getByRole('button', { name: /^sign in$/i }));

    expect(await screen.findByTestId('captcha-widget')).toBeInTheDocument();
    expect(mockLogin).toHaveBeenCalledTimes(1);

    // 2) CAPTCHA resolves; must NOT auto-submit.
    expect(emitToken).toBeTypeOf('function');
    emitToken?.('token-123');

    // Still only the initial call.
    expect(mockLogin).toHaveBeenCalledTimes(1);

    // Wait for CAPTCHA status to become verified (button enabled again).
    await waitFor(() => {
      expect(screen.getByRole('button', { name: /^sign in$/i })).toBeEnabled();
    });

    // 3) User clicks Sign In again; this time it submits with the token.
    mockLogin.mockResolvedValueOnce({ ok: true });
    await user.click(screen.getByRole('button', { name: /^sign in$/i }));

    expect(mockLogin).toHaveBeenCalledTimes(2);
    expect(mockLogin).toHaveBeenLastCalledWith(
      'user@acme.test',
      'secret',
      'acme',
      'token-123'
    );
  });
});
