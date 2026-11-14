# Resrv Payment Gateway for Eurobank (Greece)

This add-on adds a Eurobank payment gateway to [Statamic Resrv](https://github.com/reachweb/statamic-resrv).

Requires Resrv 6.5 or later on Statamic 6 and PHP 8.4.

## Documentation

Please refer to the [Resrv documentation](https://resrv.eu) to learn more about how to install and configure this add-on.

Besides standard configuration, you need to set the following env variables:

- `EUROBANK_REDIRECT_URL`: The URL that the Resrv will use to redirect for payment.
- `EUROBANK_MID`: Your merchant ID.
- `EUROBANK_SECRET`: Your secret code.

They are read in `config/resrv-payment-eurobank.php`, so they keep working after `php artisan config:cache`. To change that file, publish it with `php artisan vendor:publish --tag=resrv-payment-eurobank-config`.

Register the gateways in `config/resrv-config.php`:

```php
'payment_gateways' => [
    'eurobank' => [
        'class' => \Reach\ResrvPaymentEurobank\Http\Payment\EurobankPaymentGateway::class,
    ],
    'eurobank-iris' => [
        'class' => \Reach\ResrvPaymentEurobank\Http\Payment\EurobankIrisPaymentGateway::class,
    ],
],
```

### How payments are confirmed

The add-on sends Eurobank Resrv's webhook URL for the gateway, `https://yoursite.com/resrv/api/webhook/{config key}`, as the order's confirm and cancel URL. Eurobank posts the payment result there, and the add-on checks its digest, confirms the reservation and sends the customer on to the checkout-complete page, or to the payment page of a manual reservation. Resrv already excludes these routes from CSRF protection, so no CSRF exception is needed any more. You can remove the one earlier versions asked for.

Ask Eurobank / Cardlink to enable **background confirmation**. It sends a copy of the result straight to the same URL, so a reservation is still confirmed when the customer's browser never makes it back from the bank.

### Refunds

Refunds are made in the Eurobank back office. Refunding a Eurobank reservation in the Resrv control panel marks it refunded and reminds you to return the money yourself, and customers cannot cancel these reservations online for an automatic refund.

### Upgrading from the Resrv 3 version

Payments started before the upgrade still post back to the checkout-complete page and will not confirm their reservation, so deploy when no payment is in progress.
