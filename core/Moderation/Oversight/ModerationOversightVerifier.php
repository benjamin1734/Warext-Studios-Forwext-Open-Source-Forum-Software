<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Oversight;

final readonly class ModerationOversightVerifier
{
    public function __construct(
        private ModerationOversightStore $store,
        private ModerationOversightHasher $hasher = new ModerationOversightHasher(),
    ) {
    }

    public function verify(): OversightVerification
    {
        $previousHash = str_repeat('0', 64);
        $sequence = 0;
        $checked = 0;

        while (true) {
            $page = $this->store->pageAfter($sequence, 500);
            if ($page === []) {
                break;
            }

            foreach ($page as $entry) {
                $expectedSequence = $sequence + 1;
                if ($entry->sequence !== $expectedSequence) {
                    return new OversightVerification(false, $checked, $sequence, $previousHash, 'sequence_gap');
                }
                if (!hash_equals($previousHash, $entry->previousHash)) {
                    return new OversightVerification(false, $checked, $sequence, $previousHash, 'previous_hash_mismatch');
                }
                $payloadHash = $this->hasher->payloadHash($entry->payloadJson);
                if (!hash_equals($payloadHash, $entry->payloadHash)) {
                    return new OversightVerification(false, $checked, $sequence, $previousHash, 'payload_hash_mismatch');
                }
                $chainHash = $this->hasher->chainHash($entry->sequence, $entry->previousHash, $entry->payloadHash);
                if (!hash_equals($chainHash, $entry->chainHash)) {
                    return new OversightVerification(false, $checked, $sequence, $previousHash, 'chain_hash_mismatch');
                }

                $sequence = $entry->sequence;
                $previousHash = $entry->chainHash;
                $checked++;
            }
        }

        $state = $this->store->chainState();
        if ($state->lastSequence !== $sequence || !hash_equals($state->lastHash, $previousHash)) {
            return new OversightVerification(false, $checked, $sequence, $previousHash, 'chain_state_mismatch');
        }

        return new OversightVerification(true, $checked, $sequence, $previousHash);
    }
}
