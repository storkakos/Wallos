<?php

// A recurring item can be income (salary, rent received) as well as an expense.
// Everything already stored is an expense, which is what the default gives it.

$columnQuery = $db->query("SELECT * FROM pragma_table_info('subscriptions') WHERE name='type'");
if ($columnQuery->fetchArray(SQLITE3_ASSOC) === false) {
    $db->exec("ALTER TABLE subscriptions ADD COLUMN type TEXT NOT NULL DEFAULT 'expense'");
}

?>
