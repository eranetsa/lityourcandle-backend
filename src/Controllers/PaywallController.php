<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Subscription;

/**
 * Tells the client when to surface upgrade and exit prompts.
 */
final class PaywallController
{
    public function check(Request $req): void
    {
        $data = Validator::check($req->body, [
            'feature' => 'required|in:session,ai,program,consultant_recommend,content_limit,trial',
        ]);
        $uid = $req->userId();
        $sub = Subscription::current($uid);
        $feature = $data['feature'];

        $show     = false;
        $reason   = null;
        $message  = null;
        $cta      = 'upgrade';

        switch ($feature) {
            case 'session':
                if (!Subscription::isPaidPlan($sub)) {
                    $show = true; $reason = 'no_subscription';
                    $message = 'الجلسات متاحة لمشتركي الباقة الشهرية أو السنوية.';
                } elseif ((int)($sub['sessions_remaining'] ?? 0) < 1) {
                    $show = true; $reason = 'out_of_credits';
                    $message = 'استنفدت رصيد جلساتك لهذا الشهر، يمكنك شراء جلسة إضافية.';
                    $cta = 'extra_session';
                }
                break;
            case 'ai':
                if (!Subscription::isPaidPlan($sub) && ($sub['status'] ?? '') !== 'trial') {
                    $today = (int)(DB::one(
                        'SELECT COUNT(*) AS n FROM ai_logs WHERE user_id = :uid AND DATE(created_at) = CURDATE()',
                        [':uid' => $uid]
                    )['n'] ?? 0);
                    if ($today >= 3) {
                        $show = true; $reason = 'daily_ai_limit';
                        $message = 'استمر مع شمعة بلا حدود عبر الاشتراك.';
                    }
                }
                break;
            case 'program':
                if (!Subscription::isPaidPlan($sub) && ($sub['status'] ?? '') !== 'trial') {
                    $show = true; $reason = 'premium_content';
                    $message = 'هذا البرنامج متاح للمشتركين فقط.';
                }
                break;
            case 'consultant_recommend':
                if (!Subscription::isPaidPlan($sub)) {
                    $show = true; $reason = 'consultant_paywall';
                    $message = 'احجز جلستك الأولى مع مستشار من خلال الاشتراك.';
                }
                break;
            case 'content_limit':
                if (!Subscription::isPaidPlan($sub)) {
                    $show = true; $reason = 'free_content_limit';
                    $message = 'اشترك للوصول إلى المحتوى الكامل والبرامج اليومية.';
                }
                break;
            case 'trial':
                if ($sub && !empty($sub['trial_ends_at'])) {
                    $remain = strtotime($sub['trial_ends_at']) - time();
                    if ($remain > 0 && $remain < 86400) {
                        $show = true; $reason = 'trial_ending';
                        $message = 'تجربتك المجانية تنتهي قريبًا. اشترك لتستمر معنا.';
                    }
                }
                break;
        }

        Response::json([
            'show_upgrade' => $show,
            'reason'       => $reason,
            'message_ar'   => $message,
            'cta'          => $cta,
        ]);
    }

    public function exitPopup(Request $req): void
    {
        Response::json([
            'title_ar'   => 'أنت على وشك الخروج من إنارة شمعتك 🕯️',
            'message_ar' => 'لا تطفئها الآن… رحلتك بدأت للتو',
            'stay_label' => 'البقاء',
            'leave_label'=> 'الخروج',
        ]);
    }
}
