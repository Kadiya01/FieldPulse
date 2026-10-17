import { ApiError } from '../api/client';

export function loginErrorMessage(error: unknown): string {
  if (!(error instanceof ApiError)) {
    return 'Could not reach the service. Check your connection and try again.';
  }

  if (error.status === 429) {
    return 'Too many attempts. Wait a few minutes and try again.';
  }

  if (error.status === 401) {
    return 'Sign in failed. Check your username and password.';
  }

  return error.message;
}
