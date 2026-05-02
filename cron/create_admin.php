<?php
declare(strict_types=1);

/**
 * One-shot CLI to create or reset an admin user.
 *
 *   php cron/create_admin.php <username> <email> <password> "<full name>"
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\App;
use App\Core\Auth;
use App\Core\DB;

App::boot(dirname(__DIR__));

if ($argc < 5) {
    fwrite(STDERR, "Usage: php cron/create_admin.php <username> <email> <password> \"<full name>\"\n");
    exit(2);
}
[$username, $email, $password, $fullName] = [$argv[1], $argv[2], $argv[3], $argv[4]];

if (strlen($password) < 10) {
    fwrite(STDERR, "Password must be at least 10 characters.\n");
    exit(3);
}

$existing = DB::one('SELECT id FROM admin_users WHERE username = :u', [':u' => $username]);
$hash = Auth::hashPassword($password);
if ($existing) {
    DB::update('admin_users',
        ['password_hash' => $hash, 'email' => $email, 'full_name' => $fullName, 'is_active' => 1],
        'id = :id', [':id' => $existing['id']]
    );
    echo "Updated admin user {$username} (id={$existing['id']}).\n";
} else {
    $id = DB::insert('admin_users', [
        'username'      => $username,
        'email'         => $email,
        'password_hash' => $hash,
        'full_name'     => $fullName,
        'role'          => 'admin',
    ]);
    echo "Created admin user {$username} (id={$id}).\n";
}
