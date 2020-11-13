<?php

ob_start();
include("handler.php");
ob_end_clean();

setlocale(LC_CTYPE, "C.UTF-8");

header('Content-type: application/rss+xml; charset=utf-8');

$xml = new DOMDocument('1.0', 'utf-8');

$xmlstr = '<?xml version="1.0" encoding="UTF-8"?><rss/>';
$rss = new SimpleXMLElement($xmlstr);
$rss->addAttribute('version', '2.0');
$rss->addAttribute('xmlns:xmlns:atom', 'http://www.w3.org/2005/Atom');
$rss->addAttribute('xmlns:xmlns:itunes', 'http://www.itunes.com/dtds/podcast-1.0.dtd');
$channel = $rss->addChild('channel');
$channel->addChild('title', 'CC Politics Generator');
$channel->addChild('link', 'http://reddit.com/r/CenturyClub');
$channel->addChild('pubDate', date($date_fmt));
$channel->addChild('lastBuildDate', date($date_fmt));
$atomlink = $channel->addChild('atom:atom:link');
$atomlink->addAttribute('rel', 'self');
$atomlink->addAttribute('type', 'application/rss+xml');

$comments = preg_split("/[\r\n]+/", file_get_contents('comments.txt', false));

foreach ($comments as $index => $comment_id) {
    $item = $channel->addChild('item');
    $item->addChild('title', 'Item');
    $guid = $item->addChild('guid', $comment_id);
    $guid->addAttribute('isPermalink', 'false');
    $item->addChild('pubDate', date($date_fmt));
    $item->addChild('link', 'http://reddit.com/r/CenturyClub/' . $comment_id );
}

/**
 * Output feed
 */
echo $rss->asXML();
?>
