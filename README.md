# QCloudStorage Extension
MediaWiki extension that make MW using QCloud COS instead of local storage implementation

## Installation
1. Clone git repository  
`git clone https://github.com/RazeSoldier/MediaWiki-extension-QCloudStorage.git`  
or download snapshot [tarball](https://github.com/RazeSoldier/MediaWiki-extension-QCloudStorage/archive/master.zip)
to `mediawiki/extensions` directory
2. Install third-party dependencies: `composer install --no-dev`
3. Put `wfLoadExtension( 'QCloudStorage' );` to your LocalSettings.php.

## Configuration
Proper use of this extension must follow the configuration format below:
```php
$wgFileBackends[] = [
	'name' => 'qcloud',
	'class' => \RazeSoldier\MWQCloudStorage\QCloudFileBackend::class,
	'wikiId' => 'wiki',
	'bucket' => 'image-123456', // Here is the name of the bucket to upload the file to, format: `<name>-<appid>`
	'lockManager' => 'fsLockManager',
	'viewpoint' => 'https://cdn.example.com', // Optional. This configuration can customize the domain of the file, usually set to CDN domain.
];

$wgQCloudAuth = [
	'region' => 'ap-guangzhou', // The bucket region
	'secretId' => 'XXX', // Account secretId
	'secretKey' => 'XXX', // Account secretKey
];

// This will officially replace the original file storage implementation
$wgLocalFileRepo = [
	'name' => 'local',
	'class' => \RazeSoldier\MWQCloudStorage\QCloudRepo::class,
	'backend' => 'qcloud',
];
```

## How to migrate old file to COS
1. Make sure the configuration is correct
2. Comment `$wgLocalFileRepo` array
3. Do migrate: `php maintenance\copyFileBackend.php --src local-backend --dst qcloud --containers local-public`
4. Uncomment `$wgLocalFileRepo` array

Note: Migration time depending on the size of your local file repository.

## Support
If you encounter any problems, please feel free to ask the [Issues](https://github.com/RazeSoldier/MediaWiki-extension-QCloudStorage/issues).
