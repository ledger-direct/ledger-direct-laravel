<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Status;

use Hardcastle\LedgerDirect\Core\LedgerDirect;
use Hardcastle\LedgerDirect\Core\LedgerDirectException;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use Hardcastle\LedgerDirect\Laravel\Contracts\PaymentIntentStore;
use Hardcastle\LedgerDirect\Laravel\Events\PaymentPartiallyReceived;
use Hardcastle\LedgerDirect\Laravel\Events\PaymentReceivedInWrongAsset;
use Hardcastle\LedgerDirect\Laravel\Events\PaymentSettled;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * The work behind a payment-status endpoint, without the route: sync the
 * receiving account (throttled), match the intent, derive the status.
 *
 * The route, its secret and the `redirect` URL belong to the shop — see the
 * README for a signed-URL example. INVARIANTS.md ("Payment status") is the
 * contract this follows.
 *
 * Throttling: at most one node request per receiving account and network
 * every PaymentStatus::MIN_SYNC_INTERVAL_SECONDS, across every waiting
 * customer. The mark is set *before* the request, not after it as
 * SyncThrottle::syncIfDue() does: a node that hangs for the full timeout
 * would otherwise be hit again by every poll that arrives meanwhile. The
 * same reasoning, found in the PrestaShop module, is why this does not use
 * syncIfDue().
 */
final class StatusResponder
{
    public function __construct(
        private readonly LedgerDirect $ledgerDirect,
        private readonly Dispatcher $events,
    ) {
    }

    /**
     * Brings an order's stored intent up to date and answers with its status.
     *
     * Persisting the result is the caller's job: if StatusResult::changed(),
     * save $result->intent. Events have already been dispatched by then —
     * by every call that sees the change, so two concurrent polls for the
     * same order dispatch twice. respondFor() does not; prefer it.
     *
     * @param string|null $orderRef passed through to the events, so a listener can find its order
     */
    public function respond(PaymentIntent $intent, ?string $orderRef = null): StatusResult
    {
        $result = $this->resolve($intent);
        $this->dispatchOnStateChange($result, $orderRef);

        return $result;
    }

    /**
     * respond() with the loading and saving done: finds the order's intent
     * in $store and stores the new one with a compare-and-set before any
     * event is dispatched, so a listener that reads the store sees what the
     * event says — and only the request that wins the compare-and-set
     * dispatches. A concurrent poll that matched the same payment loses it
     * and answers from what the winner stored, without an event.
     *
     * @return StatusResult|null null when the store has no intent for $orderRef
     */
    public function respondFor(string $orderRef, PaymentIntentStore $store): ?StatusResult
    {
        $intent = $store->find($orderRef);

        if ($intent === null) {
            return null;
        }

        $result = $this->resolve($intent);

        if ($result->changed() && !$store->replace($orderRef, $intent, $result->intent)) {
            $current = $store->find($orderRef) ?? $result->intent;

            return new StatusResult($current, $current, $this->ledgerDirect->paymentStatus($current), $result->synced);
        }

        $this->dispatchOnStateChange($result, $orderRef);

        return $result;
    }

    private function resolve(PaymentIntent $intent): StatusResult
    {
        // Settled is terminal: nothing on the ledger can change the answer.
        if ($this->ledgerDirect->settlementPolicy()->isSettled($intent)) {
            return new StatusResult($intent, $intent, $this->ledgerDirect->paymentStatus($intent), false);
        }

        $synced = $this->syncThrottled($intent->destinationAccount, $intent->network);

        $fulfillment = $this->ledgerDirect->syncService()->findFulfillmentFor($intent);
        $matched = $fulfillment?->applyTo($intent) ?? $intent;

        return new StatusResult($matched, $intent, $this->ledgerDirect->paymentStatus($matched), $synced);
    }

    /**
     * Syncs the account the *intent* names, not the one configured now: an
     * order placed before the merchant switched accounts still waits on the
     * old one.
     *
     * A node failure is logged and swallowed. The endpoint then answers from
     * what is already stored, in the same shape — a customer's payment page
     * must not break because the node had a bad second.
     */
    private function syncThrottled(string $account, string $network): bool
    {
        $throttle = $this->ledgerDirect->syncThrottle();

        if ($throttle !== null) {
            if (!$throttle->shouldSync($network, $account)) {
                return false;
            }

            $throttle->markSynced($network, $account);
        }

        try {
            $this->ledgerDirect->syncService()->syncTransactions($account, $network);
        } catch (LedgerDirectException $exception) {
            $this->ledgerDirect->logger()->warning('LedgerDirect: status sync failed, answering from stored transactions', [
                'account' => $account,
                'network' => $network,
                'exception' => $exception->getMessage(),
            ]);
        }

        return true;
    }

    /**
     * Both states are judged at the same instant, so the passage of time
     * alone (waiting → expired) never fires anything; only a payment does.
     */
    private function dispatchOnStateChange(StatusResult $result, ?string $orderRef): void
    {
        $previousState = $this->ledgerDirect->paymentStatus($result->previousIntent)->state();
        $state = $result->status->state();

        if ($state === $previousState) {
            return;
        }

        $event = match ($state) {
            PaymentStatus::SETTLED => PaymentSettled::class,
            PaymentStatus::PARTIAL => PaymentPartiallyReceived::class,
            PaymentStatus::WRONG_ASSET => PaymentReceivedInWrongAsset::class,
            default => null,
        };

        if ($event !== null) {
            $this->events->dispatch(new $event($orderRef, $result->intent, $result->previousIntent, $result->status, $previousState));
        }
    }
}
