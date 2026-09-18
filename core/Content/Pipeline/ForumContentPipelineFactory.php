<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Pipeline;

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
    ): ContentPipeline {
        return new ContentPipeline(
            $database,
            [
                new DefaultContentValidationProcessor(),
                new AbuseContentPipelineProcessor($abuse),
                new PassThroughSpellcheckProcessor(),
                new PassThroughAiModerationProcessor(),
                new DefaultModerationPolicyProcessor(),
            ],
            $notifier ?? new NullContentPipelineNotifier(),
            new SearchChangeContentPipelineIndexer($searchChanges),
        );
    }
}
