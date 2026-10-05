<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Cli;

use Sebastka\Domeneshop\Dashboard\Cli\Commands as DashboardCommands;
use Symfony\Component\Console\Application as BaseApplication;

final class Application extends BaseApplication
{
    public const VERSION = '0.3.0';

    public function __construct()
    {
        parent::__construct('Domeneshop CLI', self::VERSION);

        $this->addCommands(Commands::all());

        // The dashboard commands live in a separate, optional package, because
        // they scrape the web dashboard rather than calling a documented API
        // and carry none of its guarantees. If sebastka/domeneshop-dashboard is
        // installed its commands appear under `dashboard:`; if not, nothing
        // changes. class_exists() on an absent class is simply false — the use
        // statement above never autoloads — so this CLI takes no dependency on
        // that package.
        if (class_exists(DashboardCommands::class)) {
            $this->addCommands(DashboardCommands::all());
        }
    }
}
