<?php

declare(strict_types=1);

namespace Forwext\Core\Analytics\Engagement;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use InvalidArgumentException;

final readonly class DatabaseContentEngagementRepository
{
    public function __construct(private QueryExecutor $database){}

    public function snapshot(int $days,?DateTimeImmutable $now=null):ContentEngagementSnapshot
    {
        if(!in_array($days,[7,30,90],true)){
            throw new InvalidArgumentException('Content engagement range must be 7, 30 or 90 days.');
        }
        $utc=new DateTimeZone('UTC');
        $now=($now??new DateTimeImmutable('now',$utc))->setTimezone($utc);
        $end=$now->setTime(0,0)->add(new DateInterval('P1D'));
        $start=$end->sub(new DateInterval('P'.$days.'D'));
        $params=['start'=>self::format($start),'end'=>self::format($end)];

        return new ContentEngagementSnapshot(
            $days,
            $this->count(
                'SELECT COUNT(*) FROM forwext_post_reactions WHERE created_at_utc>=:start AND created_at_utc<:end',
                $params,
            ),
            $this->count(
                'SELECT COUNT(*) FROM forwext_post_bookmarks WHERE created_at_utc>=:start AND created_at_utc<:end',
                $params,
            ),
            $this->count(
                'SELECT COUNT(*) FROM forwext_watched_threads WHERE updated_at_utc>=:start AND updated_at_utc<:end',
                $params,
            ),
            $this->count(
                'SELECT COUNT(*) FROM forwext_watched_forums WHERE updated_at_utc>=:start AND updated_at_utc<:end',
                $params,
            ),
            $this->count(
                'SELECT COUNT(*) FROM forwext_user_follows WHERE created_at_utc>=:start AND created_at_utc<:end',
                $params,
            ),
            $this->count(
                "SELECT COUNT(*) FROM forwext_analytics_events WHERE event_key='forum.search' "
                .'AND occurred_at_utc>=:start AND occurred_at_utc<:end',
                $params,
            ),
            $this->redactedSearchCount($params),
            $this->zeroResultSearchCount($params),
            $this->forums($params),
            $this->categories($params),
            $this->threads($params),
            $this->searchTerms($params),
            $now,
            $this->followLeaders($params),
        );
    }

    /** @param array<string,string> $params @return list<array{id:string,title:string,threads:int,posts:int,reactions:int,views:int,watches:int}> */
    private function forums(array $params):array
    {
        $rows=$this->database->fetchAll(new CompiledQuery(
            "WITH thread_stats AS ("
            ." SELECT forum_node_id,COUNT(*) AS threads FROM forwext_threads"
            ." WHERE created_at_utc>=:start AND created_at_utc<:end"
            ." AND deleted=0 AND moderation_state='visible' AND merged_into_thread_id IS NULL"
            ." GROUP BY forum_node_id"
            ."), post_stats AS ("
            ." SELECT t.forum_node_id,COUNT(*) AS posts FROM forwext_posts p"
            ." INNER JOIN forwext_threads t ON t.thread_id=p.thread_id"
            ." WHERE p.created_at_utc>=:start AND p.created_at_utc<:end"
            ." AND p.deleted=0 AND p.moderation_state='visible'"
            ." AND t.deleted=0 AND t.moderation_state='visible' AND t.merged_into_thread_id IS NULL"
            ." GROUP BY t.forum_node_id"
            ."), reaction_stats AS ("
            ." SELECT t.forum_node_id,COUNT(*) AS reactions FROM forwext_post_reactions r"
            ." INNER JOIN forwext_posts p ON p.post_id=r.post_id"
            ." INNER JOIN forwext_threads t ON t.thread_id=p.thread_id"
            ." WHERE r.created_at_utc>=:start AND r.created_at_utc<:end"
            ." AND p.deleted=0 AND p.moderation_state='visible'"
            ." AND t.deleted=0 AND t.moderation_state='visible' AND t.merged_into_thread_id IS NULL"
            ." GROUP BY t.forum_node_id"
            ."), view_stats AS ("
            ." SELECT forum_id,COUNT(*) AS views FROM forwext_analytics_events"
            ." WHERE event_key='forum.view' AND forum_id IS NOT NULL"
            ." AND occurred_at_utc>=:start AND occurred_at_utc<:end GROUP BY forum_id"
            ."), watch_stats AS ("
            ." SELECT forum_node_id,COUNT(*) AS watches FROM forwext_watched_forums"
            ." WHERE updated_at_utc>=:start AND updated_at_utc<:end GROUP BY forum_node_id"
            .") SELECT n.node_id,n.title,"
            ." COALESCE(ts.threads,0) AS threads,COALESCE(ps.posts,0) AS posts,"
            ." COALESCE(rs.reactions,0) AS reactions,COALESCE(vs.views,0) AS views,"
            ." COALESCE(ws.watches,0) AS watches"
            ." FROM forwext_nodes n"
            ." LEFT JOIN thread_stats ts ON ts.forum_node_id=n.node_id"
            ." LEFT JOIN post_stats ps ON ps.forum_node_id=n.node_id"
            ." LEFT JOIN reaction_stats rs ON rs.forum_node_id=n.node_id"
            ." LEFT JOIN view_stats vs ON vs.forum_id=n.node_id"
            ." LEFT JOIN watch_stats ws ON ws.forum_node_id=n.node_id"
            ." WHERE n.node_type='forum'"
            ." ORDER BY (COALESCE(vs.views,0)+COALESCE(rs.reactions,0)+COALESCE(ws.watches,0)) DESC,"
            ." COALESCE(ps.posts,0) DESC,n.title,n.node_id LIMIT 50",
            $params,
        ));
        return array_map(static fn(array $row):array=>[
            'id'=>(string)$row['node_id'],
            'title'=>(string)$row['title'],
            'threads'=>(int)$row['threads'],
            'posts'=>(int)$row['posts'],
            'reactions'=>(int)$row['reactions'],
            'views'=>(int)$row['views'],
            'watches'=>(int)$row['watches'],
        ],$rows);
    }

    /** @param array<string,string> $params @return list<array{id:string,title:string,forums:int,threads:int,posts:int,reactions:int,views:int,watches:int}> */
    private function categories(array $params):array
    {
        $rows=$this->database->fetchAll(new CompiledQuery(
            "WITH RECURSIVE node_tree AS ("
            ." SELECT node_id AS root_id,node_id,parent_id,node_type FROM forwext_nodes WHERE node_type='category'"
            ." UNION ALL SELECT t.root_id,n.node_id,n.parent_id,n.node_type FROM forwext_nodes n"
            ." INNER JOIN node_tree t ON n.parent_id=t.node_id"
            ."), forums AS ("
            ." SELECT root_id,node_id AS forum_id FROM node_tree WHERE node_type='forum'"
            ."), thread_stats AS ("
            ." SELECT forum_node_id,COUNT(*) AS threads FROM forwext_threads"
            ." WHERE created_at_utc>=:start AND created_at_utc<:end"
            ." AND deleted=0 AND moderation_state='visible' AND merged_into_thread_id IS NULL"
            ." GROUP BY forum_node_id"
            ."), post_stats AS ("
            ." SELECT t.forum_node_id,COUNT(*) AS posts FROM forwext_posts p"
            ." INNER JOIN forwext_threads t ON t.thread_id=p.thread_id"
            ." WHERE p.created_at_utc>=:start AND p.created_at_utc<:end"
            ." AND p.deleted=0 AND p.moderation_state='visible'"
            ." AND t.deleted=0 AND t.moderation_state='visible' AND t.merged_into_thread_id IS NULL"
            ." GROUP BY t.forum_node_id"
            ."), reaction_stats AS ("
            ." SELECT t.forum_node_id,COUNT(*) AS reactions FROM forwext_post_reactions r"
            ." INNER JOIN forwext_posts p ON p.post_id=r.post_id"
            ." INNER JOIN forwext_threads t ON t.thread_id=p.thread_id"
            ." WHERE r.created_at_utc>=:start AND r.created_at_utc<:end"
            ." AND p.deleted=0 AND p.moderation_state='visible'"
            ." AND t.deleted=0 AND t.moderation_state='visible' AND t.merged_into_thread_id IS NULL"
            ." GROUP BY t.forum_node_id"
            ."), view_stats AS ("
            ." SELECT forum_id,COUNT(*) AS views FROM forwext_analytics_events"
            ." WHERE event_key='forum.view' AND forum_id IS NOT NULL"
            ." AND occurred_at_utc>=:start AND occurred_at_utc<:end GROUP BY forum_id"
            ."), watch_stats AS ("
            ." SELECT forum_node_id,COUNT(*) AS watches FROM forwext_watched_forums"
            ." WHERE updated_at_utc>=:start AND updated_at_utc<:end GROUP BY forum_node_id"
            .") SELECT c.node_id,c.title,COUNT(DISTINCT f.forum_id) AS forums,"
            ." COALESCE(SUM(ts.threads),0) AS threads,COALESCE(SUM(ps.posts),0) AS posts,"
            ." COALESCE(SUM(rs.reactions),0) AS reactions,COALESCE(SUM(vs.views),0) AS views,"
            ." COALESCE(SUM(ws.watches),0) AS watches"
            ." FROM forwext_nodes c LEFT JOIN forums f ON f.root_id=c.node_id"
            ." LEFT JOIN thread_stats ts ON ts.forum_node_id=f.forum_id"
            ." LEFT JOIN post_stats ps ON ps.forum_node_id=f.forum_id"
            ." LEFT JOIN reaction_stats rs ON rs.forum_node_id=f.forum_id"
            ." LEFT JOIN view_stats vs ON vs.forum_id=f.forum_id"
            ." LEFT JOIN watch_stats ws ON ws.forum_node_id=f.forum_id"
            ." WHERE c.node_type='category' GROUP BY c.node_id,c.title"
            ." ORDER BY (COALESCE(SUM(vs.views),0)+COALESCE(SUM(rs.reactions),0)+COALESCE(SUM(ws.watches),0)) DESC,"
            ." c.title,c.node_id LIMIT 50",
            $params,
        ));
        return array_map(static fn(array $row):array=>[
            'id'=>(string)$row['node_id'],
            'title'=>(string)$row['title'],
            'forums'=>(int)$row['forums'],
            'threads'=>(int)$row['threads'],
            'posts'=>(int)$row['posts'],
            'reactions'=>(int)$row['reactions'],
            'views'=>(int)$row['views'],
            'watches'=>(int)$row['watches'],
        ],$rows);
    }

    /** @param array<string,string> $params @return list<array{id:string,title:string,forum_title:string,replies:int,views:int,reactions:int,bookmarks:int,watches:int,watch_rate:?float,bookmark_rate:?float,reaction_rate:?float}> */
    private function threads(array $params):array
    {
        $rows=$this->database->fetchAll(new CompiledQuery(
            "WITH view_stats AS ("
            ." SELECT content_id,COUNT(*) AS views FROM forwext_analytics_events"
            ." WHERE event_key='content.thread.view' AND content_type='thread' AND content_id IS NOT NULL"
            ." AND occurred_at_utc>=:start AND occurred_at_utc<:end GROUP BY content_id"
            ."), reaction_stats AS ("
            ." SELECT p.thread_id,COUNT(*) AS reactions FROM forwext_post_reactions r"
            ." INNER JOIN forwext_posts p ON p.post_id=r.post_id"
            ." WHERE r.created_at_utc>=:start AND r.created_at_utc<:end GROUP BY p.thread_id"
            ."), bookmark_stats AS ("
            ." SELECT p.thread_id,COUNT(*) AS bookmarks FROM forwext_post_bookmarks b"
            ." INNER JOIN forwext_posts p ON p.post_id=b.post_id"
            ." WHERE b.created_at_utc>=:start AND b.created_at_utc<:end GROUP BY p.thread_id"
            ."), watch_stats AS ("
            ." SELECT thread_id,COUNT(*) AS watches FROM forwext_watched_threads"
            ." WHERE updated_at_utc>=:start AND updated_at_utc<:end GROUP BY thread_id"
            ."), reply_stats AS ("
            ." SELECT thread_id,GREATEST(COUNT(*)-1,0) AS replies FROM forwext_posts"
            ." WHERE created_at_utc<:end AND deleted=0 AND moderation_state='visible' GROUP BY thread_id"
            .") SELECT t.thread_id,t.title,n.title AS forum_title,"
            ." COALESCE(rep.replies,0) AS replies,COALESCE(v.views,0) AS views,"
            ." COALESCE(r.reactions,0) AS reactions,COALESCE(b.bookmarks,0) AS bookmarks,"
            ." COALESCE(w.watches,0) AS watches"
            ." FROM forwext_threads t INNER JOIN forwext_nodes n ON n.node_id=t.forum_node_id"
            ." LEFT JOIN view_stats v ON v.content_id=t.thread_id"
            ." LEFT JOIN reaction_stats r ON r.thread_id=t.thread_id"
            ." LEFT JOIN bookmark_stats b ON b.thread_id=t.thread_id"
            ." LEFT JOIN watch_stats w ON w.thread_id=t.thread_id"
            ." LEFT JOIN reply_stats rep ON rep.thread_id=t.thread_id"
            ." WHERE t.deleted=0 AND t.moderation_state='visible' AND t.merged_into_thread_id IS NULL"
            ." AND (COALESCE(v.views,0)+COALESCE(r.reactions,0)+COALESCE(b.bookmarks,0)+COALESCE(w.watches,0))>0"
            ." ORDER BY COALESCE(v.views,0) DESC,"
            ." (COALESCE(r.reactions,0)+COALESCE(b.bookmarks,0)+COALESCE(w.watches,0)) DESC,"
            ." t.updated_at_utc DESC,t.thread_id LIMIT 50",
            $params,
        ));

        return array_map(static function(array $row):array{
            $views=(int)$row['views'];
            $reactions=(int)$row['reactions'];
            $bookmarks=(int)$row['bookmarks'];
            $watches=(int)$row['watches'];
            return [
                'id'=>(string)$row['thread_id'],
                'title'=>(string)$row['title'],
                'forum_title'=>(string)$row['forum_title'],
                'replies'=>(int)$row['replies'],
                'views'=>$views,
                'reactions'=>$reactions,
                'bookmarks'=>$bookmarks,
                'watches'=>$watches,
                'watch_rate'=>ContentEngagementSnapshot::rate($watches,$views),
                'bookmark_rate'=>ContentEngagementSnapshot::rate($bookmarks,$views),
                'reaction_rate'=>ContentEngagementSnapshot::rate($reactions,$views),
            ];
        },$rows);
    }

    /** @param array<string,string> $params @return list<array{id:string,username:string,follows:int}> */
    private function followLeaders(array $params):array
    {
        $rows=$this->database->fetchAll(new CompiledQuery(
            'SELECT u.user_id,u.username,COUNT(*) AS follows FROM forwext_user_follows f '
            .'INNER JOIN forwext_users u ON u.user_id=f.followed_user_id '
            .'WHERE f.created_at_utc>=:start AND f.created_at_utc<:end '
            .'GROUP BY u.user_id,u.username ORDER BY follows DESC,u.username,u.user_id LIMIT 25',
            $params,
        ));
        return array_map(static fn(array $row):array=>[
            'id'=>(string)$row['user_id'],
            'username'=>(string)$row['username'],
            'follows'=>(int)$row['follows'],
        ],$rows);
    }

    /** @param array<string,string> $params @return list<array{term:string,searches:int,zero_results:int,avg_results:float}> */
    private function searchTerms(array $params):array
    {
        $rows=$this->database->fetchAll(new CompiledQuery(
            'SELECT display_term,SUM(search_count) AS searches,SUM(zero_result_count) AS zero_results,'
            .'SUM(result_count_total) AS result_total FROM forwext_search_term_analytics '
            .'WHERE event_day_utc>=DATE(:start) AND event_day_utc<DATE(:end) AND display_term IS NOT NULL '
            .'GROUP BY display_term ORDER BY searches DESC,zero_results DESC,display_term LIMIT 50',
            $params,
        ));
        return array_map(static function(array $row):array{
            $searches=(int)$row['searches'];
            return [
                'term'=>(string)$row['display_term'],
                'searches'=>$searches,
                'zero_results'=>(int)$row['zero_results'],
                'avg_results'=>$searches>0?((int)$row['result_total']/$searches):0.0,
            ];
        },$rows);
    }

    /** @param array<string,string> $params */
    private function redactedSearchCount(array $params):int
    {
        return $this->count(
            "SELECT COUNT(*) FROM forwext_analytics_events WHERE event_key='forum.search'"
            ." AND occurred_at_utc>=:start AND occurred_at_utc<:end"
            ." AND JSON_UNQUOTE(JSON_EXTRACT(dimensions_json,'$.query_class'))='redacted'",
            $params,
        );
    }

    /** @param array<string,string> $params */
    private function zeroResultSearchCount(array $params):int
    {
        return $this->count(
            "SELECT COUNT(*) FROM forwext_analytics_events WHERE event_key='forum.search'"
            ." AND occurred_at_utc>=:start AND occurred_at_utc<:end"
            ." AND JSON_UNQUOTE(JSON_EXTRACT(dimensions_json,'$.result_bucket'))='zero'",
            $params,
        );
    }

    /** @param array<string,mixed> $parameters */
    private function count(string $sql,array $parameters=[]):int
    {
        return max(0,(int)$this->database->fetchValue(new CompiledQuery($sql,$parameters)));
    }

    private static function format(DateTimeImmutable $value):string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
