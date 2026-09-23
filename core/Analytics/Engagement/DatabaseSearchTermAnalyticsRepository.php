<?php

declare(strict_types=1);

namespace Forwext\Core\Analytics\Engagement;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use InvalidArgumentException;

final readonly class DatabaseSearchTermAnalyticsRepository
{
    public function __construct(private QueryExecutor $database){}

    public function record(
        string $termKey,
        string $displayTerm,
        int $resultCount,
        DateTimeImmutable $at,
    ):void{
        if(preg_match('/^[a-f0-9]{64}$/D',$termKey)!==1){
            throw new InvalidArgumentException('Search analytics term key is invalid.');
        }
        if($displayTerm===''||strlen($displayTerm)>96||$resultCount<0||$resultCount>1000000){
            throw new InvalidArgumentException('Search analytics aggregate is invalid.');
        }
        $at=$at->setTimezone(new DateTimeZone('UTC'));
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_search_term_analytics '
            . '(term_key,event_day_utc,display_term,search_count,zero_result_count,result_count_total,last_searched_at_utc) '
            . 'VALUES (:term_key,:event_day,:display_term,1,:zero_results,:result_total,:searched_at) '
            . 'ON DUPLICATE KEY UPDATE display_term=VALUES(display_term),'
            . 'search_count=search_count+1,zero_result_count=zero_result_count+VALUES(zero_result_count),'
            . 'result_count_total=result_count_total+VALUES(result_count_total),'
            . 'last_searched_at_utc=GREATEST(last_searched_at_utc,VALUES(last_searched_at_utc))',
            [
                'term_key'=>$termKey,
                'event_day'=>$at->format('Y-m-d'),
                'display_term'=>$displayTerm,
                'zero_results'=>$resultCount===0?1:0,
                'result_total'=>$resultCount,
                'searched_at'=>$at->format('Y-m-d H:i:s.u'),
            ]
        ));
    }
}
