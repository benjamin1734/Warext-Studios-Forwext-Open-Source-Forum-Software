<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Pipeline;

use Forwext\Core\Content\Ai\AiModerationDecisionStore;
use Forwext\Core\Content\Ai\AiModerationForumPolicyRepository;
use Forwext\Core\Content\Ai\AiModerationMetricsStore;
use Forwext\Core\Content\Ai\AiModerationOverrideRepository;
use Forwext\Core\Content\Ai\AiModerationPipelineProcessor;
use Forwext\Core\Content\Ai\AiModerationPolicy;
use Forwext\Core\Content\Ai\AiModerationPolicyProcessor;
use Forwext\Core\Content\Ai\AiModerationService;
use Forwext\Core\Content\Ai\NullAiModerationDecisionStore;
use Forwext\Core\Content\Ai\NullAiModerationMetricsStore;
use Forwext\Core\Content\Ai\NullAiModerationOverrideRepository;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Moderation\Abuse\AbuseEngine;
use Forwext\Core\Search\Lifecycle\SearchIndexChangeStore;

final class ForumContentPipelineFactory
{
    public static function create(
        TransactionalQueryExecutor $database,
        SearchIndexChangeStore $searchChanges,
        ?AbuseEngine $abuse = null,
        ?ContentPipelineNotifier $notifier = null,
        ?AiModerationService $aiModeration = null,
        ?AiModerationOverrideRepository $aiOverrides = null,
        ?AiModerationDecisionStore $aiDecisions = null,
        ?AiModerationPolicy $aiPolicy = null,
        ?AiModerationForumPolicyRepository $aiForumPolicies = null,
        ?AiModerationMetricsStore $aiMetrics = null,
    ): ContentPipeline {
        $aiProcessor = $aiModeration === null
            ? new PassThroughAiModerationProcessor()
            : new AiModerationPipelineProcessor(
                $aiModeration,
                $aiForumPolicies,
                $aiMetrics ?? new NullAiModerationMetricsStore(),
            );
        $policyProcessor = $aiModeration === null
            ? new DefaultModerationPolicyProcessor()
            : new AiModerationPolicyProcessor(
                $aiPolicy ?? new AiModerationPolicy(),
                $aiOverrides ?? new NullAiModerationOverrideRepository(),
                $aiDecisions ?? new NullAiModerationDecisionStore(),
                $aiForumPolicies,
            );

        return new ContentPipeline(
            $database,
            [
                new DefaultContentValidationProcessor(),
                new AbuseContentPipelineProcessor($abuse),
                new PassThroughSpellcheckProcessor(),
                $aiProcessor,
                $policyProcessor,
            ],
            $notifier ?? new NullContentPipelineNotifier(),
            new SearchChangeContentPipelineIndexer($searchChanges),
        );
    }
}
