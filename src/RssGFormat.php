<?php

declare(strict_types=1);

namespace SilverShop\ProductFeed\Google;

use SilverShop\ProductFeed\Format\ChannelFeed;
use SilverShop\ProductFeed\Format\FeedFormat;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * The RSS 2.0 + Google {@code g:} namespace serializer — the syntax half of the Google channel. It streams a
 * well-formed, XML-escaped feed: the {@code <rss><channel>} header with the shop's title/link/description, one
 * {@code <item>} per mapped product, then the closing tags.
 *
 * It renders whatever {@link GoogleChannelFeed::mapItem()} produces, handling three value shapes generically: a
 * scalar (one tag), a list of scalars (a repeated tag, e.g. {@code g:additional_image_link}), and a list of assoc
 * arrays or a single assoc array (a nested group, e.g. {@code g:shipping}).
 */
class RssGFormat implements FeedFormat
{
    use Injectable;

    private const INDENT = '    ';

    public function getContentType(): string
    {
        return 'application/xml; charset=utf-8';
    }

    public function getFileExtension(): string
    {
        return 'xml';
    }

    public function renderHeader(SiteConfig $siteConfig, ChannelFeed $channel): string
    {
        $title = trim((string) $siteConfig->getField('Title'));
        if ($title === '') {
            $title = 'Product feed';
        }
        $link = (string) Director::absoluteBaseURL();
        $description = trim((string) $siteConfig->getField('Tagline'));
        if ($description === '') {
            $description = $title;
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<rss xmlns:g="http://base.google.com/ns/1.0" version="2.0">' . "\n"
            . '  <channel>' . "\n"
            . '    <title>' . $this->escape($title) . '</title>' . "\n"
            . '    <link>' . $this->escape($link) . '</link>' . "\n"
            . '    <description>' . $this->escape($description) . '</description>' . "\n";
    }

    public function renderItem(array $mapped): string
    {
        return '    <item>' . "\n"
            . $this->renderTags($mapped, 3)
            . '    </item>' . "\n";
    }

    public function renderFooter(): string
    {
        return '  </channel>' . "\n" . '</rss>' . "\n";
    }

    /**
     * Render an attribute map into XML tags. Scalars become one tag; a list of scalars becomes repeated tags; a
     * nested array (assoc, or a list of assoc) becomes nested group(s). Empty values are skipped.
     *
     * @param array<string, mixed> $tags
     */
    private function renderTags(array $tags, int $depth): string
    {
        $out = '';
        foreach ($tags as $name => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            if (is_array($value)) {
                if (array_is_list($value)) {
                    foreach ($value as $element) {
                        $out .= is_array($element)
                            ? $this->group((string) $name, $element, $depth)
                            : $this->tag((string) $name, (string) $element, $depth);
                    }
                } else {
                    $out .= $this->group((string) $name, $value, $depth);
                }
                continue;
            }

            $out .= $this->tag((string) $name, (string) $value, $depth);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $children
     */
    private function group(string $name, array $children, int $depth): string
    {
        $pad = str_repeat(self::INDENT, $depth);

        return $pad . '<' . $name . '>' . "\n"
            . $this->renderTags($children, $depth + 1)
            . $pad . '</' . $name . '>' . "\n";
    }

    private function tag(string $name, string $value, int $depth): string
    {
        return str_repeat(self::INDENT, $depth) . '<' . $name . '>' . $this->escape($value) . '</' . $name . '>' . "\n";
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
