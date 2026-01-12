import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { LoginForm } from '../LoginForm';

const mockLogin = vi.fn();

vi.mock('../AuthContext', () => ({
  useAuth: () => ({
    login: mockLogin,
  }),
}));

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom');
  return {
    ...actual,
    useNavigate: () => vi.fn(),
  };
});

describe('LoginForm (SSO-only UX)', () => {
  beforeEach(() => {
    mockLogin.mockReset();
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

    // jsdom-safe stub
    Object.defineProperty(window, 'location', {
      value: {
        assign: vi.fn(),
      },
      writable: true,
    });
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('switches to SSO-only mode when backend returns ssoOnlyRequired', async () => {
    const user = userEvent.setup();

    mockLogin.mockResolvedValue({ ok: false, ssoOnlyRequired: true });

    render(
      <MemoryRouter>
        <LoginForm />
      </MemoryRouter>
    );

    await user.type(screen.getByLabelText(/tenant/i), 'acme');
    await user.type(screen.getByLabelText(/email address/i), 'user@acme.test');
    await user.type(screen.getByLabelText(/password/i), 'secret');

    await user.click(screen.getByRole('button', { name: /^sign in$/i }));

    // Info banner appears
    expect(
      await screen.findByText(/requires single sign-on/i)
    ).toBeInTheDocument();

    // Password/email inputs are hidden in SSO-only mode
    expect(screen.queryByLabelText(/email address/i)).not.toBeInTheDocument();
    expect(screen.queryByLabelText(/password/i)).not.toBeInTheDocument();

    // Password submit button is hidden
    expect(screen.queryByRole('button', { name: /^sign in$/i })).not.toBeInTheDocument();

    // SSO buttons stay visible
    expect(screen.getByRole('button', { name: /sign in with microsoft/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /sign in with google/i })).toBeInTheDocument();
  });

  it('enables SSO buttons only when tenant is filled', async () => {
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <LoginForm />
      </MemoryRouter>
    );

    const msButton = screen.getByRole('button', { name: /sign in with microsoft/i });
    const googleButton = screen.getByRole('button', { name: /sign in with google/i });

    expect(msButton).toBeDisabled();
    expect(googleButton).toBeDisabled();

    await user.type(screen.getByLabelText(/tenant/i), 'acme');

    expect(msButton).toBeEnabled();
    expect(googleButton).toBeEnabled();
  });
});
