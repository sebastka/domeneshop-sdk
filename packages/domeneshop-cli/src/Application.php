<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Cli;

use Symfony\Component\Console\Application as BaseApplication;

final class Application extends BaseApplication
{
    public const VERSION = '0.1.0';

    public function __construct()
    {
        parent::__construct('Domeneshop CLI', self::VERSION);

        $this->addCommands(Commands::all());
    }
}
