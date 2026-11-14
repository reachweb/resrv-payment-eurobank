<?php

namespace Reach\ResrvPaymentEurobank;

use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    public function boot()
    {
        parent::boot();

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'resrv-payment-eurobank');

        $this->publishes([
            __DIR__.'/../resources/views/livewire' => resource_path('views/vendor/resrv-payment-eurobank/livewire'),
        ], 'resrv-checkout-views-eurobank');
    }
}
