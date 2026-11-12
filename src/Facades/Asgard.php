<?php

namespace Native\Asgard\Facades;

use Illuminate\Support\Facades\Facade;
use Native\Asgard\Asgard as AsgardManager;
use Native\Asgard\ComponentRegistry;

/**
 * @method static ComponentRegistry component(string $address, string $class)
 * @method static string|null resolve(string $address)
 * @method static array all()
 * @method static void push(string $component, ?string $region = null)
 *
 * @see AsgardManager
 */
class Asgard extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AsgardManager::class;
    }
}
