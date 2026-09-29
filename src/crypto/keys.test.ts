import { describe, it, expect, vi, beforeEach } from 'vitest';
import { generateAndStoreDeviceIdentity, signPayload, hashRequestBody, generateNonce } from './keys';
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
    const { device_uuid, public_jwk } = await generateAndStoreDeviceIdentity();
    
    // Check UUID format (v4)
    expect(device_uuid).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i);
    
    // Check JWK
    expect(public_jwk.kty).toBe('EC');
    expect(public_jwk.crv).toBe('P-256');
    
    // Check that it tried to store in IndexedDB
    expect(db.device.put).toHaveBeenCalledWith(expect.objectContaining({
      id: 'current',
      device_uuid: device_uuid
    }));
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
