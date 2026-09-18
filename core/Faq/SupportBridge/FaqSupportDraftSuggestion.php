<?php
declare(strict_types=1);
namespace Forwext\Core\Faq\SupportBridge;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;
final readonly class FaqSupportDraftSuggestion {
    public DateTimeImmutable $createdAt;
    public function __construct(
        public EntityId $draftId,
        public EntityId $ticketId,
        public EntityId $sourceMessageId,
        public ?EntityId $suggestedByUserId,
        public ?string $suggestedCategoryKey,
        public string $question,
        public string $answer,
        public FaqSupportDraftStatus $status,
        DateTimeImmutable $createdAt,
    ){
        if($this->suggestedByUserId!==null) UserId::assert($this->suggestedByUserId);
        if($this->suggestedCategoryKey!==null && preg_match('/^[a-z][a-z0-9._-]{1,63}$/D',$this->suggestedCategoryKey)!==1) throw new InvalidArgumentException('FAQ support draft category key is invalid.');
        if(trim($this->question)===''||strlen($this->question)>300) throw new InvalidArgumentException('FAQ support draft question is invalid.');
        if(trim($this->answer)===''||strlen($this->answer)>50000) throw new InvalidArgumentException('FAQ support draft answer is invalid.');
        $this->createdAt=$createdAt->setTimezone(new DateTimeZone('UTC'));
    }
    public static function generateId(): EntityId { return EntityId::fromString(bin2hex(random_bytes(16))); }
}
