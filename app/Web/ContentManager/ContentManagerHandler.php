<?php

declare(strict_types=1);

namespace Forwext\App\Web\ContentManager;

use DateTimeImmutable;
use DateTimeZone;
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

        $body = '<section class="card"><h1>Kullanıcı içerik yöneticisi</h1>'
            . '<p class="muted">Bir kullanıcının konu ve mesajlarını filtrele; işlem öncesinde dry-run ile hedef kümesini doğrula.</p>'
            . ($error === null ? '' : '<div class="search-alert">' . ProfileHtml::escape($error) . '</div>')
            . '<form method="get" action="' . $action . '" class="search-form">'
            . $this->filters($targetValue, $value('type'), $value('forum'), $value('state'), $value('deleted'), $value('q'))
            . '<div class="search-actions"><button type="submit">İçerikleri getir</button></div></form></section>';

        if ($targetUser !== null) {
            $body .= '<section class="card" style="margin-top:18px"><h2>' . ProfileHtml::escape($targetUser->username()->display()) . ' içerikleri</h2>';
            if ($items === []) {
                $body .= '<p class="muted">Filtreye uyan içerik bulunamadı.</p>';
            } elseif ($items !== null) {
                foreach ($items as $item) {
                    $body .= '<article class="search-hit"><span class="search-hit-type">' . ProfileHtml::escape($item->type->value) . '</span>'
                        . '<h3>' . ProfileHtml::escape($item->title) . '</h3>'
                        . '<p>' . ProfileHtml::escape($item->excerpt) . '</p>'
                        . '<p class="muted">' . ProfileHtml::escape($item->moderationState)
                        . ($item->deleted ? ' · silinmiş' : ' · aktif')
                        . ' · forum ' . ProfileHtml::escape($item->forumNodeId->value()) . '</p></article>';
                }
            }
            $body .= '</section>';

            $body .= '<section class="card" style="margin-top:18px"><h2>Toplu işlem</h2>'
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
                . $this->options(['delete'=>'Sil','restore'=>'Geri yükle','move'=>'Taşı','approve'=>'Onayla','reindex'=>'Yeniden indeksle','reprocess'=>'Yeniden işle'], $value('action'))
                . '</select></label>'
                . '<label><span>Taşıma hedef forum ID</span><input name="target_forum" value="' . ProfileHtml::escape($value('target_forum')) . '" maxlength="32"></label>'
                . '<div class="search-actions"><button type="submit" name="mode" value="preview">Dry-run</button>'
                . '<button type="submit" name="mode" value="enqueue">Kuyruğa ekle</button></div></form>';

            if ($preview !== null) {
                $counts = $preview->countsByType();
                $body .= '<div class="notice"><strong>Dry-run sonucu:</strong> '
                    . $preview->total() . ' hedef; ' . $counts['thread'] . ' konu, ' . $counts['post'] . ' mesaj.'
                    . ($preview->truncated ? ' Güvenlik sınırı aşıldı; filtreyi daralt.' : ' Hedef kümesi çalıştırıldığında dondurulacaktır.')
                    . '</div>';
            }
            $body .= '</section>';
        }

        $recent = $this->manager->recent($actor, 20);
        $body .= '<section class="card" style="margin-top:18px"><h2>Son işlemler</h2>';
        if ($recent === []) {
            $body .= '<p class="muted">Henüz işlem yok.</p>';
        } else {
            foreach ($recent as $operation) {
                $url = ProfileHtml::escape($this->basePath->prepend('/content-manager/operations/' . $operation->operationId->value()));
                $body .= '<p><a href="' . $url . '">' . ProfileHtml::escape($operation->action->value) . '</a> · '
                    . ProfileHtml::escape($operation->status->value) . ' · ' . $operation->processedCount . '/' . $operation->totalCount
                    . ' (' . $operation->percent() . '%)</p>';
            }
        }
        $body .= '</section>';

        return Response::html(ProfileHtml::page('Kullanıcı içerik yöneticisi', $body, $this->basePath, authenticated:true))
            ->withHeader('Cache-Control', 'private, no-store');
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
