<?php

declare(strict_types=1);

namespace RequestTracer\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/** Lives on a second database connection ("other"), to prove writes are attributed to the connection that received them. */
class Note extends Model
{
    protected $connection = 'other';

    protected $table = 'notes';

    protected $guarded = [];
}
