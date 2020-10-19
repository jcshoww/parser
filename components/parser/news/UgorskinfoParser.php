<?php

namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTime;
use DateTimeZone;
use DOMElement;
use DOMNode;
use Exception;
use lanfix\parser\Parser;
use lanfix\parser\src\Element;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;

/**
 * News parser from site http://ugorskinfo.ru
 * @author jcshow
 */
class UgorskinfoParser implements ParserInterface
{
    public const USER_ID = 2;

    public const FEED_ID = 2;

    public const SITE_URL = 'http://ugorskinfo.ru';

    protected static $parsedEntities = ['a', 'img'];

    /**
     * @inheritDoc
     */
    public static function run(): array
    {
        return self::getNewsData();
    }

    /**
     * Function get news data
     * 
     * @return array
     * @throws Exception
     */
    public static function getNewsData(): array
    {
        /** Вырубаем нотисы */
        error_reporting(E_ALL & ~E_NOTICE);

        /** Get RSS news list */
        $curl = Helper::getCurl();
        $newsList = $curl->get(static::SITE_URL . "/index.php/novosti?format=feed&type=rss");
        if (! $newsList) {
            throw new Exception('Can not get news data');
        }

        /** Parse news from RSS */
        $newsListCrawler = new Crawler($newsList);
        $news = $newsListCrawler->filterXPath('//item');

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
            $description = $title;
        }

        /** @var NewsPost */
        $post = new NewsPost(static::class, $title, htmlspecialchars_decode($description), $createdAt, $link, null);

        /** Detail info crawler creation */
        $detailInfo = $itemCrawler->filterXPath('//description')->html();

        $body = $detailPageParser->document->getBody();

        /** Skip if no content */
        if (! empty($body) === true) {
            self::appendPostAdditionalData($post, $body, $detailInfo);
        }

        return $post;
    }

    /**
     * Function appends NewsPostItem objects to NewsPost with additional post data
     * 
     * @param NewsPost $post
     * @param Element $content
     * @param string $content
     * 
     * @return void
     */
    public static function appendPostAdditionalData(NewsPost $post, Element $content, string $preview): void
    {
        $crawler = new Crawler($preview);

        // Get item detail image
        $image = $crawler->filterXPath('//div[contains(@class, "K2FeedImage")]//img')->first();
        if ($image->count() === 1) {
            $detailImage = self::cleanUrl($image->attr('src') ?: '');
            $post->addItem(new NewsPostItem(NewsPostItem::TYPE_IMAGE, null, $detailImage));
        }
        
        // Get item text
        $introText = $crawler->filterXPath('//div[contains(@class, "K2FeedIntroText")]')->getNode(0);
        if (! empty($introText) === true) {
            foreach ($introText->childNodes as $node) {
                self::parseNode($post, $node);
            }
        }

        //Get full text block
        $fullTextBlock = $crawler->filterXPath('//div[contains(@class, "K2FeedFullText")]')->getNode(0);
        if (! empty($fullTextBlock) === true) {
            //Parsing each child node of full text block
            foreach ($fullTextBlock->childNodes as $node) {
                self::parseNode($post, $node);
            }
        }

        //Get item video
        $videoBlock = $content->findOne('.itemVideoBlock');
        if (! empty($videoBlock) === true) {
            $video = $videoBlock->findOne('iframe');
            $video = trim($video->getAttribute('src') ?: '');
            $videoId = preg_replace("/(.+)(\/)([^\/\?]+)([\?]{0,1})(.*)$/", '$3', $video);
            $post->addItem(new NewsPostItem(NewsPostItem::TYPE_VIDEO, null,
                null, null, null, $videoId));
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

        //Get non-empty links from nodes
        if (self::isLinkType($node) && self::hasText($node)) {
            $link = self::cleanUrl($node->getAttribute('href'));
            if ($link && $link !== '' && filter_var($link, FILTER_VALIDATE_URL)) {
                $linkText = self::hasText($node) ? $node->textContent : null;
                $post->addItem(new NewsPostItem(NewsPostItem::TYPE_LINK, $linkText, null, $link));
            }
            return;
        }

        //Get direct text nodes
        if (self::isText($node)) {
            if (self::hasText($node)) {
                $textContent = htmlspecialchars_decode($node->textContent);
                if (empty(preg_replace('/^\p{Z}+|\p{Z}+$/u', '', $textContent)) === true) {
                    return;
                }
                $post->addItem(new NewsPostItem(NewsPostItem::TYPE_TEXT, $textContent));
            }
            return;
        }

        //Check if some required to parse entities exists inside node
        $needRecursive = false;
        foreach (self::$parsedEntities as $entity) {
            if ($node->getElementsByTagName("$entity")->length > 0) {
                $needRecursive = true;
                break;
            }
        }

        //Get entire node text if we not need to parse any special entities, go recursive otherwise
        if ($needRecursive === false) {
            $textContent = htmlspecialchars_decode($node->textContent);
            if (empty(preg_replace('/^\p{Z}+|\p{Z}+$/u', '', $textContent)) === true) {
                return;
            }
            $post->addItem(new NewsPostItem(NewsPostItem::TYPE_TEXT, $textContent));
        } else {
            foreach($node->childNodes as $child) {
                self::parseNode($post, $child);
            }
        }
    }
    
    /**
     * Function cleans text from bad symbols
     * 
     * @param string $text
     * 
     * @return string|null
     */
    protected static function cleanText(string $text): ?string
    {
        $transformedText = preg_replace('/\r\n/', '', $text);
        $transformedText = preg_replace('/\<script.*\<\/script>/', '', $transformedText);
        $transformedText = html_entity_decode($transformedText);
        return preg_replace('/^\p{Z}+|\p{Z}+$/u', '', htmlspecialchars_decode($transformedText));
    }

    /**
     * Function clean dangerous urls
     * 
     * @param string $url
     * 
     * @return string
     */
    protected static function cleanUrl(string $url): string
    {
        return filter_var($url, FILTER_SANITIZE_ENCODED|FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    }

    /**
     * Function check if node text content not empty
     * 
     * @param DOMNode $node
     * 
     * @return bool
     */
    protected static function hasActualText(DOMNode $node): bool
    {
        return trim($node->textContent) !== '';
    }

    /**
     * Function check if node text content not empty
     * 
     * @param DOMNode $node
     * 
     * @return bool
     */
    protected static function hasText(DOMNode $node): bool
    {
        return trim($node->textContent) !== '';
    }

    /**
     * Function check if node is <p></p>
     * 
     * @param DOMNode
     * 
     * @return bool
     */
    protected static function isParagraphType(DOMNode $node): bool
    {
        return isset($node->tagName) === true && $node->tagName === 'p';
    }

    /**
     * Function check if node is quote
     * 
     * @param DOMNode
     * 
     * @return bool
     */
    protected static function isQuoteType(DOMNode $node): bool
    {
        return isset($node->tagName) === true && in_array($node->tagName, ['blockquote']);
    }

    /**
     * Function check if node is <a></a>
     * 
     * @param DOMNode
     * 
     * @return bool
     */
    protected static function isLinkType(DOMNode $node): bool
    {
        return isset($node->tagName) === true && $node->tagName === 'a';
    }

    /**
     * Function check if node is image
     * 
     * @param DOMNode
     * 
     * @return bool
     */
    protected static function isImageType(DOMNode $node): bool
    {
        return isset($node->tagName) === true && $node->tagName === 'img';
    }

    /**
     * Function check if node is #text
     * 
     * @param DOMNode
     * 
     * @return bool
     */
    protected static function isText(DOMNode $node): bool
    {
        return $node->nodeName === '#text';
    }
} 