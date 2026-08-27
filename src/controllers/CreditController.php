<?php

declare(strict_types=1);

namespace justinholtweb\forklift\controllers;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\web\Controller;
use justinholtweb\forklift\models\Statement;
use justinholtweb\forklift\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Credit and statements in the control panel — the credit controller's screens.
 *
 * The overview lists accounts over their limit first, because that is the list somebody is
 * actually opening this to see. Recording a payment is behind its own permission, separate from
 * seeing the balance: plenty of organisations want a salesperson to know that an account is on
 * stop without letting them clear it.
 */
class CreditController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_VIEW_CREDIT);

        if (!Plugin::getInstance()->getSettings()->getEffectiveCreditEnabled()) {
            throw new ForbiddenHttpException(Craft::t('forklift', 'Credit is not available on this edition.'));
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $over = $plugin->credit->getCompaniesOverLimit();
        $companies = [];

        foreach ($over as $row) {
            $company = $plugin->companies->getCompanyById($row['companyId']);

            if ($company !== null) {
                $companies[] = ['company' => $company] + $row;
            }
        }

        return $this->renderTemplate('forklift/credit/_index', [
            'title' => Craft::t('forklift', 'Credit'),
            'overLimit' => $companies,
            'onTerms' => \justinholtweb\forklift\elements\Company::find()
                ->creditEnabled(true)
                ->status(null)
                ->all(),
        ]);
    }

    public function actionCompany(int $companyId): Response
    {
        $plugin = Plugin::getInstance();
        $company = $plugin->companies->getCompanyById($companyId);

        if ($company === null) {
            throw new NotFoundHttpException('Company not found');
        }

        return $this->renderTemplate('forklift/credit/_company', [
            'company' => $company,
            'statement' => $plugin->credit->statement($companyId),
            'title' => Craft::t('forklift', 'Credit — {company}', ['company' => $company->getUiLabel()]),
        ]);
    }

    /**
     * A statement for a period, as HTML or CSV.
     *
     * HTML rather than PDF, deliberately. Commerce ships dompdf, so a PDF would cost no new
     * dependency — but it would cost a rendering pipeline, a template nobody can restyle without
     * fighting dompdf's CSS support, and an attack surface that has had its own advisories. A
     * printable page and a CSV do what a customer chasing an invoice actually needs, and a store
     * that wants a branded PDF already has Commerce's own PDF machinery to point at it.
     */
    public function actionStatement(int $companyId): Response
    {
        $plugin = Plugin::getInstance();
        $company = $plugin->companies->getCompanyById($companyId);

        if ($company === null) {
            throw new NotFoundHttpException('Company not found');
        }

        $from = $this->_date($this->request->getParam('from'));
        $to = $this->_date($this->request->getParam('to'));
        $statement = $plugin->credit->statement($companyId, $from, $to);

        if ($this->request->getParam('format') === 'csv') {
            return $this->response->sendContentAsFile(
                $this->_csv($statement),
                'statement-' . ($company->code ?: $company->id) . '.csv',
                ['mimeType' => 'text/csv'],
            );
        }

        return $this->renderTemplate('forklift/credit/_statement', [
            'company' => $company,
            'statement' => $statement,
            'title' => Craft::t('forklift', 'Statement — {company}', ['company' => $company->getUiLabel()]),
        ]);
    }

    public function actionRecordPayment(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_CREDIT);

        $request = $this->request;
        $companyId = (int)$request->getRequiredBodyParam('companyId');
        $amount = (float)$request->getRequiredBodyParam('amount');

        if ($amount <= 0) {
            return $this->asFailure(Craft::t('forklift', 'A payment must be more than zero.'));
        }

        $entry = Plugin::getInstance()->credit->recordPayment(
            $companyId,
            $amount,
            $request->getBodyParam('reference') ?: null,
            $this->_date($request->getBodyParam('entryDate')),
        );

        if ($entry === null) {
            return $this->asFailure(Craft::t('forklift', 'Couldn’t record the payment.'));
        }

        return $this->asSuccess(Craft::t('forklift', 'Payment recorded.'));
    }

    public function actionRecordAdjustment(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_CREDIT);

        $request = $this->request;
        $companyId = (int)$request->getRequiredBodyParam('companyId');
        $amount = (float)$request->getRequiredBodyParam('amount');

        if ($amount === 0.0) {
            return $this->asFailure(Craft::t('forklift', 'An adjustment of zero would change nothing.'));
        }

        $entry = Plugin::getInstance()->credit->recordAdjustment(
            $companyId,
            $amount,
            $request->getBodyParam('note') ?: null,
            $this->_date($request->getBodyParam('entryDate')),
        );

        if ($entry === null) {
            return $this->asFailure(Craft::t('forklift', 'Couldn’t record the adjustment.'));
        }

        return $this->asSuccess(Craft::t('forklift', 'Adjustment recorded.'));
    }

    public function actionDeleteEntry(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_CREDIT);

        $entryId = (int)$this->request->getRequiredBodyParam('entryId');

        if (!Plugin::getInstance()->credit->deleteEntryById($entryId)) {
            return $this->asFailure(Craft::t('forklift', 'Couldn’t delete that entry.'));
        }

        return $this->asSuccess(Craft::t('forklift', 'Ledger entry deleted.'));
    }

    private function _csv(Statement $statement): string
    {
        $out = "date,type,reference,due,amount,note\n";

        foreach ($statement->entries as $entry) {
            $out .= sprintf(
                "%s,%s,\"%s\",%s,%s,\"%s\"\n",
                $entry->entryDate?->format('Y-m-d') ?? '',
                $entry->type,
                str_replace('"', '""', (string)$entry->reference),
                $entry->dueDate?->format('Y-m-d') ?? '',
                number_format($entry->amount, 2, '.', ''),
                str_replace('"', '""', (string)$entry->note),
            );
        }

        $out .= sprintf("\nopening,,,,%s,\n", number_format($statement->openingBalance, 2, '.', ''));
        $out .= sprintf("closing,,,,%s,\n", number_format($statement->closingBalance, 2, '.', ''));

        return $out;
    }

    private function _date(mixed $value): ?\DateTime
    {
        return $value ? (DateTimeHelper::toDateTime($value) ?: null) : null;
    }
}
