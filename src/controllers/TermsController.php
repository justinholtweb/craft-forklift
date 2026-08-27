<?php

declare(strict_types=1);

namespace justinholtweb\forklift\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\forklift\models\Term;
use justinholtweb\forklift\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/** Payment terms in the control panel. Admin-only: these are store configuration. */
class TermsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireAdmin();

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('forklift/terms/_index', [
            'terms' => Plugin::getInstance()->terms->getAllTerms(),
            'title' => Craft::t('forklift', 'Payment terms'),
        ]);
    }

    public function actionEdit(?int $termId = null, ?Term $term = null): Response
    {
        if ($term === null) {
            $term = $termId !== null
                ? Plugin::getInstance()->terms->getTermById($termId)
                : new Term();

            if ($term === null) {
                throw new NotFoundHttpException('Payment terms not found');
            }
        }

        return $this->renderTemplate('forklift/terms/_edit', [
            'term' => $term,
            'isNew' => !$term->id,
            'title' => $term->id ? (string)$term->name : Craft::t('forklift', 'New payment terms'),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = $this->request;
        $plugin = Plugin::getInstance();
        $termId = $request->getBodyParam('termId');

        $term = $termId ? $plugin->terms->getTermById((int)$termId) : new Term();

        if ($term === null) {
            throw new NotFoundHttpException('Payment terms not found');
        }

        $term->name = $request->getBodyParam('name');
        $term->handle = $request->getBodyParam('handle');
        $term->netDays = (int)$request->getBodyParam('netDays', 30);
        $term->description = $request->getBodyParam('description') ?: null;
        $term->enabled = (bool)$request->getBodyParam('enabled', true);

        // Blank means "no early-settlement discount", which is different from zero percent.
        $percent = $request->getBodyParam('discountPercent');
        $days = $request->getBodyParam('discountDays');
        $term->discountPercent = ($percent === null || $percent === '') ? null : (float)$percent;
        $term->discountDays = ($days === null || $days === '') ? null : (int)$days;

        if (!$plugin->terms->saveTerm($term)) {
            $this->setFailFlash(Craft::t('forklift', 'Couldn’t save payment terms.'));
            Craft::$app->getUrlManager()->setRouteParams(['term' => $term]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('forklift', 'Payment terms saved.'));

        return $this->redirectToPostedUrl($term);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $termId = (int)$this->request->getRequiredBodyParam('termId');
        Plugin::getInstance()->terms->deleteTermById($termId);

        return $this->asSuccess(Craft::t('forklift', 'Payment terms deleted.'));
    }
}
