<?php

declare(strict_types=1);

namespace SilverShop\ProductFeed\Google\Tests;

use SilverShop\Page\ProductCategory;
use SilverShop\ProductFeed\Google\Model\GoogleShoppingCategory;
use SilverShop\ProductFeed\Google\Service\GoogleTaxonomy;
use SilverStripe\Dev\SapphireTest;

/**
 * Taxonomy seeding (parse, parent links from the path, idempotency) and the per-ProductCategory mapping with the
 * parent-chain walk that resolves {@code google_product_category}.
 */
class GoogleTaxonomyTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static bool $use_draft_site = true;

    private const FIXTURE = <<<TXT
        # Google_Product_Taxonomy_Version: test
        1 - Apparel & Accessories
        1604 - Apparel & Accessories > Clothing
        2271 - Apparel & Accessories > Clothing > Dresses
        TXT;

    private function seedFixture(): int
    {
        return GoogleTaxonomy::create()->seedFrom(self::FIXTURE);
    }

    public function testSeedCreatesNodesWithTitlesAndPaths(): void
    {
        $this->assertSame(3, $this->seedFixture());
        $this->assertSame(3, GoogleShoppingCategory::get()->count());

        $dresses = GoogleShoppingCategory::get()->filter('GoogleID', 2271)->first();
        $this->assertNotNull($dresses);
        $this->assertSame('Dresses', $dresses->Title);
        $this->assertSame('Apparel & Accessories > Clothing > Dresses', $dresses->FullPath);
    }

    public function testSeedRebuildsParentLinksFromPath(): void
    {
        $this->seedFixture();

        $dresses = GoogleShoppingCategory::get()->filter('GoogleID', 2271)->first();
        $clothing = GoogleShoppingCategory::get()->filter('GoogleID', 1604)->first();
        $apparel = GoogleShoppingCategory::get()->filter('GoogleID', 1)->first();

        $this->assertSame($clothing->ID, $dresses->ParentID);
        $this->assertSame($apparel->ID, $clothing->ParentID);
        $this->assertSame(0, (int) $apparel->ParentID);
    }

    public function testSeedIsIdempotent(): void
    {
        $this->seedFixture();
        $this->seedFixture();

        $this->assertSame(3, GoogleShoppingCategory::get()->count());
    }

    public function testSkipsCommentAndBlankLines(): void
    {
        $count = GoogleTaxonomy::create()->seedFrom("# header\n\n  \n1 - Animals\n");
        $this->assertSame(1, $count);
    }

    public function testCategoryMappingResolvesOwn(): void
    {
        $this->seedFixture();
        $dressesNode = GoogleShoppingCategory::get()->filter('GoogleID', 2271)->first();

        $category = ProductCategory::create(['Title' => 'Summer Dresses', 'GoogleShoppingCategoryID' => $dressesNode->ID]);
        $category->write();

        $resolved = $category->getActiveGoogleShoppingCategory();
        $this->assertNotNull($resolved);
        $this->assertSame(2271, (int) $resolved->GoogleID);
    }

    public function testCategoryMappingWalksUpToMappedAncestor(): void
    {
        $this->seedFixture();
        $clothingNode = GoogleShoppingCategory::get()->filter('GoogleID', 1604)->first();

        $parent = ProductCategory::create(['Title' => 'Clothing', 'GoogleShoppingCategoryID' => $clothingNode->ID]);
        $parent->write();

        // Child category with NO own mapping inherits the parent's via the walk.
        $child = ProductCategory::create(['Title' => 'T-Shirts', 'ParentID' => $parent->ID]);
        $child->write();

        $resolved = $child->getActiveGoogleShoppingCategory();
        $this->assertNotNull($resolved);
        $this->assertSame(1604, (int) $resolved->GoogleID);
    }

    public function testUnmappedChainResolvesToNull(): void
    {
        $this->seedFixture();
        $category = ProductCategory::create(['Title' => 'Unmapped']);
        $category->write();

        $this->assertNull($category->getActiveGoogleShoppingCategory());
    }
}
