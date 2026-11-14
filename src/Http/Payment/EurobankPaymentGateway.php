<?php

namespace Reach\ResrvPaymentEurobank\Http\Payment;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Exceptions\CheckoutEntryNotFound;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Http\Payment\PaymentInterface;
use Reach\StatamicResrv\Livewire\Traits\HandlesStatamicQueries;
use Reach\StatamicResrv\Mail\OrphanedPaymentNotification;
use Reach\StatamicResrv\Models\Reservation;

/**
 * Eurobank (Cardlink / Modirum VPOS) redirect model.
 *
 * The payment view auto-submits a signed POST form to the bank, so to Resrv this is an inline
 * gateway (redirectsForPayment() is false): Resrv's redirect path can only send a GET.
 *
 * The bank POSTs the signed result to confirmUrl / cancelUrl, both pointing at Resrv's
 * per-gateway webhook route, which is already excluded from CSRF protection. verifyPayment()
 * checks the digest, confirms the reservation and then sends the customer's browser on to the
 * return URL Resrv asked for (the checkout-complete entry or the pay-by-link page). Cardlink's
 * optional background confirmation (User-Agent "Modirum VPOS") posts the same message to the
 * same URL and is answered with a plain 200.
 */
class EurobankPaymentGateway implements PaymentInterface
{
    use HandlesStatamicQueries;

    protected const SUCCESS_STATUSES = ['AUTHORIZED', 'CAPTURED'];

    /**
     * How long an order's return URL and verified bank status are kept. They live only in the
     * Laravel cache, so clearing it forgets them (see retrievePaymentIntent()).
     */
    protected const ORDER_CACHE_DAYS = 90;

    /** How long the return link may show the reservation's details on the checkout-complete page. */
    protected const DETAILS_LINK_MINUTES = 60;

    public function name(): string
    {
        return 'eurobank';
    }

    public function label(): string
    {
        return 'Credit / Debit Card';
    }

    public function paymentView(): string
    {
        return 'resrv-payment-eurobank::livewire.checkout-payment-eurobank';
    }

    public function supportsManualConfirmation(): bool
    {
        return false;
    }

    /**
     * Refunds are made in the Eurobank back office: this add-on does not call the bank's API, so
     * refund() only records the refund in Resrv and the admin is told to return the money by hand.
     */
    public function supportsAutomaticRefunds(): bool
    {
        return false;
    }

    /** Confirmation arrives through Resrv's webhook route (see the class docblock). */
    public function supportsWebhooks(): bool
    {
        return true;
    }

    public function redirectsForPayment(): bool
    {
        return false;
    }

    public function paymentIntent($payment, Reservation $reservation, $data, ?string $returnUrl = null)
    {
        // The bank requires a unique order id per request. The reservation id lets a late bank
        // callback find its reservation after Resrv cleared payment_id (stale-intent fallback).
        $orderId = 'R'.$reservation->id.'T'.now()->format('YmdHis').Str::random(8);

        // Where verifyPayment() sends the customer after the bank answers. Normal checkout passes
        // the checkout-complete entry, the pay-by-link page its own authenticated URL; null falls
        // back to the checkout-complete entry when the customer returns.
        Cache::put($this->orderCacheKey($orderId), [
            'return_url' => $returnUrl,
            'status' => null,
        ], now()->addDays(static::ORDER_CACHE_DAYS));

        $paymentIntent = new \stdClass;
        $paymentIntent->id = $orderId;
        $paymentIntent->client_secret = json_encode([
            'postData' => $this->prepareBankData($payment, $reservation, $data, $orderId),
            'redirectUrl' => config('resrv-payment-eurobank.redirect_url'),
        ]);

        return $paymentIntent;
    }

    protected function trType(): string
    {
        return '2';
    }

    protected function payMethod(): ?string
    {
        return null;
    }

    private function prepareBankData($payment, Reservation $reservation, $data, string $orderId): array
    {
        $callbackUrl = route('resrv.webhook.gateway.store', ['gateway' => $this->configKey()]);
        $title = (string) data_get($reservation->entry(), 'title', '');

        $bankData = [
            'mid' => config('resrv-payment-eurobank.mid') ?? '',
            'lang' => 'en',
            'orderid' => $orderId,
            'orderDesc' => mb_substr($title, 0, 128),
            'orderAmount' => $payment->format(),
            'currency' => config('resrv-config.currency_isoCode'),
            'payerEmail' => $data->get('email'),
            'payMethod' => $this->payMethod() ?? '',
            'trType' => $this->trType(),
            'confirmUrl' => $callbackUrl,
            'cancelUrl' => $callbackUrl,
            'var1' => mb_substr($title, 0, 255),
        ];

        return array_merge($bankData, ['digest' => $this->digest($bankData)]);
    }

    /**
     * Resrv reads this to resume, void-check, reconcile or refund a stored order. The bank is not
     * queried: the status comes from the last signed callback verifyPayment() recorded.
     *
     * An order the bank never answered for is reported 'canceled', so Resrv mints a new order id
     * instead of re-posting one the bank rejects as a duplicate. If the customer still completes
     * that older order in another tab, its callback reaches verifyPayment() as a stale intent and
     * admins are notified to refund it.
     *
     * The recorded status lives only in the Laravel cache. Once the cache is cleared, an order the
     * bank captured also reads 'canceled'.
     */
    public function retrievePaymentIntent(string $paymentId, Reservation $reservation): ?object
    {
        $intent = new \stdClass;
        $intent->id = $paymentId;
        $intent->status = match ($this->bankStatus($paymentId)) {
            'CAPTURED' => 'succeeded',
            // trType 2 is a pre-authorization: the amount is held until captured in the back office.
            'AUTHORIZED' => 'requires_capture',
            default => 'canceled',
        };
        // A posted order can't be resumed, so there is nothing to re-mount.
        $intent->client_secret = '';
        $intent->redirectTo = null;

        return $intent;
    }

    /** The redirect model has no void call: an order the customer never completed dies at the bank. */
    public function cancelPaymentIntent(string $paymentId, Reservation $reservation): void
    {
        Log::info('Eurobank cancelPaymentIntent called (no-op)', [
            'payment_id' => $paymentId,
            'reservation_id' => $reservation->id,
            'gateway' => $this->name(),
        ]);
    }

    public function refund($reservation)
    {
        // Bookkeeping only (see supportsAutomaticRefunds()): the money is returned in the
        // Eurobank back office.
        return true;
    }

    public function getPublicKey(Reservation $reservation)
    {
        return '';
    }

    public function getSecretKey(Reservation $reservation) {}

    public function getWebhookSecret(Reservation $reservation) {}

    /**
     * Called on the checkout-complete page after verifyPayment() redirected the customer there.
     * verifyPayment() already acted on the bank's answer, so the reservation status is the result.
     */
    public function handleRedirectBack(): array
    {
        if ($pending = $this->handlePaymentPending()) {
            return $pending;
        }

        $orderId = request()->query('orderid');

        if (! is_string($orderId) || $orderId === '') {
            return [
                'status' => false,
                'reservation' => [],
            ];
        }

        $reservation = Reservation::findByPaymentId($orderId)->first();

        if ($reservation && in_array($reservation->status, ReservationStatus::live(), true)) {
            $status = true;
        } elseif (in_array($this->bankStatus($orderId), static::SUCCESS_STATUSES, true)) {
            // The bank took the money but it didn't confirm the reservation (an older order the
            // customer had moved on from, or a different amount). Admins were notified, so don't
            // tell the customer the payment failed and invite them to pay again.
            $status = 'pending';
        } else {
            $status = false;
        }

        return [
            'status' => $status,
            // Only a fresh link from the bank's callback shows the reservation: the URL ends up in
            // browser history, logs and analytics.
            'reservation' => $reservation && $this->detailsLinkIsValid($orderId) ? $reservation->toArray() : [],
        ];
    }

    public function handlePaymentPending(): bool|array
    {
        if (! request()->has('payment_pending')) {
            return false;
        }

        $reservation = Reservation::find(request()->input('payment_pending'));

        return [
            'status' => 'pending',
            'reservation' => $reservation ? $reservation->toArray() : [],
        ];
    }

    /** Handles the bank's POST to confirmUrl / cancelUrl (Resrv's webhook route). */
    public function verifyPayment($request)
    {
        $fields = $this->callbackFields($request);
        $orderId = is_string($fields['orderid'] ?? null) ? $fields['orderid'] : null;

        if (! $this->callbackDigestIsValid($fields)) {
            Log::error('Eurobank callback digest verification failed', [
                'orderid' => $orderId,
                'status' => $fields['status'] ?? null,
            ]);

            if ($this->isBackgroundConfirmation($request)) {
                abort(403);
            }

            return $this->redirectCustomer($orderId, showDetails: false);
        }

        $this->processCallback($orderId, (string) ($fields['status'] ?? ''), $fields);

        if ($this->isBackgroundConfirmation($request)) {
            return response('OK', 200);
        }

        return $this->redirectCustomer($orderId, showDetails: true);
    }

    protected function processCallback(?string $orderId, string $bankStatus, array $fields): void
    {
        if ($orderId === null || $orderId === '') {
            return;
        }

        $this->recordBankStatus($orderId, $bankStatus);

        $reservation = Reservation::findByPaymentId($orderId)->first();

        // Resrv clears payment_id before cancelPaymentIntent() (back button, gateway switch), so a
        // late callback can only find its reservation through the id in the order id.
        $isStaleIntent = false;
        if (! $reservation && preg_match('/^R(\d+)T/', $orderId, $matches)) {
            $reservation = Reservation::find($matches[1]);
            $isStaleIntent = (bool) $reservation;
        }

        if (! $reservation) {
            Log::info('Eurobank callback: no reservation found for order '.$orderId);

            return;
        }

        $context = [
            'reservation_id' => $reservation->id,
            'orderid' => $orderId,
            'bank_status' => $bankStatus,
        ];

        if (! in_array($bankStatus, static::SUCCESS_STATUSES, true)) {
            // REFUSED / ERROR: a failed attempt is retryable, so the reservation stays as it is.
            // CANCELED (the customer gave up on the bank page) ends a checkout reservation now
            // instead of waiting for ExpireReservations; expire() no-ops unless it is PENDING,
            // so awaiting-payment (pay-by-link) reservations stay payable.
            Log::info('Eurobank callback: payment not successful', $context);

            if ($bankStatus === 'CANCELED' && ! $isStaleIntent) {
                $reservation->expire();
            }

            return;
        }

        if ($isStaleIntent) {
            // The customer moved on before this order completed: confirming would attach the
            // charge to an amount or gateway the reservation no longer uses.
            Log::warning('Eurobank payment succeeded after being abandoned by the customer — manual reconciliation may be required.', $context + [
                'current_payment_id' => $reservation->payment_id,
                'current_payment_gateway' => $reservation->payment_gateway,
            ]);

            OrphanedPaymentNotification::dispatchFor($reservation, $orderId);

            return;
        }

        // Background confirmation repeats the browser's message.
        if ($reservation->status === ReservationStatus::CONFIRMED->value) {
            return;
        }

        if (OrphanedPaymentNotification::notifyIfOrphaned($reservation, $orderId)) {
            return;
        }

        if (! $this->amountMatches($reservation, $fields)) {
            Log::warning('Eurobank paid amount does not match the reservation total; not confirming.', $context + [
                'amount_paid' => ($fields['currency'] ?? '').' '.($fields['orderAmount'] ?? ''),
                'expected_amount' => config('resrv-config.currency_isoCode').' '.$reservation->totalToCharge(),
            ]);

            OrphanedPaymentNotification::dispatchFor($reservation, $orderId);

            return;
        }

        // Confirms PENDING (checkout) and AWAITING_PAYMENT (pay-by-link) reservations alike.
        if ($reservation->transitionTo(ReservationStatus::CONFIRMED, tolerant: true)) {
            ReservationConfirmed::dispatch($reservation, ReservationConfirmed::VIA_WEBHOOK, [
                'gateway' => $reservation->payment_gateway ?: $this->name(),
                'payment_id' => $orderId,
            ]);

            return;
        }

        // Lost the confirm race (e.g. ExpireReservations or the hold-lapse sweep changed the row
        // under the lock): the charge may now be orphaned, so surface it for a manual refund.
        $reservation->refresh();
        OrphanedPaymentNotification::notifyIfOrphaned($reservation, $orderId);
    }

    public function verifyWebhook()
    {
        return true;
    }

    /**
     * The POSTed fields in the order the bank sent them, read from the raw body: the digest signs
     * the exact values, and Laravel's TrimStrings / ConvertEmptyStringsToNull middleware rewrite
     * the parsed input.
     */
    protected function callbackFields(Request $request): array
    {
        $body = $request->getContent();

        if ($body === '') {
            return $request->request->all();
        }

        parse_str($body, $fields);

        return $fields;
    }

    protected function callbackDigestIsValid(array $fields): bool
    {
        $received = $fields['digest'] ?? null;
        unset($fields['digest']);

        if (! is_string($received) || $received === '' || blank(config('resrv-payment-eurobank.secret'))) {
            return false;
        }

        foreach ($fields as $value) {
            if (! is_scalar($value)) {
                return false;
            }
        }

        return hash_equals($this->digest($fields), $received);
    }

    /** Field values concatenated in order, then the shared secret: base64(sha1(...)). */
    protected function digest(array $fields): string
    {
        return base64_encode(sha1(implode('', $fields).config('resrv-payment-eurobank.secret'), true));
    }

    protected function amountMatches(Reservation $reservation, array $fields): bool
    {
        if (($fields['currency'] ?? null) !== config('resrv-config.currency_isoCode')) {
            return false;
        }

        try {
            return Price::create((string) ($fields['orderAmount'] ?? ''))->equals(Price::create($reservation->totalToCharge()));
        } catch (\Throwable) {
            return false;
        }
    }

    protected function isBackgroundConfirmation(Request $request): bool
    {
        return str_contains((string) $request->userAgent(), 'Modirum VPOS');
    }

    protected function bankStatus(string $orderId): ?string
    {
        return Cache::get($this->orderCacheKey($orderId))['status'] ?? null;
    }

    protected function recordBankStatus(string $orderId, string $bankStatus): void
    {
        $key = $this->orderCacheKey($orderId);
        $order = Cache::get($key, []);

        // Never let a later message downgrade a recorded success.
        if (in_array($order['status'] ?? null, static::SUCCESS_STATUSES, true)) {
            return;
        }

        $order['status'] = $bankStatus;

        Cache::put($key, $order, now()->addDays(static::ORDER_CACHE_DAYS));
    }

    /**
     * 303 so the browser follows the bank's POST with a GET to the return page. $showDetails signs
     * the link so handleRedirectBack() may show the reservation for a while; only a callback with a
     * valid digest gets one.
     */
    protected function redirectCustomer(?string $orderId, bool $showDetails)
    {
        $order = $orderId ? Cache::get($this->orderCacheKey($orderId), []) : [];
        $base = $order['return_url'] ?? $this->checkoutCompleteUrl();

        $query = [
            'orderid' => $orderId,
            'resrv_gateway' => $this->configKey(),
        ];

        if ($orderId && $showDetails) {
            $expires = (string) now()->addMinutes(static::DETAILS_LINK_MINUTES)->getTimestamp();
            $query['eurobank_expires'] = $expires;
            $query['eurobank_signature'] = $this->detailsLinkSignature($orderId, $expires);
        }

        // The pay-by-link base already carries ?ref=…&hash=….
        $separator = str_contains($base, '?') ? '&' : '?';

        return redirect()->away($base.$separator.http_build_query(array_filter($query)), 303);
    }

    /** Falls back to the home page when no checkout-complete entry is configured (pay-by-link only sites). */
    protected function checkoutCompleteUrl(): string
    {
        try {
            return $this->getCheckoutCompleteEntry()->absoluteUrl();
        } catch (CheckoutEntryNotFound|\TypeError) {
            return url('/');
        }
    }

    protected function detailsLinkIsValid(string $orderId): bool
    {
        $expires = request()->query('eurobank_expires');
        $signature = request()->query('eurobank_signature');

        return is_string($expires) && ctype_digit($expires) && (int) $expires >= now()->getTimestamp()
            && is_string($signature) && hash_equals($this->detailsLinkSignature($orderId, $expires), $signature);
    }

    protected function detailsLinkSignature(string $orderId, string $expires): string
    {
        return hash_hmac('sha256', $orderId.'|'.$expires, (string) config('app.key'));
    }

    protected function orderCacheKey(string $orderId): string
    {
        return 'resrv-eurobank-order:'.$orderId;
    }

    /**
     * The key this gateway is registered under in resrv-config.payment_gateways. The callback URL
     * is built in paymentIntent(), before Resrv stores reservation.payment_gateway. The exact class
     * is compared because the IRIS gateway extends this one.
     */
    protected function configKey(): string
    {
        foreach (app(PaymentGatewayManager::class)->all() as $key => $gateway) {
            if (get_class($gateway) === static::class) {
                return $key;
            }
        }

        return $this->name();
    }
}
