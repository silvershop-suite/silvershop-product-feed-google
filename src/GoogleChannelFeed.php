<?php

declare(strict_types=1);

namespace SilverShop\ProductFeed\Google;

use SilverShop\CatalogData\Availability;
use SilverShop\CatalogData\CatalogProductData;
use SilverShop\Model\Buyable;
use SilverShop\Model\Variation\Variation;
use SilverShop\Page\Product;
use SilverShop\Page\ProductCategory;
use SilverShop\ProductFeed\Format\AbstractChannelFeed;
use SilverShop\ProductFeed\Format\FeedFormat;
use SilverShop\ProductFeed\Google\Model\GoogleShoppingCategory;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injector;

/**
 * The Google Merchant channel (key {@code google}): maps the shared {@link CatalogProductData} onto Google's
 * {@code g:} product attributes, paired with {@link RssGFormat} (RSS 2.0). Registered into the product-feed core's
 * channel registry via config — the core stays Google-agnostic.
 *
 * Most attributes come straight from the VO (so a product's {@code g:id} equals its {@code content_ids} in
 * conversion tracking by construction). {@code g:google_product_category} needs catalogue context the VO does not
 * carry, so it is resolved from the source buyable's category via {@link GoogleShoppingCategory} + the
 * parent-chain walk. {@code g:product_type} is the shop's own path with the site root trimmed.
 */
class GoogleChannelFeed extends AbstractChannelFeed
{
    use Configurable;

    /**
     * How to emit {@code g:google_product_category}: the numeric Google id ({@code id}, default) or the full
     * category path ({@code path}).
     */
    private static string $google_product_category_as = 'id';

    /**
     * How many leading segments to drop from {@code g:product_type} (the shop's own category path). 1 (default)
     * drops the catalogue root, e.g. "Shop > Books > E-Books" → "Books > E-Books". 0 keeps the whole path.
     */
    private static int $product_type_trim_segments = 1;

    /**
     * Attribute labels (case-insensitive) mapped to {@code g:color} / {@code g:size}.
     *
     * @var array<int, string>
     */
    private static array $color_attributes = ['color', 'colour'];

    /**
     * @var array<int, string>
     */
    private static array $size_attributes = ['size'];

    /**
     * Whether to emit {@code g:shipping} (usually configured account-side in Merchant Center, so off by default).
     */
    private static bool $shipping_enabled = false;

    /**
     * Flat shipping rates emitted when {@see $shipping_enabled}: a list of {@code {country, service, price}}.
     *
     * @var array<int, array{country?: string, service?: string, price?: string}>
     */
    private static array $shipping = [];

    public function getChannelKey(): string
    {
        return 'google';
    }

    public function getTitle(): string
    {
        return 'Google Merchant';
    }

    public function getFormat(): FeedFormat
    {
        return Injector::inst()->get(RssGFormat::class);
    }

    public function mapItem(CatalogProductData $data, ?Buyable $buyable = null): array
    {
        $attributes = $data->attributes();

        return [
            'g:id' => $data->feedId(),
            'title' => $data->title(),
            'description' => $data->description(),
            'link' => $data->link(),
            'g:image_link' => $data->imageLink(),
            'g:additional_image_link' => $data->additionalImageLinks(),
            'g:availability' => $this->availabilityToken($data->availability()),
            'g:price' => $data->formattedPrice(),
            'g:sale_price' => $data->formattedSalePrice(),
            'g:brand' => $data->brand(),
            'g:gtin' => $data->gtin(),
            'g:mpn' => $data->mpn(),
            'g:identifier_exists' => $data->identifierExists() === false ? 'no' : null,
            'g:condition' => $data->condition()->value,
            'g:google_product_category' => $this->googleProductCategory($buyable),
            'g:product_type' => $this->trimmedProductType($data->productType()),
            'g:item_group_id' => $data->itemGroupId(),
            'g:color' => $this->attributeValue($attributes, (array) static::config()->get('color_attributes')),
            'g:size' => $this->attributeValue($attributes, (array) static::config()->get('size_attributes')),
            'g:shipping' => $this->shipping(),
        ];
    }

    private function availabilityToken(Availability $availability): string
    {
        return match ($availability) {
            Availability::InStock => 'in_stock',
            Availability::OutOfStock => 'out_of_stock',
            Availability::Preorder => 'preorder',
            Availability::Backorder => 'backorder',
        };
    }

    /**
     * Resolve {@code g:google_product_category} from the source buyable's category (its own mapping or the nearest
     * mapped ancestor). Null when there is no buyable, category or mapping.
     */
    private function googleProductCategory(?Buyable $buyable): ?string
    {
        $product = $buyable instanceof Variation ? $buyable->Product() : $buyable;
        if (!$product instanceof Product || !$product->exists()) {
            return null;
        }

        $category = $this->primaryCategory($product);
        if (!$category instanceof ProductCategory || !$category->hasMethod('getActiveGoogleShoppingCategory')) {
            return null;
        }

        $googleCategory = $category->getActiveGoogleShoppingCategory();
        if (!$googleCategory instanceof GoogleShoppingCategory || !$googleCategory->exists()) {
            return null;
        }

        return static::config()->get('google_product_category_as') === 'path'
            ? (string) $googleCategory->FullPath
            : (string) $googleCategory->GoogleID;
    }

    private function primaryCategory(Product $product): ?ProductCategory
    {
        $parent = $product->Parent();
        if ($parent instanceof ProductCategory && $parent->exists()) {
            return $parent;
        }

        // Guarded: getCategories() does byIDs($ids), and in SS6 byIDs([]) throws "filter against an empty set".
        if ($product->getCategoryIDs() !== []) {
            $first = $product->getCategories()->first();
            if ($first instanceof ProductCategory) {
                return $first;
            }
        }

        return null;
    }

    private function trimmedProductType(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $segments = array_values(array_filter(
            array_map('trim', explode(' > ', $path)),
            static fn (string $s): bool => $s !== ''
        ));

        $trim = (int) static::config()->get('product_type_trim_segments');
        if ($trim > 0) {
            $segments = array_slice($segments, $trim);
        }

        return $segments === [] ? null : implode(' > ', $segments);
    }

    /**
     * First attribute value whose label (case-insensitive) is in $labels, or null.
     *
     * @param array<string, string> $attributes
     * @param array<int, string> $labels
     */
    private function attributeValue(array $attributes, array $labels): ?string
    {
        $wanted = array_map('strtolower', $labels);
        foreach ($attributes as $label => $value) {
            if (in_array(strtolower((string) $label), $wanted, true) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * {@code g:shipping} groups (a list of {@code {g:country, g:service, g:price}}) when shipping emission is on,
     * else [] (omitted).
     *
     * @return array<int, array<string, string>>
     */
    private function shipping(): array
    {
        if (!static::config()->get('shipping_enabled')) {
            return [];
        }

        $groups = [];
        foreach ((array) static::config()->get('shipping') as $rate) {
            if (!is_array($rate)) {
                continue;
            }
            $group = [];
            if (!empty($rate['country'])) {
                $group['g:country'] = (string) $rate['country'];
            }
            if (!empty($rate['service'])) {
                $group['g:service'] = (string) $rate['service'];
            }
            if (isset($rate['price']) && (string) $rate['price'] !== '') {
                $group['g:price'] = (string) $rate['price'];
            }
            if ($group !== []) {
                $groups[] = $group;
            }
        }

        return $groups;
    }
}
