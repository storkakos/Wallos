<?php
/*
  Recurring items are income or expenses (subscriptions.type). Income feeds the
  balance on the statistics page and must stay out of every cost figure.
*/

require_once WALLOS_ROOT . '/includes/budget_period_calculations.php';
require_once WALLOS_ROOT . '/includes/currency_rates.php';
require_once WALLOS_ROOT . '/includes/upcoming_payments.php';

/**
 * Inserts a monthly item due tomorrow; omits type when it is null, so the
 * column default applies.
 *
 * @param SQLite3     $db
 * @param string      $name
 * @param float       $price
 * @param string|null $type
 * @return int
 */
function income_insert($db, $name, $price, $type = null)
{
    $typeColumn = $type === null ? '' : ', type';
    $typeValue = $type === null ? '' : ', :type';
    $stmt = $db->prepare("INSERT INTO subscriptions (user_id, name, price, currency_id, next_payment, cycle, frequency, inactive$typeColumn)
        VALUES (1, :name, :price, :currencyId, :nextPayment, 3, 1, 0$typeValue)");
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $stmt->bindValue(':price', $price, SQLITE3_FLOAT);
    $stmt->bindValue(':currencyId', wallos_test_currency_id(1, 0), SQLITE3_INTEGER);
    $stmt->bindValue(':nextPayment', (new DateTime('tomorrow'))->format('Y-m-d'), SQLITE3_TEXT);
    if ($type !== null) {
        $stmt->bindValue(':type', $type, SQLITE3_TEXT);
    }
    $stmt->execute();

    return $db->lastInsertRowID();
}

function income_source($path)
{
    return file_get_contents(WALLOS_ROOT . '/' . $path);
}

wallos_test('a new row without a type is an expense', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    $id = income_insert($db, 'Netflix', 12);

    assert_same('expense', $db->querySingle("SELECT type FROM subscriptions WHERE id = $id"), 'the column defaults to expense');
    $db->close();
});

wallos_test('the migration keeps existing rows as expenses and can run twice', function () {
    $db = new SQLite3(':memory:');
    $db->exec('CREATE TABLE subscriptions (id INTEGER PRIMARY KEY, name TEXT)');
    $db->exec("INSERT INTO subscriptions (name) VALUES ('Old entry')");

    require WALLOS_ROOT . '/migrations/000062.php';
    require WALLOS_ROOT . '/migrations/000062.php';

    assert_same('expense', $db->querySingle('SELECT type FROM subscriptions'), 'the old entry stays an expense');
    $db->close();
});

wallos_test('income is stored next to an expense and the cost filter only sees the expense', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    income_insert($db, 'Rent', 800, 'expense');
    income_insert($db, 'Salary', 3000, 'income');

    assert_same(2, $db->querySingle('SELECT COUNT(*) FROM subscriptions'), 'both rows are stored');
    assert_same('Rent', $db->querySingle("SELECT name FROM subscriptions WHERE type = 'expense'"), 'the filter keeps the expense');
    $db->close();
});

wallos_test('the amount needed in a budget period ignores income', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    income_insert($db, 'Rent', 800, 'expense');
    income_insert($db, 'Salary', 3000, 'income');

    $rows = [];
    $result = $db->query('SELECT * FROM subscriptions');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }

    $amount = computeAmountNeededInPeriod($rows, new DateTime('today'), new DateTime('+20 days'), $db, 1);

    assert_equals(800.0, $amount, 'only the expense is needed');
    $db->close();
});

wallos_test('the add endpoint and the API only accept income or expense', function () {
    assert_contains("\$type = (\$_POST['type'] ?? '') === 'income' ? 'income' : 'expense';", income_source('endpoints/subscription/add.php'), 'the form value is whitelisted');
    $api = income_source('api/subscriptions/set_subscriptions.php');
    assert_contains("\$type = (\$_POST['type'] ?? '') === 'income' ? 'income' : 'expense';", $api, 'the API add is whitelisted');
    assert_contains("\$type = \$subscription['type'];", $api, 'an API edit without type keeps the stored one');
});

wallos_test('saving, cloning and loading a subscription all carry the type', function () {
    $add = income_source('endpoints/subscription/add.php');
    assert_contains('logo_variant, type', $add, 'INSERT writes the type');
    assert_contains('type = :type', $add, 'UPDATE writes the type');
    assert_contains("':type', \$subscriptionToClone['type']", income_source('endpoints/subscription/clone.php'), 'a clone keeps the type');
    assert_contains("\$subscriptionData['type'] = \$row['type'];", income_source('endpoints/subscription/get.php'), 'the edit form receives the type');
});

wallos_test('both places that build the list pass the type on, and the list marks income', function () {
    assert_contains("\$print[\$id]['type'] = \$subscription['type'];", income_source('subscriptions.php'), 'first page load');
    assert_contains("\$print[\$id]['type'] = \$subscription['type'];", income_source('endpoints/subscriptions/get.php'), 'list refresh after filtering or saving');

    $list = income_source('includes/list_subscriptions.php');
    assert_contains('$subscriptionExtraClasses .= " income";', $list, 'income gets its CSS class');
    assert_contains("(\$isIncome ? '+' : '')", $list, 'income gets a plus sign');
    assert_contains('.subscription.income .price .value', income_source('styles/styles.css'), 'income is styled');
});

wallos_test('the form offers the type and the script keeps it in step', function () {
    assert_contains('<select id="type" name="type">', income_source('subscriptions.php'), 'the selector exists');
    $script = income_source('scripts/subscriptions.js');
    assert_contains('typeSelect.value = "expense";', $script, 'resetForm() goes back to expense');
    assert_contains('typeSelect.value = subscription.type;', $script, 'fillEditFormFields() loads the stored type');
});

wallos_test('the statistics keep income out of costs and work out the balance', function () {
    $stats = income_source('includes/stats_calculations.php');
    assert_contains('auto_renew, type FROM subscriptions', $stats, 'the query selects the type');
    assert_contains('$totalIncomePerMonth += $price;', $stats, 'income is summed separately');
    assert_contains('$balancePerMonth = $totalIncomePerMonth - $totalCostPerMonth;', $stats, 'monthly balance');
    assert_contains('$balancePerYear = $balancePerMonth * 12;', $stats, 'yearly balance');
    assert_true(
        strpos($stats, "\$subscription['type'] === 'income'") < strpos($stats, '$activeSubscriptions++'),
        'income leaves the loop before the expense-only totals'
    );

    assert_contains("\$subscription['type'] === 'income'", income_source('includes/stats_extra_calculations.php'), 'the extra statistics skip income');
    assert_contains("translate('income_and_balance', \$i18n)", income_source('stats.php'), 'the section is rendered');
    assert_contains('if ($totalIncomePerMonth > 0) {', income_source('stats.php'), 'and only when there is income');
});

wallos_test('every other cost output excludes income', function () {
    foreach ([
        'api/subscriptions/get_monthly_cost.php',
        'api/subscriptions/get_period_budget.php',
        'endpoints/cronjobs/storetotalyearlycost.php',
        'endpoints/cronjobs/sendnotifications.php',
        'endpoints/ai/generate_recommendations.php',
        'endpoints/cronjobs/generaterecommendations.php',
    ] as $path) {
        assert_contains("AND inactive = 0 AND type = 'expense'", income_source($path), $path);
    }

    assert_contains("\$subscription['type'] === 'income'", income_source('calendar.php'), 'calendar totals');
    assert_contains("(\$subscription['type'] ?? 'expense') === 'income'", income_source('includes/upcoming_cancellations.php'), 'potential savings');
});

wallos_test('the dashboard shows income and balance only when there is income', function () {
    $dashboard = income_source('index.php');
    assert_contains('if ($totalIncomePerMonth > 0) {', $dashboard, 'the block depends on income');
    assert_contains("translate('monthly_balance', \$i18n)", $dashboard, 'monthly balance');
    assert_contains("translate('yearly_balance', \$i18n)", $dashboard, 'yearly balance');
    assert_contains("\$balancePerMonth < 0 ? 'negative' : 'income'", $dashboard, 'a negative balance is marked');
});

wallos_test('every dashboard list and the calendar mark income', function () {
    $dashboard = income_source('index.php');
    assert_contains('auto_renew, type FROM subscriptions', $dashboard, 'the overdue query selects the type');
    assert_same(3, substr_count($dashboard, "(\$isIncome ? '+' : '') . formatPrice"), 'overdue, upcoming and cancellation cards show a plus');
    assert_same(3, substr_count($dashboard, "subscription-item-price<?= \$isIncome ? ' income' : '' ?>"), 'and are styled');
    assert_contains('next_payment, inactive, type', income_source('includes/upcoming_payments.php'), 'the upcoming query selects the type');

    $calendar = income_source('calendar.php');
    assert_contains("calendar-event<?= \$isIncome ? ' income' : '' ?>", $calendar, 'calendar entries get the class');
    assert_contains("(\$isIncome ? '+' : '') . formatCalendarPrice", $calendar, 'calendar entries show a plus');
});

wallos_test('upcoming payments carry the type of each row', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    income_insert($db, 'Salary', 3000, 'income');

    $rows = get_upcoming_payments($db, 1, 3);

    assert_same('income', $rows[0]['type'], 'the dashboard can tell income apart');
    $db->close();
});

wallos_test('the statistics project the balance and colour it', function () {
    $extra = income_source('includes/stats_extra_calculations.php');
    assert_contains("\$addToProjection(\$subscription, \$cycle, \$frequency, \$convertedPrice, 'income');", $extra, 'income feeds the projection');
    assert_contains("\$addToProjection(\$subscription, \$cycle, \$frequency, \$convertedPrice, 'total');", $extra, 'costs still do');
    assert_contains("round(\$bucket['income'] - \$bucket['total'], 2)", $extra, 'the balance is income minus cost');

    $stats = income_source('stats.php');
    assert_contains('loadBarGraph("projectedBalanceChart"', $stats, 'the chart is drawn');
    assert_contains("\$balancePerMonth < 0 ? 'negative' : 'income'", $stats, 'a negative balance is marked');
    assert_contains('statistics two-columns', $stats, 'the tiles stay together');
});

wallos_test('the income strings exist in the source language', function () {
    $i18n = [];
    include WALLOS_ROOT . '/includes/i18n/en.php';

    foreach (['entry_type', 'expense', 'income', 'income_and_balance', 'monthly_income', 'yearly_income', 'monthly_balance', 'yearly_balance'] as $key) {
        assert_true(isset($i18n[$key]), "en.php has $key");
    }
});
