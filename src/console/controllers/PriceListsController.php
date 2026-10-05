<?php

declare(strict_types=1);

namespace justinholtweb\forklift\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\forklift\models\PriceList;
use justinholtweb\forklift\models\PriceListEntry;
use justinholtweb\forklift\Plugin;
use yii\console\ExitCode;

/**
 * Price lists from the command line — which is where a ten-thousand-row import belongs.
 *
 * The control-panel importer exists for convenience; this exists because a real wholesale
 * catalogue arrives nightly from an ERP over SFTP, and a cron job should not be driving a web
 * form.
 */
class PriceListsController extends Controller
{
    /** The price list handle to work on. */
    public ?string $list = null;

    /** Show what would happen without writing anything. */
    public bool $dryRun = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'import' => ['list', 'dryRun'],
            'export', 'resolve-skus' => ['list'],
            default => [],
        });
    }

    /** List the price lists and how many prices each holds. */
    public function actionIndex(): int
    {
        $plugin = Plugin::getInstance();
        $lists = $plugin->priceLists->getAllPriceLists();

        if ($lists === []) {
            $this->stdout("No price lists.\n");

            return ExitCode::OK;
        }

        foreach ($lists as $list) {
            $this->stdout(sprintf(
                "%-24s %-8s priority %-4d %6d prices  %s\n",
                $list->handle,
                $list->allCompanies ? 'all' : count($list->getCompanyIds()) . ' cos',
                $list->priority,
                $plugin->priceLists->getEntryCount((int)$list->id),
                $list->getIsActive() ? 'active' : 'inactive',
            ));
        }

        return ExitCode::OK;
    }

    /**
     * Import a CSV of prices, replacing everything in the list.
     *
     * A replace rather than a merge: the file is the contract, and a row deleted from it should
     * not survive in the store because nothing mentioned it.
     *
     * @param string $file Path to a CSV of `sku,price,minQty`.
     */
    public function actionImport(string $file): int
    {
        if ($this->list === null) {
            $this->stderr("A --list handle is needed.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        if (!is_readable($file)) {
            $this->stderr("Cannot read {$file}.\n", Console::FG_RED);

            return ExitCode::NOINPUT;
        }

        $plugin = Plugin::getInstance();
        $priceList = $plugin->priceLists->getPriceListByHandle($this->list);

        if ($priceList === null) {
            $this->stderr("No price list with the handle “{$this->list}”.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        [$entries, $skipped] = $this->_parse((string)file_get_contents($file));

        $this->stdout(sprintf("%d prices read, %d rows skipped.\n", count($entries), $skipped));

        if ($this->dryRun) {
            $this->stdout("Dry run — nothing written.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $count = $plugin->priceLists->replaceEntries((int)$priceList->id, $entries);
        $resolved = $plugin->priceLists->resolveSkus((int)$priceList->id);
        $plugin->pricing->clearCaches();

        $this->stdout("{$count} prices imported, {$resolved} matched to products.\n", Console::FG_GREEN);

        if ($resolved < $count) {
            // Said out loud rather than left for somebody to discover: an unmatched row prices
            // nothing at all, and a silent 40% miss rate looks exactly like a successful import.
            $this->stdout(sprintf(
                "%d rows did not match a SKU in this store and will not price anything.\n",
                $count - $resolved,
            ), Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }

    /** Write a price list out as the CSV the importer reads back. */
    public function actionExport(?string $file = null): int
    {
        if ($this->list === null) {
            $this->stderr("A --list handle is needed.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $priceList = Plugin::getInstance()->priceLists->getPriceListByHandle($this->list);

        if ($priceList === null) {
            $this->stderr("No price list with the handle “{$this->list}”.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $out = "sku,price,minQty\n";

        foreach ($priceList->getEntries() as $entry) {
            $price = $entry->priceType === PriceListEntry::PRICE_PERCENT_OFF
                ? rtrim(rtrim(number_format($entry->amount, 4, '.', ''), '0'), '.') . '%'
                : number_format($entry->amount, 4, '.', '');

            $out .= sprintf("%s,%s,%d\n", (string)($entry->sku ?? ''), $price, $entry->minQty);
        }

        if ($file === null) {
            $this->stdout($out);
        } else {
            file_put_contents($file, $out);
            $this->stdout("Written to {$file}.\n", Console::FG_GREEN);
        }

        return ExitCode::OK;
    }

    /**
     * Match SKU-only rows to products.
     *
     * Worth running after a catalogue rebuild: a variant that was deleted and recreated has a new
     * id, and a price list pointing at the old one silently prices nothing.
     */
    public function actionResolveSkus(): int
    {
        $plugin = Plugin::getInstance();
        $lists = $this->list !== null
            ? array_filter([$plugin->priceLists->getPriceListByHandle($this->list)])
            : $plugin->priceLists->getAllPriceLists();

        $total = 0;

        foreach ($lists as $list) {
            $resolved = $plugin->priceLists->resolveSkus((int)$list->id);
            $total += $resolved;
            $this->stdout("{$list->handle}: {$resolved} matched\n");
        }

        $plugin->pricing->clearCaches();
        $this->stdout("{$total} rows matched.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * What a company pays for a SKU — the resolver, from the command line.
     *
     * @param string $sku The SKU to price.
     * @param string|null $companyCode The account code, or nothing for the public price.
     * @param int $qty The quantity.
     */
    public function actionPrice(string $sku, ?string $companyCode = null, int $qty = 1): int
    {
        $plugin = Plugin::getInstance();
        $purchasable = $plugin->quickOrder->findBySku($sku);

        if ($purchasable === null) {
            $this->stderr("No product has the SKU “{$sku}”.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $companyId = null;

        if ($companyCode !== null) {
            $company = $plugin->companies->getCompanyByCode($companyCode);

            if ($company === null) {
                $this->stderr("No company with the code “{$companyCode}”.\n", Console::FG_RED);

                return ExitCode::DATAERR;
            }

            $companyId = (int)$company->id;
        }

        $result = $plugin->pricing->resolve($purchasable, $qty, $companyId);

        $this->stdout(sprintf(
            "%s x%d\n  price  %.4f\n  list   %.4f\n  saving %.4f (%.2f%%)\n  why    %s\n",
            $purchasable->getSku(),
            $qty,
            $result->price,
            $result->listPrice,
            $result->getSaving(),
            $result->getSavingPercent(),
            $result->getSourceLabel(),
        ));

        if ($result->nextBreak !== null) {
            $this->stdout(sprintf("  next   %d+ at %.4f\n", $result->nextBreak['qty'], $result->nextBreak['price']));
        }

        if ($result->suppressedReason !== null) {
            $this->stdout('  note   ' . $result->suppressedReason . "\n", Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }

    /**
     * Create a price list.
     *
     * @param string $name Its name.
     * @param string $handle Its handle.
     */
    public function actionCreate(string $name, string $handle): int
    {
        $list = new PriceList(['name' => $name, 'handle' => $handle]);

        if (!Plugin::getInstance()->priceLists->savePriceList($list)) {
            foreach ($list->getErrors() as $attribute => $errors) {
                $this->stderr("{$attribute}: " . implode(', ', $errors) . "\n", Console::FG_RED);
            }

            return ExitCode::DATAERR;
        }

        $this->stdout("Created price list #{$list->id}.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /** @return array{0: PriceListEntry[], 1: int} */
    private function _parse(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $contents);
        rewind($handle);

        $entries = [];
        $skipped = 0;
        $line = 0;

        while (($cells = fgetcsv($handle, 4096)) !== false) {
            $line++;

            if ($cells === [null]) {
                continue;
            }

            $cells = array_map(static fn($cell) => trim((string)$cell), $cells);

            if (implode('', $cells) === '') {
                continue;
            }

            if ($line === 1 && in_array(strtolower($cells[0]), ['sku', 'code', 'part', 'item'], true)) {
                continue;
            }

            if (($cells[0] ?? '') === '' || ($cells[1] ?? '') === '') {
                $skipped++;
                continue;
            }

            $isPercent = str_ends_with($cells[1], '%');

            $entries[] = new PriceListEntry([
                'targetType' => PriceListEntry::TARGET_PURCHASABLE,
                'sku' => $cells[0],
                'minQty' => max(1, (int)($cells[2] ?? 1)),
                'priceType' => $isPercent ? PriceListEntry::PRICE_PERCENT_OFF : PriceListEntry::PRICE_FIXED,
                'amount' => (float)str_replace(['%', ',', ' '], '', $cells[1]),
            ]);
        }

        fclose($handle);

        return [$entries, $skipped];
    }
}
