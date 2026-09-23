<?php

declare(strict_types=1);

namespace Forwext\App\Web;

use Forwext\App\Web\Advertising\AdvertisingClickHandler;
use Forwext\App\Web\Advertising\AdvertisingManageHandler;
use Forwext\App\Web\Advertising\AdvertisingMiddleware;
use Forwext\App\Web\Advertising\AdvertisingRenderer;
use Forwext\App\Web\Analytics\AnalyticsRequestMiddleware;
use Forwext\App\Web\Analytics\ForumAnalyticsHandler;
use Forwext\App\Web\Analytics\ContentEngagementHandler;
use Forwext\App\Web\Analytics\OperationsAnalyticsHandler;
use Forwext\App\Web\Analytics\CommerceAnalyticsHandler;
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
use Forwext\App\Web\EasterEgg\EasterEggManageHandler;
use Forwext\App\Web\EasterEgg\EasterEggMiddleware;
use Forwext\App\Web\EasterEgg\EasterEggRenderer;
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
use Forwext\App\Web\Marketplace\MarketplaceBrowseHandler;
use Forwext\App\Web\Marketplace\MarketplaceCartHandler;
use Forwext\App\Web\Marketplace\MarketplaceCartItemHandler;
use Forwext\App\Web\Marketplace\MarketplaceCheckoutHandler;
use Forwext\App\Web\Marketplace\MarketplaceCategoryManageHandler;
use Forwext\App\Web\Marketplace\MarketplaceDetailHandler;
use Forwext\App\Web\Marketplace\MarketplaceDeliveryManageHandler;
use Forwext\App\Web\Marketplace\MarketplaceOrderDeliveryHandler;
use Forwext\App\Web\Marketplace\MarketplaceExternalSaleManageHandler;
use Forwext\App\Web\Marketplace\MarketplaceExternalSaleRedirectHandler;
use Forwext\App\Web\Marketplace\MarketplaceExternalSaleWarningHandler;
use Forwext\App\Web\Marketplace\MarketplaceInternalSaleManageHandler;
use Forwext\App\Web\Marketplace\MarketplaceManageHandler;
use Forwext\App\Web\Marketplace\MarketplaceMediaDownloadHandler;
use Forwext\App\Web\Marketplace\MarketplaceMediaUploadHandler;
use Forwext\App\Web\Marketplace\MarketplaceReviewHandler;
use Forwext\App\Web\Marketplace\MarketplaceOrderDetailHandler;
use Forwext\App\Web\Marketplace\MarketplaceOrdersHandler;
use Forwext\App\Web\Marketplace\MarketplaceSellerHandler;
use Forwext\App\Web\Payment\MarketplaceOrderPaymentHandler;
use Forwext\App\Web\Payment\PaymentManageHandler;
use Forwext\App\Web\Payment\PaymentWebhookHandler;
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
use Forwext\App\Web\Promotion\PromotionManageHandler;
use Forwext\App\Web\Reward\RewardManageHandler;
use Forwext\App\Web\Portfolio\PortfolioIndexHandler;
use Forwext\App\Web\Portfolio\PortfolioManageHandler;
use Forwext\App\Web\Portfolio\PortfolioMediaDownloadHandler;
use Forwext\App\Web\Portfolio\PortfolioMediaUploadHandler;
use Forwext\App\Web\Portfolio\PortfolioProjectHandler;
use Forwext\App\Web\Referral\ReferralAccountHandler;
use Forwext\App\Web\Referral\ReferralManageHandler;
use Forwext\App\Web\Referral\ReferralRedirectHandler;
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
use Forwext\App\Web\Giveaway\GiveawayDetailHandler;
use Forwext\App\Web\Giveaway\GiveawayEnterHandler;
use Forwext\App\Web\Giveaway\GiveawayIndexHandler;
use Forwext\App\Web\Giveaway\GiveawayManageHandler;
use Forwext\App\Web\Giveaway\GiveawayProofHandler;
use Forwext\App\Web\Support\MyTicketsHandler;
use Forwext\App\Web\Support\SupportAttachmentDownloadHandler;
use Forwext\App\Web\Support\SupportStaffDashboardHandler;
use Forwext\App\Web\Support\SupportTicketDetailHandler;
use Forwext\App\Web\Support\SupportTicketFormHandler;
use Forwext\App\Web\Subscription\SubscriptionAccountHandler;
use Forwext\App\Web\Subscription\SubscriptionManageHandler;
use Forwext\App\Web\Subscription\SubscriptionPurchaseHandler;
use Forwext\App\Web\Subscription\SubscriptionWebhookHandler;
use Forwext\App\Web\Trophy\TrophyManageHandler;
use Forwext\Core\Advertising\AdvertisingService;
use Forwext\Core\Advertising\DatabaseAdvertisingRepository;
use Forwext\Core\Analytics\AnalyticsEventRecorder;
use Forwext\Core\Analytics\AnalyticsEventRegistry;
use Forwext\Core\Analytics\AnalyticsPrivacyHasher;
use Forwext\Core\Analytics\DatabaseAnalyticsRepository;
use Forwext\Core\Analytics\Dashboard\DatabaseForumAnalyticsRepository;
use Forwext\Core\Analytics\Dashboard\ForumAnalyticsService;
use Forwext\Core\Analytics\Engagement\DatabaseSearchTermAnalyticsRepository;
use Forwext\Core\Analytics\Engagement\SearchAnalyticsService;
use Forwext\Core\Analytics\Engagement\SearchTermPolicy;
use Forwext\Core\Analytics\Engagement\ContentEngagementService;
use Forwext\Core\Analytics\Engagement\DatabaseContentEngagementRepository;
use Forwext\Core\Analytics\Operations\DatabaseOperationsAnalyticsRepository;
use Forwext\Core\Analytics\Operations\OperationsAnalyticsService;
use Forwext\Core\Analytics\Commerce\CommerceAnalyticsService;
use Forwext\Core\Analytics\Commerce\DatabaseCommerceAnalyticsRepository;
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
use Forwext\Core\EasterEgg\DatabaseEasterEggRepository;
use Forwext\Core\EasterEgg\EasterEggService;
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
use Forwext\Core\Giveaway\DatabaseGiveawayDrawRepository;
use Forwext\Core\Giveaway\DatabaseGiveawayEligibilityContextProvider;
use Forwext\Core\Giveaway\DatabaseGiveawayParticipationRepository;
use Forwext\Core\Giveaway\DatabaseGiveawayRepository;
use Forwext\Core\Giveaway\GiveawayDrawAlgorithm;
use Forwext\Core\Giveaway\GiveawayDrawService;
use Forwext\Core\Giveaway\GiveawayFingerprint;
use Forwext\Core\Giveaway\GiveawayNotifier;
use Forwext\Core\Giveaway\GiveawayParticipationService;
use Forwext\Core\Giveaway\GiveawayService;
use Forwext\Core\Giveaway\Search\GiveawaySearchAccessScopeProvider;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\CallableRequestHandler;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Http\Security\Csrf\CsrfTokenManager;
use Forwext\Core\Marketplace\DatabaseMarketplaceRepository;
use Forwext\Core\Marketplace\DatabaseMarketplaceExternalSaleRepository;
use Forwext\Core\Marketplace\DatabaseMarketplacePurchaseRepository;
use Forwext\Core\Marketplace\Delivery\DatabaseMarketplaceDeliveryRepository;
use Forwext\Core\Marketplace\Delivery\MarketplaceDeliverySecretProtector;
use Forwext\Core\Marketplace\Delivery\MarketplaceDeliveryService;
use Forwext\Core\Marketplace\MarketplaceExternalSaleService;
use Forwext\Core\Marketplace\MarketplaceExternalSaleUrlPolicy;
use Forwext\Core\Marketplace\MarketplacePurchaseNotifier;
use Forwext\Core\Marketplace\MarketplacePurchaseService;
use Forwext\Core\Marketplace\MarketplaceService;
use Forwext\Core\Marketplace\MarketplaceMediaService;
use Forwext\Core\Marketplace\Search\MarketplaceSearchAccessScopeProvider;
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
use Forwext\Core\Payment\DatabasePaymentRepository;
use Forwext\Core\Payment\PaymentProviderRegistry;
use Forwext\Core\Payment\PaymentService;
use Forwext\Core\Portfolio\DatabasePortfolioRepository;
use Forwext\Core\Portfolio\PortfolioMediaService;
use Forwext\Core\Portfolio\PortfolioService;
use Forwext\Core\Portfolio\Search\PortfolioSearchAccessScopeProvider;
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
use Forwext\Core\Promotion\DatabasePromotionMetricProvider;
use Forwext\Core\Promotion\DatabasePromotionRepository;
use Forwext\Core\Promotion\PromotionService;
use Forwext\Core\Queue\DatabaseQueueDriver;
use Forwext\Core\Referral\DatabaseReferralRepository;
use Forwext\Core\Referral\ReferralNotifier;
use Forwext\Core\Referral\ReferralService;
use Forwext\Core\Reward\DatabaseRewardRepository;
use Forwext\Core\Reward\DatabaseRoleRewardProvider;
use Forwext\Core\Reward\DatabaseSecondaryGroupRewardProvider;
use Forwext\Core\Reward\RewardProviderRegistry;
use Forwext\Core\Reward\RewardService;
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
use Forwext\Core\Support\Intake\MarketplaceOrderSupportContextResolver;
use Forwext\Core\Support\Intake\SupportContextRegistry;
use Forwext\Core\Support\Intake\ThreadSupportContextResolver;
use Forwext\Core\Support\Reporting\DatabaseSupportReportingRepository;
use Forwext\Core\Support\Ticket\DatabaseSupportTicketRepository;
use Forwext\Core\Subscription\DatabaseSubscriptionRepository;
use Forwext\Core\Subscription\SubscriptionService;
use Forwext\Core\Trophy\DatabaseTrophyMetricProvider;
use Forwext\Core\Trophy\DatabaseTrophyRepository;
use Forwext\Core\Trophy\TrophyService;
use Forwext\Core\Trophy\TrophyNotifier;
use RuntimeException;

final readonly class WebApplicationFactory
{
    public function __construct(
        private string $projectRoot,
        private ?PaymentProviderRegistry $paymentProviders=null,
    ){
        if ($projectRoot === '') throw new RuntimeException('Project root cannot be empty.');
    }

    public function create(string $version): Router
    {
        $config = $this->config();
        $database = $this->database($config);
        $secretStore = new EncryptedFileSecretStore(
            $this->projectPath($config->requireString('security.secret_store_path')),
            new SecretCipher($this->masterKey($config)),
        );
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
        $analytics = new AnalyticsEventRecorder(
            AnalyticsEventRegistry::withCoreDefaults(),
            new DatabaseAnalyticsRepository($database),
            new AnalyticsPrivacyHasher($this->analyticsPrivacyKey($config)),
        );
        $forumAnalytics = new ForumAnalyticsService(
            new DatabaseForumAnalyticsRepository($database),
            $authorizer,
        );
        $searchAnalytics = new SearchAnalyticsService(
            $analytics,
            new DatabaseSearchTermAnalyticsRepository($database),
            new SearchTermPolicy(),
            $this->searchAnalyticsKey($config),
        );
        $contentEngagement = new ContentEngagementService(
            new DatabaseContentEngagementRepository($database),
            $authorizer,
        );
        $operationsAnalytics = new OperationsAnalyticsService(
            new DatabaseOperationsAnalyticsRepository($database),
            $authorizer,
        );
        $commerceAnalytics = new CommerceAnalyticsService(
            new DatabaseCommerceAnalyticsRepository($database),
            $authorizer,
        );
        $advertisingRepository = new DatabaseAdvertisingRepository($database);
        $advertising = new AdvertisingService(
            $database,
            $advertisingRepository,
            $authorizer,
            new CoreAuditRecorder($database, new DatabaseAuditEventStore($database)),
            $this->advertisingFrequencyKey($config),
        );
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
        $attachmentQuota = new AttachmentQuotaPolicy();
        $attachmentInspector = new AttachmentInspector(new ImageMetadataSanitizer(), $attachmentQuota);

        $portfolioRepository = new DatabasePortfolioRepository($database);
        $portfolio = new PortfolioService(
            $database,
            $portfolioRepository,
            $authorizer,
            $contentManagerPipeline,
            $searchChanges,
        );
        $marketplaceRepository = new DatabaseMarketplaceRepository($database);
        $marketplace = new MarketplaceService(
            $database,
            $marketplaceRepository,
            $authorizer,
            new CoreAuditRecorder($database, new DatabaseAuditEventStore($database)),
            $searchChanges,
            $contentManagerPipeline,
        );
        $marketplaceExternalSales = new MarketplaceExternalSaleService(
            new DatabaseMarketplaceExternalSaleRepository($database),
            $marketplace,
            $this->marketplaceExternalSaleUrlPolicy($config),
            new CoreAuditRecorder($database, new DatabaseAuditEventStore($database)),
        );
        $marketplacePurchaseNotifications = new NotificationRegistry();
        MarketplacePurchaseNotifier::registerDefinitions($marketplacePurchaseNotifications);
        $marketplacePurchaseRepository = new DatabaseMarketplacePurchaseRepository($database);
        $marketplaceDeliveryRepository = new DatabaseMarketplaceDeliveryRepository($database);
        $marketplaceDelivery = new MarketplaceDeliveryService(
            $database,
            $marketplaceDeliveryRepository,
            $marketplacePurchaseRepository,
            $marketplace,
            $authorizer,
            $storage,
            $attachmentInspector,
            $this->marketplaceDeliverySecretProtector($config),
            new CoreAuditRecorder($database,new DatabaseAuditEventStore($database)),
        );
        $marketplacePurchases = new MarketplacePurchaseService(
            $database,
            $marketplacePurchaseRepository,
            $marketplace,
            new CoreAuditRecorder($database, new DatabaseAuditEventStore($database)),
            new MarketplacePurchaseNotifier(new NotificationDispatcher(
                $marketplacePurchaseNotifications,
                new DatabaseNotificationRepository($database),
            )),
            $marketplaceDelivery,
        );
        $paymentProviderRegistry=$this->paymentProviders??new PaymentProviderRegistry();
        $subscriptionRepository=new DatabaseSubscriptionRepository($database);
        $subscriptionService=new SubscriptionService(
            $database,
            $subscriptionRepository,
            $paymentProviderRegistry,
            $authorizer,
            new CoreAuditRecorder($database,new DatabaseAuditEventStore($database)),
        );
        $payments=new PaymentService(
            $database,
            new DatabasePaymentRepository($database),
            $paymentProviderRegistry,
            $marketplacePurchaseRepository,
            $authorizer,
            new CoreAuditRecorder($database,new DatabaseAuditEventStore($database)),
            $marketplaceDelivery,
        );

        $rewardRepository = new DatabaseRewardRepository($database);
        $rewardProviders = new RewardProviderRegistry([
            new DatabaseRoleRewardProvider($database),
            new DatabaseSecondaryGroupRewardProvider($database),
        ]);
        $rewards = new RewardService(
            $database,
            $rewardRepository,
            $rewardProviders,
            $authorizer,
            new CoreAuditRecorder($database, new DatabaseAuditEventStore($database)),
        );

        $promotionRepository = new DatabasePromotionRepository($database);
        $promotions = new PromotionService(
            $promotionRepository,
            new DatabasePromotionMetricProvider($database),
            $rewards,
            $authorizer,
            new CoreAuditRecorder($database, new DatabaseAuditEventStore($database)),
            $rewardRepository,
        );

        $referralNotificationRegistry = new NotificationRegistry();
        ReferralNotifier::registerDefinitions($referralNotificationRegistry);
        $referralRepository = new DatabaseReferralRepository($database);
        $referrals = new ReferralService(
            $database,
            $referralRepository,
            $users,
            $authorizer,
            new CoreAuditRecorder($database, new DatabaseAuditEventStore($database)),
            new ReferralNotifier(new NotificationDispatcher(
                $referralNotificationRegistry,
                new DatabaseNotificationRepository($database),
            )),
            $rewards,
        );
        $giveawayRepository = new DatabaseGiveawayRepository($database);
        $giveawayParticipationRepository = new DatabaseGiveawayParticipationRepository($database);
        $giveawayAudit = new CoreAuditRecorder($database, new DatabaseAuditEventStore($database));
        $giveaways = new GiveawayService(
            $database,
            $giveawayRepository,
            $authorizer,
            $giveawayAudit,
            $searchChanges,
        );
        $giveawayParticipation = new GiveawayParticipationService(
            $database,
            $giveawayRepository,
            $giveawayParticipationRepository,
            new DatabaseGiveawayEligibilityContextProvider(
                $database,
                $users,
                new DatabaseUserAccessAssignmentProvider($database),
            ),
            $authorizer,
            $giveawayAudit,
        );
        $easterEggRepository = new DatabaseEasterEggRepository($database);
        $easterEggs = new EasterEggService(
            $database,
            $easterEggRepository,
            $authorizer,
            new CoreAuditRecorder($database, new DatabaseAuditEventStore($database)),
        );
        $easterEggAssignments = new DatabaseUserAccessAssignmentProvider($database);
        $easterEggMiddleware = new EasterEggMiddleware(
            $easterEggs,
            $viewerResolver,
            $easterEggAssignments,
            $basePath,
            new EasterEggRenderer(),
        );

        $advertisingMiddleware = new AdvertisingMiddleware(
            $advertising,
            $viewerResolver,
            new DatabaseUserAccessAssignmentProvider($database),
            $threads,
            new AdvertisingRenderer($basePath),
            $basePath,
            strtolower((string) parse_url(RuntimeCanonicalUrlResolver::resolve($config->requireString('routing.canonical_url')), PHP_URL_SCHEME)) === 'https',
        );

        $giveawayFingerprint = new GiveawayFingerprint(
            $secretStore,
            $config->requireString('registration.rate_limit.fingerprint_secret_name'),
        );
        $giveawayNotificationRegistry = new NotificationRegistry();
        GiveawayNotifier::registerDefinitions($giveawayNotificationRegistry);
        $giveawayDraws = new GiveawayDrawService(
            $database,
            $giveawayRepository,
            $giveawayParticipationRepository,
            new DatabaseGiveawayDrawRepository($database),
            $authorizer,
            $giveawayAudit,
            new GiveawayNotifier(new NotificationDispatcher(
                $giveawayNotificationRegistry,
                new DatabaseNotificationRepository($database),
            )),
            new GiveawayDrawAlgorithm(),
            $rewards,
        );
        $trophyRepository = new DatabaseTrophyRepository($database);
        $trophyNotificationRegistry = new NotificationRegistry();
        TrophyNotifier::registerDefinitions($trophyNotificationRegistry);
        $trophies = new TrophyService(
            $database,
            $trophyRepository,
            new DatabaseTrophyMetricProvider($database),
            $authorizer,
            new CoreAuditRecorder($database, new DatabaseAuditEventStore($database)),
            new TrophyNotifier(new NotificationDispatcher(
                $trophyNotificationRegistry,
                new DatabaseNotificationRepository($database),
            )),
            $rewards,
        );
        $profilePage = new ProfileViewHandler(
            $users,
            $profileService,
            $accessPolicy,
            $viewerResolver,
            $basePath,
            $musicService,
            $portfolio,
            $trophies,
            $marketplace,
        );
        $searchService = new PermissionAwareSearchService(
            new ResilientSearchDriver(new NativeDatabaseSearchDriver($database)),
            $authorizer,
            [
                new PublicSearchAccessScopeProvider(),
                new ForumSearchAccessScopeProvider($nodes, $authorizer),
                new FaqSearchAccessScopeProvider($authorizer),
                new PortfolioSearchAccessScopeProvider($authorizer),
                new GiveawaySearchAccessScopeProvider($authorizer),
                new MarketplaceSearchAccessScopeProvider($authorizer),
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

        $portfolioMedia = new PortfolioMediaService(
            $database,
            $portfolio,
            $portfolioRepository,
            $storage,
            $attachmentInspector,
            $attachmentQuota,
        );
        $marketplaceMedia = new MarketplaceMediaService(
            $database,
            $marketplace,
            $marketplaceRepository,
            $storage,
            $attachmentInspector,
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
        $browserDeviceClassifier = new BugBrowserDeviceClassifier(
            new AuthenticationFingerprint(
                $secretStore,
                $config->requireString('authentication.fingerprint_secret_name'),
            ),
        );
        $bugDiagnosticCollector = new BugDiagnosticContextCollector($browserDeviceClassifier);
        $analyticsMiddleware = new AnalyticsRequestMiddleware(
            $analytics,
            $viewerResolver,
            $threads,
            $browserDeviceClassifier,
            $config->requireString('authentication.session.cookie_name'),
        );
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
            new MarketplaceOrderSupportContextResolver($marketplacePurchaseRepository, $authorizer),
        ]);

        $attachmentCsrf = $this->attachmentCsrfMiddleware($config);
        $supportCsrf = $this->supportCsrfMiddleware($config);
        $bugCsrf = $this->bugCsrfMiddleware($config);
        $faqCsrf = $this->faqCsrfMiddleware($config);
        $portfolioCsrf = $this->portfolioCsrfMiddleware($config);
        $referralCsrf = $this->referralCsrfMiddleware($config);
        $giveawayCsrf = $this->giveawayCsrfMiddleware($config);
        $easterEggCsrf = $this->easterEggCsrfMiddleware($config);
        $trophyCsrf = $this->trophyCsrfMiddleware($config);
        $rewardCsrf = $this->rewardCsrfMiddleware($config);
        $promotionCsrf = $this->promotionCsrfMiddleware($config);
        $marketplaceCategoryCsrf = $this->marketplaceCategoryCsrfMiddleware($config);
        $marketplaceCsrf = $this->marketplaceCsrfMiddleware($config);
        $paymentCsrf = $this->paymentCsrfMiddleware($config);
        $subscriptionCsrf = $this->subscriptionCsrfMiddleware($config);
        $advertisingCsrf = $this->advertisingCsrfMiddleware($config);
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
            'marketplace.index',
            [HttpMethod::Get],
            new PathTemplate('/marketplace'),
            new MarketplaceBrowseHandler($marketplace,$viewerResolver,$basePath),
        ));
        $routes->add(new Route(
            'marketplace.detail',
            [HttpMethod::Get],
            new PathTemplate('/marketplace/listings/{listingId}',['listingId'=>'[0-9a-f]{32}']),
            new MarketplaceDetailHandler($marketplace,$marketplaceExternalSales,$marketplacePurchases,$users,$viewerResolver,$basePath),
            [$marketplaceCsrf],
        ));
        $routes->add(new Route(
            'marketplace.media.download',
            [HttpMethod::Get],
            new PathTemplate('/marketplace/media/{mediaId}',['mediaId'=>'[0-9a-f]{32}']),
            new MarketplaceMediaDownloadHandler($marketplaceMedia,$viewerResolver),
        ));
        $routes->add(new Route(
            'marketplace.media.upload',
            [HttpMethod::Post],
            new PathTemplate('/marketplace/listings/{listingId}/media',['listingId'=>'[0-9a-f]{32}']),
            new MarketplaceMediaUploadHandler(
                $marketplaceMedia,$viewerResolver,new VerifiedUploadedAttachmentReader(),$attachmentQuota,$basePath
            ),
            [$marketplaceCsrf],
        ));
        $routes->add(new Route(
            'marketplace.review',
            [HttpMethod::Post],
            new PathTemplate('/marketplace/listings/{listingId}/review',['listingId'=>'[0-9a-f]{32}']),
            new MarketplaceReviewHandler($marketplace,$viewerResolver,$basePath),
            [$marketplaceCsrf],
        ));
        $routes->add(new Route(
            'marketplace.seller',
            [HttpMethod::Get],
            new PathTemplate('/marketplace/sellers/{username}'),
            new MarketplaceSellerHandler($marketplace,$users,$viewerResolver,$basePath),
        ));
        $routes->add(new Route(
            'marketplace.manage',
            [HttpMethod::Get,HttpMethod::Post],
            new PathTemplate('/marketplace/manage'),
            new MarketplaceManageHandler($marketplace,$users,$viewerResolver,$basePath),
            [$marketplaceCsrf],
        ));
        $routes->add(new Route(
            'marketplace.cart',
            [HttpMethod::Get],
            new PathTemplate('/marketplace/cart'),
            new MarketplaceCartHandler($marketplacePurchases,$viewerResolver,$basePath),
            [$marketplaceCsrf],
        ));
        $routes->add(new Route(
            'marketplace.cart.item',
            [HttpMethod::Post],
            new PathTemplate('/marketplace/cart/{listingId}',['listingId'=>'[0-9a-f]{32}']),
            new MarketplaceCartItemHandler($marketplacePurchases,$viewerResolver,$basePath),
            [$marketplaceCsrf],
        ));
        $routes->add(new Route(
            'marketplace.checkout',
            [HttpMethod::Get,HttpMethod::Post],
            new PathTemplate('/marketplace/checkout'),
            new MarketplaceCheckoutHandler($marketplacePurchases,$viewerResolver,$basePath),
            [$marketplaceCsrf],
        ));
        $routes->add(new Route(
            'marketplace.orders',
            [HttpMethod::Get],
            new PathTemplate('/marketplace/orders'),
            new MarketplaceOrdersHandler($marketplacePurchases,$viewerResolver,$basePath),
            [$marketplaceCsrf],
        ));
        $routes->add(new Route(
            'marketplace.order.detail',
            [HttpMethod::Get,HttpMethod::Post],
            new PathTemplate('/marketplace/orders/{orderId}',['orderId'=>'[0-9a-f]{32}']),
            new MarketplaceOrderDetailHandler($marketplacePurchases,$marketplaceDelivery,$payments,$users,$viewerResolver,$basePath),
            [$marketplaceCsrf],
        ));
        $routes->add(new Route(
            'marketplace.order.payment',
            [HttpMethod::Post],
            new PathTemplate('/marketplace/orders/{orderId}/payment',['orderId'=>'[0-9a-f]{32}']),
            new MarketplaceOrderPaymentHandler($payments,$viewerResolver,$basePath),
            [$marketplaceCsrf],
        ));
        $routes->add(new Route(
            'account.upgrades',
            [HttpMethod::Get],
            new PathTemplate('/account/upgrades'),
            new SubscriptionAccountHandler($subscriptionService,$viewerResolver,$basePath),
            [$subscriptionCsrf],
        ));
        $routes->add(new Route(
            'account.upgrades.purchase',
            [HttpMethod::Post],
            new PathTemplate('/account/upgrades/{planId}/purchase',['planId'=>'[0-9a-f]{32}']),
            new SubscriptionPurchaseHandler($subscriptionService,$viewerResolver,$basePath),
            [$subscriptionCsrf],
        ));
        $routes->add(new Route(
            'subscription.webhook',
            [HttpMethod::Post],
            new PathTemplate('/subscriptions/payments/webhooks/{providerKey}',['providerKey'=>'[a-z][a-z0-9._-]{1,63}']),
            new SubscriptionWebhookHandler($subscriptionService),
        ));
        $routes->add(new Route(
            'subscription.manage',
            [HttpMethod::Get,HttpMethod::Post],
            new PathTemplate('/admin/subscriptions'),
            new SubscriptionManageHandler(
                $subscriptionService,$subscriptionRepository,$users,$viewerResolver,$basePath
            ),
            [$subscriptionCsrf],
        ));
        $routes->add(new Route(
            'payment.webhook',
            [HttpMethod::Post],
            new PathTemplate('/payments/webhooks/{providerKey}',['providerKey'=>'[a-z][a-z0-9._-]{1,63}']),
            new PaymentWebhookHandler($payments),
        ));
        $routes->add(new Route(
            'marketplace.internal.manage',
            [HttpMethod::Get,HttpMethod::Post],
            new PathTemplate('/marketplace/manage/internal/{listingId}',['listingId'=>'[0-9a-f]{32}']),
            new MarketplaceInternalSaleManageHandler($marketplacePurchases,$marketplace,$viewerResolver,$basePath),
            [$marketplaceCsrf],
        ));
        $routes->add(new Route(
            'marketplace.delivery.manage',
            [HttpMethod::Get,HttpMethod::Post],
            new PathTemplate('/marketplace/manage/delivery/{listingId}',['listingId'=>'[0-9a-f]{32}']),
            new MarketplaceDeliveryManageHandler(
                $marketplaceDelivery,$marketplace,$viewerResolver,new VerifiedUploadedAttachmentReader(),
                $attachmentQuota,$basePath
            ),
            [$marketplaceCsrf],
        ));
        $routes->add(new Route(
            'marketplace.order.delivery',
            [HttpMethod::Get,HttpMethod::Post],
            new PathTemplate(
                '/marketplace/orders/{orderId}/delivery/{itemId}/{action}',
                [
                    'orderId'=>'[0-9a-f]{32}',
                    'itemId'=>'[0-9a-f]{32}',
                    'action'=>'(?:fulfill|reveal|download)',
                ],
            ),
            new MarketplaceOrderDeliveryHandler($marketplaceDelivery,$viewerResolver,$basePath),
            [$marketplaceCsrf],
        ));
        $routes->add(new Route(
            'marketplace.external.warning',
            [HttpMethod::Get],
            new PathTemplate('/marketplace/listings/{listingId}/external',['listingId'=>'[0-9a-f]{32}']),
            new MarketplaceExternalSaleWarningHandler($marketplaceExternalSales,$marketplace,$viewerResolver,$basePath),
            [$marketplaceCsrf],
        ));
        $routes->add(new Route(
            'marketplace.external.go',
            [HttpMethod::Post],
            new PathTemplate('/marketplace/listings/{listingId}/external/go',['listingId'=>'[0-9a-f]{32}']),
            new MarketplaceExternalSaleRedirectHandler($marketplaceExternalSales,$viewerResolver),
            [$marketplaceCsrf],
        ));
        $routes->add(new Route(
            'marketplace.external.manage',
            [HttpMethod::Get,HttpMethod::Post],
            new PathTemplate('/marketplace/manage/external/{listingId}',['listingId'=>'[0-9a-f]{32}']),
            new MarketplaceExternalSaleManageHandler($marketplaceExternalSales,$viewerResolver,$basePath),
            [$marketplaceCsrf],
        ));
        $routes->add(new Route(
            'marketplace.category.manage',
            [HttpMethod::Get, HttpMethod::Post],
            new PathTemplate('/admin/marketplace/categories'),
            new MarketplaceCategoryManageHandler($marketplace, $viewerResolver, $basePath),
            [$marketplaceCategoryCsrf],
        ));
        $routes->add(new Route(
            'payment.manage',
            [HttpMethod::Get,HttpMethod::Post],
            new PathTemplate('/admin/payments'),
            new PaymentManageHandler($payments,$viewerResolver,$basePath),
            [$paymentCsrf],
        ));
        $routes->add(new Route(
            'reward.manage',
            [HttpMethod::Get, HttpMethod::Post],
            new PathTemplate('/admin/rewards'),
            new RewardManageHandler($rewards, $viewerResolver, $basePath),
            [$rewardCsrf],
        ));
        $routes->add(new Route(
            'promotion.manage',
            [HttpMethod::Get, HttpMethod::Post],
            new PathTemplate('/admin/promotions'),
            new PromotionManageHandler($promotions, $users, $viewerResolver, $basePath),
            [$promotionCsrf],
        ));
        $routes->add(new Route(
            'trophy.manage',
            [HttpMethod::Get, HttpMethod::Post],
            new PathTemplate('/admin/trophies'),
            new TrophyManageHandler($trophies, $users, $viewerResolver, $basePath),
            [$trophyCsrf],
        ));
        $routes->add(new Route(
            'easteregg.manage',
            [HttpMethod::Get, HttpMethod::Post],
            new PathTemplate('/admin/easter-eggs'),
            new EasterEggManageHandler($easterEggs, $viewerResolver, $basePath),
            [$easterEggCsrf],
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
        $routes->add(new Route(
            'giveaway.index',
            [HttpMethod::Get],
            new PathTemplate('/giveaways'),
            new GiveawayIndexHandler($giveaways, $viewerResolver, $basePath),
        ));
        $routes->add(new Route(
            'giveaway.manage',
            [HttpMethod::Get, HttpMethod::Post],
            new PathTemplate('/giveaways/manage'),
            new GiveawayManageHandler($giveaways, $giveawayParticipation, $giveawayDraws, $viewerResolver, $basePath),
            [$giveawayCsrf],
        ));
        $routes->add(new Route(
            'giveaway.detail',
            [HttpMethod::Get],
            new PathTemplate('/giveaways/{giveawayId}', ['giveawayId'=>'[0-9a-f]{32}']),
            new GiveawayDetailHandler(
                $giveaways,
                $giveawayParticipation,
                $giveawayFingerprint,
                $viewerResolver,
                $basePath,
            ),
            [$giveawayCsrf],
        ));
        $routes->add(new Route(
            'giveaway.proof',
            [HttpMethod::Get],
            new PathTemplate('/giveaways/{giveawayId}/proof', ['giveawayId'=>'[0-9a-f]{32}']),
            new GiveawayProofHandler($giveaways, $giveawayDraws, $users, $viewerResolver, $basePath),
        ));
        $routes->add(new Route(
            'giveaway.enter',
            [HttpMethod::Post],
            new PathTemplate('/giveaways/{giveawayId}/enter', ['giveawayId'=>'[0-9a-f]{32}']),
            new GiveawayEnterHandler(
                $giveaways,
                $giveawayParticipation,
                $giveawayFingerprint,
                $viewerResolver,
                $basePath,
            ),
            [$giveawayCsrf],
        ));
        $routes->add(new Route(
            'referral.redirect',
            [HttpMethod::Get],
            new PathTemplate('/ref/{code}', ['code'=>'[A-Za-z0-9_-]{24,64}']),
            new ReferralRedirectHandler($referrals, $basePath),
        ));
        $routes->add(new Route(
            'referral.account',
            [HttpMethod::Get, HttpMethod::Post],
            new PathTemplate('/account/referrals'),
            new ReferralAccountHandler(
                $referrals,
                $viewerResolver,
                $basePath,
                RuntimeCanonicalUrlResolver::resolve($config->requireString('routing.canonical_url')),
            ),
            [$referralCsrf],
        ));
        $routes->add(new Route(
            'referral.manage',
            [HttpMethod::Get, HttpMethod::Post],
            new PathTemplate('/referrals/manage'),
            new ReferralManageHandler($referrals, $viewerResolver, $basePath),
            [$referralCsrf],
        ));
        $routes->add(new Route(
            'portfolio.index',
            [HttpMethod::Get],
            new PathTemplate('/portfolio'),
            new PortfolioIndexHandler($portfolio, $viewerResolver, $basePath),
        ));
        $routes->add(new Route(
            'portfolio.manage',
            [HttpMethod::Get, HttpMethod::Post],
            new PathTemplate('/portfolio/manage'),
            new PortfolioManageHandler($portfolio, $viewerResolver, $basePath),
            [$portfolioCsrf],
        ));
        $routes->add(new Route(
            'portfolio.media.download',
            [HttpMethod::Get],
            new PathTemplate('/portfolio/media/{mediaId}', ['mediaId'=>'[0-9a-f]{32}']),
            new PortfolioMediaDownloadHandler($portfolioMedia, $viewerResolver),
        ));
        $routes->add(new Route(
            'portfolio.media.upload',
            [HttpMethod::Post],
            new PathTemplate('/portfolio/{projectId}/media', ['projectId'=>'[0-9a-f]{32}']),
            new PortfolioMediaUploadHandler(
                $portfolioMedia,
                $viewerResolver,
                new VerifiedUploadedAttachmentReader(),
                $attachmentQuota,
                $basePath,
            ),
            [$portfolioCsrf],
        ));
        $routes->add(new Route(
            'portfolio.project',
            [HttpMethod::Get, HttpMethod::Post],
            new PathTemplate('/portfolio/{projectId}', ['projectId'=>'[0-9a-f]{32}']),
            new PortfolioProjectHandler($portfolio, $viewerResolver, $basePath),
            [$portfolioCsrf],
        ));
        $routes->add(new Route(
            'search.index',
            [HttpMethod::Get],
            new PathTemplate('/search'),
            new SearchHandler($searchService, $viewerResolver, $basePath, $searchAnalytics),
        ));
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
            'analytics.dashboard',
            [HttpMethod::Get],
            new PathTemplate('/admin/analytics'),
            new ForumAnalyticsHandler($forumAnalytics, $viewerResolver, $basePath),
        ));
        $routes->add(new Route(
            'analytics.content',
            [HttpMethod::Get],
            new PathTemplate('/admin/analytics/content'),
            new ContentEngagementHandler($contentEngagement, $viewerResolver, $basePath),
        ));
        $routes->add(new Route(
            'analytics.operations',
            [HttpMethod::Get],
            new PathTemplate('/admin/analytics/operations'),
            new OperationsAnalyticsHandler($operationsAnalytics, $viewerResolver, $basePath),
        ));
        $routes->add(new Route(
            'analytics.commerce',
            [HttpMethod::Get],
            new PathTemplate('/admin/analytics/commerce'),
            new CommerceAnalyticsHandler($commerceAnalytics, $viewerResolver, $basePath),
        ));
        $routes->add(new Route(
            'advertising.click',
            [HttpMethod::Get],
            new PathTemplate('/ads/click/{campaignId}', ['campaignId'=>'[0-9a-f]{32}']),
            new AdvertisingClickHandler(
                $advertising,
                $viewerResolver,
                $basePath,
                strtolower((string) parse_url(RuntimeCanonicalUrlResolver::resolve($config->requireString('routing.canonical_url')), PHP_URL_SCHEME)) === 'https',
            ),
        ));
        $routes->add(new Route(
            'advertising.manage',
            [HttpMethod::Get, HttpMethod::Post],
            new PathTemplate('/admin/advertising'),
            new AdvertisingManageHandler($advertising,$viewerResolver,$basePath),
            [$advertisingCsrf],
        ));

        $routes->add(new Route(
            'account.profile-url', [HttpMethod::Get, HttpMethod::Post], new PathTemplate('/account/profile-url'),
            new ProfileUrlSettingsHandler($profileUrlService, $viewerResolver, $basePath), [$this->profileUrlCsrfMiddleware($config)],
        ));

        return new Router($routes, $basePath, [$easterEggMiddleware, $advertisingMiddleware, $analyticsMiddleware]);
    }

    public function decorateLegacyEasterEgg(Request $request, Response $response): Response
    {
        $config = $this->config();
        $database = $this->database($config);
        $users = new DatabaseUserRepository($database);
        $sessions = new AuthSessionManager(
            $this->sessionStore($config, $database),
            new DatabaseCredentialStore($database),
            $config->requireInt('authentication.session.ttl_seconds'),
        );
        $viewerResolver = new AuthSessionProfileViewerResolver(
            $sessions,
            $users,
            $config->requireString('authentication.session.cookie_name'),
            new DatabaseDisciplineAuthenticationAvailability($database),
        );
        $authorizer = $this->permissionAuthorizer($database);
        $basePath = new BasePath(parse_url(
            RuntimeCanonicalUrlResolver::resolve($config->requireString('routing.canonical_url')),
            PHP_URL_PATH,
        ) ?: '');
        $service = new EasterEggService(
            $database,
            new DatabaseEasterEggRepository($database),
            $authorizer,
            new CoreAuditRecorder($database, new DatabaseAuditEventStore($database)),
        );
        $middleware = new EasterEggMiddleware(
            $service,
            $viewerResolver,
            new DatabaseUserAccessAssignmentProvider($database),
            $basePath,
            new EasterEggRenderer(),
        );
        $routed = $request->withAttribute(Router::ATTRIBUTE_ROUTE_NAME, 'legacy.path');

        return $middleware->process(
            $routed,
            new CallableRequestHandler(static fn (Request $_request): Response => $response),
        );
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

    private function portfolioCsrfMiddleware(ConfigRepository $config): CsrfMiddleware
    {
        return $this->csrfMiddleware($config, 'portfolio', 'forwext.csrf.portfolio.v1');
    }

    private function referralCsrfMiddleware(ConfigRepository $config): CsrfMiddleware
    {
        return $this->csrfMiddleware($config, 'referral', 'forwext.csrf.referral.v1');
    }

    private function giveawayCsrfMiddleware(ConfigRepository $config): CsrfMiddleware
    {
        return $this->csrfMiddleware($config, 'giveaway', 'forwext.csrf.giveaway.v1');
    }

    private function easterEggCsrfMiddleware(ConfigRepository $config): CsrfMiddleware
    {
        return $this->csrfMiddleware($config, 'easteregg', 'forwext.csrf.easteregg.v1');
    }

    private function trophyCsrfMiddleware(ConfigRepository $config): CsrfMiddleware
    {
        return $this->csrfMiddleware($config, 'trophy', 'forwext.csrf.trophy.v1');
    }

    private function rewardCsrfMiddleware(ConfigRepository $config): CsrfMiddleware
    {
        return $this->csrfMiddleware($config, 'reward', 'forwext.csrf.reward.v1');
    }

    private function promotionCsrfMiddleware(ConfigRepository $config): CsrfMiddleware
    {
        return $this->csrfMiddleware($config, 'promotion', 'forwext.csrf.promotion.v1');
    }

    private function marketplaceCategoryCsrfMiddleware(ConfigRepository $config): CsrfMiddleware
    {
        return $this->csrfMiddleware($config, 'marketplace-category', 'forwext.csrf.marketplace-category.v1');
    }

    private function marketplaceCsrfMiddleware(ConfigRepository $config): CsrfMiddleware
    {
        return $this->csrfMiddleware($config, 'marketplace', 'forwext.csrf.marketplace.v1');
    }

    private function paymentCsrfMiddleware(ConfigRepository $config): CsrfMiddleware
    {
        return $this->csrfMiddleware($config, 'payment', 'forwext.csrf.payment.v1');
    }

    private function subscriptionCsrfMiddleware(ConfigRepository $config): CsrfMiddleware
    {
        return $this->csrfMiddleware($config, 'subscription', 'forwext.csrf.subscription.v1');
    }

    private function advertisingCsrfMiddleware(ConfigRepository $config): CsrfMiddleware
    {
        return $this->csrfMiddleware($config, 'advertising', 'forwext.csrf.advertising.v1');
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

    private function advertisingFrequencyKey(ConfigRepository $config): SecretKey
    {
        $derived=hash_hmac(
            'sha256',
            'forwext.advertising.frequency.v1',
            $this->masterKey($config)->bytesForCrypto(),
            true,
        );
        return SecretKey::fromBase64(base64_encode($derived));
    }

    private function analyticsPrivacyKey(ConfigRepository $config): SecretKey
    {
        $derived=hash_hmac(
            'sha256',
            'forwext.analytics.privacy.v1',
            $this->masterKey($config)->bytesForCrypto(),
            true,
        );
        return SecretKey::fromBase64(base64_encode($derived));
    }

    private function searchAnalyticsKey(ConfigRepository $config): SecretKey
    {
        $derived=hash_hmac(
            'sha256',
            'forwext.analytics.search-term.v1',
            $this->masterKey($config)->bytesForCrypto(),
            true,
        );
        return SecretKey::fromBase64(base64_encode($derived));
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

    private function marketplaceDeliverySecretProtector(ConfigRepository $config): MarketplaceDeliverySecretProtector
    {
        $master=$this->masterKey($config)->bytesForCrypto();
        $encrypt=hash_hmac('sha256','forwext.marketplace.delivery.encrypt.v1',$master,true);
        $fingerprint=hash_hmac('sha256','forwext.marketplace.delivery.fingerprint.v1',$master,true);
        return new MarketplaceDeliverySecretProtector(
            new SecretCipher(SecretKey::fromBase64(base64_encode($encrypt))),
            SecretKey::fromBase64(base64_encode($fingerprint)),
        );
    }

    private function marketplaceExternalSaleUrlPolicy(ConfigRepository $config): MarketplaceExternalSaleUrlPolicy
    {
        $hosts=$config->get('marketplace.external_sale.allowed_hosts',[]);
        if(!is_array($hosts)||!array_is_list($hosts))throw new RuntimeException('Marketplace external-sale host allowlist must be a list.');
        foreach($hosts as $host)if(!is_string($host))throw new RuntimeException('Marketplace external-sale host allowlist contains an invalid entry.');
        return new MarketplaceExternalSaleUrlPolicy(
            $hosts,
            $config->requireBool('marketplace.external_sale.allow_subdomains'),
            $config->requireString('marketplace.external_sale.utm_source'),
            $config->requireString('marketplace.external_sale.utm_medium'),
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
