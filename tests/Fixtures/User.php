<?php

namespace Kstmostofa\Backfill\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $table = 'bf_users';

    protected $guarded = [];

    protected $casts = [
        'process_count' => 'integer',
    ];

    /**
     * Seed $count users with no slug.
     */
    public static function seedUnslugged(int $count): void
    {
        foreach (range(1, $count) as $i) {
            static::create(['name' => "User {$i}"]);
        }
    }
}
