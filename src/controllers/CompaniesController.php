<?php

declare(strict_types=1);

namespace justinholtweb\forklift\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\forklift\elements\Company;
use justinholtweb\forklift\models\Role;
use justinholtweb\forklift\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Company accounts in the control panel.
 *
 * The index is Craft's own element index — sources, search, bulk actions and custom sources for
 * free — so this only owns the edit screen and the save.
 */
class CompaniesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // `switch` is the front-end company switcher a buyer uses from the portal, not a CP screen:
        // it checks sign-in and membership itself. Before 5.1.0 it inherited the CP permission
        // below, so every buyer who belonged to more than one company got a 403.
        if ($action->id !== 'switch') {
            $this->requirePermission(Plugin::PERMISSION_VIEW_COMPANIES);
        }

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('forklift/companies/_index', [
            'title' => Craft::t('forklift', 'Companies'),
            'elementType' => Company::class,
        ]);
    }

    public function actionEdit(?int $companyId = null, ?Company $company = null): Response
    {
        $plugin = Plugin::getInstance();

        if ($company === null) {
            if ($companyId !== null) {
                $company = $plugin->companies->getCompanyById($companyId);

                if ($company === null) {
                    throw new NotFoundHttpException('Company not found');
                }
            } else {
                $this->requirePermission(Plugin::PERMISSION_MANAGE_COMPANIES);
                $company = new Company();
            }
        }

        return $this->renderTemplate('forklift/companies/_edit', [
            'company' => $company,
            'isNew' => !$company->id,
            'title' => $company->id
                ? $company->getUiLabel()
                : Craft::t('forklift', 'New company'),
            'termOptions' => $plugin->terms->getTermOptions(),
            'members' => $company->id ? $company->getMembers() : [],
            'certificates' => $company->id ? $company->getCertificates() : [],
            'priceListIds' => $company->id ? $plugin->priceLists->getPriceListIdsByCompanyId((int)$company->id) : [],
            'priceLists' => $plugin->priceLists->getAllPriceLists(),
            'roleOptions' => Role::options(),
            'statusOptions' => [
                ['label' => Craft::t('forklift', 'Active'), 'value' => Company::STATUS_ACTIVE],
                ['label' => Craft::t('forklift', 'On hold'), 'value' => Company::STATUS_HOLD],
                ['label' => Craft::t('forklift', 'Closed'), 'value' => Company::STATUS_CLOSED],
            ],
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_COMPANIES);

        $plugin = Plugin::getInstance();
        $request = $this->request;
        $companyId = $request->getBodyParam('companyId');

        if ($companyId) {
            $company = $plugin->companies->getCompanyById((int)$companyId);

            if ($company === null) {
                throw new NotFoundHttpException('Company not found');
            }
        } else {
            $company = new Company();
        }

        $company->title = $request->getBodyParam('title', $company->title);
        $company->code = $request->getBodyParam('code', $company->code) ?: null;
        $company->accountStatus = $request->getBodyParam('accountStatus', $company->accountStatus);
        $company->enabled = (bool)$request->getBodyParam('enabled', $company->enabled);
        $company->ownerId = $this->_firstId($request->getBodyParam('ownerId'));
        $company->termsId = $request->getBodyParam('termsId') ? (int)$request->getBodyParam('termsId') : null;
        $company->creditEnabled = (bool)$request->getBodyParam('creditEnabled');
        $company->requiresPoNumber = (bool)$request->getBodyParam('requiresPoNumber');
        $company->phone = $request->getBodyParam('phone') ?: null;
        $company->website = $request->getBodyParam('website') ?: null;
        $company->taxId = $request->getBodyParam('taxId') ?: null;
        $company->notes = $request->getBodyParam('notes') ?: null;

        // A blank number field means "not set", which is not zero. Zero on the threshold means
        // "every order needs approval" and zero on the credit limit means "no orders on account",
        // so collapsing the two would be an expensive convenience.
        $company->creditLimit = $this->_nullableFloat($request->getBodyParam('creditLimit'));
        $company->approvalThreshold = $this->_nullableFloat($request->getBodyParam('approvalThreshold'));

        $company->setFieldValuesFromRequest('fields');

        if (!$plugin->companies->saveCompany($company)) {
            $this->setFailFlash(Craft::t('forklift', 'Couldn’t save company.'));

            Craft::$app->getUrlManager()->setRouteParams(['company' => $company]);

            return null;
        }

        // Price-list assignment lives on the company screen as well as the list screen, because
        // "which lists does this customer get" is a question people ask from both directions.
        $priceListIds = $request->getBodyParam('priceListIds');

        if ($priceListIds !== null && $plugin->getSettings()->getEffectivePriceListsEnabled()) {
            $this->_setPriceLists((int)$company->id, array_map('intval', (array)$priceListIds));
        }

        $this->setSuccessFlash(Craft::t('forklift', 'Company saved.'));

        return $this->redirectToPostedUrl($company);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_DELETE_COMPANIES);

        $companyId = (int)$this->request->getRequiredBodyParam('companyId');
        $company = Plugin::getInstance()->companies->getCompanyById($companyId);

        if ($company === null) {
            throw new NotFoundHttpException('Company not found');
        }

        Plugin::getInstance()->companies->deleteCompany($company);

        if ($this->request->getAcceptsJson()) {
            return $this->asSuccess(Craft::t('forklift', 'Company deleted.'));
        }

        $this->setSuccessFlash(Craft::t('forklift', 'Company deleted.'));

        return $this->redirect('forklift/companies');
    }

    /** Put an account on hold, or take it off, from the index or the edit screen. */
    public function actionSetStatus(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_COMPANIES);

        $companyId = (int)$this->request->getRequiredBodyParam('companyId');
        $status = (string)$this->request->getRequiredBodyParam('accountStatus');

        $plugin = Plugin::getInstance();
        $company = $plugin->companies->getCompanyById($companyId);

        if ($company === null) {
            throw new NotFoundHttpException('Company not found');
        }

        if (!$plugin->companies->setAccountStatus($company, $status)) {
            return $this->asFailure(Craft::t('forklift', 'Couldn’t change the account status.'));
        }

        return $this->asSuccess(Craft::t('forklift', 'Account status changed.'), ['accountStatus' => $status]);
    }

    /**
     * Which company the *current visitor* is buying for.
     *
     * Lives here rather than in the portal controller because an administrator ordering on a
     * customer's behalf from the control panel needs the same switch. Anybody signed in may call
     * it; the service refuses a company they are not a member of.
     */
    public function actionSwitch(): Response
    {
        $this->requirePostRequest();
        $this->requireLogin();

        $companyId = $this->request->getBodyParam('companyId');
        $companyId = $companyId === null || $companyId === '' ? null : (int)$companyId;

        if (!Plugin::getInstance()->companies->setCurrentCompany($companyId)) {
            throw new ForbiddenHttpException('You are not a member of that company.');
        }

        if ($this->request->getAcceptsJson()) {
            return $this->asSuccess(Craft::t('forklift', 'Company changed.'), ['companyId' => $companyId]);
        }

        return $this->redirectToPostedUrl();
    }

    private function _setPriceLists(int $companyId, array $priceListIds): void
    {
        $plugin = Plugin::getInstance();

        foreach ($plugin->priceLists->getAllPriceLists() as $list) {
            $assigned = $plugin->priceLists->getCompanyIdsByPriceListId((int)$list->id);
            $wanted = in_array((int)$list->id, $priceListIds, true);
            $has = in_array($companyId, $assigned, true);

            if ($wanted === $has) {
                continue;
            }

            $assigned = $wanted
                ? array_merge($assigned, [$companyId])
                : array_values(array_diff($assigned, [$companyId]));

            $plugin->priceLists->setCompaniesForPriceList((int)$list->id, $assigned);
        }
    }

    /** Craft's element select posts an array; a plain input posts a scalar. */
    private function _firstId(mixed $value): ?int
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        return $value ? (int)$value : null;
    }

    private function _nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        return (float)$value;
    }
}
