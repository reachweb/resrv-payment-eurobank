<?php

namespace Reach\ResrvPaymentEurobank\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\LivewireServiceProvider;
use MarcoRieser\Livewire\ServiceProvider as StatamicLivewireServiceProvider;
use Reach\ResrvPaymentEurobank\Http\Payment\EurobankIrisPaymentGateway;
use Reach\ResrvPaymentEurobank\Http\Payment\EurobankPaymentGateway;
use Reach\ResrvPaymentEurobank\ServiceProvider;
use Reach\StatamicResrv\Providers\ResrvProvider;
use Reach\StatamicResrv\StatamicResrvServiceProvider;
use Statamic\Addons\Manifest;
use Statamic\Testing\AddonTestCase;

abstract class TestCase extends AddonTestCase
{
    use RefreshDatabase;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected function getPackageProviders($app)
    {
        return array_merge(parent::getPackageProviders($app), [
            LivewireServiceProvider::class,
            StatamicLivewireServiceProvider::class,
            StatamicResrvServiceProvider::class,
        ]);
    }

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        // Resrv is an addon too: list it so Statamic boots it and registers its webhook routes.
        $app->make(Manifest::class)->manifest['reachweb/statamic-resrv'] = [
            'id' => 'reachweb/statamic-resrv',
            'slug' => null,
            'version' => '6.5.0',
            'namespace' => 'Reach\\StatamicResrv',
            'autoload' => 'src',
            'provider' => ResrvProvider::class,
        ];

        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('resrv-config.currency_isoCode', 'EUR');

        // The card gateway is registered second so the config-key lookup can't match the IRIS
        // subclass by instanceof.
        $app['config']->set('resrv-config.payment_gateways', [
            'iris' => ['class' => EurobankIrisPaymentGateway::class],
            'card' => ['class' => EurobankPaymentGateway::class],
        ]);

        $app['config']->set('resrv-payment-eurobank', [
            'redirect_url' => 'https://eurocommerce-test.cardlink.gr/vpos/shophandlermpi',
            'mid' => '0101119349',
            'secret' => 'Cardlink1',
        ]);
    }
}
