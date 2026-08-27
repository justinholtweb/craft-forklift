<?php

declare(strict_types=1);

namespace justinholtweb\forklift\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\forklift\Plugin;
use yii\console\ExitCode;

/**
 * The things that happen because time passed rather than because anybody did anything.
 *
 * All three also run from Craft's garbage collection, so a store with a working `gc` never needs
 * this. It exists for a store that wants them on their own schedule, and because "run it now and
 * tell me what it did" is a great deal easier to debug than "wait for gc".
 */
class MaintenanceController extends Controller
{
    /**
     * Expire lapsed approval requests, expire sent quotes, and re-derive tax-exempt flags.
     */
    public function actionRun(): int
    {
        $plugin = Plugin::getInstance();

        if ($plugin->isPro()) {
            $approvals = $plugin->approvals->expireStale();
            $this->stdout("Approvals expired: {$approvals}\n", $approvals > 0 ? Console::FG_YELLOW : Console::FG_GREY);

            $quotes = $plugin->quotes->expireStale();
            $this->stdout("Quotes expired: {$quotes}\n", $quotes > 0 ? Console::FG_YELLOW : Console::FG_GREY);
        } else {
            $this->stdout("Approvals and quotes need Forklift Pro — skipped.\n", Console::FG_GREY);
        }

        $certificates = $plugin->certificates->syncExpired();
        $this->stdout("Companies whose exemption lapsed: {$certificates}\n", $certificates > 0 ? Console::FG_YELLOW : Console::FG_GREY);

        return ExitCode::OK;
    }

    /**
     * Certificates that have expired or are about to.
     *
     * @param int $days How far ahead to look.
     */
    public function actionExpiringCertificates(int $days = 30): int
    {
        $certificates = Plugin::getInstance()->certificates->getExpiring($days);

        if ($certificates === []) {
            $this->stdout("Nothing expiring within {$days} days.\n", Console::FG_GREEN);

            return ExitCode::OK;
        }

        foreach ($certificates as $certificate) {
            $remaining = $certificate->getDaysUntilExpiry();

            $this->stdout(sprintf(
                "%-30s %-12s %s\n",
                mb_substr((string)$certificate->name, 0, 30),
                $certificate->getScopeLabel(),
                $remaining !== null && $remaining < 0
                    ? 'expired ' . abs($remaining) . ' days ago'
                    : 'expires in ' . $remaining . ' days',
            ), $remaining !== null && $remaining < 0 ? Console::FG_RED : Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }

    /**
     * A one-screen answer to "is this store's B2B configuration sane".
     *
     * Every check here is something that produces no error and no log line, but quietly stops
     * Forklift doing what the merchant thinks it is doing.
     */
    public function actionDoctor(): int
    {
        $plugin = Plugin::getInstance();
        $problems = 0;

        $this->stdout("Forklift " . ($plugin->isPro() ? 'Pro' : 'Lite') . "\n\n", Console::FG_GREEN);

        foreach ($plugin->getSettings()->getHasSuppressedProSettings() as $line) {
            $this->stdout("  ! {$line}\n", Console::FG_YELLOW);
            $problems++;
        }

        // A company with an approval threshold and nobody able to approve strands every order
        // over it, silently, with no error anywhere.
        foreach (\justinholtweb\forklift\elements\Company::find()->status(null)->all() as $company) {
            if ($company->approvalThreshold !== null
                && $plugin->members->getApproversForCompany((int)$company->id) === []
            ) {
                $this->stdout("  ! {$company->getUiLabel()} needs approvals but has no approver.\n", Console::FG_RED);
                $problems++;
            }

            if ($company->creditEnabled && $company->creditLimit === null) {
                $this->stdout("  ! {$company->getUiLabel()} trades on terms with no credit limit at all.\n", Console::FG_YELLOW);
                $problems++;
            }

            if ($company->creditEnabled && $company->termsId === null) {
                $this->stdout("  ! {$company->getUiLabel()} trades on terms but has none set, so invoices have no due date.\n", Console::FG_YELLOW);
                $problems++;
            }
        }

        // An unmatched SKU prices nothing at all, and a 40% miss rate looks exactly like a
        // successful import.
        foreach ($plugin->priceLists->getAllPriceLists() as $list) {
            $unmatched = 0;

            foreach ($list->getEntries() as $entry) {
                if ($entry->targetType === 'purchasable' && !$entry->targetId) {
                    $unmatched++;
                }
            }

            if ($unmatched > 0) {
                $this->stdout("  ! {$list->handle}: {$unmatched} prices name a SKU this store does not have.\n", Console::FG_YELLOW);
                $problems++;
            }
        }

        $bypassed = $plugin->orders->getBypassedApprovalCount();

        if ($bypassed > 0) {
            $this->stdout("  ! {$bypassed} orders completed without the approval they needed, during a lapsed licence.\n", Console::FG_RED);
            $problems++;
        }

        $pending = $plugin->certificates->getTotalPending();

        if ($pending > 0) {
            $this->stdout("  ! {$pending} tax certificates are waiting for review and exempt nothing until approved.\n", Console::FG_YELLOW);
            $problems++;
        }

        if ($problems === 0) {
            $this->stdout("  Nothing to report.\n", Console::FG_GREEN);
        }

        return ExitCode::OK;
    }
}
