<?php

namespace Craft;

class TweetLoader_EntriesController extends BaseController
{
	protected $allowAnonymous = true;
	
	public function actionSyncWithRemote() {
		craft()->tweetLoader_entries->syncWithRemote();

		$this->renderTemplate('tweetLoader/empty');
	}
}