<?php

namespace Codeurs\SocialFeed;

use Abraham\TwitterOAuth\TwitterOAuth;

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
 * @property-read SocialFeedServiceTwitter $twitter
 * @property-read SocialFeedService $facebook
 * @property-read SocialFeedService $instagram
 */
class SocialFeed {
    private $services = [];

    /**
     * @param $service
     * @return SocialFeedService
     * @throws \Exception
     */
    public function __get($service) {
        if (isset($this->services[$service]))
            return $this->services[$service];

        $className = 'Codeurs\\SocialFeed\\SocialFeedService'.ucfirst($service);
        if (!class_exists($className))
            throw new \Exception("Service not found: $service");

        return $this->services[$service] = new $className();
    }
}

abstract class SocialFeedService {
    /** @var object */
    protected $credentials;
    /** @var string */
    protected $service;

    /**
     * @param array $credentials
     * @return void
     * @throws \Exception
     */
    abstract public function setCredentials(array $credentials);

    /**
     * @param string $username
     * @return SocialFeedItem[]
     */
    abstract public function getFeed($username);

    /**
     * @param string $id
     * @return SocialFeedItem
     */
    abstract public function getItem($id);

    protected function mediaFromUrl($url) {
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
            //case preg_match('/amp\.twimg\.com\/v\/([a-z0-9-]+)/i', $url, $matches):
        }
        $media->video = $video;
        return $media;
    }

    protected function requireCredentialKeys(array $keys, array $credentials) {
        foreach ($keys as $key)
            if (!array_key_exists($key, $credentials))
                throw $this->e("Missing credential $key");
    }

    protected function getCredentials() {
        if (!isset($this->credentials))
            throw $this->e("Missing credentials for service {$this->service}");
        return $this->credentials;
    }

    protected function serviceError($error) {
        return new \Exception("Service {$this->service} reports error: $error");
    }

    protected function e($msg) {
        return new \Exception($msg);
    }
}

class SocialFeedServiceTwitter extends SocialFeedService {
    protected $service = 'twitter';
    protected $connection;

    protected function getConnection() {
        if ($this->connection !== null)
            return $this->connection;
        $credentials = $this->getCredentials();
        return $this->connection = new TwitterOAuth($credentials->consumer_key, $credentials->consumer_secret, $credentials->access_token, $credentials->access_token_secret);
    }

    public function setCredentials(array $credentials) {
        $this->requireCredentialKeys(['consumer_key', 'consumer_secret', 'access_token', 'access_token_secret'], $credentials);
        $this->credentials = (object) $credentials;
    }

    public function getFeed($username) {
        $response = [];
        $connection = $this->getConnection();
        $data = $connection->get('statuses/user_timeline', ['screen_name' => $username]);
        if (isset($data->errors)) {
            throw $this->serviceError($data->errors[0]->message);
        }
        foreach ($data as $item) {
            $response[] = $this->parseItem($item);
        }
        return $response;
    }

    public function getItem($id) {
        return $this->parseItem($this->getConnection()->get("statuses/show/$id"));
    }

    private function parseItem($item) {
        $response = new SocialFeedItem();
        $user = new SocialFeedItemUser();
        $media = new SocialFeedItemMedia();
        $response->service = $this->service;
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
                $parsed = $this->mediaFromUrl($url->expanded_url);
                if ($parsed->image !== null || $parsed->video->id !== null)
                    $media = $parsed;
                    break;
            }
        }

        $response->user = $user;
        $response->media = $media;

        return $response;
    }
}

class SocialFeedServiceFacebook extends SocialFeedService {
    const API_URL = 'https://graph.facebook.com/v2.3/';

    protected $service = 'facebook';
    protected $connection;

    public function setCredentials(array $credentials) {
        $this->requireCredentialKeys(['app_id', 'app_secret'], $credentials);
        $this->credentials = (object) $credentials;
    }

    public function getFeed($username) {
        $response = [];
        $data = $this->getGraph("$username/feed");
        foreach ($data->data as $item) {
            $response[] = $this->parseItem($item);
        }
        return $response;
    }

    public function getItem($id) {
        return $this->parseItem($this->getGraph($id));
    }

    protected function getGraph($endpoint) {
        $credentials = $this->getCredentials();
        $request = @file_get_contents(self::API_URL.$endpoint."?access_token={$credentials->app_id}|{$credentials->app_secret}");
        if ($request === false) {
            throw $this->serviceError('Could not load feed, check credentials');
        }
        return json_decode($request);
    }

    private function parseItem($item) {
        $response = new SocialFeedItem();
        $user = new SocialFeedItemUser();
        $media = new SocialFeedItemMedia();
        $response->service = $this->service;
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
        $response->user = $user;
        $response->media = $media;
        return $response;
    }

}

class SocialFeedServiceInstagram extends SocialFeedService {
    const API_URL = 'https://api.instagram.com/v1/';

    protected $service = 'instagram';
    protected $connection;

    public function setCredentials(array $credentials) {
        $this->requireCredentialKeys(['client_id', 'client_secret'], $credentials);
        $this->credentials = (object) $credentials;
    }

    public function getFeed($username) {
        $response = [];
        $data = $this->getApi("users/search?q=$username");
        $id = $data->data[0]->id;
        $data = $this->getApi("/users/$id/media/recent/");
        foreach ($data->data as $item) {
            $response[] = $this->parseItem($item);
        }
        return $response;
    }

    public function getItem($id) {
        //return $this->parseItem($this->getGraph($id));
    }

    protected function getApi($endpoint) {
        $credentials = $this->getCredentials();
        $request = @file_get_contents(self::API_URL.$endpoint.(strpos($endpoint, '?') > -1 ? '&' : '?')."client_id={$credentials->client_id}");
        if ($request === false) {
            throw $this->serviceError("Could not load endpoint '$endpoint' from service {$this->service}, check credentials");
        }
        return json_decode($request);
    }

    private function parseItem($item) {
        $response = new SocialFeedItem();
        $user = new SocialFeedItemUser();
        $media = new SocialFeedItemMedia();
        $response->service = $this->service;
        $response->id = $item->id;
        $response->created = strtotime($item->created_time);
        $user->id = $item->user->id;
        $user->name = $item->user->full_name;
        $user->handle = $item->user->username;
        $user->image = $item->user->profile_picture;
        $user->link = "https://instagram.com/{$user->handle}";
        $response->link = $item->link;
        $response->text = $item->caption->text;
        /*switch ($item->type) {
            case 'photo':
                $media->image = "https://graph.facebook.com/{$item->object_id}/picture?type=normal";
                break;
            case 'video':
                $media = $this->mediaFromUrl($item->link);
        }*/

        if (isset($item->images->standard_resolution->url)) {
            $media->image = $item->images->standard_resolution->url;
        }

        //images->standard_resolution->url

        $response->user = $user;
        $response->media = $media;
        return $response;
    }

}
