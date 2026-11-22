<?php

namespace App\Http\Controllers\Health;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Passport\Passport;
use League\OAuth2\Server\CryptKey;
use Throwable;

class ReadinessController
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::connection()->select('SELECT 1');
        } catch (Throwable $exception) {
            Log::warning('Readiness check failed', [
                'dependency' => 'database',
                'exception_class' => $exception::class,
            ]);

            return response()->json(['status' => 'not_ready'], 503);
        }

        try {
            $this->assertPassportKeysAreReady();
        } catch (Throwable $exception) {
            Log::warning('Readiness check failed', [
                'dependency' => 'passport_keys',
                'exception_class' => $exception::class,
            ]);

            return response()->json(['status' => 'not_ready'], 503);
        }

        return response()->json(['status' => 'ready']);
    }

    private function assertPassportKeysAreReady(): void
    {
        foreach (['private', 'public'] as $type) {
            $key = str_replace('\\n', "\n", config("passport.{$type}_key") ?? '');

            if (! $key) {
                $key = 'file://'.Passport::keyPath('oauth-'.$type.'.key');
            }

            new CryptKey($key, null, true);
        }
    }
}
