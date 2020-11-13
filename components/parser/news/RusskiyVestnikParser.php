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
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;

/**
 * News parser from site http://rv.ru/
 * @author jcshow
 */
class RusskiyVestnikParser implements ParserInterface
{
    public const USER_ID = 2;

    public const FEED_ID = 2;

    public const SITE_URL = 'http://rv.ru/';

    /** @var int */
    protected static $tableWithMainPostIndex = 2;

    /** @var int */
    protected static $tableWithPostFeedIndex = 4;

    /** @var array */
    protected static $parsedEntities = ['a', 'img'];

    /** @var array */
    protected static $posts = [];

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

        /** Get main page */
        $curl = Helper::getCurl();
        $mainPage = $curl->get(static::SITE_URL);
        if (!$mainPage) {
            throw new Exception('Can not read main page');
        }

        /** Parse tables from main page */
        $tablesCrawler = new Crawler($mainPage);
        $tables = $tablesCrawler->filter('body > table:nth-child(6) > tr:nth-child(1) > td:nth-child(3) > table:nth-child(2) > tr > td > div');

        foreach ($tables->filter("div[align=\"justify\"]") as $item) {
            self::createPost($item);

        }

        return self::$posts;
    }

    /**
     * Function parse posts feed single post and creates post object with items
     * 
     * @param DOMElement $item
     * @param string $titleTag
     * 
     * @return void
     */
    public static function createPost(DOMElement $item, string $titleTag = 'font'): void
    {
        $crawler = new Crawler($item);
        $image = null;
        $description = "EMPTY";
        $link = $crawler->filter("a");
        if ($link->count() === 0) {
            throw new Exception("got no link");
        }

        $link = self::SITE_URL . "/" . $link->attr("href");

        /** Detail page parser creation */
        $curl = Helper::getCurl();
        $curlResult = $curl->get($link);

        $detailCrawler = new Crawler($curlResult);

        $page = $detailCrawler->filter("body > table:nth-child(3) > tr > td");
        /** Get item datetime */
        $titleBlock = $page->filter('b');
        if ($titleBlock->count() === 0) {
            throw new Exception(" title not found");
        }
        if ($titleBlock->filter("b > font")->count() !== 0) {
            $description = $titleBlock->filter("font")->text();
            $title = trim(explode(":", $titleBlock->text())[1]);

            $title = str_replace($description, "", $title);

        } else {
            $titleArr = explode(":", $titleBlock->text());
            unset($titleArr[0]);
            $title = trim(implode(": ", $titleArr));
        }

        $date = preg_replace('/\r\n/', '', trim(explode(":", $titleBlock->text())[0]));
        $date = preg_replace('/([^\:]+)(\:)(.+)/', '$1', $date);
        $createdAt = new DateTime($date . date('H:i:s'));
        $createdAt->setTimezone(new DateTimeZone('UTC'));
        $createdAt = $createdAt->format('c');

        $post = new NewsPost(static::class, $title, $description, $createdAt, $link, $image);

        foreach ($page->getNode(0)->childNodes as $node) {
            self::parseNode($post, $node);
        }

        self::$posts[] = $post;
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
        if ($node->nodeName === "b") {
            return;
        }
        //Get non-empty images from nodes
        if (self::isImageType($node)) {
            $imageLink = $node->getAttribute('src');

            if ($imageLink === '') {
                return;
            }
            if ($imageLink === 'images/2.gif') {
                return;
            }
            $imageLink = UriResolver::resolve($imageLink, static::SITE_URL);

            if ($post->image === null) {
                $post->image = $imageLink;
            } else {
                $post->addItem(new NewsPostItem(NewsPostItem::TYPE_IMAGE, $node->getAttribute('alt'), $imageLink));
            }
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
                $textContent = $node->textContent;
                $textContent = self::cleanText($textContent);

                if (self::hasActualText($textContent) === true) {
                    if ($post->description === "EMPTY") {
                        $post->description = $textContent;
                    } else {
                        $post->addItem(new NewsPostItem(NewsPostItem::TYPE_TEXT, $textContent));
                    }
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
        if ($needRecursive === false) {
            $textContent = $node->textContent;
            $textContent = self::cleanText($textContent);


            if (self::hasActualText($textContent) === true) {
                if ($post->description === "EMPTY") {
                    $post->description = $textContent;
                } else {
                    $post->addItem(new NewsPostItem(NewsPostItem::TYPE_TEXT, $textContent));
                }
            }
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
     * @return string
     */
    protected static function cleanText(string $text): string
    {
        $text = preg_replace('/\r\n/', '', $text);
        return preg_replace('/^\p{Z}+|\p{Z}+$/u', '', htmlspecialchars_decode($text));
    }

    /**
     * Function clean dangerous urls
     * 
     * @param string $url
     * 
     * @return string
     */
    public static function cleanUrl(string $url): string
    {
        $url = urlencode($url);
        return str_replace(array('%3A', '%2F'), array(':', '/'), $url);
    }

    /**
     * Function remove useless specified nodes
     * 
     * @param Crawler $crawler
     * @param string $xpath
     * 
     * @return void
     */
    protected static function removeNodes(Crawler $crawler, string $xpath): void
    {
        $crawler->filterXPath($xpath)->each(function (Crawler $crawler) {
            $domNode = $crawler->getNode(0);
            if ($domNode) {
                $domNode->parentNode->removeChild($domNode);
            }
        });
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
        return $node->tagName === 'p';
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
        return in_array($node->tagName, ['blockquote', 'em']);
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
        return $node->tagName === 'a';
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
        return $node->tagName === 'img';
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