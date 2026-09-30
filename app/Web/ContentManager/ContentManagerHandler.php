<?php

declare(strict_types=1);

namespace Forwext\App\Web\ContentManager;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Audit\HttpAuditRequestId;
use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Content\Manager\ContentManagerAccessDeniedException;
use Forwext\Core\Content\Manager\ContentManagerAction;
use Forwext\Core\Content\Manager\ContentManagerContentType;
use Forwext\Core\Content\Manager\ContentManagerFilter;
use Forwext\Core\Content\Manager\ContentManagerOperationException;
use Forwext\Core\Content\Manager\ContentManagerOperationProcessor;
use Forwext\Core\Content\Manager\ContentManagerPreview;
use Forwext\Core\Content\Manager\ContentManagerService;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\User;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Domain\User\Username;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;
use ValueError;

final readonly class ContentManagerHandler implements RequestHandlerInterface
{
    public function __construct(
        private ContentManagerService $manager,
        private ContentManagerOperationProcessor $processor,
        private UserRepository $users,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) {
            return Response::text('Authentication required.', 401)->withHeader('Cache-Control', 'no-store');
        }

        try {
            if ($request->method() === HttpMethod::Post) {
                return $this->post($request, $actor);
            }
            return $this->get($request, $actor);
        } catch (ContentManagerAccessDeniedException) {
            return Response::text('Permission denied.', 403)->withHeader('Cache-Control', 'no-store');
        } catch (ContentManagerOperationException|InvalidArgumentException|ValueError) {
            return $this->page($request, $actor, null, null, 'İçerik yöneticisi isteği geçersiz veya uygulanamıyor.');
        }
    }

    private function get(Request $request, EntityId $actor): Response
    {
        $targetRaw = $request->query()['target_user'] ?? null;
        if (!is_string($targetRaw) || trim($targetRaw) === '') {
            return $this->page($request, $actor, null, null, null);
        }

        $user = $this->resolveUser($targetRaw)
            ?? throw new ContentManagerOperationException('Target user is unavailable.');
        $filter = $this->filter($request->query(), $user->id());
        $items = $this->manager->search($actor, $filter, 100, 0);
        return $this->page($request, $actor, $user, $items, null);
    }

    private function post(Request $request, EntityId $actor): Response
    {
        $body = $request->parsedBody();
        $targetRaw = $body['target_user'] ?? null;
        $mode = $body['mode'] ?? null;
        $actionRaw = $body['action'] ?? null;
        if (!is_string($targetRaw) || !is_string($mode) || !is_string($actionRaw)) {
            throw new ContentManagerOperationException('Content manager form is incomplete.');
        }
        $user = $this->resolveUser($targetRaw)
            ?? throw new ContentManagerOperationException('Target user is unavailable.');
        $filter = $this->filter($body, $user->id());
        $action = ContentManagerAction::from($actionRaw);
        $targetForum = $this->entityOrNull($body['target_forum'] ?? null);

        if ($mode === 'preview') {
            $preview = $this->manager->preview($actor, $filter, $action, $targetForum);
            return $this->page($request, $actor, $user, $this->manager->search($actor, $filter, 100, 0), null, $preview);
        }
        if ($mode !== 'enqueue') {
            throw new ContentManagerOperationException('Content manager form mode is invalid.');
        }

        $operation = $this->manager->enqueue(
            $actor,
            $filter,
            $action,
            $targetForum,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            HttpAuditRequestId::fromRequest($request),
        );
        $this->processor->process($operation->operationId, new DateTimeImmutable('now', new DateTimeZone('UTC')), 25);
        return Response::text('', 303)
            ->withHeader('Location', $this->basePath->prepend('/content-manager/operations/' . $operation->operationId->value()))
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @param array<string,mixed> $input
     */
    private function filter(array $input, EntityId $targetUserId): ContentManagerFilter
    {
        $typeRaw = $input['type'] ?? '';
        $stateRaw = $input['state'] ?? '';
        $deletedRaw = $input['deleted'] ?? '';
        $queryRaw = $input['q'] ?? '';
        $forumRaw = $input['forum'] ?? '';

        $type = is_string($typeRaw) && $typeRaw !== '' ? ContentManagerContentType::from($typeRaw) : null;
        $state = is_string($stateRaw) && $stateRaw !== '' ? $stateRaw : null;
        $deleted = match (is_string($deletedRaw) ? $deletedRaw : '') {
            '1'=>'1',
            '0'=>'0',
            default=>null,
        };
        return new ContentManagerFilter(
            $targetUserId,
            $type,
            $this->entityOrNull($forumRaw),
            $state,
            $deleted === null ? null : $deleted === '1',
            is_string($queryRaw) && trim($queryRaw) !== '' ? trim($queryRaw) : null,
        );
    }

    private function resolveUser(string $raw): ?User
    {
        $raw = trim($raw);
        if (preg_match('/^[a-f0-9]{32}$/D', $raw) === 1) {
            return $this->users->find(EntityId::fromString($raw));
        }
        return $this->users->findByUsername(Username::fromString($raw));
    }

    private function entityOrNull(mixed $raw): ?EntityId
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        return EntityId::fromString(trim($raw));
    }

    /**
     * @param list<\Forwext\Core\Content\Manager\ContentManagerItem>|null $items
     */
    private function page(
        Request $request,
        EntityId $actor,
        ?User $targetUser,
        ?array $items,
        ?string $error,
        ?ContentManagerPreview $preview = null,
    ): Response {
        $this->manager->recent($actor, 1);
        $token = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
        if (!is_string($token) || $token === '') {
            return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
        }

        $input = $request->method() === HttpMethod::Post ? $request->parsedBody() : $request->query();
        $value = static fn (string $key): string => is_string($input[$key] ?? null) ? (string) $input[$key] : '';
        $targetValue = $targetUser?->username()->display() ?? $value('target_user');
        $action = ProfileHtml::escape($this->basePath->prepend('/content-manager'));

        $body = '<section class="content-manager-page discovery-page"><header class="surface-head content-manager-head"><div>'
            . '<span class="forum-eyebrow">YETKİLİ ARAÇLARI</span><h1>Kullanıcı içerik yöneticisi</h1>'
            . '<p>Kullanıcının konu ve mesajlarını filtrele; işlem öncesinde dry-run ile hedef kümesini doğrula.</p></div>'
            . '<a class="fx-btn" href="' . ProfileHtml::escape($this->basePath->prepend('/moderation')) . '">Moderasyona dön</a></header>';

        if ($error !== null) {
            $body .= '<div class="auth-entry-error" role="alert">' . ProfileHtml::escape($error) . '</div>';
        }

        $body .= '<section class="surface-panel content-manager-filter"><form method="get" action="' . $action
            . '" class="search-form content-manager-filter-form">'
            . $this->filters($targetValue, $value('type'), $value('forum'), $value('state'), $value('deleted'), $value('q'))
            . '<div class="search-actions"><button type="submit">İçerikleri getir</button></div></form></section>';

        if ($targetUser !== null) {
            $username = ProfileHtml::escape($targetUser->username()->display());

            $body .= '<section class="surface-panel content-manager-results"><header><div><h2>' . $username
                . ' içerikleri</h2><p>Filtreye uyan konu ve mesajlar</p></div><span>'
                . ($items === null ? 0 : count($items)) . '</span></header><div class="content-manager-list">';

            if ($items === []) {
                $body .= '<div class="surface-empty"><strong>İçerik bulunamadı.</strong>'
                    . '<span>Filtreyi değiştirerek tekrar deneyebilirsin.</span></div>';
            } elseif ($items !== null) {
                foreach ($items as $item) {
                    $body .= '<article class="content-manager-row"><span class="content-manager-type">'
                        . ProfileHtml::escape($item->type->value) . '</span><div class="content-manager-copy"><strong>'
                        . ProfileHtml::escape($item->title) . '</strong><p>' . ProfileHtml::escape($item->excerpt) . '</p>'
                        . '<small>' . ProfileHtml::escape($item->moderationState)
                        . ($item->deleted ? ' · silinmiş' : ' · aktif')
                        . ' · forum ' . ProfileHtml::escape($item->forumNodeId->value()) . '</small></div></article>';
                }
            }
            $body .= '</div></section>';

            $body .= '<section class="surface-panel content-manager-bulk"><header><h2>Toplu işlem</h2>'
                . '<p>Önce dry-run ile hedef kümesini doğrula, sonra kuyruğa ekle.</p></header>'
                . '<form method="post" action="' . $action . '" class="search-form">'
                . '<input type="hidden" name="_csrf" value="' . ProfileHtml::escape($token) . '">'
                . $this->filters(
                    $targetUser->username()->display(),
                    $value('type'),
                    $value('forum'),
                    $value('state'),
                    $value('deleted'),
                    $value('q'),
                )
                . '<label><span>İşlem</span><select name="action">'
                . $this->options([
                    'delete'=>'Sil',
                    'restore'=>'Geri yükle',
                    'move'=>'Taşı',
                    'approve'=>'Onayla',
                    'reindex'=>'Yeniden indeksle',
                    'reprocess'=>'Yeniden işle',
                ], $value('action'))
                . '</select></label>'
                . '<label><span>Taşıma hedef forum ID</span><input name="target_forum" value="'
                . ProfileHtml::escape($value('target_forum')) . '" maxlength="32"></label>'
                . '<div class="content-manager-bulk-actions"><button class="fx-btn" type="submit" name="mode" value="preview">Dry-run</button>'
                . '<button class="fx-btn fx-btn--primary" type="submit" name="mode" value="enqueue">Kuyruğa ekle</button></div></form>';

            if ($preview !== null) {
                $counts = $preview->countsByType();
                $body .= '<div class="content-manager-preview"><strong>Dry-run sonucu</strong><span>'
                    . $preview->total() . ' hedef · ' . $counts['thread'] . ' konu · ' . $counts['post'] . ' mesaj</span><small>'
                    . ($preview->truncated
                        ? 'Güvenlik sınırı aşıldı; filtreyi daralt.'
                        : 'Hedef kümesi çalıştırıldığında dondurulacaktır.')
                    . '</small></div>';
            }
            $body .= '</section>';
        }

        $recent = $this->manager->recent($actor, 20);
        $body .= '<section class="surface-panel content-manager-recent"><header><h2>Son işlemler</h2><span>'
            . count($recent) . '</span></header><div class="content-manager-operation-list">';
        if ($recent === []) {
            $body .= '<div class="surface-empty"><strong>Henüz işlem yok.</strong>'
                . '<span>İçerik yöneticisi operasyonları burada görünecek.</span></div>';
        } else {
            foreach ($recent as $operation) {
                $url = ProfileHtml::escape($this->basePath->prepend(
                    '/content-manager/operations/' . $operation->operationId->value(),
                ));
                $body .= '<a class="content-manager-operation-row" href="' . $url . '"><strong>'
                    . ProfileHtml::escape($operation->action->value) . '</strong><span>'
                    . ProfileHtml::escape($operation->status->value) . ' · '
                    . $operation->processedCount . '/' . $operation->totalCount . ' · ' . $operation->percent()
                    . '%</span></a>';
            }
        }
        $body .= '</div></section></section>';

        return Response::html(ProfileHtml::page(
            'Kullanıcı içerik yöneticisi',
            $body,
            $this->basePath,
            authenticated:true,
        ))->withHeader('Cache-Control', 'private, no-store');
    }

    private function filters(
        string $target,
        string $type,
        string $forum,
        string $state,
        string $deleted,
        string $query,
    ): string {
        return '<label><span>Kullanıcı adı veya ID</span><input name="target_user" required value="' . ProfileHtml::escape($target) . '"></label>'
            . '<label><span>İçerik tipi</span><select name="type">' . $this->options([''=>'Tümü','thread'=>'Konular','post'=>'Mesajlar'], $type) . '</select></label>'
            . '<label><span>Forum ID</span><input name="forum" maxlength="32" value="' . ProfileHtml::escape($forum) . '"></label>'
            . '<label><span>Durum</span><select name="state">' . $this->options([''=>'Tümü','visible'=>'Görünür','pending'=>'Bekleyen','rejected'=>'Reddedilmiş'], $state) . '</select></label>'
            . '<label><span>Silinme</span><select name="deleted">' . $this->options([''=>'Tümü','0'=>'Aktif','1'=>'Silinmiş'], $deleted) . '</select></label>'
            . '<label><span>Metin ara</span><input name="q" maxlength="200" value="' . ProfileHtml::escape($query) . '"></label>';
    }

    /** @param array<string,string> $items */
    private function options(array $items, string $selected): string
    {
        $html = '';
        foreach ($items as $value=>$label) {
            $html .= '<option value="' . ProfileHtml::escape($value) . '"'
                . ($value === $selected ? ' selected' : '') . '>' . ProfileHtml::escape($label) . '</option>';
        }
        return $html;
    }
}
