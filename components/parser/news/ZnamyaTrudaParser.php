<?php

namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTime;
use DateTimeZone;
use Exception;
use lanfix\parser\Parser;
use lanfix\parser\src\Element;

/**
 * News parser from site https://gazeta-trud.ru/
 * @author jcshow
 */
class ZnamyaTrudaParser implements ParserInterface
{
    public const USER_ID = 2;

    public const FEED_ID = 2;

    public const SITE_URL = 'https://gazeta-trud.ru';

    /** @var bool */
    public static $needNextContainer = false;

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
        /** Get news list */
        $curl = Helper::getCurl();
        $curlResult = $curl->get(static::SITE_URL . "/edw/api/data-marts/32/entities.json?_=26708281&limit=$limit&offset=$offset&view_component=publication_list");
        if (! $curlResult) {
            throw new Exception('Can not get news data');
        }

        $news = json_decode($curlResult, true);
        if (! json_last_error() == JSON_ERROR_NONE) {
            throw new Exception('Wrong response type received');
        }

        $result = [];
        $news = $news['results']['objects'];
        foreach ($news as $key => $item) {
            $result[] = self::getPostDetail($item);
        }

        return $result;
    }

    /**
     * Function get post detail data
     * 
     * @param array $item
     * 
     * @return NewPost
     */
    public static function getPostDetail(array $item): NewsPost
    {
        /** Get item detail link */
        $link = static::SITE_URL . $item['extra']['url'];

        /** Get title */
        $title = $item['entity_name'];

        /** Get description */
        $description = $item['extra']['short_subtitle'];

        /** Get item datetime */
        $createdAt = new DateTime($item['extra']['created_at']);
        $createdAt->setTimezone(new DateTimeZone('UTC'));
        $createdAt = $createdAt->format('c');

        /** Get item preview picture */
        $mediaParser = new Parser($item['media'], true);
        $photoUrl = '';
        if ($photoHtmlNode = $mediaParser->document->getBody()->findOne('img')) {
            $photoUrl = static::SITE_URL . trim($photoHtmlNode->getAttribute('src') ?: '');
        }

        /** @var NewsPost */
        $post = new NewsPost(static::class, $title, $description, $createdAt, $link, $photoUrl);

        /**
         * Detail page parser creation
         */
        $curl = Helper::getCurl();
        $curlResult = $curl->get($link);
        $detailPageParser = new Parser($curlResult, true);
        $detailPageBody = $detailPageParser->document->getBody();

        /** Skip if no content */
        if ($content = $detailPageBody->findOne('.content-body .content')) {
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
        /** Parse item content */
        $contentBlocks = $content->getChildren();
        if (isset($contentBlocks[1])) {
            /** Item header container with title and image */
            $itemHeader = $contentBlocks[1];

            /** Get h1 */
            $h1 = $itemHeader->findOne('h1');
            $post->addItem(new NewsPostItem(NewsPostItem::TYPE_HEADER, $h1->asText(),
                null, null, 1, null));

            /** Get detail image */
            if ($detailImage = $itemHeader->findOne('.topic_image img')) {
                $detailImage = static::SITE_URL . trim($detailImage->getAttribute('src') ?: '');
                $post->addItem(new NewsPostItem(NewsPostItem::TYPE_IMAGE, null,
                    $detailImage, null, null, null));
            }

            /** Get lead p as 2 level header */
            if ($h2 = $itemHeader->findOne('.col-md-8 .lead')) {
                $post->addItem(new NewsPostItem(NewsPostItem::TYPE_HEADER, $h2->asText(),
                    null, null, 2, null));
            }
        }

        /** Next container appends, if owl-gallery used */
        $bodyKey = 2;
        do {
            self::$needNextContainer = false;
            if (isset($contentBlocks[$bodyKey])) {
                /** Item body container with other data */
                $itemBody = $contentBlocks[$bodyKey]->findOne('.theme-default');
    
                self::appendPostBody($post, $itemBody);
            }
            $bodyKey++;
        } while (self::$needNextContainer === true);
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
            if ($bodyBlock->tag === 'p') {
                if ($video = $bodyBlock->findOne('iframe')) {
                    $video = trim($video->getAttribute('src') ?: '');
                    $videoId = preg_replace("/(.+)(\/)([^\/]+)$/", '$3', $video);
                    $post->addItem(new NewsPostItem(NewsPostItem::TYPE_VIDEO, null,
                        null, null, null, $videoId));
                } else {
                    if (! empty(trim($bodyBlock->asText())) === true) {
                        $post->addItem(new NewsPostItem(NewsPostItem::TYPE_TEXT, $bodyBlock->asText(),
                            null, null, null, null));
                    }
                }
            } elseif ($bodyBlock->tag === 'blockquote') {
                $post->addItem(new NewsPostItem(NewsPostItem::TYPE_QUOTE, $bodyBlock->asText(),
                    null, null, null, null));
            } elseif ($bodyBlock->tag === 'div' && in_array('publication-carousel', $bodyBlock->classes)) {
                self::$needNextContainer = true;
                $carousel = $bodyBlock->findOne('.owl-carousel');
                foreach ($carousel->getChildren() ?: [] as $mediaItem) {
                    $image = static::SITE_URL . trim($mediaItem->findOne('img')->getAttribute('src') ?: '');
                    $post->addItem(new NewsPostItem(NewsPostItem::TYPE_IMAGE, null,
                        $image, null, null, null));
                }
            }
        }
    }
}