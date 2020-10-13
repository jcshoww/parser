<?php

namespace app\components\parser\news;

use app\components\Helper;
use app\components\helper\secreate\DataCleaner;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTime;
use DateTimeZone;
use Exception;
use lanfix\parser\Parser;
use lanfix\parser\src\Element;

/**
 * News parser from site https://33mayak.ru/
 * @author jcshow
 */
class Mayak33Parser implements ParserInterface
{
    use DataCleaner;

    public const USER_ID = 2;

    public const FEED_ID = 2;

    public const SITE_URL = 'https://33mayak.ru';

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
        /** Get news list */
        $page = 1;
        $result = [];
        while (self::$parsedCount < $limit) {
            $curl = Helper::getCurl();
            $curlResult = $curl->get(static::SITE_URL . "/arxiv-novostej.html/page/$page");
            if (! $curlResult) {
                throw new Exception('Can not get news data');
            }

            $pageParser = new Parser($curlResult, true);
            $body = $pageParser->document->getBody();
            $news = $body->findOne('.main_content');
            foreach ($news->find('.last_hews') as $item) {
                $result[] = self::getPostDetail($item);
                self::$parsedCount++;
            }

            $page++;
        }

        return $result;
    }

    /**
     * Function get post detail data
     * 
     * @param Element $item
     * 
     * @return NewPost
     */
    public static function getPostDetail(Element $item): NewsPost
    {
        $titleBlock = $item->findOne('.post_title');

        /** Get item detail link */
        $link = $titleBlock->getAttribute('href');

        /** Get title */
        $title = $titleBlock->asText();

        /**
         * Detail page parser creation
         */
        $curl = Helper::getCurl();
        $curlResult = $curl->get($link);
        $detailPageParser = new Parser($curlResult, true);
        $detailPageHeader = $detailPageParser->document->getHead();
        $detailPageBody = $detailPageParser->document->getBody();

        /** Get description */
        $descTag = $item->findOne('.content_wrap p');
        $description = '';
        if (is_null($descTag) === false) {
            $description = $descTag->asText();
        } else {
            foreach ($detailPageHeader->find('meta') ?? [] as $meta) {
                if ($meta->getAttribute('name') === 'description') {
                    $description = $meta->getAttribute('content') ?: '';
                    break;
                }
            }
        }

        /** Get item datetime */
        $time = $item->findOne('.date_comments .date_h_m')->asText();
        $date = $item->findOne('.date_comments .date_d_m_y')->asText();
        $createdAt = new DateTime("$date $time", new DateTimeZone('Europe/Moscow'));
        $createdAt->setTimezone(new DateTimeZone('UTC'));
        $createdAt = $createdAt->format('c');

        /** Get item preview picture */
        $imageBlock = $item->findOne('.img_wrap');
        $photoUrl = '';
        if ($photoHtmlNode = $imageBlock->findOne('img')) {
            $photoUrl = $photoHtmlNode->getAttribute('src') ?: '';
            if (! empty($photoUrl) === true) {
                $photoUrl = self::cleanUrl($photoUrl);
            }
        }

        /** @var NewsPost */
        $post = new NewsPost(static::class, $title, $description, $createdAt, $link, $photoUrl);

        /** Skip if no content */
        if ($content = $detailPageBody->findOne('.main_content')) {
            self::appendPostAdditionalData($post, $content);
        }

        return $post;
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
        //Parse post headers
        $headerBlock = $content->findOne('.top_text_photo');

        //Get header text block
        $headerTexts = $headerBlock->findOne('.top_text');

        /** Get h1 */
        $h1 = $headerTexts->findOne('.entry-title');
        $post->addItem(new NewsPostItem(NewsPostItem::TYPE_HEADER, $h1->asText(),
            null, null, 1, null));

        /** Get h2 */
        $h2 = $headerTexts->findOne('p');
        if (! empty($h2) === true) {
            $post->addItem(new NewsPostItem(NewsPostItem::TYPE_HEADER, $h2->asText(),
                null, null, 2, null));
        }

        /** Get detail image */
        if ($detailImage = $headerBlock->findOne('.top_image img')) {
            $detailImage = $detailImage->getAttribute('src') ?: '';
            if (! empty($detailImage) === true) {
                $post->addItem(new NewsPostItem(NewsPostItem::TYPE_IMAGE, null,
                    self::cleanUrl($detailImage), null, null, null));
            }
        }

        // Parse detail main block content
        $mainPostBlock = $content->findOne('.main_post_text');
        self::appendPostBody($post, $mainPostBlock);
    }

    /**
     * Function appends post body to post
     * 
     * @param NewsPost $post
     * @param Element $body
     * 
     * @return void
     */
    public static function appendPostBody(NewsPost $post, Element $itemBody): void
    {
        foreach ($itemBody->getChildren() ?: [] as $bodyBlock) {
            if ($img = $bodyBlock->findOne('img')) {
                $image = $img->getAttribute('src') ?: '';
                if (! empty($image) === true) {
                    $post->addItem(new NewsPostItem(NewsPostItem::TYPE_IMAGE, null, self::cleanUrl($image)));
                }
            } elseif ($link = $bodyBlock->findOne('a')) {
                $src = $link->getAttribute('href') ?: '';
                if (! empty($src) === true) {
                    $post->addItem(new NewsPostItem(NewsPostItem::TYPE_LINK, $link->asText(), null, self::cleanUrl($src)));
                }
            } else {
                $post->addItem(new NewsPostItem(NewsPostItem::TYPE_TEXT, $bodyBlock->asText()));
            }
        }
    }
} 