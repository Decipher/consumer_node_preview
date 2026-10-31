# Changelog

This file records the changes in each release of Consumer Node Preview.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Added

- Preview targets on consumers: a label and a URL for each frontend that
  renders the consumer's node previews, edited on the consumer form.
- Preview layouts on consumers: the layouts a frontend can render a preview
  in, shown in a Layout select on the preview screen.
- Site targets in settings.php, for URLs that differ per environment, with an
  optional default target.
- A tabbed preview screen. Core's Preview button opens a Frontend tab that
  frames the unsaved node in a frontend, a Drupal tab with core's own preview,
  and a JSON:API tab with the document the frontend reads.
- A Frontend picker, Phone, Tablet and Full widths, and a link that opens the
  preview in a new tab. The screen remembers the editor's last tab, width,
  frontend and each frontend's layout.
- The Frontend picker groups targets by consumer. Each consumer gets a group
  with its label, and the settings.php targets come first in a Site group.
  The target keys do not change.
- Core's View mode select in the tab row. Changing the view mode reloads only
  the frame, and the Drupal tab follows over AJAX.
- `?frontend=<key>` and `?layout=<name>` query parameters, which pick a target
  and one of its layouts.
- The URL tokens `[jsonapi_node_preview]`, `[jsonapi_node_preview_path]`,
  `[uuid]`, `[view_mode]` and `[layout]`.
- Paragraphs in the preview document. Entity reference revisions fields are
  included, with the fields inside them up to three levels deep. A frontend
  gets those unsaved values in one request.
- An endpoint where a frontend reads and replaces its consumer's targets and
  layouts, with the `register consumer node preview targets` permission.
- A PATCH on the targets endpoint that registers one target. It replaces the
  target with the same label, or adds it, and records when it was seen. The
  other targets do not change.
- Expiry for registered targets. A target that its frontend has not
  registered for a day moves to an Inactive group in the Frontend picker,
  where it can still be picked. Cron deletes it after 30 days. The
  `inactive_after` and `delete_after` settings change the two limits. Targets
  added by hand never expire.
- A check of each frontend from the editor's browser when the preview opens.
  A frontend that does not answer in 3 seconds shows "(not responding)" in
  the Frontend picker, and can still be picked. Inactive targets are not
  checked.
- Admin theme support. The preview uses the admin theme when "Use the
  administration theme when editing or creating content" is on, and takes the
  theme's own tab styles in Claro, Gin and Olivero.
