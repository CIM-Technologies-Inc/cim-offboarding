<?php

// Exits 0 when some user already holds the admin role, 1 otherwise. The
// entrypoint uses this to seed the default admin only on a fresh database,
// so restarts never reset an existing admin's password.

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$hasAdmin = DB::table('model_has_roles')
    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
    ->where('roles.name', User::ROLE_ADMIN)
    ->exists();

exit($hasAdmin ? 0 : 1);
