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

    private function prepareBankData($payment, $reservation, $data, $orderId): array 
    {
        $bankData = [
            'mid' => env('EUROBANK_MID') ?? '',
            'lang' => 'en',
            'orderid' => $orderId,
            'orderDesc' => $reservation->entry()->title,
            'orderAmount' => $payment->format(),
            'currency' => config('resrv-config.currency_isoCode'),
            'payerEmail' => $data->get('email'),
            'trType' => '2',
            'confirmUrl' => $this->getCheckoutCompleteEntry()->absoluteUrl(),
            'cancelUrl' => $this->getCheckoutCompleteEntry()->absoluteUrl(),
            'var1' => $reservation->entry()->title,
        ];

        $digestData = implode('', $bankData) . env('EUROBANK_SECRET');
        $digest = base64_encode(sha1($digestData, true));
        
        return array_merge($bankData, ['digest' => $digest]);
    }

    public function refund($reservation)
    {
        throw new RefundFailedException('Refund not supported by Eurobank API.');

        return false;
    }

    public function getPublicKey($reservation) {}

    public function supportsWebhooks(): bool
    {
        return false;
    }

    public function redirectsForPayment(): bool
    {
        return false;
    }

    public function handleRedirectBack(): array
    {
        $request = request();
        Log::info('Payment callback received', $request->all());

        $reservation = Reservation::findByPaymentId($request->orderid)->first();

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
        if ($reservation->status === ReservationStatus::CONFIRMED) {
            return [
                'status' => true,
                'reservation' => $reservation ? $reservation->toArray() : [],
            ];
        }

        $newStatus = $this->mapStatus($request->status);

        if ($reservation->status === ReservationStatus::CONFIRMED || $newStatus === 'completed') {
            // Process successful payment
            ReservationConfirmed::dispatch($reservation);

            return [
                'status' => true,
                'reservation' => $reservation ? $reservation->toArray() : [],
            ];
        } else {
            // Handle failed payment
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
        unset($allData['digest']);
        return $allData;
    }

    public function verifyWebhook()
    {
        return false;
    }
}
