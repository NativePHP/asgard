<?php

namespace Native\Asgard\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Native\Asgard\Asgard;

class PushCommand extends Command
{
    protected $signature = 'asgard:push
        {component : A registered component address or class}
        {region? : Only re-render this region}';

    protected $description = 'Push fresh fragments to every open Asgard stream for a component';

    public function handle(Asgard $asgard): int
    {
        $component = (string) $this->argument('component');
        $region = $this->argument('region');

        try {
            $asgard->push($component, $region === null ? null : (string) $region);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info($region === null
            ? "Pushed [{$component}]."
            : "Pushed region [{$region}] of [{$component}].");

        return self::SUCCESS;
    }
}
