<?php

declare(strict_types=1);

namespace justinholtweb\forklift\db;

/**
 * Forklift's table names, in one place.
 *
 * Every query and every migration reads a name from here, so a table created as
 * `forklift_pricelist_entries` and queried as `forklift_pricelistentries` cannot drift apart in a
 * typo that only surfaces on the one database driver nobody tested.
 */
abstract class Table
{
    public const COMPANIES = '{{%forklift_companies}}';
    public const MEMBERS = '{{%forklift_members}}';
    public const PRICELISTS = '{{%forklift_pricelists}}';
    public const PRICELIST_ENTRIES = '{{%forklift_pricelist_entries}}';
    public const PRICELIST_COMPANIES = '{{%forklift_pricelist_companies}}';
    public const QUOTES = '{{%forklift_quotes}}';
    public const QUOTELINES = '{{%forklift_quotelines}}';
    public const APPROVALS = '{{%forklift_approvals}}';
    public const CREDIT_ENTRIES = '{{%forklift_creditentries}}';
    public const CERTIFICATES = '{{%forklift_certificates}}';
    public const TERMS = '{{%forklift_terms}}';
    public const ORDERS = '{{%forklift_orders}}';
}
