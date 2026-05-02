<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;

final class ConsultantController
{
    public function list(Request $req): void
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($req->query['specialty'])) {
            $where[] = 'specialty = :spec';
            $params[':spec'] = (string)$req->query['specialty'];
        }
        if (!empty($req->query['min_rating'])) {
            $where[] = 'rating >= :mr';
            $params[':mr'] = (float)$req->query['min_rating'];
        }
        if (isset($req->query['available'])) {
            $where[] = 'is_available = :av';
            $params[':av'] = filter_var($req->query['available'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        if (!empty($req->query['type'])) {
            $where[] = 'FIND_IN_SET(:t, session_types) > 0';
            $params[':t'] = (string)$req->query['type'];
        }

        $sort = match ($req->query['sort'] ?? '') {
            'rating'    => 'rating DESC, rating_count DESC',
            'price_asc' => 'price_per_session ASC',
            'price_desc'=> 'price_per_session DESC',
            default     => 'rating DESC',
        };

        $limit  = max(1, min(50, (int)($req->query['limit'] ?? 20)));
        $offset = max(0, (int)($req->query['offset'] ?? 0));

        $rows = DB::all(
            "SELECT id, name, photo_url, specialty, bio, rating, rating_count,
                    price_per_session, currency, session_types, is_available, languages
             FROM consultants
             WHERE " . implode(' AND ', $where) . "
             ORDER BY $sort
             LIMIT $limit OFFSET $offset",
            $params
        );
        Response::json(['consultants' => $rows]);
    }

    public function show(Request $req): void
    {
        $id = (int)$req->params['id'];
        $c = DB::one('SELECT * FROM consultants WHERE id = :id', [':id' => $id]);
        if (!$c) Response::error('not_found', 404);
        unset($c['user_id']);
        Response::json(['consultant' => $c]);
    }

    public function availability(Request $req): void
    {
        $id = (int)$req->params['id'];
        $rows = DB::all(
            'SELECT weekday, start_time, end_time
             FROM consultant_availability
             WHERE consultant_id = :id
             ORDER BY weekday, start_time',
            [':id' => $id]
        );
        Response::json(['availability' => $rows]);
    }
}
