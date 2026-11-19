import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const read = (path: string) => readFileSync(resolve(here, path), 'utf8');

const page = read('pages/RegisterPage.tsx');
const api = read('api/client.ts');
const controller = read('../private_storage/app/Domain/AbhAgentRegistrationController.php');
const repository = read('../private_storage/app/Database/AbhAgentRepository.php');
const kernel = read('../private_storage/app/Http/Kernel.php');
const route = read('../public_html/api/v1/abh/agents/register.php');

describe('ABH self-registration contract', () => {
  it('collects phone, password, IMEI and profile information without username or NIN', () => {
    expect(page).toContain('Nigerian mobile phone number');
    expect(page).toContain('Registered device IMEI');
    expect(page).toContain('Password');
    expect(page).toContain('Operating location / LGA');
    expect(page).toContain('generateAndStoreDeviceIdentity');
    expect(page).toContain('key_proof: keyProof');
    expect(page).not.toMatch(/username|\bNIN\b|\bnin\b/i);
  });

  it('uses the dedicated ABH registration API and declares device proof fields', () => {
    expect(page).toContain('registerAbhAgent');
    expect(api).toContain('/abh/agents/register.php');
    expect(api).toContain('interface AbhAgentRegistrationInput');
    const inputStart = api.indexOf('export interface AbhAgentRegistrationInput');
    const resultStart = api.indexOf('export interface AbhAgentRegistrationResult');
    const inputContract = api.slice(inputStart, resultStart);
    expect(inputContract).toContain('phone: string');
    expect(inputContract).toContain('device_uuid: string');
    expect(inputContract).toContain('public_key_jwk: JsonWebKey');
    expect(inputContract).toContain('key_proof: string');
    expect(inputContract).not.toMatch(/username|\bnin\b/i);
  });

  it('requires a valid P-256 public key proof and creates a pending application', () => {
    expect(controller).toContain("'device_uuid', 'public_key_jwk', 'key_proof'");
    expect(controller).toContain('Jwk::validatePublicJwk');
    expect(controller).toContain('SignatureVerifier::assertProofOfPossession');
    expect(controller).toContain("'PENDING'");
    expect(controller).not.toContain("'nin'");
    expect(controller).not.toContain("'username'");
  });

  it('persists ABH identity, credential, device key and audit rows transactionally', () => {
    expect(repository).toContain('Connection::transaction');
    expect(repository).toContain('INSERT INTO abh_agents');
    expect(repository).toContain('INSERT INTO abh_agent_credentials');
    expect(repository).toContain('INSERT INTO abh_agent_devices');
    expect(repository).toContain('device_uuid');
    expect(repository).toContain('public_key_jwk');
    expect(repository).toContain('INSERT INTO abh_audit_events');
    expect(repository).toContain('INSERT INTO abh_notifications');
    expect(repository).not.toMatch(/INSERT INTO agents\b|INSERT INTO devices\b/i);
  });

  it('registers only the dedicated ABH public registration route and keeps legacy route intact', () => {
    expect(kernel).toContain("'abh.agent.register'");
    expect(kernel).toContain("'agent.register'");
    expect(route).toContain("Kernel::handle('abh.agent.register')");
  });
});
