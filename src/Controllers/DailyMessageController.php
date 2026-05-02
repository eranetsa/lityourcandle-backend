<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;

final class DailyMessageController
{
    public function today(Request $req): void
    {
        // Pinned date-specific message wins; otherwise rotate through active pool
        $row = DB::one(
            'SELECT id, text_ar, text_en FROM daily_messages
             WHERE is_active = 1 AND show_on = CURDATE()
             ORDER BY id DESC LIMIT 1'
        );
        if (!$row) {
            $count = (int)(DB::one('SELECT COUNT(*) AS n FROM daily_messages WHERE is_active = 1')['n'] ?? 0);
            if ($count === 0) {
                Response::json([
                    'date'    => date('Y-m-d'),
                    'text_ar' => 'أشعل شمعتك اليوم… وابدأ من جديد',
                    'text_en' => 'Light your candle today... and start anew',
                ]);
            }
            $offset = (int)date('z') % $count;
            $row = DB::one(
                'SELECT id, text_ar, text_en FROM daily_messages
                 WHERE is_active = 1 ORDER BY id ASC LIMIT 1 OFFSET ' . $offset
            );
        }
        Response::json([
            'date'    => date('Y-m-d'),
            'text_ar' => $row['text_ar'] ?? '',
            'text_en' => $row['text_en'] ?? null,
        ]);
    }
}
