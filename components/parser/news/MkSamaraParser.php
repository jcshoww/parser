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
 * News parser from site https://samara.mk.ru/
 * @author jcshow
 */
class MkSamaraParser implements ParserInterface
{

    public const USER_ID = 2;

    public const FEED_ID = 2;

    public const TEXT_SIMILARITY_LIMIT = 98;

    public const SITE_URL = 'https://samara.mk.ru';

    /** @var array */
    protected static $parsedEntities = ['a', 'img', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

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
    public static function getNewsData(int $limit = 100): array
    {
        /** Вырубаем нотисы */
        error_reporting(E_ALL & ~E_NOTICE);

        /** Get main page */
        $curl = Helper::getCurl();
        $mainPage = $curl->get(static::SITE_URL . "/news/");
        if (! $mainPage) {
            throw new Exception('Can not get news data');
        }

        $limitReached = false;
        /** Parse most intresting news from main page */
        $pageCrawler = new Parser($mainPage, true);
        $pageBody = $pageCrawler->document->getBody();
        $intresting = $pageBody->find('.article-grid__interesting_right .interesting__item');
        foreach ($intresting as $postData) {
            self::parseIntrestingPost($postData);
            if (count(self::$posts) > $limit) {
                $limitReached = true;
                break;
            }
        }

        if ($limitReached === false) {
            $newsGroups = $pageBody->find('.news-listing__day-group');
            foreach ($newsGroups as $group) {
                $newsList = $group->find('.news-listing__item ');
                foreach ($newsList as $item) {
                    self::parseRegularPost($item);
                    if (count(self::$posts) > $limit) {
                        $limitReached = true;
                        break 2;
                    }
                }

                $galleriesList = $group->find('.news-listing__item-media');
                foreach ($galleriesList as $gallery) {
                    $items = $gallery->find('.media-slider-item');
                    foreach ($items as $item) {
                        self::parseGalleryPost($gallery);
                        if (count(self::$posts) > $limit) {
                            $limitReached = true;
                            break 2;
                        }
                    }
                }
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
     * Function parse intresting post from table content
     *
     * @param Element $item
     * @return void
     */
    public static function parseIntrestingPost(Element $item): void
    {
        /** Get intresting inner content */
        $content = $item->findOne('.article-preview__content');
        
        /** Get item detail link */
        $link = $content->getAttribute('href');

        /** Get title */
        $title = self::cleanText($content->findOne('.article-preview__title')->asText());

        /** Get picture */
        $imageUrl = '';
        $imageNode = $content->findOne('img');
        if (! empty($imageNode) === true) {
            $imageUrl = self::cleanUrl($imageNode->getAttribute('src') ?: '');
        }

        self::createPost($link, $title, $imageUrl);
    }

    /**
     * Function parse regular post from table content
     * 
     * @param Element $item
     * @return void
     */
    public static function parseRegularPost(Element $item): void
    {
        /** Get item detail link */
        $link = $item->findOne('.news-listing__item-link')->getAttribute('href');

        /** Get item title */
        $titleBlock = $item->findOne('.news-listing__item-title');
        $title = self::cleanText($titleBlock->asText());

        self::createPost($link, $title);
    }

    /**
     * Function parse gallery post from table content
     * 
     * @param Element $item
     * @return void
     */
    public static function parseGalleryPost(Element $item): void
    {
        /** Get item detail link */
        $link = $item->findOne('.media-slider-item__content')->getAttribute('href');

        /** Get item title */
        $titleBlock = $item->findOne('.media-slider-item__title');
        $title = self::cleanText($titleBlock->asText());

        /** Get picture */
        $imageUrl = '';
        $imageNode = $item->findOne('img');
        if (! empty($imageNode) === true) {
            $imageUrl = self::cleanUrl($imageNode->getAttribute('src') ?: '');
        }

        self::createPost($link, $title, $imageUrl);
    }

    /**
     * Function finish post creation
     * 
     * @param Element $item
     * @return void
     */
    public static function createPost(string $link, string $title, string $imageUrl = '')
    {
        /** Detail page parser creation */
        $curl = Helper::getCurl();
        $curlResult = $curl->get($link);

        $detailCrawler = new Crawler($curlResult);

        /** Get item datetime */
        $dateTimeBlock = $detailCrawler->filterXPath('//div[contains(@class, "article")]//time')->getNode(0);
        $createdAt = new DateTime($dateTimeBlock->getAttribute('datetime'));
        $createdAt->setTimezone(new DateTimeZone('UTC'));
        $createdAt = $createdAt->format('c');

        /** Get item description */
        $descriptionBlock = $detailCrawler->filterXPath('//meta[contains(@name, "description")]')->getNode(0);
        $description = self::cleanText($descriptionBlock->getAttribute('content'));

        /** @var NewsPost */
        $post = new NewsPost(static::class, $title, $description, $createdAt, $link, $imageUrl);

        $detailPageParser = new Parser($curlResult, true);
        $detailBody = $detailPageParser->document->getBody();
        $detailPage = $detailBody->findOne('.article');

        self::appendPostAdditionalData($post, $detailPage);

        self::$posts[] = $post;
    }

    /**
     * Function appends NewsPostItem objects to NewsPost with additional post data
     * 
     * @param NewsPost $post
     * @param Element $content
     * 
     * @return void
     */
    public static function appendPostAdditionalData(NewsPost $post, Element $content): void
    {
        //Get article headers
        $header = $content->findOne('.article__header');

        //Get article subtitle
        $subtitle = $header->findOne('.article__subtitle');
        if (! empty($subtitle) === true) {
            $post->addItem(new NewsPostItem(NewsPostItem::TYPE_HEADER, self::cleanText($subtitle->asText()),
                null, null, 2));
        }
        
        $crawler = new Crawler($content->asHtml());

        self::removeNodes($crawler, '//div[contains(@class, "article__meta")]');
        self::removeNodes($crawler, '//header[contains(@class, "article__header")]');
        self::removeNodes($crawler, '//div[contains(@class, "article__share-top")]');
        self::removeNodes($crawler, '//script');
        self::removeNodes($crawler, '//div[contains(@class, "article__subscription")]');
        self::removeNodes($crawler, '//div[contains(@itemprop, "author")]');
        self::removeNodes($crawler, '//div[contains(@class, "article__tag")]');
        self::removeNodes($crawler, '//div[contains(@class, "article__share-and-comments")]');
        self::removeNodes($crawler, '//div[contains(@class, "article__in-newspaper")]');
        self::removeNodes($crawler, '//div[contains(@class, "article__authors")]');

        /** Get picture */
        if (empty($post->image) === true) {
            $imageUrl = '';
            $imageNode = $crawler->filterXPath('//img[1]')->getNode(0);
            if (! empty($imageNode) === true) {
                $imageUrl = self::cleanUrl($imageNode->getAttribute('src') ?: '');
                $imageNode->parentNode->removeChild($imageNode);
            }
            $post->image = $imageUrl;
        }

        foreach ($crawler->filter("div.article__body")->getNode(0)->childNodes as $item) {
            self::parseNode($post, $item);
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
        //Get headings from post
        if ($node instanceof DOMElement) {
            $headingLevel = self::getHeadingLevel($node);
            if ($headingLevel !== null) {
                $textContent = self::cleanText($node->textContent);
                if (empty(trim($textContent)) === true) {
                    return;
                }
                $post->addItem(new NewsPostItem(NewsPostItem::TYPE_HEADER, $textContent,
                    null, null, $headingLevel));
                return;
            }
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
                $textContent = self::cleanText($node->textContent);
                similar_text($textContent, $post->description, $similarity);
                if ($similarity >= self::TEXT_SIMILARITY_LIMIT) {
                    return;
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
        if ($needRecursive === false) {
            $textContent = self::cleanText($node->textContent);
            similar_text($textContent, $post->description, $similarity);
            if ($similarity >= self::TEXT_SIMILARITY_LIMIT) {
                return;
            }

            if (self::hasActualText($textContent) === true) {
                $post->addItem(new NewsPostItem(NewsPostItem::TYPE_TEXT, $textContent));
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