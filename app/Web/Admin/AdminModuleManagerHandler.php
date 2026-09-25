<?php

declare(strict_types=1);

namespace Forwext\App\Web\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Audit\HttpAuditRequestId;
use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Module\FirstParty\FirstPartyModuleScope;
use Forwext\Core\Module\FirstParty\FirstPartyModuleService;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class AdminModuleManagerHandler implements RequestHandlerInterface
{
    public function __construct(
        private FirstPartyModuleService $modules,
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
            $csrf = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if (!is_string($csrf) || $csrf === '') {
                return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
            }

            if ($request->method() === HttpMethod::Post) {
                return $this->mutate($actor, $request);
            }

            $query = $request->query();
            $moduleKey = $this->optionalString($query, 'module', 64);
            $scope = FirstPartyModuleScope::tryFrom(
                $this->optionalString($query, 'scope', 16) ?? FirstPartyModuleScope::Global->value,
            ) ?? throw new InvalidArgumentException('Module setting scope is invalid.');
            $scopeId = $this->optionalString($query, 'scope_id', 191);

            $snapshot = $this->modules->managementSnapshot($actor, $moduleKey, $scope, $scopeId);
            $content = AdminModuleManagerHtml::page(
                $snapshot,
                $this->basePath,
                $csrf,
                ($query['updated'] ?? null) === '1',
            );

            return Response::html(ProfileHtml::page(
                'First-party Module Manager',
                $content,
                $this->basePath,
                authenticated: true,
                viewerId: $actor->value(),
            ))
                ->withHeader('Cache-Control', 'private, no-store')
                ->withHeader('X-Robots-Tag', 'noindex,nofollow');
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException|\ValueError) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
        }
    }

    private function mutate(EntityId $actor, Request $request): Response
    {
        $body = $request->parsedBody();
        $action = $this->requiredString($body, 'action', 40);
        $moduleKey = $this->requiredString($body, 'module_key', 64);
        $at = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $requestId = HttpAuditRequestId::fromRequest($request);

        switch ($action) {
            case 'enable':
                $this->modules->enable($actor, $moduleKey, $requestId, $at);
                break;
            case 'disable':
                $this->modules->disable($actor, $moduleKey, $requestId, $at);
                break;
            case 'install':
                $this->modules->install($actor, $moduleKey, $requestId, $at);
                break;
            case 'uninstall_keep':
            case 'uninstall_delete':
                $confirmation = $this->requiredString($body, 'confirm_key', 64);
                if (!hash_equals($moduleKey, $confirmation)) {
                    throw new InvalidArgumentException('Module uninstall confirmation does not match.');
                }
                $this->modules->uninstall(
                    $actor,
                    $moduleKey,
                    $action === 'uninstall_delete',
                    $requestId,
                    $at,
                );
                break;
            case 'retry_purge':
                $this->modules->retryPurge($actor, $moduleKey, $requestId, $at);
                break;
            case 'save_setting':
                $scope = FirstPartyModuleScope::tryFrom($this->requiredString($body, 'scope', 16))
                    ?? throw new InvalidArgumentException('Module setting scope is invalid.');
                $settingKey = $this->requiredString($body, 'setting_key', 64);
                $scopeId = $this->optionalString($body, 'scope_id', 191);
                $value = $body['value'] ?? null;
                if (!is_scalar($value) || is_float($value)) {
                    throw new InvalidArgumentException('Module setting value is invalid.');
                }
                $this->modules->saveSetting(
                    $actor,
                    $moduleKey,
                    $settingKey,
                    $scope,
                    $scopeId,
                    $value,
                    $requestId,
                    $at,
                );

                return $this->redirect($moduleKey, $scope, $scopeId);
            case 'reset_setting':
                $scope = FirstPartyModuleScope::tryFrom($this->requiredString($body, 'scope', 16))
                    ?? throw new InvalidArgumentException('Module setting scope is invalid.');
                $settingKey = $this->requiredString($body, 'setting_key', 64);
                $scopeId = $this->optionalString($body, 'scope_id', 191);
                $this->modules->resetSetting(
                    $actor,
                    $moduleKey,
                    $settingKey,
                    $scope,
                    $scopeId,
                    $requestId,
                    $at,
                );

                return $this->redirect($moduleKey, $scope, $scopeId);
            default:
                throw new InvalidArgumentException('Unknown module manager action.');
        }

        return $this->redirect($moduleKey, FirstPartyModuleScope::Global, null);
    }

    private function redirect(
        string $moduleKey,
        FirstPartyModuleScope $scope,
        ?string $scopeId,
    ): Response {
        $query = [
            'module'=>$moduleKey,
            'scope'=>$scope->value,
            'updated'=>'1',
        ];
        if ($scope->needsTarget() && $scopeId !== null && $scopeId !== '') {
            $query['scope_id'] = $scopeId;
        }

        return Response::redirect(
            $this->basePath->prepend('/admin/modules') . '?'
            . http_build_query($query, '', '&', PHP_QUERY_RFC3986),
            303,
        )->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string,mixed> $data */
    private function requiredString(array $data, string $key, int $maxLength): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '' || strlen(trim($value)) > $maxLength) {
            throw new InvalidArgumentException('Module manager field is invalid: ' . $key);
        }

        return trim($value);
    }

    /** @param array<string,mixed> $data */
    private function optionalString(array $data, string $key, int $maxLength): ?string
    {
        $value = $data[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || strlen($value) > $maxLength || preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException('Module manager optional field is invalid: ' . $key);
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
