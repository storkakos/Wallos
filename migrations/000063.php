<?php

// Adds a paid_at column to track when a subscription was last marked as paid.
// When paid_at falls within the current billing cycle, the subscription is
// considered paid for that cycle. It resets naturally when next_payment moves on.

$columnQuery = $db->query("SELECT * FROM pragma_table_info('subscriptions') WHERE name='paid_at'");
if ($columnQuery->fetchArray(SQLITE3_ASSOC) === false) {
    $db->exec("ALTER TABLE subscriptions ADD COLUMN paid_at TEXT DEFAULT NULL");
}

?>
