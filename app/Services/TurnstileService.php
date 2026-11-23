<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class TurnstileService
{
    public function validate(string $token, ?string $remoteIp = null): bool
    {
        $secret = config('services.turnstile.secret_key');
        if (! $secret) {
            return app()->environment(['local', 'testing']);
        }

        $response = Http::asForm()
            ->timeout(5)
            ->post(config('services.turnstile.verify_url'), array_filter([
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $remoteIp,
            ]));

        return $response->successful() && $response->json('success') === true;
    }
}
