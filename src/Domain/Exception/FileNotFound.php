<?php

declare(strict_types=1);

namespace TeamSquad\EventBus\Domain\Exception;

use Exception;

class FileNotFound extends Exception
{
    public function __construct(string $file)
    {
        parent::__construct("Configuration path file not found: {$file}");
    }
}
