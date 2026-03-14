# Changelog

## 1.1.0

This release is what happens when a plugin stops pretending one 700-line file is a personality.

- rebuilt a big part of the plugin structure so the code is cleaner, smaller, and far less cursed to work on
- split the admin side into more focused pieces, so plans, members, reports, settings, and content tools are easier to follow and less of a maze
- cleaned up the plan flow, member flow, and subscription flow so the moving parts are separated properly instead of living in one enormous “good luck” service
- improved internal reliability around grants, renewals, pauses, cancellations, and scheduled changes
- made the dashboard and admin navigation feel more consistent, including fixing small UI annoyances like the “Create New Plan” button pointing to the wrong place
- cut the initial admin bundle size down dramatically by stopping the app from loading the whole UI library like it was trying to impress someone
- added a proper local development setup for tests and admin builds, because mystery dependencies in random folders are not a strategy
- added and expanded automated tests, including bug-focused and edge-case checks, so future changes are less likely to set the plugin on fire
- cleaned up packaging and repo hygiene so fewer useless generated files end up hanging around where they do not belong
- updated the readme and added a real changelog, which is frankly the least dramatic improvement here, but still nice

In short: same plugin, much better manners.
