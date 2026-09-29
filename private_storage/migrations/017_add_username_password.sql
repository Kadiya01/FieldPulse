-- ---------------------------------------------------------------------------
-- 017 — username/password credentials, and bootstrap sessions
--
-- Phase 2 replaces the zero-password model. Previously the IMEI identified the
-- agent and the P-256 device key proved possession; there was no secret
-- anywhere, so anyone who knew a 15-digit number was that agent. IMEI is not
-- an authentication factor — it is a hardware serial number printed on the
-- handset, published on the box, and routinely recycled — and browsers cannot
-- read it, so "IMEI as the username" was never more than a user-typed string
-- with extra steps.
--
-- Two changes:
--
--   1. agents gains `username` and `password_hash`. The hash is produced by
--      PHP's password_hash() (bcrypt by default), which is salted, slow, and
--      constant-time. Both are NULLable: an existing deployment has rows
--      enrolled under the old model, and NULL here means "no password
--      credential", which cannot authenticate rather than can. Those agents
--      are given credentials deliberately by an operator, not silently.
--
--      uniq_agents_username is UNIQUE so that a lookup cannot return two
--      candidates. MySQL treats NULLs as distinct in a UNIQUE index, so every
--      un-migrated agent can share NULL without colliding.
--
--   2. refresh_tokens.device_id becomes NULLABLE. Login is now pure
--      credentials: the client does not yet hold a bound device, because the
--      device is bound *after* login by the authenticated bootstrap flow. A
--      session that exists before a device is bound is a bootstrap session,
--      and it must be representable or the login response has nowhere to put
--      its refresh cookie.
--
--      A bootstrap session is deliberately weak on its own: it is bound to no
--      device, so it can only reach the bearer-only bootstrap routes, never a
--      signed one. The moment registration completes, the client is handed a
--      device-bound token and a device-bound refresh cookie and the bootstrap
--      one is revoked.
-- ---------------------------------------------------------------------------

ALTER TABLE agents
    ADD COLUMN username VARCHAR(64) NULL AFTER full_name,
    ADD COLUMN password_hash VARCHAR(255) NULL AFTER username,
    ADD COLUMN password_updated_at DATETIME NULL AFTER password_hash;

CREATE UNIQUE INDEX uniq_agents_username ON agents (username);

-- The credential lookup is by username, and a login attempt must not be able to
-- tell "no such user" from "wrong password" by timing. Indexing the lookup
-- column keeps it a single-row seek rather than a scan.
CREATE INDEX idx_agents_login ON agents (username, status);

ALTER TABLE refresh_tokens
    MODIFY COLUMN device_id BIGINT UNSIGNED NULL;

-- The existing composite index starts at agent_id, so a device-scoped sweep
-- (revoke every session for a device) cannot use it once device_id is
-- nullable. A dedicated index keeps device revocation a seek.
CREATE INDEX idx_refresh_device_only ON refresh_tokens (device_id, revoked_at);
