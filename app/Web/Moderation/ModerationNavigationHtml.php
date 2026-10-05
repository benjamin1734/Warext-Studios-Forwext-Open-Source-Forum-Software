<?php

declare(strict_types=1);

namespace Forwext\App\Web\Moderation;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Routing\BasePath;

final class ModerationNavigationHtml
{
    public static function render(BasePath $basePath, string $active, bool $canViewAudit): string
    {
        $links = [
            'workspace' => ['/moderation', 'Çalışma alanı'],
            'approval' => ['/moderation/approval', 'Onay kuyruğu'],
            'reports' => ['/moderation#moderation-reports', 'Raporlar'],
            'discipline' => ['/moderation/discipline', 'Disiplin'],
            'abuse' => ['/moderation/abuse', 'Anti-abuse'],
        ];
        if ($canViewAudit) {
            $links['audit'] = ['/moderation/audit', 'Audit'];
            $links['oversight'] = ['/moderation/oversight', 'Bağımsız denetim'];
        }

        $html = '<nav class="moderation-nav" aria-label="Moderasyon gezinmesi">';
        foreach ($links as $key => [$path, $label]) {
            $html .= '<a href="' . self::e($basePath->prepend($path)) . '"'
                . ($active === $key ? ' aria-current="page"' : '') . '>' . self::e($label) . '</a>';
        }
        return $html . '</nav>';
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
