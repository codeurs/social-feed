<?php

namespace Codeurs\SocialFeed;

use Abraham\TwitterOAuth\TwitterOAuth;

class User {
	/** @var string */
	public $id;
	/** @var string */
	public $image;
	/** @var string */
	public $name;
	/** @var string */
	public $handle;
	/** @var string */
	public $link;
}

class Video {
	/** @var string */
	public $service;
	/** @var string */
	public $id;
	/** @var string */
	public $image;
}

class Media {
	/** @var string */
	public $image;
	/** @var Video */
	public $video;
    /** @var Array */
    public $hash;

    public function calcHash() {
        $phasher = \PHasher::Instance();
        $url = $this->image != null ? $this->image : ($this->video == null ? null : $this->video->image);
        if ($url == null) return;
        $resource = imagecreatefromstring(file_get_contents($url));
        try {
            $hash = $phasher->FastHashImage($resource);
            $this->hash = $phasher->HashAsString($hash);
        } catch (\Exception $e) {
            $this->hash = null;
        }
    }
}

class Item {
	/** @var string */
	public $service;
	/** @var string */
	public $text;
	/** @var string */
	public $link;
	/** @var string */
	public $id;
	/** @var int */
	public $created;
	/** @var User */
	public $user;
	/** @var Media */
	public $media;

    public function image() {
        return $this->media->image != null ? $this->media->image : ($this->media->video == null ? null : $this->media->video->image);
    }
}

class Config {
    public function __construct(Array $cfg = []) {
        foreach ($cfg as $k => $v) {
            if (isset($this->{$k})) {
                $this->{$k} = $v;
            }
        }
    }
    /** @var bool */
    public $create_hash = false;
}

/**
 * Get feeds from different social networks in a unified format
 * @property-read TwitterService $twitter
 * @property-read SocialFeedService $facebook
 * @property-read SocialFeedService $instagram
 */
class SocialFeed {
    /** @var array */
	private $services = [];
    /** @var array */
	private $map = [];
    /** @var Config */
    private $config;

	public function __construct(Array $config = []) {
        $this->config = new Config($config);
		$this->registerService('twitter', 'Codeurs\\SocialFeed\\TwitterService');
		$this->registerService('facebook', 'Codeurs\\SocialFeed\\FacebookService');
		$this->registerService('instagram', 'Codeurs\\SocialFeed\\InstagramService');
	}

	/**
	 * @param $service
	 * @return SocialFeedService
	 * @throws \Exception
	 */
	public function __get($service) {
		if (isset($this->services[$service]))
			return $this->services[$service];

		if (!isset($this->map[$service]))
			throw new \Exception("Service not found: $service");

		$instance = new $this->map[$service]($this->config);
		if (!$instance instanceof SocialFeedService) {
			throw new \Exception("Service $service does not implement SocialFeedService");
		}

		return $this->services[$service] = $instance;
	}

	/**
	 * Add a new service
	 * @param string $service
	 * @param string $className
	 */
	public function registerService($service, $className) {
		$this->map[$service] = $className;
	}

	/**
	 * Get service by url
	 * @param $url
	 * @return null|string
	 */
	public function getServiceFromUrl($url) {
		if (strpos($url, 'facebook') > -1)
			return 'facebook';
		if (strpos($url, 'twitter') > -1)
			return 'twitter';
		if (strpos($url, 'instagram') > -1)
			return 'instagram';
		return null;
	}
}

/**
 * Extend to provide a social medium
 * @package Codeurs\SocialFeed
 */
abstract class SocialFeedService {
	/** @var object */
	protected $credentials;
	/** @var string */
	protected $service;
    /** @var Config */
    protected $config;

    public function __construct(Config $config) {
        $this->config = $config;
    }

	/**
	 * @param array $credentials
	 * @return void
	 * @throws \Exception
	 */
	abstract public function setCredentials(array $credentials);

	/**
	 * @param string $username
	 * @return Item[]
	 */
	abstract public function getFeed($username);

	/**
	 * @param string $id
	 * @return Item|null
	 */
	abstract public function getItem($id);

	/**
	 * @param string $url
	 * @return string|null
	 */
	abstract public function getIdFromUrl($url);

	/**
	 * @param string $url
	 * @return Item|null
	 */
	public function getItemFromUrl($url) {
		return $this->getItem($this->getIdFromUrl($url));
	}

	protected function mediaFromUrl($url) {
		$media = new Media();
		$video = new Video();
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
            default:
                $video = null;
                break;
		}
        if ($video == null || $video->service == null || $video->id == null) $video = null;
		$media->video = $video;
		return $media;
	}

    protected function process(Item $item) {
        if ($this->config->create_hash) {
            $item->media->calcHash();
        }
        return $item;
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

class TwitterService extends SocialFeedService {
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

	public function getIdFromUrl($url) {
		if (preg_match('/status\/([0-9]+)/i', $url, $matches)) {
			return $matches[1];
		}
		return null;
	}

	private function parseItem($item) {
		$response = new Item();
		$user = new User();
		$media = new Media();
		$response->service = $this->service;
		$response->id = $item->id;
		$response->created = strtotime($item->created_at);
		if (isset($item->retweeted_status)) {
			$user->id = $item->retweeted_status->user->id;
			$user->handle = $item->retweeted_status->user->screen_name;
			$user->image = $item->retweeted_status->user->profile_image_url_https;
			$user->link = $item->retweeted_status->user->url;
			$user->name = $item->retweeted_status->user->name;
		} else {
			$user->id = $item->user->id;
			$user->handle = $item->user->screen_name;
			$user->image = $item->user->profile_image_url_https;
			$user->link = $item->user->url;
			$user->name = $item->user->name;
		}
		$response->link = "https://twitter.com/{$user->handle}/status/{$response->id}";
		$response->text = $item->text;

		if (isset($item->extended_entities->media)) {
			$img = $item->extended_entities->media[0];
			$media->image = $img->media_url_https;
		}

		if (isset($item->entities->urls)) {
			foreach ($item->entities->urls as $url) {
				$parsed = $this->mediaFromUrl($url->expanded_url);
				if ($parsed->image !== null || ($parsed->video != null && $parsed->video->id !== null))
					$media = $parsed;
				break;
			}
		}

		$response->user = $user;
		$response->media = $media;

		return $this->process($response);
	}
}

class FacebookService extends SocialFeedService {
	const API_URL = 'https://graph.facebook.com/v2.3/';

	protected $service = 'facebook';
	protected $connection;

	public function setCredentials(array $credentials) {
		$this->requireCredentialKeys(['app_id', 'app_secret'], $credentials);
		$this->credentials = (object) $credentials;
	}

	public function getFeed($username) {
		$response = [];
		$user = $this->getGraph("$username");
		$id = $user->id;
		$data = $this->getGraph("$username/feed");
		foreach ($data->data as $item) {
			$item = $this->parseItem($item, $id);
			if ($item !== null)
				$response[] = $item;
		}
		return $response;
	}

	public function getItem($id) {
		return $this->parseItem($this->getGraph($id));
	}

	public function getIdFromUrl($url) {
		if (preg_match('/\/posts\/([0-9]+)/i', $url, $matches)) {
			return $matches[1];
		}
		$request = @file_get_contents("https://graph.facebook.com/?ids=".urlencode($url));
		if ($request === false)
			return null;
		$data = json_decode($request);
		return $data->{$url}->id;
	}

	protected function getGraph($endpoint) {
		$credentials = $this->getCredentials();
		$request = @file_get_contents(self::API_URL.$endpoint."?access_token={$credentials->app_id}|{$credentials->app_secret}");
		if ($request === false) {
			throw $this->serviceError('Could not load feed, check credentials');
		}
		return json_decode($request);
	}

	private function parseItem($item, $id = null) {
		if ($id !== null && $item->from->id != $id)
			return null;

		$response = new Item();
		$user = new User();
		$media = new Media();
		$response->service = $this->service;
		$response->id = $item->id;
		$response->created = strtotime($item->created_time);
		$user->id = $item->from->id;
		$user->name = $item->from->name;
		$user->image = "https://graph.facebook.com/v2.3/{$user->id}/picture/";
		$user->link = "https://facebook.com/profile.php?id={$user->id}";
		$response->link = $item->link;//"http://www.facebook.com/permalink.php?id={$user->id}&v=wall&story_fbid={$response->id}";
		if (isset($item->message))
			$response->text = $item->message;
		if (isset($item->name))
			$response->text = $item->name;
		if (isset($item->type)) {
			switch ($item->type) {
				case 'photo':
					$media->image = "https://graph.facebook.com/{$item->object_id}/picture?type=normal";
					break;
				case 'video':
					$media = $this->mediaFromUrl($item->link);
			}
		}

		if (isset($item->images)) {
			$total = count($item->images);
			if ($total > 1)
				$media->image = $item->images[1]->source;
			else if ($total == 1)
				$media->image = $item->images[0]->source;
		}

		$response->user = $user;
		$response->media = $media;
		return $this->process($response);
	}

}

class InstagramService extends SocialFeedService {
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
		$data = $this->getApi("users/$id/media/recent/");
		foreach ($data->data as $item) {
			$response[] = $this->parseItem($item);
		}
		return $response;
	}

	public function getItem($id) {
		$url = urlencode("https://instagram.com/p/$id/");
		$data = $this->getApi("oembed?url=$url");
		$media_id = $data->media_id;
		return $this->parseItem($this->getApi("media/$media_id")->data);
	}

	public function getIdFromUrl($url) {
		if (preg_match('/instagram\.com\/p\/([a-z0-9-_]+)\//i', $url, $matches)) {
			return $matches[1];
		}
		return null;
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
		$response = new Item();
		$user = new User();
		$media = new Media();
		$response->service = $this->service;
		$response->id = $item->id;
		$response->created = (int) $item->created_time;
		$user->id = $item->user->id;
		$user->name = $item->user->full_name;
		$user->handle = $item->user->username;
		$user->image = $item->user->profile_picture;
		$user->link = "https://instagram.com/{$user->handle}";
		$response->link = $item->link;
		$response->text = $item->caption->text;

		switch ($item->type) {
			case 'video':
				$media->video = new Video();
				$media->video->id = $response->id;
				$media->video->image = $item->images->standard_resolution->url;
				$media->video->service = 'instagram';
				break;
			case 'image':
				$media->image = $item->images->standard_resolution->url;
				break;
		}

		$response->user = $user;
		$response->media = $media;
		return $this->process($response);
	}

}
