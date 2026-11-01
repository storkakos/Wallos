<?php
/*
  The renewal cron (endpoints/cronjobs/updatenextpayment.php) walks every
  user's overdue auto-renewing subscriptions in one loop, advancing each by
  "P{frequency}{unit}" until it is in the future. A frequency of 0 never
  advances, so the loop never ends; a negative one, or a cycle with no unit
  (one-time), is not a valid interval and throws. Either way one user's row
  stopped renewals for the whole instance.

  The save paths now refuse such values, but rows written before that still
  exist, so the cron itself must step over them. The script resolves its
  database from __DIR__, so it runs as a child process inside a throwaway
  copy of the files it needs, with a hard time limit standing in for "hangs".
*/

/**
 * Builds a sandbox holding the renewal cron and its includes, with a fresh
 * database, and returns the sandbox root.
 *
 * @return string
 */
function renewal_cron_sandbox()
{
    $root = WALLOS_TEST_TMP . '/renewal-' . uniqid('', true);

    foreach (['endpoints/cronjobs', 'includes/i18n', 'db'] as $directory) {
        mkdir($root . '/' . $directory, 0700, true);
    }
    foreach (['updatenextpayment.php', 'validate.php', 'settimezone.php'] as $file) {
        copy(WALLOS_ROOT . '/endpoints/cronjobs/' . $file, $root . '/endpoints/cronjobs/' . $file);
    }
    copy(WALLOS_ROOT . '/includes/connect_endpoint_crontabs.php', $root . '/includes/connect_endpoint_crontabs.php');
    foreach (glob(WALLOS_ROOT . '/includes/i18n/*.php') as $file) {
        copy($file, $root . '/includes/i18n/' . basename($file));
    }
    copy(wallos_test_database(), $root . '/db/wallos.db');

    return $root;
}

/**
 * Runs the renewal cron in the sandbox and reports whether it finished in
 * time and with what exit code.
 *
 * @param string $root
 * @param int    $timeoutSeconds
 * @return array{finished: bool, exitCode: int|null, output: string}
 */
function run_renewal_cron($root, $timeoutSeconds)
{
    $process = proc_open(
        [PHP_BINARY, $root . '/endpoints/cronjobs/updatenextpayment.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root . '/endpoints/cronjobs'
    );
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $output = '';
    $deadline = microtime(true) + $timeoutSeconds;
    $exitCode = null;
    while (microtime(true) < $deadline) {
        $output .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) {
            $exitCode = $status['exitcode'];
            break;
        }
        usleep(50000);
    }

    $finished = $exitCode !== null;
    if (!$finished) {
        proc_terminate($process, 9);
    }
    $output .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return ['finished' => $finished, 'exitCode' => $exitCode, 'output' => $output];
}

wallos_test('a subscription saved with an invalid frequency or cycle does not stall renewals for everyone', function () {
    $root = renewal_cron_sandbox();
    $db = new SQLite3($root . '/db/wallos.db');
    wallos_test_create_user($db, 1, 'admin');
    wallos_test_create_user($db, 2, 'other');

    $rows = [
        // name, user, cycle, frequency
        ['zero frequency', 2, 3, 0],
        ['negative frequency', 2, 3, -1],
        ['one-time', 2, 5, 1],
        ['valid monthly', 1, 3, 1],
    ];
    $stmt = $db->prepare("INSERT INTO subscriptions (user_id, name, price, currency_id, next_payment, cycle, frequency, auto_renew, inactive)
                          VALUES (:userId, :name, 1, :currencyId, '2020-01-15', :cycle, :frequency, 1, 0)");
    foreach ($rows as [$name, $userId, $cycle, $frequency]) {
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':currencyId', wallos_test_currency_id($userId, 0), SQLITE3_INTEGER);
        $stmt->bindValue(':cycle', $cycle, SQLITE3_INTEGER);
        $stmt->bindValue(':frequency', $frequency, SQLITE3_INTEGER);
        $stmt->execute();
        $stmt->reset();
    }
    $db->close();

    $run = run_renewal_cron($root, 20);

    assert_true($run['finished'], 'the cron finishes instead of looping forever');
    assert_same(0, $run['exitCode'], 'the cron exits cleanly: ' . trim(strip_tags($run['output'])));

    $db = new SQLite3($root . '/db/wallos.db');
    $nextPayments = [];
    $result = $db->query('SELECT name, next_payment FROM subscriptions');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $nextPayments[$row['name']] = $row['next_payment'];
    }
    $db->close();

    assert_true($nextPayments['valid monthly'] >= date('Y-m-d'),
        'the valid subscription after the bad rows was still renewed into the future');
    assert_same('2020-01-15', $nextPayments['zero frequency'], 'the zero-frequency row is left alone');
    assert_same('2020-01-15', $nextPayments['negative frequency'], 'the negative-frequency row is left alone');
    assert_same('2020-01-15', $nextPayments['one-time'], 'the one-time row is left alone');
});

wallos_test('both subscription save paths bound frequency and cycle', function () {
    $web = file_get_contents(WALLOS_ROOT . '/endpoints/subscription/add.php');
    assert_contains("(int) \$frequency < 1 || (int) \$frequency > 366", $web,
        'the web form endpoint refuses a frequency outside 1-366');
    assert_contains('[1, 2, 3, 4, 5]', $web, 'the web form endpoint refuses an unknown cycle');

    $api = file_get_contents(WALLOS_ROOT . '/api/subscriptions/set_subscriptions.php');
    assert_same(2, substr_count($api, '$frequency < 1 || $frequency > 366'),
        'the API refuses a frequency outside 1-366 on both add and edit');
});
