<?php

namespace App\Notifications\TransactionalEmails;

use App\Notifications\Channels\TransactionalEmailChannel;
use App\Notifications\CustomEmailNotification;
use Illuminate\Notifications\Messages\MailMessage;

class DomainRegistered extends CustomEmailNotification
{
    /** @param list<string> $nameservers */
    public function __construct(
        public string $domain,
        public array $nameservers,
        public bool $dnsActive,
        public bool $isTransactionalEmail = true,
    ) {
        $this->onQueue('high');
    }

    public function via(): array
    {
        return [TransactionalEmailChannel::class];
    }

    public function toMail(): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('OnePloy: '.$this->domain.' is registered')
            ->greeting('Your domain is registered')
            ->line($this->domain.' has been registered successfully.')
            ->line($this->dnsActive
                ? 'Authoritative DNS is active and managed by OnePloy.'
                : 'Registration is complete. Authoritative DNS still requires operator attention.');

        if ($this->nameservers !== []) {
            $mail->line('Nameservers: '.implode(', ', $this->nameservers));
        }

        return $mail->action('Manage domains', route('oneploy.domains'));
    }
}
