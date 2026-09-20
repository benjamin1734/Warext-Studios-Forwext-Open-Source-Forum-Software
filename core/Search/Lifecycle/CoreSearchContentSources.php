<?php

declare(strict_types=1);

namespace Forwext\Core\Search\Lifecycle;

use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Faq\Search\DatabaseFaqSearchContentSource;
use Forwext\Core\Giveaway\Search\DatabaseGiveawaySearchContentSource;
use Forwext\Core\Marketplace\Search\DatabaseMarketplaceSearchContentSource;
use Forwext\Core\Portfolio\Search\DatabasePortfolioSearchContentSource;
use Forwext\Core\Search\Lifecycle\Source\DatabaseForumSearchContentSource;
use Forwext\Core\Search\Lifecycle\Source\DatabasePostSearchContentSource;
use Forwext\Core\Search\Lifecycle\Source\DatabaseThreadSearchContentSource;
use Forwext\Core\Search\Lifecycle\Source\DatabaseUserSearchContentSource;

final class CoreSearchContentSources
{
    public static function create(QueryExecutor $database): SearchContentSourceRegistry
    {
        $registry = new SearchContentSourceRegistry();
        $registry->register(new DatabaseUserSearchContentSource($database));
        $registry->register(new DatabaseForumSearchContentSource($database));
        $registry->register(new DatabaseThreadSearchContentSource($database));
        $registry->register(new DatabasePostSearchContentSource($database));
        $registry->register(new DatabaseFaqSearchContentSource($database));
        $registry->register(new DatabasePortfolioSearchContentSource($database));
        $registry->register(new DatabaseGiveawaySearchContentSource($database));
        $registry->register(new DatabaseMarketplaceSearchContentSource($database));
        return $registry;
    }
}
