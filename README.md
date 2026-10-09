# Consumer Node Preview

Lets each consumer register the frontend URLs that render node previews, and
points the node preview screen at the frontend an editor picks.

Built for decoupled sites that serve unsaved previews through
[JSON:API Node Preview](https://www.drupal.org/project/jsonapi_node_preview),
with consumers from the
[Consumers](https://www.drupal.org/project/consumers) module.

- Project page: <https://www.drupal.org/project/consumer_node_preview>
- Issue queue: <https://www.drupal.org/project/issues/consumer_node_preview>

## Requirements

- [Consumers](https://www.drupal.org/project/consumers)
- [JSON:API Node Preview](https://www.drupal.org/project/jsonapi_node_preview)
- Drupal core JSON:API and Node modules

JSON:API Node Preview 1.0.0-beta5 and earlier serve stale or missing data for
edited and never saved paragraphs, and its resolver is disabled entirely on
sites that also run jsonapi_resources (any jsonapi_views site). Use a version
with the include resolver fixes from
[#3367724](https://git.drupalcode.org/project/jsonapi_node_preview/-/issues/3367724).

## Installation

Install as you would normally install a contributed Drupal module. See the
[Drupal documentation](https://www.drupal.org/docs/extending-drupal/installing-modules)
for further information.

## Features

- **A tabbed preview screen.** Core's Preview button opens three tabs:
  - **Frontend** frames the unsaved node in a frontend, with Phone, Tablet
    and Full widths, a picker when there is more than one target, and an
    "Open in new tab" link. It fills the space below the admin toolbar.
  - **Drupal** shows core's own preview.
  - **JSON:API** shows the preview document the frontend reads.

  The screen remembers the editor's last tab, width and frontend. The preview
  route becomes a node operation, so it uses the admin theme when "Use the
  administration theme when editing or creating content" is on.
- **Targets per consumer.** Every consumer gets a **Preview targets** field:
  a list of label and URL pairs, for example local, staging and production.
  A frontend can also register its own targets over the API (see below).
- **Targets per environment.** settings.php can list targets too, which suits
  a URL that differs per environment and should not travel with a database
  copy:

  ```php
  $settings['consumer_node_preview'] = [
    'targets' => [
      'frontend' => [
        'label' => 'Frontend',
        'url' => 'https://www.example.com/node/preview?vm=[view_mode]#[jsonapi_node_preview_path]',
      ],
    ],
    // Optional. Else the first target opens.
    'default' => 'settings:frontend',
  ];
  ```

  Site targets come first in the picker. A `?frontend=<key>` query parameter
  on the preview URL picks a target, where the key is `settings:<name>` or
  `<consumer id>:<delta>`.

## Target URLs

A target URL is a template. It can use these tokens:

- `[jsonapi_node_preview]`: the absolute URL of the node's JSON:API preview
  document.
- `[jsonapi_node_preview_path]`: the same, as a path from the site root. Use
  it when the frontend reaches Drupal on its own origin, through a proxy, so
  the request needs no CORS.
- `[uuid]`: the node's UUID, which keys its preview.
- `[view_mode]`: the view mode being previewed.

The preview document includes the node's entity reference revisions fields,
such as paragraphs, so a frontend gets their unsaved values in one request.

Put the document URL in the fragment, so it never reaches the frontend's
server logs:

```
http://localhost:3000/node/preview?vm=[view_mode]#[jsonapi_node_preview]
```

Frontend implementers: read the raw `location.hash`, decode it, then repair
the scheme. Routers rewrite the URL and can collapse the double slash, so
`#http://…` arrives as `#http:/…`. Apply
`replace(/^(https?):\/(?!\/)/, '$1://')` after decoding.

## Registering targets over the API

Targets can be managed on the consumer form, or replaced over HTTP:

```
GET /api/consumer-node-preview/targets?consumer=<uuid>
PUT /api/consumer-node-preview/targets?consumer=<uuid>
Content-Type: application/json

{"targets": [
  {"label": "Local", "url": "http://localhost:3000/node/preview/[view_mode]#[jsonapi_node_preview]"}
]}
```

Without the `consumer` parameter the consumer is negotiated from the request
by the Consumers module, for example from the `X-Consumer-ID` header.

Access needs all of:

- the `register consumer node preview targets` permission, and
- ownership of the consumer, or the `administer consumer entities`
  permission.

The `X-Consumer-ID` header only selects a consumer. It never grants access,
because any client can send any value in it. This endpoint exists so a
frontend never needs entity update access to its consumer: a consumer update
permission would also cover the client id, the secret and fields other
modules attach, which is far more than URL registration should touch.

Cookie authenticated PUT requests must send the `X-CSRF-Token` header from
`/session/token`.

## CORS

The frontend fetches the preview endpoint with the editor's session. The
site's `services.yml` must allow that origin with credentials:

```yaml
cors.config:
  enabled: true
  allowedHeaders: ['*']
  allowedMethods: ['GET']
  allowedOrigins: ['http://localhost:3000']
  supportsCredentials: true
```

Never use a wildcard origin together with `supportsCredentials`.

## Maintainers

- Stuart Clark (Deciphered) - <https://www.drupal.org/u/deciphered>

---

Repository initiated with
[drupal_extension_scaffold](https://github.com/AlexSkrypnyk/drupal_extension_scaffold).
