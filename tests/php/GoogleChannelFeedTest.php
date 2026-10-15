<?php

declare(strict_types=1);

namespace SilverShop\ProductFeed\Google\Tests;

use SilverShop\CatalogData\Availability;
use SilverShop\CatalogData\CatalogProductData;
use SilverShop\CatalogData\CatalogProductDataFactory;
use SilverShop\CatalogData\Condition;
use SilverShop\Extension\ShopConfigExtension;
use SilverShop\Model\Variation\AttributeType;
use SilverShop\Model\Variation\AttributeValue;
use SilverShop\Model\Variation\Variation;
use SilverShop\Page\Product;
use SilverShop\Page\ProductCategory;
use SilverShop\ProductFeed\Google\GoogleChannelFeed;
use SilverShop\ProductFeed\Google\Model\GoogleShoppingCategory;
use SilverShop\ProductFeed\Google\Service\GoogleTaxonomy;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

/**
 * The Google field map from a real {@link \SilverShop\CatalogData\CatalogProductData}: id/title/price/availability
 * tokens, sale price, condition, identifier_exists, gtin/mpn (present + absent), item_group_id for variants,
 * color/size, product_type root-trim, and google_product_category via the taxonomy + parent-walk.
 */
class GoogleChannelFeedTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static bool $use_draft_site = true;

    private GoogleChannelFeed $channel;
    private CatalogProductDataFactory $factory;
    private ProductCategory $shop;
    private ProductCategory $apparel;

    protected function setUp(): void
    {
        parent::setUp();

        Config::modify()->set(ProductCategory::class, 'must_have_price', false);
        Config::modify()->set(Product::class, 'global_allow_purchase', true);
        Config::modify()->set(ShopConfigExtension::class, 'base_currency', 'EUR');

        $this->channel = GoogleChannelFeed::create();
        $this->factory = CatalogProductDataFactory::create();

        $this->shop = ProductCategory::create(['Title' => 'Shop']);
        $this->shop->write();
        $this->apparel = ProductCategory::create(['Title' => 'Apparel', 'ParentID' => $this->shop->ID]);
        $this->apparel->write();
    }

    private function newProduct(array $data = []): Product
    {
        $product = Product::create(array_merge([
            'Title' => 'Rain Jacket',
            'BasePrice' => 89.95,
            'InternalItemID' => 'RJ-001',
            'AllowPurchase' => true,
            'ParentID' => $this->apparel->ID,
        ], $data));
        $product->write();

        return $product;
    }

    private function map(Product|Variation $buyable): array
    {
        return $this->channel->mapItem($this->factory->forBuyable($buyable), $buyable);
    }

    public function testChannelIdentity(): void
    {
        $this->assertSame('google', $this->channel->getChannelKey());
        $this->assertSame('google.xml', $this->channel->getFileName());
    }

    public function testCoreFieldMap(): void
    {
        $mapped = $this->map($this->newProduct());

        $this->assertSame('RJ-001', $mapped['g:id']);
        $this->assertSame('Rain Jacket', $mapped['title']);
        $this->assertSame('89.95 EUR', $mapped['g:price']);
        $this->assertSame('in_stock', $mapped['g:availability']);
        $this->assertSame('new', $mapped['g:condition']);
        $this->assertStringStartsWith('http', $mapped['link']);
    }

    public function testAvailabilityOutOfStockToken(): void
    {
        $mapped = $this->map($this->newProduct(['AllowPurchase' => false]));
        $this->assertSame('out_of_stock', $mapped['g:availability']);
    }

    public function testMapsVoFieldsIncludingSalePrice(): void
    {
        // A crafted VO exercises the whole map (incl. sale price) independent of the pricing/discount stack.
        $vo = new CatalogProductData(
            'ID1',
            'GRP1',
            'Rain Jacket',
            'Waterproof.',
            'https://shop.example/p',
            'https://shop.example/i.jpg',
            ['https://shop.example/a.jpg', 'https://shop.example/b.jpg'],
            Availability::InStock,
            100.0,
            75.0,
            'EUR',
            'Acme',
            '4006381333931',
            'MPN1',
            null,
            Condition::New_,
            'Shop > Apparel',
            ['Colour' => 'Red', 'Size' => 'L'],
        );

        $mapped = $this->channel->mapItem($vo, null);

        $this->assertSame('ID1', $mapped['g:id']);
        $this->assertSame('GRP1', $mapped['g:item_group_id']);
        $this->assertSame('100.00 EUR', $mapped['g:price']);
        $this->assertSame('75.00 EUR', $mapped['g:sale_price']);
        $this->assertSame('Acme', $mapped['g:brand']);
        $this->assertSame('4006381333931', $mapped['g:gtin']);
        $this->assertSame('MPN1', $mapped['g:mpn']);
        $this->assertSame('Red', $mapped['g:color']);
        $this->assertSame('L', $mapped['g:size']);
        $this->assertSame('Apparel', $mapped['g:product_type']);
        $this->assertSame(['https://shop.example/a.jpg', 'https://shop.example/b.jpg'], $mapped['g:additional_image_link']);
    }

    public function testItemGroupIdForVariation(): void
    {
        $product = $this->newProduct(['InternalItemID' => 'RJ-001']);
        $variation = Variation::create(['ProductID' => $product->ID, 'Price' => 89.95, 'InternalItemID' => 'RJ-001-RED']);
        $variation->write();

        $mapped = $this->map($variation);
        $this->assertSame('RJ-001-RED', $mapped['g:id']);
        $this->assertSame('RJ-001', $mapped['g:item_group_id']);
    }

    public function testSimpleProductHasNoItemGroupId(): void
    {
        $mapped = $this->map($this->newProduct());
        $this->assertNull($mapped['g:item_group_id']);
    }

    public function testColorAndSizeFromAttributes(): void
    {
        $product = $this->newProduct();
        $colourType = AttributeType::create(['Name' => 'Colour', 'Label' => 'Colour']);
        $colourType->write();
        $colour = AttributeValue::create(['Value' => 'Red', 'TypeID' => $colourType->ID]);
        $colour->write();
        $sizeType = AttributeType::create(['Name' => 'Size', 'Label' => 'Size']);
        $sizeType->write();
        $size = AttributeValue::create(['Value' => 'L', 'TypeID' => $sizeType->ID]);
        $size->write();

        $variation = Variation::create(['ProductID' => $product->ID, 'Price' => 89.95]);
        $variation->write();
        $variation->AttributeValues()->add($colour);
        $variation->AttributeValues()->add($size);

        $mapped = $this->map($variation);
        $this->assertSame('Red', $mapped['g:color']);
        $this->assertSame('L', $mapped['g:size']);
    }

    public function testProductTypeRootIsTrimmed(): void
    {
        // Default product_type_trim_segments = 1 drops the "Shop" root.
        $mapped = $this->map($this->newProduct());
        $this->assertSame('Apparel', $mapped['g:product_type']);
    }

    public function testProductTypeRootTrimConfigurable(): void
    {
        Config::modify()->set(GoogleChannelFeed::class, 'product_type_trim_segments', 0);
        $mapped = $this->map($this->newProduct());
        $this->assertSame('Shop > Apparel', $mapped['g:product_type']);
    }

    public function testIdentifierExistsNoWhenNoIdentifiers(): void
    {
        // No GTIN, no MPN, no Brand assigned → identifier_exists: no.
        $mapped = $this->map($this->newProduct());
        $this->assertSame('no', $mapped['g:identifier_exists']);
    }

    public function testGtinPresentAndAbsent(): void
    {
        $withGtin = $this->newProduct(['InternalItemID' => 'RJ-G', 'GTIN' => '4006381333931']);
        $mappedWith = $this->map($withGtin);
        $this->assertSame('4006381333931', $mappedWith['g:gtin']);
        // identifier_exists omitted when a GTIN is present.
        $this->assertNull($mappedWith['g:identifier_exists']);

        $withoutGtin = $this->newProduct(['InternalItemID' => 'RJ-N']);
        $this->assertNull($this->map($withoutGtin)['g:gtin']);
    }

    public function testGoogleProductCategoryViaTaxonomyParentWalk(): void
    {
        GoogleTaxonomy::create()->seedFrom("1 - Apparel & Accessories\n1604 - Apparel & Accessories > Clothing\n");
        $clothingNode = GoogleShoppingCategory::get()->filter('GoogleID', 1604)->first();

        // Map the top "Shop" category; the product sits under "Apparel" (no own mapping) → resolves via the walk.
        $this->shop->GoogleShoppingCategoryID = $clothingNode->ID;
        $this->shop->write();

        $mapped = $this->map($this->newProduct());
        $this->assertSame('1604', $mapped['g:google_product_category']);
    }

    public function testGoogleProductCategoryAsPath(): void
    {
        Config::modify()->set(GoogleChannelFeed::class, 'google_product_category_as', 'path');
        GoogleTaxonomy::create()->seedFrom("1 - Apparel & Accessories\n1604 - Apparel & Accessories > Clothing\n");
        $clothingNode = GoogleShoppingCategory::get()->filter('GoogleID', 1604)->first();
        $this->apparel->GoogleShoppingCategoryID = $clothingNode->ID;
        $this->apparel->write();

        $mapped = $this->map($this->newProduct());
        $this->assertSame('Apparel & Accessories > Clothing', $mapped['g:google_product_category']);
    }

    public function testShippingOmittedByDefault(): void
    {
        $mapped = $this->map($this->newProduct());
        $this->assertSame([], $mapped['g:shipping']);
    }

    public function testShippingEmittedWhenEnabled(): void
    {
        Config::modify()->set(GoogleChannelFeed::class, 'shipping_enabled', true);
        Config::modify()->set(GoogleChannelFeed::class, 'shipping', [
            ['country' => 'NL', 'service' => 'Standard', 'price' => '4.95 EUR'],
        ]);

        $mapped = $this->map($this->newProduct());
        $this->assertSame([['g:country' => 'NL', 'g:service' => 'Standard', 'g:price' => '4.95 EUR']], $mapped['g:shipping']);
    }
}
