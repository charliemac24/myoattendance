<?php

namespace App\Console\Commands;

use App\Enums\SmsMessageStatus;
use App\Models\SmsMessage;
use App\Services\FeatureEntitlementService;
use App\Services\SemaphoreClient;
use App\Services\SmsRetryPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessSmsCommand extends Command
{
    protected $signature = 'sms:process {--limit= : Maximum messages to claim in this invocation}';

    protected $description = 'Process pending SMS messages through Semaphore';

    public function handle(SemaphoreClient $client, SmsRetryPolicy $retryPolicy, FeatureEntitlementService $entitlements): int
    {
        $this->recoverStaleProcessing();
        $limit = max(1, (int) ($this->option('limit') ?: config('services.semaphore.batch_size', 50)));
        $claimed = $sent = $retryScheduled = $failed = 0;
        for ($i = 0; $i < $limit; $i++) {
            $message = $this->claimNext();
            if (! $message) {
                break;
            }
            $claimed++;
            if (! $entitlements->canUseSms($message->organization)) {
                $message->forceFill([
                    'status' => SmsMessageStatus::Failed,
                    'processing_started_at' => null,
                    'next_attempt_at' => null,
                    'failed_at' => now(),
                    'last_error_code' => 'sms_not_entitled',
                    'last_error_message' => 'SMS access was no longer active when this message was ready to send.',
                ])->save();
                Log::info('sms_cancelled_not_entitled', ['sms_message_id' => $message->id]);

                continue;
            }
            $result = $client->send($message->recipient_phone, $message->message, $message->sender_name ?: config('services.semaphore.sender_name'));
            if ($result->accepted) {
                $message->forceFill(['status' => SmsMessageStatus::Sent, 'sent_at' => now(), 'processing_started_at' => null, 'next_attempt_at' => null, 'failed_at' => null, 'last_error_code' => null, 'last_error_message' => null, 'provider_message_id' => $result->providerMessageId, 'provider_status' => $result->providerStatus, 'provider_response' => $result->providerResponse])->save();
                $sent++;
                Log::info('sms_sent', ['sms_message_id' => $message->id]);

                continue;
            }
            $willRetry = $result->retryable && $message->attempt_count < (int) config('services.semaphore.max_attempts', 3);
            $fields = ['processing_started_at' => null, 'last_error_code' => $result->errorCode, 'last_error_message' => $result->errorMessage, 'provider_response' => $result->providerResponse, 'provider_status' => $result->providerStatus];
            if ($willRetry) {
                $fields += ['status' => SmsMessageStatus::Pending, 'next_attempt_at' => $retryPolicy->nextAttemptAt($message->attempt_count, now(), $result->retryAfterSeconds)];
                $retryScheduled++;
            } else {
                $fields += ['status' => SmsMessageStatus::Failed, 'failed_at' => now(), 'next_attempt_at' => null, 'last_error_code' => $result->errorCode ?: 'max_attempts_exceeded', 'last_error_message' => $result->errorMessage ?: 'Maximum SMS attempts reached.'];
                $failed++;
            }
            $message->forceFill($fields)->save();
            Log::warning($willRetry ? 'sms_retry_scheduled' : 'sms_failed', ['sms_message_id' => $message->id, 'error_code' => $message->last_error_code]);
        }
        $this->line("SMS processing complete. Claimed: {$claimed}, Sent: {$sent}, Retry scheduled: {$retryScheduled}, Failed: {$failed}");

        return self::SUCCESS;
    }

    private function claimNext(): ?SmsMessage
    {
        return DB::transaction(function (): ?SmsMessage {
            $message = SmsMessage::query()->where('status', SmsMessageStatus::Pending)->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->orderBy('created_at')->orderBy('id')->lockForUpdate()->first();
            if (! $message) {
                return null;
            }
            $message->forceFill(['status' => SmsMessageStatus::Processing, 'processing_started_at' => now(), 'attempt_count' => $message->attempt_count + 1, 'last_attempt_at' => now()])->save();
            Log::info('sms_claimed', ['sms_message_id' => $message->id]);

            return $message->fresh();
        });
    }

    private function recoverStaleProcessing(): void
    {
        $cutoff = now()->subMinutes((int) config('services.semaphore.stale_after_minutes', 10));
        SmsMessage::query()->where('status', SmsMessageStatus::Processing)->where('processing_started_at', '<=', $cutoff)->update(['status' => SmsMessageStatus::Failed, 'failed_at' => now(), 'processing_started_at' => null, 'last_error_code' => 'provider_uncertain', 'last_error_message' => 'Processing became stale; provider acceptance is uncertain. Review before retrying.']);
    }
}
