<?php

declare(strict_types=1);

namespace FchubThankYou\Domain\Enums;

enum RedirectType: string
{
    case Page = 'page';
    case Post = 'post';
    case Cpt  = 'cpt';
    case Url  = 'url';
}
