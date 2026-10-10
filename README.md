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
sites that also run jsonapi_resources (any jsonapi_views site). Until a
release has them, apply the include resolver fixes from
https://www.drupal.org/i/3367724.

## Installation

Install as you would normally install a contributed Drupal module. See the
[Drupal documentation](https://www.drupal.org/docs/extending-drupal/installing-modules)
for further information.

## Features

- Every consumer gets a **Preview targets** field: a list of label and URL
  pairs. One consumer can hold several targets, for example local, staging
  and production. A target URL must be absolute and use http or https. The
  consumer form and the API check the same rule.
- A URL is a template. It can use two tokens:
  - `[jsonapi_node_preview]`: the absolute URL of the JSON:API preview
    endpoint for the previewed node.
  - `[view_mode]`: the view mode of the preview.
- The node preview form gets a **Frontend** select. When the editor picks a
  target, the preview page renders that URL in an iframe. The Drupal option
  keeps the core preview.

A typical target URL puts the endpoint in the fragment, so the preview URL
never reaches the frontend's server logs:

```
http://localhost:3000/node/preview/[view_mode]#[jsonapi_node_preview]
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

The endpoint takes the site's global authentication providers: the session
cookie, and an OAuth bearer token when Simple OAuth is installed. HTTP Basic
authentication is not global in core, so it does not work here.

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
