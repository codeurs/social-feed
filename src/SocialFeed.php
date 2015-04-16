<?php

namespace Codeurs\SocialFeed;

use Abraham\TwitterOAuth\TwitterOAuth;

/**
 * Supported services at this time
 */
class SocialFeedService {
    const Twitter   = 'twitter';
    const Facebook  = 'facebook';
    const Instagram = 'instagram';
}

class SocialFeedItemUser {
    /** @var string */
    var $id;
    /** @var string */
    var $image;
    /** @var string */
    var $name;
    /** @var string */
    var $handle;
    /** @var string */
    var $link;
}

class SocialFeedItemMediaVideo {
    /** @var string */
    var $service;
    /** @var string */
    var $id;
    /** @var string */
    var $image;
}

class SocialFeedItemMedia {
    /** @var string */
    var $image;
    /** @var SocialFeedItemMediaVideo */
    var $video;
}

class SocialFeedItem {
    /** @var string */
    var $service;
    /** @var string */
    var $text;
    /** @var string */
    var $link;
    /** @var string */
    var $id;
    /** @var int */
    var $created;
    /** @var SocialFeedItemUser */
    var $user;
    /** @var SocialFeedItemMedia */
    var $media;
}

/**
 * Get feeds from different social networks in a unified format
 */
class SocialFeed {
    /** @var array */
    private $credentials;

    public function __construct() {
        $this->credentials = [];
    }

    /**
     * @param string $service
     * @param array $credentials
     * @throws \Exception
     */
    public function setCredentials($service, array $credentials) {
        switch ($service) {
            case SocialFeedService::Twitter:
                $this->requireCredentialKeys($service, ['consumer_key', 'consumer_secret', 'access_token', 'access_token_secret'], $credentials);
                break;
            case SocialFeedService::Facebook:
                $this->requireCredentialKeys($service, ['app_id', 'app_secret'], $credentials);
                break;
            case SocialFeedService::Instagram:
                $this->requireCredentialKeys($service, ['client_id', 'client_secret'], $credentials);
                break;
            default:
                throw $this->e("Unrecognized service: $service");
        }
        $this->credentials[$service] = (object) $credentials;
    }

    public function getFeed($service, $username) {
        $response = [];
        switch ($service) {
            case SocialFeedService::Twitter:
                $credentials = $this->getCredentials(SocialFeedService::Twitter);
                $connection = new TwitterOAuth($credentials->consumer_key, $credentials->consumer_secret, $credentials->access_token, $credentials->access_token_secret);
                $data = $connection->get("statuses/user_timeline", array("screen_name" => $username));
                if (isset($data->errors)) {
                    throw $this->serviceError($service, $data->errors[0]->message);
                }
                foreach ($data as $item) {
                    $response[] = $this->parseItem($service, $item);
                }
                break;
            case SocialFeedService::Facebook:
                $credentials = $this->getCredentials(SocialFeedService::Facebook);
                $request = @file_get_contents("https://graph.facebook.com/v2.3/$username/feed?access_token={$credentials->app_id}|{$credentials->app_secret}");
                if ($request === false) {
                    throw $this->serviceError($service, 'Could not load feed, check credentials');
                }
                $data = json_decode($request);
                foreach ($data->data as $item) {
                    $response[] = $this->parseItem($service, $item);
                }
                break;
            default:
                throw $this->e("Unrecognized service: $service");
        }
        return $response;
    }

    public function getItem($service, $id) {

    }

    private function parseItem($service, $item) {
        $response = new SocialFeedItem();
        $user = new SocialFeedItemUser();
        $media = new SocialFeedItemMedia();
        $response->service = $service;
        switch ($service) {
            case SocialFeedService::Twitter:
                $response->id = $item->id;
                $response->created = strtotime($item->created_at);

                $user->id = $item->user->id;
                $user->handle = $item->user->screen_name;
                $user->image = $item->user->profile_image_url_https;
                $user->link = $item->user->url;
                $user->name = $item->user->name;


                $response->link = "https://twitter.com/{$user->handle}/status/{$response->id}";
                $response->text = $item->text;

                if (isset($item->extended_entities->media)) {
                    $img = $item->extended_entities->media[0];
                    $media->image = $img->media_url_https;
                }

                if (isset($item->entities->urls)) {
                    foreach ($item->entities->urls as $url) {
                        $media = $this->mediaFromUrl($url->expanded_url);
                        if ($media->image !== null || $media->video->id !== null)
                            break;
                    }
                }

                break;
            case SocialFeedService::Facebook:
                $response->id = $item->id;
                $response->created = strtotime($item->created_time);

                $user->id = $item->from->id;
                $user->name = $item->from->name;
                $user->image = "https://graph.facebook.com/v2.3/{$user->id}/picture/";
                $user->link = "https://facebook.com/profile.php?id={$user->id}";

                $response->link = $item->link;
                $response->text = $item->message;

                switch ($item->type) {
                    case 'photo':
                        $media->image = "https://graph.facebook.com/{$item->object_id}/picture?type=normal";
                        break;
                    case 'video':
                        $media = $this->mediaFromUrl($item->link);
                }
                break;
        }
        $response->user = $user;
        $response->media = $media;
        return $response;
    }

    private function mediaFromUrl($url) {
        $media = new SocialFeedItemMedia();
        $video = new SocialFeedItemMediaVideo();
        switch (1) {
            case preg_match('/vine\.co\/v\/([a-z0-9]+)/i', $url, $matches):
                $video->id = $matches[1];
                $video->service = 'vine';
                $vine = @file_get_contents("http://vine.co/v/{$video->id}");
                if ($vine !== false) {
                    preg_match('/property="og:image" content="(.*?)"/', $vine, $images);
                    if (isset($images[1]) && $images[1] != '')
                        $video->image = $images[1];
                }
                break;
            case preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/ ]{11})/i', $url, $matches):
                $video->id = $matches[1];
                $video->service = 'youtube';
                $video->image = "http://img.youtube.com/vi/{$video->id}/hqdefault.jpg";
                break;
            case preg_match('/https?:\/\/(?:www\.|player\.)?vimeo.com\/(?:channels\/(?:\w+\/)?|groups\/([^\/]*)\/videos\/|album\/(\d+)\/video\/|video\/|)(\d+)(?:$|\/|\?)/i', $url, $matches):
                $video->id = $matches[3];
                $video->service = 'vimeo';
                break;
            case preg_match('/instagram\.com\/p\/([a-z0-9-_]+)\//i', $url, $matches):
                $data = @file_get_contents('http://api.instagram.com/oembed?url='.urlencode($url));
                if ($data !== false) {
                    $info = json_decode($data);
                    if (strpos($info->html, 'video') > -1) {
                        $video->id = $matches[0];
                        $video->service = 'instagram';
                        $video->image = $info->thumbnail_url;
                    } else {
                        $media->image = $info->thumbnail_url;
                    }
                }
                break;
            case preg_match('/facebook\.com\/.+\/videos\/([0-9]+)\//i', $url, $matches):
                $video->id = $matches[1];
                $video->service = 'facebook';
                $video->image = "https://graph.facebook.com/{$video->id}/picture?type=large";
                break;
        }
        $media->video = $video;
        return $media;
    }

    private function requireCredentialKeys($service, array $keys, array $credentials) {
        foreach ($keys as $key)
            if (!array_key_exists($key, $credentials))
                throw $this->e("Missing credential $key in $service");
    }

    private function getCredentials($service) {
        if (!array_key_exists($service, $this->credentials))
            throw $this->e("Missing credentials for service $service");
        return $this->credentials[$service];
    }

    private function serviceError($service, $error) {
        return new \Exception("Service $service reports error: $error");
    }

    private function e($msg) {
        return new \Exception($msg);
    }
}