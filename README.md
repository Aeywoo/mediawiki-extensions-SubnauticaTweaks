## SubnauticaTweaks
This extension handles site-wide interface messages, tweaks, and improvements on Subnautica wikis. It is a fork of Wikimedia's WikimediaMessages with increased functionality, handling server-farm wide modifications to the core MediaWiki software and this fork is based off [GloopTweaks](https://github.com/glooptweaks/mediawiki-extensions-GloopTweaks).

### Rights
* `editinterfacesite` - Allows the user to edit site-wide interface messages if `$wgSubnauticaTweaksProtectSiteInterface` is enabled

### Configuration settings
* `$wgSubnauticaTweaksProtectSiteInterface` - Protect site-wide interface messages from local edits
* `$wgSubnauticaTweaksSendDetailsWithContactPage` - Send additional info with Special:Contact messages (e.g IP and User-Agent)
* `$wgSubnauticaTweaksAddFooterLinks` - Add a link to our Terms of Use etc to the footer
* `$wgSubnauticaTweaksEnableMessageOverrides` - Enable overriding certain MediaWiki messages with our own
* `$wgSubnauticaTweaksRequireLicensesToUpload` - Enforces requiring MediaWiki:Licenses to not be blank for uploads to be enabled
* `$wgSubnauticaTweaksEnableSearchboxMetadata` - Add structured data to the main page to add a Google Sitelinks search box
* `$wgSubnauticaTweaksEnableTheming` - Enable loading themes when the theme cookie is set or the legacy darkmode cookie is true
* `$wgSubnauticaTweaksEnableLoadingFixedWidth` - Enable loading the wg.fixedwidth ResourceLoader module when the readermode cookie is true