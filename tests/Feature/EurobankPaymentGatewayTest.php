<?php

namespace Reach\ResrvPaymentEurobank\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Reach\ResrvPaymentEurobank\Http\Payment\EurobankPaymentGateway;
use Reach\ResrvPaymentEurobank\Tests\TestCase;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Facades\Price;
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
    public function the_bank_posts_back_to_the_webhook_route_of_the_gateways_own_config_key(): void
    {
        [, $postData] = $this->startPayment(Reservation::factory()->create());

        $this->assertSame('http://localhost/resrv/api/webhook/card', $postData['confirmUrl']);
        $this->assertSame('http://localhost/resrv/api/webhook/card', $postData['cancelUrl']);
        $this->assertSame('50.00', $postData['orderAmount']);
    }

    #[Test]
    public function a_successful_callback_confirms_once_and_returns_the_customer_to_the_return_url(): void
    {
        $reservation = Reservation::factory()->create();
        [$orderId] = $this->startPayment($reservation);

        $this->postCallback($orderId, 'CAPTURED')
            ->assertStatus(303)
            ->assertRedirect(self::PAY_PAGE.'&orderid='.$orderId.'&resrv_gateway=card');

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
    }

    #[Test]
    public function a_payment_for_a_different_amount_does_not_confirm_the_reservation(): void
    {
        $reservation = Reservation::factory()->create();
        [$orderId] = $this->startPayment($reservation);

        $this->postCallback($orderId, 'CAPTURED', amount: '40.00');

        $this->assertSame('pending', $reservation->fresh()->status);
        Event::assertNotDispatched(ReservationConfirmed::class);
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

        [$authorized] = $this->startPayment($reservation);
        $this->postCallback($authorized, 'AUTHORIZED');
        $this->assertSame('requires_capture', $gateway->retrievePaymentIntent($authorized, $reservation)->status);

        // A later failure message for the same order must not hide the money that moved.
        $this->postCallback($authorized, 'CANCELED');
        $this->assertSame('requires_capture', $gateway->retrievePaymentIntent($authorized, $reservation)->status);
    }

    #[Test]
    public function the_checkout_complete_page_reports_the_reservation_status(): void
    {
        $reservation = Reservation::factory()->create();
        [$orderId] = $this->startPayment($reservation);
        $this->postCallback($orderId, 'CAPTURED');

        $this->get('/?orderid='.$orderId);
        $this->assertTrue($this->gateway()->handleRedirectBack()['status']);

        $this->get('/?orderid=R999T20260101000000abcdefgh');
        $this->assertFalse($this->gateway()->handleRedirectBack()['status']);
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
    private function postCallback(string $orderId, string $status, string $amount = '50.00', string $userAgent = 'Mozilla/5.0', bool $tamper = false)
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
        $fields['digest'] = base64_encode(sha1(implode('', $fields).'Cardlink1', true));

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
