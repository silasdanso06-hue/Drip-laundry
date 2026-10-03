<?php
declare(strict_types=1);

function discountPolicy(): array {
    $policy = json_decode(file_get_contents(dirname(__DIR__) . '/assets/data/discount-policy.json'), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($policy['tiers'] ?? null) || !is_string($policy['description'] ?? null)) throw new RuntimeException('Discount rules are unavailable.');
    foreach ($policy['tiers'] as $tier) {
        if (!is_int($tier['minimumMinor'] ?? null) || $tier['minimumMinor'] < 0 || !is_int($tier['ratePercent'] ?? null) || $tier['ratePercent'] < 0 || $tier['ratePercent'] > 100) throw new RuntimeException('Invalid discount rule.');
    }
    return $policy;
}

function orderTotals(int $subtotalMinor): array {
    if ($subtotalMinor < 0) throw new InvalidArgumentException('Invalid order subtotal.');
    $rate = 0;
    foreach (discountPolicy()['tiers'] as $tier) if ($subtotalMinor >= $tier['minimumMinor']) $rate = max($rate, $tier['ratePercent']);
    // Round the discount to the nearest pesewa, then subtract it from the subtotal.
    $discountMinor = intdiv($subtotalMinor * $rate + 50, 100);
    return ['subtotal' => $subtotalMinor / 100, 'discountRate' => $rate, 'discountAmount' => $discountMinor / 100, 'total' => ($subtotalMinor - $discountMinor) / 100];
}
