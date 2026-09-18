<?php

declare(strict_types=1);

namespace Forwext\App\Web\Moderation;

use Forwext\App\Web\Profile\AuthSessionProfileViewerResolver;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\App\Web\Report\ReportServiceFactory;
use Forwext\Core\Auth\Credential\DatabaseCredentialStore;
use Forwext\Core\Auth\Session\AuthSessionManager;
use Forwext\Core\Config\ConfigLoader;
use Forwext\Core\Config\ConfigRepository;
use Forwext\Core\Database\DatabaseConfig;
use Forwext\Core\Database\DatabaseConnection;
use Forwext\Core\Database\PdoConnectionFactory;
use Forwext\Core\Domain\Access\DatabaseUserAccessAssignmentProvider;
use Forwext\Core\Domain\Access\Permission\DatabasePermissionRuleRepository;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionEngine;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Event\DomainEventDispatcher;
use Forwext\Core\Domain\User\DatabaseUserRepository;
use Forwext\Core\Forum\Moderation\ContentModerationService;
use Forwext\Core\Forum\Moderation\DatabaseContentModerationRepository;
use Forwext\Core\Forum\Moderation\DatabaseModerationAuditStore;
use Forwext\Core\Forum\Moderation\ModerationOperationException;
use Forwext\Core\Forum\Node\DatabaseForumNodeRepository;
use Forwext\Core\Http\Canonical\CanonicalUrl;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Moderation\Approval\ApprovalQueueRegistry;
use Forwext\Core\Moderation\Approval\ApprovalQueueService;
use Forwext\Core\Moderation\Approval\ForumApprovalQueueProvider;
use Forwext\Core\Moderation\Abuse\AbuseModerationService;
use Forwext\Core\Moderation\Abuse\AbuseOperationException;
use Forwext\Core\Moderation\Abuse\DatabaseAbuseRepository;
use Forwext\Core\Moderation\Discipline\DatabaseDisciplineAuthenticationAvailability;
use Forwext\Core\Moderation\Discipline\DatabaseDisciplineRepository;
use Forwext\Core\Moderation\Discipline\DisciplineActionType;
use Forwext\Core\Moderation\Discipline\DisciplineOperationException;
use Forwext\Core\Moderation\Discipline\DisciplineService;
use Forwext\Core\Moderation\Discipline\NotificationDisciplineNotifier;
use Forwext\Core\Moderation\Report\DatabaseReportRepository;
use Forwext\Core\Moderation\Report\ReportGroupNotFoundException;
use Forwext\Core\Moderation\Task\DatabaseModerationTaskRepository;
use Forwext\Core\Moderation\Task\ModerationTaskNotFoundException;
use Forwext\Core\Moderation\Task\ModerationTaskService;
use Forwext\Core\Moderation\Workspace\AbuseWorkspaceSource;
use Forwext\Core\Moderation\Workspace\ApprovalQueueWorkspaceSource;
use Forwext\Core\Moderation\Workspace\DisciplineWorkspaceSource;
use Forwext\Core\Moderation\Workspace\ModerationTaskWorkspaceSource;
use Forwext\Core\Moderation\Workspace\ModerationWorkspaceService;
use Forwext\Core\Moderation\Workspace\ReportWorkspaceSource;
use Forwext\Core\Notification\DatabaseNotificationRepository;
use Forwext\Core\Notification\NotificationDispatcher;
use Forwext\Core\Notification\NotificationRegistry;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Security\Secret\EncryptedFileSecretStore;
use Forwext\Core\Security\Secret\EnvironmentOrFileSecretKeyProvider;
use Forwext\Core\Security\Secret\SecretCipher;
use Forwext\Core\Security\Secret\SecretKey;
use Forwext\Core\Session\DatabaseSessionStore;
use Forwext\Core\Session\FileSessionStore;
use Forwext\Core\Session\SessionStore;
use InvalidArgumentException;
use RuntimeException;
use ValueError;

final class ModerationApplicationFactory
{
    private ConfigRepository $config;
    private CanonicalUrl $canonicalUrl;
    private BasePath $basePath;
    private ?DatabaseConnection $database = null;
    private ?ProfileViewerResolver $viewers = null;
    private ?PermissionAuthorizer $authorizer = null;

    public function __construct(private string $projectRoot)
    {
        if ($this->projectRoot === '') {
            throw new RuntimeException('Project root cannot be empty.');
        }
        $this->config = (new ConfigLoader())->load(
            $this->projectRoot . '/config/defaults.php',
            $this->projectRoot . '/config/generated.php',
        );
        $this->canonicalUrl = new CanonicalUrl($this->config->requireString('routing.canonical_url'));
        $this->basePath = $this->canonicalUrl->basePath();
    }

    public function handle(Request $request): ?Response
    {
        $path = parse_url($request->uri(), PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }
        $routePath = $this->basePath->strip($path);
        if ($routePath === null || ($routePath !== '/moderation' && !str_starts_with($routePath, '/moderation/'))) {
            return null;
        }

        $actorId = $this->viewerResolver()->resolve($request);
        if ($actorId === null) {
            return $this->secure(Response::text('Unauthorized', 401));
        }

        $gate = new PermissionGate($this->permissionAuthorizer(), $actorId);
        $database = $this->database();
        $taskRepository = new DatabaseModerationTaskRepository($database);
        $reportRepository = new DatabaseReportRepository($database);
        $nodes = new DatabaseForumNodeRepository($database);
        $users = new DatabaseUserRepository($database);
        $audit = new DatabaseModerationAuditStore($database);
        $disciplineRepository = new DatabaseDisciplineRepository($database);
        $abuseRepository = new DatabaseAbuseRepository($database);
        $disciplineNotifications = new NotificationRegistry();
        NotificationDisciplineNotifier::registerDefinitions($disciplineNotifications);
        $disciplineService = new DisciplineService(
            $database,
            $disciplineRepository,
            $users,
            $gate,
            $audit,
            new NotificationDisciplineNotifier(new NotificationDispatcher(
                $disciplineNotifications,
                new DatabaseNotificationRepository($database),
            )),
            new DomainEventDispatcher(),
        );
        $contentModeration = new ContentModerationService(
            $nodes,
            new DatabaseContentModerationRepository($database, $audit),
            $gate,
        );
        $approvalRegistry = new ApprovalQueueRegistry([
            new ForumApprovalQueueProvider($database, $nodes, $gate, $contentModeration),
        ]);
        $approvalService = new ApprovalQueueService($approvalRegistry, $gate);
        $abuseService = new AbuseModerationService(
            $database,
            $abuseRepository,
            $contentModeration,
            $gate,
            $audit,
        );
        $guard = new ModerationRequestGuard($this->canonicalUrl);
        $canManage = $gate->allows(PermissionKey::fromString('moderation.manage'));
        $handler = new ModerationWorkspaceHandler(
            new ModerationWorkspaceService($gate, [
                new ReportWorkspaceSource($database, $reportRepository),
                new ApprovalQueueWorkspaceSource($approvalRegistry),
                new DisciplineWorkspaceSource(
                    $disciplineRepository,
                    $gate,
                    \Forwext\Core\Moderation\Workspace\ModerationWorkspaceSection::Warnings,
                    [DisciplineActionType::Warning, DisciplineActionType::Restriction],
                ),
                new DisciplineWorkspaceSource(
                    $disciplineRepository,
                    $gate,
                    \Forwext\Core\Moderation\Workspace\ModerationWorkspaceSection::Bans,
                    [DisciplineActionType::Suspension, DisciplineActionType::Ban],
                ),
                new AbuseWorkspaceSource($abuseRepository, $gate),
                new ModerationTaskWorkspaceSource($taskRepository),
            ]),
            new ModerationTaskService(
                $database,
                $taskRepository,
                $gate,
                $audit,
            ),
            $guard,
            $this->basePath,
            $canManage,
        );
        $reportHandler = new ReportModerationHandler(
            ReportServiceFactory::create($database, $this->permissionAuthorizer(), $gate),
            $guard,
            $this->basePath,
            $canManage,
        );
        $approvalHandler = new ApprovalQueueHandler(
            $approvalService,
            $guard,
            $this->basePath,
            $canManage,
        );
        $disciplineHandler = new DisciplineHandler(
            $disciplineService,
            $users,
            $guard,
            $this->basePath,
            new DisciplineCapabilities(
                $gate->allows(PermissionKey::fromString(DisciplineService::WARNING_ISSUE_PERMISSION)),
                $gate->allows(PermissionKey::fromString(DisciplineService::WARNING_MANAGE_PERMISSION)),
                $gate->allows(PermissionKey::fromString(DisciplineService::RESTRICTION_MANAGE_PERMISSION)),
                $gate->allows(PermissionKey::fromString(DisciplineService::BAN_MANAGE_PERMISSION)),
                $gate->allows(PermissionKey::fromString(DisciplineService::REVOKE_PERMISSION)),
            ),
        );
        $abuseHandler = new AbuseHandler(
            $abuseService,
            $guard,
            $this->basePath,
            new AbuseCapabilities(
                $gate->allows(PermissionKey::fromString(AbuseModerationService::MANAGE_RULES_PERMISSION)),
                $gate->allows(PermissionKey::fromString(AbuseModerationService::CLEANUP_PERMISSION)),
            ),
        );

        try {
            if ($routePath === '/moderation') {
                if ($request->method() !== HttpMethod::Get) {
                    return $this->secure(Response::text('Method Not Allowed', 405)->withHeader('Allow', 'GET'));
                }
                return $handler->view();
            }

            if ($routePath === '/moderation/approval') {
                if ($request->method() !== HttpMethod::Get) {
                    return $this->secure(Response::text('Method Not Allowed', 405)->withHeader('Allow', 'GET'));
                }
                return $approvalHandler->view();
            }

            if ($routePath === '/moderation/approval/actions') {
                if ($request->method() !== HttpMethod::Post) {
                    return $this->secure(Response::text('Method Not Allowed', 405)->withHeader('Allow', 'POST'));
                }
                return $approvalHandler->moderate($request);
            }

            if ($routePath === '/moderation/discipline') {
                if ($request->method() !== HttpMethod::Get) {
                    return $this->secure(Response::text('Method Not Allowed', 405)->withHeader('Allow', 'GET'));
                }
                return $disciplineHandler->view();
            }

            if ($routePath === '/moderation/discipline/actions') {
                if ($request->method() !== HttpMethod::Post) {
                    return $this->secure(Response::text('Method Not Allowed', 405)->withHeader('Allow', 'POST'));
                }
                return $disciplineHandler->issue($request);
            }

            if ($routePath === '/moderation/discipline/warning-definitions') {
                if ($request->method() !== HttpMethod::Post) {
                    return $this->secure(Response::text('Method Not Allowed', 405)->withHeader('Allow', 'POST'));
                }
                return $disciplineHandler->saveWarningDefinition($request);
            }

            if (preg_match('#^/moderation/discipline/([0-9a-f]{32})/revoke$#D', $routePath, $matches) === 1) {
                if ($request->method() !== HttpMethod::Post) {
                    return $this->secure(Response::text('Method Not Allowed', 405)->withHeader('Allow', 'POST'));
                }
                return $disciplineHandler->revoke($request, $matches[1]);
            }

            if ($routePath === '/moderation/abuse') {
                if ($request->method() !== HttpMethod::Get) {
                    return $this->secure(Response::text('Method Not Allowed', 405)->withHeader('Allow', 'GET'));
                }
                return $abuseHandler->view();
            }

            if ($routePath === '/moderation/abuse/rules') {
                if ($request->method() !== HttpMethod::Post) {
                    return $this->secure(Response::text('Method Not Allowed', 405)->withHeader('Allow', 'POST'));
                }
                return $abuseHandler->saveRule($request);
            }

            if ($routePath === '/moderation/abuse/events') {
                if ($request->method() !== HttpMethod::Post) {
                    return $this->secure(Response::text('Method Not Allowed', 405)->withHeader('Allow', 'POST'));
                }
                return $abuseHandler->bulk($request);
            }

            if ($routePath === '/moderation/tasks') {
                if ($request->method() !== HttpMethod::Post) {
                    return $this->secure(Response::text('Method Not Allowed', 405)->withHeader('Allow', 'POST'));
                }
                return $handler->createTask($request);
            }

            if (preg_match('#^/moderation/tasks/([0-9a-f]{32})/status$#D', $routePath, $matches) === 1) {
                if ($request->method() !== HttpMethod::Post) {
                    return $this->secure(Response::text('Method Not Allowed', 405)->withHeader('Allow', 'POST'));
                }
                return $handler->updateTaskStatus($request, $matches[1]);
            }

            if (preg_match('#^/moderation/reports/([0-9a-f]{32})$#D', $routePath, $matches) === 1) {
                if ($request->method() !== HttpMethod::Get) {
                    return $this->secure(Response::text('Method Not Allowed', 405)->withHeader('Allow', 'GET'));
                }
                return $reportHandler->view($matches[1]);
            }

            if (preg_match('#^/moderation/reports/([0-9a-f]{32})/(assign|status|comments)$#D', $routePath, $matches) === 1) {
                if ($request->method() !== HttpMethod::Post) {
                    return $this->secure(Response::text('Method Not Allowed', 405)->withHeader('Allow', 'POST'));
                }
                return match ($matches[2]) {
                    'assign' => $reportHandler->assign($request, $matches[1]),
                    'status' => $reportHandler->status($request, $matches[1]),
                    'comments' => $reportHandler->comment($request, $matches[1]),
                };
            }

            return $this->secure(Response::text('Not Found', 404));
        } catch (PermissionDeniedException|ReportMutationGuardException|DisciplineMutationGuardException|AbuseMutationGuardException) {
            return $this->secure(Response::text('Forbidden', 403));
        } catch (ModerationTaskNotFoundException|ReportGroupNotFoundException) {
            return $this->secure(Response::text('Not Found', 404));
        } catch (ModerationOperationException|DisciplineOperationException|AbuseOperationException) {
            return $this->secure(Response::text('Conflict', 409));
        } catch (InvalidArgumentException|ValueError) {
            return $this->secure(Response::text('Bad Request', 400));
        }
    }

    private function viewerResolver(): ProfileViewerResolver
    {
        if ($this->viewers === null) {
            $this->viewers = new AuthSessionProfileViewerResolver(
                new AuthSessionManager(
                    $this->sessionStore(),
                    new DatabaseCredentialStore($this->database()),
                    $this->config->requireInt('authentication.session.ttl_seconds'),
                ),
                new DatabaseUserRepository($this->database()),
                $this->config->requireString('authentication.session.cookie_name'),
                new DatabaseDisciplineAuthenticationAvailability($this->database()),
            );
        }
        return $this->viewers;
    }

    private function permissionAuthorizer(): PermissionAuthorizer
    {
        return $this->authorizer ??= new PermissionAuthorizer(
            new PermissionEngine(new DatabasePermissionRuleRepository($this->database())),
            new DatabaseUserAccessAssignmentProvider($this->database()),
        );
    }

    private function sessionStore(): SessionStore
    {
        return match ($this->config->requireString('session.driver')) {
            'file' => new FileSessionStore($this->projectPath($this->config->requireString('session.path'))),
            'database' => new DatabaseSessionStore($this->database()),
            default => throw new RuntimeException(
                'Configured session driver requires an explicit advanced-runtime moderation composition.',
            ),
        };
    }

    private function database(): DatabaseConnection
    {
        if ($this->database !== null) {
            return $this->database;
        }
        $secrets = new EncryptedFileSecretStore(
            $this->projectPath($this->config->requireString('security.secret_store_path')),
            new SecretCipher($this->masterKey()),
        );
        $password = $secrets->get($this->config->requireString('database.password_secret'));
        if ($password === null) {
            throw new RuntimeException('Database password secret is unavailable.');
        }
        $socket = $this->config->get('database.unix_socket');
        if ($socket !== null && !is_string($socket)) {
            throw new RuntimeException('Database unix socket configuration is invalid.');
        }
        $this->database = (new PdoConnectionFactory())->create(new DatabaseConfig(
            $this->config->requireString('database.host'),
            $this->config->requireInt('database.port'),
            $this->config->requireString('database.name'),
            $this->config->requireString('database.username'),
            $password,
            $this->config->requireString('database.charset'),
            $this->config->requireInt('database.connect_timeout_seconds'),
            $socket,
        ));
        return $this->database;
    }

    private function masterKey(): SecretKey
    {
        return (new EnvironmentOrFileSecretKeyProvider(
            $this->projectPath($this->config->requireString('security.master_key_file')),
            $this->config->requireString('security.master_key_environment'),
        ))->load();
    }

    private function projectPath(string $path): string
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new RuntimeException('Configured project path is invalid.');
        }
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/D', $path) === 1) {
            return $path;
        }
        return $this->projectRoot . '/' . ltrim(str_replace('\\', '/', $path), '/');
    }

    private function secure(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
