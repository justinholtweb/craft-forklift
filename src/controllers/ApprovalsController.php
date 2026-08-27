<?php

declare(strict_types=1);

namespace justinholtweb\forklift\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\forklift\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The approval queue in the control panel.
 *
 * A merchant-side screen, separate from the buyer-side one in the portal. Somebody at the store
 * can approve on a customer's behalf — a distributor's account manager doing it over the phone is
 * the common case — and the decision is recorded with their name on it either way.
 */
class ApprovalsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_MANAGE_APPROVALS);

        if (!Plugin::getInstance()->getSettings()->getEffectiveApprovalsEnabled()) {
            throw new ForbiddenHttpException(Craft::t('forklift', 'Approvals are not available on this edition.'));
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('forklift/approvals/_index', [
            'title' => Craft::t('forklift', 'Approvals'),
            'approvals' => $plugin->approvals->getAllPending(),
            'bypassedCount' => $plugin->orders->getBypassedApprovalCount(),
        ]);
    }

    public function actionDetail(int $approvalId): Response
    {
        $approval = Plugin::getInstance()->approvals->getApprovalById($approvalId);

        if ($approval === null) {
            throw new NotFoundHttpException('Approval not found');
        }

        return $this->renderTemplate('forklift/approvals/_detail', [
            'approval' => $approval,
            'order' => $approval->getOrder(),
            'company' => $approval->getCompany(),
            'title' => Craft::t('forklift', 'Approval #{id}', ['id' => $approval->id]),
        ]);
    }

    public function actionDecide(): Response
    {
        $this->requirePostRequest();

        $request = $this->request;
        $plugin = Plugin::getInstance();

        $approvalId = (int)$request->getRequiredBodyParam('approvalId');
        $decision = (string)$request->getRequiredBodyParam('decision');
        $note = $request->getBodyParam('note') ?: null;

        $approval = $plugin->approvals->getApprovalById($approvalId);

        if ($approval === null) {
            throw new NotFoundHttpException('Approval not found');
        }

        if (!$approval->getIsPending()) {
            // Somebody else got there first, or it lapsed. Said plainly rather than silently
            // overwriting a decision that has already been emailed to the buyer.
            return $this->asFailure(Craft::t('forklift', 'This request has already been {status}.', [
                'status' => strtolower($approval->getStatusLabel()),
            ]));
        }

        $user = Craft::$app->getUser()->getIdentity();

        $ok = $decision === 'approve'
            ? $plugin->approvals->approve($approval, $user, $note)
            : $plugin->approvals->decline($approval, $user, $note);

        if (!$ok) {
            return $this->asFailure(Craft::t('forklift', 'Couldn’t record the decision.'));
        }

        return $this->asSuccess(
            $decision === 'approve'
                ? Craft::t('forklift', 'Order approved.')
                : Craft::t('forklift', 'Order declined.'),
            [],
            \craft\helpers\UrlHelper::cpUrl('forklift/approvals'),
        );
    }
}
