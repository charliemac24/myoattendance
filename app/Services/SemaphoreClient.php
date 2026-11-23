<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class SemaphoreClient
{
    public function send(string $recipientPhone, string $message, ?string $senderName): SemaphoreSendResult
    {
        $config = config('services.semaphore');
        $recipientPhone = preg_replace('/\D+/', '', $recipientPhone) ?: '';

        try {
            $payload = ['apikey' => $config['api_key'], 'number' => $recipientPhone, 'message' => $message];
            if ($senderName) {
                $payload['sendername'] = $senderName;
            }
            $response = Http::timeout((int) $config['timeout'])->acceptJson()->asForm()->post(rtrim($config['base_url'], '/').'/messages', $payload);
        } catch (ConnectionException) {
            return new SemaphoreSendResult(false, true, 'connection_error', 'Semaphore connection failed.', null, null, null, null);
        }

        $body = $response->json();
        $safeBody = is_array($body) ? $this->sanitize($body) : null;
        $retryAfter = is_numeric($response->header('Retry-After')) ? max(1, (int) $response->header('Retry-After')) : null;
        if ($response->status() === 429) {
            return new SemaphoreSendResult(false, true, 'rate_limited', 'Semaphore rate limit reached.', null, null, $retryAfter, $safeBody);
        }
        if ($response->serverError() || $response->status() === 408) {
            return new SemaphoreSendResult(false, true, 'provider_temporary_failure', 'Semaphore temporarily rejected the request.', null, null, $retryAfter, $safeBody);
        }

        $item = is_array($body) && isset($body[0]) && is_array($body[0]) ? $body[0] : null;
        $status = is_string($item['status'] ?? null) ? strtolower($item['status']) : null;
        $messageId = isset($item['message_id']) ? (string) $item['message_id'] : null;
        if ($response->successful() && $item && $messageId && in_array($status, ['queued', 'pending', 'sent'], true)) {
            return new SemaphoreSendResult(true, false, '', '', $messageId, $item['status'], null, $safeBody);
        }
        if ($response->successful() && $status === 'failed') {
            return new SemaphoreSendResult(false, false, 'provider_rejected', 'Semaphore rejected the SMS.', $messageId, $item['status'] ?? null, null, $safeBody);
        }

        $auth = in_array($response->status(), [401, 403], true);
        $retryable = ! $auth && ($response->successful() || $response->serverError());

        return new SemaphoreSendResult(false, $retryable, $auth ? 'provider_authentication' : 'provider_invalid_response', $auth ? 'Semaphore authentication/configuration failed.' : 'Semaphore returned an unexpected response.', $messageId, $item['status'] ?? null, $retryAfter, $safeBody);
    }

    private function sanitize(array $value): array
    {
        $secret = config('services.semaphore.api_key');
        array_walk_recursive($value, function (&$item) use ($secret): void {
            if ($secret && is_string($item)) {
                $item = str_replace($secret, '[REDACTED]', $item);
            }
        });

        return $value;
    }
}
