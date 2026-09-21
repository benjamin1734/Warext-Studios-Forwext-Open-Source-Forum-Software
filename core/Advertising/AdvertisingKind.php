<?php

declare(strict_types=1);

namespace Forwext\Core\Advertising;

enum AdvertisingKind:string
{
    case Advertisement='advertisement';
    case Notice='notice';
    case Announcement='announcement';

    public function managementPermission():string
    {
        return $this===self::Advertisement?'ads.manage':'notice.manage';
    }
}
