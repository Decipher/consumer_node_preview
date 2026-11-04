# Registering targets over the API

A frontend can register itself, so nobody has to add it to the consumer by hand.

```text
PATCH /api/consumer-node-preview/targets?consumer=<uuid>
Content-Type: application/json

{
  "target": {"label": "Local", "url": "http://localhost:3000/node/preview?vm=[view_mode]&layout=[layout]#[jsonapi_node_preview]"},
  "layouts": ["default", "preview"]
}
```

| Method | What it does |
| --- | --- |
| `GET` | Reads the targets and layouts |
| `PUT` | Replaces all targets. Sends `"targets": [...]` |
| `PATCH` | Replaces the target with that label in place, or adds it at the end |

`layouts` is optional. All three answer with `consumer`, `targets` and
`layouts`. A bad payload gets a 422 with a `message`.

## Naming the consumer

Use `?consumer=<uuid>`, or the client ID in an `X-Consumer-ID` header or
`consumerId` query parameter. A request that doesn't name a known consumer gets
a 404. It never falls back to the default consumer.

## Access

The user needs the `register consumer node preview targets` permission, and must
own the consumer or have `administer consumer entities`. Otherwise it's a 403.
`X-Consumer-ID` never grants access.

Use any authentication your site has enabled, such as a Simple OAuth token. With
a session cookie, `PUT` and `PATCH` also need `X-CSRF-Token` from
`/session/token`. From a browser on another origin, add the method to the CORS
`allowedMethods`.

## Expiry

Send the `PATCH` again every few hours while the frontend runs, with the same
label.

After a day without one, the target moves to **Inactive targets** at the bottom
of the Frontend picker. You can still pick it, and the next `PATCH` moves it
back. After 30 days, cron deletes it.

Change the limits on the **Preview** tab at **Administration > Configuration >
Web services > Consumers**, or pin them in settings.php, in seconds:

```php
$config['consumer_node_preview.settings']['inactive_after'] = 86400;
$config['consumer_node_preview.settings']['delete_after'] = 2592000;
```

Settings targets never expire, and neither do targets added on the consumer
form. The exception is a `PATCH` with the same label as a form target, which
takes it over. A `PUT` or form save keeps the time if the label and URL don't
change. A new label or URL clears it, so that target no longer expires.

## Linking to a target

Add these to Drupal's preview URL:

- `?frontend=<key>`: `settings:<name>`, or `<consumer id>:<delta>`. The delta is
  the target's position, so it shifts if an earlier target goes.
- `?layout=<name>`: one of that target's layouts.
