<?php

declare(strict_types=1);

namespace justinholtweb\forklift\models\payments;

use Craft;
use craft\commerce\models\payments\BasePaymentForm;

/**
 * What a buyer fills in to pay on account: their purchase order number, and nothing else.
 *
 * No card details, no token, no redirect. That is the whole point of paying on terms — the
 * *authorisation* is the trading relationship, and the only thing the merchant needs from the
 * buyer at checkout is the reference their accounts-payable department will match the invoice
 * against.
 */
class PurchaseOrderPaymentForm extends BasePaymentForm
{
    public ?string $poNumber = null;

    /** Free text the buyer wants on the invoice: a cost centre, a project code, a contact. */
    public ?string $reference = null;

    /** Set by the gateway when the company insists on one. */
    public bool $poNumberRequired = false;

    protected function defineRules(): array
    {
        return [
            [['poNumber', 'reference'], 'string', 'max' => 128],
            [['poNumber'], 'required', 'when' => fn(self $model) => $model->poNumberRequired, 'message' => Craft::t('forklift', 'A purchase order number is required.')],
            [['poNumber', 'reference'], 'trim'],
        ];
    }
}
