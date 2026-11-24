<?php

namespace App\Mail;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells super admin a school submitted a payment that is waiting for Activate.
 */
class PaymentSubmittedAlert extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @var array{organization: string, amount: string, plan: string, period: ?string, reference: ?string, paid_at: ?string, submitted_by: ?string, url: string}
     */
    public array $details;

    public function __construct(Payment $payment)
    {
        $payment->loadMissing(['organization', 'bill.items', 'submittedBy']);
        $bill = $payment->bill;

        $this->details = [
            'organization' => $payment->organization->name,
            'amount' => $payment->currency.' '.number_format($payment->amount_minor / 100, 2),
            'plan' => Payment::activatesLabel($payment->activates()),
            'period' => $bill ? $bill->billing_period_start->format('M j, Y').' to '.$bill->billing_period_end->format('M j, Y') : null,
            'reference' => $payment->reference_number,
            'paid_at' => $payment->paid_at?->format('M j, Y'),
            'submitted_by' => $payment->submittedBy?->name,
            'url' => url('/super-admin?payment='.$payment->id),
        ];
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Payment to review: '.$this->details['organization'].' · '.$this->details['amount']);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.alerts.payment-submitted');
    }
}
