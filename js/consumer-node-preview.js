/**
 * @file
 * Tabs, view mode, frontend targets, widths and the JSON:API document on the
 * preview.
 */

/**
 * @param {object} Drupal
 *   The Drupal object.
 * @param {object} drupalSettings
 *   The page's settings.
 * @param {Function} once
 *   Core's once(), which runs code once per element.
 */
((Drupal, drupalSettings, once) => {
  const key = (name) => `consumer_node_preview.${name}`;

  // Storage can be blocked. The page works without it.
  const recall = (name) => {
    try {
      return window.localStorage.getItem(key(name));
    } catch {
      return null;
    }
  };
  const remember = (name, value) => {
    try {
      window.localStorage.setItem(key(name), value);
    } catch {
      // Not remembered.
    }
  };

  /**
   * Fetches the preview document with the editor's session and prints it.
   *
   * @param {HTMLElement} output
   *   The element that holds the document.
   */
  async function loadDocument(output) {
    const code = output.querySelector('code');
    try {
      const response = await fetch(output.dataset.consumerNodePreviewJsonapi, {
        credentials: 'same-origin',
        headers: { Accept: 'application/vnd.api+json' },
      });
      const text = await response.text();
      let body = text;
      try {
        body = JSON.stringify(JSON.parse(text), null, 2);
      } catch {
        // Not JSON. It is shown as it came.
      }
      code.textContent = response.ok
        ? body
        : `${response.status} ${response.statusText}\n\n${body}`;
    } catch (error) {
      code.textContent = Drupal.t(
        'The document could not be loaded: @message',
        {
          '@message': error.message,
        },
      );
    }
  }

  /**
   * Sets up one preview page.
   *
   * @param {HTMLElement} preview
   *   The preview's wrapper.
   */
  function init(preview) {
    const settings = drupalSettings.consumerNodePreview || {};
    const targetSettings = settings.targets || {};
    const fill = (template, viewMode) =>
      template.split(settings.placeholder).join(encodeURIComponent(viewMode));
    let viewMode = settings.viewMode || 'full';

    const tabs = Array.from(preview.querySelectorAll('[role="tab"]'));
    const output = preview.querySelector(
      '[data-consumer-node-preview-jsonapi]',
    );
    const drupal = preview.querySelector('[data-consumer-node-preview-drupal]');
    let loaded = false;
    let drupalStale = false;
    let drupalLoading = false;

    // The Drupal tab follows the view mode once it is shown. One request runs
    // at a time, and asks again if the view mode moved on meanwhile. A failed
    // request leaves the tab stale, so showing the tab again retries.
    const refreshDrupal = () => {
      if (
        drupalLoading ||
        !drupalStale ||
        !settings.drupalUrl ||
        drupal.closest('[role="tabpanel"]').hidden
      ) {
        return;
      }
      const requested = viewMode;
      drupalLoading = true;
      Drupal.ajax({
        url: fill(settings.drupalUrl, requested),
        progress: { type: 'fullscreen' },
      })
        .execute()
        .then(
          () => {
            drupalLoading = false;
            drupalStale = requested !== viewMode;
            refreshDrupal();
          },
          () => {
            drupalLoading = false;
          },
        );
    };

    // Only a tab the editor picks is remembered, not the page's default.
    const select = (tab, picked = true) => {
      tabs.forEach((each) => {
        const selected = each === tab;
        each.setAttribute('aria-selected', selected ? 'true' : 'false');
        each.tabIndex = selected ? 0 : -1;
        each.classList.toggle('is-active', selected);
        each.parentElement.classList.toggle('is-active', selected);
        document.getElementById(each.getAttribute('aria-controls')).hidden =
          !selected;
      });
      if (picked) {
        remember('tab', tab.dataset.consumerNodePreviewTab);
      }
      if (output && !loaded && !output.closest('[role="tabpanel"]').hidden) {
        loaded = true;
        loadDocument(output);
      }
      refreshDrupal();
    };

    tabs.forEach((tab, index) => {
      tab.addEventListener('click', (event) => {
        event.preventDefault();
        select(tab);
      });
      // Arrow keys, Home and End move between tabs, as ARIA tabs expect.
      tab.addEventListener('keydown', (event) => {
        const next = {
          ArrowLeft: index - 1,
          ArrowRight: index + 1,
          Home: 0,
          End: tabs.length - 1,
        }[event.key];
        if (next === undefined) {
          return;
        }
        event.preventDefault();
        const target = tabs[(next + tabs.length) % tabs.length];
        select(target);
        target.focus();
      });
    });

    select(
      tabs.find(
        (tab) => tab.dataset.consumerNodePreviewTab === recall('tab'),
      ) ||
        tabs.find((tab) => tab.getAttribute('aria-selected') === 'true') ||
        tabs[0],
      false,
    );

    // Switching frontend or view mode reloads only the frame and the new tab
    // link. A target in the URL wins over the remembered one.
    let frame = preview.querySelector('[data-consumer-node-preview-frame]');
    // A new frame loads the URL without a history entry. So the browser's
    // Back button leaves the preview, instead of stepping back through the
    // frame while the controls stay as they are.
    const load = (url) => {
      if (frame.getAttribute('src') === url) {
        return;
      }
      const next = frame.cloneNode(false);
      next.setAttribute('src', url);
      frame.replaceWith(next);
      frame = next;
    };
    const open = preview.querySelector('[data-consumer-node-preview-open]');
    const targets = preview.querySelector(
      '[data-consumer-node-preview-target]',
    );
    let { active } = settings;
    const show = (targetKey) => {
      if (!frame || !targetSettings[targetKey]) {
        return;
      }
      active = targetKey;
      const url = fill(targetSettings[targetKey].url, viewMode);
      load(url);
      open.setAttribute('href', url);
    };
    if (targets) {
      targets.addEventListener('change', () => {
        show(targets.value);
        remember('target', targets.value);
      });
      const requested = new URLSearchParams(window.location.search).has(
        'frontend',
      );
      const remembered = recall('target');
      if (!requested && remembered && targetSettings[remembered]) {
        targets.value = remembered;
        show(remembered);
      }
    }

    // Core's View mode select, without core's page reload.
    const viewModes = preview.querySelector(
      '[data-consumer-node-preview-view-mode]',
    );
    if (viewModes) {
      viewModes.addEventListener('change', () => {
        viewMode = viewModes.value;
        if (settings.previewUrl) {
          window.history.replaceState(
            window.history.state,
            '',
            fill(settings.previewUrl, viewMode) + window.location.search,
          );
        }
        show(active);
        drupalStale = true;
        refreshDrupal();
      });
    }

    const viewport = preview.querySelector(
      '[data-consumer-node-preview-viewport]',
    );
    const widths = Array.from(
      preview.querySelectorAll('[data-consumer-node-preview-width]'),
    );
    const resize = (button) => {
      widths.forEach((each) => {
        const pressed = each === button;
        each.setAttribute('aria-pressed', pressed ? 'true' : 'false');
        each.classList.toggle('button--primary', pressed);
      });
      viewport.style.setProperty(
        '--consumer-node-preview-width',
        button.dataset.consumerNodePreviewWidth,
      );
      remember('width', button.dataset.consumerNodePreviewWidth);
    };
    widths.forEach((button) =>
      button.addEventListener('click', () => resize(button)),
    );
    const width = widths.find(
      (button) => button.dataset.consumerNodePreviewWidth === recall('width'),
    );
    if (width) {
      resize(width);
    }

    // A new tab keeps the preview, so core's leave-preview dialog is skipped.
    preview.querySelectorAll('a[target="_blank"]').forEach((link) => {
      link.addEventListener('click', (event) => event.stopPropagation());
    });
  }

  /**
   * Attaches the tabbed node preview.
   */
  Drupal.behaviors.consumerNodePreview = {
    attach(context) {
      once(
        'consumer-node-preview',
        '[data-consumer-node-preview]',
        context,
      ).forEach(init);
    },
  };
})(Drupal, drupalSettings, once);
