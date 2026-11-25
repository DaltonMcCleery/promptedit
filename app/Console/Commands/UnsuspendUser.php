<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:unsuspend-user {email : The email address of an existing user}')]
#[Description('Let a suspended user sign in and generate again')]
class UnsuspendUser extends Command
{
    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No user has the email address {$email}.");

            return self::FAILURE;
        }

        $user->forceFill(['suspended_at' => null])->save();

        $this->info("{$email} can sign in and generate again.");

        return self::SUCCESS;
    }
}
