<?php

declare(strict_types=1);

namespace justinholtweb\forklift\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\DateTimeHelper;
use justinholtweb\forklift\elements\Company;
use justinholtweb\forklift\Plugin;
use yii\console\ExitCode;

/**
 * The credit ledger from the command line.
 *
 * `payments` exists because remittances arrive as a bank file, not as somebody typing forty
 * numbers into a control panel; `repair` exists because post-completion bookkeeping is
 * deliberately wrapped in a `try` so that a failure cannot take down the request a customer is
 * looking at — which means a failure leaves an invoice unraised, and something has to put it
 * right.
 */
class CreditController extends Controller
{
    /** Show what would happen without writing anything. */
    public bool $dryRun = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'payments', 'repair' => ['dryRun'],
            default => [],
        });
    }

    /** Every account on terms, its balance and what is overdue. */
    public function actionIndex(): int
    {
        $plugin = Plugin::getInstance();
        $companies = Company::find()->creditEnabled(true)->status(null)->all();

        if ($companies === []) {
            $this->stdout("No accounts are trading on terms.\n");

            return ExitCode::OK;
        }

        foreach ($companies as $company) {
            $statement = $plugin->credit->statement((int)$company->id);
            $remaining = $statement->getCreditRemaining();

            $this->stdout(sprintf(
                "%-16s %-28s balance %12.2f  available %12s  overdue %10.2f\n",
                (string)$company->code,
                mb_substr($company->getUiLabel(), 0, 28),
                $statement->closingBalance,
                $remaining === null ? 'no limit' : number_format($remaining, 2),
                $statement->getTotalOverdue(),
            ), $statement->getIsOverLimit() ? Console::FG_RED : Console::FG_GREY);
        }

        return ExitCode::OK;
    }

    /**
     * Print one account's statement.
     *
     * @param string $companyCode The account code.
     */
    public function actionStatement(string $companyCode): int
    {
        $plugin = Plugin::getInstance();
        $company = $plugin->companies->getCompanyByCode($companyCode);

        if ($company === null) {
            $this->stderr("No company with the code “{$companyCode}”.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $statement = $plugin->credit->statement((int)$company->id);

        $this->stdout($company->getUiLabel() . "\n", Console::FG_GREEN);
        $this->stdout(sprintf("  opening %12.2f\n", $statement->openingBalance));

        foreach ($statement->entries as $entry) {
            $this->stdout(sprintf(
                "  %-10s %-10s %-20s %12.2f%s\n",
                $entry->entryDate?->format('Y-m-d') ?? '',
                $entry->type,
                mb_substr((string)$entry->reference, 0, 20),
                $entry->amount,
                $entry->getIsOverdue() ? '  overdue ' . $entry->getDaysOverdue() . 'd' : '',
            ));
        }

        $this->stdout(sprintf("  closing %12.2f\n", $statement->closingBalance));

        foreach ($statement->aging as $days => $amount) {
            $this->stdout(sprintf("  %-16s %12.2f\n", $statement->agingLabels[$days] ?? $days, $amount));
        }

        return ExitCode::OK;
    }

    /**
     * Import remittances: a CSV of `accountCode,amount,reference,date`.
     *
     * Amounts are given positive — "we received 4,000" — and stored negative, because asking every
     * bank export to remember the sign is asking for one of them to forget.
     *
     * @param string $file Path to the CSV.
     */
    public function actionPayments(string $file): int
    {
        if (!is_readable($file)) {
            $this->stderr("Cannot read {$file}.\n", Console::FG_RED);

            return ExitCode::NOINPUT;
        }

        $plugin = Plugin::getInstance();
        $handle = fopen($file, 'r');
        $line = 0;
        $applied = 0;
        $skipped = 0;

        while (($cells = fgetcsv($handle, 4096)) !== false) {
            $line++;

            if ($cells === [null] || $cells === false) {
                continue;
            }

            $cells = array_map(static fn($cell) => trim((string)$cell), $cells);

            if (implode('', $cells) === '') {
                continue;
            }

            if ($line === 1 && !is_numeric($cells[1] ?? '')) {
                continue;
            }

            $company = $plugin->companies->getCompanyByCode($cells[0] ?? '');
            $amount = (float)str_replace([',', ' '], '', $cells[1] ?? '0');

            if ($company === null || $amount <= 0) {
                $this->stdout("  line {$line}: skipped\n", Console::FG_YELLOW);
                $skipped++;
                continue;
            }

            if ($this->dryRun) {
                $this->stdout(sprintf("  %s %.2f\n", $company->code, $amount));
                $applied++;
                continue;
            }

            $date = ($cells[3] ?? '') !== '' ? DateTimeHelper::toDateTime($cells[3]) ?: null : null;
            $plugin->credit->recordPayment((int)$company->id, $amount, $cells[2] ?? null, $date ?: null);
            $applied++;
        }

        fclose($handle);

        $this->stdout(
            ($this->dryRun ? "Dry run: " : '') . "{$applied} payments, {$skipped} skipped.\n",
            $this->dryRun ? Console::FG_YELLOW : Console::FG_GREEN,
        );

        return ExitCode::OK;
    }

    /**
     * Raise charges for completed on-account orders that have none.
     *
     * Idempotent — an order that already has its charge is left alone — so it is safe to run on a
     * schedule, and safe to run twice while somebody is watching it.
     */
    public function actionRepair(): int
    {
        $plugin = Plugin::getInstance();
        $commerce = \craft\commerce\Plugin::getInstance();

        if ($commerce === null) {
            $this->stderr("Commerce is not installed.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $orderIds = (new \craft\db\Query())
            ->select(['orderId'])
            ->from([\justinholtweb\forklift\db\Table::ORDERS])
            ->where(['not', ['companyId' => null]])
            ->column();

        $raised = 0;

        foreach ($orderIds as $orderId) {
            $order = $commerce->getOrders()->getOrderById((int)$orderId);

            if ($order === null || !$order->isCompleted) {
                continue;
            }

            if (!$order->getGateway() instanceof \justinholtweb\forklift\gateways\PurchaseOrder) {
                continue;
            }

            if ($plugin->credit->getChargeForOrder((int)$orderId) !== null) {
                continue;
            }

            $this->stdout("  {$order->reference}: no charge on the ledger\n", Console::FG_YELLOW);

            if (!$this->dryRun) {
                $plugin->credit->chargeOrder($order);
            }

            $raised++;
        }

        $this->stdout(
            ($this->dryRun ? 'Dry run: ' : '') . "{$raised} charges " . ($this->dryRun ? 'would be raised' : 'raised') . ".\n",
            Console::FG_GREEN,
        );

        return ExitCode::OK;
    }
}
