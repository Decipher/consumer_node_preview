/**
 * @file
 * Tabs, view mode, frontend targets, layouts, widths and the JSON:API
 * document on the preview. It also checks which frontends answer.
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

  // How long a frontend has to answer the check, in milliseconds.
  const CHECK_TIMEOUT = 3000;

  /**
   * Tells whether a frontend's origin answers.
   *
   * The request is "no-cors", so any response counts, even an opaque one.
   * Only a network error or no answer in time counts as silence.
   *
   * @param {string} origin
   *   The origin to ask.
   *
   * @return {Promise<boolean>}
   *   Whether it answered.
   */
  async function answers(origin) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), CHECK_TIMEOUT);
    try {
      await fetch(`${origin}/`, {
        mode: 'no-cors',
        cache: 'no-store',
        credentials: 'omit',
        signal: controller.signal,
      });
      return true;
    } catch {
      return false;
    } finally {
      clearTimeout(timer);
    }
  }

  /**
   * Marks the picker's targets whose frontend does not answer.
   *
   * Each origin is asked once. A path, or a URL on Drupal's own origin, is
   * skipped, because the page itself came from there. An inactive target is
   * skipped too. It has not registered for a while, and the check must not
   * wake a sleeping frontend. A marked target can still be picked, as picking
   * it may wake a sleeping frontend.
   *
   * @param {HTMLSelectElement} picker
   *   The Frontend picker.
   * @param {object} targetSettings
   *   The targets, keyed by target key, each with a url.
   */
  function checkFrontends(picker, targetSettings) {
    const origins = new Map();
    Array.from(picker.options).forEach((option) => {
      const target = targetSettings[option.value];
      if (!target || target.inactive) {
        return;
      }
      let origin;
      try {
        ({ origin } = new URL(target.url, window.location.href));
      } catch {
        return;
      }
      if (origin === window.location.origin || origin === 'null') {
        return;
      }
      if (!origins.has(origin)) {
        origins.set(origin, []);
      }
      origins.get(origin).push(option);
    });
    origins.forEach((options, origin) => {
      answers(origin).then((answered) => {
        if (answered) {
          return;
        }
        options.forEach((option) => {
          option.setAttribute('data-consumer-node-preview-not-responding', '');
          option.textContent = Drupal.t('@label (not responding)', {
            '@label': option.textContent,
          });
        });
      });
    });
  }

  /**
   * Runs a callback once the page and its frame have loaded.
   *
   * @param {Function} callback
   *   The callback.
   */
  const whenSettled = (callback) => {
    if (document.readyState === 'complete') {
      setTimeout(callback, 0);
    } else {
      window.addEventListener('load', () => setTimeout(callback, 0), {
        once: true,
      });
    }
  };

  /**
   * Sets up one preview page.
   *
   * @param {HTMLElement} preview
   *   The preview's wrapper.
   */
  function init(preview) {
    const settings = drupalSettings.consumerNodePreview || {};
    const targetSettings = settings.targets || {};
    const fill = (template, viewMode, layout = '') =>
      template
        .split(settings.placeholder)
        .join(encodeURIComponent(viewMode))
        .split(settings.layoutPlaceholder)
        .join(encodeURIComponent(layout));
    let viewMode = settings.viewMode || 'full';
    let layout = settings.layout || '';

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

    // Switching frontend, layout or view mode reloads only the frame and the
    // new tab link. A target or layout in the URL wins over the remembered
    // one. Each target remembers its own layout.
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
    const layouts = preview.querySelector(
      '[data-consumer-node-preview-layout]',
    );
    const query = new URLSearchParams(window.location.search);
    // A layout in the URL counts once, for the target the page opens on,
    // even when that is a remembered one. An unlisted one is ignored.
    let requestedLayout = query.get('layout');
    let { active } = settings;

    // The controller lists layouts, maybe none, for every target.
    const layoutsOf = (targetKey) => targetSettings[targetKey].layouts;
    const pickLayout = (targetKey, candidates) => {
      const names = layoutsOf(targetKey).map((each) => each.name);
      return candidates.find((name) => names.includes(name)) || names[0] || '';
    };
    // The picker lists the framed target's layouts, and hides without any.
    const listLayouts = () => {
      if (!layouts) {
        return;
      }
      const options = layoutsOf(active);
      layouts.replaceChildren(
        ...options.map(
          (each) =>
            new Option(each.label, each.name, false, each.name === layout),
        ),
      );
      (layouts.closest('.js-form-item') || layouts.parentElement).hidden =
        !options.length;
    };

    const show = (targetKey) => {
      if (!frame || !targetSettings[targetKey]) {
        return;
      }
      if (targetKey !== active) {
        active = targetKey;
        layout = pickLayout(active, [
          requestedLayout,
          recall(`layout.${active}`),
        ]);
        listLayouts();
      }
      const url = fill(targetSettings[targetKey].url, viewMode, layout);
      load(url);
      open.setAttribute('href', url);
    };
    if (targets) {
      targets.addEventListener('change', () => {
        show(targets.value);
        remember('target', targets.value);
      });
      const remembered = recall('target');
      if (!query.has('frontend') && remembered && targetSettings[remembered]) {
        targets.value = remembered;
        show(remembered);
      }
      // The check never holds up the framed preview.
      whenSettled(() => checkFrontends(targets, targetSettings));
    }
    if (layouts) {
      layouts.addEventListener('change', () => {
        layout = layouts.value;
        remember(`layout.${active}`, layout);
        show(active);
      });
      layout = pickLayout(active, [
        requestedLayout,
        recall(`layout.${active}`),
        layout,
      ]);
      listLayouts();
      show(active);
    }
    requestedLayout = null;

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
