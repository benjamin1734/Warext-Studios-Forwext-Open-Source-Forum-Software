<?php

declare(strict_types=1);

namespace Forwext\App\Web\Bug;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Bug\Conversation\BugReportConversationView;
use Forwext\Core\Bug\Conversation\BugReportMessage;
use Forwext\Core\Bug\Conversation\BugReportMessageRole;
use Forwext\Core\Bug\Intake\BugAttachmentRecord;
use Forwext\Core\Bug\Intake\BugReportIntake;
use Forwext\Core\Bug\Report\BugHistoryEventType;
use Forwext\Core\Bug\Report\BugReportHistoryEntry;
use Forwext\Core\Bug\Report\BugReportSeverity;
use Forwext\Core\Bug\Report\BugReportStatus;
use Forwext\Core\Routing\BasePath;

final class BugReportDetailHtml
{
    /**
     * @param list<BugAttachmentRecord> $attachments
     */
    public static function page(
        BugReportConversationView $view,
        ?BugReportIntake $intake,
        array $attachments,
        string $csrfToken,
        BasePath $basePath,
        BugReportDetailCapabilities $capabilities,
        string $categoryLabel,
        bool $updated=false,
        ?BugReportStaffContext $staffContext=null,
    ): string {
        $report=$view->report;
        $notice=$updated?'<div class="notice success">Hata bildirimi güncellendi.</div>':'';
        $links='<a href="'.self::e($basePath->prepend('/bugs')).'">Hata Bildirimlerim</a>';
        if($capabilities->canAccessStaffDashboard){
            $links.=' · <a href="'.self::e($basePath->prepend('/bugs/staff')).'">Hata yönetimi</a>';
        }

        $meta='<div class="profile-stats">'
            .self::stat('Durum',$report->status->label())
            .self::stat('Kategori',$categoryLabel)
            .self::stat('Önem',$report->severity->label())
            .self::stat('Tarih',$report->createdAt->format('Y-m-d H:i'))
            .'</div>';

        $details='<section class="card section"><h2>Bildirim ayrıntıları</h2>'
            .'<h3>Özet</h3><p>'.self::multiline($report->summary).'</p>';
        if($intake!==null){
            $details.='<h3>Tekrar üretme adımları</h3><p>'.self::multiline($intake->reproductionSteps).'</p>'
                .'<h3>Beklenen sonuç</h3><p>'.self::multiline($intake->expectedResult).'</p>'
                .'<h3>Gerçekleşen sonuç</h3><p>'.self::multiline($intake->actualResult).'</p>';
            if($intake->reportedSourcePath!==null){
                $details.='<p><strong>Kaynak sayfa:</strong> <code>'.self::e($intake->reportedSourcePath).'</code></p>';
            }
        }
        if($staffContext?->duplicateLink!==null){
            $canonical=$staffContext->duplicateLink->canonicalReportId->value();
            $details.='<div class="notice"><strong>Duplicate:</strong> Bu kayıt <a href="'
                .self::e($basePath->prepend('/bugs/'.rawurlencode($canonical))).'">#'
                .self::e($canonical).'</a> kaydına bağlıdır.</div>';
        }
        if($attachments!==[]){
            $details.='<div><strong>Ek dosyalar</strong><ul>';
            foreach($attachments as $attachment){
                $href=$basePath->prepend(
                    '/bugs/'.rawurlencode($report->reportId->value())
                    .'/attachments/'.rawurlencode($attachment->attachmentId->value()),
                );
                $details.='<li><a href="'.self::e($href).'">'.self::e($attachment->filename->value())
                    .'</a> <span class="muted">('.self::e($attachment->mediaType).', '
                    .self::e(self::bytes($attachment->sizeBytes)).')</span></li>';
            }
            $details.='</ul></div>';
        }
        $details.='</section>';

        $conversation='<section class="card section"><h2>Yanıtlar ve ek bilgiler</h2>';
        if($view->messages===[]){
            $conversation.='<p class="muted">Henüz ek bilgi veya yetkili yanıtı yok.</p>';
        }else{
            foreach($view->messages as $message){
                $conversation.=self::message($message);
            }
        }
        $conversation.='</section>';

        $reply='';
        if($capabilities->canReply){
            $reply='<section class="card section"><h2>'
                .($view->staffView?'Yetkili yanıtı':'Ek bilgi gönder')
                .'</h2><form method="post" action="'.self::action($report->reportId->value(),$basePath).'" class="presence-settings">'
                .self::csrf($csrfToken)
                .'<input type="hidden" name="action" value="reply">'
                .'<label><span>Mesaj</span><textarea name="body" maxlength="10000" rows="6" required></textarea></label>'
                .'<button type="submit">'.($view->staffView?'Yanıt gönder':'Ek bilgiyi gönder').'</button></form></section>';
        }

        $status='';
        if($capabilities->canManageStatus){
            if($report->status===BugReportStatus::Duplicate&&$staffContext?->duplicateLink!==null){
                $status='<section class="card section"><h2>Durum</h2>'
                    .'<p class="muted">Canonical duplicate bağı varken durum doğrudan değiştirilemez. '
                    .'Kaydı yeniden açmak için duplicate bağını kaldırın.</p></section>';
            }else{
                $status='<section class="card section"><h2>Durum</h2><form method="post" action="'
                    .self::action($report->reportId->value(),$basePath).'" class="presence-settings">'
                    .self::csrf($csrfToken).'<input type="hidden" name="action" value="status">'
                    .'<label><span>Yeni durum</span><select name="status">';
                foreach(BugReportStatus::cases() as $candidate){
                    if(!$report->status->canTransitionTo($candidate)
                        ||($candidate===BugReportStatus::Duplicate&&$report->status!==BugReportStatus::Duplicate)
                    ){
                        continue;
                    }
                    $status.='<option value="'.self::e($candidate->value).'"'
                        .($candidate===$report->status?' selected':'').'>'.self::e($candidate->label()).'</option>';
                }
                $status.='</select></label><button type="submit">Durumu güncelle</button></form></section>';
            }
        }

        $staffControls='';
        if($staffContext!==null){
            if($capabilities->canAssign){
                $staffControls.='<section class="card section"><h2>Atama</h2>'
                    .'<form method="post" action="'.self::action($report->reportId->value(),$basePath).'" class="presence-settings">'
                    .self::csrf($csrfToken).'<input type="hidden" name="action" value="assign">'
                    .'<label><span>Yetkili kullanıcı adı</span><input name="assignee_username" maxlength="80" value="'
                    .self::e($staffContext->assigneeUsername??'').'"></label>'
                    .'<p class="muted">Boş gönderirsen atama kaldırılır.</p>'
                    .'<button type="submit">Atamayı güncelle</button></form></section>';
            }

            if($capabilities->canManageWorkflow){
                $staffControls.='<section class="card section"><h2>Workflow</h2>'
                    .'<form method="post" action="'.self::action($report->reportId->value(),$basePath).'" class="presence-settings">'
                    .self::csrf($csrfToken).'<input type="hidden" name="action" value="severity">'
                    .'<label><span>Önem</span><select name="severity">';
                foreach(BugReportSeverity::cases() as $candidate){
                    $staffControls.='<option value="'.self::e($candidate->value).'"'
                        .($candidate===$report->severity?' selected':'').'>'.self::e($candidate->label()).'</option>';
                }
                $staffControls.='</select></label><button type="submit">Önemi güncelle</button></form>'
                    .'<form method="post" action="'.self::action($report->reportId->value(),$basePath).'" class="presence-settings">'
                    .self::csrf($csrfToken).'<input type="hidden" name="action" value="category">'
                    .'<label><span>Kategori</span><select name="category">';
                foreach($staffContext->categories as $category){
                    $staffControls.='<option value="'.self::e($category->key).'"'
                        .($category->key===$report->categoryKey?' selected':'').'>'.self::e($category->label).'</option>';
                }
                $staffControls.='</select></label><button type="submit">Kategoriyi güncelle</button></form></section>';
            }

            if($capabilities->canLinkDuplicate){
                $staffControls.='<section class="card section"><h2>Duplicate tespiti</h2>'
                    .'<p class="muted">Benzerlik skoru yalnız öneridir; duplicate kararı yetkilinin açık işlemiyle verilir.</p>';
                if($staffContext->duplicateLink!==null){
                    $canonical=$staffContext->duplicateLink->canonicalReportId->value();
                    $staffControls.='<p>Canonical kayıt: <a href="'
                        .self::e($basePath->prepend('/bugs/'.rawurlencode($canonical))).'">#'.self::e($canonical).'</a></p>'
                        .'<form method="post" action="'.self::action($report->reportId->value(),$basePath).'">'
                        .self::csrf($csrfToken).'<input type="hidden" name="action" value="duplicate_unlink">'
                        .'<button type="submit">Duplicate bağını kaldır ve yeniden aç</button></form>';
                }elseif($staffContext->duplicateSuggestions===[]){
                    $staffControls.='<div class="empty">Yeterli benzerlikte aday bulunamadı.</div>';
                }else{
                    if($report->status->isTerminal()){
                        $staffControls.='<div class="notice">Terminal bir kaydı duplicate işaretlemek için önce yeniden açın.</div>';
                    }
                    foreach($staffContext->duplicateSuggestions as $suggestion){
                        $candidate=$suggestion->report;
                        $candidateId=$candidate->reportId->value();
                        $staffControls.='<article class="search-hit"><div class="search-hit-type">Benzerlik %'
                            .self::e(number_format($suggestion->score*100,1))
                            .' · '.self::e($candidate->status->label())
                            .' · '.self::e($candidate->severity->label()).'</div>'
                            .'<h3><a href="'.self::e($basePath->prepend('/bugs/'.rawurlencode($candidateId))).'">'
                            .self::e($candidate->title).'</a></h3><p>'.self::e(self::excerpt($candidate->summary)).'</p>';
                        if(!$report->status->isTerminal()){
                            $staffControls.='<form method="post" action="'.self::action($report->reportId->value(),$basePath).'">'
                                .self::csrf($csrfToken).'<input type="hidden" name="action" value="duplicate">'
                                .'<input type="hidden" name="canonical_report_id" value="'.self::e($candidateId).'">'
                                .'<button type="submit">Bu kayda duplicate olarak bağla</button></form>';
                        }
                        $staffControls.='</article>';
                    }
                }
                $staffControls.='</section>';
            }
        }

        $history='<section class="card section"><h2>Durum geçmişi</h2>';
        if($view->history===[]){
            $history.='<p class="muted">Henüz geçmiş kaydı yok.</p>';
        }else{
            $history.='<ul>';
            foreach($view->history as $entry){
                $history.='<li>'.self::e($entry->createdAt->format('Y-m-d H:i')).' — '
                    .self::e(self::historyText($entry)).'</li>';
            }
            $history.='</ul>';
        }
        $history.='</section>';

        $body='<section class="card settings"><h1>'.self::e($report->title).'</h1>'
            .'<p>'.$links.'</p>'
            .'<p class="muted">Kayıt #'.self::e($report->reportId->value()).'</p>'
            .$notice.$meta.'</section>'.$details.$conversation.$reply.$status.$staffControls.$history;

        return ProfileHtml::page('Hata bildirimi',$body,$basePath,authenticated:true);
    }

    private static function message(BugReportMessage $message):string
    {
        $role=$message->authorRole===BugReportMessageRole::Staff?'Yetkili':'Kullanıcı';
        return '<article class="search-hit"><div class="search-hit-type">'.self::e($role)
            .' · '.self::e($message->createdAt->format('Y-m-d H:i')).'</div><p>'
            .self::multiline($message->body).'</p></article>';
    }

    private static function historyText(BugReportHistoryEntry $entry):string
    {
        return match($entry->eventType){
            BugHistoryEventType::Created=>'Hata bildirimi oluşturuldu.',
            BugHistoryEventType::StatusChanged=>'Durum: '.(string)($entry->payload['from']??'?')
                .' → '.(string)($entry->payload['to']??'?'),
            BugHistoryEventType::Assigned=>'Atama güncellendi.',
            BugHistoryEventType::SeverityChanged=>'Önem seviyesi: '.(string)($entry->payload['from']??'?')
                .' → '.(string)($entry->payload['to']??'?'),
            BugHistoryEventType::CategoryChanged=>'Kategori: '.(string)($entry->payload['from']??'?')
                .' → '.(string)($entry->payload['to']??'?'),
        };
    }

    private static function stat(string $label,string $value):string
    {
        return '<div><strong>'.self::e($label).'</strong><span>'.self::e($value).'</span></div>';
    }

    private static function csrf(string $token):string
    {
        return '<input type="hidden" name="_csrf" value="'.self::e($token).'">';
    }

    private static function action(string $reportId,BasePath $basePath):string
    {
        return self::e($basePath->prepend('/bugs/'.rawurlencode($reportId)));
    }

    private static function excerpt(string $value):string
    {
        $value=trim(preg_replace('/\s+/u',' ',$value)??$value);
        if(function_exists('mb_strlen')&&mb_strlen($value,'UTF-8')>260){
            return mb_substr($value,0,257,'UTF-8').'...';
        }
        return strlen($value)>260?substr($value,0,257).'...':$value;
    }

    private static function multiline(string $value):string{return nl2br(self::e($value),false);}
    private static function e(string $value):string{return ProfileHtml::escape($value);}

    private static function bytes(int $bytes):string
    {
        if($bytes>=1048576)return number_format($bytes/1048576,1).' MiB';
        if($bytes>=1024)return number_format($bytes/1024,1).' KiB';
        return $bytes.' B';
    }
}
