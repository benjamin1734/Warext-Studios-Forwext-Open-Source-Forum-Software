<?php

declare(strict_types=1);

namespace Forwext\Core\Advertising;

use InvalidArgumentException;

final class AdvertisingPlacementRegistry
{
    /** @return array<string,string> */
    public static function all():array
    {
        return [
            'notice.top'=>'Site üst notice/announcement alanı',
            'page.top'=>'Sayfa üst reklam alanı',
            'content.before'=>'İçerik öncesi reklam alanı',
            'content.after'=>'İçerik sonrası reklam alanı',
            'forum.thread.top'=>'Konu üst reklam alanı',
            'forum.thread.bottom'=>'Konu alt reklam alanı',
            'page.bottom'=>'Sayfa alt reklam/notice alanı',
        ];
    }

    public static function assert(string $key):void
    {
        if (!array_key_exists($key,self::all())) {
            throw new InvalidArgumentException('Advertising placement key is not registered.');
        }
    }

    /** @return list<string> */
    public static function forRoute(string $routeName):array
    {
        $placements=['notice.top','page.top','content.before','content.after','page.bottom'];
        if (str_contains($routeName,'thread')) {
            $placements[]='forum.thread.top';
            $placements[]='forum.thread.bottom';
        }
        return $placements;
    }
}
