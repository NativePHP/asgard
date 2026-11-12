<?php

namespace Native\Asgard\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Native\Asgard\Asgard;

class PushCommand extends Command
{
    protected $signature = 'asgard:push
        {component? : A registered component address or class. Optional with --to}
        {region? : Only re-render this region}
        {--to=* : Only push to streams in this channel. Repeat for more}';

    protected $description = 'Push fresh fragments to open Asgard streams for a component, or for some channels';

    public function handle(Asgard $asgard): int
    {
        $component = $this->argument('component');
        $component = $component === null ? null : (string) $component;
        $region = $this->argument('region');
        $region = $region === null ? null : (string) $region;
        $channels = array_values(array_map('strval', (array) $this->option('to')));

        try {
            if ($channels !== []) {
                $asgard->to($channels)->push($component, $region);
            } elseif ($component === null) {
                throw new InvalidArgumentException('Name a component, or pass --to with a channel.');
            } else {
                $asgard->push($component, $region);
            }
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $what = match (true) {
            $component === null => 'every stream',
            $region === null => "[{$component}]",
            default => "region [{$region}] of [{$component}]",
        };

        $this->components->info($channels === []
            ? "Pushed {$what}."
            : "Pushed {$what} in ".implode(', ', array_map(fn ($c) => "[{$c}]", $channels)).'.');

        return self::SUCCESS;
    }
}
