-- ---------------------------------------------------------------------------
-- 019 — storage state and evidence-path durability
--
-- Two columns, both answering the same question: does the row's file_path
-- currently name a file that exists?
--
-- storage_state
--   The ingestion pipeline moves bytes across four directories and writes the
--   ledger at the same time, and those are two different kinds of store. MySQL
--   gives us a transaction over its own writes and nothing else. There is no
--   atomic commit across a filesystem rename and an INSERT, and pretending
--   otherwise is how a ledger ends up pointing at a file that was never
--   written.
--
--   Rather than modelling a fiction, the row records where the bytes are
--   supposed to be:
--
--     QUARANTINED  file in quarantine/, written and confirmed before the row
--                  committed. The only state where file_path is known-good.
--     PROCESSED    moved to processed/{verified,review,rejected}. Set in the
--                  same statement that rewrites file_path, so the pair moves
--                  together.
--     MISSING      a reconciliation pass found no file at file_path. The
--                  evidence is gone or was removed out of band, and that fact
--                  is recorded rather than discovered later during an audit.
--
--   REJECTED is deliberately NOT a storage state. A rejected submission still
--   has a file, in processed/rejected/. "Rejected" is a verification outcome
--   carried by submissions.status; conflating the two would produce rows that
--   are rejected for two unrelated reasons.
--
-- storage_state_updated_at
--   Without a timestamp, "when did the file stop matching the row" is
--   unanswerable, and an audit that cannot bound its own window cannot
--   certify anything. bin/prune.php reports on it.
--
-- client_accuracy_m
--   accuracy_m has been in SubmitController::ALLOWED_PAYLOAD_KEYS since the
--   endpoint was written and is populated by CapturePage from
--   GeolocationPosition.coords.accuracy, but it was never validated, never
--   stored, and never read. The allowlist entry made the field *accepted*
--   without making it *used*: a client that sent an accuracy of 10 m and one
--   that sent 10000 m produced byte-identical ledger rows, and
--   Geofence::evaluate() had no accuracy argument to tell them apart.
--
--   This is the exact failure the Phase 4 gate calls "no accepted API field is
--   silently discarded". It is not cosmetic: Geofence::isBlocking() treats an
--   implausible position as GPS_UNRELIABLE, and a self-reported accuracy is
--   one of the signals that makes a position implausible. Storing it is the
--   precondition for using it.
--
--   DECIMAL(10,2), nullable:
--     nullable  — a fix with no reported accuracy is legal, and must stay
--                 distinguishable from a reported accuracy of zero.
--     10,2      — millimetre precision is far past any real GNSS figure, but
--                 floats arrive as e.g. 12.5432109 and DECIMAL keeps the
--                 ledger comparison-exact rather than dragging a double into
--                 an aggregate query.
--
--   Named client_accuracy_m to match client_latitude / client_longitude /
--   client_captured_at: every client_-prefixed column here is an untrusted
--   claim. Server-derived columns carry a server_ prefix and are never written
--   by SubmitController.
-- ---------------------------------------------------------------------------

ALTER TABLE submissions
    ADD COLUMN storage_state ENUM('QUARANTINED','PROCESSED','MISSING') NOT NULL DEFAULT 'QUARANTINED'
        AFTER file_path,
    ADD COLUMN storage_state_updated_at DATETIME NULL AFTER storage_state,
    ADD COLUMN client_accuracy_m DECIMAL(10,2) NULL AFTER client_captured_at;

-- Reconciliation and audit both scan for rows whose bytes are not where the row
-- says. Left of the status/date indexes so a state-first query stays index-only.
CREATE INDEX idx_submissions_storage_state ON submissions (storage_state, server_received_at);