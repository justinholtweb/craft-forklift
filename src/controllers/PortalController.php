<?php

declare(strict_types=1);

namespace justinholtweb\forklift\controllers;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\web\Controller;
use craft\web\View;
use justinholtweb\forklift\elements\Quote;
use justinholtweb\forklift\models\Member;
use justinholtweb\forklift\models\Role;
use justinholtweb\forklift\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The buyer's side: the company portal, quote requests, and approvals by email link.
 *
 * ## The templates are the store's, not Forklift's
 *
 * Every screen here renders a **site** template with a documented variable, falling back to a
 * plain one the plugin ships. A B2B portal has to look like the shop it is part of, and a plugin
 * that insists on its own markup gets replaced by a fortnight of overrides.
 *
 * ## Authorisation
 *
 * Every action re-derives the company from the signed-in user's memberships. Nothing trusts a
 * posted `companyId` — a portal is exactly the place where an incremented id in a form field
 * would show one customer another customer's statement.
 */
class PortalController extends Controller
{
    protected array|bool|int $allowAnonymous = ['approval'];

    public function actionIndex(): Response
    {
        $this->requireLogin();

        $plugin = Plugin::getInstance();
        $company = $plugin->companies->getCurrentCompany();
        $member = $plugin->companies->getCurrentMember();

        return $this->_render('index', [
            'company' => $company,
            'member' => $member,
            'companies' => $plugin->companies->getCompaniesForUser(Craft::$app->getUser()->getIdentity()),
            'balance' => $this->_financials($member) ? $plugin->credit->balanceFor((int)$company->id) : null,
            'creditAvailable' => $this->_financials($member) ? $plugin->credit->availableFor((int)$company->id) : null,
            'pendingApprovals' => $plugin->approvals->getPendingForApprover(Craft::$app->getUser()->getIdentity()),
        ]);
    }

    public function actionOrders(): Response
    {
        $this->requireLogin();

        [$company] = $this->_requireMembership();
        $plugin = Plugin::getInstance();
        $ids = $plugin->orders->getOrderIdsForCompany((int)$company->id, 100);

        return $this->_render('orders', [
            'company' => $company,
            'orders' => $ids === []
                ? []
                : \craft\commerce\elements\Order::find()->id($ids)->isCompleted(true)->fixedOrder(true)->all(),
        ]);
    }

    public function actionQuotes(): Response
    {
        $this->requireLogin();

        [$company] = $this->_requireMembership();

        return $this->_render('quotes', [
            'company' => $company,
            'quotes' => Quote::find()->companyId($company->id)->status(null)->all(),
        ]);
    }

    public function actionStatement(): Response
    {
        $this->requireLogin();

        [$company, $member] = $this->_requireMembership();

        if (!$member->getCanSeeFinancials()) {
            throw new ForbiddenHttpException(Craft::t('forklift', 'Your role does not include the account’s finances.'));
        }

        return $this->_render('statement', [
            'company' => $company,
            'statement' => Plugin::getInstance()->credit->statement((int)$company->id),
        ]);
    }

    public function actionBuyers(): Response
    {
        $this->requireLogin();

        [$company, $member] = $this->_requireMembership();

        return $this->_render('buyers', [
            'company' => $company,
            'member' => $member,
            'members' => $company->getMembers(),
            'canManage' => $member->getCanManageMembers()
                && Plugin::getInstance()->getSettings()->allowSelfServiceMembers,
            'roleOptions' => Role::options(),
        ]);
    }

    /**
     * Add or change a buyer from the front end.
     *
     * Only a company administrator, only when the store has allowed self-service, and only for
     * users who already have an account — the same rule as the control panel, for the same
     * reason: quietly creating user accounts from a public form is how a store ends up with a
     * hundred half-made users and a spam problem.
     */
    public function actionSaveBuyer(): ?Response
    {
        $this->requirePostRequest();
        $this->requireLogin();

        $plugin = Plugin::getInstance();
        [$company, $member] = $this->_requireMembership();

        if (!$member->getCanManageMembers() || !$plugin->getSettings()->allowSelfServiceMembers) {
            throw new ForbiddenHttpException(Craft::t('forklift', 'You cannot manage buyers on this account.'));
        }

        $email = (string)$this->request->getRequiredBodyParam('email');
        $user = Craft::$app->getUsers()->getUserByUsernameOrEmail(trim($email));

        if ($user === null) {
            return $this->asFailure(Craft::t('forklift', 'No account was found with that email address. Ask them to register first.'));
        }

        $role = (string)$this->request->getBodyParam('role', Role::BUYER);

        if (!Role::exists($role)) {
            $role = Role::BUYER;
        }

        $spendLimit = $this->request->getBodyParam('spendLimit');

        $saved = $plugin->members->addUserToCompany(
            (int)$company->id,
            (int)$user->id,
            $role,
            ($spendLimit === null || $spendLimit === '') ? null : (float)$spendLimit,
        );

        if ($saved === false) {
            return $this->asFailure(Craft::t('forklift', 'Couldn’t add that buyer.'));
        }

        return $this->asSuccess(Craft::t('forklift', 'Buyer added.'));
    }

    public function actionRemoveBuyer(): ?Response
    {
        $this->requirePostRequest();
        $this->requireLogin();

        $plugin = Plugin::getInstance();
        [$company, $member] = $this->_requireMembership();

        if (!$member->getCanManageMembers() || !$plugin->getSettings()->allowSelfServiceMembers) {
            throw new ForbiddenHttpException(Craft::t('forklift', 'You cannot manage buyers on this account.'));
        }

        $memberId = (int)$this->request->getRequiredBodyParam('memberId');
        $target = $plugin->members->getMemberById($memberId);

        // Re-derived, never trusted from the form: a member id from another company would
        // otherwise let an administrator of one account remove a buyer from another.
        if ($target === null || (int)$target->companyId !== (int)$company->id) {
            throw new NotFoundHttpException('Buyer not found');
        }

        if (!$plugin->members->deleteMemberById($memberId)) {
            return $this->asFailure(Craft::t('forklift', 'A company needs at least one administrator.'));
        }

        return $this->asSuccess(Craft::t('forklift', 'Buyer removed.'));
    }

    // Quotes
    // -------------------------------------------------------------------------

    /** Turn the visitor's cart into a request for a price. */
    public function actionRequestQuote(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();

        if (!$plugin->getSettings()->getEffectiveQuotesEnabled()) {
            return $this->asFailure(Craft::t('forklift', 'Quotes are not available.'));
        }

        $cart = Commerce::getInstance()?->getCarts()->getCart();

        if ($cart === null || $cart->getLineItems() === []) {
            return $this->asFailure(Craft::t('forklift', 'There is nothing in your basket to quote.'));
        }

        $quote = $plugin->quotes->requestFromCart($cart, array_filter([
            'email' => $this->request->getBodyParam('email'),
            'reference' => $this->request->getBodyParam('reference'),
            'message' => $this->request->getBodyParam('message'),
        ], static fn($value) => $value !== null && $value !== ''));

        if ($quote === null) {
            return $this->asFailure(Craft::t('forklift', 'Couldn’t record your request.'));
        }

        if ($plugin->getSettings()->clearCartOnQuoteRequest) {
            $cart->setLineItems([]);
            Craft::$app->getElements()->saveElement($cart, false);
        }

        return $this->asModelSuccess(
            $quote,
            Craft::t('forklift', 'Thank you — your quote request has been received.'),
            'quote',
        );
    }

    // Approvals
    // -------------------------------------------------------------------------

    /**
     * Decide an approval from the link in an email.
     *
     * Anonymous by design: the people who sign off spend in a purchasing department very often
     * have no login at all, and a workflow that requires one is a workflow that gets bypassed by
     * forwarding the email to somebody who does.
     *
     * The token is the authorisation. It is 32 random characters, unique-indexed, tied to one
     * approval, and it is the *only* thing that identifies the request — the id is never accepted
     * from the query string on this route.
     */
    public function actionApproval(string $token): Response
    {
        $plugin = Plugin::getInstance();
        $approval = $plugin->approvals->getApprovalByToken($token);

        if ($approval === null) {
            throw new NotFoundHttpException('Approval not found');
        }

        $decision = $this->request->getBodyParam('decision');

        if ($this->request->getIsPost() && $decision !== null) {
            $this->requirePostRequest();

            if (!$approval->getIsPending()) {
                return $this->_render('approval', [
                    'approval' => $approval,
                    'order' => $approval->getOrder(),
                    'error' => Craft::t('forklift', 'This request has already been {status}.', [
                        'status' => strtolower($approval->getStatusLabel()),
                    ]),
                ]);
            }

            $note = $this->request->getBodyParam('note') ?: null;
            $user = Craft::$app->getUser()->getIdentity();

            if ($decision === 'approve') {
                $plugin->approvals->approve($approval, $user, $note);
            } else {
                $plugin->approvals->decline($approval, $user, $note);
            }
        }

        return $this->_render('approval', [
            'approval' => $approval,
            'order' => $approval->getOrder(),
            'company' => $approval->getCompany(),
            'error' => null,
        ]);
    }

    /**
     * Submit the visitor's cart for approval.
     *
     * The verdict decides whether there is anything to submit, so the button and the gate cannot
     * disagree about whether this order needs signing off.
     */
    public function actionSubmitForApproval(): ?Response
    {
        $this->requirePostRequest();
        $this->requireLogin();

        $plugin = Plugin::getInstance();
        $cart = Commerce::getInstance()?->getCarts()->getCart();

        if ($cart === null) {
            return $this->asFailure(Craft::t('forklift', 'There is no basket to submit.'));
        }

        // The cart has to be saved before an approval can hang off it, and before the company
        // assignment that the verdict depends on exists.
        if (!$cart->id) {
            Craft::$app->getElements()->saveElement($cart, false);
        }

        $verdict = $plugin->checkout->verdict($cart);

        if ($verdict->getIsAwaitingApproval()) {
            return $this->asFailure(Craft::t('forklift', 'This order is already waiting for approval.'));
        }

        if (!$verdict->getNeedsApproval()) {
            return $this->asFailure(Craft::t('forklift', 'This order does not need approval.'));
        }

        $approval = $plugin->approvals->request(
            $cart,
            \justinholtweb\forklift\models\Approval::REASON_OVER_THRESHOLD,
            $this->request->getBodyParam('note') ?: null,
        );

        if ($approval === null) {
            return $this->asFailure(Craft::t('forklift', 'Couldn’t submit for approval.'));
        }

        return $this->asSuccess(Craft::t('forklift', 'Submitted for approval. You will be emailed when it has been decided.'));
    }

    // Internals
    // -------------------------------------------------------------------------

    /**
     * The signed-in visitor's company and membership, or a refusal.
     *
     * @return array{0: \justinholtweb\forklift\elements\Company, 1: Member}
     */
    private function _requireMembership(): array
    {
        $plugin = Plugin::getInstance();
        $company = $plugin->companies->getCurrentCompany();
        $member = $plugin->companies->getCurrentMember();

        if ($company === null || $member === null) {
            throw new ForbiddenHttpException(Craft::t('forklift', 'You are not signed in to a company account.'));
        }

        return [$company, $member];
    }

    private function _financials(?Member $member): bool
    {
        return $member !== null && $member->getCanSeeFinancials();
    }

    /**
     * Render the store's template if it has one, otherwise Forklift's.
     *
     * `_forklift/portal/orders.twig` in the site's own templates always wins, so a store styles
     * the portal by creating a file rather than by overriding a plugin.
     *
     * The fallback goes through `renderTemplate()` with an explicit template mode rather than
     * swapping the view's mode by hand and pushing a string into the response: Yii's `Response`
     * has a public `$content` property and no `setContent()`, so the by-hand version fataled on
     * every portal page — and it also skipped the response formatter, so nothing would have set a
     * content type.
     */
    private function _render(string $name, array $variables): Response
    {
        $siteTemplate = '_forklift/portal/' . $name;

        if (Craft::$app->getView()->doesTemplateExist($siteTemplate)) {
            return $this->renderTemplate($siteTemplate, $variables);
        }

        return $this->renderTemplate('forklift/_portal/' . $name, $variables, View::TEMPLATE_MODE_CP);
    }
}
