<?php

declare(strict_types=1);

namespace Forwext\App\Web\Faq;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Faq\FaqArticle;
use Forwext\Core\Faq\FaqCategory;
use Forwext\Core\Faq\FaqService;
use Forwext\Core\Faq\FaqVisibility;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;
use ValueError;

final readonly class FaqManageHandler implements RequestHandlerInterface
{
    public function __construct(
        private FaqService $faq,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) {
            return Response::text('Authentication required.', 401)->withHeader('Cache-Control','no-store');
        }

        try {
            if ($request->method() === HttpMethod::Post) {
                $this->mutate($actor, $request);
                return Response::text('',303)
                    ->withHeader('Location',$this->basePath->prepend('/faq/manage?updated=1'))
                    ->withHeader('Cache-Control','no-store');
            }

            if (($request->query()['export'] ?? null) === '1') {
                $json = $this->faq->export($actor);
                return (new Response($json,200))
                    ->withHeader('Content-Type','application/json; charset=utf-8')
                    ->withHeader('Content-Disposition',"attachment; filename*=UTF-8''forwext-faq.json")
                    ->withHeader('X-Content-Type-Options','nosniff')
                    ->withHeader('Cache-Control','private, no-store');
            }

            $token = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if (!is_string($token) || $token === '') {
                return Response::text('Internal Server Error',500)->withHeader('Cache-Control','no-store');
            }
            $snapshot = $this->faq->managementSnapshot($actor);
            return Response::html(FaqHtml::manage(
                $snapshot['categories'],
                $snapshot['articles'],
                $snapshot['helpful'],
                $this->basePath,
                $token,
                ($request->query()['updated'] ?? null) === '1',
            ))->withHeader('Cache-Control','private, no-store');
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden',403)->withHeader('Cache-Control','no-store');
        } catch (InvalidArgumentException|ValueError) {
            return Response::text('Bad Request',400)->withHeader('Cache-Control','no-store');
        }
    }

    private function mutate(EntityId $actor, Request $request): void
    {
        $body = $request->parsedBody();
        $action = $body['action'] ?? null;
        if (!is_string($action)) {
            throw new InvalidArgumentException('FAQ management action is missing.');
        }

        switch ($action) {
            case 'category_save':
                $this->faq->saveCategory($actor, new FaqCategory(
                    strtolower($this->requiredString($body,'key',64)),
                    $this->requiredString($body,'label',120),
                    $this->optionalString($body,'description',500) ?? '',
                    $this->requiredString($body,'language',32),
                    FaqVisibility::from($this->requiredString($body,'visibility',16)),
                    $this->integer($body,'sort_order',0,65535,100),
                    self::checked($body,'active'),
                ));
                break;

            case 'article_save':
                $idRaw = $this->optionalString($body,'article_id',32);
                $existing = $idRaw === null
                    ? null
                    : $this->faq->managementArticle($actor, EntityId::fromString($idRaw));
                if ($idRaw !== null && $existing === null) {
                    throw new InvalidArgumentException('FAQ article id does not exist.');
                }
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $tagsRaw = $this->optionalString($body,'tags',1000) ?? '';
                $tags = $tagsRaw === ''
                    ? []
                    : array_values(array_filter(array_map('trim', explode(',',$tagsRaw)), static fn(string $tag): bool=>$tag!==''));
                $article = new FaqArticle(
                    $existing?->articleId ?? FaqArticle::generateId(),
                    strtolower($this->requiredString($body,'category',64)),
                    strtolower($this->requiredString($body,'slug',160)),
                    $this->requiredString($body,'question',300),
                    $this->requiredString($body,'answer',50000),
                    $tags,
                    FaqVisibility::from($this->requiredString($body,'visibility',16)),
                    $this->requiredString($body,'language',32),
                    $this->integer($body,'sort_order',0,65535,100),
                    $this->optionalString($body,'seo_title',200),
                    $this->optionalString($body,'seo_description',320),
                    self::checked($body,'active'),
                    $existing?->createdAt ?? $now,
                    $now,
                );
                $this->faq->saveArticle($actor,$article);
                break;

            case 'import':
                $this->faq->import($actor,$this->requiredString($body,'json',5_000_000));
                break;

            default:
                throw new InvalidArgumentException('Unknown FAQ management action.');
        }
    }

    /** @param array<string,mixed> $body */
    private function requiredString(array $body,string $key,int $max): string
    {
        $value=$body[$key]??null;
        if(!is_string($value)) throw new InvalidArgumentException('FAQ field is missing: '.$key);
        $value=trim($value);
        if($value===''||strlen($value)>$max) throw new InvalidArgumentException('FAQ field is invalid: '.$key);
        return $value;
    }

    /** @param array<string,mixed> $body */
    private function optionalString(array $body,string $key,int $max): ?string
    {
        $value=$body[$key]??null;
        if($value===null||$value==='') return null;
        if(!is_string($value)||strlen($value)>$max) throw new InvalidArgumentException('FAQ optional field is invalid: '.$key);
        $value=trim($value);
        return $value===''?null:$value;
    }

    /** @param array<string,mixed> $body */
    private function integer(array $body,string $key,int $min,int $max,int $default): int
    {
        if(!array_key_exists($key,$body)||$body[$key]==='') return $default;
        $value=filter_var($body[$key],FILTER_VALIDATE_INT);
        if(!is_int($value)||$value<$min||$value>$max) throw new InvalidArgumentException('FAQ integer field is invalid: '.$key);
        return $value;
    }

    /** @param array<string,mixed> $body */
    private static function checked(array $body,string $key): bool
    {
        return ($body[$key]??null)==='1'||($body[$key]??null)===1||($body[$key]??null)===true;
    }
}
