<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Tests\Fixtures\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = ['is_vip' => 'boolean'];
}
