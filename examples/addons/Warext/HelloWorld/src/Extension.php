<?php

declare(strict_types=1);

namespace Warext\HelloWorld;

use Forwext\Core\Addon\AddonId;
use Forwext\Core\Addon\Ui\AddonUiRegistration;
use Warext\HelloWorld\Widget\HelloWidget;

final class Extension
{
    public const ID = 'Warext/HelloWorld';

    public static function ui(): AddonUiRegistration
    {
        $registration = new AddonUiRegistration(AddonId::fromString(self::ID));
        $registration->widget(new HelloWidget());

        return $registration;
    }
}
