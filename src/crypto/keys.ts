import { db } from '../db/db';

const ALGO_PARAMS: EcdsaParams = {
  name: 'ECDSA',
  hash: 'SHA-256',
};

const KEY_GEN_PARAMS: EcKeyGenParams = {
  name: 'ECDSA',
  namedCurve: 'P-256',
};

export async function generateAndStoreDeviceIdentity(): Promise<{ device_uuid: string; public_key_jwk: JsonWebKey }> {
  // Generate a cryptographically random installation UUID (v4).
  const device_uuid = crypto.randomUUID();

  // Generate an ECDSA P-256 key pair with the Web Crypto API.
  // The private key MUST be generated as non-extractable.
  const keyPair = await crypto.subtle.generateKey(
    KEY_GEN_PARAMS,
    false, // extractable = false for private key security
    ['sign', 'verify']
  );

  // Export the public half now, while it is in hand. The private key is
  // non-extractable and never leaves this function.
  const public_key_jwk = await crypto.subtle.exportKey('jwk', keyPair.publicKey);

  // Store the private CryptoKey in Dexie.
  await db.device.put({
    id: 'current',
    device_uuid,
    private_key: keyPair.privateKey,
    public_key_jwk,
    registered_at: Date.now(),
  });

  return { device_uuid, public_key_jwk };
}

export async function getDeviceIdentity() {
  return await db.device.get('current');
}

export async function signPayload(payload: string, privateKey: CryptoKey): Promise<string> {
  const encoder = new TextEncoder();
  const data = encoder.encode(payload);

  const signatureBuffer = await crypto.subtle.sign(
    ALGO_PARAMS,
    privateKey,
    data
  );

  const bytes = new Uint8Array(signatureBuffer);
  const len = bytes.byteLength;
  let binary = '';
  for (let i = 0; i < len; i++) {
    binary += String.fromCharCode(bytes[i]);
  }

  // Standard base64, then rewritten to unpadded base64url.
  //
  // The rewrite is mandatory, not cosmetic. Str::base64UrlDecode() on the
  // server is strict — /^[A-Za-z0-9_-]+$/ — so it rejects '+', '/' and '='
  // outright and returns null. A DER-encoded P-256 signature is ~70 bytes, which
  // is ~96 base64 characters, and btoa() emits '+' or '/' at roughly one
  // position in 32, so an unconverted signature fails verification almost
  // every time. The PHP suites never caught this because they build their
  // signatures server-side with Str::base64UrlEncode() and therefore encode the
  // signature the way the decoder expects; only a real browser key can produce
  // the mismatch.
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

export async function hashRequestBody(body: string | ArrayBuffer): Promise<string> {
  const data = typeof body === 'string' ? new TextEncoder().encode(body) : body;
  const hashBuffer = await crypto.subtle.digest('SHA-256', data);
  const hashArray = Array.from(new Uint8Array(hashBuffer));
  const hashHex = hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
  return hashHex;
}

/**
 * Lowercase hex SHA-256 of a Blob's bytes.
 *
 * Distinct from hashRequestBody because a Blob has to be read to obtain its
 * bytes first, and because the caller must be certain the digest describes the
 * exact object being stored rather than a re-encoding of it. The server
 * compares this against the bytes it receives and rejects a mismatch outright,
 * so a digest taken from anything other than the uploaded blob is a submission
 * that can never be accepted.
 */
export async function sha256Hex(blob: Blob): Promise<string> {
  const buffer = await blob.arrayBuffer();
  return hashRequestBody(buffer);
}

export function generateNonce(): string {
  const array = new Uint8Array(16);
  crypto.getRandomValues(array);
  return Array.from(array, byte => byte.toString(16).padStart(2, '0')).join('');
}
