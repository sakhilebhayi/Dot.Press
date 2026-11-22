<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HealthCheckServiceProvider;
use App\Providers\JetstreamServiceProvider;
use App\Providers\PressExportServiceProvider;
use Dot\SsoClient\DotSsoServiceProvider;

return [
    AppServiceProvider::class,
    HealthCheckServiceProvider::class,
    FortifyServiceProvider::class,
    JetstreamServiceProvider::class,
    PressExportServiceProvider::class,
    DotSsoServiceProvider::class,
];
