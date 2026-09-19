<?php

declare(strict_types=1);

namespace Forwext\Core\Giveaway;

use InvalidArgumentException;

final class GiveawayDrawAlgorithm
{
    /** @param list<GiveawayDrawCandidate> $entries */
    public function select(array $entries, string $seedHex): GiveawayDrawSelection
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $seedHex) !== 1) {
            throw new InvalidArgumentException('Giveaway draw seed is invalid.');
        }
        if ($entries === []) {
            throw new GiveawayDrawException('Giveaway draw requires at least one participant.');
        }

        usort($entries, static fn (GiveawayDrawCandidate $a, GiveawayDrawCandidate $b): int =>
            [$a->userId->value(), $a->entryId->value()] <=> [$b->userId->value(), $b->entryId->value()]
        );

        $lines = [];
        $users = [];
        $totalWeight = 0;
        foreach ($entries as $entry) {
            $userKey = $entry->userId->value();
            if (isset($users[$userKey])) {
                throw new GiveawayDrawException('Giveaway draw population contains duplicate users.');
            }
            $users[$userKey] = true;
            $totalWeight += $entry->weight;
            if ($totalWeight > PHP_INT_MAX) {
                throw new GiveawayDrawException('Giveaway draw weight exceeds the supported integer range.');
            }
            $lines[] = $entry->entryId->value() . ':' . $userKey . ':' . $entry->weight;
        }

        $populationHash = hash('sha256', "forwext-giveaway-population-v1\n" . implode("\n", $lines));
        $ticket = $this->ticket($seedHex, $populationHash, $totalWeight);

        $cursor = 0;
        foreach ($entries as $entry) {
            $cursor += $entry->weight;
            if ($ticket <= $cursor) {
                return new GiveawayDrawSelection(
                    $populationHash,
                    count($entries),
                    $totalWeight,
                    $ticket,
                    $entry,
                );
            }
        }

        throw new GiveawayDrawException('Giveaway draw ticket could not be mapped to a participant.');
    }

    /** @param list<GiveawayDrawCandidate> $entries */
    public function verifies(GiveawayDraw $draw, array $entries): bool
    {
        try {
            $selection = $this->select($entries, $draw->seedHex);
        } catch (GiveawayDrawException|InvalidArgumentException) {
            return false;
        }

        return hash_equals($selection->populationHash, $draw->populationHash)
            && $selection->participantCount === $draw->participantCount
            && $selection->totalWeight === $draw->totalWeight
            && $selection->selectedTicket === $draw->selectedTicket
            && $selection->winner->userId->equals($draw->winnerUserId)
            && $selection->winner->entryId->equals($draw->winnerEntryId)
            && $selection->winner->weight === $draw->winnerEntryWeight;
    }

    private function ticket(string $seedHex, string $populationHash, int $totalWeight): int
    {
        if ($totalWeight < 1) {
            throw new GiveawayDrawException('Giveaway draw total weight must be positive.');
        }

        $remainder = ((PHP_INT_MAX % $totalWeight) + 1) % $totalWeight;
        $maximumAccepted = PHP_INT_MAX - $remainder;

        for ($counter = 0; $counter < 1_000_000; ++$counter) {
            $digest = hash(
                'sha256',
                'forwext-giveaway-ticket-v1|' . $seedHex . '|' . $populationHash . '|' . $counter,
                true,
            );
            $parts = unpack('Nhigh/Nlow', substr($digest, 0, 8));
            if (!is_array($parts)) {
                throw new GiveawayDrawException('Giveaway draw digest could not be decoded.');
            }

            $high = ((int) $parts['high']) & 0x7fffffff;
            $low = (int) $parts['low'];
            $value = ($high * 4_294_967_296) + $low;
            if ($value > $maximumAccepted) {
                continue;
            }

            return ($value % $totalWeight) + 1;
        }

        throw new GiveawayDrawException('Giveaway draw rejection sampling exceeded its safety limit.');
    }
}
