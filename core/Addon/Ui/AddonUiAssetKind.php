<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Ui;

enum AddonUiAssetKind: string
{
    case Css = 'css';
    case JavaScript = 'js';

    public function extension(): string
    {
        return $this->value;
    }
}
