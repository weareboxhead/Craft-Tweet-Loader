# Tweet Loader - Plugin for Craft CMS

'Synchronises' a given list of Twitter accounts with a Craft CMS Section.

More specifically, Tweet Loader retrieves content from a given set of Twitter accounts, saves new content as individual entries, updates the link to the tweet for existing entries, and closes entries which have been removed from Twitter.

The Section, Fields and Category Group are automatically created on installation.

## Usage

* Download and extract the plugin files
* Copy `tweetloader/` to your site's `/craft/plugins/` directory
* Install the plugin
* Fill in the fields in the plugin's [settings](#settings)
* Load `http://[yourdomain]/actions/tweetLoader/entries/syncWithRemote`

## <a name="settings"></a>Settings

### Consumer Key, Consumer Secret

See [https://dev.twitter.com/](https://dev.twitter.com/) for App creation.

### Section Id, Entry Type Id, Category Group Id

These are the ids of the Section, Entry Type and Category Group used.

Automatically populated on plugin install.

### Twitter User Ids

A comma separated list of Twitter account ids for which to retrieve content.

## Categories

When saving new entries, Tweet Loader will check if any categories in the Category Group match the post's tags.

Any which are found will be saved as categories for the entry.