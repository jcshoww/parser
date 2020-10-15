<?php

namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\ParserInterface;
use DateTime;
use DateTimeZone;
use DOMNode;
use Exception;
use lanfix\parser\Parser;
use lanfix\parser\src\Element;
use Symfony\Component\DomCrawler\Crawler;

/**
 * News parser from site https://komigor.com/
 * @author jcshow
 */
class KomigorParser implements ParserInterface
{

    public const USER_ID = 2;

    public const FEED_ID = 2;

    public const SITE_URL = 'https://komigor.com';

    public const BASE_YOUTUBE_VIDEO_URL = 'https://www.youtube.com/watch?v=';

    /** @var array */
    protected static $projects = [
        1, 3, 2, 27
    ];

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
        //Parse last page of each news project
        foreach (self::$projects as $project) {
            /** Get page */
            $curl = Helper::getCurl();
            $page = $curl->get(static::SITE_URL . "/novosti?project_id=$project");
            if (! $page) {
                continue;
            }

            /** Parse news block from page */
            $pageCrawler = new Parser($page, true);
            $pageBody = $pageCrawler->document->getBody();
            $pageBlock = $pageBody->find('.content-items-container .item');

            foreach ($pageBlock as $item) {
                self::createPost($item);
            }
        }

        usort(self::$posts, function($a, $b) {
            $ad = $a->createDate;
            $bd = $b->createDate;
        
            if ($ad == $bd) {
                return 0;
            }
        
            return $ad > $bd ? -1 : 1;
        });

        return self::$posts;
    }

    /**
     * Function create post from item
     * 
     * @param Element $content
     * 
     * @return void
     */
    public static function createPost(Element $content): void
    {
        //Get youtube container
        $ytContainer = $content->findOne('.youtube-play');
        $videoId = $ytContainer->getAttribute('data-id');

        /** Get item detail link */
        $link = static::BASE_YOUTUBE_VIDEO_URL . $videoId;

        /** Get title */
        $title = self::cleanText($content->findOne('.item-name h3')->asText());

        /** Get picture */
        $imageUrl = '';
        $imageNode = $ytContainer->findOne('img');
        if (! empty($imageNode) === true) {
            $imageUrl = self::cleanUrl($imageNode->getAttribute('src') ?: '');
        }

        //Get item description
        $description = '';
        $subtitleNode = $content->findOne('.item-sub-name');
        if (! empty($subtitleNode) === true) {
            $description = self::cleanText($subtitleNode->asText());

            //parse string for date published
            $stringToDate = preg_replace('/ года/', '', $description);
            $stringToDate = self::convertDateToEng($stringToDate);
            $createdAt = DateTime::createFromFormat('d F Y H:i:s', $stringToDate . ' ' . date('H:i:s'));
            $createdAt->setTimezone(new DateTimeZone('UTC'));
            $createdAt = $createdAt->format('c');
        } else {
            $description = $title;

            /** Detail page parser creation */
            $curl = Helper::getCurl();
            $curlResult = $curl->get($link);

            /** Parse detail video page */
            $pageCrawler = new Crawler($curlResult);
            $dateBlock = $pageCrawler->filterXPath('//meta[contains(@itemprop, "datePublished")]')->getNode(0);
            
            //If video unaccessable
            if (empty($dateBlock) == true) {
                return;
            }
            $createdAt = new DateTime($dateBlock->getAttribute('content') . date('H:i:s'));
            $createdAt->setTimezone(new DateTimeZone('UTC'));
            $createdAt = $createdAt->format('c');
        }

        /** @var NewsPost */
        $post = new NewsPost(static::class, $title, $description, $createdAt, $link, $imageUrl);

        self::$posts[] = $post;
    }

    /**
     * Function converts russian months to eng
     * 
     * @param string $date
     * 
     * @return string
     */
    protected static function convertDateToEng(string $date): string
    {
        $date = mb_strtolower($date);

        $ruMonth = [
            'января',
            'февраля',
            'марта',
            'апреля',
            'мая',
            'июня',
            'июля',
            'августа',
            'сентября',
            'октября',
            'ноября',
            'декабря'
        ];

        $enMonth = [
            'January',
            'February',
            'March',
            'April',
            'May',
            'June',
            'July',
            'August',
            'September',
            'October',
            'November',
            'December'
        ];

        $date = str_replace($ruMonth, $enMonth, $date);

        return $date;
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
    protected static function cleanUrl(string $url): string
    {
        $url = urlencode($url);
        return str_replace(array('%3A', '%2F'), array(':', '/'), $url);
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
     * Function returns heading level
     * 
     * @param DOMNode
     * @return int|null
     */
    protected static function getHeadingLevel(DOMNode $node): ?int
    {
        $headingTags = ['h1' => 1, 'h2' => 2, 'h3' => 3, 'h4' => 4, 'h5' => 5, 'h6' => 6];

        return $headingTags[$node->nodeName] ?? null;
    }
} 