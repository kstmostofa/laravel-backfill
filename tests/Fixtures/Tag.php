<?php

namespace Kstmostofa\Backfill\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    protected $table = 'bf_tags';

    public $timestamps = false;

    protected $guarded = [];

    public static function seed(int $count): void
    {
        foreach (range(1, $count) as $i) {
            static::create(['name' => "Tag {$i}"]);
        }
    }
}
