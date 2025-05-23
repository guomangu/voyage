<?php

namespace craft\fields\conditions;

use craft\base\conditions\BaseNumberConditionRule;
use Money\Money;

/**
 * Text field condition rule.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 4.0.0
 */
class NumberFieldConditionRule extends BaseNumberConditionRule implements FieldConditionRuleInterface
{
    use FieldConditionRuleTrait;

    /**
     * @inheritdoc
     */
    protected function elementQueryParam(): ?string
    {
        return $this->paramValue();
    }

    /**
     * @inheritdoc
     */
    protected function matchFieldValue($value): bool
    {
        if ($value instanceof Money) {
            $value = (float)$value->getAmount();
        }

        /** @var int|float|null $value */
        return $this->matchValue($value);
    }
}
