<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:suspend-user {email : The email address of an existing user}')]
#[Description('Sign a user out and stop them signing in or generating')]
class SuspendUser extends Command
{
    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No user has the email address {$email}.");

            return self::FAILURE;
        }

        if ($user->isSuspended()) {
            $this->info("{$email} was already suspended.");

            return self::SUCCESS;
        }

        $user->forceFill(['suspended_at' => now()])->save();

        $this->info("{$email} is suspended. Their next request signs them out. Generations already running will finish.");

        return self::SUCCESS;
    }
}
