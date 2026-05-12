<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Subscription;
use App\Services\AppleReceiptService;
use App\Services\GoogleReceiptService;

final class SubscriptionController
{
    public function plans(Request $req): void
    {
        Response::json(['plans' => App::config('plans')]);
    }

    public function mine(Request $req): void
    {
        $sub = Subscription::current($req->userId());
        Response::json(['subscription' => $sub]);
    }

    public function startTrial(Request $req): void
    {
        // Free trials were retired — subscriptions now bill immediately on
        // purchase. The endpoint stays mounted so old clients don't 404,
        // but rejects every call. Existing trial rows expire naturally via
        // cron/update_subscriptions.php and stop being renewed by anyone.
        Response::error('trial_not_offered', 410, [
            'message_ar' => 'لم تعد التجربة المجانية متاحة. الرجاء الاشتراك مباشرة.',
        ]);
    }

    public function validateApple(Request $req): void
    {
        $data = Validator::check($req->body, [
            'receipt'     => 'required|string',
            'product_id'  => 'required|string|max:120',
        ]);
        $plan = $this->planFromProduct($data['product_id']);
        if (!$plan) Response::error('unknown_product', 422);

        $r = (new AppleReceiptService())->validate($data['receipt']);
        if (empty($r['valid'])) {
            Response::error('receipt_invalid', 402, ['detail' => $r]);
        }
        $subId = Subscription::applyStoreResult($req->userId(), 'apple', $r, $plan);
        Response::json(['ok' => true, 'subscription_id' => $subId]);
    }

    public function validateGoogle(Request $req): void
    {
        $data = Validator::check($req->body, [
            'product_id'     => 'required|string|max:120',
            'purchase_token' => 'required|string',
        ]);
        $plan = $this->planFromProduct($data['product_id']);
        if (!$plan) Response::error('unknown_product', 422);

        $r = (new GoogleReceiptService())->validate($data['product_id'], $data['purchase_token']);
        if (empty($r['valid'])) {
            Response::error('receipt_invalid', 402, ['detail' => $r]);
        }
        $subId = Subscription::applyStoreResult($req->userId(), 'google', $r, $plan);
        Response::json(['ok' => true, 'subscription_id' => $subId]);
    }

    public function buyExtraSession(Request $req): void
    {
        $data = Validator::check($req->body, [
            'store'           => 'required|in:apple,google',
            'receipt'         => 'string',          // apple
            'product_id'      => 'string',
            'purchase_token'  => 'string',          // google
        ]);

        $sub = Subscription::current($req->userId());
        $price = Subscription::isPaidPlan($sub)
            ? (float)App::config('plans.extra_session.subscriber')
            : (float)App::config('plans.extra_session.non_subscriber');

        if ($data['store'] === 'apple') {
            if (empty($data['receipt'])) Response::error('receipt_required', 422);
            $r = (new AppleReceiptService())->validate($data['receipt']);
        } else {
            if (empty($data['product_id']) || empty($data['purchase_token'])) {
                Response::error('product_or_token_required', 422);
            }
            // Google one-off purchases use a different endpoint, but we keep the same JWT flow
            $r = (new GoogleReceiptService())->validate($data['product_id'], $data['purchase_token']);
        }
        if (empty($r['valid'])) Response::error('receipt_invalid', 402);

        $txId = DB::insert('transactions', [
            'user_id'       => $req->userId(),
            'kind'          => 'extra_session',
            'amount'        => $price,
            'currency'      => 'SAR',
            'store'         => $data['store'],
            'store_tx_id'   => $r['original_tx'] ?? null,
            'status'        => 'succeeded',
            'metadata_json' => json_encode($r['raw'] ?? []),
        ]);

        if ($sub) {
            DB::run(
                'UPDATE subscriptions SET sessions_remaining = sessions_remaining + 1 WHERE id = :id',
                [':id' => $sub['id']]
            );
        }

        Response::json(['ok' => true, 'transaction_id' => $txId, 'amount' => $price]);
    }

    private function planFromProduct(string $productId): ?string
    {
        $id = strtolower($productId);
        if (str_contains($id, 'weekly'))  return 'weekly';
        if (str_contains($id, 'monthly')) return 'monthly';
        if (str_contains($id, 'yearly') || str_contains($id, 'annual')) return 'yearly';
        return null;
    }
}
