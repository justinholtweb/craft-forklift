<?php

declare(strict_types=1);

namespace justinholtweb\forklift\models;

use Craft;
use craft\base\Model;
use craft\elements\User;
use DateTime;
use justinholtweb\forklift\elements\Company;
use justinholtweb\forklift\Plugin;

/**
 * One person's membership of one company.
 *
 * The role and the spend limit are properties of the *membership*, not of the user: the same
 * person can be an administrator of one account and a viewer on another, which is exactly what
 * happens when a buying group and one of its members both trade with the same supplier.
 *
 * @property-read User|null $user
 * @property-read Company|null $company
 */
class Member extends Model
{
    public ?int $id = null;
    public ?int $companyId = null;
    public ?int $userId = null;
    public string $role = Role::BUYER;

    /**
     * The most this person may spend on one order before somebody has to sign it off.
     *
     * Null and zero mean different things and both are useful: **null** is "no limit of their
     * own", **zero** is "may not place any order unassisted" — the new starter whose every order
     * goes to their manager.
     */
    public ?float $spendLimit = null;

    /** Every order this person places needs approval, whatever the amount. */
    public bool $requiresApproval = false;

    /** The company this person's carts default to when they belong to more than one. */
    public bool $isDefault = false;

    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    private ?User $_user = null;
    private ?Company $_company = null;

    public function getUser(): ?User
    {
        if ($this->_user === null && $this->userId) {
            $this->_user = Craft::$app->getUsers()->getUserById($this->userId);
        }

        return $this->_user;
    }

    public function setUser(?User $user): void
    {
        $this->_user = $user;
        $this->userId = $user?->id;
    }

    public function getCompany(): ?Company
    {
        if ($this->_company === null && $this->companyId) {
            $this->_company = Plugin::getInstance()->companies->getCompanyById($this->companyId);
        }

        return $this->_company;
    }

    public function setCompany(?Company $company): void
    {
        $this->_company = $company;
        $this->companyId = $company?->id;
    }

    public function getName(): string
    {
        return $this->getUser()?->getName() ?? Craft::t('forklift', 'Unknown user');
    }

    public function getEmail(): ?string
    {
        return $this->getUser()?->email;
    }

    public function getRoleLabel(): string
    {
        return Role::label($this->role);
    }

    public function getCanApprove(): bool
    {
        return Role::canApprove($this->role);
    }

    public function getCanPurchase(): bool
    {
        return Role::canPurchase($this->role);
    }

    public function getCanManageMembers(): bool
    {
        return Role::canManageMembers($this->role);
    }

    public function getCanSeeFinancials(): bool
    {
        return Role::canSeeFinancials($this->role);
    }

    /**
     * Whether an order of this size needs somebody else's signature.
     *
     * Only the *member's* half of the question — the company threshold is the other half, and
     * `services\Checkout` puts the two together. Kept apart because a member limit is about who
     * this person is trusted to be, and a company threshold is about how the account is run.
     */
    public function exceedsSpendLimit(float $amount): bool
    {
        if ($this->requiresApproval) {
            return true;
        }

        if ($this->spendLimit === null) {
            return false;
        }

        return $amount > $this->spendLimit;
    }

    protected function defineRules(): array
    {
        return [
            [['companyId', 'userId', 'role'], 'required'],
            [['companyId', 'userId'], 'integer'],
            [['role'], 'in', 'range' => Role::ALL],
            [['spendLimit'], 'number', 'min' => 0],
            [['requiresApproval', 'isDefault'], 'boolean'],
        ];
    }
}
