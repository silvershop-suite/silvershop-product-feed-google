<?php

declare(strict_types=1);

namespace SilverShop\ProductFeed\Google\Tests;

use DOMDocument;
use SilverShop\ProductFeed\Google\GoogleChannelFeed;
use SilverShop\ProductFeed\Google\RssGFormat;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * The RSS 2.0 + g: serializer: well-formed XML, the g: namespace, XML escaping (XSS-safe), repeated tags, nested
 * groups, empty-value skipping, and content type / extension.
 */
class RssGFormatTest extends SapphireTest
{
    protected $usesDatabase = true;

    private RssGFormat $format;

    protected function setUp(): void
    {
        parent::setUp();
        $this->format = RssGFormat::create();
    }

    public function testContentTypeAndExtension(): void
    {
        $this->assertStringContainsString('application/xml', $this->format->getContentType());
        $this->assertSame('xml', $this->format->getFileExtension());
    }

    public function testRendersScalarTags(): void
    {
        $item = $this->format->renderItem(['g:id' => 'RJ-001', 'title' => 'Rain Jacket']);
        $this->assertStringContainsString('<g:id>RJ-001</g:id>', $item);
        $this->assertStringContainsString('<title>Rain Jacket</title>', $item);
        $this->assertStringContainsString('<item>', $item);
        $this->assertStringContainsString('</item>', $item);
    }

    public function testEscapesXmlSpecialCharacters(): void
    {
        $item = $this->format->renderItem(['title' => 'Jack & Jill <b> "quote" \'q\'']);
        $this->assertStringContainsString('Jack &amp; Jill &lt;b&gt;', $item);
        $this->assertStringContainsString('&quot;quote&quot;', $item);
        $this->assertStringNotContainsString('<b>', $item);
    }

    public function testEscapesAmpersandInUrls(): void
    {
        $item = $this->format->renderItem(['link' => 'https://shop.example/p?a=1&b=2']);
        $this->assertStringContainsString('a=1&amp;b=2', $item);
    }

    public function testSkipsEmptyValues(): void
    {
        $item = $this->format->renderItem(['g:id' => 'X', 'g:brand' => null, 'g:mpn' => '', 'g:extra' => []]);
        $this->assertStringContainsString('<g:id>X</g:id>', $item);
        $this->assertStringNotContainsString('g:brand', $item);
        $this->assertStringNotContainsString('g:mpn', $item);
        $this->assertStringNotContainsString('g:extra', $item);
    }

    public function testRepeatedTagForListOfScalars(): void
    {
        $item = $this->format->renderItem(['g:additional_image_link' => ['https://x/a.jpg', 'https://x/b.jpg']]);
        $this->assertSame(2, substr_count($item, '<g:additional_image_link>'));
        $this->assertStringContainsString('<g:additional_image_link>https://x/a.jpg</g:additional_image_link>', $item);
    }

    public function testNestedGroupForShipping(): void
    {
        $item = $this->format->renderItem([
            'g:shipping' => [
                ['g:country' => 'NL', 'g:service' => 'Standard', 'g:price' => '4.95 EUR'],
            ],
        ]);
        $this->assertStringContainsString('<g:shipping>', $item);
        $this->assertStringContainsString('<g:country>NL</g:country>', $item);
        $this->assertStringContainsString('<g:price>4.95 EUR</g:price>', $item);
        $this->assertStringContainsString('</g:shipping>', $item);
    }

    public function testFullDocumentIsWellFormedXmlWithGoogleNamespace(): void
    {
        $channel = GoogleChannelFeed::create();
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->Title = 'My Shop';
        $siteConfig->write();

        $xml = $this->format->renderHeader($siteConfig, $channel)
            . $this->format->renderItem([
                'g:id' => 'RJ-001',
                'title' => 'Rain Jacket & Hood',
                'g:price' => '89.95 EUR',
                'g:availability' => 'in_stock',
                'g:additional_image_link' => ['https://x/a.jpg', 'https://x/b.jpg'],
            ])
            . $this->format->renderFooter();

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadXML($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();

        $this->assertTrue($loaded, 'Generated RSS must be well-formed XML');
        $this->assertSame([], $errors);
        $this->assertSame('rss', $dom->documentElement?->nodeName);
        $this->assertSame('http://base.google.com/ns/1.0', $dom->documentElement?->getAttribute('xmlns:g'));
        $this->assertStringContainsString('<title>My Shop</title>', $xml);
    }
}
