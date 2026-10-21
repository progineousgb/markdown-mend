<?php

declare(strict_types=1);

namespace Progineous\Mend;

use Illuminate\Support\ServiceProvider;

final class MendServiceProvider extends ServiceProvider
{
    /**
     * @return void
     */
    public function register()
    {
        $this->app->singleton(Repairer::class);
    }
}
