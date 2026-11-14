<?php

return [

    // The Eurobank (Cardlink) payment page the checkout form posts to.
    'redirect_url' => env('EUROBANK_REDIRECT_URL'),

    // Your merchant ID.
    'mid' => env('EUROBANK_MID'),

    // The shared secret used to sign requests and verify the bank's responses.
    'secret' => env('EUROBANK_SECRET'),

];
