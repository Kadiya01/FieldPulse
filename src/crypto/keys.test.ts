import { describe, it, expect, vi, beforeEach } from 'vitest';
import { generateAndStoreDeviceIdentity, hashRequestBody, generateNonce } from './keys';
import { db } from '../db/db';

// Mock IndexedDB
vi.mock('../db/db', () => ({
  db: {
    device: {
      put: vi.fn(),
      get: vi.fn()
    }
  }
}));

describe('Cryptography & Identity', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('should generate a valid v4 UUID and CryptoKey', async () => {
    const { device_uuid, public_key_jwk } = await generateAndStoreDeviceIdentity();

    // Check UUID format (v4)
    expect(device_uuid).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i);

    // Check JWK
    expect(public_key_jwk.kty).toBe('EC');
    expect(public_key_jwk.crv).toBe('P-256');

    // Check that it tried to store in IndexedDB
    expect(db.device.put).toHaveBeenCalledWith(expect.objectContaining({
      id: 'current',
      device_uuid: device_uuid
    }));
  });

  /**
   * The public JWK is stored alongside the private key so registration can send
   * the exact key the server validated, without re-deriving it. If it were left
   * out of the stored record, registerDevice() would send `undefined` and the
   * server would reject the registration with a 422 naming the field — far from
   * the missing property.
   */
  it('should persist the public JWK next to the non-extractable private key', async () => {
    const { device_uuid, public_key_jwk } = await generateAndStoreDeviceIdentity();

    const stored = vi.mocked(db.device.put).mock.calls[0][0];

    expect(stored.public_key_jwk).toEqual(public_key_jwk);
    expect(stored.private_key).toBeInstanceOf(CryptoKey);
    expect(stored.device_uuid).toBe(device_uuid);
  });

  /**
   * The private key must be non-extractable, and the test is here rather than in
   * a comment because `extractable: false` is invisible at the type level: a
   * regression that flipped it to true would still type-check, still sign, and
   * would only matter on the day something managed to read the handle.
   *
   * Export is expected to *reject*, not to throw synchronously — Web Crypto
   * returns a promise, so it is awaited and the rejection asserted.
   */
  it('should generate a non-extractable private key', async () => {
    await generateAndStoreDeviceIdentity();

    const stored = vi.mocked(db.device.put).mock.calls[0][0];
    const privateKey = stored.private_key as CryptoKey;

    expect(privateKey.extractable).toBe(false);
    await expect(crypto.subtle.exportKey('pkcs8', privateKey)).rejects.toBeDefined();
  });

  it('should generate consistent hashes for identical bodies', async () => {
    const body = '{"agent":"test","count":5}';
    const hash1 = await hashRequestBody(body);
    const hash2 = await hashRequestBody(body);
    
    expect(hash1).toBe(hash2);
    expect(hash1.length).toBe(64); // SHA-256 hex string is 64 chars
  });

  it('should generate unique nonces', () => {
    const nonce1 = generateNonce();
    const nonce2 = generateNonce();
    expect(nonce1).not.toBe(nonce2);
    expect(nonce1.length).toBe(32); // 16 bytes = 32 hex chars
  });
});
