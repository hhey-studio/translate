<?php

namespace enupal\translate\console\controllers;

use Craft;
use craft\base\ElementInterface;
use craft\console\Controller;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\helpers\Console;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use craft\models\Site;
use DateTime;
use enupal\translate\helpers\ArrayFlattener;
use enupal\translate\records\Metric;
use enupal\translate\Translate;
use Throwable;
use yii\console\ExitCode;

class TranslateController extends Controller
{
    private const NODE_CLASS = 'verbb\navigation\elements\Node';

    /**
     * Actually call the provider APIs rather than just reading settings.
     *
     * @var bool
     */
    public bool $live = false;

    /**
     * Provider handle to use, e.g. 'claude'.
     *
     * @var string|null
     */
    public ?string $provider = null;

    /**
     * Target site handles, e.g. --target=en,fr.
     *
     * @var string[]
     */
    public array $target = [];

    /**
     * Source site handle. Defaults to the primary site.
     */
    public ?string $source = null;

    /**
     * What to translate: entries, globals, categories, nodes or assets.
     */
    public string $type = 'entries';

    /**
     * Limit entries to these section handles.
     *
     * @var string[]
     */
    public array $section = [];

    /**
     * Limit categories to these group handles.
     *
     * @var string[]
     */
    public array $group = [];

    /**
     * Limit navigation nodes to these navigation handles.
     *
     * @var string[]
     */
    public array $nav = [];

    /**
     * Limit assets to these volume handles.
     *
     * @var string[]
     */
    public array $volume = [];

    /**
     * Limit to these element IDs.
     *
     * @var string[]
     */
    public array $elementId = [];

    /**
     * Save entries as drafts (1) or straight to the live entry (0). Overrides
     * the "Save as draft" setting for this run.
     */
    public ?bool $drafts = null;

    /**
     * Give every target the enabled state the element has in the source site.
     */
    public bool $syncStatus = false;

    /**
     * Count what would be sent, without calling the provider or saving.
     */
    public bool $dryRun = false;

    /**
     * Skip elements a previous run of the same source, target and type
     * already finished.
     */
    public bool $resume = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'providers' => ['live'],
            'test' => ['provider'],
            'content' => [
                'target', 'source', 'type', 'section', 'group', 'nav', 'volume', 'elementId',
                'provider', 'drafts', 'syncStatus', 'dryRun', 'resume',
            ],
            default => [],
        });
    }

    /**
     * Sync translations from DB
     *
     * @return int
     */
    public function actionSync(): int
    {
        $this->stdout(Craft::t('app', 'Syncing translations from DB:') . PHP_EOL, \yii\helpers\Console::FG_GREEN);
        $this->stdout(Craft::t('app', 'Adding translations to the queue:') . PHP_EOL, \yii\helpers\Console::FG_YELLOW);
        Translate::$app->translate->runSync();
        $this->stdout(Craft::t('app', 'Running queued-up jobs') . PHP_EOL, \yii\helpers\Console::FG_YELLOW);
        Craft::$app->queue->run();
        $this->stdout(Craft::t('app', 'Syncing translations from DB job started') . PHP_EOL, \yii\helpers\Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Report the status of every translation provider.
     *
     * Use --live to actually call each configured provider's API, which
     * verifies the credentials really work (and costs a few tokens).
     *
     * @return int
     */
    public function actionProviders(): int
    {
        $this->stdout('Translation providers' . PHP_EOL . PHP_EOL, Console::FG_GREEN);

        foreach (Translate::$app->providers->getAllProviders() as $handle => $provider) {
            $configured = $provider->isConfigured();

            $this->stdout(str_pad($handle, 14));
            $this->stdout(str_pad($provider::displayName(), 30));

            if (!$configured) {
                $this->stdout('not configured' . PHP_EOL, Console::FG_GREY);
                continue;
            }

            $model = $provider->getModelName();

            if (!$this->live) {
                $this->stdout('configured', Console::FG_GREEN);
                $this->stdout($model ? " ($model)" : '');
                $this->stdout(PHP_EOL);
                continue;
            }

            if ($provider->isConnected()) {
                $this->stdout('connected', Console::FG_GREEN);
                $this->stdout($model ? " ($model)" : '');
                $this->stdout(PHP_EOL);
            } else {
                $this->stdout('FAILED — check credentials and logs' . PHP_EOL, Console::FG_RED);
            }
        }

        return ExitCode::OK;
    }

    /**
     * Translate a single string, to sanity check a provider end to end.
     *
     * craft enupal-translate/translate/test "Hello world" es --provider=claude
     *
     * @param string $text The string to translate.
     * @param string $language The target language, e.g. 'es'.
     * @return int
     */
    public function actionTest(string $text, string $language): int
    {
        $handle = $this->provider ?: (Translate::$app->providers->getContentProvider()?->handle());

        if (!$handle) {
            $this->stderr('No provider is enabled.' . PHP_EOL, Console::FG_RED);
            return ExitCode::CONFIG;
        }

        $provider = Translate::$app->providers->getProviderByHandle($handle);

        if ($provider === null || !$provider->isConfigured()) {
            $this->stderr("Provider '$handle' is not enabled." . PHP_EOL, Console::FG_RED);
            return ExitCode::CONFIG;
        }

        $result = $provider->translate(['sample' => $text], $language);

        if (!$result->success) {
            $this->stderr('Failed: ' . $result->errorMessage . PHP_EOL, Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout('Source:      ' . $text . PHP_EOL);
        $this->stdout('Translation: ' . ($result->translations['sample'] ?? '(none)') . PHP_EOL, Console::FG_GREEN);
        $this->stdout(sprintf(
            'Provider: %s  Model: %s  Tokens: %d in / %d out  Calls: %d' . PHP_EOL,
            $result->provider,
            $result->model ?? 'n/a',
            $result->inputTokens,
            $result->outputTokens,
            $result->apiCalls
        ), Console::FG_GREY);

        return ExitCode::OK;
    }

    /**
     * Report which fields in this install can be translated.
     *
     * Shows every custom field, the serializer that will handle it, and why
     * anything is being skipped — so a site's coverage can be checked without
     * running a translation.
     *
     * @return int
     */
    public function actionFields(): int
    {
        $content = Translate::$app->content;
        $excluded = Translate::$app->settings->getSettings()->getExcludedFieldHandles();

        $supported = [];
        $unsupported = [];

        foreach (Craft::$app->getFields()->getAllFields() as $field) {
            $class = get_class($field);
            $serializer = $content->getSerializer($field);
            $short = fn(?string $c) => $c ? substr((string)strrchr($c, '\\'), 1) : '-';

            $row = [
                'handle' => $field->handle,
                'name' => $field->name,
                'class' => $class,
                'serializer' => $serializer ? $short(get_class($serializer)) : null,
            ];

            if (!$serializer) {
                $row['reason'] = 'no serializer for ' . $short($class);
                $unsupported[] = $row;
                continue;
            }

            if (in_array($field->handle, $excluded, true)) {
                $row['reason'] = 'excluded in settings';
                $unsupported[] = $row;
                continue;
            }

            $isNested = in_array($class, \enupal\translate\services\Content::$nestedElementFields, true);
            $method = $field->translationMethod ?? \craft\base\Field::TRANSLATION_METHOD_NONE;

            if (!$isNested && $method === \craft\base\Field::TRANSLATION_METHOD_NONE) {
                $row['reason'] = 'field is not translatable (Translation Method: None)';
                $unsupported[] = $row;
                continue;
            }

            $supported[] = $row;
        }

        $this->stdout(PHP_EOL . 'Translatable (' . count($supported) . ')' . PHP_EOL, Console::FG_GREEN);
        foreach ($supported as $r) {
            $this->stdout(sprintf("  %-28s %-22s %s\n", $r['handle'], $r['serializer'], $r['name']));
        }

        $this->stdout(PHP_EOL . 'Skipped (' . count($unsupported) . ')' . PHP_EOL, Console::FG_YELLOW);
        foreach ($unsupported as $r) {
            $this->stdout(sprintf("  %-28s %s\n", $r['handle'], $r['reason']));
        }

        $this->stdout(PHP_EOL . 'Field types this plugin knows how to translate:' . PHP_EOL);
        foreach ($content->getSupportedFieldClasses() as $class => $serializer) {
            $installed = class_exists($class) ? 'installed' : 'not installed';
            $this->stdout(sprintf("  %-46s %-16s %s\n", $class, substr((string)strrchr($serializer, '\\'), 1), $installed));
        }

        return ExitCode::OK;
    }

    /**
     * Translate content into one or more sites, one element at a time.
     *
     * Runs in the foreground rather than on the queue, so a long run is not
     * cut off by the queue's time limit and every failure is reported here.
     *
     * craft enupal-translate/translate/content --target=en,fr --section=pages --drafts=0 --sync-status
     * craft enupal-translate/translate/content --target=fr --type=nodes
     * craft enupal-translate/translate/content --target=fr --dry-run
     *
     * @return int
     */
    public function actionContent(): int
    {
        $sites = Craft::$app->getSites();
        $sourceSite = $this->source ? $sites->getSiteByHandle($this->source) : $sites->getPrimarySite();

        if ($sourceSite === null) {
            $this->stderr("Unknown source site '$this->source'." . PHP_EOL, Console::FG_RED);
            return ExitCode::USAGE;
        }

        if (empty($this->target)) {
            $this->stderr('Pass the target sites, e.g. --target=en,fr' . PHP_EOL, Console::FG_RED);
            return ExitCode::USAGE;
        }

        $targetSites = [];

        foreach ($this->target as $handle) {
            $site = $sites->getSiteByHandle($handle);

            if ($site === null || $site->id === $sourceSite->id) {
                $this->stderr("'$handle' is not a valid target site." . PHP_EOL, Console::FG_RED);
                return ExitCode::USAGE;
            }

            $targetSites[] = $site;
        }

        try {
            $batches = $this->getContentBatches($sourceSite);
        } catch (Throwable $e) {
            $this->stderr($e->getMessage() . PHP_EOL, Console::FG_RED);
            return ExitCode::USAGE;
        }

        if (!$this->dryRun) {
            try {
                Translate::$app->content->resolveProvider($this->provider);
            } catch (Throwable $e) {
                $this->stderr($e->getMessage() . PHP_EOL, Console::FG_RED);
                return ExitCode::CONFIG;
            }

            if ($this->drafts !== null) {
                Translate::$app->settings->getSettings()->saveAsDraft = $this->drafts;
            }
        }

        $startedAt = Db::prepareDateForDb(new DateTime());
        $failed = [];
        $totals = ['done' => 0, 'skipped' => 0, 'strings' => 0, 'characters' => 0];

        foreach ($targetSites as $targetSite) {
            $progressFile = $this->getProgressFile($sourceSite, $targetSite);
            $completed = [];

            if ($this->resume && is_file($progressFile)) {
                $completed = array_flip(Json::decode(file_get_contents($progressFile)) ?? []);
            }

            $this->stdout(PHP_EOL . "$sourceSite->handle → $targetSite->handle" . ($this->dryRun ? ' (dry run)' : '') . PHP_EOL, Console::FG_GREEN);

            foreach ($batches as $label => $query) {
                $ids = (clone $query)->ids();
                $count = count($ids);
                $this->stdout(PHP_EOL . "$label ($count)" . PHP_EOL, Console::FG_YELLOW);

                foreach ($ids as $index => $id) {
                    $prefix = sprintf('  [%d/%d] #%d ', $index + 1, $count, $id);

                    if (isset($completed[$id])) {
                        $totals['skipped']++;
                        continue;
                    }

                    $element = (clone $query)->id($id)->one();

                    if ($element === null) {
                        continue;
                    }

                    $this->stdout($prefix . $this->describe($element) . ' ');
                    $time = microtime(true);

                    try {
                        if ($this->dryRun) {
                            [$values] = ArrayFlattener::flatten(Translate::$app->content->serializeElement($element, $sourceSite, $targetSite));
                            $characters = array_sum(array_map('mb_strlen', $values));
                            $totals['strings'] += count($values);
                            $totals['characters'] += $characters;
                            $this->stdout(sprintf('%d strings, %d characters', count($values), $characters) . PHP_EOL, Console::FG_GREY);
                            continue;
                        }

                        $note = $this->translateContentElement($element, $sourceSite, $targetSite);
                    } catch (Throwable $e) {
                        $failed[$targetSite->handle][] = $id;
                        $this->stdout('✗ ' . $e->getMessage() . PHP_EOL, Console::FG_RED);
                        Craft::error(sprintf('Could not translate element %d into %s: %s', $id, $targetSite->handle, $e), __METHOD__);
                        continue;
                    }

                    $totals['done']++;
                    $completed[$id] = true;
                    FileHelper::writeToFile($progressFile, Json::encode(array_keys($completed)));

                    $this->stdout(sprintf('✓ %.1fs', microtime(true) - $time), Console::FG_GREEN);
                    $this->stdout(($note ? " $note" : '') . PHP_EOL, Console::FG_GREY);
                }
            }
        }

        $this->stdout(PHP_EOL);

        if ($this->dryRun) {
            $this->stdout(sprintf(
                'Would send %d strings, %d characters per target site (%d characters in total).' . PHP_EOL,
                $totals['strings'] / count($targetSites),
                $totals['characters'] / count($targetSites),
                $totals['characters']
            ), Console::FG_GREEN);

            return ExitCode::OK;
        }

        $usage = Metric::find()
            ->where(['type' => Metric::TYPE_CONTENT])
            ->andWhere(['>=', 'dateCreated', $startedAt])
            ->select([
                'characters' => 'SUM([[characterCount]])',
                'input' => 'SUM([[inputTokens]])',
                'output' => 'SUM([[outputTokens]])',
            ])
            ->asArray()
            ->one();

        $this->stdout(sprintf(
            'Translated %d, skipped %d already done, failed %d. Characters: %d, tokens: %d in / %d out.' . PHP_EOL,
            $totals['done'],
            $totals['skipped'],
            array_sum(array_map('count', $failed)),
            $usage['characters'] ?? 0,
            $usage['input'] ?? 0,
            $usage['output'] ?? 0
        ), $failed ? Console::FG_YELLOW : Console::FG_GREEN);

        foreach ($failed as $handle => $ids) {
            $this->stdout(sprintf('Failed into %s: --target=%s --element-id=%s', $handle, $handle, implode(',', $ids)) . PHP_EOL, Console::FG_RED);
        }

        return $failed ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Element queries for the chosen type, keyed by a label for the output.
     * Structures are walked in structure order, so a parent always gets its
     * translated slug before its children build their URIs from it.
     *
     * @return array<string, ElementQueryInterface>
     */
    private function getContentBatches(Site $sourceSite): array
    {
        $batches = [];

        switch ($this->type) {
            case 'entries':
                $entries = Craft::$app->getEntries();
                $sections = $this->section
                    ? array_map(fn($handle) => $entries->getSectionByHandle($handle) ?? throw new \InvalidArgumentException("Unknown section '$handle'."), $this->section)
                    : $entries->getAllSections();

                foreach ($sections as $section) {
                    $query = Entry::find()->section($section);

                    if ($section->structureId) {
                        $query->orderBy(['structureelements.lft' => SORT_ASC]);
                    }

                    $batches["Section: $section->name"] = $query;
                }
                break;

            case 'globals':
                $batches['Global sets'] = GlobalSet::find();
                break;

            case 'categories':
                $categories = Craft::$app->getCategories();
                $groups = $this->group
                    ? array_map(fn($handle) => $categories->getGroupByHandle($handle) ?? throw new \InvalidArgumentException("Unknown category group '$handle'."), $this->group)
                    : $categories->getAllGroups();

                foreach ($groups as $group) {
                    $batches["Category group: $group->name"] = Category::find()->group($group)->orderBy(['structureelements.lft' => SORT_ASC]);
                }
                break;

            case 'nodes':
                if (!class_exists(self::NODE_CLASS)) {
                    throw new \InvalidArgumentException('Navigation is not installed.');
                }

                $navs = \verbb\navigation\Navigation::$plugin->getNavs();
                $navModels = $this->nav
                    ? array_map(fn($handle) => $navs->getNavByHandle($handle) ?? throw new \InvalidArgumentException("Unknown navigation '$handle'."), $this->nav)
                    : $navs->getAllNavs();

                foreach ($navModels as $nav) {
                    $batches["Navigation: $nav->name"] = (self::NODE_CLASS)::find()->nav($nav)->orderBy(['structureelements.lft' => SORT_ASC]);
                }
                break;

            case 'assets':
                $volumes = Craft::$app->getVolumes();
                $volumeModels = $this->volume
                    ? array_map(fn($handle) => $volumes->getVolumeByHandle($handle) ?? throw new \InvalidArgumentException("Unknown volume '$handle'."), $this->volume)
                    : $volumes->getAllVolumes();

                foreach ($volumeModels as $volume) {
                    $batches["Volume: $volume->name"] = Asset::find()->volumeId($volume->id);
                }
                break;

            default:
                throw new \InvalidArgumentException("Unknown type '$this->type'. Use entries, globals, categories, nodes or assets.");
        }

        foreach ($batches as $query) {
            $query->siteId($sourceSite->id)->status(null);

            if ($this->elementId) {
                $query->id(array_map('intval', $this->elementId));
            }
        }

        return $batches;
    }

    /**
     * Translate one element into one site.
     *
     * @return string|null a note for the output
     */
    private function translateContentElement(ElementInterface $element, Site $sourceSite, Site $targetSite): ?string
    {
        $note = null;

        if (is_a($element, self::NODE_CLASS)) {
            $note = $this->translateNode($element, $sourceSite, $targetSite);
        } else {
            $saved = Translate::$app->content->translateElement($element, $sourceSite, $targetSite, null, true, $this->provider);

            if ($saved === null) {
                $note = 'nothing to translate';
            } elseif ($saved instanceof Entry && $saved->getIsDraft()) {
                $note = "draft #$saved->draftId";
            }
        }

        if ($this->syncStatus) {
            $note = trim($note . ' ' . $this->syncElementStatus($element, $targetSite)) ?: null;
        }

        return $note;
    }

    /**
     * Navigation nodes that show their entry's title take the translated
     * entry title instead of a separate translation, so menu and page always
     * match. Custom and passive nodes, and nodes with their own title, go to
     * the provider like any other element.
     */
    private function translateNode(ElementInterface $node, Site $sourceSite, Site $targetSite): ?string
    {
        /** @var \verbb\navigation\elements\Node $node */
        Translate::$app->content->assertTargetSupported($node, $targetSite);

        $sourceLinked = $node->getElement();
        $targetLinked = $node->elementId && $node->type && class_exists($node->type)
            ? $node->type::find()->id($node->elementId)->siteId($targetSite->id)->status(null)->one()
            : null;

        if ($sourceLinked !== null && $targetLinked !== null && $node->title === $sourceLinked->title) {
            $target = $node::find()->id($node->id)->siteId($targetSite->id)->status(null)->one();
            $target->title = $targetLinked->title;
            $target->setElementSiteId($targetSite->id);

            if (!Craft::$app->getElements()->saveElement($target)) {
                throw new \RuntimeException('Could not save the node: ' . implode(' ', $target->getFirstErrors()));
            }

            return $targetLinked->title === $sourceLinked->title
                ? 'entry title copied, but it is not translated yet'
                : 'entry title copied';
        }

        $saved = Translate::$app->content->translateElement($node, $sourceSite, $targetSite, null, true, $this->provider);

        // A node linking an entry has to link the target site's entry.
        if ($saved !== null && $targetLinked !== null && (int)$saved->getElementSiteId() !== $targetSite->id) {
            $saved->setElementSiteId($targetSite->id);

            if (!Craft::$app->getElements()->saveElement($saved)) {
                throw new \RuntimeException('Could not save the node: ' . implode(' ', $saved->getFirstErrors()));
            }
        }

        return null;
    }

    /**
     * Give the element the source site's enabled state in the target site.
     *
     * @return string|null a note for the output
     */
    private function syncElementStatus(ElementInterface $source, Site $targetSite): ?string
    {
        if (Translate::$app->settings->getSettings()->saveAsDraft && $source instanceof Entry) {
            return 'status not synced for drafts';
        }

        $target = $source::find()->id($source->id)->siteId($targetSite->id)->status(null)->one();
        $enabled = (bool)$source->getEnabledForSite();

        if ($target === null || (bool)$target->getEnabledForSite() === $enabled) {
            return null;
        }

        $target->setEnabledForSite($enabled);

        if (!Craft::$app->getElements()->saveElement($target)) {
            throw new \RuntimeException('Could not sync the status: ' . implode(' ', $target->getFirstErrors()));
        }

        return $enabled ? 'enabled' : 'disabled';
    }

    private function getProgressFile(Site $sourceSite, Site $targetSite): string
    {
        return sprintf(
            '%s/enupal-translate/%s-%s-%s.json',
            Craft::$app->getPath()->getRuntimePath(),
            $sourceSite->handle,
            $targetSite->handle,
            $this->type
        );
    }

    private function describe(ElementInterface $element): string
    {
        $title = (string)($element->title ?? '');

        return $element::displayName() . ($title !== '' ? ' “' . mb_strimwidth($title, 0, 50, '…') . '”' : '');
    }

}
