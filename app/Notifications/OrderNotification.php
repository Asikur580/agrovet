<?php 

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class OrderNotification extends Notification
{
    use Queueable;

    protected $message;

    public function __construct($message)
    {
        $this->message = $message;
    }

    // কোন চ্যানেল দিয়ে যাবে (database, mail, sms ইত্যাদি)
    public function via($notifiable)
    {
        return ['database']; 
    }

    public function toArray($notifiable)
    {
        return [
            'message' => $this->message,
        ];
    }
}
