<?php

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TenantDomainProvisioningNeeded extends Notification
{
    public function __construct(
        protected Tenant $tenant,
        protected string $hostname,
        protected string $reason
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Manual DNS action required for tenant subdomain')
            ->line(sprintf('Tenant "%s" (%s) needs a DNS record.', $this->tenant->name, $this->tenant->id))
            ->line('Requested subdomain: ' . $this->hostname)
            ->line('Reason: ' . $this->reason)
            ->line('Once the DNS record is created, the tenant can access the platform via their subdomain.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'tenant_id' => $this->tenant->id,
            'tenant_slug' => $this->tenant->slug,
            'hostname' => $this->hostname,
            'reason' => $this->reason,
        ];
    }
}
