<?php

namespace App\Livewire;

use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Illuminate\Notifications\Messages\MailMessage;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Help extends Component
{
    use WithRateLimiting;

    #[Validate(['required', 'min:10', 'max:1000'])]
    public string $description;

    #[Validate(['required', 'min:3', 'max:600'])]
    public string $subject;

    public function submit()
    {
        try {
            $this->validate();
            $this->rateLimit(3, 30);

            $settings = instanceSettings();
            $mail = new MailMessage;
            $mail->view(
                'emails.help',
                [
                    'description' => $this->description,
                ]
            );
            $mail->subject("[HELP]: {$this->subject}");
            $type = set_transanctional_email_settings($settings);
            $supportEmail = config('oneploy.support_email');

            if (blank($supportEmail) || blank($type)) {
                throw new \RuntimeException(
                    'Support delivery is not configured. Ask an administrator to configure ONEPLOY_SUPPORT_EMAIL and transactional email, or open '.
                    config('oneploy.support_url')
                );
            }

            send_user_an_email($mail, auth()->user()?->email, $supportEmail);
            $this->dispatch('success', 'Feedback sent.', 'We will get in touch with you as soon as possible.');
            $this->reset('description', 'subject');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.help')->layout('layouts.app');
    }
}
