<?php

namespace App\Mail;

use App\Models\CompanySetting;
use App\Models\EmailLog;
use App\Models\Estimate;
use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * The current link of an email that went out with an old link, sent to the
 * address that email went to (see LegacyEmailLinkController).
 */
class LegacyEmailLinkMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public EmailLog $emailLog, public string $url) {}

    public function build()
    {
        $document = $this->emailLog->mailable;
        $company = $document->company;

        [$type, $number] = match (true) {
            $document instanceof Invoice => ['invoice', $document->invoice_number],
            $document instanceof Estimate => ['estimate', $document->estimate_number],
            default => ['payment receipt', $document->payment_number],
        };

        return $this->from($this->emailLog->from ?: config('mail.from.address'), config('mail.from.name'))
            ->subject("New link to {$type} {$number} from {$company->name}")
            ->markdown('emails.legacy-link', [
                'companyName' => $company->name,
                'type' => $type,
                'number' => $number,
                'expiresOn' => $this->expiresOn($company->id),
            ]);
    }

    /**
     * The last day the link works, when the company lets links expire.
     * Mirrors EmailLog::isExpired().
     */
    private function expiresOn(int $companyId): ?string
    {
        if (CompanySetting::getSetting('automatically_expire_public_links', $companyId) !== 'YES') {
            return null;
        }

        $days = (int) CompanySetting::getSetting('link_expiry_days', $companyId);

        return $this->emailLog->created_at->copy()->addDays($days)->toFormattedDateString();
    }
}
