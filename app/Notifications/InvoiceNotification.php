<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class InvoiceNotification extends Notification
{
    use Queueable;

    protected $message;
    protected $invoiceId;

    public function __construct($message, $invoiceId)
    {
        $this->message = $message;
        $this->invoiceId = $invoiceId;
    }

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toArray($notifiable)
    {
        return [
            'message'    => $this->message,
            'invoice_id' => $this->invoiceId,
        ];
    }
}

