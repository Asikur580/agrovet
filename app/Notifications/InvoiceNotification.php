<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

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
        // এখানে mail যোগ করলাম
        return ['database', 'mail'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('New Invoice Notification')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line($this->message)
            ->action('View Invoice', "https://ra.s3cbd.com/invoice/" . $this->invoiceId)
            ->line('Thank you for using our system!');
    }

    public function toArray($notifiable)
    {
        return [
            'message'    => $this->message,
            'invoice_id' => $this->invoiceId,
        ];
    }
}
