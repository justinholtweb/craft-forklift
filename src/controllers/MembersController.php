<?php

declare(strict_types=1);

namespace justinholtweb\forklift\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\forklift\models\Member;
use justinholtweb\forklift\models\Role;
use justinholtweb\forklift\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Buyers on a company account, in the control panel.
 *
 * Adding somebody by email address rather than only by user picker is deliberate: the person a
 * merchant wants to add usually already has an account they created at checkout, and making the
 * merchant find them in an element selector is a worse experience than typing the address they
 * were just given on the telephone.
 */
class MembersController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_MANAGE_MEMBERS);

        return true;
    }

    public function actionIndex(int $companyId): Response
    {
        $plugin = Plugin::getInstance();
        $company = $plugin->companies->getCompanyById($companyId);

        if ($company === null) {
            throw new NotFoundHttpException('Company not found');
        }

        return $this->renderTemplate('forklift/members/_index', [
            'company' => $company,
            'members' => $company->getMembers(),
            'roleOptions' => Role::options(),
            'title' => Craft::t('forklift', 'Buyers — {company}', ['company' => $company->getUiLabel()]),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = $this->request;
        $plugin = Plugin::getInstance();

        $companyId = (int)$request->getRequiredBodyParam('companyId');
        $memberId = $request->getBodyParam('memberId');

        $member = $memberId
            ? $plugin->members->getMemberById((int)$memberId)
            : new Member(['companyId' => $companyId]);

        if ($member === null) {
            throw new NotFoundHttpException('Member not found');
        }

        // An existing member's user is not editable — that would be a different membership.
        if (!$member->id) {
            $userId = $this->_resolveUser($request->getBodyParam('userId'), $request->getBodyParam('email'));

            if ($userId === null) {
                return $this->asModelFailure(
                    $member,
                    Craft::t('forklift', 'No user was found with that email address.'),
                    'member',
                );
            }

            $member->userId = $userId;
        }

        $member->role = (string)$request->getBodyParam('role', $member->role);
        $member->requiresApproval = (bool)$request->getBodyParam('requiresApproval');
        $member->isDefault = (bool)$request->getBodyParam('isDefault');

        $spendLimit = $request->getBodyParam('spendLimit');
        $member->spendLimit = ($spendLimit === null || $spendLimit === '') ? null : (float)$spendLimit;

        if (!$plugin->members->saveMember($member)) {
            return $this->asModelFailure($member, Craft::t('forklift', 'Couldn’t save buyer.'), 'member');
        }

        return $this->asModelSuccess($member, Craft::t('forklift', 'Buyer saved.'), 'member', [
            'redirect' => $this->request->getValidatedBodyParam('redirect')
                ?? \craft\helpers\UrlHelper::cpUrl('forklift/companies/' . $companyId . '/members'),
        ]);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $memberId = (int)$this->request->getRequiredBodyParam('memberId');

        if (!Plugin::getInstance()->members->deleteMemberById($memberId)) {
            // The only reason a delete is refused: it would leave the account with nobody able to
            // administer it. Said plainly, because the alternative is a silent no-op.
            return $this->asFailure(Craft::t('forklift', 'A company needs at least one administrator.'));
        }

        return $this->asSuccess(Craft::t('forklift', 'Buyer removed.'));
    }

    /**
     * A user id from the picker, or an email address typed in.
     *
     * Never creates a user. Inviting somebody who has no account is a different feature with
     * different consequences — an activation email, a password, a public form — and quietly
     * creating accounts from a control-panel text field is how a store ends up with sixty
     * half-made users.
     */
    private function _resolveUser(mixed $userId, ?string $email): ?int
    {
        if (is_array($userId)) {
            $userId = reset($userId);
        }

        if ($userId) {
            return (int)$userId;
        }

        if (!$email) {
            return null;
        }

        return Craft::$app->getUsers()->getUserByUsernameOrEmail(trim($email))?->id;
    }
}
