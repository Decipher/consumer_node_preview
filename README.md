# Consumer Node Preview

[![Pipeline](https://git.drupalcode.org/project/consumer_node_preview/badges/1.0.x/pipeline.svg)](https://git.drupalcode.org/project/consumer_node_preview/-/pipelines)
[![Test](https://github.com/Decipher/consumer_node_preview/actions/workflows/test.yml/badge.svg?branch=1.0.x)](https://github.com/Decipher/consumer_node_preview/actions/workflows/test.yml?query=branch%3A1.0.x)
[![Coverage](https://codecov.io/gh/Decipher/consumer_node_preview/branch/1.0.x/graph/badge.svg)](https://codecov.io/gh/Decipher/consumer_node_preview/branch/1.0.x)

Shows unsaved content in your decoupled frontends from Drupal's Preview button.
Each consumer lists the frontend URLs that render its previews, beside
Drupal's own preview and the JSON:API document.

![Drupal's preview screen with Frontend, Drupal and JSON:API tabs. The Frontend tab frames the unsaved page in the decoupled site, below the Frontend, Layout and View mode selects and the Phone, Tablet and Full width buttons.](docs/images/preview-screen.png)

For a full description of the module, visit the
[project page](https://www.drupal.org/project/consumer_node_preview).

Submit bug reports and feature suggestions, or track changes in the
[issue queue](https://www.drupal.org/project/issues/consumer_node_preview).

## Table of contents

- Requirements
- Installation
- Configuration
- Target URLs
- Registering targets over the API
- Troubleshooting
- Maintainers

## Requirements

- Drupal 10.3 or later, or Drupal 11
- [Consumers](https://www.drupal.org/project/consumers)
- [JSON:API Node Preview](https://www.drupal.org/project/jsonapi_node_preview)
- JSON:API and Node (Drupal core)

JSON:API Node Preview 1.0.0-beta5 and earlier serve stale or missing
paragraphs, and turn their resolver off on sites with JSON:API Resources.
Until a release includes them, apply the fixes from
https://www.drupal.org/i/3367724.

## Installation

1. Download and install via Composer. JSON:API Node Preview is in beta, so
   require it with the module:

   ```bash
   composer require drupal/jsonapi_node_preview:^1.0@beta drupal/consumer_node_preview
   ```

2. Enable the module:

   ```bash
   drush en consumer_node_preview
   ```

## Configuration

1. Edit a consumer at **Administration > Configuration > Web services >
   Consumers**. Add its **Preview targets**: a label and a URL for each
   frontend, such as local, staging and production. **Preview layouts** lists
   the layouts the frontend can render a preview in. The first is the default.
2. Allow each frontend's origin in the site's `services.yml`. The frontend
   fetches the preview with the editor's session, so it needs credentials:

   ```yaml
   cors.config:
     enabled: true
     allowedHeaders: ['*']
     allowedMethods: ['GET']
     allowedOrigins: ['http://localhost:3000']
     supportsCredentials: true
   ```

   Never use a wildcard origin with `supportsCredentials`.
3. Edit a node, change something, and press **Preview**.

Targets that differ per environment can live in settings.php. They come
first in the Frontend picker:

```php
$settings['consumer_node_preview'] = [
  'targets' => [
    'frontend' => [
      'label' => 'Frontend',
      'url' => 'https://www.example.com/node/preview?vm=[view_mode]&layout=[layout]#[jsonapi_node_preview_path]',
      // Optional. The layouts this frontend can render a preview in.
      'layouts' => ['default', 'preview'],
    ],
  ],
  // Optional. Else the first target opens.
  'default' => 'settings:frontend',
];
```

A settings target can also be a path from the site root, such as
`/node/preview`. That suits a frontend that Drupal's own host serves.

## Target URLs

A target URL is a template that can use these tokens:

- `[jsonapi_node_preview]`: the absolute URL of the node's JSON:API preview
  document.
- `[jsonapi_node_preview_path]`: the same, as a path, for a frontend that
  reaches Drupal through a proxy on its own origin.
- `[uuid]`: the node's UUID.
- `[view_mode]`: the view mode being previewed.
- `[layout]`: the layout picked in the Layout select, or nothing.

A good target URL looks like this:

```text
https://www.example.com/node/preview?vm=[view_mode]#[jsonapi_node_preview]
```

- The document URL goes in the fragment, after `#`. Browsers do not send the
  fragment to the server, so the URL stays out of the frontend's server logs.
- `[view_mode]` goes in the path or the query. A new view mode then changes
  the frame's URL, and the frame reloads.

### Reading the document URL

The frontend's preview page gets the document URL from the fragment:

```js
const url = decodeURIComponent(location.hash.slice(1))
  // Some routers change "https://" to "https:/". Put the slash back.
  .replace(/^(https?):\/(?!\/)/, '$1://')
const response = await fetch(url, { credentials: 'include' })
```

The browser can encode characters in the fragment, so decode it first.
`credentials: 'include'` sends the editor's Drupal session cookie with the
request.

### Opening a target from a link

Query parameters on Drupal's preview URL pick what the Frontend tab shows:

- `?frontend=<key>` picks the target. The key is `settings:<name>` for a
  target in settings.php, or `<consumer id>:<delta>` for a consumer's target.
  `2:0` is the first target of consumer 2.
- `?layout=<name>` picks one of that target's layouts.

## Registering targets over the API

A frontend can read and replace its consumer's targets and layouts:

```text
GET /api/consumer-node-preview/targets?consumer=<uuid>
PUT /api/consumer-node-preview/targets?consumer=<uuid>
Content-Type: application/json

{
  "targets": [
    {"label": "Local", "url": "http://localhost:3000/node/preview?vm=[view_mode]#[jsonapi_node_preview]"}
  ],
  "layouts": ["default", "preview"]
}
```

`layouts` is optional. A PUT without it keeps the consumer's layouts. Instead
of `consumer`, the `X-Consumer-ID` header or a `consumerId` query parameter can
name the consumer by its client ID. A request that doesn't name a known
consumer gets a 404.

Access needs the `register consumer node preview targets` permission, and
ownership of the consumer or the `administer consumer entities` permission.
`X-Consumer-ID` only selects a consumer. It never grants access. A PUT with
the session cookie needs the `X-CSRF-Token` header from `/session/token`. A
PUT from a browser on another origin also needs `PUT` in the CORS
`allowedMethods`.

## Troubleshooting

### The Frontend tab shows an error instead of the draft

The editor's session cookie did not reach Drupal. Without it, the preview
document answers 404.

Drupal's session cookie is `SameSite=Lax`. Browsers send it only when the
frontend and Drupal share a site, such as `www.example.com` and
`cms.example.com`. Ports and subdomains can differ. A frontend on another
domain, such as `example.vercel.app`, does not get the cookie.

For a frontend on another domain:

- Give it a host name on the site's domain, such as `preview.example.com`.
- Or serve Drupal through a proxy on the frontend's origin, and use the
  `[jsonapi_node_preview_path]` token. The editor then signs in through that
  proxy, so the session cookie belongs to the frontend's host.

Also check that:

- the site's CORS settings allow the frontend's origin, with
  `supportsCredentials: true`;
- the preview page fetches with `credentials: 'include'`;
- the frontend lets Drupal show it in a frame. An `X-Frame-Options` header,
  or a `frame-ancestors` rule in its Content Security Policy, blocks the
  frame.

## Maintainers

- Stuart Clark - [deciphered](https://www.drupal.org/u/deciphered)
