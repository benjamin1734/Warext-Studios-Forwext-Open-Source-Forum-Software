<?php

declare(strict_types=1);

namespace Forwext\App\Web\Forum;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Attachment\AttachmentId;
use Forwext\Core\Http\Request;
use InvalidArgumentException;

final class StagedAttachmentInput
{
    private const MAX_ATTACHMENTS_PER_SUBMISSION = 20;

    /** @return list<EntityId> */
    public static function fromRequest(Request $request): array
    {
        $raw = $request->parsedBody()['attachment_ids'] ?? [];
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_string($raw)) {
            $raw = [$raw];
        }
        if (!is_array($raw) || count($raw) > self::MAX_ATTACHMENTS_PER_SUBMISSION) {
            throw new InvalidArgumentException('Attachment selection is invalid.');
        }

        $ids = [];
        $seen = [];
        foreach ($raw as $value) {
            if (!is_string($value) || preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
                throw new InvalidArgumentException('Attachment id is invalid.');
            }
            $id = AttachmentId::fromStored($value);
            if (isset($seen[$id->value()])) {
                continue;
            }
            $seen[$id->value()] = true;
            $ids[] = $id;
        }

        return $ids;
    }
}
