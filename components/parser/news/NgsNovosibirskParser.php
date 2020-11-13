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
 * News parser from site https://ngs.ru/
 * @author jcshow
 */
class NgsNovosibirskParser implements ParserInterface
{
    public const USER_ID = 2;

    public const FEED_ID = 2;

    public const SITE_URL = 'https://ngs.ru';

    public const DUMMY_SCREEN_RESOLUTION_IMAGE_PATTERN_REPLACE_VALUE = '_1280.';

    /** @var array */
    protected static $parsedEntities = ['img', 'blockquote', 'figcaption', 'iframe'];

    /** @var array */
    protected static $preservedItemTypes = ['images', 'iframe'];

    /** @var array */
    protected static $scriptData;

    /** @var array */
    protected static $itemPostFeed = [];

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
            $post = self::getPostDetail($item);

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
    public static function getPostDetail(SimpleXMLElement $item)//: NewsPost
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


        /** @var NewsPost */
        $post = new NewsPost(static::class, $title, $description, $createdAt, $link, $picture);

        /** Detail page parser creation */
        $curl = Helper::getCurl();
        $curlResult = $curl->get($link);

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


        $nodeList = $initialState['data']['data']['article']["data"]["text"];
        // parse detail page for texts
        foreach ($nodeList as $node) {
            if ($node['type'] === 'images') {
                $url = $node['value']['url'];

                $url = preg_replace('/##/', self::DUMMY_SCREEN_RESOLUTION_IMAGE_PATTERN_REPLACE_VALUE, $url);
                $imageLink = UriResolver::resolve($url, static::SITE_URL);
                if ($post->image === null) {
                    $post->image = $imageLink;
                } else {
                    $post->addItem(new NewsPostItem(NewsPostItem::TYPE_IMAGE, $node['value']['description'], $imageLink));
                }
            }

            if ($node['type'] === 'iframe') {
                $link = $node['media']['src'];


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


            if ($node["type"] === "text" && $node["value"] !== null) {
                $bodyNode = new Crawler($node["value"]);
                if ($bodyNode->count() === 0) {
                    continue;
                }
                self::parseNode($post, $bodyNode->getNode(0));
            }
        }

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
        return trim($text, "⠀ \t\n\r\0\x0B\xC2\xA0.,") !== '';
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

} 