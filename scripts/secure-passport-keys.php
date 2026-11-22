<?php

use App\Support\PassportKeyPermissions;
use Illuminate\Contracts\Console\Kernel;
use Laravel\Passport\Passport;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$keyPaths = [];
foreach (['private', 'public'] as $type) {
    $configuredPath = config("passport.{$type}_key");
    $keyPaths[] = is_string($configuredPath) && $configuredPath !== ''
        ? $configuredPath
        : Passport::keyPath("oauth-{$type}.key");
}

$secured = app(PassportKeyPermissions::class)->secureExisting($keyPaths);

if ($secured !== []) {
    echo 'Secured permissions for '.count($secured).' existing Passport key file(s).'.PHP_EOL;
}
