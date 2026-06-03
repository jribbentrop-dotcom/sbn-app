<?php

use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    App\Providers\LLMServiceProvider::class,
    App\Providers\PaymentProviderServiceProvider::class,
];
