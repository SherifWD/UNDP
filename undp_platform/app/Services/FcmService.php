<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FcmService
{
    public function sendToUser(User $user, string $title, string $body, array $data = []): void
    {
        if (! $user->fcm_token) {
            return;
        }

        $serverKey = (string) config('services.fcm.server_key');
        $endpoint = (string) config('services.fcm.legacy_endpoint', 'https://fcm.googleapis.com/fcm/send');

        if ($serverKey === '') {
            // Fallback for non-production environments.
            logger()->info('FCM server key missing, notification logged only', [
                'user_id' => $user->id,
                'title' => $title,
                'body' => $body,
                'data' => $data,
            ]);

            return;
        }

        $response = Http::withHeaders([
            'Authorization' => 'key='.$serverKey,
            'Content-Type' => 'application/json',
        ])->post($endpoint, [
            'to' => $user->fcm_token,
            'notification' => [
                'title' => $title,
                'body' => $body,
            ],
            'data' => $data,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('FCM push failed: '.$response->body());
        }
    }
}
