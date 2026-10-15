<?php

declare(strict_types=1);

namespace SilverShop\ProductFeed\Google\Model;

use SilverShop\ProductFeed\Google\Service\GoogleTaxonomy;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\Hierarchy\Hierarchy;
use SilverStripe\Security\Permission;

/**
 * One node of Google's official product taxonomy (e.g. "Apparel & Accessories > Clothing > Dresses"), seeded from
 * the bundled taxonomy file. A {@link \SilverShop\Page\ProductCategory} is mapped to one of these; the connector
 * emits the node's {@code GoogleID} (or full path) as {@code g:google_product_category}.
 *
 * It is a {@link Hierarchy} so the CMS can pick it from a tree dropdown. The full path is denormalised onto
 * {@see $FullPath} during seeding so emission never has to walk the tree per item.
 *
 * @property string $Title
 * @property int $GoogleID
 * @property string $FullPath
 * @property int $ParentID
 * @method GoogleShoppingCategory Parent()
 */
class GoogleShoppingCategory extends DataObject
{
    private static string $table_name = 'ProductFeedGoogleCategory';

    private static string $singular_name = 'Google product category';

    private static string $plural_name = 'Google product categories';

    private static array $db = [
        'Title' => 'Varchar(255)',
        'GoogleID' => 'Int',
        'FullPath' => 'Varchar(500)',
    ];

    private static array $has_one = [
        'Parent' => GoogleShoppingCategory::class,
    ];

    private static array $extensions = [
        Hierarchy::class,
    ];

    private static array $indexes = [
        'GoogleID' => true,
    ];

    private static string $default_sort = 'Title ASC';

    /**
     * Whether to seed the bundled taxonomy automatically on dev/build. Set false to seed only via the
     * {@link \SilverShop\ProductFeed\Google\Task\SeedGoogleShoppingCategoryTask}.
     */
    private static bool $seed_on_build = true;

    private static array $searchable_fields = [
        'Title',
        'GoogleID',
    ];

    private static array $summary_fields = [
        'GoogleID' => 'Google ID',
        'FullPath' => 'Category path',
    ];

    public function getTreeTitle(): string
    {
        return htmlspecialchars((string) $this->Title, ENT_QUOTES);
    }

    /**
     * Seed the taxonomy on dev/build when the table is empty (unless disabled, or running tests — the test suite
     * seeds a tiny fixture of its own). Seeding the full ~5,600-node file is wrapped in one transaction.
     */
    public function requireDefaultRecords(): void
    {
        parent::requireDefaultRecords();

        // Never seed the full taxonomy during a unit-test run (the test suite seeds tiny fixtures of its own).
        if (defined('PHPUNIT_COMPOSER_INSTALL')) {
            return;
        }
        if (!static::config()->get('seed_on_build')) {
            return;
        }
        if (self::get()->exists()) {
            return;
        }

        $count = GoogleTaxonomy::create()->seed();
        DB::alteration_message("Seeded $count Google product taxonomy categories", 'created');
    }

    public function canView($member = null)
    {
        return true;
    }

    public function canEdit($member = null)
    {
        return Permission::check('CMS_ACCESS_CMSMain', 'any', $member);
    }

    public function canCreate($member = null, $context = [])
    {
        return Permission::check('ADMIN', 'any', $member);
    }

    public function canDelete($member = null)
    {
        return Permission::check('ADMIN', 'any', $member);
    }
}
