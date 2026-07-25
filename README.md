# UserImpact

A MediaWiki extension that shows a user their own edit and article-view statistics on their user page, similar to Wikipedia's "Your impact" homepage module.

## How it works

When a logged-in user views their own user page, a panel appears showing their total edits, when they last edited, their longest daily edit streak, and a 60-day activity chart. If article view tracking is configured, it also shows how many total views the articles they've edited have received, and which of their edited articles are the most viewed.

The panel only shows on a user's own page.

## Requirements

- [Extension:PageViewInfo](https://www.mediawiki.org/wiki/Extension:PageViewInfo)
- A `PageViewService` implementation, for example [Extension:Plausible](https://github.com/FULU-Foundation/mediawiki-extension-Plausible), configured with a working API key. Without one, the edit-stats half of the panel still works; the view-stats half is omitted.

## Settings

- `$wgUserImpactEnabled`: master switch for the whole extension. Defaults to `true`.
- `$wgUserImpactDefaultShow`: default value of the per-user "show my impact stats" preference, for users who haven't set it themselves. Defaults to `false` (opt-in).
- `$wgUserImpactPreferencesHeading`: overrides the heading text for the preferences section containing the toggle. Defaults to `""`, which shows "Your impact".

Each user can also opt in or out individually via Special:Preferences.

## Structure

```
UserImpact/
├── extension.json
├── includes/
│   ├── Hooks.php
│   └── ImpactStats.php
├── resources/
│   └── ext.userimpact.styles.less
├── i18n/
│   ├── en.json
│   └── qqq.json
├── LICENSE
└── README.md
```

## License

MIT
