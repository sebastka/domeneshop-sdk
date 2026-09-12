<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Model;

/** The web-hosting plan attached to a domain, or `none`. */
enum Webhotel: string
{
    case None = 'none';
    case WebSmall = 'websmall';
    case WebMedium = 'webmedium';
    case WebLarge = 'weblarge';
    case WebXLarge = 'webxlarge';
}
