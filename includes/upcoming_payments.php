<?php

const UPCOMING_PAYMENTS_LIMITS = [3, 5, 10, 20];

/**
 * Parse a dashboard limit and return null for unsupported values.
 *
 * @param mixed $limit
 * @return int|null
 */
function parse_upcoming_payments_limit($limit)
{
    if (is_string($limit) && ctype_digit($limit)) {
        $limit = (int) $limit;
    }

    return is_int($limit) && in_array($limit, UPCOMING_PAYMENTS_LIMITS, true) ? $limit : null;
}

/**
 * Return a supported dashboard limit, falling back to the current default.
 *
 * @param mixed $limit
 * @return int
 */
function normalize_upcoming_payments_limit($limit)
{
    return parse_upcoming_payments_limit($limit) ?? 3;
}

/**
 * Fetch the upcoming subscriptions shown on the dashboard.
 *
 * @param SQLite3 $db
 * @param int      $userId
 * @param mixed    $limit
 * @param string   $type   'expense' or 'income'
 * @return array
 */
require_once __DIR__ . '/paid_status.php';

function get_upcoming_payments($db, $userId, $limit, $type = 'expense')
{
    $limit = normalize_upcoming_payments_limit($limit);

    $stmt = $db->prepare("SELECT id, logo, logo_text_color, logo_variant, name, price, currency_id, next_payment, inactive, type, cycle, frequency, paid_at
        FROM subscriptions
        WHERE user_id = :userId
          AND next_payment >= date('now')
          AND inactive = 0
          AND cycle != 5
          AND type = :type
        ORDER BY next_payment ASC, price DESC");
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':type', $type, SQLITE3_TEXT);
    $result = $stmt->execute();

    $subscriptions = [];
    while (count($subscriptions) < $limit && $result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        if (!isPaidThisCycle($row['paid_at'] ?? null, $row['cycle'], $row['frequency'], $row['next_payment'])) {
            $subscriptions[] = $row;
        }
    }

    return $subscriptions;
}
