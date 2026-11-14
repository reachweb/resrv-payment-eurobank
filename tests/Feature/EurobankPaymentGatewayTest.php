<?php

namespace Reach\ResrvPaymentEurobank\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Reach\ResrvPaymentEurobank\Http\Payment\EurobankPaymentGateway;
use Reach\ResrvPaymentEurobank\Tests\TestCase;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Mail\OrphanedPaymentNotification;
use Reach\StatamicResrv\Models\Reservation;

class EurobankPaymentGatewayTest extends TestCase
{
    private const PAY_PAGE = 'https://site.test/pay?ref=ABCDEF&hash=xyz';

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([ReservationConfirmed::class]);
        Mail::fake();
    }

    #[Test]
    public function the_signed_form_sends_the_bank_back_to_the_webhook_route_of_the_gateways_own_config_key(): void
    {
        [, $postData] = $this->startPayment(Reservation::factory()->create());

        $this->assertSame('0101119349', $postData['mid']);
        $this->assertSame('EUR', $postData['currency']);
        $this->assertSame('50.00', $postData['orderAmount']);
        $this->assertSame('http://localhost/resrv/api/webhook/card', $postData['confirmUrl']);
        $this->assertSame('http://localhost/resrv/api/webhook/card', $postData['cancelUrl']);

        // The Cardlink spec signs every field value in order, followed by the shared secret.
        $digest = $postData['digest'];
        unset($postData['digest']);
        $this->assertSame(base64_encode(sha1(implode('', $postData).'Cardlink1', true)), $digest);
    }

    #[Test]
    public function a_successful_callback_confirms_once_and_returns_the_customer_to_the_return_url(): void
    {
        $reservation = Reservation::factory()->create();
        [$orderId] = $this->startPayment($reservation);

        $response = $this->postCallback($orderId, 'CAPTURED')->assertStatus(303);

        $this->assertStringStartsWith(
            self::PAY_PAGE.'&orderid='.$orderId.'&resrv_gateway=card&eurobank_expires=',
            $response->headers->get('Location'),
        );
        $this->assertSame('confirmed', $reservation->fresh()->status);
        Event::assertDispatched(ReservationConfirmed::class, fn ($event) => $event->via === ReservationConfirmed::VIA_WEBHOOK
            && $event->payment === ['gateway' => 'card', 'payment_id' => $orderId]);

        // Cardlink's background confirmation repeats the same message server to server.
        $this->postCallback($orderId, 'CAPTURED', userAgent: 'Modirum VPOS')
            ->assertOk()
            ->assertSee('OK');

        Event::assertDispatchedTimes(ReservationConfirmed::class, 1);
    }

    #[Test]
    public function a_successful_callback_confirms_an_awaiting_payment_reservation(): void
    {
        $reservation = Reservation::factory()->create(['status' => 'awaiting_payment']);
        [$orderId] = $this->startPayment($reservation);

        $this->postCallback($orderId, 'AUTHORIZED')->assertStatus(303);

        $this->assertSame('confirmed', $reservation->fresh()->status);
    }

    #[Test]
    public function a_callback_with_a_wrong_digest_changes_nothing(): void
    {
        $reservation = Reservation::factory()->create();
        [$orderId] = $this->startPayment($reservation);

        $this->postCallback($orderId, 'CAPTURED', tamper: true)
            ->assertStatus(303)
            ->assertRedirect(self::PAY_PAGE.'&orderid='.$orderId.'&resrv_gateway=card');

        $this->postCallback($orderId, 'CAPTURED', userAgent: 'Modirum VPOS', tamper: true)->assertForbidden();

        $this->assertSame('pending', $reservation->fresh()->status);
        Event::assertNotDispatched(ReservationConfirmed::class);
        $this->assertSame('canceled', $this->gateway()->retrievePaymentIntent($orderId, $reservation)->status);
    }

    #[Test]
    public function a_refused_payment_leaves_the_reservation_payable(): void
    {
        $reservation = Reservation::factory()->create();
        [$orderId] = $this->startPayment($reservation);

        $this->postCallback($orderId, 'REFUSED')->assertStatus(303);

        $this->assertSame('pending', $reservation->fresh()->status);
        $this->assertSame($orderId, $reservation->fresh()->payment_id);
    }

    #[Test]
    public function a_cancelled_payment_expires_a_checkout_reservation_but_not_an_awaiting_payment_one(): void
    {
        $checkout = Reservation::factory()->create();
        [$checkoutOrder] = $this->startPayment($checkout);
        $manual = Reservation::factory()->create(['status' => 'awaiting_payment']);
        [$manualOrder] = $this->startPayment($manual);

        $this->postCallback($checkoutOrder, 'CANCELED');
        $this->postCallback($manualOrder, 'CANCELED');

        $this->assertSame('expired', $checkout->fresh()->status);
        $this->assertSame('awaiting_payment', $manual->fresh()->status);
    }

    #[Test]
    public function a_payment_for_an_abandoned_order_does_not_confirm_the_reservation(): void
    {
        $reservation = Reservation::factory()->create();
        [$orderId] = $this->startPayment($reservation);

        // Resrv clears the intent when the customer goes back or switches gateway.
        $reservation->update(['payment_id' => '', 'payment_gateway' => '']);

        $this->postCallback($orderId, 'CAPTURED')->assertStatus(303);

        $this->assertSame('pending', $reservation->fresh()->status);
        Event::assertNotDispatched(ReservationConfirmed::class);
        Mail::assertSent(OrphanedPaymentNotification::class);
    }

    #[Test]
    public function a_payment_for_a_different_amount_does_not_confirm_the_reservation(): void
    {
        $reservation = Reservation::factory()->create();
        [$orderId] = $this->startPayment($reservation);

        $this->postCallback($orderId, 'CAPTURED', amount: '40.00');

        $this->assertSame('pending', $reservation->fresh()->status);
        Event::assertNotDispatched(ReservationConfirmed::class);
        Mail::assertSent(OrphanedPaymentNotification::class);
    }

    #[Test]
    public function a_late_payment_for_an_expired_reservation_notifies_admins_instead_of_confirming(): void
    {
        $reservation = Reservation::factory()->create();
        [$orderId] = $this->startPayment($reservation);
        $reservation->update(['status' => 'expired']);

        $this->postCallback($orderId, 'CAPTURED');

        $this->assertSame('expired', $reservation->fresh()->status);
        Event::assertNotDispatched(ReservationConfirmed::class);
        Mail::assertSent(OrphanedPaymentNotification::class);
    }

    #[Test]
    public function a_reservation_that_cannot_be_confirmed_is_not_reported_confirmed(): void
    {
        $reservation = Reservation::factory()->create();
        [$orderId] = $this->startPayment($reservation);
        $reservation->update(['status' => 'completed']);

        $this->postCallback($orderId, 'CAPTURED');

        $this->assertSame('completed', $reservation->fresh()->status);
        Event::assertNotDispatched(ReservationConfirmed::class);
    }

    #[Test]
    public function callbacks_are_rejected_while_no_secret_is_configured(): void
    {
        config(['resrv-payment-eurobank.secret' => '']);
        $reservation = Reservation::factory()->create();
        [$orderId] = $this->startPayment($reservation);

        $this->postCallback($orderId, 'CAPTURED', secret: '');

        $this->assertSame('pending', $reservation->fresh()->status);
    }

    #[Test]
    public function a_customer_returns_to_the_home_page_when_the_order_was_forgotten_and_there_is_no_checkout_complete_entry(): void
    {
        $reservation = Reservation::factory()->create();
        [$orderId] = $this->startPayment($reservation);
        Cache::flush();

        $response = $this->postCallback($orderId, 'CAPTURED')->assertStatus(303);

        $this->assertStringStartsWith('http://localhost?orderid='.$orderId.'&resrv_gateway=card&', $response->headers->get('Location'));
        $this->assertSame('confirmed', $reservation->fresh()->status);
    }

    #[Test]
    public function retrieve_payment_intent_reports_the_last_verified_bank_status(): void
    {
        $reservation = Reservation::factory()->create(['status' => 'awaiting_payment']);
        $gateway = $this->gateway();

        [$unanswered] = $this->startPayment($reservation);
        $this->assertSame('canceled', $gateway->retrievePaymentIntent($unanswered, $reservation)->status);

        [$refused] = $this->startPayment($reservation);
        $this->postCallback($refused, 'REFUSED');
        $this->assertSame('canceled', $gateway->retrievePaymentIntent($refused, $reservation)->status);

        [$captured] = $this->startPayment($reservation);
        $this->postCallback($captured, 'CAPTURED');
        $this->assertSame('succeeded', $gateway->retrievePaymentIntent($captured, $reservation)->status);

        [$authorized] = $this->startPayment($reservation);
        $this->postCallback($authorized, 'AUTHORIZED');
        $this->assertSame('requires_capture', $gateway->retrievePaymentIntent($authorized, $reservation)->status);

        // A later failure message for the same order must not hide the money that moved.
        $this->postCallback($authorized, 'CANCELED');
        $this->assertSame('requires_capture', $gateway->retrievePaymentIntent($authorized, $reservation)->status);
    }

    #[Test]
    public function the_checkout_complete_page_shows_the_reservation_only_through_a_fresh_link(): void
    {
        $reservation = Reservation::factory()->create();
        [$orderId] = $this->startPayment($reservation);
        $returnLink = $this->postCallback($orderId, 'CAPTURED')->headers->get('Location');

        $result = $this->returnTo($returnLink);
        $this->assertTrue($result['status']);
        $this->assertSame($reservation->id, $result['reservation']['id']);

        // The bare order id, or the link once it has expired, still reports the result but not the reservation.
        $this->assertSame(['status' => true, 'reservation' => []], $this->returnTo('/?orderid='.$orderId));

        $this->travel(61)->minutes();
        $this->assertSame(['status' => true, 'reservation' => []], $this->returnTo($returnLink));
    }

    #[Test]
    public function the_checkout_complete_page_reports_refused_and_unconfirmed_payments(): void
    {
        $refused = Reservation::factory()->create();
        [$refusedOrder] = $this->startPayment($refused);
        $this->assertFalse($this->returnTo($this->postCallback($refusedOrder, 'REFUSED')->headers->get('Location'))['status']);

        // The bank took the money but it didn't confirm the reservation: not reported as failed.
        $mismatched = Reservation::factory()->create();
        [$mismatchedOrder] = $this->startPayment($mismatched);
        $this->assertSame('pending', $this->returnTo($this->postCallback($mismatchedOrder, 'CAPTURED', amount: '40.00')->headers->get('Location'))['status']);

        $this->assertFalse($this->returnTo('/?orderid=R999T20260101000000abcdefgh')['status']);
    }

    private function returnTo(string $url): array
    {
        $this->app->instance('request', Request::create($url));

        return $this->gateway()->handleRedirectBack();
    }

    private function gateway(): EurobankPaymentGateway
    {
        return app(EurobankPaymentGateway::class);
    }

    /** Creates the bank order the way Resrv's pay-by-link page does and stores it on the reservation. */
    private function startPayment(Reservation $reservation): array
    {
        $intent = $this->gateway()->paymentIntent(
            Price::create($reservation->totalToCharge()),
            $reservation,
            collect(['email' => 'customer@example.com']),
            self::PAY_PAGE,
        );

        $reservation->update(['payment_id' => $intent->id, 'payment_gateway' => 'card']);

        return [$intent->id, (array) json_decode($intent->client_secret)->postData];
    }

    /** Posts a callback signed as the Cardlink redirect specification describes. */
    private function postCallback(string $orderId, string $status, string $amount = '50.00', string $userAgent = 'Mozilla/5.0', bool $tamper = false, string $secret = 'Cardlink1')
    {
        $fields = [
            'mid' => '0101119349',
            'orderid' => $orderId,
            'status' => $status,
            'orderAmount' => $amount,
            'currency' => 'EUR',
            'paymentTotal' => $amount,
            // Trailing space and empty value: the digest signs the raw values, before Laravel's
            // TrimStrings and ConvertEmptyStringsToNull middleware rewrite the input.
            'message' => 'OK, 00 - Approved ',
            'riskScore' => '',
            'txId' => '926012471',
        ];
        $fields['digest'] = base64_encode(sha1(implode('', $fields).$secret, true));

        if ($tamper) {
            $fields['orderAmount'] = '0.01';
        }

        return $this->call(
            'POST',
            '/resrv/api/webhook/card',
            $fields,
            [],
            [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_USER_AGENT' => $userAgent],
            http_build_query($fields),
        );
    }
}
