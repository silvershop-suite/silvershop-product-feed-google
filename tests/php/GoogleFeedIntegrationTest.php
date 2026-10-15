<?php

declare(strict_types=1);

namespace SilverShop\ProductFeed\Google\Tests;

use DOMDocument;
use SilverShop\Extension\ShopConfigExtension;
use SilverShop\Model\Variation\Variation;
use SilverShop\Page\Product;
use SilverShop\Page\ProductCategory;
use SilverShop\ProductFeed\Google\GoogleChannelFeed;
use SilverShop\ProductFeed\Google\Model\GoogleShoppingCategory;
use SilverShop\ProductFeed\Google\Service\GoogleTaxonomy;
use SilverShop\ProductFeed\Service\ProductFeedGenerator;
use SilverShop\ProductFeed\Service\ProductFeedSelector;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;

/**
 * End-to-end: the product-feed core generator + the Google channel + RssGFormat produce a valid Google RSS feed
 * from real catalogue data — well-formed XML, the g: namespace, variants grouped by item_group_id, stock-driven
 * availability, and the resolved google_product_category.
 */
class GoogleFeedIntegrationTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static bool $use_draft_site = true;

    private string $outputDir;

    protected function setUp(): void
    {
        parent::setUp();

        Config::modify()->set(ProductFeedSelector::class, 'reading_stage', Versioned::DRAFT);
        Config::modify()->set(ProductCategory::class, 'must_have_price', false);
        Config::modify()->set(Product::class, 'global_allow_purchase', true);
        Config::modify()->set(ShopConfigExtension::class, 'base_currency', 'EUR');

        $this->outputDir = sys_get_temp_dir() . '/pfg-' . uniqid('', true);
        Config::modify()->set(ProductFeedGenerator::class, 'output_dir', $this->outputDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->outputDir)) {
            foreach (glob($this->outputDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->outputDir);
        }
        parent::tearDown();
    }

    public function testGeneratesValidGoogleRssFromCatalogue(): void
    {
        $shop = ProductCategory::create(['Title' => 'Shop']);
        $shop->write();
        $apparel = ProductCategory::create(['Title' => 'Apparel', 'ParentID' => $shop->ID]);
        $apparel->write();

        GoogleTaxonomy::create()->seedFrom("1 - Apparel & Accessories\n1604 - Apparel & Accessories > Clothing\n");
        $node = GoogleShoppingCategory::get()->filter('GoogleID', 1604)->first();
        $apparel->GoogleShoppingCategoryID = $node->ID;
        $apparel->write();

        $simple = Product::create([
            'Title' => 'Hat',
            'BasePrice' => 19.95,
            'InternalItemID' => 'HAT-1',
            'AllowPurchase' => true,
            'ParentID' => $apparel->ID,
        ]);
        $simple->write();

        $varied = Product::create([
            'Title' => 'Jacket',
            'BasePrice' => 89.95,
            'InternalItemID' => 'JKT-1',
            'AllowPurchase' => true,
            'ParentID' => $apparel->ID,
        ]);
        $varied->write();
        foreach (['JKT-1-S', 'JKT-1-M'] as $sku) {
            $v = Variation::create(['ProductID' => $varied->ID, 'Price' => 89.95, 'InternalItemID' => $sku]);
            $v->write();
        }

        $result = ProductFeedGenerator::create()->generate(GoogleChannelFeed::create());
        $this->assertSame($this->outputDir . '/google.xml', $result['path']);
        $this->assertSame(3, $result['items']); // Hat + 2 jacket variations

        $xml = (string) file_get_contents($result['path']);

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadXML($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();

        $this->assertTrue($loaded, 'Generated Google feed must be well-formed XML');
        $this->assertSame([], $errors);
        $this->assertSame('http://base.google.com/ns/1.0', $dom->documentElement?->getAttribute('xmlns:g'));

        // Simple product + both variations present; variations share the parent item_group_id.
        $this->assertStringContainsString('<g:id>HAT-1</g:id>', $xml);
        $this->assertStringContainsString('<g:id>JKT-1-S</g:id>', $xml);
        $this->assertStringContainsString('<g:id>JKT-1-M</g:id>', $xml);
        $this->assertSame(2, substr_count($xml, '<g:item_group_id>JKT-1</g:item_group_id>'));
        $this->assertStringContainsString('<g:availability>in_stock</g:availability>', $xml);
        $this->assertStringContainsString('<g:google_product_category>1604</g:google_product_category>', $xml);
        $this->assertStringContainsString('<g:product_type>Apparel</g:product_type>', $xml);
    }
}
