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
        if (! $mainPage) {
            throw new Exception('Can not read main page');
        }

        /** Parse tables from main page */
        $tablesCrawler = new Crawler($mainPage);
        $tables = $tablesCrawler->filter('body > table');

        foreach ($tables as $key => $table) {
            if ($key === self::$tableWithMainPostIndex) {
                self::parseMainPost($table);
            } elseif ($key === self::$tableWithPostFeedIndex) {
                self::parsePostsFeed($table);
            }
        }

        return self::$posts;
    }

    /**
     * Function parse main post from table content
     * 
     * @param DOMElement $item
     * @return void
     */
    public static function parseMainPost(DOMElement $item): void
    {
        $itemCrawler = new Crawler($item);

        /** Get first table td with main content */
        $td = $itemCrawler->filterXPath('//td')->getNode(0);

        /** Get first table from this td */
        $innerTable = $td->getElementsByTagName('table')->item(0);

        /** Get first td from this inner table */
        $dataContainer = $innerTable->getElementsByTagName('td')->item(0);

        self::createPost($dataContainer);
    }

    /**
     * Function parse posts feed for required content
     * 
     * @param DOMElement $item
     * @return void
     */
    public static function parsePostsFeed(DOMElement $item): void
    {
        $itemCrawler = new Crawler($item);

        self::removeNodes($itemCrawler, '//br');
        self::removeNodes($itemCrawler, '//hr');

        /** Get first table td with main content */
        $newsContainer = $itemCrawler->filter('td div[align=justify]')->getNode(0);
        if (! empty($newsContainer) === true) {
            foreach ($newsContainer->getElementsByTagName('div') as $node) {
                self::createPost($node, 'b');
            }
        }
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
        $image = '';
        $description = '';
        foreach ($item->childNodes as $childNode) {
            if (self::isImageType($childNode) && $childNode->getAttribute('src') === 'images/2.gif') {
                continue;
            }

            // Get post image
            if (self::isImageType($childNode)) {
                $image = self::cleanUrl($childNode->getAttribute('src') ?: '');
                $image = UriResolver::resolve($image, static::SITE_URL);
                continue;
            }

            /** Get post title */
            if ($childNode->tagName === $titleTag) {
                $title = $childNode->textContent;
                continue;
            }

            if (self::isLinkType($childNode)) {
                $link = UriResolver::resolve($childNode->getAttribute('href'), static::SITE_URL);
                continue;
            }

            if (self::isText($childNode)) {
                $description .= self::cleanText($childNode->textContent);
            }
        }

        /** Detail page parser creation */
        $curl = Helper::getCurl();
        $curlResult = $curl->get($link);

        $detailCrawler = new Crawler($curlResult);

        /** Get item datetime */
        $titleBlock = $detailCrawler->filter('div[align=justify] b')->getNode(0);
        $date = preg_replace('/\r\n/', '', $titleBlock->textContent);
        $date = preg_replace('/([^\:]+)(\:)(.+)/', '$1', $date);
        $createdAt = new DateTime($date . date('H:i:s'));
        $createdAt->setTimezone(new DateTimeZone('UTC'));
        $createdAt = $createdAt->format('c');

        /** @var NewsPost */
        $post = new NewsPost(static::class, $title, htmlspecialchars_decode($description), $createdAt, $link, $image);

        self::parsePostDetails($post, $detailCrawler);

        self::$posts[] = $post;
    }

    /**
     * Function get post detail data
     * 
     * @param NewsPost $post
     * @param Crawler $crawler
     * 
     * @return void
     */
    public static function parsePostDetails(NewsPost $post, Crawler $crawler): void
    {
        self::removeNodes($crawler, '//br');
        $fullTextBlock = $crawler->filter('div[align=justify]')->getNode(0);
        foreach ($fullTextBlock->childNodes as $node) {
            if (self::isImageType($node) && $node->getAttribute('src') === 'images/2.gif') {
                continue;
            }
            self::parseNode($post, $node);
        }
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

        //Get extremly bad formatted headers
        if ($node->tagName === 'b' && empty($post->items) === true) {
            $mainHeaderText = '';
            foreach ($node->childNodes as $childNode) {
                if ($childNode->tagName === 'font') {
                    $textContent = self::cleanText($childNode->textContent);
                    if (empty(trim($textContent)) === true) {
                        return;
                    }
                    $post->addItem(new NewsPostItem(NewsPostItem::TYPE_HEADER, $textContent,
                        null, null, 2));
                } else {
                    if (self::hasText($childNode)) {
                        $textContent = self::cleanText($childNode->textContent);
                        if (empty(trim($textContent)) === true) {
                            return;
                        }
                        $mainHeaderText .= $textContent;
                    }
                }
            }

            $post->addItem(new NewsPostItem(NewsPostItem::TYPE_HEADER, $mainHeaderText,
                null, null, 1));

            return;
        }

        //Get direct text nodes
        if (self::isText($node)) {
            if (self::hasText($node)) {
                $textContent = self::cleanText($node->textContent);
                if (empty(trim($textContent)) === true) {
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
            $textContent = self::cleanText($node->textContent);
            if (empty(trim($textContent)) === true) {
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