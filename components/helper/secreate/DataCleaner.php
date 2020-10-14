<?php

namespace app\components\helper\secreate;

trait DataCleaner
{
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
}
