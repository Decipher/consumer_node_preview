/**
 * @file
 * Tabs, frontend targets, widths and the JSON:API document on the preview.
 */
((Drupal, once) => {
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
    const tabs = Array.from(preview.querySelectorAll('[role="tab"]'));
    const output = preview.querySelector(
      '[data-consumer-node-preview-jsonapi]',
    );
    let loaded = false;

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

    // Switching frontend reloads the frame and the new tab link. A target in
    // the URL wins over the remembered one.
    const frame = preview.querySelector('[data-consumer-node-preview-frame]');
    const open = preview.querySelector('[data-consumer-node-preview-open]');
    const targets = preview.querySelector(
      '[data-consumer-node-preview-target]',
    );
    if (targets) {
      const show = (option) => {
        option.selected = true;
        if (frame.getAttribute('src') !== option.value) {
          frame.setAttribute('src', option.value);
        }
        open.setAttribute('href', option.value);
      };
      targets.addEventListener('change', () => {
        const option = targets.selectedOptions[0];
        show(option);
        remember('target', option.dataset.key);
      });
      const requested = new URLSearchParams(window.location.search).has(
        'frontend',
      );
      const remembered = Array.from(targets.options).find(
        (option) => option.dataset.key === recall('target'),
      );
      if (!requested && remembered) {
        show(remembered);
      }
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
   *
   * @type {Drupal~behavior}
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
})(Drupal, once);
