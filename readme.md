# SocialFeed

Get feeds from different social networks in a unified format

## Install

Use composer: `composer install codeurs/social-feed` or simply use the SocialFeed.php class in the src directory.

## Features

- Get the most recent posts of a user
- Get a specific item based on an ID
- Implemented media: Twitter, Facebook, Instagram
- Returns items in simple format
- Gets information (id, service, thumbnail) on attached video/images from: youtube, vine, instagram, vimeo

## Example

### Facebook

```php
$feed = new SocialFeed();

// Input api credentials
$feed->facebook->setCredentials([
  'app_id' => '__',
  'app_secret' => '__'
]);

// Get all recent posts for user 'codeurs.be'
$data = $feed->facebook->getFeed('codeurs.be');
foreach ($data as $item) {
  // ...
}

// Get a single facebook post
$item = $feed->facebook->getItem('646338148755451_704084252980840');
```

### Output

Output returns the following format (as a php object; printed as json for readability):

```json
{
  "service": "facebook",
  "text": "Example.",
  "link": "https://www.facebook.com/____",
  "id": "_",
  "created": 1428764403,
  "user": {
    "id": "_",
    "image": "https://graph.facebook.com/v2.3/_/picture/",
    "name": "_",
    "handle": null,
    "link": "https://facebook.com/profile.php?id=_"
  },
  "media": {
    "image": "https://graph.facebook.com/_/picture?type=normal",
    "video": null
  }
}
```

If a video is present, information will be available in media.video:

```json
{
  "image": null,
  "video": {
    "service": "youtube",
    "id": "_",
    "image": "http://img.youtube.com/vi/_/hqdefault.jpg"
  }
}
```

## Credentials

The following credentials are needed depending on the medium:
- Facebook: app_id, app_secret
- Twitter: consumer_key, consumer_secret, access_token, access_token_secret
- Instagram: client_id, client_secret