<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GenerateVapidKeys extends Command
{
    protected $signature = 'webpush:vapid';

    protected $description = 'Generate the VAPID key pair for Web Push and print the .env lines';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->info('Add these to your .env, then restart the server:');
        $this->newLine();
        $this->line('WEBPUSH_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('WEBPUSH_PRIVATE_KEY='.$keys['privateKey']);

        return self::SUCCESS;
    }
}
