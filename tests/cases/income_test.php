<?php
/*
  Recurring items are income or expenses (subscriptions.type). Income feeds the
  balance on the statistics page and must stay out of every cost figure.
*/

require_once WALLOS_ROOT . '/includes/budget_period_calculations.php';
require_once WALLOS_ROOT . '/includes/currency_rates.php';
require_once WALLOS_ROOT . '/includes/upcoming_payments.php';
require_once WALLOS_ROOT . '/includes/upcoming_cancellations.php';
require_once __DIR__ . '/price_sort_test.php';

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
    assert_contains("(\$isIncome ? '+' : '-')", $list, 'income gets a plus sign and expenses a minus');
    assert_contains('.subscription.income .price .value', income_source('styles/styles.css'), 'income is styled');
});

wallos_test('the form offers the type and the script keeps it in step', function () {
    assert_contains('<select id="type" name="type"', income_source('subscriptions.php'), 'the selector exists');
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
    assert_contains("translate('income_and_expenses', \$i18n)", income_source('stats.php'), 'the section is rendered');
    assert_contains('if ($totalIncomePerMonth > 0) {', income_source('stats.php'), 'and only when there is income');
});

wallos_test('every other cost output excludes income', function () {
    foreach ([
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
    foreach (['monthly_income', 'monthly_expenses', 'monthly_balance', 'yearly_income', 'yearly_expenses', 'yearly_balance'] as $tile) {
        assert_contains("'$tile'", $dashboard, $tile);
    }
    assert_contains("\$balancePerMonth < 0 ? 'negative' : 'income'", $dashboard, 'a negative balance is marked');
});

wallos_test('every dashboard list and the calendar mark income', function () {
    $dashboard = income_source('index.php');
    assert_contains('auto_renew, type FROM subscriptions', $dashboard, 'the overdue query selects the type');
    assert_same(3, substr_count($dashboard, "(\$isIncome ? '+' : '-') . formatPrice"), 'overdue, upcoming and cancellation cards show a sign');
    assert_same(3, substr_count($dashboard, "subscription-item-price <?= \$isIncome ? 'income' : 'expense' ?>"), 'and are coloured');
    assert_contains('next_payment, inactive, type', income_source('includes/upcoming_payments.php'), 'the upcoming query selects the type');

    $calendar = income_source('calendar.php');
    assert_contains("calendar-event <?= \$isIncome ? 'income' : 'expense' ?>", $calendar, 'calendar entries get the class');
    assert_contains('$totalIncomeThisMonth += $convertedPrice;', $calendar, 'the month sums its income');
    assert_contains("(\$isIncome ? '+' : '-') . formatCalendarPrice", $calendar, 'calendar entries show a sign');
});

wallos_test('upcoming payments carry the type of each row', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    income_insert($db, 'Salary', 3000, 'income');

    income_insert($db, 'Rent', 800, 'expense');

    $payments = get_upcoming_payments($db, 1, 3);
    $income = get_upcoming_payments($db, 1, 3, 'income');

    assert_same(['Rent'], array_column($payments, 'name'), 'upcoming payments are expenses only');
    assert_same(['Salary'], array_column($income, 'name'), 'upcoming income is its own list');

    income_insert($db, 'Small side job', 50, 'income');
    $income = get_upcoming_payments($db, 1, 3, 'income');
    assert_same('Salary', $income[0]['name'], 'on the same day the larger amount comes first');
    $db->close();
});

wallos_test('the dashboard only shows the budget block when a budget is set', function () {
    $dashboard = income_source('index.php');
    assert_contains('if (isset($monthlyBudget) && $monthlyBudget > 0) {', $dashboard, 'no budget, no block');
    assert_contains("translate('upcoming_income', \$i18n)", $dashboard, 'upcoming income has its own section');
});

wallos_test('the statistics project the balance and colour it', function () {
    $extra = income_source('includes/stats_extra_calculations.php');
    assert_contains("\$addToProjection(\$subscription, \$cycle, \$frequency, \$convertedPrice, 'income');", $extra, 'income feeds the projection');
    assert_contains("\$addToProjection(\$subscription, \$cycle, \$frequency, \$convertedPrice, 'total');", $extra, 'costs still do');
    assert_contains("round(\$bucket['income'] - \$bucket['total'], 2)", $extra, 'the balance is income minus cost');

    $stats = income_source('stats.php');
    assert_contains('loadBarGraph("projectedBalanceChart"', $stats, 'the chart is drawn');
    assert_contains("\$balancePerMonth < 0 ? 'negative' : 'income'", $stats, 'a negative balance is marked');
    assert_contains('"projectedBalanceChart"', $stats, 'the balance chart is present');
    assert_contains('null, "balance");', $stats, 'and colours by sign');
    assert_contains("['yearly_expenses', 0 - \$totalCostPerYear, \$totalCostPerYear > 0 ? 'expense' : '']", $stats, 'expenses have their own tile, shown as negative');
});

wallos_test('the form rewords itself for income', function () {
    $form = income_source('subscriptions.php');
    assert_same(10, substr_count($form, 'data-income-label='), 'every expense-worded label has an income one');
    assert_same(2, substr_count($form, 'data-income-placeholder='), 'and so do the placeholders');
    assert_contains('onchange="applyEntryTypeLabels()"', $form, 'switching the type rewords the form');

    $script = str_replace("\r\n", "\n", income_source('scripts/subscriptions.js'));
    assert_contains("typeSelect.value = subscription.type;\n  applyEntryTypeLabels();", $script, 'the edit form rewords itself after loading the type');
});

wallos_test('the list can be filtered and sorted by type', function () {
    $endpoint = income_source('endpoints/subscriptions/get.php');
    assert_contains("in_array(\$_GET['type'], ['income', 'expense'], true)", $endpoint, 'the filter value is whitelisted');
    assert_contains('data-entrytype="income"', income_source('includes/filters_menu.php'), 'the filter is offered');
    assert_contains("'renewal_type', 'type'];", $endpoint, 'the refreshed list can sort by type');
    assert_contains("'renewal_type', 'type'];", income_source('subscriptions.php'), 'the first page load too');
});

wallos_test('a bulk action sets the type and only accepts income or expense', function () {
    $bulk = income_source('endpoints/subscriptions/bulkedit.php');
    assert_contains("'set_type' => \"type = :type\"", $bulk, 'the action exists');
    assert_contains("\$type = (\$data['value'] ?? '') === 'income' ? 'income' : 'expense';", $bulk, 'the value is whitelisted');
    assert_contains('value = document.querySelector("#bulk-type").value;', income_source('scripts/subscriptions.js'), 'the script sends the text value');
});

wallos_test('the details popup marks income', function () {
    $script = income_source('scripts/subscription-details.js');
    assert_contains("detailsAddChip(chips, strings.income, 'ok');", $script, 'income chip');
    assert_contains("(isIncome ? '+' : '-')", $script, 'sign');
});

wallos_test('notifications and calendar exports label income', function () {
    assert_contains("(\$isIncome ? 'Income: ' : '')", income_source('endpoints/cronjobs/sendnotifications.php'), 'payment reminders');
    assert_contains("(\$isIncome ? '+' : '') . formatPrice(", income_source('endpoints/cronjobs/sendnotifications.php'), 'payment reminder amounts');
    assert_contains("(\$rowSubscription['type'] === 'income' ? 'Income: ' : '')", income_source('endpoints/cronjobs/sendcancellationnotifications.php'), 'cancellation reminders');

    foreach (['api/subscriptions/get_ical_feed.php', 'endpoints/subscription/exportcalendar.php'] as $path) {
        assert_contains("(\$subscription['type'] === 'income' ? 'Income: ' : '')", income_source($path), $path);
    }
});

wallos_test('the projection counts payments due on the first of the month', function () {
    $start = new DateTime('first day of next month midnight');

    assert_same('00:00:00', $start->format('H:i:s'), 'the first bucket starts at midnight');
    assert_contains("new DateTime('first day of next month midnight')", income_source('includes/stats_extra_calculations.php'), 'the statistics use it');
});

wallos_test('income stays out of cancellations and replacements', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $incomeId = income_insert($db, 'Ending job', 2000, 'income');
    $expenseId = income_insert($db, 'Old gym', 30, 'expense');
    $date = (new DateTime('+10 days'))->format('Y-m-d');
    $db->exec("UPDATE subscriptions SET cancellation_date = '$date' WHERE id IN ($incomeId, $expenseId)");

    assert_same(['Old gym'], array_column(get_upcoming_cancellations($db, 1), 'name'), 'only the expense is a cancellation');
    assert_contains("\$sub['type'] !== 'income'", income_source('subscriptions.php'), 'income cannot replace an expense');
    $db->close();
});

wallos_test('sorting by price keeps income after the expenses', function () {
    $rows = [
        ['name' => 'Salary', 'type' => 'income', 'sort_price' => 3000],
        ['name' => 'Netflix', 'type' => 'expense', 'sort_price' => 12],
        ['name' => 'Rent', 'type' => 'expense', 'sort_price' => 800],
        ['name' => 'Side job', 'type' => 'income', 'sort_price' => 50],
    ];

    price_sort_load_helpers();
    sortSubscriptionsByPrice($rows, false);

    assert_same(['Rent', 'Netflix', 'Salary', 'Side job'], array_column($rows, 'name'), 'each type is ranked on its own');
});

wallos_test('income has its own overdue, one-time and action wording', function () {
    $dashboard = income_source('index.php');
    assert_contains("'income_not_received' => array_filter(", $dashboard, 'overdue income has its own heading');
    assert_contains('foreach ($overdueGroup as $subscription) {', $dashboard, 'each group lists its own entries');

    $list = income_source('includes/list_subscriptions.php');
    assert_contains("\$isIncome ? 'one_time_income' : 'lifetime_purchases'", $list, 'one-time income is not a purchase');
    assert_contains("\$isIncome ? 'mark_received' : 'renew'", $list, 'income is received, not renewed');
    assert_contains('dataset.confirmDeleteIncome', income_source('scripts/subscriptions.js'), 'the delete question says income');
});

wallos_test('the statistics filter by type, count income and estimate all-time income', function () {
    $stats = income_source('includes/stats_calculations.php');
    assert_contains("\$filteredType === 'income' || \$filteredType === 'expense'", $stats, 'the filter value is whitelisted before it reaches the query');
    assert_contains('$activeIncome++;', $stats, 'active income is counted');
    assert_contains('if (count($conditions) > 1) {', $stats, 'the account-wide cost history is hidden while filtering');
    assert_contains('data-entrytype="<?= $entryType ?>"', income_source('stats.php'), 'the filter is offered');
    assert_contains("hasAttribute('data-entrytype')", income_source('scripts/stats.js'), 'the script applies it');
    assert_contains('$totalLifetimeIncome += $monthlyPrice', income_source('includes/stats_extra_calculations.php'), 'all-time income is estimated');
});

wallos_test('every income string is translated into German', function () {
    $i18n = [];
    include WALLOS_ROOT . '/includes/i18n/en.php';
    $english = $i18n;
    $i18n = [];
    include WALLOS_ROOT . '/includes/i18n/de.php';

    $source = str_replace("\r\n", "\n", file_get_contents(WALLOS_ROOT . '/includes/i18n/en.php'));
    preg_match('/\/\/ Income and expenses\n(.*?)\n\];/s', $source, $block);
    preg_match_all('/"([a-z_]+)" =>/', $block[1], $keys);

    assert_true(count($keys[1]) > 30, 'the income block was found');
    foreach ($keys[1] as $key) {
        assert_true(isset($i18n[$key]), "de.php has $key");
    }
});

wallos_test('the monthly API keeps cost and income apart', function () {
    $api = income_source('api/subscriptions/get_monthly_cost.php');
    assert_contains("if (\$subscription['type'] === 'income') {", $api, 'income is summed on its own');
    assert_contains("'monthly_balance' => number_format(\$monthlyIncome - \$monthlyCost, 2)", $api, 'the balance is income minus cost');
    assert_contains("NOT (type = 'income' AND cycle = 5)", $api, 'one-time income stays out, as in the statistics');
});

wallos_test('the income strings exist in the source language', function () {
    $i18n = [];
    include WALLOS_ROOT . '/includes/i18n/en.php';

    foreach (['entry_type', 'expense', 'income', 'income_and_expenses', 'upcoming_income', 'monthly_expenses', 'yearly_expenses', 'total_income', 'balance', 'monthly_income', 'yearly_income', 'monthly_balance', 'yearly_balance'] as $key) {
        assert_true(isset($i18n[$key]), "en.php has $key");
    }
});
