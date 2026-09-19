<?php

declare(strict_types=1);

namespace Forwext\App\Web;

use Forwext\App\Web\Bug\BugAttachmentDownloadHandler;
use Forwext\App\Web\Bug\BugReportDetailHandler;
use Forwext\App\Web\Bug\BugReportFormHandler;
use Forwext\App\Web\Bug\BugStaffDashboardHandler;
use Forwext\App\Web\Bug\BugStaffExportHandler;
use Forwext\App\Web\Bug\MyBugReportsHandler;
use Forwext\App\Web\ContentManager\ContentManagerHandler;
use Forwext\App\Web\ContentManager\ContentManagerOperationHandler;
use Forwext\App\Web\Editor\EditorLinkPreviewHandler;
use Forwext\App\Web\Editor\EditorMentionLookupHandler;
use Forwext\App\Web\Editor\EditorPreviewHandler;
use Forwext\App\Web\Editor\EditorQuoteHandler;
use Forwext\App\Web\Editor\EditorSpellcheckHandler;
use Forwext\App\Web\Editor\SpellcheckDictionaryHandler;
use Forwext\App\Web\Forum\AttachmentDownloadHandler;
use Forwext\App\Web\Forum\AttachmentDownloadResponseFactory;
use Forwext\App\Web\Forum\AttachmentFinalizeHandler;
use Forwext\App\Web\Forum\AttachmentServiceResolver;
use Forwext\App\Web\Forum\AttachmentStageHandler;
use Forwext\App\Web\Forum\ThreadFreshnessHandler;
use Forwext\App\Web\Forum\VerifiedUploadedAttachmentReader;
use Forwext\App\Web\Notification\NotificationRealtimeHandler;
use Forwext\App\Web\Notification\NotificationRealtimeSseHandler;
use Forwext\App\Web\Notification\NotificationSoundCategoryHandler;
use Forwext\App\Web\Notification\NotificationSoundCsrfTokenHandler;
use Forwext\App\Web\Notification\NotificationSoundSettingsHandler;
use Forwext\App\Web\Moderation\DisciplineAccountHandler;
use Forwext\App\Web\Moderation\ThreadFreshnessPolicyHandler;
use Forwext\App\Web\Moderation\ThreadFreshnessReviewHandler;
use Forwext\App\Web\Profile\ActivityFeedHandler;
use Forwext\App\Web\Profile\AuthSessionProfileViewerResolver;
use Forwext\App\Web\Profile\CustomProfileUrlHandler;
use Forwext\App\Web\Profile\MemberDirectoryHandler;
use Forwext\App\Web\Profile\ProfileActivityCsrfTokenHandler;
use Forwext\App\Web\Profile\ProfileActivityDeleteHandler;
use Forwext\App\Web\Profile\ProfileActivitySettingsHandler;
use Forwext\App\Web\Profile\ProfileCommentsHandler;
use Forwext\App\Web\Profile\ProfileMediaHandler;
use Forwext\App\Web\Profile\ProfileMusicHandler;
use Forwext\App\Web\Profile\ProfilePostReactionHandler;
use Forwext\App\Web\Profile\ProfilePostsHandler;
use Forwext\App\Web\Profile\ProfileUrlSettingsHandler;
use Forwext\App\Web\Profile\ProfileViewHandler;
use Forwext\App\Web\Social\BookmarkListHandler;
use Forwext\App\Web\Social\InteractionCsrfTokenHandler;
use Forwext\App\Web\Social\PostBookmarkHandler;
use Forwext\App\Web\Social\PostReactionHandler;
use Forwext\App\Web\Social\UserRelationshipHandler;
use Forwext\App\Web\Search\SearchHandler;
use Forwext\App\Web\Faq\FaqArticleHandler;
use Forwext\App\Web\Faq\FaqArticleIdHandler;
use Forwext\App\Web\Faq\FaqIndexHandler;
use Forwext\App\Web\Faq\FaqManageHandler;
use Forwext\App\Web\Faq\FaqSupportDraftHandler;
use Forwext\App\Web\Support\MyTicketsHandler;
use Forwext\App\Web\Support\SupportAttachmentDownloadHandler;
use Forwext\App\Web\Support\SupportStaffDashboardHandler;
use Forwext\App\Web\Support\SupportTicketDetailHandler;
use Forwext\App\Web\Support\SupportTicketFormHandler;
use Forwext\Core\Audit\CoreAuditRecorder;
use Forwext\Core\Audit\DatabaseAuditEventStore;
use Forwext\Core\Auth\AuthenticationFingerprint;
use Forwext\Core\Auth\Credential\DatabaseCredentialStore;
use Forwext\Core\Auth\Session\AuthSessionManager;
use Forwext\Core\Bug\Conversation\DatabaseBugReportConversationRepository;
use Forwext\Core\Bug\Conversation\NotificationBugReportNotifier;
use Forwext\Core\Bug\Diagnostic\BugBrowserDeviceClassifier;
use Forwext\Core\Bug\Diagnostic\BugDiagnosticContextCollector;
use Forwext\Core\Bug\Diagnostic\DatabaseBugDiagnosticContextRepository;
use Forwext\Core\Bug\Intake\DatabaseBugReportIntakeRepository;
use Forwext\Core\Bug\Report\DatabaseBugReportRepository;
use Forwext\Core\Bug\Staff\DatabaseBugStaffRepository;
use Forwext\Core\Config\ConfigLoader;
use Forwext\Core\Config\ConfigRepository;
use Forwext\Core\Content\Manager\ContentManagerOperationProcessor;
use Forwext\Core\Content\Manager\ContentManagerService;
use Forwext\Core\Content\Manager\DatabaseContentManagerOperationRepository;
use Forwext\Core\Content\Manager\DatabaseContentManagerRepository;
use Forwext\Core\Content\Pipeline\ForumContentPipelineFactory;
use Forwext\Core\Content\Spellcheck\AuthorizerSpellcheckPermissionResolver;
use Forwext\Core\Content\Spellcheck\DatabaseSpellcheckDictionaryRepository;
use Forwext\Core\Content\Spellcheck\SpellcheckProviderRegistry;
use Forwext\Core\Content\Spellcheck\SpellcheckService;
use Forwext\Core\Content\Spellcheck\TurkishSpellcheckProvider;
use Forwext\Core\Database\DatabaseConfig;
use Forwext\Core\Database\DatabaseConnection;
use Forwext\Core\Database\PdoConnectionFactory;
use Forwext\Core\Domain\Access\DatabaseUserAccessAssignmentProvider;
use Forwext\Core\Domain\Access\Permission\DatabasePermissionRuleRepository;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionEngine;
use Forwext\Core\Domain\User\DatabaseUserRepository;
use Forwext\Core\Faq\DatabaseFaqRepository;
use Forwext\Core\Faq\FaqService;
use Forwext\Core\Faq\SupportBridge\DatabaseFaqSupportBridgeRepository;
use Forwext\Core\Faq\SupportBridge\FaqSupportBridgeService;
use Forwext\Core\Faq\Search\FaqSearchAccessScopeProvider;
use Forwext\Core\Forum\Attachment\AttachmentInspector;
use Forwext\Core\Forum\Attachment\AttachmentQuotaPolicy;
use Forwext\Core\Forum\Attachment\DatabaseAttachmentRepository;
use Forwext\Core\Forum\Attachment\GdAttachmentThumbnailGenerator;
use Forwext\Core\Forum\Attachment\ImageMetadataSanitizer;
use Forwext\Core\Forum\Editor\BbCodeRenderer;
use Forwext\Core\Forum\Editor\CrossThreadQuoteService;
use Forwext\Core\Forum\Editor\DatabaseMentionSuggestionProvider;
use Forwext\Core\Forum\Editor\EditorLimits;
use Forwext\Core\Forum\Editor\EditorPreviewService;
use Forwext\Core\Forum\Editor\LinkPreviewService;
use Forwext\Core\Forum\Editor\LinkPreviewUrlPolicy;
use Forwext\Core\Forum\Editor\NativeHostAddressResolver;
use Forwext\Core\Forum\Editor\PinnedHttpsLinkPreviewTransport;
use Forwext\Core\Forum\Editor\SafeEditorLinkPolicy;
use Forwext\Core\Forum\Editor\SafeLinkEmbedResolver;
use Forwext\Core\Forum\Editor\UserMentionResolver;
use Forwext\Core\Forum\Freshness\DatabaseThreadFreshnessRepository;
use Forwext\Core\Forum\Freshness\ThreadFreshnessNotifier;
use Forwext\Core\Forum\Freshness\ThreadFreshnessService;
use Forwext\Core\Forum\Moderation\DatabaseContentModerationRepository;
use Forwext\Core\Forum\Moderation\DatabaseModerationAuditStore;
use Forwext\Core\Forum\Node\DatabaseForumNodeRepository;
use Forwext\Core\Forum\Post\DatabasePostRepository;
use Forwext\Core\Forum\Thread\DatabaseThreadRepository;
use Forwext\Core\Forum\Thread\ThreadTypeRegistry;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Http\Security\Csrf\CsrfTokenManager;
use Forwext\Core\Notification\DatabaseNotificationRepository;
use Forwext\Core\Notification\NotificationDispatcher;
use Forwext\Core\Notification\NotificationRegistry;
use Forwext\Core\Notification\Realtime\DatabaseNotificationRealtimeReader;
use Forwext\Core\Notification\Realtime\NotificationRealtimeService;
use Forwext\Core\Notification\Sound\DatabaseNotificationSoundRepository;
use Forwext\Core\Notification\Sound\EngineNotificationSoundPermissionResolver;
use Forwext\Core\Notification\Sound\NotificationSoundCatalog;
use Forwext\Core\Notification\Sound\NotificationSoundService;
use Forwext\Core\Moderation\Discipline\DatabaseDisciplineAuthenticationAvailability;
use Forwext\Core\Moderation\Discipline\DatabaseDisciplineRepository;
use Forwext\Core\Profile\Activity\ActivityFeedService;
use Forwext\Core\Realtime\DatabaseRealtimeMessageStore;
use Forwext\Core\Realtime\PollingRealtimeTransport;
use Forwext\Core\Realtime\RealtimeMode;
use Forwext\Core\Profile\Activity\DatabaseActivityFeedRepository;
use Forwext\Core\Profile\Activity\DatabaseProfileActivityRepository;
use Forwext\Core\Profile\Activity\ProfileActivityService;
use Forwext\Core\Profile\DatabaseProfileStore;
use Forwext\Core\Profile\Music\DatabaseProfileMusicStore;
use Forwext\Core\Profile\Music\EngineProfileMusicPermissionResolver;
use Forwext\Core\Profile\Music\ProfileMusicExternalPolicy;
use Forwext\Core\Profile\Music\ProfileMusicService;
use Forwext\Core\Profile\OwnerSafeProfileAccessPolicy;
use Forwext\Core\Profile\ProfileDirectoryReader;
use Forwext\Core\Profile\ProfileMediaKind;
use Forwext\Core\Profile\ProfileMediaService;
use Forwext\Core\Profile\ProfileService;
use Forwext\Core\Profile\Url\DatabaseProfileUrlStore;
use Forwext\Core\Profile\Url\EngineProfileUrlPermissionResolver;
use Forwext\Core\Profile\Url\ProfileSlugPolicy;
use Forwext\Core\Profile\Url\ProfileUrlService;
use Forwext\Core\Queue\DatabaseQueueDriver;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\RuntimeCanonicalUrlResolver;
use Forwext\Core\Routing\PathTemplate;
use Forwext\Core\Routing\Route;
use Forwext\Core\Routing\RouteCollection;
use Forwext\Core\Routing\Router;
use Forwext\Core\Security\Secret\EncryptedFileSecretStore;
use Forwext\Core\Security\Secret\EnvironmentOrFileSecretKeyProvider;
use Forwext\Core\Security\Secret\SecretCipher;
use Forwext\Core\Security\Secret\SecretKey;
use Forwext\Core\Search\Access\ForumSearchAccessScopeProvider;
use Forwext\Core\Search\Access\PublicSearchAccessScopeProvider;
use Forwext\Core\Search\Lifecycle\DatabaseSearchIndexChangeStore;
use Forwext\Core\Search\NativeDatabaseSearchDriver;
use Forwext\Core\Search\ResilientSearchDriver;
use Forwext\Core\Search\PermissionAwareSearchService;
use Forwext\Core\Search\Saved\SavedSearchQueryRegistry;
use Forwext\Core\Session\DatabaseSessionStore;
use Forwext\Core\Session\FileSessionStore;
use Forwext\Core\Session\SessionStore;
use Forwext\Core\Social\Interaction\DatabaseSocialInteractionRepository;
use Forwext\Core\Social\Interaction\SocialInteractionService;
use Forwext\Core\Storage\LocalStorageDriver;
use Forwext\Core\Support\Conversation\DatabaseSupportConversationRepository;
use Forwext\Core\Support\Conversation\NotificationSupportTicketNotifier;
use Forwext\Core\Support\Intake\AccountSupportContextResolver;
use Forwext\Core\Support\Intake\DatabaseSupportSubmissionRateLimiter;
use Forwext\Core\Support\Intake\DatabaseSupportTicketIntakeRepository;
use Forwext\Core\Support\Intake\MarketplaceSupportContextResolver;
use Forwext\Core\Support\Intake\SupportContextRegistry;
use Forwext\Core\Support\Intake\ThreadSupportContextResolver;
use Forwext\Core\Support\Reporting\DatabaseSupportReportingRepository;
use Forwext\Core\Support\Ticket\DatabaseSupportTicketRepository;
use RuntimeException;

final readonly class WebApplicationFactory
{
    public function __construct(private string $projectRoot)
    {
        if ($projectRoot === '') throw new RuntimeException('Project root cannot be empty.');
    }

    public function create(string $version): Router
    {
        $config = $this->config();
        $database = $this->database($config);
        $authorizer = $this->permissionAuthorizer($database);
        $users = new DatabaseUserRepository($database);
        $discipline = new DatabaseDisciplineRepository($database);
        $profileStore = new DatabaseProfileStore($database);
        $accessPolicy = new OwnerSafeProfileAccessPolicy();
        $profileService = new ProfileService($profileStore, $accessPolicy);
        $sessions = new AuthSessionManager(
            $this->sessionStore($config, $database),
            new DatabaseCredentialStore($database),
            $config->requireInt('authentication.session.ttl_seconds'),
        );
        $disciplineAccountViewerResolver = new AuthSessionProfileViewerResolver(
            $sessions,
            $users,
            $config->requireString('authentication.session.cookie_name'),
        );
        $viewerResolver = new AuthSessionProfileViewerResolver(
            $sessions,
            $users,
            $config->requireString('authentication.session.cookie_name'),
            new DatabaseDisciplineAuthenticationAvailability($database),
        );
        $storage = $this->localStorage($config);
        $mediaService = new ProfileMediaService($profileStore, $storage, $accessPolicy);
        $musicService = new ProfileMusicService(
            new DatabaseProfileMusicStore($database),
            $storage,
            $profileService,
            $accessPolicy,
            new EngineProfileMusicPermissionResolver($authorizer),
            $this->externalMusicPolicy($config),
            $config->requireInt('profile_music.upload_max_bytes'),
            $config->requireInt('profile_music.default_volume'),
        );
        $profileUrlService = new ProfileUrlService(
            new DatabaseProfileUrlStore($database),
            $accessPolicy,
            new EngineProfileUrlPermissionResolver($authorizer),
            new ProfileSlugPolicy($this->profileUrlReservedNames($config)),
            $config->requireInt('profile_url.minimum_change_interval_seconds'),
            $config->requireInt('profile_url.change_window_seconds'),
            $config->requireInt('profile_url.maximum_changes_per_window'),
        );
        $basePath = $this->basePath($config);
        $profilePage = new ProfileViewHandler($users, $profileService, $accessPolicy, $viewerResolver, $basePath, $musicService);

        $editorLinks = new SafeEditorLinkPolicy();
        $editorPreview = new EditorPreviewService(
            new BbCodeRenderer(
                $editorLinks,
                new UserMentionResolver($users, $basePath),
                new SafeLinkEmbedResolver($editorLinks),
            ),
            new EditorLimits(),
        );
        $mentionSuggestions = new DatabaseMentionSuggestionProvider($database);
        $posts = new DatabasePostRepository($database);
        $threads = new DatabaseThreadRepository($database, ThreadTypeRegistry::withCoreDefaults());
        $nodes = new DatabaseForumNodeRepository($database);
        $searchChanges = new DatabaseSearchIndexChangeStore($database);
        $contentGovernanceAudit = new CoreAuditRecorder($database, new DatabaseAuditEventStore($database));
        $spellcheck = new SpellcheckService(
            new SpellcheckProviderRegistry([new TurkishSpellcheckProvider()]),
            new DatabaseSpellcheckDictionaryRepository($database),
            new AuthorizerSpellcheckPermissionResolver($authorizer),
            $contentGovernanceAudit,
        );
        $contentManagerQueue = new DatabaseQueueDriver($database);
        $contentManagerRepository = new DatabaseContentManagerRepository($database);
        $contentManagerOperations = new DatabaseContentManagerOperationRepository($database);
        $contentManagerPipeline = ForumContentPipelineFactory::create(
            $database,
            $searchChanges,
            spellcheck: $spellcheck,
        );
        $contentManagerModeration = new DatabaseContentModerationRepository(
            $database,
            new DatabaseModerationAuditStore($database),
        );
        $contentManagerProcessor = new ContentManagerOperationProcessor(
            $contentManagerOperations,
            $contentManagerRepository,
            $contentManagerModeration,
            $searchChanges,
            $contentManagerPipeline,
            $authorizer,
        );
        $contentManager = new ContentManagerService(
            $contentManagerRepository,
            $contentManagerOperations,
            $nodes,
            $authorizer,
            $contentManagerQueue,
            $contentGovernanceAudit,
        );
        $freshnessRepository = new DatabaseThreadFreshnessRepository($database);
        $freshnessNotificationRegistry = new NotificationRegistry();
        ThreadFreshnessNotifier::registerDefinitions($freshnessNotificationRegistry);
        $freshnessNotifier = new ThreadFreshnessNotifier(new NotificationDispatcher(
            $freshnessNotificationRegistry,
            new DatabaseNotificationRepository($database),
        ));
        $freshness = new ThreadFreshnessService(
            $freshnessRepository,
            $nodes,
            $authorizer,
            $searchChanges,
            $freshnessNotifier,
            $contentGovernanceAudit,
        );
        $faqRepository = new DatabaseFaqRepository($database);
        $faq = new FaqService(
            $database,
            $faqRepository,
            $authorizer,
            $searchChanges,
        );
        $faqSupportBridge = new FaqSupportBridgeService(
            $database,
            $faq,
            $faqRepository,
            new DatabaseFaqSupportBridgeRepository($database),
            $authorizer,
        );
        $searchService = new PermissionAwareSearchService(
            new ResilientSearchDriver(new NativeDatabaseSearchDriver($database)),
            $authorizer,
            [
                new PublicSearchAccessScopeProvider(),
                new ForumSearchAccessScopeProvider($nodes, $authorizer),
                new FaqSearchAccessScopeProvider($authorizer),
            ],
            new SavedSearchQueryRegistry(),
        );
        $quotes = new CrossThreadQuoteService($posts, $threads, $users);
        $linkPreviews = new LinkPreviewService(
            new LinkPreviewUrlPolicy(new NativeHostAddressResolver()),
            new PinnedHttpsLinkPreviewTransport(),
        );

        $socialRepository = new DatabaseSocialInteractionRepository($database);
        $socialInteractions = new SocialInteractionService(
            $socialRepository,
            $posts,
            $threads,
            $users,
            $authorizer,
        );
        $profileActivityRepository = new DatabaseProfileActivityRepository($database);
        $profileActivity = new ProfileActivityService(
            $profileActivityRepository,
            $socialRepository,
            $users,
            $authorizer,
        );
        $activityFeed = new ActivityFeedService(
            new DatabaseActivityFeedRepository($database),
            $profileActivityRepository,
            $profileActivity,
            $socialRepository,
            $authorizer,
        );

        $notificationSound = new NotificationSoundService(
            new DatabaseNotificationSoundRepository($database),
            new EngineNotificationSoundPermissionResolver($authorizer),
            NotificationSoundCatalog::coreDefaults(),
        );
        $notificationRealtime = new NotificationRealtimeService(
            new PollingRealtimeTransport(new DatabaseRealtimeMessageStore($database)),
            new DatabaseNotificationRealtimeReader($database),
            $authorizer,
        );
        $realtimeMode = $this->realtimeMode($config);
        $websocketPath = $this->realtimeWebsocketPath($config);

        $attachmentQuota = new AttachmentQuotaPolicy();
        $attachmentInspector = new AttachmentInspector(new ImageMetadataSanitizer(), $attachmentQuota);
        $secretStore = new EncryptedFileSecretStore(
            $this->projectPath($config->requireString('security.secret_store_path')),
            new SecretCipher($this->masterKey($config)),
        );
        $bugReports = new DatabaseBugReportRepository($database);
        $bugDiagnostics = new DatabaseBugDiagnosticContextRepository($database);
        $bugIntake = new DatabaseBugReportIntakeRepository($database);
        $bugConversation = new DatabaseBugReportConversationRepository($database);
        $bugStaff = new DatabaseBugStaffRepository($database);
        $bugAudit = new CoreAuditRecorder($database, new DatabaseAuditEventStore($database));
        $bugNotificationRegistry = new NotificationRegistry();
        NotificationBugReportNotifier::registerDefinitions($bugNotificationRegistry);
        $bugNotifier = new NotificationBugReportNotifier(new NotificationDispatcher(
            $bugNotificationRegistry,
            new DatabaseNotificationRepository($database),
        ));
        $bugDiagnosticCollector = new BugDiagnosticContextCollector(new BugBrowserDeviceClassifier(
            new AuthenticationFingerprint(
                $secretStore,
                $config->requireString('authentication.fingerprint_secret_name'),
            ),
        ));
        $attachmentServices = new AttachmentServiceResolver(
            new DatabaseAttachmentRepository($database, $attachmentQuota),
            $posts,
            $threads,
            $nodes,
            $storage,
            $attachmentInspector,
            new GdAttachmentThumbnailGenerator($attachmentQuota),
            $attachmentQuota,
            $authorizer,
        );
        $supportTickets = new DatabaseSupportTicketRepository($database);
        $supportIntake = new DatabaseSupportTicketIntakeRepository($database);
        $supportConversation = new DatabaseSupportConversationRepository($database);
        $supportReporting = new DatabaseSupportReportingRepository($database);
        $supportAudit = new CoreAuditRecorder($database, new DatabaseAuditEventStore($database));
        $supportNotificationRegistry = new NotificationRegistry();
        NotificationSupportTicketNotifier::registerDefinitions($supportNotificationRegistry);
        $supportNotifier = new NotificationSupportTicketNotifier(new NotificationDispatcher(
            $supportNotificationRegistry,
            new DatabaseNotificationRepository($database),
        ));
        $supportContexts = new SupportContextRegistry([
            new ThreadSupportContextResolver($threads, $authorizer),
            new AccountSupportContextResolver($users, $authorizer),
            new MarketplaceSupportContextResolver(),
        ]);

        $attachmentCsrf = $this->attachmentCsrfMiddleware($config);
        $supportCsrf = $this->supportCsrfMiddleware($config);
        $bugCsrf = $this->bugCsrfMiddleware($config);
        $faqCsrf = $this->faqCsrfMiddleware($config);
        $interactionCsrf = $this->interactionCsrfMiddleware($config);
        $profileActivityCsrf = $this->profileActivityCsrfMiddleware($config);
        $notificationSoundCsrf = $this->notificationSoundCsrfMiddleware($config);
        $spellcheckDictionaryCsrf = $this->spellcheckDictionaryCsrfMiddleware($config);
        $contentManagerCsrf = $this->contentManagerCsrfMiddleware($config);
        $freshnessCsrf = $this->freshnessCsrfMiddleware($config);

        $routes = new RouteCollection();
        $routes->add(new Route('home', [HttpMethod::Get], new PathTemplate('/'), new HomeHandler($version, $basePath)));
        $routes->add(new Route(
            'bug.report.create',
            [HttpMethod::Get, HttpMethod::Post],
            new PathTemplate('/bugs/report'),
            new BugReportFormHandler(
                $database,
                $bugReports,
                $bugDiagnostics,
                $bugIntake,
                $bugDiagnosticCollector,
                $storage,
                $attachmentInspector,
                $attachmentQuota,
                $viewerResolver,
                $authorizer,
                new VerifiedUploadedAttachmentReader(),
                $basePath,
            ),
            [$bugCsrf],
        ));
        $routes->add(new Route(
            'bug.reports.mine',
            [HttpMethod::Get],
            new PathTemplate('/bugs'),
            new MyBugReportsHandler(
                $database,
                $bugReports,
                $viewerResolver,
                $authorizer,
                $basePath,
            ),
        ));
        $routes->add(new Route(
            'bug.staff.dashboard',
            [HttpMethod::Get],
            new PathTemplate('/bugs/staff'),
            new BugStaffDashboardHandler(
                $database,
                $bugReports,
                $bugStaff,
                $viewerResolver,
                $authorizer,
                $users,
                $bugNotifier,
                $bugAudit,
                $basePath,
            ),
        ));
        $routes->add(new Route(
            'bug.staff.export',
            [HttpMethod::Get],
            new PathTemplate('/bugs/staff/export.csv'),
            new BugStaffExportHandler(
                $database,
                $bugReports,
                $bugStaff,
                $viewerResolver,
                $authorizer,
                $users,
                $bugNotifier,
                $bugAudit,
            ),
        ));
        $routes->add(new Route(
            'bug.report.detail',
            [HttpMethod::Get, HttpMethod::Post],
            new PathTemplate('/bugs/{reportId}', ['reportId'=>'[0-9a-f]{32}']),
            new BugReportDetailHandler(
                $database,
                $bugReports,
                $bugIntake,
                $bugConversation,
                $bugStaff,
                $viewerResolver,
                $authorizer,
                $users,
                $bugNotifier,
                $bugAudit,
                $basePath,
            ),
            [$bugCsrf],
        ));
        $routes->add(new Route(
            'bug.report.attachment',
            [HttpMethod::Get],
            new PathTemplate(
                '/bugs/{reportId}/attachments/{attachmentId}',
                ['reportId'=>'[0-9a-f]{32}','attachmentId'=>'[0-9a-f]{32}'],
            ),
            new BugAttachmentDownloadHandler(
                $database,
                $bugReports,
                $bugIntake,
                $storage,
                $viewerResolver,
                $authorizer,
                new AttachmentDownloadResponseFactory(),
            ),
        ));
        $routes->add(new Route(
            'faq.index',
            [HttpMethod::Get],
            new PathTemplate('/faq'),
            new FaqIndexHandler($faq, $viewerResolver, $basePath),
        ));
        $routes->add(new Route(
            'faq.manage',
            [HttpMethod::Get, HttpMethod::Post],
            new PathTemplate('/faq/manage'),
            new FaqManageHandler($faq, $viewerResolver, $basePath),
            [$faqCsrf],
        ));
        $routes->add(new Route(
            'faq.manage.support-drafts',
            [HttpMethod::Get, HttpMethod::Post],
            new PathTemplate('/faq/manage/support-drafts'),
            new FaqSupportDraftHandler($faq, $faqSupportBridge, $viewerResolver, $basePath),
            [$faqCsrf],
        ));
        $routes->add(new Route(
            'faq.article.id',
            [HttpMethod::Get],
            new PathTemplate('/faq/articles/{articleId}', ['articleId'=>'[0-9a-f]{32}']),
            new FaqArticleIdHandler($faq, $viewerResolver, $basePath),
        ));
        $routes->add(new Route(
            'faq.article',
            [HttpMethod::Get, HttpMethod::Post],
            new PathTemplate(
                '/faq/{language}/{slug}',
                [
                    'language'=>'[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*',
                    'slug'=>'[a-z0-9][a-z0-9-]{1,159}',
                ],
            ),
            new FaqArticleHandler($faq, $viewerResolver, $basePath),
            [$faqCsrf],
        ));
        $routes->add(new Route(
            'support.ticket.new',
            [HttpMethod::Get, HttpMethod::Post],
            new PathTemplate('/support/new'),
            new SupportTicketFormHandler(
                $database,
                $supportTickets,
                $supportIntake,
                $supportConversation,
                $faqSupportBridge,
                new DatabaseSupportSubmissionRateLimiter($database),
                $supportContexts,
                $storage,
                $attachmentInspector,
                $attachmentQuota,
                $viewerResolver,
                $authorizer,
                new VerifiedUploadedAttachmentReader(),
                $basePath,
                $supportAudit,
            ),
            [$supportCsrf],
        ));
        $routes->add(new Route(
            'support.tickets.mine',
            [HttpMethod::Get],
            new PathTemplate('/support/tickets'),
            new MyTicketsHandler(
                $supportTickets,
                $supportReporting,
                $viewerResolver,
                $authorizer,
                $basePath,
            ),
        ));
        $routes->add(new Route(
            'support.staff.dashboard',
            [HttpMethod::Get],
            new PathTemplate('/support/staff'),
            new SupportStaffDashboardHandler(
                $supportTickets,
                $supportReporting,
                $viewerResolver,
                $authorizer,
                $basePath,
            ),
        ));
        $routes->add(new Route(
            'support.ticket.detail',
            [HttpMethod::Get, HttpMethod::Post],
            new PathTemplate('/support/tickets/{ticketId}', ['ticketId'=>'[0-9a-f]{32}']),
            new SupportTicketDetailHandler(
                $database,
                $supportTickets,
                $supportIntake,
                $supportConversation,
                $faqSupportBridge,
                $viewerResolver,
                $authorizer,
                $users,
                $supportNotifier,
                $basePath,
                $supportAudit,
            ),
            [$supportCsrf],
        ));
        $routes->add(new Route(
            'support.ticket.attachment',
            [HttpMethod::Get],
            new PathTemplate(
                '/support/tickets/{ticketId}/attachments/{attachmentId}',
                ['ticketId'=>'[0-9a-f]{32}','attachmentId'=>'[0-9a-f]{32}'],
            ),
            new SupportAttachmentDownloadHandler(
                $database,
                $supportTickets,
                $supportIntake,
                $storage,
                $viewerResolver,
                $authorizer,
                new AttachmentDownloadResponseFactory(),
            ),
        ));
        $routes->add(new Route('search.index', [HttpMethod::Get], new PathTemplate('/search'), new SearchHandler($searchService, $viewerResolver, $basePath)));
        $routes->add(new Route(
            'content-manager.index', [HttpMethod::Get, HttpMethod::Post], new PathTemplate('/content-manager'),
            new ContentManagerHandler($contentManager, $contentManagerProcessor, $users, $viewerResolver, $basePath),
            [$contentManagerCsrf],
        ));
        $routes->add(new Route(
            'content-manager.operation', [HttpMethod::Get, HttpMethod::Post],
            new PathTemplate('/content-manager/operations/{operationId}', ['operationId'=>'[0-9a-f]{32}']),
            new ContentManagerOperationHandler($contentManager, $contentManagerProcessor, $viewerResolver, $basePath),
            [$contentManagerCsrf],
        ));
        $routes->add(new Route(
            'thread.freshness', [HttpMethod::Get, HttpMethod::Post],
            new PathTemplate('/threads/{threadId}/freshness', ['threadId'=>'[0-9a-f]{32}']),
            new ThreadFreshnessHandler($freshness, $viewerResolver, $basePath),
            [$freshnessCsrf],
        ));
        $routes->add(new Route(
            'moderation.freshness', [HttpMethod::Get, HttpMethod::Post],
            new PathTemplate('/moderation/freshness'),
            new ThreadFreshnessReviewHandler($freshness, $viewerResolver, $basePath),
            [$freshnessCsrf],
        ));
        $routes->add(new Route(
            'moderation.freshness.policy', [HttpMethod::Get, HttpMethod::Post],
            new PathTemplate('/moderation/freshness/policy'),
            new ThreadFreshnessPolicyHandler($freshness, $nodes, $viewerResolver, $basePath),
            [$freshnessCsrf],
        ));
        $routes->add(new Route('editor.preview', [HttpMethod::Post], new PathTemplate('/editor/preview'), new EditorPreviewHandler($editorPreview, $viewerResolver)));
        $routes->add(new Route('editor.spellcheck', [HttpMethod::Post], new PathTemplate('/editor/spellcheck'), new EditorSpellcheckHandler($spellcheck, $viewerResolver)));
        $routes->add(new Route('editor.mention', [HttpMethod::Get], new PathTemplate('/editor/mention'), new EditorMentionLookupHandler($users, $viewerResolver, $basePath, $mentionSuggestions)));
        $routes->add(new Route('editor.quote', [HttpMethod::Get], new PathTemplate('/editor/quote'), new EditorQuoteHandler($quotes, $viewerResolver, $authorizer)));
        $routes->add(new Route('editor.link-preview', [HttpMethod::Post], new PathTemplate('/editor/link-preview'), new EditorLinkPreviewHandler($linkPreviews, $viewerResolver)));
        $routes->add(new Route(
            'account.spellcheck-dictionary', [HttpMethod::Get, HttpMethod::Post],
            new PathTemplate('/account/spellcheck-dictionary'),
            new SpellcheckDictionaryHandler($spellcheck, $viewerResolver, $basePath),
            [$spellcheckDictionaryCsrf],
        ));
        $routes->add(new Route(
            'forum.attachment.stage', [HttpMethod::Post], new PathTemplate('/forums/{forumId}/attachments'),
            new AttachmentStageHandler($attachmentServices, $viewerResolver, new VerifiedUploadedAttachmentReader()), [$attachmentCsrf],
        ));
        $routes->add(new Route(
            'forum.attachment.finalize', [HttpMethod::Post], new PathTemplate('/attachments/{attachmentId}/finalize'),
            new AttachmentFinalizeHandler($attachmentServices, $viewerResolver), [$attachmentCsrf],
        ));
        $routes->add(new Route(
            'forum.attachment.download', [HttpMethod::Get], new PathTemplate('/attachments/{attachmentId}'),
            new AttachmentDownloadHandler($attachmentServices, $viewerResolver, new AttachmentDownloadResponseFactory()),
        ));

        $routes->add(new Route(
            'interactions.csrf', [HttpMethod::Get], new PathTemplate('/account/interactions/csrf'),
            new InteractionCsrfTokenHandler($viewerResolver), [$interactionCsrf],
        ));
        $routes->add(new Route(
            'post.reactions', [HttpMethod::Get, HttpMethod::Put, HttpMethod::Delete], new PathTemplate('/posts/{postId}/reactions'),
            new PostReactionHandler($socialInteractions, $viewerResolver), [$interactionCsrf],
        ));
        $routes->add(new Route(
            'post.bookmark', [HttpMethod::Put, HttpMethod::Delete], new PathTemplate('/posts/{postId}/bookmark'),
            new PostBookmarkHandler($socialInteractions, $viewerResolver), [$interactionCsrf],
        ));
        $routes->add(new Route(
            'account.bookmarks', [HttpMethod::Get], new PathTemplate('/account/bookmarks'),
            new BookmarkListHandler($socialInteractions, $viewerResolver), [$interactionCsrf],
        ));
        $routes->add(new Route(
            'user.follow', [HttpMethod::Put, HttpMethod::Delete], new PathTemplate('/users/{userId}/follow'),
            new UserRelationshipHandler($socialInteractions, $viewerResolver, false), [$interactionCsrf],
        ));
        $routes->add(new Route(
            'user.ignore', [HttpMethod::Put, HttpMethod::Delete], new PathTemplate('/users/{userId}/ignore'),
            new UserRelationshipHandler($socialInteractions, $viewerResolver, true), [$interactionCsrf],
        ));

        $routes->add(new Route(
            'profile-activity.csrf', [HttpMethod::Get], new PathTemplate('/account/profile-activity/csrf'),
            new ProfileActivityCsrfTokenHandler($viewerResolver), [$profileActivityCsrf],
        ));
        $routes->add(new Route(
            'profile.posts', [HttpMethod::Get, HttpMethod::Post], new PathTemplate('/users/{userId}/profile-posts'),
            new ProfilePostsHandler($profileActivity, $viewerResolver), [$profileActivityCsrf],
        ));
        $routes->add(new Route(
            'profile.comments', [HttpMethod::Get, HttpMethod::Post], new PathTemplate('/profile-posts/{profilePostId}/comments'),
            new ProfileCommentsHandler($profileActivity, $viewerResolver), [$profileActivityCsrf],
        ));
        $routes->add(new Route(
            'profile.reactions', [HttpMethod::Get, HttpMethod::Put, HttpMethod::Delete], new PathTemplate('/profile-posts/{profilePostId}/reactions'),
            new ProfilePostReactionHandler($profileActivity, $viewerResolver), [$profileActivityCsrf],
        ));
        $routes->add(new Route(
            'profile.post.delete', [HttpMethod::Delete], new PathTemplate('/profile-posts/{profilePostId}'),
            new ProfileActivityDeleteHandler($profileActivity, $viewerResolver, false), [$profileActivityCsrf],
        ));
        $routes->add(new Route(
            'profile.comment.delete', [HttpMethod::Delete], new PathTemplate('/profile-comments/{commentId}'),
            new ProfileActivityDeleteHandler($profileActivity, $viewerResolver, true), [$profileActivityCsrf],
        ));
        $routes->add(new Route(
            'account.profile-activity', [HttpMethod::Get, HttpMethod::Put], new PathTemplate('/account/profile-activity'),
            new ProfileActivitySettingsHandler($profileActivity, $viewerResolver), [$profileActivityCsrf],
        ));
        $routes->add(new Route(
            'activity.feed', [HttpMethod::Get], new PathTemplate('/activity'),
            new ActivityFeedHandler($activityFeed, $viewerResolver),
        ));

        $routes->add(new Route(
            'account.discipline', [HttpMethod::Get], new PathTemplate('/account/discipline'),
            new DisciplineAccountHandler($discipline, $disciplineAccountViewerResolver, $basePath),
        ));

        $routes->add(new Route(
            'account.notifications.realtime', [HttpMethod::Get], new PathTemplate('/account/notifications/realtime'),
            new NotificationRealtimeHandler(
                $notificationRealtime,
                $viewerResolver,
                $basePath,
                $realtimeMode,
                $config->requireInt('realtime.poll_interval_ms'),
                $config->requireInt('realtime.hidden_poll_interval_ms'),
                min(100, $config->requireInt('realtime.poll_limit')),
                $websocketPath,
            ),
        ));
        $routes->add(new Route(
            'account.notifications.realtime.sse', [HttpMethod::Get], new PathTemplate('/account/notifications/realtime/sse'),
            new NotificationRealtimeSseHandler(
                $notificationRealtime,
                $viewerResolver,
                min(100, $config->requireInt('realtime.poll_limit')),
                $config->requireInt('realtime.sse_retry_ms'),
            ),
        ));

        $routes->add(new Route(
            'notification-sound.csrf', [HttpMethod::Get], new PathTemplate('/account/notification-sound/csrf'),
            new NotificationSoundCsrfTokenHandler($viewerResolver), [$notificationSoundCsrf],
        ));
        $routes->add(new Route(
            'account.notification-sound', [HttpMethod::Get, HttpMethod::Put], new PathTemplate('/account/notification-sound'),
            new NotificationSoundSettingsHandler($notificationSound, $viewerResolver, $basePath), [$notificationSoundCsrf],
        ));
        $routes->add(new Route(
            'account.notification-sound.category', [HttpMethod::Put, HttpMethod::Delete],
            new PathTemplate('/account/notification-sound/categories/{categoryKey}', ['categoryKey' => '[a-z][a-z0-9_.-]{1,95}']),
            new NotificationSoundCategoryHandler($notificationSound, $viewerResolver), [$notificationSoundCsrf],
        ));

        $routes->add(new Route('members.index', [HttpMethod::Get], new PathTemplate('/members'), new MemberDirectoryHandler(new ProfileDirectoryReader($database), $basePath)));
        $routes->add(new Route('members.profile', [HttpMethod::Get], new PathTemplate('/members/{username}'), $profilePage));
        $routes->add(new Route('members.avatar', [HttpMethod::Get], new PathTemplate('/members/{username}/avatar'), new ProfileMediaHandler($users, $mediaService, $viewerResolver, ProfileMediaKind::Avatar)));
        $routes->add(new Route('members.banner', [HttpMethod::Get], new PathTemplate('/members/{username}/banner'), new ProfileMediaHandler($users, $mediaService, $viewerResolver, ProfileMediaKind::Banner)));
        $routes->add(new Route('members.music', [HttpMethod::Get], new PathTemplate('/members/{username}/music'), new ProfileMusicHandler($users, $musicService, $viewerResolver)));
        $routes->add(new Route(
            'profiles.custom', [HttpMethod::Get], new PathTemplate('/u/{slug}'),
            new CustomProfileUrlHandler($profileUrlService, $users, $profileService, $viewerResolver, $profilePage, $basePath),
        ));
        $routes->add(new Route(
            'account.profile-url', [HttpMethod::Get, HttpMethod::Post], new PathTemplate('/account/profile-url'),
            new ProfileUrlSettingsHandler($profileUrlService, $viewerResolver, $basePath), [$this->profileUrlCsrfMiddleware($config)],
        ));

        return new Router($routes, $basePath);
    }

    public function contentSecurityPolicy(): string
    {
        $sources = ["'self'"];
        foreach ($this->externalMusicPolicy($this->config())->allowedHosts() as $host) $sources[] = 'https://' . $host;
        return "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data:; media-src " . implode(' ', $sources) . "; connect-src 'self'; "
            . "object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'";
    }

    private function permissionAuthorizer(DatabaseConnection $database): PermissionAuthorizer
    {
        return new PermissionAuthorizer(
            new PermissionEngine(new DatabasePermissionRuleRepository($database)),
            new DatabaseUserAccessAssignmentProvider($database),
        );
    }

    private function config(): ConfigRepository
    {
        return (new ConfigLoader())->load($this->projectRoot . '/config/defaults.php', $this->projectRoot . '/config/generated.php');
    }

    private function database(ConfigRepository $config): DatabaseConnection
    {
        $secrets = new EncryptedFileSecretStore(
            $this->projectPath($config->requireString('security.secret_store_path')),
            new SecretCipher($this->masterKey($config)),
        );
        $password = $secrets->get($config->requireString('database.password_secret'));
        if ($password === null) throw new RuntimeException('Database password secret is unavailable.');
        $socket = $config->get('database.unix_socket');
        if ($socket !== null && !is_string($socket)) throw new RuntimeException('Database unix socket configuration is invalid.');
        return (new PdoConnectionFactory())->create(new DatabaseConfig(
            $config->requireString('database.host'), $config->requireInt('database.port'), $config->requireString('database.name'),
            $config->requireString('database.username'), $password, $config->requireString('database.charset'),
            $config->requireInt('database.connect_timeout_seconds'), $socket,
        ));
    }

    private function realtimeMode(ConfigRepository $config): RealtimeMode
    {
        try {
            return RealtimeMode::from($config->requireString('realtime.mode'));
        } catch (\ValueError $exception) {
            throw new RuntimeException('Configured realtime mode is invalid.', previous: $exception);
        }
    }

    private function realtimeWebsocketPath(ConfigRepository $config): ?string
    {
        $path = $config->get('realtime.websocket_path');
        if ($path === null || $path === '') return null;
        if (!is_string($path) || !str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '?') || str_contains($path, '#')) {
            throw new RuntimeException('Realtime websocket path must be a same-origin absolute path.');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) throw new RuntimeException('Realtime websocket path contains control characters.');
        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') throw new RuntimeException('Realtime websocket path contains an ambiguous segment.');
        }
        return $path;
    }

    private function freshnessCsrfMiddleware(ConfigRepository $config): CsrfMiddleware
    {
        return $this->csrfMiddleware($config, 'thread-freshness', 'forwext.csrf.thread-freshness.v1');
    }

    private function contentManagerCsrfMiddleware(ConfigRepository $config): CsrfMiddleware
    {
        return $this->csrfMiddleware($config, 'content-manager', 'forwext.csrf.content-manager.v1');
    }

    private function spellcheckDictionaryCsrfMiddleware(ConfigRepository $config): CsrfMiddleware
    {
        return $this->csrfMiddleware($config, 'spellcheck-dictionary', 'forwext.csrf.spellcheck-dictionary.v1');
    }

    private function profileUrlCsrfMiddleware(ConfigRepository $config): CsrfMiddleware
    {
        return $this->csrfMiddleware($config, 'profile-url', 'forwext.csrf.profile-url.v1');
    }

    private function attachmentCsrfMiddleware(ConfigRepository $config): CsrfMiddleware
    {
        return $this->csrfMiddleware($config, 'attachment', 'forwext.csrf.attachment.v1');
    }

    private function supportCsrfMiddleware(ConfigRepository $config): CsrfMiddleware
    {
        return $this->csrfMiddleware($config, 'support-ticket', 'forwext.csrf.support-ticket.v1');
    }

    private function bugCsrfMiddleware(ConfigRepository $config): CsrfMiddleware
    {
        return $this->csrfMiddleware($config, 'bug-report', 'forwext.csrf.bug-report.v1');
    }

    private function faqCsrfMiddleware(ConfigRepository $config): CsrfMiddleware
    {
        return $this->csrfMiddleware($config, 'faq', 'forwext.csrf.faq.v1');
    }

    private function interactionCsrfMiddleware(ConfigRepository $config): CsrfMiddleware
    {
        return $this->csrfMiddleware($config, 'interaction', 'forwext.csrf.interaction.v1');
    }

    private function profileActivityCsrfMiddleware(ConfigRepository $config): CsrfMiddleware
    {
        return $this->csrfMiddleware($config, 'profile-activity', 'forwext.csrf.profile-activity.v1');
    }

    private function notificationSoundCsrfMiddleware(ConfigRepository $config): CsrfMiddleware
    {
        return $this->csrfMiddleware($config, 'notification-sound', 'forwext.csrf.notification-sound.v1');
    }

    private function csrfMiddleware(ConfigRepository $config, string $purpose, string $context): CsrfMiddleware
    {
        $derived = hash_hmac('sha256', $context, $this->masterKey($config)->bytesForCrypto(), true);
        return new CsrfMiddleware(
            new CsrfTokenManager(SecretKey::fromBase64(base64_encode($derived)), $config->requireInt('http_security.csrf.token_ttl_seconds')),
            $purpose,
            $config->requireString('http_security.csrf.cookie_name'),
            true,
            $config->requireInt('http_security.csrf.cookie_max_age'),
        );
    }

    private function masterKey(ConfigRepository $config): SecretKey
    {
        return (new EnvironmentOrFileSecretKeyProvider(
            $this->projectPath($config->requireString('security.master_key_file')),
            $config->requireString('security.master_key_environment'),
        ))->load();
    }

    private function sessionStore(ConfigRepository $config, DatabaseConnection $database): SessionStore
    {
        return match ($config->requireString('session.driver')) {
            'file' => new FileSessionStore($this->projectPath($config->requireString('session.path'))),
            'database' => new DatabaseSessionStore($database),
            default => throw new RuntimeException('Configured session driver requires an explicit advanced-runtime composition.'),
        };
    }

    private function localStorage(ConfigRepository $config): LocalStorageDriver
    {
        if ($config->requireString('storage.driver') !== 'local') throw new RuntimeException('Configured storage driver requires an explicit advanced-runtime composition.');
        $baseUrl = $config->get('storage.local.public_base_url');
        if ($baseUrl !== null && !is_string($baseUrl)) throw new RuntimeException('Public storage base URL configuration is invalid.');
        return new LocalStorageDriver(
            $this->projectPath($config->requireString('storage.local.private_root')),
            $this->projectPath($config->requireString('storage.local.public_root')),
            $baseUrl,
        );
    }

    private function externalMusicPolicy(ConfigRepository $config): ProfileMusicExternalPolicy
    {
        $hosts = $config->get('profile_music.external_allowed_hosts', []);
        if (!is_array($hosts) || !array_is_list($hosts)) throw new RuntimeException('Profile music external host allowlist must be a list.');
        foreach ($hosts as $host) if (!is_string($host)) throw new RuntimeException('Profile music external host allowlist contains an invalid entry.');
        return new ProfileMusicExternalPolicy($hosts);
    }

    /** @return list<string> */
    private function profileUrlReservedNames(ConfigRepository $config): array
    {
        $names = $config->get('profile_url.reserved_names', []);
        if (!is_array($names) || !array_is_list($names)) throw new RuntimeException('Reserved custom profile URL names must be a list.');
        foreach ($names as $name) if (!is_string($name)) throw new RuntimeException('Reserved custom profile URL names contain an invalid entry.');
        return $names;
    }

    private function basePath(ConfigRepository $config): BasePath
    {
        $canonicalUrl = RuntimeCanonicalUrlResolver::resolve(
            $config->requireString('routing.canonical_url'),
        );
        $path = parse_url($canonicalUrl, PHP_URL_PATH);
        if ($path === false) throw new RuntimeException('Canonical URL path is invalid.');
        return new BasePath(is_string($path) ? rtrim($path, '/') : '');
    }

    private function projectPath(string $path): string
    {
        if ($path === '' || str_contains($path, "\0")) throw new RuntimeException('Configured project path is invalid.');
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/D', $path) === 1) return $path;
        return $this->projectRoot . '/' . ltrim(str_replace('\\', '/', $path), '/');
    }
}
