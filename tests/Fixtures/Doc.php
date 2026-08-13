<?php

namespace Kstmostofa\Backfill\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A model with a non-integer primary key, to prove the cursor round-trips
 * ULID/UUID-style keys as well as auto-increment integers.
 */
class Doc extends Model
{
    protected $table = 'bf_docs';

    protected $primaryKey = 'uid';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}
