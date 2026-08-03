<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class GrantPlatformAdmin extends Command
{
    protected $signature = 'platform:admin {email} {--revoke : Remove platform admin access instead}';

    protected $description = 'Grant (or revoke) platform-wide admin access for a user';

    public function handle(): int
    {
        $user = User::withoutGlobalScopes()->where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error("No user found with email {$this->argument('email')}.");

            return self::FAILURE;
        }

        $user->forceFill(['is_platform_admin' => ! $this->option('revoke')])->save();

        $this->info($this->option('revoke')
            ? "{$user->email} is no longer a platform admin."
            : "{$user->email} is now a platform admin.");

        return self::SUCCESS;
    }
}
