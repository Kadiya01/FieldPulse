import { useEffect, useState } from 'react';
import {
  fetchMyRewards,
  fetchPeriodRewards,
  decideReward,
  ApiError,
  type Reward,
  type OperatorReward,
  type RewardStatus,
  type RewardPeriod
} from '../api/client';
import { useSession } from '../auth/sessionContext';
import { Award, RefreshCw, Loader2, Check, Banknote, Ban, AlertCircle } from 'lucide-react';

/**
 * Rewards.
 *
 * Two readers share one screen. Everyone sees *their own* published
 * entitlements, fetched from `rewards/self.php`, which is bearer-scoped to the
 * caller and cannot be widened by a parameter. An operator additionally sees the
 * whole period and can move an entitlement through its states — approve, pay,
 * void — from `rewards/index.php` and `rewards/decide.php`, both operator-only.
 *
 * The role check below is presentation, exactly as on the review screen:
 * `Kernel::assertOperator` is the enforcement, and a role revoked server-side
 * still lands here as a 403 that this page words as "no longer".
 *
 * Nothing on this screen is a live figure. Every amount and rank was frozen when
 * the period closed; a reward shown here will not move because a later capture
 * was verified. That is the whole point of the feature.
 */
export default function RewardsPage() {
  const { isOperator, label } = useSession();

  const [mine, setMine] = useState<Reward[] | null>(null);
  const [paymentNote, setPaymentNote] = useState('');
  const [loadingMine, setLoadingMine] = useState(true);
  const [mineError, setMineError] = useState('');

  const [board, setBoard] = useState<RewardPeriod | null>(null);
  const [loadingBoard, setLoadingBoard] = useState(false);
  const [boardError, setBoardError] = useState('');
  const [notice, setNotice] = useState('');

  // The self view is fetched once. It is not periodic data, so nothing here
  // depends on the operator's period selector.
  useEffect(() => {
    let live = true;

    (async () => {
      try {
        const result = await fetchMyRewards();

        if (live) {
          setMine(result.rewards);
          setPaymentNote(result.meta.payment_note);
          setMineError('');
        }
      } catch (err) {
        if (live) {
          setMineError(
            err instanceof Error ? err.message : 'Could not load your rewards.'
          );
        }
      } finally {
        if (live) {
          setLoadingMine(false);
        }
      }
    })();

    return () => {
      live = false;
    };
  }, []);

  /**
   * The operator board, fetched once on mount.
   *
   * Inline async body rather than a shared callback entered synchronously, for
   * the same reason as the review queue: a setState on that path is the cascading
   * render `react/set-state-in-effect` exists to prevent. `live` guards unmount.
   * Changing week goes through `loadPeriod`, so a fetch never triggers another.
   */
  useEffect(() => {
    if (!isOperator) {
      return;
    }

    let live = true;

    (async () => {
      try {
        const fresh = await fetchPeriodRewards();

        if (live) {
          setBoard(fresh);
          setBoardError('');
        }
      } catch (err) {
        if (live) {
          setBoard(null);
          setBoardError(
            err instanceof Error ? err.message : 'Could not load this period.'
          );
        }
      } finally {
        if (live) {
          setLoadingBoard(false);
        }
      }
    })();

    return () => {
      live = false;
    };
  }, [isOperator]);

  /** Switching week from the selector. A press, so state up front is fine. */
  const loadPeriod = async (nextPeriod: string) => {
    setLoadingBoard(true);
    setBoardError('');
    setNotice('');

    try {
      setBoard(await fetchPeriodRewards(nextPeriod));
    } catch (err) {
      setBoardError(err instanceof Error ? err.message : 'Could not load that period.');
    } finally {
      setLoadingBoard(false);
    }
  };

  /** Shared by the Refresh button and after a decision; a press, so state up front is fine. */
  const reload = async () => {
    setLoadingMine(true);
    setLoadingBoard(true);

    try {
      const [self, fresh] = await Promise.all([
        fetchMyRewards(),
        isOperator ? fetchPeriodRewards(board?.meta.period) : Promise.resolve(null)
      ]);
      setMine(self.rewards);
      setPaymentNote(self.meta.payment_note);
      setMineError('');
      if (fresh) {
        setBoard(fresh);
      }
    } catch (err) {
      setMineError(err instanceof Error ? err.message : 'Could not load rewards.');
    } finally {
      setLoadingMine(false);
      setLoadingBoard(false);
    }
  };

  return (
    <section>
      <div className="flex flex-wrap items-center justify-between gap-3 mb-1">
        <h1 className="text-xl font-bold text-gray-900 flex items-center gap-2">
          <Award size={20} aria-hidden="true" /> Rewards
        </h1>
        <button
          type="button"
          onClick={() => void reload()}
          disabled={loadingMine || loadingBoard}
          className="inline-flex items-center gap-1 text-sm font-medium text-blue-800 bg-white px-3 py-2 rounded border border-blue-300 hover:bg-blue-50 disabled:opacity-60"
        >
          {loadingMine || loadingBoard
            ? <Loader2 size={14} className="animate-spin" aria-hidden="true" />
            : <RefreshCw size={14} aria-hidden="true" />}
          Refresh
        </button>
      </div>

      <p className="text-sm text-gray-700 mb-4">
        These are published entitlements, decided from the standing frozen when each
        week closed. They do not change when later captures are verified.
        {paymentNote && <span className="block text-gray-600 mt-1">{paymentNote}</span>}
      </p>

      <div aria-live="polite">
        {mineError && (
          <p role="alert" className="bg-red-100 border border-red-400 text-red-800 px-4 py-3 rounded mb-4 flex items-start gap-2 text-sm">
            <AlertCircle className="shrink-0 mt-0.5" size={16} aria-hidden="true" />
            <span>{mineError}</span>
          </p>
        )}
      </div>

      <h2 className="text-lg font-semibold text-gray-900 mb-2">Your rewards</h2>

      {mine === null && loadingMine ? (
        <p role="status" className="text-center text-gray-700 flex items-center justify-center gap-2 py-8">
          <Loader2 size={16} className="animate-spin" aria-hidden="true" />
          Loading your rewards…
        </p>
      ) : mine && mine.length > 0 ? (
        <ul className="space-y-3 mb-8">
          {mine.map(reward => (
            <li key={reward.id}>
              <RewardCard reward={reward} />
            </li>
          ))}
        </ul>
      ) : (
        !mineError && (
          <p className="text-gray-700 bg-white rounded-lg shadow p-6 text-center mb-8">
            You have no published rewards yet. A week appears here once it closes.
          </p>
        )
      )}

      {isOperator && (
        <>
          <div className="flex flex-wrap items-center justify-between gap-3 mb-2">
            <h2 className="text-lg font-semibold text-gray-900">Period entitlements</h2>
            {board && board.meta.available_periods.length > 0 && (
              <div>
                <label htmlFor="period" className="sr-only">Period</label>
                <select
                  id="period"
                  value={board.meta.period}
                  onChange={e => void loadPeriod(e.target.value)}
                  className="text-sm border border-gray-400 rounded-md p-2 bg-white"
                >
                  {board.meta.available_periods.map(p => (
                    <option key={p} value={p}>{p}</option>
                  ))}
                </select>
              </div>
            )}
          </div>

          <div aria-live="polite">
            {boardError && (
              <p role="alert" className="bg-red-100 border border-red-400 text-red-800 px-4 py-3 rounded mb-4 text-sm">
                {boardError}
              </p>
            )}
            {notice && (
              <p className="bg-green-100 border border-green-400 text-green-900 px-4 py-3 rounded mb-4 text-sm">
                {notice}
              </p>
            )}
          </div>

          {label && <p className="text-xs text-gray-600 mb-3">Signed in as {label}</p>}

          {loadingBoard && !board ? (
            <p role="status" className="text-center text-gray-700 py-6">Loading period…</p>
          ) : board && board.rewards.length > 0 ? (
            <ul className="space-y-3">
              {board.rewards.map(reward => (
                <li key={reward.id}>
                  <OperatorRewardCard
                    reward={reward}
                    onDecided={async (message) => {
                      setNotice(message);
                      await reload();
                    }}
                  />
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-gray-700 bg-white rounded-lg shadow p-6 text-center">
              No rewards were published for this period.
            </p>
          )}
        </>
      )}
    </section>
  );
}

/** The word for each state, spelled out rather than left as an uppercase enum. */
const STATUS_WORDS: Record<RewardStatus, string> = {
  PENDING: 'Published — awaiting approval',
  APPROVED: 'Approved — awaiting payment',
  PAID: 'Paid',
  VOID: 'Voided'
};

const STATUS_TONE: Record<RewardStatus, string> = {
  PENDING: 'text-amber-800',
  APPROVED: 'text-blue-800',
  PAID: 'text-green-800',
  VOID: 'text-gray-600'
};

/**
 * One entitlement, addressed to the reader.
 *
 * The amount is shown as a bare number when set and as "Amount not set" when the
 * server returns null — never as 0. The organisation publishes a null until it
 * has decided a figure, and rendering that as zero would claim an agent is owed
 * nothing.
 */
function RewardCard({ reward }: { reward: Reward }) {
  return (
    <article className="bg-white rounded-lg shadow p-4">
      <header className="flex flex-wrap items-baseline justify-between gap-2 mb-2">
        <div>
          <h3 className="font-semibold text-gray-900">
            {reward.tier.label ?? 'Reward'}
          </h3>
          <p className="text-sm text-gray-700">
            Week of {reward.period_start_date} · rank {reward.rank} ·{' '}
            {reward.total_verified_count} verified
          </p>
        </div>
        <span className={`text-sm font-medium ${STATUS_TONE[reward.status]}`}>
          {STATUS_WORDS[reward.status]}
        </span>
      </header>
      <p className="text-2xl font-bold text-gray-900">
        {formatAmount(reward)}
      </p>
      {reward.void_reason && (
        <p className="text-sm text-gray-700 mt-2">Reason: {reward.void_reason}</p>
      )}
    </article>
  );
}

/**
 * One entitlement with its operator actions.
 *
 * Which buttons appear is derived from the current status, and mirrors the
 * server's transition table rather than inventing one: PENDING can be approved
 * or voided, APPROVED can be paid or voided, and PAID is final. A transition the
 * server would refuse is not offered, but the server still checks — losing a race
 * with another operator returns 409 and is worded here rather than as a crash.
 *
 * `agent_code` identifies the recipient. There is no agent id in the response
 * the page acts on, so it cannot name a payee the operator never saw.
 */
function OperatorRewardCard({
  reward,
  onDecided
}: {
  reward: OperatorReward;
  onDecided: (message: string) => Promise<void>;
}) {
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState<'APPROVE' | 'PAY' | 'VOID' | null>(null);
  const [errorMsg, setErrorMsg] = useState('');

  const act = async (action: 'APPROVE' | 'PAY' | 'VOID') => {
    if (action === 'VOID' && reason.trim().length < 5) {
      setErrorMsg('A reason of at least 5 characters is required to void a reward.');
      return;
    }

    setBusy(action);
    setErrorMsg('');

    try {
      await decideReward(reward.id, action, action === 'VOID' ? reason.trim() : undefined);
      setReason('');
      await onDecided(
        action === 'APPROVE'
          ? `Approved ${reward.agent.agent_code}'s reward for ${reward.period_start_date}.`
          : action === 'PAY'
            ? `Marked ${reward.agent.agent_code}'s reward paid for ${reward.period_start_date}.`
            : `Voided ${reward.agent.agent_code}'s reward for ${reward.period_start_date}.`
      );
    } catch (err) {
      setErrorMsg(
        err instanceof ApiError && err.status === 409
          ? 'Someone else changed this reward first. Refresh to see its current state.'
          : err instanceof Error
            ? err.message
            : 'The decision could not be recorded.'
      );
    } finally {
      setBusy(null);
    }
  };

  const canApprove = reward.status === 'PENDING';
  const canPay = reward.status === 'APPROVED';
  const canVoid = reward.status === 'PENDING' || reward.status === 'APPROVED';

  return (
    <article className="bg-white rounded-lg shadow p-4">
      <header className="flex flex-wrap items-baseline justify-between gap-2 mb-2">
        <div>
          <h3 className="font-semibold text-gray-900">
            {reward.agent.agent_code} · {reward.agent.full_name || 'Unnamed agent'}
          </h3>
          <p className="text-sm text-gray-700">
            {reward.tier.label ?? 'Reward'} · week of {reward.period_start_date} · rank{' '}
            {reward.rank} · {reward.total_verified_count} verified
          </p>
        </div>
        <span className={`text-sm font-medium ${STATUS_TONE[reward.status]}`}>
          {STATUS_WORDS[reward.status]}
        </span>
      </header>

      <p className="text-2xl font-bold text-gray-900 mb-3">{formatAmount(reward)}</p>

      <div role="alert" aria-live="assertive">
        {errorMsg && (
          <p className="text-sm text-red-800 bg-red-100 border border-red-300 rounded px-3 py-2 mb-2">
            {errorMsg}
          </p>
        )}
      </div>

      {canVoid && (
        <>
          <label htmlFor={`void-${reward.id}`} className="block text-sm font-medium text-gray-800 mb-1">
            Void reason <span className="font-normal text-gray-600">(required to void)</span>
          </label>
          <input
            id={`void-${reward.id}`}
            value={reason}
            onChange={e => setReason(e.target.value)}
            className="w-full border border-gray-400 rounded-md p-2 text-sm mb-2"
            placeholder="Why this entitlement is being cancelled"
          />
        </>
      )}

      <div className="flex flex-wrap gap-2">
        {canApprove && (
          <button
            type="button"
            onClick={() => void act('APPROVE')}
            disabled={busy !== null}
            className="inline-flex items-center gap-1 bg-blue-700 text-white px-4 py-2 rounded-md font-medium hover:bg-blue-800 disabled:opacity-60"
          >
            {busy === 'APPROVE'
              ? <Loader2 size={16} className="animate-spin" aria-hidden="true" />
              : <Check size={16} aria-hidden="true" />}
            Approve
          </button>
        )}
        {canPay && (
          <button
            type="button"
            onClick={() => void act('PAY')}
            disabled={busy !== null}
            className="inline-flex items-center gap-1 bg-green-700 text-white px-4 py-2 rounded-md font-medium hover:bg-green-800 disabled:opacity-60"
          >
            {busy === 'PAY'
              ? <Loader2 size={16} className="animate-spin" aria-hidden="true" />
              : <Banknote size={16} aria-hidden="true" />}
            Mark paid
          </button>
        )}
        {canVoid && (
          <button
            type="button"
            onClick={() => void act('VOID')}
            disabled={busy !== null}
            className="inline-flex items-center gap-1 bg-red-700 text-white px-4 py-2 rounded-md font-medium hover:bg-red-800 disabled:opacity-60"
          >
            {busy === 'VOID'
              ? <Loader2 size={16} className="animate-spin" aria-hidden="true" />
              : <Ban size={16} aria-hidden="true" />}
            Void
          </button>
        )}
        {!canVoid && (
          <p className="text-sm text-gray-600 inline-flex items-center gap-1">
            <Check size={14} aria-hidden="true" /> This reward is settled; no further action.
          </p>
        )}
      </div>
    </article>
  );
}

/**
 * An amount, or an honest statement that there is not one yet.
 *
 * A null amount is a published state, not a missing value: the organisation has
 * not set a figure for the band. It is shown as words so it cannot be mistaken
 * for a payment of nothing.
 */
function formatAmount(reward: Reward): string {
  if (reward.amount === null) {
    return 'Amount not set';
  }

  if (reward.currency) {
    return `${reward.currency} ${reward.amount.toLocaleString()}`;
  }

  return reward.amount.toLocaleString();
}