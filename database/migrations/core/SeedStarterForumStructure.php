<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class SeedStarterForumStructure implements Migration
{
    private const CATEGORY_ID = '00000000000000000000000000000001';
    private const FORUM_ID = '00000000000000000000000000000002';

    public function id(): MigrationId
    {
        return MigrationId::fromString('20260927113000_starter_forum_structure');
    }

    public function owner(): MigrationOwner
    {
        return MigrationOwner::core();
    }

    public function isIdempotent(): bool
    {
        return true;
    }

    public function isTransactional(): bool
    {
        return true;
    }

    public function up(MigrationContext $context): void
    {
        $nodeCount = (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_nodes',
        ));
        if ($nodeCount > 0) {
            return;
        }

        $context->execute(new CompiledQuery(
            'INSERT INTO forwext_nodes '
            . '(node_id,parent_id,node_type,title,slug,description,visibility,sort_order,'
            . 'page_content,link_target,link_new_window,created_at_utc,updated_at_utc) '
            . "VALUES (:node_id,NULL,'category',:title,:slug,:description,'listed',10,"
            . 'NULL,NULL,0,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))',
            [
                'node_id' => self::CATEGORY_ID,
                'title' => 'Genel',
                'slug' => 'genel',
                'description' => 'Topluluğun genel kategorisi.',
            ],
        ));

        $context->execute(new CompiledQuery(
            'INSERT INTO forwext_nodes '
            . '(node_id,parent_id,node_type,title,slug,description,visibility,sort_order,'
            . 'page_content,link_target,link_new_window,created_at_utc,updated_at_utc) '
            . "VALUES (:node_id,:parent_id,'forum',:title,:slug,:description,'listed',10,"
            . 'NULL,NULL,0,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))',
            [
                'node_id' => self::FORUM_ID,
                'parent_id' => self::CATEGORY_ID,
                'title' => 'Genel Sohbet',
                'slug' => 'genel-sohbet',
                'description' => 'Topluluk üyelerinin genel konuları konuşabileceği başlangıç forumu.',
            ],
        ));

        $context->execute(new CompiledQuery(
            'INSERT INTO forwext_forum_settings '
            . '(node_id,allow_new_threads,allow_replies,require_thread_approval,require_post_approval,'
            . 'default_thread_sort,threads_per_page,updated_at_utc) '
            . "VALUES (:node_id,1,1,0,0,'last_post',30,UTC_TIMESTAMP(6))",
            ['node_id' => self::FORUM_ID],
        ));
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $nodes = (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_nodes',
        ));
        $forumsWithoutSettings = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_nodes n "
            . 'LEFT JOIN forwext_forum_settings s ON s.node_id=n.node_id '
            . "WHERE n.node_type='forum' AND s.node_id IS NULL",
        ));

        return $nodes > 0 && $forumsWithoutSettings === 0
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Starter forum structure or forum settings are incomplete.');
    }
}
