<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Widget;

use Forwext\Core\Cache\CacheStore;

final readonly class WidgetRenderService
{
    public function __construct(
        private WidgetRegistry $widgets,
        private ?CacheStore $cache = null,
    ) {
    }

    public function renderSlot(string $slot, WidgetContext $context): string
    {
        $html = '';
        foreach ($this->widgets->forSlot($slot) as $registration) {
            $html .= $this->renderWidget($registration->widget, $context);
        }

        return $html;
    }

    private function renderWidget(Widget $widget, WidgetContext $context): string
    {
        $ttl = $widget->cacheTtlSeconds();
        if ($ttl === 0 || $this->cache === null) {
            return $widget->render($context);
        }

        $cacheKey = 'ui-widget:' . hash(
            'sha256',
            $widget->key() . '|' . $widget->slot() . '|' . $context->fingerprint(),
        );
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached->value;
        }

        $html = $widget->render($context);
        $this->cache->put(
            $cacheKey,
            $html,
            $ttl,
            ['ui.widget', 'ui.widget.' . $widget->key(), 'ui.slot.' . $widget->slot()],
        );

        return $html;
    }
}
