<?php

namespace Craft;

class TweetLoaderPlugin extends BasePlugin
{
	function getName()
	{
		return Craft::t('Tweet Loader');
	}

	function getVersion()
	{
		return '0.2.1';
	}

	function getDeveloper()
	{
		return 'Boxhead';
	}

	function getDeveloperUrl()
	{
		return 'http://boxhead.io';
	}

	function onAfterInstall()
	{
		// Create the Tweets Field Group
		Craft::log('Creating the Tweets Field Group.');

		$group = new FieldGroupModel();
		$group->name = 'Tweets';

		if (craft()->fields->saveGroup($group))
		{
			Craft::log('Tweets field group created successfully.');
			
			$groupId = $group->id;
		}
		else
		{
			Craft::log('Could not save the Tweets field group.', LogLevel::Error);

			return false;
		}

		// Create the Basic Fields
		Craft::log('Creating the basic Tweets Fields.');

		$basicFields = array(
			'tweetId'			=>	'Tweet Id',
			'twitterUserId'		=>	'Twitter User Id',
			'tweetText'			=>	'Tweet Text',
			'tweetPageUrl'		=>	'Tweet Page URL',
			'tweetImageUrl'		=>	'Tweet Image URL',
			'tweetLocation'		=>  'Tweet Location String',
			'tweetLatitude'		=>	'Tweet Location Latitude',
			'tweetLongitude'	=>	'Tweet Location Longitude'
		);

		$tweetsEntryLayoutIds = array();

		foreach($basicFields as $handle => $name) {
			Craft::log('Creating the ' . $name . ' field.');

			$field = new FieldModel();
			$field->groupId	  		= $groupId;
			$field->name	 		= $name;
			$field->handle	   		= $handle;
			$field->translatable 	= true;
			$field->type		 	= 'PlainText';

			if (craft()->fields->saveField($field))
			{
				Craft::log($name . ' field created successfully.');

				$tweetsEntryLayoutIds[] = $field->id;
			}
			else
			{
				Craft::log('Could not save the ' . $name . ' field.', LogLevel::Error);

				return false;
			}
		}

		// Create the Tweets category group
		Craft::log('Creating the Tweets category group.');

		$categoryGroup = new CategoryGroupModel();

		$categoryGroup->name = 'Tweets';
		$categoryGroup->handle = 'tweets';
		$categoryGroup->hasUrls = false;

		if (craft()->categories->saveGroup($categoryGroup))
		{
			Craft::log('Tweets category group created successfully.');
		}
		else
		{
			Craft::log('Could not create the Tweets category group.', LogLevel::Error);

			return false;
		}

		// Create the Tweet Categories field
		Craft::log('Creating the Tweet Categories field.');

		$categoriesField = new FieldModel();
		$categoriesField->groupId		= $groupId;
		$categoriesField->name			= 'Tweet Categories';
		$categoriesField->handle		= 'tweetCategories';
		$categoriesField->translatable	= true;
		$categoriesField->type			= 'Categories';
		$categoriesField->settings		= array( 'source' => 'group:' . $categoryGroup->id );

		if (craft()->fields->saveField($categoriesField))
		{
			Craft::log('Tweet Categories field created successfully.');

			$tweetsEntryLayoutIds[] = $categoriesField->id;
		}
		else
		{
			Craft::log('Could not save the Tweet Categories field.', LogLevel::Error);

			return false;
		}

		// Create the Tweets Field Layout
		Craft::log('Creating the Tweets Field Layout.');

		if ($tweetsEntryLayout = craft()->fields->assembleLayout(array('Tweets' => $tweetsEntryLayoutIds), array()))
		{
			Craft::log('Tweets Field Layout created successfully.');
		}
		else
		{
			Craft::log('Could not create the Tweets Field Layout', LogLevel::Error);

			return false;
		}	

		$tweetsEntryLayout->type = ElementType::Entry;

		// Create the Tweets Channel
		Craft::log('Creating the Tweets Channel.');

		$tweetsChannelSection 					= new SectionModel();
		$tweetsChannelSection->name 			= 'Tweets';
		$tweetsChannelSection->handle 			= 'tweets';
		$tweetsChannelSection->type 			= SectionType::Channel;
		$tweetsChannelSection->hasUrls 			= false;
		$tweetsChannelSection->enableVersioning = false;

		$primaryLocaleId = craft()->i18n->getPrimarySiteLocaleId();
		$locales[$primaryLocaleId] = new SectionLocaleModel(array(
			'locale'		  => $primaryLocaleId,
		));

		$tweetsChannelSection->setLocales($locales);

		if (craft()->sections->saveSection($tweetsChannelSection))
		{
			Craft::log('Tweets Channel created successfully.');
		}
		else
		{
			Craft::log('Could not create the Tweets Channel.', LogLevel::Warning);

			return false;
		}

		// Get the array of entry types for our new section
		$tweetsEntryTypes = $tweetsChannelSection->getEntryTypes();
		// There will only be one so get that
		$tweetsEntryType = $tweetsEntryTypes[0];

		$tweetsEntryType->hasTitleField = true;
		$tweetsEntryType->titleLabel 	= 'Title';
		$tweetsEntryType->setFieldLayout($tweetsEntryLayout);

		if (craft()->sections->saveEntryType($tweetsEntryType))
		{
			Craft::log('Tweets Channel Entry Type saved successfully.');
		}
		else
		{
			Craft::log('Could not create the Tweets Channel Entry Type.', LogLevel::Warning);

			return false;
		}

		// Save the settings based on the section and entry type we just created
		craft()->plugins->savePluginSettings($this,
			array(
				'sectionId'	 		=> $tweetsChannelSection->id,
				'entryTypeId'   	=> $tweetsEntryType->id,
				'categoryGroupId'   => $categoryGroup->id,
			)
		);
	}

	protected function defineSettings()
	{
		return array(
			'consumerKey'					=> array(AttributeType::String, 'default' => ''),
			'consumerSecret'				=> array(AttributeType::String, 'default' => ''),
			'sectionId'						=> array(AttributeType::String, 'default' => ''),
			'entryTypeId'					=> array(AttributeType::String, 'default' => ''),
			'categoryGroupId'				=> array(AttributeType::String, 'default' => ''),
			'twitterUserIds'				=> array(AttributeType::String, 'default' => ''),
			'onlySaveTweetsWithCategories'	=> array(AttributeType::Bool, 'default' => '')
		);
	}

	public function getSettingsHtml()
	{
		return craft()->templates->render('tweetloader/settings', array(
			'settings' => $this->getSettings()
		));
	}
}