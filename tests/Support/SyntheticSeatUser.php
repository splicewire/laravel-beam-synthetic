<?php

namespace Splicewire\Beam\Synthetic\Tests\Support;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User;

/** The central user model of the test host: uuid-keyed, as Beam hosts' users are. */
class SyntheticSeatUser extends User
{
    use HasUuids;

    protected $table = 'users';

    protected $connection = 'central';

    protected $fillable = ['name', 'email', 'password'];

    /** `Tenant::users()` names no pivot key, so it derives one from the class name; a host's model is `User`. */
    public function getForeignKey(): string
    {
        return 'user_id';
    }
}
