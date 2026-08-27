<?php

declare(strict_types=1);

namespace justinholtweb\forklift\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\forklift\elements\Company;
use justinholtweb\forklift\models\Role;
use justinholtweb\forklift\Plugin;
use yii\console\ExitCode;

/**
 * Company accounts from the command line — for seeding, for migrations off another platform, and
 * for the two-minute setup that would otherwise be twenty clicks.
 */
class CompaniesController extends Controller
{
    /** Which account to work on, by code. */
    public ?string $company = null;

    /** The role to give a buyer. */
    public string $role = Role::BUYER;

    /** Per-order spend limit. */
    public ?float $spendLimit = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'add-buyer' => ['role', 'spendLimit'],
            'hold', 'release' => [],
            default => [],
        });
    }

    /** List the accounts. */
    public function actionIndex(): int
    {
        $companies = Company::find()->status(null)->all();

        if ($companies === []) {
            $this->stdout("No companies.\n");

            return ExitCode::OK;
        }

        foreach ($companies as $company) {
            $this->stdout(sprintf(
                "%-16s %-32s %-8s %2d buyers%s\n",
                (string)$company->code,
                mb_substr($company->getUiLabel(), 0, 32),
                $company->accountStatus,
                $company->getMemberCount(),
                $company->creditEnabled ? '  on terms' : '',
            ));
        }

        return ExitCode::OK;
    }

    /**
     * Create an account.
     *
     * @param string $name The company name.
     * @param string|null $code Its account code. Generated from the name if omitted.
     */
    public function actionCreate(string $name, ?string $code = null): int
    {
        $company = new Company(['title' => $name, 'code' => $code]);

        if (!Plugin::getInstance()->companies->saveCompany($company)) {
            foreach ($company->getErrors() as $attribute => $errors) {
                $this->stderr("{$attribute}: " . implode(', ', $errors) . "\n", Console::FG_RED);
            }

            return ExitCode::DATAERR;
        }

        $this->stdout("Created {$company->code} (#{$company->id}).\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Put somebody on an account.
     *
     * The first person on an account becomes its administrator whatever `--role` says — an
     * account with a single buyer and nobody able to administer it is the most common way to end
     * up needing support.
     *
     * @param string $companyCode The account code.
     * @param string $email The buyer's email address. They must already have an account.
     */
    public function actionAddBuyer(string $companyCode, string $email): int
    {
        $plugin = Plugin::getInstance();
        $company = $plugin->companies->getCompanyByCode($companyCode);

        if ($company === null) {
            $this->stderr("No company with the code “{$companyCode}”.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $user = Craft::$app->getUsers()->getUserByUsernameOrEmail($email);

        if ($user === null) {
            $this->stderr("No user with the email address “{$email}”. Forklift never creates users.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $member = $plugin->members->addUserToCompany(
            (int)$company->id,
            (int)$user->id,
            $this->role,
            $this->spendLimit,
        );

        if ($member === false) {
            $this->stderr("Couldn’t add that buyer.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("{$user->email} added to {$company->code} as {$member->role}.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Put an account on hold — the credit controller's four-o'clock-on-a-Friday action.
     *
     * @param string $companyCode The account code.
     */
    public function actionHold(string $companyCode): int
    {
        return $this->_setStatus($companyCode, Company::STATUS_HOLD, 'on hold');
    }

    /**
     * Take an account off hold.
     *
     * @param string $companyCode The account code.
     */
    public function actionRelease(string $companyCode): int
    {
        return $this->_setStatus($companyCode, Company::STATUS_ACTIVE, 'active');
    }

    private function _setStatus(string $companyCode, string $status, string $label): int
    {
        $plugin = Plugin::getInstance();
        $company = $plugin->companies->getCompanyByCode($companyCode);

        if ($company === null) {
            $this->stderr("No company with the code “{$companyCode}”.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        if (!$plugin->companies->setAccountStatus($company, $status)) {
            $this->stderr("Couldn’t change the status.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("{$company->code} is now {$label}.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
