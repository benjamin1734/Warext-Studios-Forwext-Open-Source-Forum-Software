<?php

declare(strict_types=1);

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\DatabaseConfig;
use Forwext\Core\Database\PdoConnectionFactory;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$env = static function (string $key, ?string $default = null): string {
    $value = getenv($key);
    if ($value === false || $value === '') {
        if ($default !== null) {
            return $default;
        }
        throw new RuntimeException(sprintf('Required environment variable %s is missing.', $key));
    }
    return $value;
};

$database = (new PdoConnectionFactory())->create(new DatabaseConfig(
    $env('FORWEXT_TEST_DB_HOST', '127.0.0.1'),
    (int) $env('FORWEXT_TEST_DB_PORT', '3308'),
    $env('FORWEXT_TEST_DB_NAME', 'forwext_browser_ci'),
    $env('FORWEXT_TEST_DB_USER', 'root'),
    $env('FORWEXT_TEST_DB_PASSWORD', 'root'),
));

$administratorId = $database->fetchValue(new CompiledQuery(
    "SELECT user_id FROM forwext_users WHERE username_key='ci-admin' AND status='active' LIMIT 1",
));
if (!is_string($administratorId) || preg_match('/^[a-f0-9]{32}$/D', $administratorId) !== 1) {
    throw new RuntimeException('Phase 8 browser fixture administrator is unavailable.');
}

$portfolioId = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
$faqArticleId = 'ffffffffffffffffffffffffffffffff';
$bugReportId = 'abababababababababababababababab';

$database->execute(new CompiledQuery(
    'INSERT INTO forwext_portfolio_projects '
    . '(project_id,owner_user_id,category_key,slug,title,summary,description,state,featured,created_at_utc,updated_at_utc) '
    . "VALUES (:project_id,:owner,'general','phase8-browser-project','Phase 8 Tarayıcı Projesi',"
    . "'Portfolyo yoğunluk görünümünü gerçek veriyle doğrular.',"
    . "'Phase 8 portfolyo liste, detay ve yönetim yüzeylerinin gerçek Chromium doğrulama kaydıdır.',"
    . "'published',1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) "
    . 'ON DUPLICATE KEY UPDATE owner_user_id=VALUES(owner_user_id),category_key=VALUES(category_key),'
    . "state='published',featured=1,updated_at_utc=UTC_TIMESTAMP(6)",
    ['project_id'=>$portfolioId,'owner'=>$administratorId],
));
foreach (['forwext','phase8','browser'] as $tag) {
    $database->execute(new CompiledQuery(
        'INSERT IGNORE INTO forwext_portfolio_project_tags (project_id,tag_key) VALUES (:project_id,:tag_key)',
        ['project_id'=>$portfolioId,'tag_key'=>$tag],
    ));
}

$database->execute(new CompiledQuery(
    'INSERT INTO forwext_faq_articles '
    . '(article_id,category_key,slug,question,answer,visibility,language,sort_order,seo_title,seo_description,active,created_at_utc,updated_at_utc) '
    . "VALUES (:article_id,'general','phase8-browser-faq','Phase 8 tarayıcı doğrulaması nasıl çalışır?',"
    . "'Gerçek kurulum üzerinde masaüstü ve mobil Chromium akışlarıyla doğrulanır.',"
    . "'public','tr',10,NULL,NULL,1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) "
    . 'ON DUPLICATE KEY UPDATE question=VALUES(question),answer=VALUES(answer),visibility=VALUES(visibility),'
    . 'active=1,updated_at_utc=UTC_TIMESTAMP(6)',
    ['article_id'=>$faqArticleId],
));
foreach (['phase8','browser'] as $tag) {
    $database->execute(new CompiledQuery(
        'INSERT IGNORE INTO forwext_faq_article_tags (article_id,tag_key) VALUES (:article_id,:tag_key)',
        ['article_id'=>$faqArticleId,'tag_key'=>$tag],
    ));
}

$database->execute(new CompiledQuery(
    'INSERT INTO forwext_bug_reports '
    . '(report_id,category_key,reporter_user_id,assigned_user_id,title,summary,severity,status,finalized_at_utc,created_at_utc,updated_at_utc,version) '
    . "VALUES (:report_id,'frontend',:reporter,NULL,'Phase 8 tarayıcı hata kaydı',"
    . "'Kişisel hata listesi ve detay yoğunluğunu gerçek veriyle doğrular.','high','in_review',NULL,"
    . 'UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),1) '
    . 'ON DUPLICATE KEY UPDATE reporter_user_id=VALUES(reporter_user_id),severity=VALUES(severity),'
    . "status='in_review',updated_at_utc=UTC_TIMESTAMP(6)",
    ['report_id'=>$bugReportId,'reporter'=>$administratorId],
));

$portfolioCount = (int) $database->fetchValue(new CompiledQuery(
    "SELECT COUNT(*) FROM forwext_portfolio_projects WHERE project_id=:id AND state='published'",
    ['id'=>$portfolioId],
));
$faqCount = (int) $database->fetchValue(new CompiledQuery(
    "SELECT COUNT(*) FROM forwext_faq_articles WHERE article_id=:id AND active=1",
    ['id'=>$faqArticleId],
));
$bugCount = (int) $database->fetchValue(new CompiledQuery(
    "SELECT COUNT(*) FROM forwext_bug_reports WHERE report_id=:id AND reporter_user_id=:reporter",
    ['id'=>$bugReportId,'reporter'=>$administratorId],
));
if ($portfolioCount !== 1 || $faqCount !== 1 || $bugCount !== 1) {
    throw new RuntimeException('Phase 8 browser fixtures were not created completely.');
}

echo "Phase 8 browser fixtures seeded.\n";
