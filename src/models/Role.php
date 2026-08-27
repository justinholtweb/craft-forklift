<?php

declare(strict_types=1);

namespace justinholtweb\forklift\models;

use Craft;

/**
 * The four roles a person can hold inside a company.
 *
 * Deliberately four and not a permission matrix. Every B2B suite that started with a role builder
 * ended up with customers who had one role called "Everything" — the useful distinctions in a
 * purchasing department are small in number and stable across industries:
 *
 * - **Admin** — runs the account. Adds and removes buyers, sets their limits, approves anything,
 *   sees the statement.
 * - **Approver** — approves other people's orders, up to their own spend limit. Does not
 *   administer the account.
 * - **Buyer** — places orders, up to their own spend limit. The default, and the common case.
 * - **Viewer** — sees the company's orders and quotes and can reorder into a *cart*, but cannot
 *   place an order. The warehouse manager who checks what is arriving.
 *
 * A spend limit is a property of the *member*, not of the role, so "Rita can spend £20,000 and
 * Sam £500" needs no new role.
 */
abstract class Role
{
    public const ADMIN = 'admin';
    public const APPROVER = 'approver';
    public const BUYER = 'buyer';
    public const VIEWER = 'viewer';

    public const ALL = [self::ADMIN, self::APPROVER, self::BUYER, self::VIEWER];

    public static function label(string $role): string
    {
        return match ($role) {
            self::ADMIN => Craft::t('forklift', 'Administrator'),
            self::APPROVER => Craft::t('forklift', 'Approver'),
            self::BUYER => Craft::t('forklift', 'Buyer'),
            self::VIEWER => Craft::t('forklift', 'Viewer'),
            default => $role,
        };
    }

    /** @return array<int, array{label: string, value: string}> */
    public static function options(): array
    {
        return array_map(static fn(string $role) => [
            'label' => self::label($role),
            'value' => $role,
        ], self::ALL);
    }

    public static function exists(string $role): bool
    {
        return in_array($role, self::ALL, true);
    }

    /** Whether this role may add, edit and remove the company's other members. */
    public static function canManageMembers(string $role): bool
    {
        return $role === self::ADMIN;
    }

    /** Whether this role may decide somebody else's approval request. */
    public static function canApprove(string $role): bool
    {
        return in_array($role, [self::ADMIN, self::APPROVER], true);
    }

    /** Whether this role may place an order at all. */
    public static function canPurchase(string $role): bool
    {
        return in_array($role, [self::ADMIN, self::APPROVER, self::BUYER], true);
    }

    /** Whether this role may see the company's balance, invoices and statement. */
    public static function canSeeFinancials(string $role): bool
    {
        return in_array($role, [self::ADMIN, self::APPROVER], true);
    }

    /** Whether this role may ask for a quote. Viewers may — asking costs nothing. */
    public static function canRequestQuote(string $role): bool
    {
        return true;
    }
}
