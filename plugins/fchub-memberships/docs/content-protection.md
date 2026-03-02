# Content Protection

This is how you actually block access to content for non-members. The plugin hooks into WordPress's content rendering to check permissions before showing anything.

## Protection Modes

When a non-member tries to access protected content, you have three options:

### Redirect
Send them to a URL of your choice (login page, sales page, etc.).

### Teaser
Show the post excerpt but hide the full content. Great for giving a taste of what's inside.

### Hidden
Remove the content from queries entirely. The post won't show up in archives, search results, or navigation. It's like it doesn't exist for non-members.

## What Gets Protected

### Posts & Pages
Any WordPress post, page, or custom post type can be protected. The plugin hooks into `the_content` and `template_include` to intercept rendering.

### Taxonomy Terms
Categories, tags, and custom taxonomy terms can be restricted at the admin level.

### Third-Party Content
Through adapters, the system also protects:
- **LearnDash courses and lessons** — members get enrolled/unenrolled automatically
- **FluentCommunity spaces** — members get added/removed from community spaces

## How Access Is Evaluated

When someone visits a protected page, the `AccessEvaluator` runs through this checklist:

1. **Admin?** → bypass, allow access
2. **Membership paused?** → deny
3. **Direct grant exists?** → check if it's active and not drip-locked
4. **Plan-based grant?** → check if any active plan includes this resource
5. **Trial status?** → check if trial is still valid
6. **Drip locked?** → check if the drip unlock date has passed

The result is cached per-request so it doesn't hit the database repeatedly for the same check.

## Setting Up Protection

1. Go to **FCHub Memberships > Content**
2. Add protection rules for specific posts, pages, or post types
3. Choose the protection mode (redirect, teaser, or hidden)
4. The rules link to plans — only members of those plans can access the content

## Important Notes

- Protection rules are separate from plan content rules. Plan rules define what a plan *grants*. Protection rules define what *requires* a plan.
- A single piece of content can be included in multiple plans
- Admin users always bypass protection
- The access check is fast — results are cached within each page load
