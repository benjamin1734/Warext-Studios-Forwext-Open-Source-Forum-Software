<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreatePortfolioSystem implements Migration
{
    private const PERMISSIONS = [
        'portfolio.view' => 'View published portfolio projects.',
        'portfolio.create' => 'Create portfolio projects.',
        'portfolio.manage_own' => 'Manage own portfolio projects.',
        'portfolio.manage_all' => 'Manage, feature and moderate all portfolio projects.',
        'portfolio.comment.create' => 'Comment on visible portfolio projects.',
        'portfolio.reaction.use' => 'React to visible portfolio projects.',
    ];

    public function id(): MigrationId
    {
        return MigrationId::fromString('20260919130000_portfolio_system');
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
        return false;
    }

    public function up(MigrationContext $context): void
    {
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_portfolio_categories ('
            . 'category_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'label VARCHAR(120) NOT NULL,description VARCHAR(500) NOT NULL DEFAULT \'\','
            . 'sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,active TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,'
            . 'created_at_utc DATETIME(6) NOT NULL,updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(category_key),KEY idx_forwext_portfolio_categories(active,sort_order,category_key)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_portfolio_projects ('
            . 'project_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'owner_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'category_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'slug VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'title VARCHAR(180) NOT NULL,summary VARCHAR(500) NOT NULL DEFAULT \'\',description MEDIUMTEXT NOT NULL,'
            . "state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',"
            . 'featured TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,created_at_utc DATETIME(6) NOT NULL,updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(project_id),UNIQUE KEY uq_forwext_portfolio_owner_slug(owner_user_id,slug),'
            . 'KEY idx_forwext_portfolio_listing(state,featured,updated_at_utc,project_id),'
            . 'KEY idx_forwext_portfolio_owner(owner_user_id,state,updated_at_utc),'
            . 'CONSTRAINT fk_forwext_portfolio_owner FOREIGN KEY(owner_user_id) REFERENCES forwext_users(user_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_portfolio_category FOREIGN KEY(category_key) REFERENCES forwext_portfolio_categories(category_key) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_portfolio_project_tags ('
            . 'project_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'tag_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,PRIMARY KEY(project_id,tag_key),'
            . 'KEY idx_forwext_portfolio_tag(tag_key,project_id),'
            . 'CONSTRAINT fk_forwext_portfolio_tag_project FOREIGN KEY(project_id) REFERENCES forwext_portfolio_projects(project_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_portfolio_media ('
            . 'media_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'project_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,path VARCHAR(1024) NOT NULL,'
            . 'alt_text VARCHAR(200) NOT NULL DEFAULT \'\',sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,created_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(media_id),KEY idx_forwext_portfolio_media(project_id,sort_order,media_id),'
            . 'CONSTRAINT fk_forwext_portfolio_media_project FOREIGN KEY(project_id) REFERENCES forwext_portfolio_projects(project_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_portfolio_comments ('
            . 'comment_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'project_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'author_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,body TEXT NOT NULL,'
            . "state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',"
            . 'created_at_utc DATETIME(6) NOT NULL,updated_at_utc DATETIME(6) NOT NULL,PRIMARY KEY(comment_id),'
            . 'KEY idx_forwext_portfolio_comments(project_id,state,created_at_utc,comment_id),'
            . 'CONSTRAINT fk_forwext_portfolio_comment_project FOREIGN KEY(project_id) REFERENCES forwext_portfolio_projects(project_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_portfolio_comment_user FOREIGN KEY(author_user_id) REFERENCES forwext_users(user_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_portfolio_reactions ('
            . 'project_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'reaction_key VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,updated_at_utc DATETIME(6) NOT NULL,PRIMARY KEY(project_id,user_id),'
            . 'KEY idx_forwext_portfolio_reactions_type(reaction_key,project_id),'
            . 'CONSTRAINT fk_forwext_portfolio_reaction_project FOREIGN KEY(project_id) REFERENCES forwext_portfolio_projects(project_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_portfolio_reaction_user FOREIGN KEY(user_id) REFERENCES forwext_users(user_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_portfolio_reaction_type FOREIGN KEY(reaction_key) REFERENCES forwext_reaction_types(reaction_key) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_portfolio_history ('
            . 'history_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'project_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'actor_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'action VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'from_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,to_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,PRIMARY KEY(history_id),'
            . 'KEY idx_forwext_portfolio_history(project_id,created_at_utc,history_id),'
            . 'CONSTRAINT fk_forwext_portfolio_history_project FOREIGN KEY(project_id) REFERENCES forwext_portfolio_projects(project_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_portfolio_history_actor FOREIGN KEY(actor_user_id) REFERENCES forwext_users(user_id) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            "INSERT IGNORE INTO forwext_portfolio_categories "
            . "(category_key,label,description,sort_order,active,created_at_utc,updated_at_utc) "
            . "VALUES ('general','Genel','Genel portfolyo projeleri.',10,1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",
        ));

        $context->execute(new CompiledQuery(
            'INSERT IGNORE INTO forwext_user_profile_tabs (user_id,tab_key,enabled,visibility,sort_order) '
            . "SELECT user_id,'portfolio',1,'public',20 FROM forwext_users",
        ));

        foreach (self::PERMISSIONS as $key => $description) {
            $context->execute(new CompiledQuery(
                'INSERT INTO forwext_permissions(permission_key,value_type,description,created_at_utc,updated_at_utc) '
                . "VALUES (:key,'flag',:description,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) "
                . 'ON DUPLICATE KEY UPDATE value_type=VALUES(value_type),description=VALUES(description),updated_at_utc=VALUES(updated_at_utc)',
                ['key' => $key, 'description' => $description],
            ));
        }

        foreach (['new_user','member','verified','moderator','administrator'] as $template) {
            foreach (array_keys(self::PERMISSIONS) as $permission) {
                $effect = match ($permission) {
                    'portfolio.view' => 'allow',
                    'portfolio.create', 'portfolio.manage_own', 'portfolio.comment.create', 'portfolio.reaction.use'
                        => $template === 'new_user' ? 'deny' : 'allow',
                    'portfolio.manage_all' => in_array($template, ['moderator','administrator'], true) ? 'allow' : 'deny',
                    default => 'deny',
                };
                $context->execute(new CompiledQuery(
                    'INSERT INTO forwext_permission_template_rules(template_key,permission_key,effect,numeric_limit) '
                    . 'VALUES (:template,:permission,:effect,NULL) '
                    . 'ON DUPLICATE KEY UPDATE effect=VALUES(effect),numeric_limit=NULL',
                    ['template' => $template, 'permission' => $permission, 'effect' => $effect],
                ));
            }
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $tables = (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() '
            . "AND TABLE_NAME IN ('forwext_portfolio_categories','forwext_portfolio_projects','forwext_portfolio_project_tags',"
            . "'forwext_portfolio_media','forwext_portfolio_comments','forwext_portfolio_reactions','forwext_portfolio_history')",
        ));
        $permissions = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permissions WHERE permission_key IN "
            . "('portfolio.view','portfolio.create','portfolio.manage_own','portfolio.manage_all','portfolio.comment.create','portfolio.reaction.use')",
        ));
        $rules = (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_permission_template_rules '
            . "WHERE template_key IN ('new_user','member','verified','moderator','administrator') "
            . "AND permission_key LIKE 'portfolio.%'",
        ));
        $starter = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_portfolio_categories WHERE category_key='general'",
        ));
        $profileTabs = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_user_profile_tabs t INNER JOIN forwext_users u ON u.user_id=t.user_id "
            . "WHERE t.tab_key='portfolio'",
        ));
        $users = (int) $context->fetchValue(new CompiledQuery('SELECT COUNT(*) FROM forwext_users'));

        return $tables === 7 && $permissions === 6 && $rules === 30 && $starter === 1 && $profileTabs === $users
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Portfolio schema or permission defaults are incomplete.');
    }
}
