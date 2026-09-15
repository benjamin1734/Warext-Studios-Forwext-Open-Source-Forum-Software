<?php

declare(strict_types=1);

namespace Forwext\App\Web\Permission;

use Forwext\Core\Domain\Access\Permission\Analyzer\PermissionAnalysis;
use Forwext\Core\Domain\Access\Permission\Analyzer\PermissionAnalysisLayer;
use Forwext\Core\Domain\Access\Permission\Analyzer\PermissionAnalysisStep;

final class PermissionAnalysisHtml
{
    public function render(PermissionAnalysis $analysis): string
    {
        $decision = $analysis->isAllowed() ? 'Allowed' : 'Denied';
        $html = '<section class="permission-analysis" aria-labelledby="permission-analysis-title">';
        $html .= '<h2 id="permission-analysis-title">Permission analysis</h2>';
        $html .= '<p class="permission-analysis__summary"><strong>' . $decision . '</strong> — '
            . $this->escape($analysis->summary()) . '</p>';
        $html .= '<p class="permission-analysis__key"><code>'
            . $this->escape($analysis->permissionKey()->value()) . '</code></p>';
        $html .= '<ol class="permission-analysis__layers">';

        foreach ($analysis->layers() as $layer) {
            $html .= $this->renderLayer($layer);
        }

        $html .= '</ol></section>';

        return $html;
    }

    private function renderLayer(PermissionAnalysisLayer $layer): string
    {
        $html = '<li class="permission-analysis__layer" data-state="'
            . $this->escape($layer->state()->value) . '">';
        $html .= '<h3>' . $this->escape($layer->label()) . '</h3>';
        $html .= '<p>' . $this->escape($layer->explanation()) . '</p>';
        $html .= '<p class="permission-analysis__state">State: '
            . $this->escape(str_replace('_', ' ', $layer->state()->value)) . '</p>';

        if ($layer->steps() !== []) {
            $html .= '<ul class="permission-analysis__steps">';
            foreach ($layer->steps() as $step) {
                $html .= $this->renderStep($step);
            }
            $html .= '</ul>';
        }

        return $html . '</li>';
    }

    private function renderStep(PermissionAnalysisStep $step): string
    {
        return '<li data-effect="' . $this->escape($step->effect()->value)
            . '" data-outcome="' . $this->escape($step->outcome()) . '">'
            . $this->escape($step->explanation()) . '</li>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
