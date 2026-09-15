<?php

declare(strict_types=1);
namespace Forwext\Core\Profile;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
final readonly class ProfileDirectoryReader
{
    public function __construct(private QueryExecutor $database) {}
    /** @return list<array{user_id:string,username:string,created_at:string,has_avatar:bool}> */
    public function latestPublic(int $limit=24): array
    {
        if($limit<1||$limit>100){throw new ProfileException('Member directory limit must be 1..100.');}
        $rows=$this->database->fetchAll(new CompiledQuery('SELECT u.`user_id`,u.`username`,u.`created_at_utc`,p.`avatar_path` FROM `forwext_users` u LEFT JOIN `forwext_user_profiles` p ON p.`user_id`=u.`user_id` WHERE u.`status`=\'active\' AND COALESCE(p.`profile_visibility`,\'public\')=\'public\' ORDER BY u.`created_at_utc` DESC LIMIT '.$limit));
        return array_map(static fn(array $r):array=>['user_id'=>(string)$r['user_id'],'username'=>(string)$r['username'],'created_at'=>(string)$r['created_at_utc'],'has_avatar'=>is_string($r['avatar_path']??null)&&$r['avatar_path']!==''],$rows);
    }
}
