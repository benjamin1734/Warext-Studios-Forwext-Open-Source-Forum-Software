<?php

declare(strict_types=1);

namespace Forwext\Core\Giveaway;

use Forwext\Core\Notification\NotificationDefinition;
use Forwext\Core\Notification\NotificationDispatcher;
use Forwext\Core\Notification\NotificationRegistry;
use Forwext\Core\Notification\NotificationRequest;

final readonly class GiveawayNotifier
{
    public const WINNER = 'giveaway.winner';
    public const WINNER_REPLACED = 'giveaway.winner_replaced';

    public function __construct(private NotificationDispatcher $dispatcher)
    {
    }

    public static function registerDefinitions(NotificationRegistry $registry): void
    {
        $registry->register(new NotificationDefinition(
            self::WINNER,
            'giveaway',
            'Çekilişi kazandınız',
            '{{giveaway}} çekilişini kazandınız. Ödül: {{prize}}.',
        ));
        $registry->register(new NotificationDefinition(
            self::WINNER_REPLACED,
            'giveaway',
            'Çekiliş sonucu yeniden çekildi',
            '{{giveaway}} çekilişindeki önceki kazanan sonucu yeniden çekildi. Kanıt sayfasından zinciri inceleyebilirsiniz.',
        ));
    }

    public function winner(GiveawayDraw $draw, Giveaway $giveaway): void
    {
        $this->dispatcher->dispatch(new NotificationRequest(
            $draw->winnerUserId,
            self::WINNER,
            [
                'giveaway'=>$giveaway->title,
                'prize'=>$giveaway->prize->title,
            ],
            null,
            'giveaway-winner:' . $draw->drawId->value(),
            '/giveaways/' . $giveaway->giveawayId->value() . '/proof',
            [
                'giveaway_id'=>$giveaway->giveawayId->value(),
                'draw_id'=>$draw->drawId->value(),
                'draw_sequence'=>$draw->sequence,
            ],
        ));
    }

    public function replaced(GiveawayDraw $previous, Giveaway $giveaway): void
    {
        $this->dispatcher->dispatch(new NotificationRequest(
            $previous->winnerUserId,
            self::WINNER_REPLACED,
            ['giveaway'=>$giveaway->title],
            null,
            'giveaway-winner-replaced:' . $previous->drawId->value(),
            '/giveaways/' . $giveaway->giveawayId->value() . '/proof',
            [
                'giveaway_id'=>$giveaway->giveawayId->value(),
                'draw_id'=>$previous->drawId->value(),
            ],
        ));
    }
}
