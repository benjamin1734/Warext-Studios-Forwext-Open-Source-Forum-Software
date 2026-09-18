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
    ): string {
        $report=$view->report;
        $notice=$updated?'<div class="notice success">Hata bildirimi güncellendi.</div>':'';
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
            $status='<section class="card section"><h2>Durum</h2><form method="post" action="'
                .self::action($report->reportId->value(),$basePath).'" class="presence-settings">'
                .self::csrf($csrfToken).'<input type="hidden" name="action" value="status">'
                .'<label><span>Yeni durum</span><select name="status">';
            foreach(BugReportStatus::cases() as $candidate){
                $status.='<option value="'.self::e($candidate->value).'"'
                    .($candidate===$report->status?' selected':'').'>'.self::e($candidate->label()).'</option>';
            }
            $status.='</select></label><button type="submit">Durumu güncelle</button></form></section>';
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
            .'<p><a href="'.self::e($basePath->prepend('/bugs')).'">Hata Bildirimlerim</a></p>'
            .'<p class="muted">Kayıt #'.self::e($report->reportId->value()).'</p>'
            .$notice.$meta.'</section>'.$details.$conversation.$reply.$status.$history;

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

    private static function multiline(string $value):string{return nl2br(self::e($value),false);}
    private static function e(string $value):string{return ProfileHtml::escape($value);}

    private static function bytes(int $bytes):string
    {
        if($bytes>=1048576)return number_format($bytes/1048576,1).' MiB';
        if($bytes>=1024)return number_format($bytes/1024,1).' KiB';
        return $bytes.' B';
    }
}
