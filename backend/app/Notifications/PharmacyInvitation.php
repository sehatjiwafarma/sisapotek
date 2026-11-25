<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PharmacyInvitation extends Notification
{
    public function __construct(public string $pharmacyName, public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Undangan akses apotek')->greeting('Halo!')
            ->line('Anda diundang bergabung ke '.$this->pharmacyName.'.')
            ->action('Tinjau undangan', route('invitations.show', $this->token))
            ->line('Undangan berlaku 72 jam. Masuk atau buat akun menggunakan email penerima.');
    }
}
