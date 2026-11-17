<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Removes the link between a user and their Authentik account, for example
 * after the Authentik account was deleted and created again. The user's next
 * sign-in with Authentik links them again by email address.
 */
class UnlinkAuthentikAccount extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'authentik:unlink {email : The email address of the InvoiceShelf user}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Unlink a user from their Authentik account, so that their next sign-in with Authentik links them again by email address';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $user) {
            $this->error("No user has the email address {$email}.");

            return self::FAILURE;
        }

        if ($user->authentik_id === null) {
            $this->info("{$user->email} is not linked to an Authentik account.");

            return self::SUCCESS;
        }

        $user->forceFill(['authentik_id' => null])->save();

        $this->info("Unlinked {$user->email} from their Authentik account.");

        return self::SUCCESS;
    }
}
