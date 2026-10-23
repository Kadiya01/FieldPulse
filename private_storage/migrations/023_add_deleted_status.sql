-- ---------------------------------------------------------------------------
-- 023 — DELETED agent status
--
-- Additive migration.
--
-- The ADMIN user-management flow retires an agent by soft-deleting the row:
-- the record, its submissions and its audit trail must survive for compliance,
-- but the account must be un-usable forever. The status column already models
-- usability, so retiring is one more status value rather than a DELETE
-- statement or a new tombstone table.
--
-- The new value is appended to the ENUM, so no existing row changes meaning.
-- DELETED is deliberately not the default: nothing on the insert side created
-- a DELETED row before, and nothing here makes it possible by accident.
--
-- Enforcement is shared, not new: every authentication path re-reads the agent
-- row and refuses anything that is not ACTIVE (Authenticator), so a retired
-- account stops working on its very next request — same mechanism that already
-- suspends accounts. The retirement-specific rules live in the controller that
-- performs it: DELETED is terminal, cannot be set on yourself, and cannot be
-- set on the last active ADMIN.
-- ---------------------------------------------------------------------------

ALTER TABLE agents
    MODIFY status ENUM('ACTIVE','INACTIVE','SUSPENDED','DELETED') NOT NULL DEFAULT 'ACTIVE';