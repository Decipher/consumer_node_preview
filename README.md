# Consumer Node Preview

[![Pipeline](https://git.drupalcode.org/project/consumer_node_preview/badges/1.0.x/pipeline.svg)](https://git.drupalcode.org/project/consumer_node_preview/-/pipelines)
[![Test](https://github.com/Decipher/consumer_node_preview/actions/workflows/test.yml/badge.svg?branch=1.0.x)](https://github.com/Decipher/consumer_node_preview/actions/workflows/test.yml?query=branch%3A1.0.x)
[![Coverage](https://codecov.io/gh/Decipher/consumer_node_preview/branch/1.0.x/graph/badge.svg)](https://codecov.io/gh/Decipher/consumer_node_preview/branch/1.0.x)

Preview unsaved content in your decoupled frontend from Drupal's Preview button.
Your frontend needs a preview page that fetches the draft. See "Target URLs".

![The preview screen, with the Frontend tab showing an unsaved page.](https://git.drupalcode.org/project/consumer_node_preview/-/raw/1.0.x/docs/images/preview-screen.png)

For a full description of the module, visit the
[project page](https://www.drupal.org/project/consumer_node_preview).

Submit bug reports and feature suggestions, or track changes in the
[issue queue](https://www.drupal.org/project/issues/consumer_node_preview).

## Table of contents

- Features
- Requirements
- Installation
- Configuration
- Target URLs
- Troubleshooting
- Maintainers

## Features

- Preview a draft in your frontend, beside Drupal's preview and the JSON:API
  document.
- Set frontends per consumer, or per environment in settings.php.
- Pick the view mode, layout and device width on the preview screen.
- See which frontends aren't responding.
- Frontends can [register themselves](https://git.drupalcode.org/project/consumer_node_preview/-/blob/1.0.x/docs/api.md), and drop off when they stop.

## Requirements

- Drupal 10.3 or later, or Drupal 11
- [Consumers](https://www.drupal.org/project/consumers)
- [JSON:API Node Preview](https://www.drupal.org/project/jsonapi_node_preview)
- JSON:API and Node (Drupal core)
- Optional: [Simple OAuth](https://www.drupal.org/project/simple_oauth) 6.1 or
  later, for a frontend on another site

JSON:API Node Preview 1.0.0-beta5 and earlier have bugs with paragraphs and with
JSON:API Resources. Until a release includes the fixes, apply them from
https://www.drupal.org/i/3367724.

## Installation

Install as you would normally install a contributed Drupal module. JSON:API Node
Preview is in beta, so require it too:

```bash
composer require drupal/jsonapi_node_preview:^1.0@beta drupal/consumer_node_preview
drush en consumer_node_preview
```

## Configuration

1. Edit a consumer at **Administration > Configuration > Web services >
   Consumers**. Under **Preview targets**, add a label and URL for each
   frontend.
2. Allow each frontend's origin in `sites/default/services.yml`, then rebuild
   caches. The frontend fetches with the editor's session, so CORS must allow
   credentials from that origin, not a wildcard:

   ```yaml
   parameters:
     cors.config:
       enabled: true
       allowedHeaders: ['*']
       allowedMethods: ['GET']
       allowedOrigins: ['http://localhost:3000']
       supportsCredentials: true
   ```

3. Edit a node, change something, and press **Preview**.

To offer layouts, list them under **Preview layouts** and put `[layout]` in the
target's URL.

Targets that differ per environment can go in settings.php. They come first in
the Frontend picker:

```php
$settings['consumer_node_preview'] = [
  'targets' => [
    'frontend' => [
      'label' => 'Frontend',
      'url' => 'https://www.example.com/node/preview?vm=[view_mode]#[jsonapi_node_preview]',
    ],
  ],
  // Opens first for an editor with no remembered pick.
  'default' => 'settings:frontend',
];
```

To limit the hosts a target can use, including targets registered over the
API, list them under **Allowed target hosts** on the **Preview** tab at
**Administration > Configuration > Web services > Consumers**. A `*.` pattern
matches any subdomain, such as `*.preview.example.com`. A saved target on a
host you take off the list leaves the Frontend picker. The tab also sets how
long a preview token lasts, and when registered targets expire. The preview
page makes its tokens when it opens, so an editor reloads the page once they
run out. To pin a value
per environment, override it in settings.php:

```php
$config['consumer_node_preview.settings']['allowed_target_hosts'] = [
  'www.example.com',
  '*.preview.example.com',
];
```

## Target URLs

A target URL can use these tokens:

| Token | Value |
| --- | --- |
| `[jsonapi_node_preview]` | The URL of the node's JSON:API preview document |
| `[jsonapi_node_preview_path]` | The same as a path, for a frontend that proxies Drupal |
| `[uuid]` | The node's UUID |
| `[view_mode]` | The view mode being previewed |
| `[layout]` | The layout picked, or nothing |
| `[preview_token]` | A short-lived Simple OAuth access token for the editor, for a frontend on another site |

Put the document URL in the fragment, so it stays out of the frontend's server
logs:

```text
https://www.example.com/node/preview?vm=[view_mode]#[jsonapi_node_preview]
```

The frontend's preview page reads it and fetches with the editor's session:

```js
const url = decodeURIComponent(location.hash.slice(1))
  // Some routers change "https://" to "https:/". Put the slash back.
  .replace(/^(https?):\/(?!\/)/, '$1://')
const response = await fetch(url, { credentials: 'include' })
```

### Previewing on another site

A frontend on another site never gets the editor's session cookie. This
includes a tunnel such as `*.trycloudflare.com`. With
[Simple OAuth](https://www.drupal.org/project/simple_oauth) installed, a
target can ask for a token instead. Put it after the document URL in the
fragment:

```text
https://preview.example.com/node/preview?vm=[view_mode]#[jsonapi_node_preview];token=[preview_token]
```

The token is a Simple OAuth access token for the editor, issued to the
target's consumer. It has the scopes the editor gets by signing in to that
consumer, so the consumer needs the Authorization Code grant. It lasts 15
minutes, unless you change that on the **Preview** tab. A target in
settings.php names its consumer by client ID:

```php
$settings['consumer_node_preview']['targets']['frontend']['consumer'] = 'my-frontend';
```

Without Simple OAuth, or for a consumer without the Authorization Code grant,
`[preview_token]` is empty.

The frontend splits the token off and sends it as a bearer token, without
credentials:

```js
const [url, token] = decodeURIComponent(location.hash.slice(1)).split(';token=')
const response = await fetch(url, {
  headers: { Authorization: `Bearer ${token}` },
  credentials: 'omit',
})
```

The frontend fetches without credentials, so CORS can allow any origin, which
suits tunnels whose host names change:

```yaml
parameters:
  cors.config:
    enabled: true
    allowedHeaders: ['*']
    allowedMethods: ['GET']
    allowedOrigins: ['*']
    supportsCredentials: false
```

## Troubleshooting

### Why does the Frontend tab show an error instead of the draft?

The editor's session cookie probably didn't reach Drupal. The cookie is
`SameSite=Lax`, so the frontend and Drupal must share a site, such as
`www.example.com` and `cms.example.com`. Ports and subdomains can differ.

For a frontend on another domain or a tunnel, give it a token. See "Previewing
on another site". Or proxy Drupal through it and use
`[jsonapi_node_preview_path]`.

Also check CORS, `credentials: 'include'`, and any `X-Frame-Options` or
`frame-ancestors` rule on the frontend.

### What does "(not responding)" mean?

The frontend didn't answer in time. You can still pick it, as opening it may
wake it up.

## Maintainers

- Stuart Clark - [deciphered](https://www.drupal.org/u/deciphered)
