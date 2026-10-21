<?php

declare(strict_types=1);

namespace Progineous\Mend;

final class Mend
{
    /**
     * Repair markdown produced by a language model.
     */
    public static function repair(string $markdown): string
    {
        return (new Repairer())->repair($markdown);
    }
}
