<?php
/**
 *
 * @author MediaSfera <info@media-sfera.com>
 * @author FingliGroup <info@fingli.ru>
 * @author Vitaliy Moskalyuk <flanker@bk.ru>
 *
 * @note Данный код предоставлен в рамках оказания услуг, для выполнения поставленных задач по сбору и обработке данных. Переработка, адаптация и модификация ПО без разрешения правообладателя является нарушением исключительных прав.
 *
 */

namespace app\components\parser\news;


use app\components\mediasfera\MediasferaNewsParser;
use app\components\mediasfera\NewsPostWrapper;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use Symfony\Component\DomCrawler\Crawler;


/**
 * @rss_html
 */
class FondskParser extends MediasferaNewsParser implements ParserInterface
{
    /*run*/
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'http://www.fondsk.ru/';
    public const NEWSLIST_URL = 'http://www.fondsk.ru/rss.html';

    public const DATEFORMAT = 'D, d M Y H:i:s O';

    public const NEWSLIST_POST = '//rss/channel/item';
    public const NEWSLIST_TITLE = '//title';
    public const NEWSLIST_LINK = '//link';
    public const NEWSLIST_DATE = '//pubDate';

    public const NEWSLIST_IMG = '.page_article [itemprop="image"] img';
    public const ARTICLE_DESC = '.page_article .article_notice';
    public const ARTICLE_TEXT =   '#news-page[itemprop="articleBody"]';

    public const CHECK_EMPTY_CHARS = " –\t\n\r\0\x0B\xC2\xA0";

    protected static NewsPostWrapper $post;

    public static function run(): array
    {
        $posts = [];

        $listContent = self::getPage(self::NEWSLIST_URL);

        $listCrawler = new Crawler($listContent);

        $listCrawler->filterXPath(self::NEWSLIST_POST)->slice(0, self::NEWS_LIMIT)->each(function (Crawler $node) use (&$posts) {

            self::$post = new NewsPostWrapper();
            self::$post->isPrepareItems = false;

            self::$post->title = self::getNodeData('text', $node, self::NEWSLIST_TITLE);
            self::$post->original = self::getNodeData('text', $node, self::NEWSLIST_LINK);
            self::$post->createDate = self::getNodeDate('text', $node, self::NEWSLIST_DATE);

            $articleContent = self::getPage(self::$post->original);

            if (!empty($articleContent)) {

                $articleCrawler = new Crawler($articleContent);

                self::$post->description = self::getNodeData('text', $articleCrawler, self::ARTICLE_DESC);
                self::$post->image = self::getNodeData('src', $articleCrawler, self::NEWSLIST_IMG);

                self::parse($articleCrawler->filter(self::ARTICLE_TEXT));
            }

            $newsPost = self::$post->getNewsPost();

            foreach ($newsPost->items as $key => $item) {
                if($item->type == NewsPostItem::TYPE_TEXT) {
                    $text = trim($item->text, self::CHECK_EMPTY_CHARS);

                    if(!$text) {
                        unset($newsPost->items[$key]);
                    }
                }
            }

            $posts[] = $newsPost;
        });

        return $posts;
    }
}
