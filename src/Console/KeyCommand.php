<?php

namespace Native\Asgard\Console;

use Illuminate\Console\Command;
use Native\Asgard\Security\Base64Url;

/**
 * Prints a new Asgard key. The key is a public identifier, not a secret:
 * the same value goes in the server's and the app's ASGARD_KEY.
 */
class KeyCommand extends Command
{
    protected $signature = 'asgard:key';

    protected $description = 'Generate an Asgard key for one app and environment';

    public function handle(): int
    {
        $this->line('ASGARD_KEY='.Base64Url::encode(random_bytes(24)));

        return self::SUCCESS;
    }
}
