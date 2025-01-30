# Resrv Payment Gateway for Eurobank (Greece)

This add-on adds a Eurobank payment gateway to [Statamic Resrv](https://github.com/reachweb/statamic-resrv).

## Documentation

Please refer to the [Resrv documentation](https://resrv.eu) to learn more about how to install and configure this add-on.

Besides standard configuration, you need to set the following env variables:

- `EUROBANK_REDIRECT_URL`: The URL that the Resrv will use to redirect for payment.
- `EUROBANK_MID`: Your merchant ID.
- `EUROBANK_SECRET`: Your secret code.

You also need to add our `Payment Completed` URL in the `App\Http\Middleware\VerifyCsrfToken` `except` array for the POST request from Eurobank to work.
