<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Layout\Builder;

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
use Forwext\Core\Ui\Layout\UiSlotRegistry;
use Forwext\Core\Ui\Widget\WidgetRegistry;
use InvalidArgumentException;

final readonly class LayoutBuilderService
{
    private const MANAGE_PERMISSION = 'appearance.manage';
    private const ADVANCED_PERMISSION = 'appearance.advanced';

    public function __construct(
        private LayoutBuilderRepository $repository,
        private PermissionAuthorizer $authorizer,
        private AuditRecorder $audit,
        private UiSlotRegistry $slots,
        private WidgetRegistry $widgets,
        private LayoutDocumentCodec $codec = new LayoutDocumentCodec(),
    ) {
    }

    public function snapshot(EntityId $actor, string $layoutKey): LayoutBuilderSnapshot
    {
        $this->require($actor, self::MANAGE_PERMISSION);
        $record = $this->repository->findByKey($layoutKey);

        return new LayoutBuilderSnapshot(
            $layoutKey,
            $record?->draftRevisionId === null ? null : $this->repository->revision($record->draftRevisionId),
            $record?->publishedRevisionId === null ? null : $this->repository->revision($record->publishedRevisionId),
        );
    }

    /**
     * @return array{slots:list<\Forwext\Core\Ui\Layout\UiSlotDefinition>,widgets:list<\Forwext\Core\Ui\Widget\RegisteredWidget>}
     */
    public function catalog(EntityId $actor): array
    {
        $this->require($actor, self::MANAGE_PERMISSION);

        return [
            'slots' => $this->slots->all(),
            'widgets' => $this->widgets->all(),
        ];
    }

    public function saveDraft(
        EntityId $actor,
        string $layoutKey,
        LayoutDocument $document,
        DateTimeImmutable $now,
        AuditRequestId $requestId,
        LayoutRevisionSource $source = LayoutRevisionSource::Editor,
    ): LayoutRevision {
        $this->require($actor, self::MANAGE_PERMISSION);
        $this->validateDocument($document);

        $now = $now->setTimezone(new DateTimeZone('UTC'));
        $existing = $this->repository->findByKey($layoutKey);
        $layout = $existing ?? new LayoutRecord(
            LayoutRecord::generateId(),
            $layoutKey,
            null,
            null,
            $now,
            $now,
        );
        $revision = new LayoutRevision(
            LayoutRevision::generateId(),
            $layout->layoutId,
            $document,
            $source,
            $actor,
            $now,
        );

        $event = new AuditEvent(
            AuditEvent::generateId(),
            AuditScope::Administration,
            $actor,
            AuditAction::fromString($source === LayoutRevisionSource::Import ? 'appearance.layout.import' : 'appearance.layout.save'),
            'ui.layout',
            $layout->layoutId->value(),
            null,
            $source === LayoutRevisionSource::Import ? 'appearance.layout.import' : 'appearance.layout.save',
            $requestId,
            self::snapshotArray($existing),
            [
                'layout_key' => $layoutKey,
                'draft_revision_id' => $revision->revisionId->value(),
                'placement_count' => count($document->placements),
            ],
            $now,
        );

        $this->audit->mutate($event, function () use ($layout, $revision, $actor, $now): void {
            $this->repository->saveDraft($layout, $revision, $actor, $now);
        });

        return $revision;
    }

    public function import(
        EntityId $actor,
        string $layoutKey,
        string $json,
        DateTimeImmutable $now,
        AuditRequestId $requestId,
    ): LayoutRevision {
        $this->require($actor, self::ADVANCED_PERMISSION);
        $document = $this->codec->import($json, $layoutKey);

        return $this->saveDraft(
            $actor,
            $layoutKey,
            $document,
            $now,
            $requestId,
            LayoutRevisionSource::Import,
        );
    }

    public function publish(
        EntityId $actor,
        string $layoutKey,
        EntityId $expectedDraftRevisionId,
        DateTimeImmutable $now,
        AuditRequestId $requestId,
    ): void {
        $this->require($actor, self::MANAGE_PERMISSION);
        $this->require($actor, self::ADVANCED_PERMISSION);

        $record = $this->repository->findByKey($layoutKey)
            ?? throw new InvalidArgumentException('Layout does not exist.');
        if (
            $record->draftRevisionId === null
            || !$record->draftRevisionId->equals($expectedDraftRevisionId)
        ) {
            throw new InvalidArgumentException('Layout draft changed before publish.');
        }

        $draft = $this->repository->revision($expectedDraftRevisionId)
            ?? throw new InvalidArgumentException('Layout draft revision does not exist.');
        $this->validateDocument($draft->document);
        $now = $now->setTimezone(new DateTimeZone('UTC'));

        $event = new AuditEvent(
            AuditEvent::generateId(),
            AuditScope::Administration,
            $actor,
            AuditAction::fromString('appearance.layout.publish'),
            'ui.layout',
            $record->layoutId->value(),
            null,
            'appearance.layout.publish',
            $requestId,
            self::snapshotArray($record),
            [
                'layout_key' => $layoutKey,
                'published_revision_id' => $expectedDraftRevisionId->value(),
                'placement_count' => count($draft->document->placements),
            ],
            $now,
        );

        $published = $this->audit->mutate(
            $event,
            fn (): bool => $this->repository->publish(
                $record->layoutId,
                $expectedDraftRevisionId,
                $actor,
                $now,
            ),
        );
        if ($published !== true) {
            throw new InvalidArgumentException('Layout draft changed before publish.');
        }
    }

    public function export(EntityId $actor, string $layoutKey): string
    {
        $snapshot = $this->snapshot($actor, $layoutKey);
        $revision = $snapshot->draft ?? $snapshot->published
            ?? throw new InvalidArgumentException('Layout does not have a revision to export.');

        return $this->codec->export($layoutKey, $revision->document);
    }

    /** @return list<LayoutPlacement> */
    public function preview(
        EntityId $actor,
        string $layoutKey,
        string $routeName,
        bool $authenticated,
        LayoutDevice $device,
    ): array {
        $snapshot = $this->snapshot($actor, $layoutKey);
        $document = $snapshot->draft?->document ?? $snapshot->published?->document ?? new LayoutDocument([]);

        return array_values(array_filter(
            $document->placements,
            static fn (LayoutPlacement $placement): bool =>
                $placement->enabled
                && $placement->condition->matches($routeName, $authenticated, $device),
        ));
    }

    private function validateDocument(LayoutDocument $document): void
    {
        $knownWidgets = [];
        foreach ($this->widgets->all() as $registered) {
            $knownWidgets[$registered->widget->key()] = true;
        }

        foreach ($document->placements as $placement) {
            $this->slots->get($placement->slotKey);
            if (!isset($knownWidgets[$placement->widgetKey])) {
                throw new InvalidArgumentException('Layout references an unregistered widget: ' . $placement->widgetKey);
            }
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
    private static function snapshotArray(?LayoutRecord $record): array
    {
        return $record === null ? [] : [
            'layout_key' => $record->key,
            'draft_revision_id' => $record->draftRevisionId?->value(),
            'published_revision_id' => $record->publishedRevisionId?->value(),
        ];
    }
}
