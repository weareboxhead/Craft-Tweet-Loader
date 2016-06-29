<?php

namespace Craft;

use Abraham\TwitterOAuth\TwitterOAuth;

class TweetLoader_EntriesService extends BaseApplicationComponent
{
	private $callLimit = 200;
	private $sectionId;
	private $entryTypeId;
	private $twitterUserIds;
	private $connection;
	private $settings;

	function __construct()
	{
		// Get the wrapper for our authenticated call
		require_once craft()->path->getPluginsPath() . 'tweetloader/wrapper/autoload.php';

		$this->settings = craft()->plugins->getPlugin('tweetLoader')->getSettings();

		$consumerKey = $this->settings->consumerKey;

		if (!$consumerKey)
		{
			Craft::log('No Client Id provided in settings', LogLevel::Error);

			return;
		}

		$consumerSecret = $this->settings->consumerSecret;

		if (!$consumerSecret)
		{
			Craft::log('No Consumer Secret provided in settings', LogLevel::Error);

			return;
		}

		$this->sectionId = $this->settings->sectionId;

		if (!$this->sectionId)
		{
			Craft::log('No Section Id provided in settings', LogLevel::Error);

			return;
		}

		$this->entryTypeId = $this->settings->entryTypeId;

		if (!$this->entryTypeId)
		{
			Craft::log('No Entry Type Id provided in settings', LogLevel::Error);

			return;
		}

		$this->twitterUserIds 	= $this->settings->twitterUserIds;

		$this->connection = new TwitterOAuth($consumerKey, $consumerSecret);
	}

	private function getUserScreenName($userId)
	{
		$params = array(
			'user_id'			=> 	$userId,
			// We don't need this
			'include_entities'	=> false,
		);

		$user = $this->connection->get('users/show', $params);

		// We just need the screen name
		return $user->screen_name;
	}

	private function getRemoteData($userId)
	{
		$userScreenName = $this->getUserScreenName($userId);

		$tweets = array(); 

		$params = array(
			'user_id'		=> 	$userId,
			// Don't include retweets
			'include_rts'	=>	false,
			// Tweets by default returns tweets from last week. Return max possible
			'count'			=>	$this->callLimit,
			// We don't need all the info about the user returning every time
			'trim_user'		=>	true,
		);

		// While the Tweets API is still returning new results, keep collecting
		do {
			// Call for more tweets
			$result = $this->connection->get('statuses/user_timeline', $params);

			// If we were paginating, the first result (the tweet with id max_id), is a duplicate. Unset
			if (isset($params['max_id']))
			{
				unset($result[0]);
			}

			// Merge the actual Tweets content into our array
			$tweets = array_merge($tweets, $result);

			// Set the max id for the next call
			if (isset($tweets[count($tweets) - 1]))
			{
				$params['max_id'] = $tweets[count($tweets) - 1]->id;
			}
		} while (count($result));

		$data = array(
			'ids'		=>	array(),
			'tweets'	=>	array(),
		);

		// For each Tweets
		foreach ($tweets as $tweet) {
			// Get the id
			$tweetId = $tweet->id;
			// Add a reference to the screen name (in the same format twitter uses) for later use
			$tweet->screen_name = $userScreenName;

			// Add this id to our array
			$data['ids'][]				= $tweetId;
			// Add this tweet to our array, using the id as the key
			$data['tweets'][$tweetId] 	= $tweet;
		}

		return $data;
	}

	private function getLocalData($userId)
	{
		// Create a Craft Element Criteria Model
		$criteria = craft()->elements->getCriteria(ElementType::Entry);
		// Restrict the parameters to the correct channel
		$criteria->sectionId 		= $this->sectionId;
		// Restrict the parameters to the correct entry type
		$criteria->type 			= $this->entryTypeId;
		// Restrict the parameters to this user
		$criteria->search 			= 'twitterUserId:' . $userId;
		// Include closed entries
		$criteria->status 			= [null];
		// Don't limit the criteria
		$criteria->limit 			= null;

		$data = array(
			'ids'		=>	array(),
			'tweets'	=>	array(),
		);

		// For each Tweet
		foreach ($criteria as $entry) {
			// Get the id of this tweet
			$tweetId = $entry->tweetId;

			// Add this id to our array
			$data['ids'][]				= $tweetId;
			// Add this entry id to our array, using the tweet id as the key for reference
			$data['tweets'][$tweetId] 	= $entry->id;
		}

		return $data;
	}

	private function saveEntry($entry)
	{
		$success = craft()->entries->saveEntry($entry);

		// If the attempt failed
		if (!$success)
		{
			Craft::log('Couldn’t save entry ' . $entry->getContent()->id, LogLevel::Warning);
		}
	}

	private function constructTweetPageUrl($tweet)
	{
		return 'https://twitter.com/' . $tweet->screen_name . '/status/' . $tweet->id;
	}

	private function parseContent($tweet, $text, $userId)
	{
		// The standard content
		$content = array(
			'tweetId'			=>	$tweet->id,
			'twitterUserId'		=>	$userId,
			'tweetText'			=>	$text,
			'tweetPageUrl'		=>	$this->constructTweetPageUrl($tweet),
			'tweetCategories'	=>	craft()->tweetLoader_categories->parseCategories($tweet->entities->hashtags),
		);

		// Do we have any geo coordaintes we can save?
		if (isset($tweet->coordinates->coordinates))
		{
			$content['tweetLatitude'] = $tweet->coordinates->coordinates[0];
			$content['tweetLongitude'] = $tweet->coordinates->coordinates[1];
		} 
		else if (isset($tweet->place->bounding_box))
		{
			$coords = $tweet->place->bounding_box->coordinates[0][0];

			$content['tweetLocation'] = $tweet->place->name;
			$content['tweetLatitude'] = trim($coords[1]);
			$content['tweetLongitude'] = trim($coords[0]);
		}

		// Do we have any media items we can save?
		if (isset($tweet->entities->media[0]))
		{
			// We only want to save the URL if it is a photo
			if ($tweet->entities->media[0]->type === 'photo')
			{
				$content['tweetImageUrl'] = $tweet->entities->media[0]->media_url_https;
			}
		}

// echo '<pre>';
// print_r($tweet);
// echo '</pre>';
// exit;

		return $content;
	}

	// Standardise length for titles by running all though one function
	private function truncateTitle($title)
	{
		return craft()->tweetLoader_string->truncate($title);
	}

	private function getEntryById($id)
	{
		return craft()->entries->getEntryById($id);
	}

	private function createEntry($tweet, $userId)
	{
		// Does our plugin settings specify to ONLY save if a category (hashtag) exists?
		if ($this->settings->onlySaveTweetsWithCategories)
		{
			// Does this tweet have any of our categories?
			$matchingCategoryHastags = craft()->tweetLoader_categories->parseCategories($tweet->entities->hashtags);

			// If $matchinCategoryHastags is an empty array we need to get out of here
			if (empty($matchingCategoryHastags))
			{
				return;
			}
		}

		// Format the tweet text
		$text = $tweet->text;

		// Create a new instance of the Craft Entry Model
		$entry = new EntryModel();
		// Set the section id
		$entry->sectionId 	= $this->sectionId;
		// Set the entry type
		$entry->typeId 		= $this->entryTypeId;
		// Set the author as super admin
		$entry->authorId 	= 1;
		// Set disabled to begin with
		// $entry->enabled 	= false;
		// Set the publish date as post date
		$entry->postDate 	= strtotime($tweet->created_at);
		// Set the title
		$entry->getContent()->title = $this->truncateTitle($text);
		// Set the other content
		$entry->setContentFromPost($this->parseContent($tweet, $text, $userId));

		// Save the entry!
		$this->saveEntry($entry);
	}

	// Anything we like can be updated in here
	private function updateEntry($localEntry, $tweet)
	{
		// Set up an empty array for our updating content
		$content = array();

		// Get the remote Tweet page url
		// We are checking this in case the user's handle has changed
		$remoteTweetPageUrl = $this->constructTweetPageUrl($tweet);
		// Get the local Tweet page url
		$localTweetPageUrl 	= $localEntry->tweetPageUrl;

		if ($remoteTweetPageUrl !== $localTweetPageUrl) {
			$content['tweetPageUrl'] = $remoteTweetPageUrl;
		}

		// If we have no updating content, don't update the entry
		if (!count($content)) {
			return true;
		}

		// Set the content
		$localEntry->setContentFromPost($content);

		// Save the entry!
		$this->saveEntry($localEntry);
	}

	private function closeEntry($entry)
	{
		// Set the status to disabled
		$entry->enabled = false;
		// Save the entry!
		$this->saveEntry($entry);
	}

	public function syncWithRemote()
	{
		// If we failed to make the connection, don't go on
		if (!$this->connection)
		{
			return;
		}

		// Explode our list of ids
		foreach (explode(',', $this->twitterUserIds) as $userId)
		{
			// Remove any white space
			$userId = trim($userId);

			// If there are no user ids, continue
			if (!strlen($userId))
			{
				Craft::log('No user ids specified', LogLevel::Warning);

				continue;
			}


			// Get remote data
			$remoteData = $this->getRemoteData($userId);

			if (!$remoteData)
			{
				Craft::log('Failed to get remote data for user id: ' . $userId, LogLevel::Error);

				continue;
			}

			// Get local data
			$localData 	= $this->getLocalData($userId);

			if (!$localData)
			{
				Craft::log('Failed to get local data for user id: ' . $userId, LogLevel::Error);

				continue;
			}

			// Determine which entries we are missing by id
			$missingIds 	= 	array_diff($remoteData['ids'], $localData['ids']);

			// Determine which entries we shouldn't have by id
			$removedIds 	= 	array_diff($localData['ids'], $remoteData['ids']);

			// Determine which entries need updating (all active entries which we aren't about to create)
			$updatingIds 	=	array_diff($remoteData['ids'], $missingIds);


			// For each missing id
			foreach ($missingIds as $id)
			{
				// Create this entry
			    $this->createEntry($remoteData['tweets'][$id], $userId);
			}

			// For each redundant entry
			foreach ($removedIds as $id)
			{
				// Disable it
			    $this->closeEntry($this->getEntryById($localData['tweets'][$id]));
			}

			// For each updating track
			foreach ($updatingIds as $id) {
				$this->updateEntry($this->getEntryById($localData['tweets'][$id]), $remoteData['tweets'][$id]);
			}
		}
	}
}