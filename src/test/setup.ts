import '@testing-library/jest-dom/vitest';
import { webcrypto } from 'node:crypto';

// jsdom ships a `crypto` object that provides randomUUID and getRandomValues
// but no SubtleCrypto, so `crypto.subtle.generateKey` is undefined under the
// jsdom environment and every device-identity test fails on a missing property
// rather than on real behaviour. Node's webcrypto is a complete implementation
// of the same spec, so install it when — and only when — the DOM one is
// incomplete. This keeps the tests exercising real ECDSA.
const hasSubtle = typeof globalThis.crypto !== 'undefined' && typeof globalThis.crypto.subtle !== 'undefined';

if (!hasSubtle) {
  Object.defineProperty(globalThis, 'crypto', {
    value: webcrypto,
    configurable: true,
    writable: true,
  });
}
