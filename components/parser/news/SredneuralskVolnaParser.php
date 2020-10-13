<?php

namespace app\components\parser\news;

use app\components\Helper;
use app\components\helper\secreate\DOMNodeParse;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTime;
use DateTimeZone;
use DOMElement;
use DOMNode;
use DOMText;
use Exception;
use lanfix\parser\Parser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;

/**
 * News parser from site http://www.sredneuralsk.info/
 * @author jcshow
 */
class SredneuralskVolnaParser implements ParserInterface
{
    use DOMNodeParse;

    public const USER_ID = 2;

    public const FEED_ID = 2;

    public const SITE_URL = 'http://www.sredneuralsk.info/';

    /**
     * @inheritDoc
     */
    public static function run(): array
    {
        return self::getNewsData();
    }

    /**
     * Function get fixed news count data
     * 
     * @param int $offset
     * @param int $limit
     * 
     * @return array
     * @throws Exception
     */
    public static function getNewsData(int $offset = 0, int $limit = 100): array
    {
        /** Get RSS news list */
        $curl = Helper::getCurl();
        $newsList = $curl->get(static::SITE_URL . "?format=feed&type=rss&limit=$limit&offset=$offset");
        if (! $newsList) {
            throw new Exception('Can not get news data');
        }

        /** Parse news from RSS */
        $articleListCrawler = new Crawler($newsList);
        $news = $articleListCrawler->filterXPath('//item');

        $result = [];
        foreach ($news as $item) {
            try {
                $post = self::getPostDetail($item);
            } catch (Exception $e) {
                continue;
            }

            $result[] = $post;
        }

        return $result;
    }

    /**
     * Function get post detail data
     * 
     * @param DOMElement $item
     * 
     * @return NewPost
     */
    public static function getPostDetail(DOMElement $item): NewsPost
    {
        $itemCrawler = new Crawler($item);

        /** Get item detail link */
        $link = $itemCrawler->filterXPath('//link')->text();

        /** Get title */
        $title = $itemCrawler->filterXPath('//title')->text();

        /** Get item datetime */
        $createdAt = new DateTime($itemCrawler->filterXPath('//pubDate')->text());
        $createdAt->setTimezone(new DateTimeZone('UTC'));
        $createdAt = $createdAt->format('c');

        /** Get preview picture */
        $picture = $itemCrawler->filterXPath('//enclosure')->attr('url');
        $lastUrlPart = basename($picture);
        $encodedLastUrlPart = urlencode($lastUrlPart);
        $finalLink = str_replace($lastUrlPart, $encodedLastUrlPart, $picture);

        /** Detail page parser creation */
        $curl = Helper::getCurl();
        $curlResult = $curl->get($link);
        $detailPageParser = new Parser($curlResult, true);
        $head = $detailPageParser->document->getHead();

        /** Get description */
        $description = '';
        foreach ($head->find('meta') ?? [] as $meta) {
            if ($meta->getAttribute('name') === 'description') {
                $description = $meta->getAttribute('content') ?: '';
                break;
            }
        }
        if (empty($description) === true) {
            throw new Exception('Empty post description');
        }

        /** @var NewsPost */
        $post = new NewsPost(static::class, $title, htmlspecialchars_decode($description), $createdAt, $link, $finalLink);

        $post->addItem(new NewsPostItem(NewsPostItem::TYPE_HEADER, $title,
            null, null, 1));

        /** Detail info crawler creation */
        $detailInfo = $itemCrawler->filterXPath('//description')->html();

        /** Skip if no content */
        if (!empty($detailInfo) === true) {
            self::appendPostAdditionalData($post, $detailInfo);
        }

        return $post;
    }

    /**
     * Function appends NewsPostItem objects to NewsPost with additional post data
     * 
     * @param NewsPost $post
     * @param string $content
     * 
     * @return void
     */
    public static function appendPostAdditionalData(NewsPost $post, string $content): void
    {
        $crawler = new Crawler($content);

        // Get item detail image
        $image = $crawler->filterXPath('//div[contains(@class, "K2FeedImage")]//img')->first();
        if ($image->count() === 1) {
            $detailImage = trim($image->attr('src') ?: '');
            $post->addItem(new NewsPostItem(NewsPostItem::TYPE_IMAGE, null, $detailImage));
        }

        // Get item h2
        $h2 = $crawler->filterXPath('//div[contains(@class, "K2FeedIntroText")]//p')->first();
        if ($h2->count() === 1 && ! empty($h2->text()) === true) {
            $post->addItem(new NewsPostItem(NewsPostItem::TYPE_HEADER, $h2->text(),
                null, null, 2));
        }

        $fullText = $crawler->filterXPath('//div[contains(@class, "K2FeedFullText")]')->getNode(0);

        //Parsing each child node of full text block
        foreach ($fullText->childNodes as $key => $node) {
            self::parseNode($post, $node);
        }
    }

    /**
     * Function parse single children of full text block and appends NewsPostItems founded
     * 
     * @param NewsPost $post
     * @param DOMNode $node
     * 
     * @return void
     */
    public static function parseNode(NewsPost $post, DOMNode $node): void
    {
        //Get non-empty quotes from nodes
        if (self::isQuoteType($node) && self::hasText($node)) {
            $post->addItem(new NewsPostItem(NewsPostItem::TYPE_QUOTE, $node->textContent));
            return;
        }

        //Get non-empty links from nodes
        if (self::isLinkType($node) && self::hasText($node)) {
            $link = $node->getAttribute('href');
            if ($link && $link !== '' && filter_var($link, FILTER_VALIDATE_URL)) {
                $linkText = self::hasText($node) ? $node->textContent : null;
                $post->addItem(new NewsPostItem(NewsPostItem::TYPE_LINK, $linkText, null, $link));
            }
            return;
        }

        //Get non-empty images from nodes
        if (self::isImageType($node)) {
            $imageLink = $node->getAttribute('src');

            if ($imageLink === '') {
                return;
            }

            $imageLink = UriResolver::resolve($imageLink, static::SITE_URL);

            $post->addItem(new NewsPostItem(NewsPostItem::TYPE_IMAGE, $node->getAttribute('alt'), $imageLink));
            return;
        }

        //Get non-empty text from nodes
        if (self::isParagraphType($node) && self::hasText($node)) {
            $pText = '';
            foreach($node->childNodes as $child) {
                if (! $child instanceof DOMText && self::notRegularTextFormat($child)) {
                    if (! empty($pText) === true) {
                        $post->addItem(new NewsPostItem(NewsPostItem::TYPE_TEXT, $pText));
                        $pText = '';
                    }
                    self::parseNode($post, $child);
                } else {
                    $pText .= $child->textContent;
                }
            }
            $pText = trim(html_entity_decode($pText), " \t\n\r\0\x0B\xC2\xA0");
            if (empty($pText) === true) {
                return;
            }
            $post->addItem(new NewsPostItem(NewsPostItem::TYPE_TEXT, $pText));
        }
    }

    /**
     * Function check if node has non-regular text format
     * 
     * @param DOMNode
     * 
     * @return bool
     */
    protected static function notRegularTextFormat(DOMNode $node)
    {
        return self::isQuoteType($node) === true
            || self::isLinkType($node) === true
            || self::isImageType($node) === true;
    }

    
}