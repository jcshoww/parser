<?php

namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTime;
use DateTimeZone;
use DOMNode;
use Exception;
use SimpleXMLElement;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;

/**
 * News parser from site https://ngs55.ru/
 * @author jcshow
 */
class Ngs55Parser implements ParserInterface
{
    public const USER_ID = 2;

    public const FEED_ID = 2;

    public const SITE_URL = 'https://ngs55.ru';

    public const DUMMY_SCREEN_RESOLUTION_IMAGE_PATTERN_REPLACE_VALUE = '_710.';

    /** @var array */
    protected static $parsedEntities = ['a', 'img', 'blockquote', 'figcaption', 'iframe'];

    /** @var array */
    protected static $preservedItemTypes = ['images', 'iframe'];

    /** @var array */
    protected static $scriptData;

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
     * @param int $limit
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
        $newsList = $curl->get(static::SITE_URL . "/news/rss/");
        if (! $newsList) {
            throw new Exception('Can not get news data');
        }

        /** Parse news from RSS */
        //:NOTE Symfony crawler can't read this file through Xpath //item, somewhy...
        $newsListXml = new SimpleXMLElement($newsList);
        $posts = [];
        foreach ($newsListXml->channel->item as $item) {
            try {
                $post = self::getPostDetail($item);
            } catch (Exception $e) {
                continue;
            }

            $posts[] = $post;
        }

        return $posts;
    }

    /**
     * Function get post detail data
     * 
     * @param SimpleXMLElement $item
     * 
     * @return NewPost
     */
    public static function getPostDetail(SimpleXMLElement $item): NewsPost
    {
        /** Get item detail link */
        $link = self::cleanUrl($item->link);

        /** Get title */
        $title = self::cleanText($item->title);

        /** Get item datetime */
        $createdAt = new DateTime($item->pubDate);
        $createdAt->setTimezone(new DateTimeZone('UTC'));
        $createdAt = $createdAt->format('c');

        /** Get description */
        $description = self::cleanText($item->description);

        //Get image if exists
        $picture = null;
        $imageBlock = $item->enclosure;
        if (! empty($imageBlock) === true) {
            $picture = self::cleanUrl($imageBlock->attributes()->url);
        }

        /** @var NewsPost */
        $post = new NewsPost(static::class, $title, $description, $createdAt, $link, $picture);

        /** Detail page parser creation */
        $curl = Helper::getCurl();
        $curlResult = $curl->get($link);
        $curlResult = preg_replace('/\<nobr[^>]*\>/', '', $curlResult);
        $curlResult = preg_replace('/\<\/nobr\>/', '', $curlResult);
        $crawler = new Crawler($curlResult);

        self::fillScriptData($curlResult);

        self::removeNodes($crawler, '//comment() | //br | //hr | //script | //link | //style');
        self::removeNodes($crawler, '//div[contains(@class, "H3cf")]//div[@id="record-header"]');
        self::removeNodes($crawler, '//div[contains(@class, "H3cf")]//figure[1]//picture');
        self::removeNodes($crawler, '//div[contains(@class, "H3cf")]//div[contains(@class, "Pdf")]');
        self::removeNodes($crawler, '//div[contains(@class, "H3cf")]//div[contains(@class, "IHakv")]');
        self::removeNodes($crawler, '//div[contains(@class, "H3cf")]//div[contains(@class, "J5apr")]');

        $detailPage = $crawler->filterXpath('//div[contains(@class, "H3cf")]//div[1]')->getNode(0);

        // parse detail page for texts
        foreach ($detailPage->childNodes as $node) {
            self::parseNode($post, $node);
        }
        
        self::appendVideoFromScript($post);

        return $post;
    }

    /**
     * Function parse single children of full text block and appends NewsPostItems founded
     * 
     * @param NewsPost $post
     * @param DOMNode $node
     * @param bool $skipText
     * 
     * @return void
     */
    public static function parseNode(NewsPost $post, DOMNode $node, bool $skipText = false): void
    {
        //Get figcaption
        if (isset($node->tagName) && $node->tagName === 'figcaption') {
            foreach ($node->childNodes as $subnode) {
                self::parseNode($post, $subnode);
            }
            return;
        }

        //Get non-empty quotes from nodes
        if (self::isQuoteType($node) && self::hasText($node)) {
            $post->addItem(new NewsPostItem(NewsPostItem::TYPE_QUOTE, $node->textContent));
            return;
        }

        //Get non-empty images from nodes
        if (self::isImageType($node)) {
            $imageLink = self::cleanUrl($node->getAttribute('src'));
            if ($imageLink === '') {
                $imageLink = self::getImageUrlFromScript();
            }

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
            if ($link && $link !== '') {
                if (! preg_match('/http[s]?/', $link)) {
                    $link = UriResolver::resolve($link, static::SITE_URL);
                }
                if (filter_var($link, FILTER_VALIDATE_URL)) {
                    $linkText = self::hasText($node) ? $node->textContent : null;
                    $linkText = self::cleanText($linkText);
                    $post->addItem(new NewsPostItem(NewsPostItem::TYPE_LINK, $linkText, null, $link));
                }
            }
            return;
        }

        //Get direct text nodes
        if (self::isText($node)) {
            if ($skipText === false && self::hasText($node)) {
                $textContent = self::cleanText($node->textContent);
                if (strlen($post->description) >= strlen($textContent)) {
                    if (preg_match('/' . preg_quote($textContent, '/') . '/', $post->description)) {
                        return;
                    }
                }

                if (self::hasActualText($textContent) === true) {
                    $post->addItem(new NewsPostItem(NewsPostItem::TYPE_TEXT, $textContent));
                }
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
        if ($skipText === false && $needRecursive === false) {
            $textContent = self::cleanText($node->textContent);
            if (strlen($post->description) >= strlen($textContent)) {
                if (preg_match('/' . preg_quote($textContent, '/') . '/', $post->description)) {
                    return;
                }
            }

            if (self::hasActualText($textContent) === true) {
                $post->addItem(new NewsPostItem(NewsPostItem::TYPE_TEXT, $textContent));
            }
        } else {
            foreach($node->childNodes as $child) {
                self::parseNode($post, $child, $skipText);
            }
        }
    }

    /**
     * Function parses detail page curl result and fill script data with data from json
     * need to parse js-hide data from article
     * 
     * @param string $curlResult
     */
    protected static function fillScriptData(string $curlResult): void
    {
        $crawler = new Crawler($curlResult);
        $initialState = '';
        $scripts = $crawler->filterXPath('//script');
        foreach ($scripts as $script) {
            if (preg_match('/window.__INITIAL_STATE/', $script->textContent)) {
                $initialState = $script->textContent;
                break;
            }
        }
        $initialState = preg_replace('/window\.\_\_INITIAL_STATE\_\_\=/', '', $initialState);
        $initialState = preg_replace('/\;\(function\(\).*$/', '', $initialState);
        $initialState = json_decode($initialState, true);
        $initialState = $initialState['data']['data']['article']['data']['text'];
        $itemTypes = self::$preservedItemTypes;
        self::$scriptData = array_values(array_filter($initialState, function ($item) use ($itemTypes) {
            return in_array($item['type'], $itemTypes);
        }));
        array_shift(self::$scriptData);
    }

    /**
     * Function parses out image url from script data
     * 
     * @return string
     */
    protected static function getImageUrlFromScript(): string
    {
        $indexToRemove = null;
        foreach (self::$scriptData as $index => $value) {
            if ($value['type'] === 'images') {
                $indexToRemove = $index;
                break;
            }
        }
        if ($indexToRemove === null) {
            return '';
        }
        $url = self::$scriptData[$index]['value']['url'];
        unset(self::$scriptData[$index]);
        return preg_replace('/##/', self::DUMMY_SCREEN_RESOLUTION_IMAGE_PATTERN_REPLACE_VALUE, $url);
    }

    /**
     * Function parses out video url from script data and appends it to post
     * 
     * @param NewsPost $post
     * 
     * @return void
     */
    protected static function appendVideoFromScript(NewsPost $post): void
    {
        foreach (self::$scriptData as $index => $value) {
            if ($value['type'] === 'iframe') {
                $link = self::$scriptData[$index]['media']['src'];
                unset(self::$scriptData[$index]);

                if ($link && $link !== '') {
                    if ($ytVideoId = self::getYoutubeVideoId($link)) {
                        $post->addItem(new NewsPostItem(NewsPostItem::TYPE_VIDEO, null, null, null, null, $ytVideoId));
                        continue;
                    }
                    if (preg_match('/vk\.com/', $link)) {
                        $link = preg_replace('/^(\/\/)(.*)/', 'https://$2', html_entity_decode($link));
                        if (filter_var($link, FILTER_VALIDATE_URL)) {
                            $post->addItem(new NewsPostItem(NewsPostItem::TYPE_LINK, null, null, $link));
                        }
                    }
                }
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
        $transformedText = preg_replace('/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/m', '', $text);
        $transformedText = preg_replace('/\<script.*\<\/script>/m', '', $transformedText);
        $transformedText = mb_convert_encoding($transformedText, 'UTF-8', mb_detect_encoding($transformedText));
        $transformedText = html_entity_decode($transformedText);
        $transformedText = preg_replace('/^\p{Z}+|\p{Z}+$/u', '', htmlspecialchars_decode($transformedText));
        $transformedText = preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/m', '', $transformedText);
        $transformedText = preg_replace('/\xe2\xa0\x80/m', '', $transformedText);
        return $transformedText;
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
        $url = urlencode($url);
        return str_replace(array('%3A', '%2F'), array(':', '/'), $url);
    }

    /**
     * Function check if string has actual text
     * 
     * @param string|null $text
     * 
     * @return bool
     */
    protected static function hasActualText(?string $text): bool
    {
        return trim($text, "⠀ \t\n\r\0\x0B\xC2\xA0") !== '';
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
     * Function check if node is <iframe></iframe>
     * 
     * @param DOMNode
     * 
     * @return bool
     */
    protected static function isVideoType(DOMNode $node): bool
    {
        return isset($node->tagName) === true && $node->tagName === 'iframe';
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

    /**
     * Function parse's out youtube video id from link
     * 
     * @param string $link
     * 
     * @return string|null
     */
    protected static function getYoutubeVideoId(string $link): ?string
    {
        preg_match(
            '/(youtu\.be\/|youtube\.com\/(watch\?(.*&)?v=|(embed|v)\/))([\w-]{11})/iu',
            $link,
            $matches
        );

        return $matches[5] ?? null;
    }

    /**
     * Function remove useless specified nodes
     * 
     * @param Crawler $crawler
     * @param string $xpath
     * @param int|null $count
     * 
     * @return void
     */
    protected static function removeNodes(Crawler $crawler, string $xpath, ?int $count = null): void
    {
        $crawler->filterXPath($xpath)->each(function (Crawler $crawler, int $key) use ($count) {
            if ($count !== null && $key === $count) {
                return;
            }
            $domNode = $crawler->getNode(0);
            if ($domNode) {
                $domNode->parentNode->removeChild($domNode);
            }
        });
    }
} 