<?php

declare(strict_types=1);

namespace livehand\abtestcraft\models;

use Craft;
use craft\base\Model;
use craft\elements\Entry;
use craft\helpers\StringHelper;
use DateTime;
use livehand\abtestcraft\ABTestCraft;

/**
 * Test model
 */
class Test extends Model
{
    public ?int $id = null;
    public ?int $siteId = null;
    public string $name = '';
    public string $handle = '';
    public ?string $hypothesis = null;
    public ?string $variantDescription = null;
    public ?string $learnings = null;
    public string $status = 'draft';
    public ?int $controlEntryId = null;
    public ?int $variantEntryId = null;
    public int $trafficSplit = 50;
    public string $goalType = 'form';
    public ?string $goalValue = null;
    public ?DateTime $startedAt = null;
    public ?DateTime $endedAt = null;
    public ?string $winnerVariant = null;
    public ?DateTime $significanceNotifiedAt = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?DateTime $dateDeleted = null;
    public ?string $uid = null;

    // Status constants
    public const STATUS_DRAFT = 'draft';
    public const STATUS_RUNNING = 'running';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_COMPLETED = 'completed';

    // Variant constants
    public const VARIANT_CONTROL = 'control';
    public const VARIANT_VARIANT = 'variant';

    // Goal type constants
    public const GOAL_PHONE = 'phone';
    public const GOAL_FORM = 'form';
    public const GOAL_PAGE = 'page';
    public const GOAL_EMAIL = 'email';
    public const GOAL_DOWNLOAD = 'download';

    public function defineRules(): array
    {
        return [
            [['name', 'handle', 'controlEntryId', 'variantEntryId', 'goalType'], 'required'],
            [['name', 'handle'], 'string', 'max' => 255],
            [['trafficSplit'], 'integer', 'min' => 0, 'max' => 100],
            [['status'], 'in', 'range' => [self::STATUS_DRAFT, self::STATUS_RUNNING, self::STATUS_PAUSED, self::STATUS_COMPLETED]],
            [['goalType'], 'in', 'range' => [self::GOAL_PHONE, self::GOAL_FORM, self::GOAL_PAGE, self::GOAL_EMAIL, self::GOAL_DOWNLOAD]],
            [['goalValue'], 'string', 'max' => 500],
            [['winnerVariant'], 'in', 'range' => [self::VARIANT_CONTROL, self::VARIANT_VARIANT, null]],
            [['handle'], 'match', 'pattern' => '/^[a-z][A-Za-z0-9]*(?:-[A-Za-z0-9]+)*$/', 'message' => 'Handle must start with a lowercase letter and contain only letters, numbers, and optional hyphens (camelCase or kebab-case are supported)'],
            // Prevent same entry for control and variant
            [['variantEntryId'], 'compare', 'compareAttribute' => 'controlEntryId', 'operator' => '!=', 'message' => 'Control and variant entries must be different'],
            // Prevent circular reference - variant cannot be a descendant of control
            [['variantEntryId'], 'validateNotDescendantOfControl'],
        ];
    }

    /**
     * Validate that the variant entry is not a descendant of the control entry
     * Prevents circular references that could cause infinite loops
     */
    public function validateNotDescendantOfControl(string $attribute): void
    {
        if (!$this->controlEntryId || !$this->variantEntryId) {
            return;
        }

        // Check if variant is a descendant of control
        $isDescendant = Entry::find()
            ->descendantOf($this->controlEntryId)
            ->id($this->variantEntryId)
            ->siteId($this->siteId)
            ->exists();

        if ($isDescendant) {
            $this->addError($attribute, 'The variant entry cannot be a child/descendant of the control entry. This would create a circular reference.');
        }
    }

    public function attributeLabels(): array
    {
        return [
            'name' => 'Test Name',
            'handle' => 'Handle',
            'hypothesis' => 'Hypothesis',
            'variantDescription' => 'Variant Changes',
            'learnings' => 'Learnings',
            'controlEntryId' => 'Control Entry (Original)',
            'variantEntryId' => 'Variant Entry (Test)',
            'trafficSplit' => 'Traffic to Variant (%)',
            'goalType' => 'Goal Type',
            'goalValue' => 'Goal Value',
        ];
    }

    /**
     * Generate a handle from the name
     */
    public function generateHandle(): void
    {
        if (empty($this->handle) && !empty($this->name)) {
            $this->handle = StringHelper::toKebabCase($this->name);
        }
    }

    /**
     * Get the control entry
     */
    public function getControlEntry(): ?Entry
    {
        if (!$this->controlEntryId) {
            return null;
        }
        return Entry::find()->id($this->controlEntryId)->siteId($this->siteId)->one();
    }

    /**
     * Get the variant entry
     */
    public function getVariantEntry(): ?Entry
    {
        if (!$this->variantEntryId) {
            return null;
        }
        return Entry::find()->id($this->variantEntryId)->siteId($this->siteId)->one();
    }

    /**
     * Check if test is running
     */
    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    /**
     * Check if test is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if test has been soft deleted
     */
    public function isTrashed(): bool
    {
        return $this->dateDeleted !== null;
    }

    /**
     * Get the duration of the test in days
     * Returns null if test hasn't started or is still running
     */
    public function getDurationDays(): ?int
    {
        if (!$this->startedAt) {
            return null;
        }

        $endDate = $this->endedAt ?? new DateTime();
        $diff = $this->startedAt->diff($endDate);

        // Return at least 1 day if test ran for any amount of time
        return max(1, $diff->days);
    }

    /**
     * Check if test can be started (status only)
     */
    public function canStart(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_PAUSED]);
    }

    /**
     * Check if test has enabled goals configured
     */
    public function hasEnabledGoals(): bool
    {
        return !empty($this->getEnabledGoals());
    }

    /**
     * Check if test is ready to start (status + goals)
     */
    public function isReadyToStart(): bool
    {
        return $this->canStart() && $this->hasEnabledGoals();
    }

    /**
     * Check if test can be paused
     */
    public function canPause(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    /**
     * Get available goal types
     */
    public static function getGoalTypes(): array
    {
        return [
            self::GOAL_PHONE => 'Phone Click (tel: links)',
            self::GOAL_FORM => 'Form Submission',
            self::GOAL_PAGE => 'Page Visit',
            self::GOAL_EMAIL => 'Email Click (mailto: links)',
            self::GOAL_DOWNLOAD => 'File Download',
        ];
    }

    /**
     * Get available statuses
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_RUNNING => 'Running',
            self::STATUS_PAUSED => 'Paused',
            self::STATUS_COMPLETED => 'Completed',
        ];
    }

    /**
     * Get all goals for this test
     *
     * @return Goal[]
     */
    public function getGoals(): array
    {
        if (!$this->id) {
            return [];
        }

        return ABTestCraft::getInstance()->goals->getGoalsByTestId($this->id);
    }

    /**
     * Get enabled goals for this test
     *
     * @return Goal[]
     */
    public function getEnabledGoals(): array
    {
        if (!$this->id) {
            return [];
        }

        return ABTestCraft::getInstance()->goals->getEnabledGoalsByTestId($this->id);
    }

    /**
     * Check if test has any goals configured
     */
    public function hasGoals(): bool
    {
        return !empty($this->getGoals());
    }

    /**
     * Get goals configuration for JavaScript tracking
     */
    public function getGoalsJsConfig(): array
    {
        if (!$this->id) {
            return [];
        }

        return ABTestCraft::getInstance()->goals->getGoalsJsConfig($this->id);
    }
}
