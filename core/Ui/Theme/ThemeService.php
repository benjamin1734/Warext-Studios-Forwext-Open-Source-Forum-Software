<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Theme;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Audit\AuditAction;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Audit\AuditRequestId;
use Forwext\Core\Audit\AuditScope;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class ThemeService
{
    private const MANAGE_PERMISSION = 'appearance.manage';
    private const ADVANCED_PERMISSION = 'appearance.advanced';

    public function __construct(
        private ThemeRepository $themes,
        private PermissionAuthorizer $authorizer,
        private AuditRecorder $audit,
        private ThemeTemplateCache $templateCache,
        private ThemeDiffService $diff = new ThemeDiffService(),
    ) {
    }

    /** @return list<ThemeDefinition> */
    public function all(EntityId $actor): array
    {
        $this->require($actor, self::MANAGE_PERMISSION);
        return $this->themes->all();
    }

    public function snapshot(EntityId $actor, string $themeKey): ThemeSnapshot
    {
        $this->require($actor, self::MANAGE_PERMISSION);
        $theme = $this->themes->findByKey($themeKey)
            ?? throw new InvalidArgumentException('Theme was not found.');

        return new ThemeSnapshot(
            $theme,
            $theme->stagingRevisionId === null ? null : $this->themes->revision($theme->stagingRevisionId),
            $theme->publishedRevisionId === null ? null : $this->themes->revision($theme->publishedRevisionId),
            $this->themes->revisions($theme->themeId),
        );
    }

    public function stage(
        EntityId $actor,
        string $key,
        string $name,
        ?string $parentKey,
        ThemePayload $payload,
        DateTimeImmutable $now,
        AuditRequestId $requestId,
    ): ThemeRevision {
        $this->require($actor, self::MANAGE_PERMISSION);
        if ($payload->customCss !== '' || $payload->customJs !== '') {
            $this->require($actor, self::ADVANCED_PERMISSION);
        }

        $existing = $this->themes->findByKey($key);
        $parent = $parentKey === null || $parentKey === ''
            ? null
            : ($this->themes->findByKey($parentKey)
                ?? throw new InvalidArgumentException('Parent theme was not found.'));

        $themeId = $existing?->themeId ?? ThemeDefinition::generateId();
        $this->assertParentChain($themeId, $parent);

        $now = $now->setTimezone(new DateTimeZone('UTC'));
        $theme = new ThemeDefinition(
            $themeId,
            $key,
            $name,
            $parent?->themeId,
            $existing?->stagingRevisionId,
            $existing?->publishedRevisionId,
            $existing?->createdAt ?? $now,
            $now,
        );
        $revision = new ThemeRevision(
            ThemeRevision::generateId(),
            $theme->themeId,
            $payload,
            $actor,
            $now,
        );

        $event = new AuditEvent(
            AuditEvent::generateId(),
            AuditScope::Administration,
            $actor,
            AuditAction::fromString('appearance.theme.stage'),
            'ui.theme',
            $theme->themeId->value(),
            null,
            'appearance.theme.stage',
            $requestId,
            self::themeSnapshot($existing),
            [
                'theme_key' => $theme->key,
                'name' => $theme->name,
                'parent_theme_id' => $theme->parentThemeId?->value(),
                'staging_revision_id' => $revision->revisionId->value(),
                'template_count' => count($payload->templates),
                'language_count' => count($payload->phrases),
                'custom_css' => $payload->customCss !== '',
                'custom_js' => $payload->customJs !== '',
            ],
            $now,
        );

        $this->audit->mutate($event, function () use ($theme, $revision, $actor, $now): void {
            $this->themes->saveStaging($theme, $revision, $actor, $now);
        });

        return $revision;
    }

    public function publish(
        EntityId $actor,
        string $themeKey,
        EntityId $expectedStagingRevisionId,
        DateTimeImmutable $now,
        AuditRequestId $requestId,
    ): void {
        $this->require($actor, self::MANAGE_PERMISSION);
        $this->require($actor, self::ADVANCED_PERMISSION);

        $theme = $this->themes->findByKey($themeKey)
            ?? throw new InvalidArgumentException('Theme was not found.');
        if (
            $theme->stagingRevisionId === null
            || !$theme->stagingRevisionId->equals($expectedStagingRevisionId)
        ) {
            throw new InvalidArgumentException('Theme staging revision changed before publish.');
        }

        $revision = $this->themes->revision($expectedStagingRevisionId)
            ?? throw new InvalidArgumentException('Theme staging revision was not found.');
        if (!$revision->themeId->equals($theme->themeId)) {
            throw new InvalidArgumentException('Theme staging revision belongs to another theme.');
        }

        $effectivePayload = $this->effectivePayload($theme, $revision->payload);
        $compiledRevision = new ThemeRevision(
            $revision->revisionId,
            $revision->themeId,
            $effectivePayload,
            $revision->createdBy,
            $revision->createdAt,
        );
        $this->templateCache->compile($theme->key, $compiledRevision);

        $now = $now->setTimezone(new DateTimeZone('UTC'));
        $event = new AuditEvent(
            AuditEvent::generateId(),
            AuditScope::Administration,
            $actor,
            AuditAction::fromString('appearance.theme.publish'),
            'ui.theme',
            $theme->themeId->value(),
            null,
            'appearance.theme.publish',
            $requestId,
            self::themeSnapshot($theme),
            [
                'theme_key' => $theme->key,
                'published_revision_id' => $revision->revisionId->value(),
                'effective_template_count' => count($effectivePayload->templates),
                'effective_language_count' => count($effectivePayload->phrases),
            ],
            $now,
        );

        $published = $this->audit->mutate(
            $event,
            fn (): bool => $this->themes->publish(
                $theme->themeId,
                $revision->revisionId,
                $actor,
                $now,
            ),
        );
        if ($published !== true) {
            throw new InvalidArgumentException('Theme staging revision changed before publish.');
        }
    }

    public function rollbackStaging(
        EntityId $actor,
        string $themeKey,
        EntityId $revisionId,
        DateTimeImmutable $now,
        AuditRequestId $requestId,
    ): void {
        $this->require($actor, self::MANAGE_PERMISSION);
        $this->require($actor, self::ADVANCED_PERMISSION);

        $theme = $this->themes->findByKey($themeKey)
            ?? throw new InvalidArgumentException('Theme was not found.');
        $revision = $this->themes->revision($revisionId)
            ?? throw new InvalidArgumentException('Theme revision was not found.');
        if (!$revision->themeId->equals($theme->themeId)) {
            throw new InvalidArgumentException('Theme revision belongs to another theme.');
        }

        $now = $now->setTimezone(new DateTimeZone('UTC'));
        $event = new AuditEvent(
            AuditEvent::generateId(),
            AuditScope::Administration,
            $actor,
            AuditAction::fromString('appearance.theme.rollback'),
            'ui.theme',
            $theme->themeId->value(),
            null,
            'appearance.theme.rollback',
            $requestId,
            self::themeSnapshot($theme),
            [
                'theme_key' => $theme->key,
                'staging_revision_id' => $revisionId->value(),
            ],
            $now,
        );

        $changed = $this->audit->mutate(
            $event,
            fn (): bool => $this->themes->pointStaging(
                $theme->themeId,
                $revisionId,
                $actor,
                $now,
            ),
        );
        if ($changed !== true) {
            throw new InvalidArgumentException('Theme rollback target could not be staged.');
        }
    }

    /** @return list<ThemeDiffEntry> */
    public function diff(
        EntityId $actor,
        string $themeKey,
        EntityId $leftRevisionId,
        EntityId $rightRevisionId,
    ): array {
        $this->require($actor, self::MANAGE_PERMISSION);
        $theme = $this->themes->findByKey($themeKey)
            ?? throw new InvalidArgumentException('Theme was not found.');
        $left = $this->themes->revision($leftRevisionId)
            ?? throw new InvalidArgumentException('Left theme revision was not found.');
        $right = $this->themes->revision($rightRevisionId)
            ?? throw new InvalidArgumentException('Right theme revision was not found.');

        if (!$left->themeId->equals($theme->themeId) || !$right->themeId->equals($theme->themeId)) {
            throw new InvalidArgumentException('Theme diff revisions must belong to the selected theme.');
        }

        return $this->diff->diff($left->payload, $right->payload);
    }

    public function effectivePublishedPayload(EntityId $actor, string $themeKey): ThemePayload
    {
        $this->require($actor, self::MANAGE_PERMISSION);
        return (new ThemeRuntimeResolver($this->themes))->effectivePublishedPayload($themeKey);
    }

    private function effectivePayload(ThemeDefinition $theme, ThemePayload $ownPayload): ThemePayload
    {
        if ($theme->parentThemeId === null) {
            return $ownPayload;
        }

        $parent = $this->themes->findById($theme->parentThemeId)
            ?? throw new InvalidArgumentException('Parent theme was not found.');
        $parentPayload = (new ThemeRuntimeResolver($this->themes))->effectivePublishedPayload($parent->key);

        return ThemePayload::merge($parentPayload, $ownPayload);
    }

    private function assertParentChain(EntityId $themeId, ?ThemeDefinition $parent): void
    {
        $visited = [$themeId->value() => true];
        $depth = 0;

        while ($parent !== null) {
            if (isset($visited[$parent->themeId->value()])) {
                throw new InvalidArgumentException('Theme inheritance cycle detected.');
            }
            $visited[$parent->themeId->value()] = true;

            ++$depth;
            if ($depth > 16) {
                throw new InvalidArgumentException('Theme inheritance depth exceeds the supported limit.');
            }

            $parent = $parent->parentThemeId === null
                ? null
                : ($this->themes->findById($parent->parentThemeId)
                    ?? throw new InvalidArgumentException('Theme parent chain is incomplete.'));
        }
    }

    private function require(EntityId $actor, string $permission): void
    {
        $decision = $this->authorizer->resolve($actor, PermissionKey::fromString($permission));
        if (!$decision->isAllowed()) {
            throw new PermissionDeniedException($decision);
        }
    }

    /** @return array<string,scalar|null> */
    private static function themeSnapshot(?ThemeDefinition $theme): array
    {
        if ($theme === null) {
            return [];
        }

        return [
            'theme_key' => $theme->key,
            'name' => $theme->name,
            'parent_theme_id' => $theme->parentThemeId?->value(),
            'staging_revision_id' => $theme->stagingRevisionId?->value(),
            'published_revision_id' => $theme->publishedRevisionId?->value(),
        ];
    }
}
