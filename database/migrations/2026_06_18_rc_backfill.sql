-- =============================================================================
-- 2026-06-18 — RevenueCat REST back-fill / reconciliation support
-- =============================================================================
-- Adds:
--   1) subscriptions.last_synced_at — timestamp of the last RC REST snapshot
--      reconciliation, so operators can spot stale rows after a back-fill.
--   2) subscriptions.rc_backfill_receipt — preserves a snapshot of the last
--      REST payload separately from store_latest_receipt (which is sacred:
--      it holds the real Apple/Play receipt the webhook delivered). Letting
--      back-fill overwrite store_latest_receipt would destroy forensic data.
--   3) transactions.store_alt_tx_id — secondary dedupe key for
--      NON_RENEWING_PURCHASE events. The webhook stores RC's event.id in
--      store_tx_id; the REST back-fill stores RC's non_subscription.id
--      there. The two are DIFFERENT identifiers for the same purchase, so
--      to remain idempotent across both paths we ALSO store the underlying
--      Apple/Google `store_transaction_id` in store_alt_tx_id and check
--      BOTH columns before crediting.
--   4) index on (store_alt_tx_id) so the back-fill's dedupe lookup is O(log n).
-- =============================================================================

ALTER TABLE subscriptions
    ADD COLUMN last_synced_at        DATETIME    DEFAULT NULL AFTER updated_at,
    ADD COLUMN rc_backfill_receipt   MEDIUMTEXT  DEFAULT NULL AFTER store_latest_receipt;

ALTER TABLE transactions
    ADD COLUMN store_alt_tx_id   VARCHAR(255) DEFAULT NULL AFTER store_tx_id,
    ADD KEY idx_tx_store_alt_tx (store_alt_tx_id);
