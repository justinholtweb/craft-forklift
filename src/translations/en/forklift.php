<?php

/**
 * Forklift's user-facing strings.
 *
 * Every string the plugin shows goes through `Craft::t('forklift', …)`, and this file is the
 * catalogue a translator works from. English maps to itself; the value is what a store can
 * override without touching a template.
 */

return [
    // Identity
    'Forklift' => 'Forklift',
    'Company' => 'Company',
    'company' => 'company',
    'Companies' => 'Companies',
    'companies' => 'companies',
    'Quote' => 'Quote',
    'quote' => 'quote',
    'Quotes' => 'Quotes',
    'quotes' => 'quotes',

    // Roles
    'Administrator' => 'Administrator',
    'Approver' => 'Approver',
    'Buyer' => 'Buyer',
    'Viewer' => 'Viewer',

    // Account status
    'Active' => 'Active',
    'On hold' => 'On hold',
    'Closed' => 'Closed',

    // Checkout
    'Place order' => 'Place order',
    'Submit for approval' => 'Submit for approval',
    'Awaiting approval' => 'Awaiting approval',
    'This account is on hold. Please contact us to place an order.' => 'This account is on hold. Please contact us to place an order.',
    'This account is closed and cannot place orders.' => 'This account is closed and cannot place orders.',
    'Your account can view orders but not place them. Ask an administrator to change your role.' => 'Your account can view orders but not place them. Ask an administrator to change your role.',
    'This order is waiting for approval. You will be emailed when it has been decided.' => 'This order is waiting for approval. You will be emailed when it has been decided.',
    'This order was declined by an approver.' => 'This order was declined by an approver.',
    'This order is over your spend limit and needs approval.' => 'This order is over your spend limit and needs approval.',
    'This order is over your company’s approval threshold and needs approval.' => 'This order is over your company’s approval threshold and needs approval.',
    'Your orders need approval before they can be placed.' => 'Your orders need approval before they can be placed.',
    'This order has grown since it was approved, so it needs approving again.' => 'This order has grown since it was approved, so it needs approving again.',
    'This account requires a purchase order number on every order.' => 'This account requires a purchase order number on every order.',

    // Pricing
    'List price' => 'List price',
    'Contract price' => 'Contract price',
    'Quoted price' => 'Quoted price',
    'Promotion' => 'Promotion',
    'Everything' => 'Everything',

    // Payment terms
    'Due on receipt' => 'Due on receipt',
    'Prepay' => 'Prepay',
    'Purchase Order (Forklift)' => 'Purchase Order (Forklift)',
    'Purchase order number' => 'Purchase order number',
    'A purchase order number is required.' => 'A purchase order number is required.',

    // Credit
    'Charge' => 'Charge',
    'Payment' => 'Payment',
    'Adjustment' => 'Adjustment',
    'Current' => 'Current',
    'Statement' => 'Statement',
    'Balance' => 'Balance',

    // Certificates
    'Approved' => 'Approved',
    'Rejected' => 'Rejected',
    'Expired' => 'Expired',
    'Pending review' => 'Pending review',
    'All jurisdictions' => 'All jurisdictions',

    // Quick order
    'Quick order' => 'Quick order',
    'No SKU given.' => 'No SKU given.',
    'Quantity must be at least 1.' => 'Quantity must be at least 1.',
];
