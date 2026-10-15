<?php

declare(strict_types=1);

namespace SilverShop\ProductFeed\Google\Extension;

use SilverShop\Page\ProductCategory;
use SilverShop\ProductFeed\Google\Model\GoogleShoppingCategory;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\TreeDropdownField;

/**
 * Maps a {@link ProductCategory} to a {@link GoogleShoppingCategory} (Google's taxonomy) and resolves that mapping
 * up the category tree. A product's {@code g:google_product_category} comes from its category's mapping, or — when
 * that category has none — the nearest mapped ancestor, so mapping a top-level category covers all its descendants.
 *
 * @property int $GoogleShoppingCategoryID
 * @method GoogleShoppingCategory GoogleShoppingCategory()
 * @extends Extension<ProductCategory>
 */
class ProductCategoryGoogleExtension extends Extension
{
    private static array $has_one = [
        'GoogleShoppingCategory' => GoogleShoppingCategory::class,
    ];

    protected function updateCMSFields(FieldList $fields): void
    {
        // has_one auto-scaffolds a plain dropdown; remove it and add a tree picker once (scaffold-dup pattern).
        $fields->removeByName('GoogleShoppingCategoryID');

        $picker = TreeDropdownField::create(
            'GoogleShoppingCategoryID',
            _t(self::class . '.GoogleCategory', 'Google product category'),
            GoogleShoppingCategory::class
        )->setDescription(_t(
            self::class . '.GoogleCategoryDesc',
            'Used for the Google Shopping feed. Sub-categories inherit this unless they set their own.'
        ));

        $fields->addFieldsToTab('Root.Main', [
            HeaderField::create('GoogleFeedHeader', _t(self::class . '.GoogleFeedHeader', 'Google Shopping')),
            $picker,
        ]);
    }

    /**
     * The Google category effective for this product category: its own mapping if set, otherwise the nearest mapped
     * ancestor (walking up the ProductCategory tree). Null when nothing in the chain is mapped.
     */
    public function getActiveGoogleShoppingCategory(): ?GoogleShoppingCategory
    {
        $category = $this->owner;
        $guard = 0;

        while ($category instanceof ProductCategory && $category->exists() && $guard++ < 100) {
            if ((int) $category->getField('GoogleShoppingCategoryID') > 0) {
                $mapped = $category->GoogleShoppingCategory();
                if ($mapped instanceof GoogleShoppingCategory && $mapped->exists()) {
                    return $mapped;
                }
            }
            $category = $category->Parent();
        }

        return null;
    }
}
