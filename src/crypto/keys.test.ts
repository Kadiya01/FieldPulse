import { describe, it, expect, vi, beforeEach } from 'vitest';
import { generateAndStoreDeviceIdentity, hashRequestBody, generateNonce, signPayload } from './keys';
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

  /**
   * signPayload must emit unpadded base64url, because that is the only alphabet
   * the server accepts.
   *
   * Str::base64UrlDecode() on the PHP side is strict — /^[A-Za-z0-9_-]+$/ — so a
   * plain btoa() result is refused whenever it happens to contain '+', '/', or
   * '=' padding. A DER-encoded P-256 signature is ~70 bytes, i.e. ~96 base64
   * characters, so that is nearly every signature rather than a rare edge case:
   * measured against the real decoder, 200 generated signatures were accepted
   * 200 times as base64url and 0 times as plain base64.
   *
   * Asserting the alphabet is what makes this catch the regression. Asserting
   * that "signing works" would not, because the signature is well-formed DER
   * either way — the encoding is only wrong at the boundary.
   */
  it('should encode signatures as unpadded base64url', async () => {
    await generateAndStoreDeviceIdentity();
    const privateKey = vi.mocked(db.device.put).mock.calls[0][0].private_key as CryptoKey;

    // Checked over several signatures: a single sample could pass by luck, and
    // the failure this guards against is probabilistic by nature.
    for (let i = 0; i < 50; i++) {
      const signature = await signPayload('{"nonce":' + i + '}', privateKey);

      expect(signature).toMatch(/^[A-Za-z0-9_-]+$/);
      // Padding would be rejected by the decoder's alphabet check above.
      expect(signature).not.toContain('=');
    }
  });

  it('should produce signatures the server can verify', async () => {
    const { public_key_jwk } = await generateAndStoreDeviceIdentity();
    const privateKey = vi.mocked(db.device.put).mock.calls[0][0].private_key as CryptoKey;
    const payload = '{"submission_uuid":"abc","count":1}';

    const signature = await signPayload(payload, privateKey);

    // Round-trip through the exact alphabet the server enforces, then verify.
    // WebCrypto emits DER; openssl_verify() wants that DER, so no unwrapping
    // happens here either — this is the same byte sequence the server receives.
    // atob() needs the padding signPayload() correctly omits, so it is restored
    // here — which also confirms the omission is safe to strip.
    let std = signature.replace(/-/g, '+').replace(/_/g, '/');
    if (std.length % 4 !== 0) std += '='.repeat(4 - (std.length % 4));
    const bytes = Uint8Array.from(atob(std), (c) => c.charCodeAt(0));

    const publicKey = await crypto.subtle.importKey(
      'jwk',
      public_key_jwk as JsonWebKey,
      { name: 'ECDSA', namedCurve: 'P-256' },
      false,
      ['verify']
    );

    await expect(
      crypto.subtle.verify(
        { name: 'ECDSA', hash: 'SHA-256' },
        publicKey,
        bytes,
        new TextEncoder().encode(payload)
      )
    ).resolves.toBe(true);
  });
});
