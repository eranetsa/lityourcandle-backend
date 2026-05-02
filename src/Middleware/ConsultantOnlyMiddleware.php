<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;

final class ConsultantOnlyMiddleware implements Middleware
{
    public function handle(Request $req): void
    {
        if (!in_array($req->userRole(), ['consultant', 'admin'], true)) {
            Response::error('forbidden', 403);
        }
    }
}
