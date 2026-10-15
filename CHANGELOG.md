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
- Admin theme support. The preview uses the admin theme when "Use the
  administration theme when editing or creating content" is on, and takes the
  theme's own tab styles in Claro, Gin and Olivero.
