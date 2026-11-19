# Rewards

FieldPulse reports weekly and monthly activity, the server verifies it, and the
organisation rewards agents from their **frozen** verified standing. This
document describes the last part of that sentence: how a period closes, how an
entitlement is published, and what an operator may do with it afterwards.

## The cutoff rule

> At period close, only the frozen verified ranking determines reward eligibility and rank; later changes to live performance summaries do not change an already published reward entitlement.

Everything else here is that rule made mechanical. If a change you are about to
make would let a published entitlement move because a live summary moved, the
change is wrong.

## Periods

FieldPulse supports both **WEEKLY** and **MONTHLY** reporting periods, discriminated by `period_type`:

- **WEEKLY**: One business week, identified by `period_start_date` as the Monday `00:00:00` in the configured business timezone (`APP_TIMEZONE`, default `Africa/Lagos`).
- **MONTHLY**: One calendar month, identified by `period_start_date` as the first day of the month (`YYYY-MM-01 00:00:00`) in `APP_TIMEZONE`.

Periods are produced by `Domain\PeriodResolver`, which is the only place boundary and period arithmetic lives.

### Preserving historical records

Migration 026 restores the `period_type ENUM('WEEKLY', 'MONTHLY') NOT NULL DEFAULT 'WEEKLY'` discriminator across:
1. `agent_performance_summary`
2. `reward_rankings`
3. `agent_rewards`

All historical records are backfilled and defaulted to `WEEKLY`, preserving all existing weekly data and functionality without change.

### Collision prevention

Because a monthly period always begins on the 1st of the month, and a weekly period can also begin on the 1st of the month (when the 1st falls on a Monday), `period_type` is included in all unique keys and composite primary keys:
- `agent_performance_summary`: `PRIMARY KEY (agent_id, period_start_date, period_type)`
- `reward_rankings`: `PRIMARY KEY (period_start_date, rank, period_type)` and `UNIQUE KEY (period_start_date, agent_id, period_type)`
- `agent_rewards`: `PRIMARY KEY (id)`, `UNIQUE KEY (agent_id, period_start_date, period_type)`, and `FOREIGN KEY fk_reward_ranking (period_start_date, agent_id, period_type) REFERENCES reward_rankings (period_start_date, agent_id, period_type)`

This structural separation ensures that weekly and monthly performance summaries, frozen rankings, and reward entitlements never collide, combine, or overwrite one another.

A period becomes **closable** once its UTC window has ended and a grace window
has passed:

```
closable  <=>  now >= period_end_utc + rewards.close_grace_hours
```

The grace window is a straggler margin, not an extension of the week. A report
captured at 23:50 on Sunday belongs to the week that ended ten minutes later, and
the grace is the time its queue entry gets to be verified before the standing is
read out. It defaults to 24 hours and is set by `REWARD_CLOSE_GRACE_HOURS`.

## The three tables

| Table | Written | Meaning |
|---|---|---|
| `reward_tiers` | by an operator | the schedule: a rank band, a label, and (optionally) an amount |
| `reward_rankings` | once, at close | the frozen standing — the ranked order for one period, immovable |
| `agent_rewards` | once, at close | the published entitlement, then moved through its states by operators |

`reward_rankings` is the freeze. Its primary key is
`(period_start_date, rank, period_type)`, so a period cannot hold two agents at
the same rank, and nothing in the application ever updates it.

`agent_rewards` carries `FOREIGN KEY (period_start_date, agent_id, period_type)
REFERENCES reward_rankings (period_start_date, agent_id, period_type)`. That is
deliberate and is the structural half of the cutoff rule: an entitlement cannot
exist for an agent and period that was not frozen, so there is no code path that
could re-derive a reward from a live summary. The schema refuses.

### Rank order

Rank comes from exactly one place — the same `ORDER BY` the leaderboard uses:

```
total_verified_count DESC, total_pending ASC, agent_code ASC
```

The freeze reads it through `LeaderboardRepository::standingForPeriod()`, which
reuses the leaderboard's own `filters()` and ordering constant. A frozen rank and
the rank shown on screen are therefore the same number by construction, not by
careful maintenance of two copies.

Only agents with `status = 'ACTIVE'` are ranked, because the leaderboard hides
inactive agents and the freeze must match what was displayed. An agent suspended
before close is not ranked that week and receives no entitlement; the
`agent_status_at_close` column records the status the ranked agents held at close
so a later status change cannot rewrite history.

## Closing a period

`Reward\RewardService::closePeriod()` does, inside one transaction:

1. Return `already_closed` if `reward_rankings` already holds the period.
2. Read the standing and write `reward_rankings`.
3. Match each rank against the active bands in `reward_tiers`.
4. Write one `agent_rewards` row per matched rank, `status = 'PENDING'`.

A rank that falls in no active band receives **no** row. Absence of a band means
"not rewarded", which is not the same thing as being rewarded with nothing.

The close is idempotent three times over: the `already_closed` check, `INSERT
IGNORE` on the freeze, and a no-op `ON DUPLICATE KEY UPDATE` on entitlements. Any
number of concurrent closers therefore produce exactly one freeze and exactly one
entitlement per agent.

### How it is triggered

Publication never depends on somebody opening a screen:

| Trigger | When | Notes |
|---|---|---|
| cPanel cron worker | every minute | `workers/process_queue.php` calls `closeDuePeriods()` when `REWARD_AUTO_CLOSE` is true |
| `bin/close_rewards.php` | on demand | `--all` (default), `--period=YYYY-MM-DD`, `--dry-run` |
| Reward reads | on read | the `/rewards` endpoints call `closeIfDue()` when `REWARD_CLOSE_ON_READ` is true |

All three call the same `closePeriod()`, so whichever gets there first closes the
week and the others find nothing to do. The read-path fallback exists for a
deployment whose cron has stopped; it is a fallback, not the mechanism.

## Publication is not payment

`status` walks one direction only:

```
PENDING ──approve──> APPROVED ──pay──> PAID
   │                     │
   └──────void───────────┴──> VOID
```

| Status | Means | Who writes it |
|---|---|---|
| `PENDING` | published: "this is what you are owed" | the close itself |
| `APPROVED` | an operator has confirmed the figure | `SUPERVISOR` / `ADMIN` |
| `PAID` | the organisation has settled up | `SUPERVISOR` / `ADMIN` |
| `VOID` | cancelled, with a mandatory reason | `SUPERVISOR` / `ADMIN` |

FieldPulse cannot move money. `PAID` only ever records something the organisation
did outside this system, so the agent-facing UI must not read `PENDING` or
`APPROVED` as "paid". The API returns an explicit `is_paid` boolean for that
reason.

`PAID` is terminal: a paid reward cannot be voided, because voiding it would
erase the record of a payment rather than record a cancellation.

Every transition is guarded inside the `UPDATE` (`AND status = ...`), so two
operators acting at once produce a `409 STATE_CONFLICT` for the loser instead of
the second click silently overwriting the first.

## Amounts

`reward_amount` and `currency` are **nullable, and NULL is meaningful**. A band
with a NULL amount has not had a figure set by the organisation; a published
entitlement with a NULL amount is shown as a tier with no figure attached.

Nothing in the codebase substitutes a default. Zero would read as "worth
nothing", which is a different claim from "not yet decided", and inventing a
figure is the organisation's decision to make, not the application's.

## Operator identifiers

`approved_by_operator_id`, `paid_by_operator_id` and `voided_by_operator_id`
reference `agents(id)`. This deployment has one identity concept — an agent row
carrying a `SUPERVISOR` or `ADMIN` role (migration 014) — so an operator *is* an
agent row. The columns are not named `*_by_agent_id` because sitting next to
`agent_id` (the recipient) that reads as though the payee had approved their own
payment.

## Configuration

| Variable | Default | Meaning |
|---|---|---|
| `REWARD_CLOSE_GRACE_HOURS` | `24` | hours after a period's UTC window before it may close |
| `REWARD_AUTO_CLOSE` | `true` | let the cron worker close periods on its own |
| `REWARD_CLOSE_ON_READ` | `true` | close-on-read fallback for a deployment whose cron has stopped |

## Troubleshooting

- **A week never closes.** Check that `agent_performance_summary` has rows for
  it — a period with no summary rows is never a close candidate, because there is
  nothing to rank. Run `bin/close_rewards.php --period=<monday> --dry-run` to see
  `due` and `frozen` for one week at a time.
- **An agent is missing from a closed week.** They were not `ACTIVE` when the
  period closed, so they were not on the board and were not ranked. That is
  intended, and the freeze will not be rewritten to include them.
- **An amount is blank on a published reward.** The band's `reward_amount` was
  NULL at close. Set it in `reward_tiers`; the change applies to periods that
  close afterwards, not to entitlements already published.