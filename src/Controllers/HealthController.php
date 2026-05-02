<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;

final class HealthController
{
    public function index(Request $req): void
    {
        try {
            DB::pdo()->query('SELECT 1');
            $db = 'ok';
        } catch (\Throwable $e) {
            $db = 'down';
        }
        Response::json(['status' => 'ok', 'db' => $db, 'time' => date('c')]);
    }
}
