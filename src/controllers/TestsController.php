<?php

declare(strict_types=1);

namespace livehand\abtestcraft\controllers;

use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use livehand\abtestcraft\models\Test;
use livehand\abtestcraft\ABTestCraft;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Tests controller - CRUD operations for split tests
 */
class TestsController extends Controller
{
    /**
     * List all tests
     */
    public function actionIndex(): Response
    {
        $this->requirePermission('abtestcraft:viewResults');

        $request = Craft::$app->getRequest();
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        // Get status filter from query param, default to 'active'
        $statusFilter = $request->getQueryParam('status', 'active');
        if (!in_array($statusFilter, ['active', 'completed', 'all'])) {
            $statusFilter = 'active';
        }

        $tests = ABTestCraft::getInstance()->tests->getTestsByStatus($statusFilter, $siteId);

        // Cache test counts for 60 seconds (changes infrequently)
        $cacheKey = "abtestcraft_counts_{$siteId}";
        $counts = Craft::$app->getCache()->getOrSet($cacheKey, function () use ($siteId) {
            return ABTestCraft::getInstance()->tests->getTestCounts($siteId);
        }, 60);

        // Batch load stats for all tests in a single query
        $batchStats = ABTestCraft::getInstance()->stats->getBatchTestStats($tests);

        $testsWithStats = [];
        foreach ($tests as $test) {
            // Get time estimate for running tests
            $timeEstimate = null;
            if ($test->status === Test::STATUS_RUNNING) {
                $timeEstimate = ABTestCraft::getInstance()->stats->getTimeEstimate($test);
            }
            $testsWithStats[] = [
                'test' => $test,
                'stats' => $batchStats[$test->id] ?? [],
                'timeEstimate' => $timeEstimate,
            ];
        }

        return $this->renderTemplate('abtestcraft/tests/index', [
            'tests' => $testsWithStats,
            'statusFilter' => $statusFilter,
            'counts' => $counts,
            'licenseInfo' => ABTestCraft::getInstance()->license->getStatusInfo(),
            'canCreateTests' => ABTestCraft::getInstance()->license->canCreateTests(),
        ]);
    }

    /**
     * Create new test form
     */
    public function actionNew(): Response
    {
        $this->requirePermission('abtestcraft:manageTests');

        // Check license allows creating tests
        if (!ABTestCraft::getInstance()->license->canCreateTests()) {
            Craft::$app->getSession()->setError(
                Craft::t('abtestcraft', 'A valid license is required to create new tests.')
            );
            return $this->redirect('abtestcraft/tests');
        }

        $test = new Test();
        $test->siteId = Craft::$app->getSites()->getCurrentSite()->id;

        return $this->renderTemplate('abtestcraft/tests/_form', [
            'test' => $test,
            'isNew' => true,
            'controlEntry' => null,
            'variantEntry' => null,
        ]);
    }

    /**
     * Edit test form
     */
    public function actionEdit(int $testId): Response
    {
        $this->requirePermission('abtestcraft:manageTests');

        $test = ABTestCraft::getInstance()->tests->getTestById($testId);

        if (!$test) {
            throw new NotFoundHttpException('Test not found');
        }

        // Get the entry objects for the element select fields
        $controlEntry = $test->controlEntryId
            ? Entry::find()->id($test->controlEntryId)->siteId($test->siteId)->status(null)->one()
            : null;
        $variantEntry = $test->variantEntryId
            ? Entry::find()->id($test->variantEntryId)->siteId($test->siteId)->status(null)->one()
            : null;

        return $this->renderTemplate('abtestcraft/tests/_form', [
            'test' => $test,
            'isNew' => false,
            'controlEntry' => $controlEntry,
            'variantEntry' => $variantEntry,
        ]);
    }

    /**
     * View test results
     */
    public function actionResults(int $testId): Response
    {
        $this->requirePermission('abtestcraft:viewResults');

        $test = ABTestCraft::getInstance()->tests->getTestById($testId);

        if (!$test) {
            throw new NotFoundHttpException('Test not found');
        }

        $stats = ABTestCraft::getInstance()->stats->getTestStats($test);
        $timeEstimate = ABTestCraft::getInstance()->stats->getTimeEstimate($test);

        return $this->renderTemplate('abtestcraft/tests/results', [
            'test' => $test,
            'stats' => $stats,
            'timeEstimate' => $timeEstimate,
        ]);
    }

    /**
     * Save test
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('abtestcraft:manageTests');

        $request = Craft::$app->getRequest();
        $testId = (int) $request->getBodyParam('testId');

        if ($testId) {
            $test = ABTestCraft::getInstance()->tests->getTestById($testId);
            if (!$test) {
                throw new NotFoundHttpException('Test not found');
            }
        } else {
            $test = new Test();
        }

        $test->siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $test->name = $request->getBodyParam('name');
        $test->handle = $request->getBodyParam('handle');

        // Auto-generate handle from name if empty or still matches a previous auto-generation
        $test->generateHandle();

        $test->hypothesis = $request->getBodyParam('hypothesis');
        $test->variantDescription = $request->getBodyParam('variantDescription');

        // Element select returns an array of IDs, get the first one and cast to int
        $controlEntryIds = $request->getBodyParam('controlEntryId');
        $variantEntryIds = $request->getBodyParam('variantEntryId');
        $controlId = is_array($controlEntryIds) ? ($controlEntryIds[0] ?? null) : $controlEntryIds;
        $variantId = is_array($variantEntryIds) ? ($variantEntryIds[0] ?? null) : $variantEntryIds;
        $test->controlEntryId = $controlId ? (int) $controlId : null;
        $test->variantEntryId = $variantId ? (int) $variantId : null;

        $test->trafficSplit = (int) $request->getBodyParam('trafficSplit', 50);

        // Keep legacy fields for backward compatibility
        $test->goalType = $request->getBodyParam('goalType');
        $test->goalValue = $request->getBodyParam('goalValue');

        if (!ABTestCraft::getInstance()->tests->saveTest($test)) {
            $errors = $test->getErrors();
            $errorMessages = [];
            foreach ($errors as $attribute => $messages) {
                $errorMessages[] = $attribute . ': ' . implode(', ', $messages);
            }
            Craft::$app->getSession()->setError('Could not save test. ' . implode(' | ', $errorMessages));

            // Get entry objects for re-displaying the form
            $controlEntry = $test->controlEntryId
                ? Entry::find()->id($test->controlEntryId)->siteId($test->siteId)->status(null)->one()
                : null;
            $variantEntry = $test->variantEntryId
                ? Entry::find()->id($test->variantEntryId)->siteId($test->siteId)->status(null)->one()
                : null;

            Craft::$app->getUrlManager()->setRouteParams([
                'test' => $test,
                'controlEntry' => $controlEntry,
                'variantEntry' => $variantEntry,
            ]);

            return null;
        }

        // Save goals (new multi-goal system)
        $goalsData = $request->getBodyParam('goals', []);
        $this->saveGoalsFromRequest($test, $goalsData);

        Craft::$app->getSession()->setNotice('Test saved.');

        return $this->redirectToPostedUrl($test);
    }

    /**
     * Save learnings for a test (from results page)
     */
    public function actionSaveLearnings(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('abtestcraft:manageTests');

        $request = Craft::$app->getRequest();
        $testId = $request->getRequiredBodyParam('testId');
        $learnings = $request->getBodyParam('learnings');

        $test = ABTestCraft::getInstance()->tests->getTestById($testId);

        if (!$test) {
            throw new NotFoundHttpException('Test not found');
        }

        $test->learnings = $learnings;

        if (!ABTestCraft::getInstance()->tests->saveTest($test)) {
            Craft::$app->getSession()->setError('Could not save learnings.');
            return null;
        }

        Craft::$app->getSession()->setNotice('Learnings saved.');

        return $this->redirectToPostedUrl($test);
    }

    /**
     * Process and save goals from form data
     */
    private function saveGoalsFromRequest(Test $test, array $goalsData): void
    {
        $goalsToSave = [];

        foreach ($goalsData as $goalType => $goalData) {
            // Check if this goal is enabled (lightswitch returns '1' when on)
            $isEnabled = !empty($goalData['isEnabled']);

            if (!$isEnabled) {
                continue;
            }

            $config = $goalData['config'] ?? [];

            // Process config based on goal type
            if ($goalType === 'form') {
                $config = $this->processFormGoalConfig($config);
            } elseif ($goalType === 'download' && isset($config['extensions'])) {
                // Convert comma-separated string to array
                $extensions = $config['extensions'];
                if (is_string($extensions)) {
                    $config['extensions'] = array_map('trim', explode(',', $extensions));
                }
            }

            $goalsToSave[] = [
                'goalType' => $goalType,
                'isEnabled' => true,
                'config' => $config,
            ];
        }

        ABTestCraft::getInstance()->goals->saveGoalsForTest($test, $goalsToSave);
    }

    /**
     * Process form goal configuration
     * Handles both smart mode (multi-select forms) and advanced mode (CSS selectors)
     */
    private function processFormGoalConfig(array $config): array
    {
        $mode = $config['mode'] ?? 'advanced';

        if ($mode === 'smart') {
            // Get array of selected forms (checkboxes)
            // Forms are submitted as 'plugin:handle' strings
            $forms = $config['forms'] ?? [];

            // Ensure forms is an array (could be empty)
            if (!is_array($forms)) {
                $forms = [];
            }

            // Filter out empty values
            $forms = array_filter($forms, fn($f) => !empty($f));
            $config['forms'] = array_values($forms);

            // Clear legacy single-form fields
            unset($config['formId']);
            unset($config['pluginHandle']);
            unset($config['formHandle']);

            // Clear advanced mode fields when in smart mode
            unset($config['formSelector']);
            unset($config['successMethod']);
            unset($config['successSelector']);
        } else {
            // Advanced mode - clear smart mode fields
            unset($config['formId']);
            unset($config['forms']);
            unset($config['pluginHandle']);
            unset($config['formHandle']);
        }

        return $config;
    }

    /**
     * Delete test
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('abtestcraft:manageTests');

        $testId = Craft::$app->getRequest()->getRequiredBodyParam('testId');
        $test = ABTestCraft::getInstance()->tests->getTestById($testId);

        if (!$test) {
            throw new NotFoundHttpException('Test not found');
        }

        if (!ABTestCraft::getInstance()->tests->deleteTest($test)) {
            return $this->asJson(['success' => false]);
        }

        return $this->asJson(['success' => true]);
    }

    /**
     * Start test
     */
    public function actionStart(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('abtestcraft:manageTests');

        $testId = Craft::$app->getRequest()->getRequiredBodyParam('testId');
        $test = ABTestCraft::getInstance()->tests->getTestById($testId);

        if (!$test) {
            throw new NotFoundHttpException('Test not found');
        }

        $result = ABTestCraft::getInstance()->tests->startTest($test);

        if ($result !== true) {
            // $result is an error message string
            if (Craft::$app->getRequest()->getAcceptsJson()) {
                return $this->asFailure($result);
            }
            Craft::$app->getSession()->setError($result);
            return $this->redirectToPostedUrl($test);
        }

        if (Craft::$app->getRequest()->getAcceptsJson()) {
            return $this->asSuccess('Test started.');
        }

        Craft::$app->getSession()->setNotice('Test started.');
        return $this->redirectToPostedUrl($test);
    }

    /**
     * Pause test
     */
    public function actionPause(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('abtestcraft:manageTests');

        $testId = Craft::$app->getRequest()->getRequiredBodyParam('testId');
        $test = ABTestCraft::getInstance()->tests->getTestById($testId);

        if (!$test) {
            throw new NotFoundHttpException('Test not found');
        }

        if (!ABTestCraft::getInstance()->tests->pauseTest($test)) {
            if (Craft::$app->getRequest()->getAcceptsJson()) {
                return $this->asFailure('Could not pause test.');
            }
            Craft::$app->getSession()->setError('Could not pause test.');
            return $this->redirectToPostedUrl($test);
        }

        if (Craft::$app->getRequest()->getAcceptsJson()) {
            return $this->asSuccess('Test paused.');
        }

        Craft::$app->getSession()->setNotice('Test paused.');
        return $this->redirectToPostedUrl($test);
    }

    /**
     * Complete test and declare winner
     */
    public function actionComplete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('abtestcraft:manageTests');

        $request = Craft::$app->getRequest();
        $testId = $request->getRequiredBodyParam('testId');
        $winner = $request->getBodyParam('winner');

        $test = ABTestCraft::getInstance()->tests->getTestById($testId);

        if (!$test) {
            throw new NotFoundHttpException('Test not found');
        }

        if (!ABTestCraft::getInstance()->tests->completeTest($test, $winner)) {
            Craft::$app->getSession()->setError('Could not complete test.');
            return $this->redirectToPostedUrl($test);
        }

        Craft::$app->getSession()->setNotice('Test completed.');
        return $this->redirectToPostedUrl($test);
    }
}
