<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class InvoiceCreatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $invoice;

    /**
     * Create a new message instance.
     */
    public function __construct(Invoice $invoice)
    {
        $this->invoice = $invoice;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this->subject('New Invoice Created: ' . $this->invoice->invoiceId)
                    ->markdown('emails.invoices.created')
                    ->with([
                        'invoice' => $this->invoice,
                        'url' => "https://ra.s3cbd.com/invoice/" . $this->invoice->id
                    ]);
    }
}
