<?php

declare(strict_types=1);

namespace justinholtweb\forklift\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\forklift\elements\Company;
use justinholtweb\forklift\elements\Quote;
use justinholtweb\forklift\Plugin;
use yii\web\Response;

/**
 * Forklift's settings, as several screens rather than one long pane.
 *
 * Every screen posts only its own fields, so `actionSave()` merges into the existing settings
 * rather than replacing them — posting a partial form into `savePluginSettings()` would wipe
 * every setting the current screen does not happen to render.
 */
class SettingsController extends Controller
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
        return $this->redirect('forklift/settings/general');
    }

    public function actionGeneral(): Response
    {
        return $this->_screen('general', Craft::t('forklift', 'General'));
    }

    public function actionCompanies(): Response
    {
        return $this->_screen('companies', Craft::t('forklift', 'Companies and buyers'));
    }

    public function actionQuotes(): Response
    {
        return $this->_screen('quotes', Craft::t('forklift', 'Quotes'));
    }

    public function actionCredit(): Response
    {
        return $this->_screen('credit', Craft::t('forklift', 'Credit and terms'));
    }

    /** The field layout for companies — the site's own fields on an account. */
    public function actionFields(): Response
    {
        return $this->renderTemplate('forklift/settings/_fields', [
            'title' => Craft::t('forklift', 'Company fields'),
            'selectedTab' => 'fields',
            'fieldLayout' => Craft::$app->getFields()->getLayoutByType(Company::class),
            'saveAction' => 'forklift/settings/save-field-layout',
            'layoutFor' => 'company',
            'settings' => Plugin::getInstance()->getSettings(),
            'instructions' => Craft::t('forklift', 'Fields added here appear on every company account — a sales rep, a region, delivery instructions, whatever your accounts actually differ by.'),
        ]);
    }

    public function actionQuoteFields(): Response
    {
        return $this->renderTemplate('forklift/settings/_fields', [
            'title' => Craft::t('forklift', 'Quote fields'),
            'selectedTab' => 'quote-fields',
            'fieldLayout' => Craft::$app->getFields()->getLayoutByType(Quote::class),
            'saveAction' => 'forklift/settings/save-field-layout',
            'layoutFor' => 'quote',
            'settings' => Plugin::getInstance()->getSettings(),
            'instructions' => Craft::t('forklift', 'Fields added here appear on every quote — a lead source, a delivery week, whoever is handling it.'),
        ]);
    }

    public function actionSaveFieldLayout(): ?Response
    {
        $this->requirePostRequest();

        $for = (string)$this->request->getBodyParam('layoutFor', 'company');
        $layout = Craft::$app->getFields()->assembleLayoutFromPost();

        [$elementType, $configKey] = $for === 'quote'
            ? [Quote::class, Plugin::CONFIG_QUOTE_FIELD_LAYOUT_KEY]
            : [Company::class, Plugin::CONFIG_COMPANY_FIELD_LAYOUT_KEY];

        Plugin::getInstance()->saveFieldLayout($layout, $elementType, $configKey);

        $this->setSuccessFlash(Craft::t('forklift', 'Fields saved.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Save one screen's worth of settings.
     *
     * Merged into the existing values rather than replacing them, because
     * `savePluginSettings()` overwrites everything it is given and a partial form would otherwise
     * blank every setting on the other four screens.
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $posted = $this->request->getBodyParam('settings', []);

        if (!is_array($posted)) {
            $posted = [];
        }

        // Aging buckets arrive as a comma-separated string, because a repeatable row editor for
        // three numbers is more machinery than the thing deserves.
        if (isset($posted['agingBuckets']) && is_string($posted['agingBuckets'])) {
            $posted['agingBuckets'] = array_values(array_filter(array_map(
                static fn(string $part) => (int)trim($part),
                explode(',', $posted['agingBuckets']),
            )));
        }

        $values = array_merge($settings->toArray(), $posted);

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $values)) {
            $this->setFailFlash(Craft::t('forklift', 'Couldn’t save settings.'));
            Craft::$app->getUrlManager()->setRouteParams(['settings' => $settings]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('forklift', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }

    private function _screen(string $handle, string $title): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('forklift/settings/_' . $handle, [
            'title' => $title,
            'selectedTab' => $handle,
            'settings' => $plugin->getSettings(),
            'isPro' => $plugin->isPro(),
            'suppressed' => $plugin->getSettings()->getHasSuppressedProSettings(),
            'terms' => $plugin->terms->getAllTerms(),
        ]);
    }
}
