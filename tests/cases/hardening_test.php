<?php
/*
  Fixes adapted from the Tally fork of Wallos. The renewal cron itself is tested
  in renewal_frequency_test.php.
*/

function hardening_source($path)
{
    return str_replace("\r\n", "\n", file_get_contents(WALLOS_ROOT . '/' . $path));
}

wallos_test('the search endpoints are for signed-in users only', function () {
    foreach (['endpoints/logos/search.php', 'endpoints/logos/icon_search.php', 'endpoints/payments/search.php'] as $path) {
        assert_contains("require_once '../../includes/require_login.php';", hardening_source($path), $path);
    }
    $login = hardening_source('includes/require_login.php');
    assert_contains('http_response_code(401);', $login, 'anonymous callers are refused');
    assert_true(strpos($login, 'exit;') < strpos($login, 'session_write_close();'), 'before anything else happens');
});

wallos_test('one failing channel does not end a notification run', function () {
    foreach ([['endpoints/cronjobs/sendnotifications.php', 11], ['endpoints/cronjobs/sendcancellationnotifications.php', 7]] as [$path, $channels]) {
        $source = hardening_source($path);
        assert_same($channels, substr_count($source, 'notifications if enabled'), "$path channel count");
        assert_same($channels, substr_count($source, 'catch (Throwable $error)') - 1, "$path wraps every channel and each email recipient");
    }
});

wallos_test('a failed channel is reported and escaped', function () {
    ob_start();
    $before = ini_set('error_log', WALLOS_TEST_TMP . '/notification-errors.log');
    report_notification_failure('Ntfy', new RuntimeException('<b>down</b>'));
    ini_set('error_log', $before);
    $output = ob_get_clean();

    assert_contains('Ntfy notification failed: &lt;b&gt;down&lt;/b&gt;', $output, 'the message cannot inject markup');
});

wallos_test('the cancellation cron keeps the account id', function () {
    $source = hardening_source('endpoints/cronjobs/sendcancellationnotifications.php');
    assert_not_contains('foreach ($notify as $userId', $source, 'a loop must not replace the account being processed');
    assert_not_contains('household WHERE id = :userId', $source, 'household lookups are scoped to the account');
});

wallos_test('the image picks up security fixes on every build', function () {
    assert_contains('ARG APK_CACHE_BUST', hardening_source('Dockerfile'), 'the upgrade layer can be refreshed');
    assert_contains('APK_CACHE_BUST: ${APK_CACHE_BUST:-}', hardening_source('docker-compose.yaml'), 'compose passes it on');
});
