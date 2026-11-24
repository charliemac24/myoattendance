<?php

namespace App\Mail;

use App\Models\Organization;
use App\Models\User;
use App\Services\SmsPriceEstimator;
use App\Support\OrganizationTypes;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells super admin a new organization signed up, and whether it already answered the SMS questions.
 */
class NewSignupAlert extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @var array{organization: string, type: string, owner: ?string, owner_email: ?string, trial_ends: ?string, answers: ?string, people: ?int, texts_per_day: ?int, days: ?string, estimate: ?string, url: string}
     */
    public array $details;

    public function __construct(Organization $organization, User $owner)
    {
        $trialEnds = $organization->baseSubscription()->first()?->trial_ends_at;
        $this->details = [
            'organization' => $organization->name,
            'type' => OrganizationTypes::LABELS[$organization->type] ?? ucfirst((string) $organization->type),
            'owner' => $owner->name,
            'owner_email' => $owner->email,
            'trial_ends' => $trialEnds?->copy()->timezone($organization->timezone ?: config('app.timezone'))->format('M j, Y'),
            'url' => url('/super-admin?organization='.$organization->id),
        ] + self::smsAnswers($organization);
    }

    /**
     * The school's SMS answers in words, and the monthly estimate they give (null when it did not answer).
     *
     * @return array{answers: ?string, people: ?int, texts_per_day: ?int, days: ?string, estimate: ?string}
     */
    public static function smsAnswers(Organization $organization): array
    {
        $people = $organization->sms_estimate_people;
        $texts = (array) ($organization->sms_estimate_texts ?? []);
        $days = $organization->sms_estimate_days;
        if (! $people || ! $days || SmsPriceEstimator::textsPerDay($texts) === 0) {
            return ['answers' => null, 'people' => null, 'texts_per_day' => null, 'days' => null, 'estimate' => null];
        }

        return [
            'answers' => SmsPriceEstimator::describeAnswers($people, $texts, $days),
            'people' => $people,
            'texts_per_day' => SmsPriceEstimator::textsPerDay($texts),
            'days' => ['weekdays' => 'Weekdays', 'mon_sat' => 'Mon–Sat', 'every_day' => 'Every day'][$days] ?? $days,
            'estimate' => 'PHP '.number_format(app(SmsPriceEstimator::class)->estimateMinor($people, $texts, $days) / 100, 2).'/month',
        ];
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'New sign-up: '.$this->details['organization']);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.alerts.new-signup');
    }
}
