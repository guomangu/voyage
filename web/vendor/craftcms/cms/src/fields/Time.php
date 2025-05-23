<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\fields;

use Craft;
use craft\base\CrossSiteCopyableFieldInterface;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\InlineEditableFieldInterface;
use craft\base\MergeableFieldInterface;
use craft\base\SortableFieldInterface;
use craft\elements\Entry;
use craft\fields\conditions\EmptyFieldConditionRule;
use craft\gql\types\DateTime as DateTimeType;
use craft\helpers\DateTimeHelper;
use craft\i18n\Locale;
use craft\validators\TimeValidator;
use DateTime;
use GraphQL\Type\Definition\Type;
use yii\db\Schema;

/**
 * Date represents a Time field.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.5.12
 */
class Time extends Field implements InlineEditableFieldInterface, SortableFieldInterface, MergeableFieldInterface, CrossSiteCopyableFieldInterface
{
    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('app', 'Time');
    }

    /**
     * @inheritdoc
     */
    public static function icon(): string
    {
        return 'clock';
    }

    /**
     * @inheritdoc
     */
    public static function phpType(): string
    {
        return sprintf('\\%s|null', DateTime::class);
    }

    /**
     * @inheritdoc
     */
    public static function dbType(): string
    {
        return Schema::TYPE_STRING;
    }

    /**
     * @var string|null The minimum allowed time
     */
    public ?string $min = null;

    /**
     * @var string|null The maximum allowed time
     */
    public ?string $max = null;

    /**
     * @var int The number of minutes that the timepicker options should increment by
     */
    public int $minuteIncrement = 30;

    /**
     * @inheritdoc
     */
    public function __construct($config = [])
    {
        // Config normalization
        foreach (['min', 'max'] as $name) {
            if (isset($config[$name]) && is_array($config[$name])) {
                if ($date = DateTimeHelper::toDateTime($config[$name], true)) {
                    $config[$name] = $date->format('H:i');
                } else {
                    unset($config[$name]);
                }
            }
        }
        parent::__construct($config);
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels(): array
    {
        return [
            'min' => Craft::t('app', 'Min Time'),
            'max' => Craft::t('app', 'Max Time'),
        ];
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['minuteIncrement'], 'integer', 'min' => 1, 'max' => 60];
        $rules[] = [['max'], TimeValidator::class, 'min' => $this->min];

        return $rules;
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml(): ?string
    {
        return $this->settingsHtml(false);
    }

    /**
     * @inheritdoc
     */
    public function getReadOnlySettingsHtml(): ?string
    {
        return $this->settingsHtml(true);
    }

    private function settingsHtml(bool $readOnly): string
    {
        $incrementOptions = [5, 10, 15, 30, 60];
        $incrementOptions = array_combine($incrementOptions, $incrementOptions);

        return Craft::$app->getView()->renderTemplate('_components/fieldtypes/Time/settings.twig', [
            'incrementOptions' => $incrementOptions,
            'field' => $this,
            'min' => $this->min ? DateTimeHelper::toDateTime(['time' => $this->min], true) : null,
            'max' => $this->max ? DateTimeHelper::toDateTime(['time' => $this->max], true) : null,
            'readOnly' => $readOnly,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getInputId(): string
    {
        return sprintf('%s-time', parent::getInputId());
    }

    /**
     * @inheritdoc
     */
    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        return Craft::$app->getView()->renderTemplate('_includes/forms/time.twig', [
            'id' => parent::getInputId(), // can't use $this->getInputId() here because the template adds the "-time"
            'describedBy' => $this->describedBy,
            'name' => $this->handle,
            'value' => $value,
            'minTime' => $this->min,
            'maxTime' => $this->max,
            'minuteIncrement' => $this->minuteIncrement,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getElementValidationRules(): array
    {
        return [
            [TimeValidator::class, 'min' => $this->min, 'max' => $this->max],
        ];
    }

    /**
     * @inheritdoc
     */
    protected function searchKeywords(mixed $value, ElementInterface $element): string
    {
        return '';
    }

    /**
     * @inheritdoc
     */
    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        if (!$value) {
            return '';
        }

        return Craft::$app->getFormatter()->asTime($value, Locale::LENGTH_SHORT);
    }

    /**
     * @inheritdoc
     */
    public function previewPlaceholderHtml(mixed $value, ?ElementInterface $element): string
    {
        if (!$value) {
            $value = new DateTime();
        }
        return $this->getPreviewHtml($value, $element ?? new Entry());
    }

    /**
     * @inheritdoc
     */
    public function normalizeValue(mixed $value, ?ElementInterface $element): mixed
    {
        if (!$value) {
            return null;
        }

        if ($value instanceof DateTime) {
            return $value;
        }

        if (is_array($value)) {
            if (empty($value['time'])) {
                return null;
            }

            return DateTimeHelper::toDateTime($value, true) ?: null;
        }

        return DateTimeHelper::toDateTime(['time' => $value], true) ?: null;
    }

    /**
     * @inheritdoc
     */
    public function serializeValue(mixed $value, ?ElementInterface $element): mixed
    {
        /** @var DateTime|null $value */
        return $value?->format('H:i:s');
    }

    /**
     * @inheritdoc
     */
    public function serializeValueForDb(mixed $value, ?ElementInterface $element): mixed
    {
        // Bypass Db::prepareDateForDb()
        return $this->serializeValue($value, $element);
    }

    /**
     * @inheritdoc
     */
    public function getElementConditionRuleType(): array|string|null
    {
        return EmptyFieldConditionRule::class;
    }

    /**
     * @inheritdoc
     */
    public function getContentGqlType(): Type|array
    {
        return DateTimeType::getType();
    }

    /**
     * @inheritdoc
     * @since 3.5.0
     */
    public function getContentGqlMutationArgumentType(): Type|array
    {
        return [
            'name' => $this->handle,
            'type' => DateTimeType::getType(),
            'description' => $this->instructions,
        ];
    }
}
