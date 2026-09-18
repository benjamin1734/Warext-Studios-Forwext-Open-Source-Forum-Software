<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateFaqSystem implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260918014000_faq_system');
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
            'CREATE TABLE IF NOT EXISTS forwext_faq_categories ('
            . 'category_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'label VARCHAR(120) NOT NULL,'
            . "description VARCHAR(500) NOT NULL DEFAULT '',"
            . 'language VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'visibility VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'active TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (category_key),'
            . 'KEY idx_forwext_faq_category_listing (language,active,visibility,sort_order,category_key)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_faq_articles ('
            . 'article_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'category_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'slug VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'question VARCHAR(300) NOT NULL,'
            . 'answer MEDIUMTEXT NOT NULL,'
            . 'visibility VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'language VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'seo_title VARCHAR(200) NULL,'
            . 'seo_description VARCHAR(320) NULL,'
            . 'active TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (article_id),'
            . 'UNIQUE KEY uq_forwext_faq_language_slug (language,slug),'
            . 'KEY idx_forwext_faq_article_category (category_key,active,sort_order,article_id),'
            . 'KEY idx_forwext_faq_article_public (active,visibility,language,updated_at_utc,article_id),'
            . 'CONSTRAINT fk_forwext_faq_article_category FOREIGN KEY (category_key) '
            . 'REFERENCES forwext_faq_categories (category_key) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_faq_article_tags ('
            . 'article_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'tag_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'PRIMARY KEY (article_id,tag_key),'
            . 'KEY idx_forwext_faq_tag_lookup (tag_key,article_id),'
            . 'CONSTRAINT fk_forwext_faq_tag_article FOREIGN KEY (article_id) '
            . 'REFERENCES forwext_faq_articles (article_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_faq_helpful_votes ('
            . 'article_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'helpful TINYINT(1) UNSIGNED NOT NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (article_id,user_id),'
            . 'KEY idx_forwext_faq_helpful_summary (article_id,helpful),'
            . 'CONSTRAINT fk_forwext_faq_helpful_article FOREIGN KEY (article_id) '
            . 'REFERENCES forwext_faq_articles (article_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_faq_helpful_user FOREIGN KEY (user_id) '
            . 'REFERENCES forwext_users (user_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'INSERT IGNORE INTO forwext_faq_categories '
            . '(category_key,label,description,language,visibility,sort_order,active,created_at_utc,updated_at_utc) '
            . "VALUES ('general','Genel','Sık sorulan genel sorular.','tr','public',10,1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",
        ));

        $permissions = [
            'faq.view'=>'View FAQ content that requires member access.',
            'faq.manage'=>'Manage FAQ categories, articles, imports and exports.',
        ];
        foreach ($permissions as $permissionKey=>$description) {
            $context->execute(new CompiledQuery(
                'INSERT INTO forwext_permissions '
                . '(permission_key,value_type,description,created_at_utc,updated_at_utc) '
                . "VALUES (:permission_key,'flag',:description,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) "
                . 'ON DUPLICATE KEY UPDATE value_type=VALUES(value_type),description=VALUES(description),'
                . 'updated_at_utc=VALUES(updated_at_utc)',
                ['permission_key'=>$permissionKey,'description'=>$description],
            ));
        }

        foreach (['new_user','member','verified','moderator','administrator'] as $templateKey) {
            foreach (array_keys($permissions) as $permissionKey) {
                $effect = match ($permissionKey) {
                    'faq.view' => 'allow',
                    'faq.manage' => in_array($templateKey, ['moderator','administrator'], true) ? 'allow' : 'deny',
                    default => 'deny',
                };
                $context->execute(new CompiledQuery(
                    'INSERT IGNORE INTO forwext_permission_template_rules '
                    . '(template_key,permission_key,effect,numeric_limit) '
                    . 'VALUES (:template_key,:permission_key,:effect,NULL)',
                    ['template_key'=>$templateKey,'permission_key'=>$permissionKey,'effect'=>$effect],
                ));
            }
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $tables = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() '
            . "AND TABLE_NAME IN ('forwext_faq_categories','forwext_faq_articles',"
            . "'forwext_faq_article_tags','forwext_faq_helpful_votes')",
        ));
        $foreignKeys = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS '
            . 'WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME IN ('
            . "'fk_forwext_faq_article_category','fk_forwext_faq_tag_article',"
            . "'fk_forwext_faq_helpful_article','fk_forwext_faq_helpful_user')",
        ));
        $permissions = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permissions WHERE permission_key IN ('faq.view','faq.manage') "
            . "AND value_type='flag'",
        ));
        $templateRules = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_permission_template_rules '
            . "WHERE template_key IN ('new_user','member','verified','moderator','administrator') "
            . "AND permission_key IN ('faq.view','faq.manage')",
        ));
        $starter = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_faq_categories WHERE category_key='general'",
        ));
        $uniqueSlug = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() '
            . "AND TABLE_NAME='forwext_faq_articles' AND INDEX_NAME='uq_forwext_faq_language_slug' AND NON_UNIQUE=0",
        ));

        return (int) $tables === 4
            && (int) $foreignKeys === 4
            && (int) $permissions === 2
            && (int) $templateRules === 10
            && (int) $starter === 1
            && (int) $uniqueSlug === 2
            ? MigrationVerification::passed()
            : MigrationVerification::failed('FAQ schema, permission defaults or indexes are incomplete.');
    }
}
