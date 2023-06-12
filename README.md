# Nextcloud App Ecosystem V2

Nextcloud App Ecosystem V2 provides a new API for external apps on different programming languages

| Currently in a prototyping stage

## Dev

`base.php` adjustment for authentication of ex apps.

`base.php - handleLogin` add after apache auth

```php
if (self::tryAppEcosystemV2Login($request)) {
	return true;
}
```

and function in the end

```php
protected static function tryAppEcosystemV2Login(OCP\IRequest $request): bool {
	$appManager = Server::get(OCP\App\IAppManager::class);
	if (!$request->getHeader('AE-SIGNATURE')) {
		return false;
	}
	if (!$appManager->isAppLoaded('app_ecosystem_v2')) {
		return false;
	}
	if (!$appManager->isInstalled('app_ecosystem_v2')) {
		return false;
	}
	$appEcosystemV2Service = Server::get(OCA\AppEcosystemV2\Service\AppEcosystemV2Service::class);
	return $appEcosystemV2Service->validateExAppRequestToNC($request);
}
```

## 🔧 Configuration

### Admin settings

In Admin section you can configure existing external apps.

## 🛠️ State of maintenance

While there are some things that could be done to further improve this app, the app is currently maintained with **limited effort**. This means:

* The main functionality works for the majority of the use cases
* We will ensure that the app will continue to work like this for future releases and we will fix bugs that we classify as 'critical'
* We will not invest further development resources ourselves in advancing the app with new features
* We do review and enthusiastically welcome community PR's

We would be more than excited if you would like to collaborate with us. We will merge pull requests for new features and fixes. We also would love to welcome co-maintainers.

If you are a customer of Nextcloud and you have a strong business case for any development of this app, we will consider your wishes for our roadmap. Please contact your account manager to talk about the possibilities.
