<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Subscription;

final class AdminController
{
    /**
     * POST /api/admin/gift-session
     * Body: { user_id: int, sessions?: int, note?: string }
     *
     * Adds free session credit to a specific user.
     * If the user has no subscription, creates a 'gift' row.
     */
    public function giftSession(Request $req): void
    {
        $data = Validator::check($req->body, [
            'user_id'  => 'required|integer',
            'sessions' => 'integer',
            'note'     => 'string|max:255',
        ]);

        $userId   = (int) $data['user_id'];
        $sessions = max(1, (int) ($data['sessions'] ?? 1));
        $note     = $data['note'] ?? null;

        $user = DB::one('SELECT id, name FROM users WHERE id = :id', [':id' => $userId]);
        if (!$user) {
            Response::error('user_not_found', 404);
        }

        DB::transaction(function () use ($userId, $sessions, $note, $req): void {
            $sub = Subscription::current($userId);

            if ($sub) {
                DB::run(
                    'UPDATE subscriptions SET sessions_remaining = sessions_remaining + :n WHERE id = :id',
                    [':n' => $sessions, ':id' => $sub['id']]
                );
                $subId = (int) $sub['id'];
            } else {
                $subId = DB::insert('subscriptions', [
                    'user_id'            => $userId,
                    'plan'               => 'gift',
                    'status'             => 'active',
                    'sessions_total'     => $sessions,
                    'sessions_remaining' => $sessions,
                    'store'              => 'none',
                    'created_at'         => date('Y-m-d H:i:s'),
                    'updated_at'         => date('Y-m-d H:i:s'),
                ]);
            }

            DB::insert('transactions', [
                'user_id'       => $userId,
                'kind'          => 'gift_session',
                'amount'        => 0,
                'currency'      => 'SAR',
                'store'         => 'none',
                'store_tx_id'   => null,
                'status'        => 'succeeded',
                'metadata_json' => json_encode([
                    'sessions'        => $sessions,
                    'gifted_by'       => $req->userId(),
                    'note'            => $note,
                    'subscription_id' => $subId,
                ]),
            ]);
        });

        $updated = Subscription::current($userId);

        Response::json([
            'ok'                 => true,
            'user_id'            => $userId,
            'sessions_added'     => $sessions,
            'sessions_remaining' => (int) ($updated['sessions_remaining'] ?? $sessions),
        ]);
    }
}
