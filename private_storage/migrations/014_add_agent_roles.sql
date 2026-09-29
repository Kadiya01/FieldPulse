-- ---------------------------------------------------------------------------
-- 014 — agent roles
--
-- Additive migration.
--
-- The review queue (§13) is a supervisor function: a reviewer decides whether an
-- agent is paid for a submission, which is the single most consequential write
-- in the system. Enforcing that needs a role on the agent row, because a
-- reviewer is an agent row that happens to hold a supervisor role rather than a
-- separate user table — the deployment model has exactly one identity concept,
-- the enrolled handset, and inventing a second one for reviewers would have
-- meant a second credential model.
--
-- Default is AGENT, so this migration is safe to apply to a live table: no
-- existing row gains any privilege by being updated in place.
-- ---------------------------------------------------------------------------

ALTER TABLE agents
    ADD COLUMN role ENUM('AGENT','SUPERVISOR','ADMIN') NOT NULL DEFAULT 'AGENT' AFTER full_name;

-- The Kernel role gate filters on (role, status); without this the check is a
-- full table scan on a table that grows by one row per enrolment.
CREATE INDEX idx_agents_role ON agents (role, status);
