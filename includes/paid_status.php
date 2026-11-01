<?php

// A subscription counts as paid for the billing cycle that contains paid_at. The
// status clears by itself once next_payment moves on, so nothing has to reset it.

function getPaymentCycleDays($cycle, $frequency)
{
    switch ((int) $cycle) {
        case 1:
            return 1 * $frequency;
        case 2:
            return 7 * $frequency;
        case 3:
            return 30 * $frequency;
        case 4:
            return 365 * $frequency;
        default:
            return 0;
    }
}

function isPaidThisCycle($paidAt, $cycle, $frequency, $nextPayment)
{
    if (!$paidAt || (int) $cycle === 5) {
        return false;
    }

    $paymentCycleDays = getPaymentCycleDays($cycle, $frequency);
    if ($paymentCycleDays <= 0) {
        return false;
    }

    $paidDate = new DateTime($paidAt);
    $nextPaymentDate = new DateTime($nextPayment);
    $currentDate = new DateTime((new DateTime('now'))->format('Y-m-d'));

    if ($currentDate > $nextPaymentDate) {
        // Overdue: the cycle window runs forward from next_payment
        $cycleEnd = clone $nextPaymentDate;
        $cycleEnd->modify('+' . $paymentCycleDays . ' days');
        return $paidDate >= $nextPaymentDate && $paidDate <= $cycleEnd;
    }

    // Normal: walk back from next_payment to find the cycle containing today
    $daysUntilNextPayment = $currentDate->diff($nextPaymentDate)->days;
    $cyclesBack = max(1, (int) ceil($daysUntilNextPayment / $paymentCycleDays));

    $cycleStart = clone $nextPaymentDate;
    $cycleStart->modify('-' . ($cyclesBack * $paymentCycleDays) . ' days');

    return $paidDate >= $cycleStart && $paidDate <= $nextPaymentDate;
}
