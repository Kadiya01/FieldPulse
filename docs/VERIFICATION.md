# Verification policy

How a submission becomes `VERIFIED`, `REQUIRES_REVIEW`, or `REJECTED`, and why
each threshold sits where it does.

---

## Where it runs

Nothing in this document happens during `POST /submit`. The request authenticates,
validates, stores the file in `quarantine/`, writes the ledger row, and enqueues a
job. Everything below runs in `workers/process_queue.php`.

That split is the most important design decision in the system. A request that
decodes a 12-megapixel image, computes a DCT, and queries the band index can
outlive a PHP `max_execution_time` on shared hosting — and a client that gets no
response retries, so a timeout becomes a duplicate submission. The agent gets a
`202` in a few hundred milliseconds, and the queue absorbs the slow work with
retries and a visible failure state.

---

## The pipeline

```
 1. resolve the file          stored_path → real path, or the file is gone
 2. integrity                 magic bytes match the recorded MIME
 3. decode                    GD can open it; dimensions within limits
 4. pHash                     32×32 grayscale → DCT-II → 8×8 → 64 bits
 5. EXIF                      capture time, camera, orientation
 6. period                    Monday 00:00 business tz → UTC window
 7. geofence                  Haversine against assigned sites
 8. duplicates                exact digest, then band-indexed pHash
 9. timestamp                 EXIF vs client vs server receive time
10. weekly cap                count_claimed sum against the cap
11. decide                    Verification\DecisionMatrix
12. archive                   quarantine → processed/{disposition}/
13. aggregate                 recompute the agent's weekly summary
```

Steps 12 and 13 are inside the transaction that writes the verdict, so a crash
cannot leave a file moved but not recorded or a verdict recorded but not counted.

---

## Dispositions

| Disposition | Effect |
|---|---|
| `VERIFIED` | Counted toward the weekly total and the leaderboard |
| `REQUIRES_REVIEW` | Not counted; a supervisor must decide |
| `REJECTED` | Not counted; reasons recorded |

**The automated pass only ever auto-verifies a clean result.** Anything
ambiguous is escalated. The asymmetry is deliberate: a false rejection costs an
agent money and generates a dispute, while a false verify costs the programme
money. When the rules cannot tell fraud from a legitimate edge case, the rules
abstain and a human looks.

---

## Rejection rules

### `IMAGE_UNREADABLE`
The file cannot be decoded, or its magic bytes contradict the recorded MIME.
No further check is meaningful, so the pipeline stops here rather than reporting
a pile of derived failures.

### `EXACT_DUPLICATE`
A byte-identical file already exists. The search is deliberately **global, not
scoped to the submitting agent** — a re-upload by the same agent and the same
photo claimed by a different agent are both double submissions, and narrowing the
query to one agent would let the second one through.

### `PERCEPTUAL_DUPLICATE` / `CROSS_AGENT_DUPLICATE`
pHash distance within `PHASH_HAMMING_THRESHOLD` (default 8) of an earlier
submission in the period. Same agent → `PERCEPTUAL_DUPLICATE`; different agent →
`CROSS_AGENT_DUPLICATE`. The distinction is for the audit trail, not the
disposition: a duplicate is a duplicate either way.

### `TIMESTAMP_FUTURE`
`captured_at` more than `TS_MAX_FUTURE_SKEW` (default 900s) ahead of server
receive time. Generous by design — phone clocks drift, and a small lead is
normal. A large lead means either a broken clock or a pre-dated submission, and
the two are indistinguishable from here.

---

## Review rules

### `POSSIBLE_DUPLICATE`
Distance in `(threshold, threshold + PHASH_POSSIBLE_MARGIN]`. Close enough that
a human should look, not close enough to assert fraud.

### `OUTSIDE_GEOFENCE`
Outside every radius in the agent's `agent_sites`. Not "suspicious" on its own —
site boundaries are administrative, and an agent legitimately working a
neighbouring location looks identical to one defrauding.

### `SITE_UNASSIGNED`
**The agent has no site configured, so the geofence could not be evaluated.**

This is a review, never a pass, and that distinction is the whole point. The
absence of evidence is not evidence of compliance: if an unconfigured agent
auto-verified, then removing a site assignment would be a way to disable
geofencing entirely. Escalating makes the missing configuration visible instead.

### `NO_GPS`
No coordinates supplied. Also a review, not a pass, for the same reason.

### `GPS_UNRELIABLE`
Supplied coordinates are not plausible for a handset — null island, a
lat/long transposition, or a fix so precise it indicates mocking.

### Timestamp family
`TIMESTAMP_MISSING`, `TIMESTAMP_UNPARSEABLE`, `TIMESTAMP_TOO_OLD`,
`TIMESTAMP_DISCREPANCY`. EXIF and client timestamps disagreeing beyond
tolerance means one of the two is wrong, and the system has no way to tell which
is telling the truth.

---

## Perceptual hashing

Resize to 32×32 grayscale, DCT-II, keep the top-left 8×8, threshold the
coefficients against their median, emit 64 bits, split into four 16-bit bands.

The median is the threshold because it is **self-calibrating**: a photo's own
coefficient distribution sets the cut, so an underexposed night shot and a
blown-out midday shot are both compared on their own terms instead of against an
absolute brightness.

The bit loop walks the coefficients in **row-major spatial order**. This is not
incidental. An earlier version sorted the coefficients by magnitude before
assigning bits, which made every bit mean "the i-th largest coefficient" instead
of "the coefficient at position i" — the magnitude distribution survived and the
spatial arrangement was thrown away. Two unrelated images with a similar spread
of coefficient sizes then hashed as near-identical. The
`--filter=phash` mirror test in `bin/selftest.php` exists to catch exactly this.

Bands are only an **index**. Candidates are retrieved by shared band, then the
full 64-bit Hamming distance is computed. A pair 9–15 bits apart whose bands
happen not to overlap is never retrieved; that is recorded as `NOT_ASSESSED`
rather than reported as unique, because "we did not look" and "we found nothing"
are different claims.

### Scaling is not free

pHash tolerance is real but limited. It absorbs re-encoding, mild rescaling, and
slight colour and brightness shifts. It does **not** absorb a heavy crop, a
large rotation, or a screenshot of a screenshot. Do not widen the threshold to
catch those: at distance 12+ the false-positive rate on ordinary unrelated
photos climbs faster than the true-positive rate, and every false positive is a
supervisor's time or a disputed rejection.

---

## Geofencing

Haversine against each `agent_sites` row for the agent, using that site's
`radius_m` (default 250m).

Candidate sites are narrowed with a bounding box before the distance
calculation, padded by `GEOFENCE_BBOX_PADDING_M`. The box is an index-friendly
prefilter only — every candidate is still distance-checked exactly, so padding
error cannot produce a wrong verdict, only a missed optimisation.

An agent assigned to several sites passes if **any** site matches, which is what
makes multi-site deployment work without a special case.

---

## The queue

The database is the queue. There is no broker to run and nothing to supervise.

```
claim   UPDATE ... SET status='processing', locked_at=UTC_TIMESTAMP(), lock_token=:t
        WHERE id IN (SELECT id FROM (SELECT id FROM processing_jobs
                     WHERE status IN ('queued','failed') AND run_after <= UTC_TIMESTAMP()
                     ORDER BY run_after, id LIMIT :n) AS due)
```

`claimBatch` is safe to run concurrently: the row lock is what serialises it, and
each worker only ever touches rows carrying its own `lock_token`.

A job that dies — timeout, OOM, `kill -9` — is left in `processing`. The next
tick's `recoverStaleLocks` returns it to the queue once `locked_at` is older than
`JOB_LOCK_TIMEOUT_MIN`. Without that, one killed worker would strand a
submission in `processing` forever, with no error and no alert.

Retries use exponential backoff via `run_after`, capped by `JOB_MAX_ATTEMPTS`.
A submission that exhausts its attempts ends in `FAILED` with the error recorded,
visible to an operator. It is never silently dropped.

Overlapping cron ticks are **expected and safe**. A tick that runs past 60
seconds will overlap the next one; the lock protocol handles it.

---

## Why the summary is a full recompute

Every write of `agent_performance_summary` recomputes all counters from
`submissions` rather than incrementing them.

Incrementing is faster and is where this class of bug lives: a job retried three
times counts one submission three times; a restore from backup undercounts
permanently. A full recompute is idempotent, so replaying a day of cron, a
re-processed job, or a `bin/reaggregate.php` run cannot corrupt anything.

The table has exactly one writer — `LeaderboardRepository::refresh()` — called
from the verification service, the review service, and the rebuild tool. An
earlier version kept a second copy of the summary SQL inside the verification
service, and the two drifted until one referenced a column the table did not
have. One table, one writer.

---

## Tuning

| Variable | Default | Raising it | Lowering it |
|---|---|---|---|
| `PHASH_HAMMING_THRESHOLD` | 8 | Catches more re-encodes; more false positives | Safer, misses re-encoded duplicates |
| `PHASH_POSSIBLE_MARGIN` | 4 | More escalations, fewer auto-passes | Cheaper, more misses reach auto-verify |
| `GEOFENCE_DEFAULT_RADIUS_M` | 250 | Tolerates GPS error and boundary work | Fewer false escalations, more misses |
| `TS_MAX_FUTURE_SKEW` | 900 | Tolerates worse clocks; weaker anti-pre-dating | Stricter; more `TIMESTAMP_FUTURE` |
| `WEEKLY_CAP` | 2500 | — | Catches bulk fraud earlier |

Bump `VERIFICATION_VERSION` whenever a threshold changes. It is stamped onto
every verdict, so a disputed count can be traced to the exact rule set that
produced it. Then run `bin/reaggregate.php` if past verdicts need re-deciding —
the old verdicts will not update themselves.
