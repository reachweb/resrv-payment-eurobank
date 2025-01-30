<?php

namespace Reach\ResrvPaymentEurobank;

use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    public function boot()
    {
        parent::boot();

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'statamic-resrv');

        $this->publishes([
            __DIR__.'/../resources/views/livewire' => resource_path('views/vendor/statamic-resrv/livewire'),
        ], 'resrv-checkout-views-eurobank');
    }
}
