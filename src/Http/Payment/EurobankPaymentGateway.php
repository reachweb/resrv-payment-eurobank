<?php

namespace Reach\ResrvPaymentEurobank\Http\Payment;

use Illuminate\Support\Facades\Log;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\ReservationCancelled;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Exceptions\RefundFailedException;
use Reach\StatamicResrv\Http\Payment\PaymentInterface;
use Reach\StatamicResrv\Livewire\Traits\HandlesStatamicQueries;
use Reach\StatamicResrv\Models\Reservation;

class EurobankPaymentGateway implements PaymentInterface
{
    use HandlesStatamicQueries;

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

    public function paymentIntent($payment, Reservation $reservation, $data)
    {
        $orderId = date('YmdHis');

        $paymentIntent = new \stdClass;
        $paymentIntent->id = $orderId;
        $paymentIntent->client_secret = json_encode([
            'postData' =>  $this->prepareBankData($payment, $reservation, $data, $orderId),
            'redirectUrl' => env('EUROBANK_REDIRECT_URL'),
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

    private function prepareBankData($payment, $reservation, $data, $orderId): array
    {
        $callbackUrl = $this->getCheckoutCompleteEntry()->absoluteUrl();
        $callbackUrl .= (str_contains($callbackUrl, '?') ? '&' : '?').'resrv_gateway='.$this->name();

        $bankData = [
            'mid' => env('EUROBANK_MID') ?? '',
            'lang' => 'en',
            'orderid' => $orderId,
            'orderDesc' => $reservation->entry()->title,
            'orderAmount' => $payment->format(),
            'currency' => config('resrv-config.currency_isoCode'),
            'payerEmail' => $data->get('email'),
            'trType' => $this->trType(),
            'confirmUrl' => $callbackUrl,
            'cancelUrl' => $callbackUrl,
            'var1' => $reservation->entry()->title,
        ];

        $payMethod = $this->payMethod();
        if ($payMethod !== null) {
            $bankData['payMethod'] = $payMethod;
        }

        $digestData = implode('', $bankData) . env('EUROBANK_SECRET');
        $digest = base64_encode(sha1($digestData, true));

        return array_merge($bankData, ['digest' => $digest]);
    }

    public function refund($reservation)
    {
        throw new RefundFailedException('Refund not supported by Eurobank API.');

        return false;
    }

    public function supportsWebhooks(): bool
    {
        return false;
    }

    public function redirectsForPayment(): bool
    {
        return false;
    }

    public function getPublicKey(Reservation $reservation)
    {
        return '';
    }

    public function getSecretKey(Reservation $reservation) {}

    public function getWebhookSecret(Reservation $reservation) {}

    public function handleRedirectBack(): array
    {
        if ($pending = $this->handlePaymentPending()) {
            return $pending;
        }

        $request = request();
        Log::info('Payment callback received', $request->all());

        $reservation = Reservation::with('customer')->findByPaymentId($request->orderid)->first();

        if (! $reservation) {
            Log::info('Reservation not found for id '.$request->orderid);

            return [
                'status' => false,
                'reservation' => [],
            ];
        }

        // Verify the payment details
        if (! $this->verifyPayment($request)) {
            Log::error('Payment verification failed', $request->all());
            return [
                'status' => false,
                'reservation' => [],
            ];
        }

        // If already confirmed, return
        if ($reservation->status === ReservationStatus::CONFIRMED->value) {
            return [
                'status' => true,
                'reservation' => $reservation ? $reservation->toArray() : [],
            ];
        }

        $newStatus = $this->mapStatus($request->status);

        if ($reservation->status === ReservationStatus::CONFIRMED->value || $newStatus === 'completed') {
            // Process successful payment
            Log::info('Reservation confirmed', $reservation->toArray());
            ReservationConfirmed::dispatch($reservation);
            
            return [
                'status' => true,
                'reservation' => $reservation ? $reservation->toArray() : [],
            ];
        } else {
            // Handle failed payment
            Log::info('Reservation cancelled', $reservation->toArray());
            ReservationCancelled::dispatch($reservation);

            return [
                'status' => false,
                'reservation' => $reservation ? $reservation->toArray() : [],
            ];
        }

    }

    public function handlePaymentPending(): bool|array
    {
        return false;
    }

    private function mapStatus($status)
    {
        switch ($status) {
            case 'AUTHORIZED':
            case 'CAPTURED':
                return 'completed';
            case 'CANCELED':
                return 'canceled';
            case 'REFUSED':
                return 'refused';
            case 'ERROR':
                return 'error';
            default:
                return 'unknown';
        }
    }

    public function verifyPayment($request)
    {
        $digestData = implode('', $this->getBankData($request)) . env('EUROBANK_SECRET');
        $digest = base64_encode(sha1($digestData, true));

        return $request->digest === $digest;
    }

    private function getBankData($request)
    {
        $allData = $request->all();
        unset($allData['digest'], $allData['resrv_gateway']);

        return $allData;
    }

    public function verifyWebhook()
    {
        return false;
    }
}
