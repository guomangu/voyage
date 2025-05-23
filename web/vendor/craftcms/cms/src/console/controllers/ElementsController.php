<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\console\controllers;

use Craft;
use craft\base\ElementInterface;
use craft\console\Controller;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\helpers\Component;
use craft\helpers\Console;
use craft\helpers\Db;
use craft\models\Section;
use yii\console\ExitCode;

/**
 * Manages elements.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 4.1.0
 */
class ElementsController extends Controller
{
    /**
     * @var bool Whether the element should be hard-deleted.
     */
    public bool $hard = false;

    /**
     * @var bool Whether to only do a dry run of the prune elements of type process.
     * @since 5.6.0
     */
    public bool $dryRun = false;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        switch ($actionID) {
            case 'delete':
                $options[] = 'hard';
                break;
            case 'delete-all-of-type':
                $options[] = 'dryRun';
                break;
        }
        return $options;
    }

    /**
     * Deletes an element by its ID.
     *
     * @param int $id The element ID to delete.
     */
    public function actionDelete(int $id): int
    {
        $element = $this->_element($id);

        if (is_int($element)) {
            return $element;
        }

        // Don't allow deleting single entries
        if ($element instanceof Entry && $element->getSection()->type === Section::TYPE_SINGLE) {
            $this->stderr("Deleting single section entries isn’t allowed.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $title = $this->ansiFormat($element->getUiLabel(), Console::FG_CYAN);

        if (!$this->hard && $element->dateDeleted) {
            $this->stderr("“{$title}” is already soft-deleted. Try again with --hard to hard-delete it.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($this->interactive && !$this->confirm(sprintf('Are you sure you want to %s “%s”?', $this->hard ? 'hard-delete' : 'delete', $title))) {
            return ExitCode::OK;
        }

        $this->stdout("Deleting “{$title}” ... ");

        try {
            $success = Craft::$app->getElements()->deleteElement($element, $this->hard);
        } catch (\Throwable $e) {
            $this->stdout("error: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!$success) {
            $this->stdout("failed\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("done\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Deletes all elements of a given type.
     *
     * @param class-string<ElementInterface> $type The element type to delete.
     * @since 5.6.0
     */
    public function actionDeleteAllOfType(string $type): int
    {
        // get the elements of that type
        $query = (new Query())
            ->select('id')
            ->from(Table::ELEMENTS)
            ->where(['type' => $type]);

        // exclude single entries
        if ($type === Entry::class) {
            $singleSections = Craft::$app->getEntries()->getSectionsByType(Section::TYPE_SINGLE);
            if (!empty($singleSections)) {
                $singleEntryIds = Entry::find()
                    ->sectionId(array_map(fn(Section $section) => $section->id, $singleSections))
                    ->status(null)
                    ->site('*')
                    ->unique()
                    ->ids();
                if (!empty($singleEntryIds)) {
                    $query->andWhere(['not', ['id' => $singleEntryIds]]);
                }
            }
        }

        $total = $query->count();

        $isValid = Component::validateComponentClass($type, ElementInterface::class);
        if ($isValid) {
            $typeLabel = $total == 1 ? $type::lowerDisplayName() : $type::pluralLowerDisplayName();
        } else {
            $typeLabel = sprintf('`%s` %s', $type, $total == 1 ? 'element' : 'elements');
        }

        if (!$total) {
            $this->stdout(sprintf("%s\n", $this->markdownToAnsi("No $typeLabel found.")));
            return ExitCode::OK;
        }

        $this->stdout(sprintf("%s\n", $this->markdownToAnsi("$total $typeLabel found.")));

        if (!$this->dryRun && !$this->confirm('Continue?')) {
            $this->stdout("Aborting.\n");
            return ExitCode::OK;
        }

        foreach (Db::each($query) as $element) {
            $elementId = $element['id'];
            $message = sprintf('Deleting %s %s', $isValid ? $type::lowerDisplayName() : 'element', $elementId);
            $this->do($message, function() use ($elementId) {
                if (!$this->dryRun) {
                    Db::delete(Table::ELEMENTS, [
                        'id' => $elementId,
                    ]);
                    Db::delete(Table::SEARCHINDEX, [
                        'elementId' => $elementId,
                    ]);
                }
            });
        }

        $dryRunLabel = $this->dryRun ? '**[DRY RUN]** ' : '';
        $this->stdout(sprintf("%s\n", $this->markdownToAnsi("$dryRunLabel$total $typeLabel deleted.")));
        return ExitCode::OK;
    }

    /**
     * Restores an element by its ID.
     *
     * @param int $id The element ID to restore.
     */
    public function actionRestore(int $id): int
    {
        $element = $this->_element($id);

        if (is_int($element)) {
            return $element;
        }

        $title = $this->ansiFormat($element->getUiLabel(), Console::FG_CYAN);

        if (!$element->dateDeleted) {
            $this->stderr("“{$title}” is already restored.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Restoring “{$title}” ... ");

        try {
            $success = Craft::$app->getElements()->restoreElement($element);
        } catch (\Throwable $e) {
            $this->stdout("error: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!$success) {
            $this->stdout("failed\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("done\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    private function _element(int $id): ElementInterface|int
    {
        if ($id < 1) {
            $this->stderr("Invalid element ID: $id\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $criteria = [
            'siteId' => '*',
            'unique' => true,
            'trashed' => null,
            'drafts' => null,
            'provisionalDrafts' => null,
            'revisions' => null,
        ];

        $element = Craft::$app->getElements()->getElementById($id, criteria: $criteria);

        if (!$element) {
            $this->stderr("Invalid element ID: $id\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return $element;
    }
}
