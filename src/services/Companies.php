<?php

declare(strict_types=1);

namespace justinholtweb\forklift\services;

use Craft;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\StringHelper;
use justinholtweb\forklift\db\Table;
use justinholtweb\forklift\elements\Company;
use justinholtweb\forklift\models\Member;
use justinholtweb\forklift\Plugin;
use yii\base\Component;

/**
 * Companies: reading them, and deciding which one a request belongs to.
 *
 * ## Which company is "current"
 *
 * Most people belong to exactly one company and never think about this. The ones who belong to
 * several — a buying group, a franchisee who orders for two sites, a distributor's own staff
 * ordering on behalf of customers — need a choice, and the choice has to survive a page load
 * without surviving a *sign-out*. So it lives in the session, and it is validated against the
 * user's memberships on every read rather than trusted: a session written before somebody was
 * removed from an account must not keep pricing their cart at that account's rates.
 *
 * The resolution order is deliberate:
 *
 * 1. the session, if that company is still one of theirs;
 * 2. their membership flagged `isDefault`;
 * 3. their only membership, if they have exactly one;
 * 4. nothing — a signed-in retail customer with no account is not an error.
 */
class Companies extends Component
{
    /** Where the chosen company id lives between requests. */
    public const SESSION_KEY = 'forklift.companyId';

    /** @var array<int, Company|null> */
    private array $_byId = [];

    /** Memoized per request. `false` means "not worked out yet", null means "worked out: none". */
    private Company|null|false $_current = false;

    public function getCompanyById(int $id): ?Company
    {
        if (!array_key_exists($id, $this->_byId)) {
            /** @var Company|null $company */
            $company = Company::find()->id($id)->status(null)->one();
            $this->_byId[$id] = $company;
        }

        return $this->_byId[$id];
    }

    public function getCompanyByCode(string $code): ?Company
    {
        /** @var Company|null */
        return Company::find()->code($code)->status(null)->one();
    }

    public function getCompanyByUid(string $uid): ?Company
    {
        /** @var Company|null */
        return Company::find()->uid($uid)->status(null)->one();
    }

    /**
     * Every company this user buys for.
     *
     * @return Company[]
     */
    public function getCompaniesForUser(int|User $user): array
    {
        $userId = $user instanceof User ? $user->id : $user;

        if (!$userId) {
            return [];
        }

        /** @var Company[] */
        return Company::find()->memberUserId($userId)->status(null)->all();
    }

    /**
     * The company this request is buying for, or null.
     *
     * Never trusts the session on its own — see the class docblock.
     */
    public function getCurrentCompany(): ?Company
    {
        if ($this->_current !== false) {
            return $this->_current;
        }

        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null) {
            return $this->_current = null;
        }

        $memberships = Plugin::getInstance()->members->getMembersByUserId($user->id);

        if ($memberships === []) {
            return $this->_current = null;
        }

        $allowed = array_column($memberships, 'companyId');
        $sessionId = $this->_sessionCompanyId();

        if ($sessionId !== null && in_array($sessionId, $allowed, true)) {
            return $this->_current = $this->getCompanyById($sessionId);
        }

        foreach ($memberships as $membership) {
            if ($membership->isDefault) {
                return $this->_current = $this->getCompanyById((int)$membership->companyId);
            }
        }

        if (count($memberships) === 1) {
            return $this->_current = $this->getCompanyById((int)$memberships[0]->companyId);
        }

        // Several accounts and no default. Refusing to guess is right: pricing a cart at the
        // wrong account's rates is worse than asking which account this order is for.
        return $this->_current = null;
    }

    /**
     * The company id parked in the session, if there is one.
     *
     * Guarded, and not only for tidiness: a console request has no session at all, and Craft's
     * `getSession()` on the console application is a different component with no `get()`. Anything
     * that reads the session from a service also called by a queue job or a console command has to
     * cope with there not being one.
     */
    private function _sessionCompanyId(): ?int
    {
        $value = $this->_session()?->get(self::SESSION_KEY);

        return $value === null || $value === '' ? null : (int)$value;
    }

    /**
     * The session, or null when there is not one.
     *
     * Craft's console application throws `MissingComponentException` from `getSession()` rather
     * than returning null, so every path that reads or writes the chosen company has to go
     * through here. Queue jobs and console commands legitimately call these methods.
     */
    private function _session(): ?\craft\web\Session
    {
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            return null;
        }

        try {
            $session = Craft::$app->getSession();
        } catch (\Throwable) {
            return null;
        }

        return $session instanceof \craft\web\Session ? $session : null;
    }

    /** The membership behind {@see getCurrentCompany()}, which is what carries the spend limit. */
    public function getCurrentMember(): ?Member
    {
        $user = Craft::$app->getUser()->getIdentity();
        $company = $this->getCurrentCompany();

        if ($user === null || $company === null) {
            return null;
        }

        return Plugin::getInstance()->members->getMember((int)$company->id, (int)$user->id);
    }

    /**
     * Choose the company this session buys for.
     *
     * Returns false when the user is not a member of it, rather than throwing: this is reachable
     * from a front-end form, and a stale bookmark is not an exceptional condition.
     */
    public function setCurrentCompany(?int $companyId): bool
    {
        $this->_current = false;
        $session = $this->_session();

        if ($companyId === null) {
            $session?->remove(self::SESSION_KEY);

            return true;
        }

        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null) {
            return false;
        }

        // The membership check comes before the session, so an outsider is refused for the right
        // reason whatever context this is called from.
        if (Plugin::getInstance()->members->getMember($companyId, (int)$user->id) === null) {
            return false;
        }

        if ($session === null) {
            // A console request or a queue job. There is nowhere to keep the choice, and saying
            // so is better than reporting a selection that was not made.
            return false;
        }

        $session->set(self::SESSION_KEY, $companyId);

        return true;
    }

    /**
     * An account code, when the merchant did not supply one.
     *
     * Derived from the name, uppercased, with a numeric suffix if it collides. Recognisable on a
     * pick note, which a random string is not.
     */
    public function generateCode(Company $company): string
    {
        $base = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$company->title) ?: 'ACC');
        $base = substr($base, 0, 8);

        if ($base === '') {
            $base = 'ACC';
        }

        $code = $base;
        $suffix = 1;

        while (($existing = $this->getCompanyByCode($code)) !== null && $existing->id !== $company->id) {
            $code = $base . '-' . (++$suffix);

            if ($suffix > 999) {
                // Give up on being readable rather than looping. Vanishingly unlikely, and a
                // unique code matters more than a pretty one.
                return $base . '-' . StringHelper::randomString(6);
            }
        }

        return $code;
    }

    /**
     * Save a company. A thin wrapper over Craft's element save, so callers do not each have to
     * remember to disable validation or to propagate.
     */
    public function saveCompany(Company $company, bool $runValidation = true): bool
    {
        $saved = Craft::$app->getElements()->saveElement($company, $runValidation);

        if ($saved) {
            // The memo has to be refreshed rather than merely dropped, and this is not a
            // micro-optimisation. `Checkout` asks for the company through this service on every
            // recalculation; a credit controller who puts an account on hold and a threshold that
            // was changed a moment ago would both be read from a copy loaded before the change,
            // and the checkout gate would go on using the old answer for the rest of the request.
            $this->_byId[(int)$company->id] = $company;
            $this->_current = false;
        }

        return $saved;
    }

    public function deleteCompany(Company $company): bool
    {
        $deleted = Craft::$app->getElements()->deleteElement($company);

        if ($deleted) {
            unset($this->_byId[(int)$company->id]);
            $this->_current = false;
        }

        return $deleted;
    }

    /** Forget everything memoized. Called between test cases, and by long-running console work. */
    public function clearCaches(): void
    {
        $this->_byId = [];
        $this->_current = false;
    }

    /** Put an account on hold, or take it off. The credit controller's one-click action. */
    public function setAccountStatus(Company $company, string $status): bool
    {
        if (!in_array($status, Company::ACCOUNT_STATUSES, true)) {
            return false;
        }

        $company->accountStatus = $status;

        return $this->saveCompany($company, false);
    }

    public function getTotalCompanies(): int
    {
        return (int)Company::find()->status(null)->count();
    }

    /**
     * How many accounts have an approval threshold set.
     *
     * A raw count against the table rather than an element query, because the settings screen
     * asks this on every control-panel request to decide whether to warn about a lapsed licence,
     * and it should cost one index scan.
     */
    public function getTotalCompaniesRequiringApproval(): int
    {
        return (int)(new Query())
            ->from([Table::COMPANIES])
            ->where(['not', ['approvalThreshold' => null]])
            ->count();
    }

    /** Companies that hold a certificate needing somebody's attention. */
    public function getTotalCompaniesWithPendingCertificates(): int
    {
        return (int)(new Query())
            ->from([Table::CERTIFICATES])
            ->where(['status' => 'pending'])
            ->count();
    }
}
