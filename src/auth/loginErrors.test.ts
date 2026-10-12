import { describe, expect, it } from 'vitest';
import { ApiError } from '../api/client';
import { loginErrorMessage } from './loginErrors';

describe('login error messages', () => {
  it('keeps credential failures indistinguishable', () => {
    expect(loginErrorMessage(new ApiError(401, 'UNAUTHENTICATED', 'Authentication failed.', null)))
      .toBe('Sign in failed. Check your username and password.');
  });

  it('explains rate limiting', () => {
    expect(loginErrorMessage(new ApiError(429, 'RATE_LIMITED', 'Too many attempts.', null)))
      .toBe('Too many attempts. Wait a few minutes and try again.');
  });

  it('shows safe server errors such as an HTTPS requirement', () => {
    expect(loginErrorMessage(new ApiError(403, 'FORBIDDEN', 'HTTPS is required.', null)))
      .toBe('HTTPS is required.');
  });

  it('distinguishes a connection failure from rejected credentials', () => {
    expect(loginErrorMessage(new TypeError('Failed to fetch')))
      .toBe('Could not reach FieldPulse. Check your connection and try again.');
  });
});
