<?php

namespace Reach\ResrvPaymentEurobank\Http\Payment;

use Reach\StatamicResrv\Models\Reservation;

class EurobankIrisPaymentGateway extends EurobankPaymentGateway
{
    public function name(): string
    {
        return 'eurobank-iris';
    }

    public function label(): string
    {
        return 'IRIS';
    }

    protected function trType(): string
    {
        return '1';
    }

    protected function payMethod(): ?string
    {
        return 'IRIS';
    }
}
