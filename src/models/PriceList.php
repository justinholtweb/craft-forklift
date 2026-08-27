<?php

declare(strict_types=1);

namespace justinholtweb\forklift\models;

use Craft;
use craft\base\Model;
use craft\helpers\DateTimeHelper;
use DateTime;
use justinholtweb\forklift\Plugin;

/**
 * A contract price list.
 *
 * Craft's own catalog pricing rules already price *by condition* — this product type, that user
 * group, over that quantity. What they cannot express is the thing wholesale actually runs on: a
 * negotiated sheet of prices belonging to one named customer, with quantity breaks, that somebody
 * imported from a spreadsheet and will export back to one.
 *
 * So a price list is a **document**, not a rule. It has a name a salesperson recognises, an owner
 * (some companies, or every company), dates it is good between, and a body of entries. Two lists
 * can both price a purchasable; `priority` decides, and ties break on id so the answer is stable
 * rather than "whichever the database felt like returning first".
 *
 * @property-read PriceListEntry[] $entries
 * @property-read int[] $companyIds
 */
class PriceList extends Model
{
    public ?int $id = null;
    public ?int $storeId = null;
    public ?string $name = null;
    public ?string $handle = null;
    public ?string $description = null;
    public int $priority = 0;

    /**
     * Applies to every company, not to a named few.
     *
     * The "trade price" list every account gets, which a customer-specific list at a higher
     * priority then overrides for the handful who negotiated better.
     */
    public bool $allCompanies = false;

    public ?DateTime $dateFrom = null;
    public ?DateTime $dateTo = null;
    public bool $enabled = true;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    /** @var PriceListEntry[]|null */
    private ?array $_entries = null;

    /** @var int[]|null */
    private ?array $_companyIds = null;

    public function __toString(): string
    {
        return (string)$this->name;
    }

    /** @return PriceListEntry[] */
    public function getEntries(): array
    {
        if ($this->_entries === null) {
            $this->_entries = $this->id
                ? Plugin::getInstance()->priceLists->getEntriesByPriceListId($this->id)
                : [];
        }

        return $this->_entries;
    }

    /** @param PriceListEntry[] $entries */
    public function setEntries(array $entries): void
    {
        $this->_entries = array_values($entries);
    }

    /** @return int[] */
    public function getCompanyIds(): array
    {
        if ($this->_companyIds === null) {
            $this->_companyIds = $this->id
                ? Plugin::getInstance()->priceLists->getCompanyIdsByPriceListId($this->id)
                : [];
        }

        return $this->_companyIds;
    }

    /**
     * @param int[]|null $ids Null means "nobody touched the assignments" and leaves them alone —
     *                        an absent form field must never unassign every customer.
     */
    public function setCompanyIds(?array $ids): void
    {
        $this->_companyIds = $ids === null ? null : array_values(array_unique(array_map('intval', $ids)));
    }

    public function getIsActive(?DateTime $on = null): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $on ??= DateTimeHelper::currentUTCDateTime();

        if ($this->dateFrom !== null && $on < $this->dateFrom) {
            return false;
        }

        if ($this->dateTo !== null && $on > $this->dateTo) {
            return false;
        }

        return true;
    }

    public function appliesToCompany(int $companyId): bool
    {
        return $this->allCompanies || in_array($companyId, $this->getCompanyIds(), true);
    }

    public function getCpEditUrl(): string
    {
        return \craft\helpers\UrlHelper::cpUrl('forklift/price-lists/' . $this->id);
    }

    protected function defineRules(): array
    {
        return [
            [['name', 'handle'], 'required'],
            [['priority'], 'integer'],
            [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_]*$/', 'message' => Craft::t('forklift', 'Handles may only contain letters, numbers and underscores, and must start with a letter.')],
            [['dateTo'], 'validateDateRange', 'skipOnEmpty' => false],
            [['enabled', 'allCompanies'], 'boolean'],
            [['description', 'storeId', 'dateFrom'], 'safe'],
        ];
    }

    public function validateDateRange(string $attribute): void
    {
        if ($this->dateFrom !== null && $this->dateTo !== null && $this->dateTo < $this->dateFrom) {
            $this->addError($attribute, Craft::t('forklift', 'The end date must be after the start date.'));
        }
    }
}
