<?php

declare(strict_types=1);

namespace justinholtweb\forklift\services;

use Craft;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use justinholtweb\forklift\db\Table;
use justinholtweb\forklift\elements\Company;
use justinholtweb\forklift\models\Member;
use justinholtweb\forklift\models\Term;
use justinholtweb\forklift\Plugin;
use yii\base\Component;

/**
 * The B2B facts about a Commerce order: which account it belongs to, who placed it, its purchase
 * order number, its terms, and what approval it went through.
 *
 * Kept in Forklift's own table rather than on the order, because Commerce's order table is not
 * ours to extend and an order's `orderLanguage`-style custom fields do not survive being read
 * back by a plugin that has been uninstalled and reinstalled. The row is written on every cart
 * save, so a cart carries its company from the first line item onwards — which it has to, because
 * the price of that first line item depends on it.
 *
 * `getQuoteIdForOrder()` is on a hot path — `Pricing` asks it for every line of every
 * recalculation — so it is memoized per request and answers `null` from cache rather than
 * re-querying for the overwhelmingly common case of an order that came from no quote.
 */
class Orders extends Component
{
    /** @var array<int, array|null> */
    private array $_rows = [];

    /**
     * The Forklift row for an order, or null.
     *
     * @return array<string, mixed>|null
     */
    public function getRowForOrder(int $orderId): ?array
    {
        if (!array_key_exists($orderId, $this->_rows)) {
            $row = (new Query())
                ->from([Table::ORDERS])
                ->where(['orderId' => $orderId])
                ->one();

            $this->_rows[$orderId] = $row ?: null;
        }

        return $this->_rows[$orderId];
    }

    public function getCompanyIdForOrder(int $orderId): ?int
    {
        $row = $this->getRowForOrder($orderId);

        return isset($row['companyId']) ? (int)$row['companyId'] : null;
    }

    public function getCompanyForOrder(Order $order): ?Company
    {
        $companyId = $order->id ? $this->getCompanyIdForOrder((int)$order->id) : null;

        return $companyId ? Plugin::getInstance()->companies->getCompanyById($companyId) : null;
    }

    public function getMemberForOrder(Order $order): ?Member
    {
        $row = $order->id ? $this->getRowForOrder((int)$order->id) : null;

        if (isset($row['memberId'])) {
            return Plugin::getInstance()->members->getMemberById((int)$row['memberId']);
        }

        return null;
    }

    public function getQuoteIdForOrder(int $orderId): ?int
    {
        $row = $this->getRowForOrder($orderId);

        return isset($row['quoteId']) ? (int)$row['quoteId'] : null;
    }

    public function getPoNumberForOrder(int $orderId): ?string
    {
        $row = $this->getRowForOrder($orderId);

        return $row['poNumber'] ?? null;
    }

    public function getTermsForOrder(int $orderId): ?Term
    {
        $row = $this->getRowForOrder($orderId);

        return isset($row['termsId']) ? Plugin::getInstance()->terms->getTermById((int)$row['termsId']) : null;
    }

    public function getApprovalIdForOrder(int $orderId): ?int
    {
        $row = $this->getRowForOrder($orderId);

        return isset($row['approvalId']) ? (int)$row['approvalId'] : null;
    }

    /**
     * Write, or update, the Forklift row for an order.
     *
     * Only the keys given are touched — a caller recording a PO number must not blank the
     * company, and a caller assigning a company must not blank an approval that has already been
     * granted. This is the same trap as an absent form field clearing a relation, and it is worth
     * the extra care here because every one of these columns is load-bearing at checkout.
     *
     * @param array<string, mixed> $values
     */
    public function setValuesForOrder(int $orderId, array $values): bool
    {
        $allowed = [
            'companyId', 'memberId', 'poNumber', 'termsId', 'dueDate', 'approvalId',
            'approvalBypassed', 'quoteId', 'priceListIds', 'taxExemptCertificateId',
        ];

        $values = array_intersect_key($values, array_flip($allowed));

        if ($values === []) {
            return true;
        }

        if (array_key_exists('dueDate', $values)) {
            $values['dueDate'] = Db::prepareDateForDb($values['dueDate']);
        }

        if (array_key_exists('priceListIds', $values) && is_array($values['priceListIds'])) {
            $values['priceListIds'] = json_encode(array_values(array_map('intval', $values['priceListIds'])));
        }

        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(DateTimeHelper::currentUTCDateTime());
        $existing = $this->getRowForOrder($orderId);

        if ($existing !== null) {
            $db->createCommand()
                ->update(Table::ORDERS, $values + ['dateUpdated' => $now], ['orderId' => $orderId])
                ->execute();
        } else {
            $db->createCommand()
                ->insert(Table::ORDERS, $values + [
                    'orderId' => $orderId,
                    'dateCreated' => $now,
                    'dateUpdated' => $now,
                    'uid' => StringHelper::UUID(),
                ])
                ->execute();
        }

        unset($this->_rows[$orderId]);

        return true;
    }

    /**
     * Attach an order to the account its customer is buying for.
     *
     * Called on every cart save. Deliberately does **not** overwrite a company that is already on
     * the order: a merchant editing an order in the control panel, or a quote materialised for a
     * named account, must not have their choice undone the next time the cart recalculates.
     */
    public function assignCompany(Order $order): void
    {
        if (!$order->id || $order->isCompleted) {
            return;
        }

        if (!Plugin::getInstance()->getSettings()->autoAssignCompany) {
            return;
        }

        if ($this->getCompanyIdForOrder((int)$order->id) !== null) {
            return;
        }

        $customer = $order->getCustomer();

        if ($customer === null) {
            return;
        }

        // The company the *customer* buys for, not the current session's — an order edited in the
        // control panel by an administrator belongs to the customer's account, not the
        // administrator's.
        $memberships = Plugin::getInstance()->members->getMembersByUserId((int)$customer->id);

        if ($memberships === []) {
            return;
        }

        $member = null;

        if (count($memberships) === 1) {
            $member = $memberships[0];
        } else {
            foreach ($memberships as $candidate) {
                if ($candidate->isDefault) {
                    $member = $candidate;
                    break;
                }
            }

            // Several accounts, no default, and the session can still answer it when the customer
            // is the person browsing.
            if ($member === null) {
                $current = Plugin::getInstance()->companies->getCurrentMember();

                if ($current !== null && $current->userId === $customer->id) {
                    $member = $current;
                }
            }
        }

        if ($member === null) {
            return;
        }

        $company = Plugin::getInstance()->companies->getCompanyById((int)$member->companyId);

        $this->setValuesForOrder((int)$order->id, [
            'companyId' => $member->companyId,
            'memberId' => $member->id,
            'termsId' => $company?->termsId,
        ]);
    }

    /** Move an order onto a different account, or off one. Used by the CP order panel. */
    public function setCompanyForOrder(Order $order, ?int $companyId): bool
    {
        if (!$order->id) {
            return false;
        }

        $memberId = null;
        $termsId = null;

        if ($companyId !== null) {
            $company = Plugin::getInstance()->companies->getCompanyById($companyId);
            $termsId = $company?->termsId;

            $customer = $order->getCustomer();

            if ($customer !== null) {
                $memberId = Plugin::getInstance()->members->getMember($companyId, (int)$customer->id)?->id;
            }
        }

        return $this->setValuesForOrder((int)$order->id, [
            'companyId' => $companyId,
            'memberId' => $memberId,
            'termsId' => $termsId,
        ]);
    }

    /** @return int[] Order ids for a company, most recent first. */
    public function getOrderIdsForCompany(int $companyId, ?int $limit = null): array
    {
        $query = (new Query())
            ->select(['orderId'])
            ->from([Table::ORDERS])
            ->where(['companyId' => $companyId])
            ->orderBy(['orderId' => SORT_DESC]);

        if ($limit !== null) {
            $query->limit($limit);
        }

        return array_map('intval', $query->column());
    }

    /** Orders that completed without the approval they should have had, because of a downgrade. */
    public function getBypassedApprovalCount(): int
    {
        return (int)(new Query())
            ->from([Table::ORDERS])
            ->where(['approvalBypassed' => true])
            ->count();
    }

    public function clearCaches(): void
    {
        $this->_rows = [];
    }
}
