import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const read = (path: string) => readFileSync(resolve(here, path), 'utf8');

const api = read('api/client.ts');
const page = read('pages/LoginPage.tsx');
const registrationPage = read('pages/RegisterPage.tsx');
const loginController = read('../private_storage/app/Domain/AbhAgentLoginController.php');
const refreshController = read('../private_storage/app/Domain/AbhAgentRefreshController.php');
const authRepository = read('../private_storage/app/Database/AbhAuthRepository.php');
const authLimiter = read('../private_storage/app/Security/AbhAuthRateLimiter.php');
const authenticator = read('../private_storage/app/Security/AbhAuthenticator.php');
const kernel = read('../private_storage/app/Http/Kernel.php');
const signatureVerifier = read('../private_storage/app/Security/SignatureVerifier.php');
const migration = read('../private_storage/abh_migrations/007_agent_sessions_and_device_keys.sql');

describe('ABH agent authentication contract', () => {
  it('uses phone/password plus the registered browser private key', () => {
    expect(page).toContain('Phone number');
    expect(page).toContain('login(phone.trim(), password)');
    expect(page).toContain('same browser used during registration');
    expect(page).not.toMatch(/username|\\bNIN\\b/i);
    expect(api).toContain('/abh/auth/login.php');
    expect(api).toContain("['ABH_LOGIN', phone.trim(), device.device_uuid, String(timestamp), nonce].join('\\n')");
    expect(api).toContain('signPayload(proof, device.private_key)');
  });

  it('stores a non-extractable browser key and proves it during registration', () => {
    expect(registrationPage).toContain('generateAndStoreDeviceIdentity');
    expect(registrationPage).toContain('signPayload');
    expect(registrationPage).toContain('ABH_REGISTRATION');
    expect(registrationPage).toContain('public_key_jwk: device.public_key_jwk');
  });

  it('denies pending/rejected/suspended agents and non-approved devices', () => {
    expect(loginController).toContain("Credentials::verify");
    expect(loginController).toContain("'PENDING'");
    expect(loginController).toContain("'REJECTED'");
    expect(loginController).toContain("'SUSPENDED'");
    expect(loginController).toContain("['device_status'] !== 'ACTIVE'");
    expect(loginController).toContain('SignatureVerifier::assertProofOfPossession');
    expect(loginController).toContain('AbhAuthRepository::consumeNonce');
    expect(authLimiter).toContain('abh_auth_attempts');
  });

  it('uses only ABH session, nonce and account tables for session lifecycle', () => {
    expect(authRepository).toContain('abh_agent_sessions');
    expect(authRepository).toContain('abh_auth_nonces');
    expect(authRepository).toContain('abh_agents');
    expect(authRepository).toContain('abh_agent_credentials');
    expect(authRepository).toContain('abh_agent_devices');
    expect(authRepository).toContain('REUSE_DETECTED');
    expect(authRepository).not.toMatch(/\b(?:FROM|JOIN|INTO|UPDATE)\s+(?:agents|devices|refresh_tokens|login_attempts)\b/i);
    expect(refreshController).toContain('AbhAuthRepository::rotate');
  });

  it('re-authenticates every signed API request against ABH state', () => {
    expect(authenticator).toContain('abh_agent_sessions');
    expect(authenticator).toContain('abh_agents');
    expect(authenticator).toContain('abh_agent_devices');
    expect(authenticator).toContain('SignatureVerifier::verify');
    expect(authenticator).toContain('AbhAuthRepository::consumeNonce');
    expect(kernel).toContain("'abh.auth.login'");
    expect(kernel).toContain("'abh.auth.refresh'");
    expect(kernel).toContain("'abh.auth.logout'");
    expect(kernel).toContain("str_starts_with($route, 'abh.')");
  });

  it('supports raw WebCrypto P-256 signatures and creates isolated session storage', () => {
    expect(signatureVerifier).toContain('self::p1363ToDer($raw)');
    expect(migration).toContain('public_key_jwk JSON');
    expect(migration).toContain('CREATE TABLE abh_agent_sessions');
    expect(migration).toContain('CREATE TABLE abh_auth_nonces');
    expect(migration).toContain('CREATE TABLE abh_auth_attempts');
    expect(migration).not.toMatch(/\b(?:ALTER|CREATE) TABLE (?:agents|devices|refresh_tokens)\b/i);
  });

  it('restores and clears sessions using only ABH refresh/logout routes', () => {
    expect(api).toContain('/abh/auth/refresh.php');
    expect(api).toContain('/abh/auth/logout.php');
    expect(api).not.toContain('${API_BASE}/auth/refresh.php');
    expect(api).not.toContain('${API_BASE}/auth/login.php');
  });
});
