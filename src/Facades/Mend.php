<?php

declare(strict_types=1);

namespace Progineous\Mend\Facades;

use Illuminate\Support\Facades\Facade;
use Progineous\Mend\Repairer;

/**
 * @method static string repair(string $markdown)
 */
final class Mend extends Facade
{
    /**
     * @return class-string<Repairer>
     */
    protected static function getFacadeAccessor()
    {
        return Repairer::class;
    }
}
