<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The weekly summary is cached per user per week; any expense change clears it.
 */
class SummaryCache
{
    public static function key(User $user): string
    {
        return sprintf('summary:%d:%s', $user->id, Carbon::now('Asia/Jakarta')->startOfWeek()->toDateString());
    }

    public static function forget(User $user): void
    {
        Cache::forget(self::key($user));
    }
}
