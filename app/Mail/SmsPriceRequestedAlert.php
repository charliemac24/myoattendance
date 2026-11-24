<?php

namespace App\Mail;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells super admin a school asked for its custom SMS price, with the school's answers and the estimate they give.
 */
class SmsPriceRequestedAlert extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @var array{organization: string, requested_by: ?string, answers: ?string, people: ?int, texts_per_day: ?int, days: ?string, estimate: ?string, url: string}
     */
    public array $details;

    public function __construct(Organization $organization, ?string $requestedBy = null)
    {
        $this->details = ['organization' => $organization->name, 'requested_by' => $requestedBy, 'url' => url('/super-admin?organization='.$organization->id)]
            + NewSignupAlert::smsAnswers($organization);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'SMS price requested: '.$this->details['organization']);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.alerts.sms-price-requested');
    }
}
