<?php

namespace Reach\ResrvPaymentEurobank;

use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    public function register()
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/resrv-payment-eurobank.php', 'resrv-payment-eurobank');
    }

    public function boot()
    {
        parent::boot();

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'resrv-payment-eurobank');

        $this->publishes([
            __DIR__.'/../resources/views/livewire' => resource_path('views/vendor/resrv-payment-eurobank/livewire'),
        ], 'resrv-checkout-views-eurobank');

        $this->publishes([
            __DIR__.'/../config/resrv-payment-eurobank.php' => config_path('resrv-payment-eurobank.php'),
        ], 'resrv-payment-eurobank-config');
    }
}
