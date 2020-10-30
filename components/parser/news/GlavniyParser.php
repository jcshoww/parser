<?php

namespace app\components\parser\news;

use app\components\Helper;
use app\components\helper\aayaami\DOMNodeRecursiveIterator;
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
 * News parser from site https://www.glavny.tv/
 * @author jcshow
 */
class GlavniyParser implements ParserInterface
{
    public const USER_ID = 2;

    public const FEED_ID = 2;

    public const SITE_URL = 'https://www.glavny.tv';

    /** @var array */
    protected static $parsedEntities = ['a', 'img', 'blockquote', 'iframe', 'video'];

    /** @var array */
    protected static $sentenceEndSymbols = ['.', '!', '?'];

    /** @var int */
    protected static $parsedCount = 0;

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
    public static function getNewsData(int $limit = 100): array
    {
        /** Вырубаем нотисы */
        error_reporting(E_ALL & ~E_NOTICE);

        $pageNum = 0;
        $posts = [];
        while (self::$parsedCount < $limit) {
            /** Get news list */
            $curl = Helper::getCurl();
            $newsList = $curl->get(static::SITE_URL . "/news?page=$pageNum");
            if ($curl->getInfo()['http_code'] === 404) {
                break;
            }

            /** Parse news from page html */
            $newsListCrawler = new Crawler($newsList);
            $news = $newsListCrawler->filterXPath('//div[contains(@class,"view-content")]//div[contains(@class,"views-row")]');

            foreach ($news as $item) {
                $post = self::getPostDetail($item);

                $posts[] = $post;
                self::$parsedCount++;
            }

            $pageNum++;
        }

        return $posts;
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
        $titleBlock = $itemCrawler->filterXPath('//h2[contains(@class,"news-title")]//a')->getNode(0);

        /** Get item detail link */
        $link = self::cleanUrl($titleBlock->getAttribute('href'));
        if (! preg_match('/http[s]?/', $link)) {
            $link = UriResolver::resolve($link, static::SITE_URL);
        }

        /** Get title */
        $title = self::cleanText($titleBlock->textContent);

        /** Get item datetime */
        $dateBlock = $itemCrawler->filterXPath('//div[contains(@class,"news-date")]')->getNode(0);
        $dateTime = $itemCrawler->filterXPath('//div[contains(@class,"news-time")]')->getNode(0);
        $createdAt = new DateTime($dateBlock . ' ' . $dateTime, new DateTimeZone('Europe/Moscow'));
        $createdAt->setTimezone(new DateTimeZone('UTC'));
        $createdAt = $createdAt->format('c');

        /** Detail page parser creation */
        $curl = Helper::getCurl();
        $tries = 1;
        do {
            $ckfile = tempnam("/tmp", 'cookiename');
            $curl->setOption(CURLOPT_CONNECTTIMEOUT, 60);
            $curl->setOption(CURLOPT_TIMEOUT, 60);
            $curl->setOption(CURLOPT_COOKIEFILE, $ckfile);
            $curlResult = $curl->get($link);
            $tries++;
        } while (empty($curlResult) === true);
        $crawler = new Crawler($curlResult);

        self::removeNodes($crawler, '
            //comment() | //br | //hr | //script | //style | //head | //link | //meta | //form
        ');

        //Get image if exists
        $picture = null;
        $imageBlock = $crawler->filterXPath('
            //div[contains(@class,"inside")]
            //div[contains(@class,"field-type-image")]
            //img[1]
        ')->getNode(0);
        if (! empty($imageBlock) === true) {
            $picture = self::cleanUrl($imageBlock->getAttribute('src'));
            if (! preg_match('/http[s]?/', $picture)) {
                $picture = UriResolver::resolve($picture, static::SITE_URL);
            }
            $imageBlock->parentNode->removeChild($imageBlock);
        }

        $detailPage = $crawler->filterXpath('
            //div[contains(@class,"inside")]
            //div[contains(@class,"pane-node-body")]
        ')->getNode(0);

        /** Get description */
        $description = '';
        $descriptionBlock = $crawler->filterXpath('
            //div[contains(@class,"inside")]
            //div[contains(@class,"pane-node-field-subtitle")]
        ')->getNode(0);
        if (empty($descriptionBlock->textContent)) {
            $description = [];
            $nodeIterator = new DOMNodeRecursiveIterator($detailPage->childNodes);
            foreach ($nodeIterator->getRecursiveIterator() as $node) {
                $text = self::cleanText($node->textContent);
                $description[] = $text;
                if (! empty($node->textContent)) {
                    $node->parentNode->removeChild($node);
                }
                if (in_array(substr($text, -1), self::$sentenceEndSymbols)) {
                    break;
                }
            }
            $description = implode(' ', $description);
        } else {
            $description = self::cleanText($descriptionBlock->textContent);
        }

        /** @var NewsPost */
        $post = new NewsPost(static::class, $title, $description, $createdAt, $link, $picture);

        // parse detail page for texts
        foreach ($detailPage->childNodes as $node) {
            self::parseNode($post, $node);
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
        //Get non-empty quotes from nodes
        if (self::isQuoteType($node) && self::hasText($node)) {
            $post->addItem(new NewsPostItem(NewsPostItem::TYPE_QUOTE, $node->textContent));
            return;
        }

        //Get non-empty images from nodes
        if (self::isImageType($node)) {
            $imageLink = self::cleanUrl($node->getAttribute('src'));

            if ($imageLink === '') {
                return;
            }

            if (! preg_match('/http[s]?/', $imageLink)) {
                $imageLink = UriResolver::resolve($imageLink, static::SITE_URL);
            }

            $post->addItem(new NewsPostItem(NewsPostItem::TYPE_IMAGE, $node->getAttribute('alt'), $imageLink));
            return;
        }

        //Get videos from text
        if (self::isVideoType($node)) {
            $link = self::cleanUrl($node->getAttribute('src'));
            if ($link && $link !== '') {
                if ($ytVideoId = self::getYoutubeVideoId($link)) {
                    $post->addItem(new NewsPostItem(NewsPostItem::TYPE_VIDEO, null, null, null, null, $ytVideoId));
                    return;
                }
                if (! preg_match('/http[s]?/', $link)) {
                    $link = UriResolver::resolve($link, static::SITE_URL);
                }
                if (preg_match('/vk\.com/', $link)) {
                    $link = preg_replace('/^(\/\/)(.*)/', 'https://$2', html_entity_decode($link));
                }
                if (filter_var($link, FILTER_VALIDATE_URL)) {
                    $post->addItem(new NewsPostItem(NewsPostItem::TYPE_LINK, null, null, $link));
                }
            }
            return;
        }

        //Get non-empty links from nodes
        if (self::isLinkType($node) && self::hasText($node)) {
            $link = self::cleanUrl($node->getAttribute('href'));
            if ($link && $link !== '') {
                if ($ytVideoId = self::getYoutubeVideoId($link)) {
                    $post->addItem(new NewsPostItem(NewsPostItem::TYPE_VIDEO, null, null, null, null, $ytVideoId));
                    return;
                }
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
        return preg_replace_callback('/[^\x21-\x7f]/', function ($match) {
            return rawurlencode($match[0]);
        }, $url);
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
        return preg_replace('/[^а-яА-Яa-zA-Z]+/', '', trim($text, "⠀ \t\n\r\0\x0B\xC2\xA0")) !== '';
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
     * Function check if node is video
     * 
     * @param DOMNode
     * 
     * @return bool
     */
    protected static function isVideoType(DOMNode $node): bool
    {
        return 
            isset($node->tagName) === true && 
            in_array(
                $node->tagName,
                ['iframe', 'video']
            );
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