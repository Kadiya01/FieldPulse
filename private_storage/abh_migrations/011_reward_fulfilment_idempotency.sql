-- Give each reward fulfilment event an idempotency key so a lost
-- response cannot accidentally record a payment or delivery twice.
ALTER TABLE abh_reward_fulfilment_events
    ADD COLUMN idempotency_key CHAR(36) NULL AFTER award_id,
    ADD UNIQUE KEY uq_abh_reward_fulfilment_award_idempotency (award_id, idempotency_key);
