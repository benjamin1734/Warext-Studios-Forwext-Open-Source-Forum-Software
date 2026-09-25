<?php

declare(strict_types=1);

namespace Forwext\Core\Admin\Integration;

enum IntegrationSettingType: string
{
    case Flag = 'flag';
    case Integer = 'integer';
    case String = 'string';
    case Enum = 'enum';
    case StringList = 'string_list';
    case HttpsUrl = 'https_url';
    case HttpsUrlList = 'https_url_list';
    case Email = 'email';
    case SameOriginPath = 'same_origin_path';
}
