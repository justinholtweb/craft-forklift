<?php

declare(strict_types=1);

namespace justinholtweb\forklift\controllers;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\web\Controller;
use justinholtweb\forklift\elements\Company;
use justinholtweb\forklift\models\Certificate;
use justinholtweb\forklift\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Tax exemption certificates in the control panel.
 *
 * Approving one is its own action rather than a status dropdown on the edit form, because
 * approval is the moment somebody takes responsibility for a document — it deserves a button that
 * says what it does, not a select that can be changed by accident while fixing a typo in the
 * certificate number.
 */
class CertificatesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_MANAGE_CERTIFICATES);

        return true;
    }

    public function actionIndex(?int $companyId = null): Response
    {
        $plugin = Plugin::getInstance();
        $company = $companyId ? $plugin->companies->getCompanyById($companyId) : null;

        if ($companyId && $company === null) {
            throw new NotFoundHttpException('Company not found');
        }

        $warningDays = $plugin->getSettings()->certificateExpiryWarningDays;

        return $this->renderTemplate('forklift/certificates/_index', [
            'title' => $company
                ? Craft::t('forklift', 'Tax certificates — {company}', ['company' => $company->getUiLabel()])
                : Craft::t('forklift', 'Tax certificates'),
            'company' => $company,
            'certificates' => $company
                ? $company->getCertificates()
                : $this->_allCertificates(),
            // Resolved here rather than in the template: a company lookup inside a Twig loop is
            // both a query per row and a null waiting to have a method called on it.
            'companyNames' => $this->_companyNames(),
            'expiring' => $warningDays > 0 ? $plugin->certificates->getExpiring($warningDays) : [],
            'pendingCount' => $plugin->certificates->getTotalPending(),
        ]);
    }

    public function actionEdit(?int $certificateId = null, ?Certificate $certificate = null, ?int $companyId = null): Response
    {
        $plugin = Plugin::getInstance();

        if ($certificate === null) {
            $certificate = $certificateId !== null
                ? $plugin->certificates->getCertificateById($certificateId)
                : new Certificate(['companyId' => $companyId]);

            if ($certificate === null) {
                throw new NotFoundHttpException('Certificate not found');
            }
        }

        return $this->renderTemplate('forklift/certificates/_edit', [
            'certificate' => $certificate,
            'isNew' => !$certificate->id,
            'company' => $certificate->companyId ? $plugin->companies->getCompanyById((int)$certificate->companyId) : null,
            'title' => $certificate->id
                ? (string)$certificate->name
                : Craft::t('forklift', 'New tax certificate'),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = $this->request;
        $plugin = Plugin::getInstance();
        $certificateId = $request->getBodyParam('certificateId');

        $certificate = $certificateId
            ? $plugin->certificates->getCertificateById((int)$certificateId)
            : new Certificate();

        if ($certificate === null) {
            throw new NotFoundHttpException('Certificate not found');
        }

        $certificate->companyId = $this->_firstId($request->getBodyParam('companyId')) ?? $certificate->companyId;
        $certificate->name = $request->getBodyParam('name');
        $certificate->certificateNumber = $request->getBodyParam('certificateNumber') ?: null;
        $certificate->countryCode = $request->getBodyParam('countryCode') ?: null;
        $certificate->administrativeArea = $request->getBodyParam('administrativeArea') ?: null;
        $certificate->issueDate = $this->_date($request->getBodyParam('issueDate'));
        $certificate->expiryDate = $this->_date($request->getBodyParam('expiryDate'));
        $certificate->assetId = $this->_firstId($request->getBodyParam('assetId'));
        $certificate->note = $request->getBodyParam('note') ?: null;

        // The status is only settable here for a new record, and only to `pending`. Approving is
        // its own action so that it is always a deliberate act.
        if (!$certificate->id) {
            $certificate->status = Certificate::STATUS_PENDING;
        }

        // Approving and rejecting are buttons on this same form rather than a status dropdown,
        // because approval is the moment somebody takes responsibility for a document — and
        // because a second <form> inside Craft's full-page form would silently post the wrong one.
        $decision = $this->request->getBodyParam('decision');

        if ($decision === 'approve' && !$certificate->getIsExpired()) {
            $certificate->status = Certificate::STATUS_APPROVED;
        } elseif ($decision === 'reject') {
            $certificate->status = Certificate::STATUS_REJECTED;
        }

        if (!$plugin->certificates->saveCertificate($certificate)) {
            $this->setFailFlash(Craft::t('forklift', 'Couldn’t save certificate.'));
            Craft::$app->getUrlManager()->setRouteParams(['certificate' => $certificate]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('forklift', 'Certificate saved.'));

        return $this->redirectToPostedUrl($certificate);
    }

    public function actionApprove(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $certificate = $plugin->certificates->getCertificateById(
            (int)$this->request->getRequiredBodyParam('certificateId'),
        );

        if ($certificate === null) {
            throw new NotFoundHttpException('Certificate not found');
        }

        if ($certificate->getIsExpired()) {
            return $this->asFailure(Craft::t('forklift', 'That certificate has already expired.'));
        }

        $plugin->certificates->approve($certificate);

        return $this->asSuccess(Craft::t('forklift', 'Certificate approved. Tax will not be charged on covered orders.'));
    }

    public function actionReject(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $certificate = $plugin->certificates->getCertificateById(
            (int)$this->request->getRequiredBodyParam('certificateId'),
        );

        if ($certificate === null) {
            throw new NotFoundHttpException('Certificate not found');
        }

        $plugin->certificates->reject($certificate, $this->request->getBodyParam('note'));

        return $this->asSuccess(Craft::t('forklift', 'Certificate rejected.'));
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $certificateId = (int)$this->request->getRequiredBodyParam('certificateId');
        Plugin::getInstance()->certificates->deleteCertificateById($certificateId);

        return $this->asSuccess(Craft::t('forklift', 'Certificate deleted.'));
    }

    /** @return array<int, string> Company id => name. */
    private function _companyNames(): array
    {
        $names = [];

        foreach (Company::find()->status(null)->all() as $company) {
            $names[(int)$company->id] = $company->getUiLabel();
        }

        return $names;
    }

    /** @return Certificate[] */
    private function _allCertificates(): array
    {
        $plugin = Plugin::getInstance();
        $out = [];

        foreach (Company::find()->status(null)->all() as $company) {
            foreach ($plugin->certificates->getCertificatesByCompanyId((int)$company->id) as $certificate) {
                $out[] = $certificate;
            }
        }

        // Pending first — that is the queue somebody opened this screen to work through.
        usort($out, static function(Certificate $a, Certificate $b) {
            $rank = static fn(Certificate $c) => $c->getEffectiveStatus() === Certificate::STATUS_PENDING ? 0 : 1;

            return [$rank($a), $b->id] <=> [$rank($b), $a->id];
        });

        return $out;
    }

    private function _firstId(mixed $value): ?int
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        return $value ? (int)$value : null;
    }

    private function _date(mixed $value): ?\DateTime
    {
        return $value ? (DateTimeHelper::toDateTime($value) ?: null) : null;
    }
}
