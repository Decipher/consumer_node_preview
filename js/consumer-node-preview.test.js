/**
 * @file
 * Tests for the preview screen's script.
 *
 * The fixture follows templates/consumer-node-preview.html.twig. The
 * FunctionalJavascript tests drive the real markup in a browser.
 */

/* eslint-env jest */

const VIEW_MODE = '__consumer_node_preview_view_mode__';
const LAYOUT = '__consumer_node_preview_layout__';

const TARGETS = [
  { key: 'settings:one', label: 'One', url: `/one?mode=${VIEW_MODE}` },
  { key: 'settings:two', label: 'Two', url: `/two?mode=${VIEW_MODE}` },
];

// One and Three list layouts. Two has no [layout] token, so it lists none.
const LAYOUT_TARGETS = [
  {
    key: 'settings:one',
    label: 'One',
    url: `/one?mode=${VIEW_MODE}&layout=${LAYOUT}`,
    layouts: ['default', 'bare_page', 'wide'],
  },
  TARGETS[1],
  {
    key: 'settings:three',
    label: 'Three',
    url: `/three?mode=${VIEW_MODE}&layout=${LAYOUT}`,
    layouts: ['default', 'wide'],
  },
];

/**
 * Returns the page's settings, as the controller attaches them.
 *
 * @param {Array} targets
 *   The frontend targets, with key, label, url and layouts.
 *
 * @return {object}
 *   The consumerNodePreview settings.
 */
const settings = (targets) => ({
  placeholder: VIEW_MODE,
  viewMode: 'full',
  layoutPlaceholder: LAYOUT,
  layout: (targets.length && (targets[0].layouts || [])[0]) || '',
  active: targets.length ? targets[0].key : null,
  targets: Object.fromEntries(
    targets.map((each) => [
      each.key,
      {
        label: each.label,
        url: each.url,
        layouts: (each.layouts || []).map((name) => ({
          name,
          label: name.toUpperCase(),
        })),
        ...(each.inactive ? { inactive: true } : {}),
      },
    ]),
  ),
  previewUrl: `/node/preview/uuid/${VIEW_MODE}`,
  drupalUrl: `/node/preview/uuid/${VIEW_MODE}/drupal`,
});

/**
 * Completes a target's URL for a view mode.
 *
 * @param {string} name
 *   The target's name, one or two.
 * @param {string} [viewMode]
 *   The view mode.
 *
 * @return {string}
 *   The URL.
 */
const url = (name, viewMode = 'full') => `/${name}?mode=${viewMode}`;

/**
 * Completes a target's URL for a layout.
 *
 * @param {string} name
 *   The target's name, one or three.
 * @param {string} layout
 *   The layout.
 * @param {string} [viewMode]
 *   The view mode.
 *
 * @return {string}
 *   The URL.
 */
const at = (name, layout, viewMode = 'full') =>
  `/${name}?mode=${viewMode}&layout=${layout}`;

/**
 * Returns the preview markup.
 *
 * @param {object} [options]
 *   The page to build.
 * @param {Array} [options.targets]
 *   The frontend targets, with key, label and url, and a group for a
 *   consumer's target.
 * @param {string} [options.jsonapi]
 *   The JSON:API URL, or an empty string for a bundle without one.
 * @param {string} [options.active]
 *   The tab the page opens on.
 * @param {boolean} [options.formItem]
 *   Whether the layout picker sits in a form item wrapper, as in core's
 *   themes.
 *
 * @return {string}
 *   The HTML.
 */
const markup = ({
  targets = TARGETS,
  jsonapi = '/jsonapi/node/page/uuid/preview',
  active = 'frontend',
  formItem = true,
} = {}) => {
  const tab = (id, label) => `
    <li class="tabs__tab${id === active ? ' is-active' : ''}" role="presentation">
      <a href="#consumer-node-preview-${id}" id="consumer-node-preview-${id}-tab" role="tab"
        aria-controls="consumer-node-preview-${id}" aria-selected="${id === active}"
        data-consumer-node-preview-tab="${id}">${label}</a>
    </li>`;
  // The controller puts each target in its group's optgroup.
  const groups = targets.reduce((result, each, index) => {
    const option = `<option value="${each.key}"${index === 0 ? ' selected' : ''}>${each.label}</option>`;
    const last = result[result.length - 1];
    const group = each.group || 'Site';
    if (last && last.label === group) {
      last.options.push(option);
    } else {
      result.push({ label: group, options: [option] });
    }
    return result;
  }, []);
  const picker =
    targets.length > 1
      ? `<select data-consumer-node-preview-target>${groups
          .map(
            (group) =>
              `<optgroup label="${group.label}">${group.options.join('')}</optgroup>`,
          )
          .join('')}</select>`
      : '';
  const widths = ['390px', '820px', '100%']
    .map(
      (width) =>
        `<button type="button" aria-pressed="${width === '100%'}" data-consumer-node-preview-width="${width}">${width}</button>`,
    )
    .join('');
  const layouts = (targets.length && targets[0].layouts) || [];
  const layoutPicker = targets.some((each) => each.layouts)
    ? `<div class="${formItem ? 'js-form-item' : ''}"${layouts.length ? '' : ' hidden'}>
        <select data-consumer-node-preview-layout>${layouts
          .map((name) => `<option value="${name}">${name}</option>`)
          .join('')}</select>
      </div>`
    : '';
  const first =
    targets.length &&
    targets[0].url.replace(VIEW_MODE, 'full').replace(LAYOUT, layouts[0]);
  const frontend = targets.length
    ? `${picker}${layoutPicker}${widths}
      <a href="${first}" target="_blank" data-consumer-node-preview-open>Open</a>
      <div data-consumer-node-preview-viewport>
        <iframe src="${first}" data-consumer-node-preview-frame></iframe>
      </div>`
    : '<div data-consumer-node-preview-unconfigured></div>';
  const document = jsonapi
    ? `<pre data-consumer-node-preview-jsonapi="${jsonapi}"><code>Loading</code></pre>`
    : '<div class="messages"></div>';
  return `
    <div data-consumer-node-preview>
      <ul role="tablist">
        ${tab('frontend', 'Frontend')}${tab('drupal', 'Drupal')}${tab('jsonapi', 'JSON:API')}
      </ul>
      <select data-consumer-node-preview-view-mode>
        <option value="full" selected>Default</option>
        <option value="teaser">Teaser</option>
      </select>
      <section id="consumer-node-preview-frontend" role="tabpanel"${active !== 'frontend' ? ' hidden' : ''}>${frontend}</section>
      <section id="consumer-node-preview-drupal" role="tabpanel"${active !== 'drupal' ? ' hidden' : ''}>
        <div data-consumer-node-preview-drupal><p>Core preview</p></div>
      </section>
      <section id="consumer-node-preview-jsonapi" role="tabpanel" hidden>${document}</section>
    </div>`;
};

/**
 * Builds the page and attaches the behavior to it.
 *
 * @param {object} [options]
 *   The markup options, and settings to override.
 *
 * @return {HTMLElement}
 *   The preview's wrapper.
 */
const attach = (options = {}) => {
  // The script holds the object it loaded with, so this fills that object.
  drupalSettings.consumerNodePreview = {
    ...settings(options.targets || TARGETS),
    ...options.settings,
  };
  document.body.innerHTML = markup(options);
  Drupal.behaviors.consumerNodePreview.attach(document);
  return document.querySelector('[data-consumer-node-preview]');
};

const tab = (id) =>
  document.querySelector(`[data-consumer-node-preview-tab="${id}"]`);
const panel = (id) => document.getElementById(`consumer-node-preview-${id}`);
const frame = () =>
  document.querySelector('[data-consumer-node-preview-frame]');
const open = () => document.querySelector('[data-consumer-node-preview-open]');
const picker = () =>
  document.querySelector('[data-consumer-node-preview-target]');
const width = (value) =>
  document.querySelector(`[data-consumer-node-preview-width="${value}"]`);
const code = () =>
  document.querySelector('[data-consumer-node-preview-jsonapi] code');
const key = (event) =>
  new KeyboardEvent('keydown', { key: event, bubbles: true, cancelable: true });
const settle = () =>
  new Promise((resolve) => {
    setTimeout(resolve, 0);
  });

/**
 * Picks an option in a select, as an editor does.
 *
 * @param {HTMLSelectElement} select
 *   The select.
 * @param {string} value
 *   The option's value.
 */
const choose = (select, value) => {
  select.value = value;
  select.dispatchEvent(new Event('change'));
};
const pick = (value) => choose(picker(), value);
const viewMode = (value) =>
  choose(
    document.querySelector('[data-consumer-node-preview-view-mode]'),
    value,
  );

describe('Drupal.behaviors.consumerNodePreview', () => {
  beforeEach(() => {
    global.Drupal = {
      behaviors: {},
      t: (text, args = {}) =>
        Object.entries(args).reduce(
          (result, [name, value]) => result.replace(name, value),
          text,
        ),
    };
    // Core's once() marks each element, so a second attach skips it.
    global.once = (id, selector, context) =>
      Array.from(context.querySelectorAll(selector)).filter((element) => {
        if (element.dataset.once === id) {
          return false;
        }
        element.dataset.once = id;
        return true;
      });
    global.drupalSettings = {};
    global.fetch = jest.fn();
    // Core's Drupal.ajax(). Each request waits until the test settles it.
    Drupal.ajax = jest.fn(() => ({
      execute: () =>
        new Promise((resolve, reject) => {
          Drupal.ajax.pending.push({ resolve, reject });
        }),
    }));
    Drupal.ajax.pending = [];
    window.localStorage.clear();
    window.history.replaceState(null, '', '/');
    jest.isolateModules(() => {
      // The script runs as Drupal loads it: once, against the globals above.
      // eslint-disable-next-line global-require, import/extensions
      require('./consumer-node-preview.js');
    });
  });

  afterEach(() => {
    delete global.Drupal;
    delete global.drupalSettings;
    delete global.once;
    delete global.fetch;
    jest.restoreAllMocks();
  });

  describe('tabs', () => {
    it('opens on the tab the page marks, and shows only its panel', () => {
      attach();
      expect(tab('frontend').getAttribute('aria-selected')).toBe('true');
      expect(tab('frontend').tabIndex).toBe(0);
      expect(tab('drupal').tabIndex).toBe(-1);
      expect(panel('frontend').hidden).toBe(false);
      expect(panel('drupal').hidden).toBe(true);
      // Only a tab the editor picks is remembered.
      expect(window.localStorage.getItem('consumer_node_preview.tab')).toBe(
        null,
      );
    });

    it('opens on the first tab when the page marks none', () => {
      document.body.innerHTML = markup().replace(
        /aria-selected="true"/,
        'aria-selected="false"',
      );
      Drupal.behaviors.consumerNodePreview.attach(document);
      expect(tab('frontend').getAttribute('aria-selected')).toBe('true');
    });

    it('selects a clicked tab and remembers it', () => {
      attach();
      tab('drupal').click();
      expect(tab('drupal').getAttribute('aria-selected')).toBe('true');
      expect(tab('drupal').classList.contains('is-active')).toBe(true);
      expect(tab('drupal').parentElement.classList.contains('is-active')).toBe(
        true,
      );
      expect(tab('frontend').getAttribute('aria-selected')).toBe('false');
      expect(panel('drupal').hidden).toBe(false);
      expect(panel('frontend').hidden).toBe(true);
      expect(window.localStorage.getItem('consumer_node_preview.tab')).toBe(
        'drupal',
      );
    });

    it('opens on the remembered tab', () => {
      window.localStorage.setItem('consumer_node_preview.tab', 'drupal');
      attach();
      expect(tab('drupal').getAttribute('aria-selected')).toBe('true');
      expect(panel('frontend').hidden).toBe(true);
    });

    it('moves between tabs with the arrow keys, Home and End', () => {
      attach();
      tab('frontend').dispatchEvent(key('ArrowRight'));
      expect(tab('drupal').getAttribute('aria-selected')).toBe('true');
      expect(document.activeElement).toBe(tab('drupal'));
      tab('drupal').dispatchEvent(key('End'));
      expect(tab('jsonapi').getAttribute('aria-selected')).toBe('true');
      tab('jsonapi').dispatchEvent(key('ArrowRight'));
      expect(tab('frontend').getAttribute('aria-selected')).toBe('true');
      tab('frontend').dispatchEvent(key('ArrowLeft'));
      expect(tab('jsonapi').getAttribute('aria-selected')).toBe('true');
      tab('jsonapi').dispatchEvent(key('Home'));
      expect(tab('frontend').getAttribute('aria-selected')).toBe('true');
    });

    it('leaves other keys alone', () => {
      attach();
      const event = key('Enter');
      tab('frontend').dispatchEvent(event);
      expect(event.defaultPrevented).toBe(false);
      expect(tab('frontend').getAttribute('aria-selected')).toBe('true');
    });

    it('attaches once to a page', () => {
      attach();
      Drupal.behaviors.consumerNodePreview.attach(document);
      tab('drupal').click();
      expect(window.localStorage.getItem('consumer_node_preview.tab')).toBe(
        'drupal',
      );
      expect(panel('drupal').hidden).toBe(false);
    });
  });

  describe('JSON:API document', () => {
    it('loads once, with the session, when its tab opens', async () => {
      fetch.mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('{"data":{"id":"uuid"}}'),
      });
      attach();
      expect(fetch).not.toHaveBeenCalled();
      tab('jsonapi').click();
      await settle();
      expect(fetch).toHaveBeenCalledWith('/jsonapi/node/page/uuid/preview', {
        credentials: 'same-origin',
        headers: { Accept: 'application/vnd.api+json' },
      });
      expect(code().textContent).toBe(
        '{\n  "data": {\n    "id": "uuid"\n  }\n}',
      );
      tab('drupal').click();
      tab('jsonapi').click();
      expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('shows the status with the body of a failed request', async () => {
      fetch.mockResolvedValue({
        ok: false,
        status: 403,
        statusText: 'Forbidden',
        text: () => Promise.resolve('{"errors":[]}'),
      });
      attach({ active: 'jsonapi' });
      tab('jsonapi').click();
      await settle();
      expect(code().textContent).toBe('403 Forbidden\n\n{\n  "errors": []\n}');
    });

    it('shows a body that is not JSON as it came', async () => {
      fetch.mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('<html>Oops</html>'),
      });
      attach();
      tab('jsonapi').click();
      await settle();
      expect(code().textContent).toBe('<html>Oops</html>');
    });

    it('says when the request fails', async () => {
      fetch.mockRejectedValue(new Error('Network down'));
      attach();
      tab('jsonapi').click();
      await settle();
      expect(code().textContent).toBe(
        'The document could not be loaded: Network down',
      );
    });

    it('loads nothing when JSON:API has no preview for the bundle', () => {
      attach({ jsonapi: '' });
      tab('jsonapi').click();
      expect(fetch).not.toHaveBeenCalled();
    });
  });

  describe('frontend picker', () => {
    it('frames the picked frontend and remembers it', () => {
      attach();
      pick('settings:two');
      expect(frame().getAttribute('src')).toBe(url('two'));
      expect(open().getAttribute('href')).toBe(url('two'));
      expect(window.localStorage.getItem('consumer_node_preview.target')).toBe(
        'settings:two',
      );
    });

    it('does not reload a frame that already shows the frontend', () => {
      attach();
      const before = frame();
      pick('settings:one');
      expect(frame()).toBe(before);
      expect(open().getAttribute('href')).toBe(url('one'));
    });

    it('opens on the remembered frontend', () => {
      window.localStorage.setItem(
        'consumer_node_preview.target',
        'settings:two',
      );
      attach();
      expect(picker().value).toBe('settings:two');
      expect(frame().getAttribute('src')).toBe(url('two'));
    });

    it('lets a frontend in the URL win over the remembered one', () => {
      window.localStorage.setItem(
        'consumer_node_preview.target',
        'settings:two',
      );
      window.history.replaceState(null, '', '/?frontend=settings:one');
      attach();
      expect(frame().getAttribute('src')).toBe(url('one'));
    });

    it('frames a frontend picked in another group', () => {
      const targets = [
        ...LAYOUT_TARGETS.slice(0, 2),
        { ...LAYOUT_TARGETS[2], key: '3:0', group: 'Nuxt' },
      ];
      attach({ targets });
      expect(picker().querySelectorAll('optgroup')).toHaveLength(2);
      pick('3:0');
      expect(picker().selectedOptions[0].parentElement.label).toBe('Nuxt');
      expect(frame().getAttribute('src')).toBe(at('three', 'default'));
      expect(window.localStorage.getItem('consumer_node_preview.target')).toBe(
        '3:0',
      );
      pick('settings:two');
      expect(frame().getAttribute('src')).toBe(url('two'));
    });

    it('works with one frontend and no picker', () => {
      attach({ targets: [TARGETS[0]] });
      expect(picker()).toBe(null);
      expect(frame().getAttribute('src')).toBe(url('one'));
    });

    it('works with no frontend at all', () => {
      attach({ targets: [], active: 'drupal' });
      expect(frame()).toBe(null);
      expect(panel('drupal').hidden).toBe(false);
    });
  });

  describe('view mode', () => {
    it('switches the frame and the new tab link without a reload', () => {
      attach();
      const before = frame();
      viewMode('teaser');
      // A new frame, so the switch adds no history entry.
      expect(frame()).not.toBe(before);
      expect(frame().getAttribute('src')).toBe(url('one', 'teaser'));
      expect(open().getAttribute('href')).toBe(url('one', 'teaser'));
      expect(window.location.pathname).toBe('/node/preview/uuid/teaser');
    });

    it('keeps the picked frontend', () => {
      attach();
      pick('settings:two');
      viewMode('teaser');
      expect(frame().getAttribute('src')).toBe(url('two', 'teaser'));
    });

    it('keeps the query string in the address', () => {
      window.history.replaceState(null, '', '/?frontend=settings:two');
      attach();
      viewMode('teaser');
      expect(window.location.pathname + window.location.search).toBe(
        '/node/preview/uuid/teaser?frontend=settings:two',
      );
    });

    it('starts from the view mode the page shows', () => {
      attach({ settings: { viewMode: 'teaser' } });
      pick('settings:two');
      expect(frame().getAttribute('src')).toBe(url('two', 'teaser'));
    });

    it('leaves the address alone without a preview URL', () => {
      attach({ settings: { previewUrl: null } });
      viewMode('teaser');
      expect(window.location.pathname).toBe('/');
      expect(frame().getAttribute('src')).toBe(url('one', 'teaser'));
    });

    it('switches with no frontend at all', () => {
      attach({ targets: [], active: 'drupal' });
      viewMode('teaser');
      expect(frame()).toBe(null);
      expect(Drupal.ajax).toHaveBeenCalledTimes(1);
    });
  });

  describe('Drupal tab', () => {
    const request = (mode) => ({
      url: `/node/preview/uuid/${mode}/drupal`,
      progress: { type: 'fullscreen' },
    });

    it('asks for nothing until the view mode changes', () => {
      attach({ active: 'drupal' });
      tab('frontend').click();
      tab('drupal').click();
      expect(Drupal.ajax).not.toHaveBeenCalled();
    });

    it('follows the view mode while it is shown', () => {
      attach({ active: 'drupal' });
      viewMode('teaser');
      expect(Drupal.ajax).toHaveBeenCalledWith(request('teaser'));
    });

    it('waits until it is shown, and then asks once', async () => {
      attach();
      viewMode('teaser');
      expect(Drupal.ajax).not.toHaveBeenCalled();
      tab('drupal').click();
      expect(Drupal.ajax).toHaveBeenCalledWith(request('teaser'));
      Drupal.ajax.pending[0].resolve();
      await settle();
      tab('frontend').click();
      tab('drupal').click();
      expect(Drupal.ajax).toHaveBeenCalledTimes(1);
    });

    it('runs one request at a time and catches up with the view mode', async () => {
      attach({ active: 'drupal' });
      viewMode('teaser');
      viewMode('full');
      expect(Drupal.ajax).toHaveBeenCalledTimes(1);
      Drupal.ajax.pending[0].resolve();
      await settle();
      expect(Drupal.ajax).toHaveBeenCalledTimes(2);
      expect(Drupal.ajax).toHaveBeenLastCalledWith(request('full'));
      Drupal.ajax.pending[1].resolve();
      await settle();
      expect(Drupal.ajax).toHaveBeenCalledTimes(2);
    });

    it('asks again when it is shown after a failed request', async () => {
      attach({ active: 'drupal' });
      viewMode('teaser');
      Drupal.ajax.pending[0].reject();
      await settle();
      expect(Drupal.ajax).toHaveBeenCalledTimes(1);
      tab('frontend').click();
      tab('drupal').click();
      expect(Drupal.ajax).toHaveBeenCalledTimes(2);
    });

    it('asks for nothing without a Drupal URL', () => {
      attach({ active: 'drupal', settings: { drupalUrl: null } });
      viewMode('teaser');
      expect(Drupal.ajax).not.toHaveBeenCalled();
    });
  });

  describe('layouts', () => {
    const layouts = () =>
      document.querySelector('[data-consumer-node-preview-layout]');
    const listed = () =>
      Array.from(layouts().options).map((option) => option.value);
    const shown = () => !layouts().parentElement.hidden;
    const layout = (value) => choose(layouts(), value);

    it("frames a target on its first layout and lists the target's layouts", () => {
      attach({ targets: LAYOUT_TARGETS });
      expect(frame().getAttribute('src')).toBe(at('one', 'default'));
      expect(listed()).toEqual(['default', 'bare_page', 'wide']);
      expect(layouts().options[1].textContent).toBe('BARE_PAGE');
      expect(layouts().value).toBe('default');
      expect(shown()).toBe(true);
    });

    it('switches layout without a reload and remembers it per target', () => {
      attach({ targets: LAYOUT_TARGETS });
      layout('bare_page');
      expect(frame().getAttribute('src')).toBe(at('one', 'bare_page'));
      expect(open().getAttribute('href')).toBe(at('one', 'bare_page'));
      expect(
        window.localStorage.getItem(
          'consumer_node_preview.layout.settings:one',
        ),
      ).toBe('bare_page');
    });

    it('hides for a target without layouts and comes back with the remembered one', () => {
      attach({ targets: LAYOUT_TARGETS });
      layout('bare_page');
      pick('settings:two');
      expect(frame().getAttribute('src')).toBe(url('two'));
      expect(listed()).toEqual([]);
      expect(shown()).toBe(false);
      pick('settings:three');
      expect(frame().getAttribute('src')).toBe(at('three', 'default'));
      expect(listed()).toEqual(['default', 'wide']);
      pick('settings:one');
      expect(frame().getAttribute('src')).toBe(at('one', 'bare_page'));
      expect(layouts().value).toBe('bare_page');
      expect(shown()).toBe(true);
    });

    it('opens on the remembered layout', () => {
      window.localStorage.setItem(
        'consumer_node_preview.layout.settings:one',
        'wide',
      );
      attach({ targets: LAYOUT_TARGETS });
      expect(frame().getAttribute('src')).toBe(at('one', 'wide'));
      expect(layouts().value).toBe('wide');
    });

    it('opens on the first layout when the remembered one is no longer listed', () => {
      window.localStorage.setItem(
        'consumer_node_preview.layout.settings:one',
        'gone',
      );
      attach({ targets: LAYOUT_TARGETS });
      expect(frame().getAttribute('src')).toBe(at('one', 'default'));
    });

    it('lets a listed layout in the URL win once, without remembering it', () => {
      window.localStorage.setItem(
        'consumer_node_preview.layout.settings:one',
        'bare_page',
      );
      window.history.replaceState(null, '', '/?layout=wide');
      attach({ targets: LAYOUT_TARGETS });
      expect(frame().getAttribute('src')).toBe(at('one', 'wide'));
      pick('settings:two');
      pick('settings:one');
      expect(frame().getAttribute('src')).toBe(at('one', 'bare_page'));
    });

    it('ignores a layout in the URL that the target does not list', () => {
      window.localStorage.setItem(
        'consumer_node_preview.layout.settings:one',
        'bare_page',
      );
      window.history.replaceState(null, '', '/?layout=nope');
      attach({ targets: LAYOUT_TARGETS });
      expect(frame().getAttribute('src')).toBe(at('one', 'bare_page'));
    });

    it('gives the layout in the URL to a remembered target', () => {
      window.localStorage.setItem(
        'consumer_node_preview.target',
        'settings:three',
      );
      window.history.replaceState(null, '', '/?layout=wide');
      attach({ targets: LAYOUT_TARGETS });
      expect(frame().getAttribute('src')).toBe(at('three', 'wide'));
      expect(listed()).toEqual(['default', 'wide']);
      expect(layouts().value).toBe('wide');
    });

    it('keeps the layout when the view mode changes', () => {
      attach({ targets: LAYOUT_TARGETS });
      layout('wide');
      viewMode('teaser');
      expect(frame().getAttribute('src')).toBe(at('one', 'wide', 'teaser'));
    });

    it('hides its own parent outside a form item', () => {
      attach({ targets: LAYOUT_TARGETS, formItem: false });
      pick('settings:two');
      expect(shown()).toBe(false);
    });

    it('switches layout when the browser blocks storage', () => {
      jest.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
        throw new Error('Blocked');
      });
      jest.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
        throw new Error('Blocked');
      });
      attach({ targets: LAYOUT_TARGETS });
      layout('wide');
      expect(frame().getAttribute('src')).toBe(at('one', 'wide'));
      viewMode('teaser');
      expect(frame().getAttribute('src')).toBe(at('one', 'wide', 'teaser'));
    });

    it('encodes the layout in the URL', () => {
      const targets = [{ ...LAYOUT_TARGETS[0], layouts: ['a&b c'] }];
      attach({ targets });
      expect(frame().getAttribute('src')).toBe(at('one', 'a%26b%20c'));
    });

    it('is absent when no target lists layouts', () => {
      attach();
      expect(layouts()).toBe(null);
      expect(frame().getAttribute('src')).toBe(url('one'));
    });
  });

  describe('widths', () => {
    it('sizes the frame to the pressed width and remembers it', () => {
      const preview = attach();
      width('390px').click();
      const viewport = preview.querySelector(
        '[data-consumer-node-preview-viewport]',
      );
      expect(
        viewport.style.getPropertyValue('--consumer-node-preview-width'),
      ).toBe('390px');
      expect(width('390px').getAttribute('aria-pressed')).toBe('true');
      expect(width('390px').classList.contains('button--primary')).toBe(true);
      expect(width('100%').getAttribute('aria-pressed')).toBe('false');
      expect(window.localStorage.getItem('consumer_node_preview.width')).toBe(
        '390px',
      );
    });

    it('opens on the remembered width', () => {
      window.localStorage.setItem('consumer_node_preview.width', '820px');
      attach();
      expect(width('820px').getAttribute('aria-pressed')).toBe('true');
    });
  });

  describe('frontend check', () => {
    // Frontends on two other origins, a second target on the first origin,
    // and a path on Drupal's own origin.
    const REMOTE_TARGETS = [
      {
        key: 'settings:one',
        label: 'One',
        url: `http://one.example.com/one?mode=${VIEW_MODE}`,
      },
      {
        key: '2:0',
        label: 'Other',
        url: `http://one.example.com/other?mode=${VIEW_MODE}`,
        group: 'Nuxt',
      },
      {
        key: '2:1',
        label: 'Two',
        url: `https://two.example.com:8443/two?mode=${VIEW_MODE}`,
        group: 'Nuxt',
      },
      { key: 'settings:path', label: 'Path', url: `/path?mode=${VIEW_MODE}` },
    ];
    const option = (value) =>
      picker().querySelector(`option[value="${value}"]`);
    const silent = (value) =>
      option(value).hasAttribute('data-consumer-node-preview-not-responding');

    afterEach(() => {
      jest.useRealTimers();
    });

    it('asks each other origin once, after the page settles', async () => {
      fetch.mockResolvedValue({ type: 'opaque', ok: false, status: 0 });
      attach({ targets: REMOTE_TARGETS });
      // The frame does not wait for the check.
      expect(frame().getAttribute('src')).toBe(
        'http://one.example.com/one?mode=full',
      );
      expect(fetch).not.toHaveBeenCalled();
      await settle();
      expect(fetch.mock.calls.map(([resource]) => resource)).toEqual([
        'http://one.example.com/',
        'https://two.example.com:8443/',
      ]);
      expect(fetch).toHaveBeenCalledWith('http://one.example.com/', {
        mode: 'no-cors',
        cache: 'no-store',
        credentials: 'omit',
        signal: expect.any(AbortSignal),
      });
      await settle();
      // An opaque response is an answer.
      REMOTE_TARGETS.forEach((each) => {
        expect(silent(each.key)).toBe(false);
        expect(option(each.key).textContent).toBe(each.label);
      });
    });

    it('does not ask the origin of an inactive target', async () => {
      fetch.mockRejectedValue(new TypeError('Failed to fetch'));
      const targets = [
        ...REMOTE_TARGETS,
        {
          key: '3:0',
          label: 'Asleep',
          url: `http://asleep.example.com/?mode=${VIEW_MODE}`,
          group: 'Inactive',
          inactive: true,
        },
      ];
      attach({ targets });
      await settle();
      await settle();
      expect(fetch.mock.calls.map(([resource]) => resource)).toEqual([
        'http://one.example.com/',
        'https://two.example.com:8443/',
      ]);
      expect(silent('3:0')).toBe(false);
      expect(option('3:0').textContent).toBe('Asleep');
    });

    it('marks the targets of an origin that does not answer', async () => {
      fetch.mockImplementation((resource) =>
        resource === 'http://one.example.com/'
          ? Promise.reject(new TypeError('Failed to fetch'))
          : Promise.resolve({ type: 'opaque' }),
      );
      attach({ targets: REMOTE_TARGETS });
      await settle();
      await settle();
      expect(silent('settings:one')).toBe(true);
      expect(silent('2:0')).toBe(true);
      expect(option('settings:one').textContent).toBe('One (not responding)');
      expect(option('2:0').textContent).toBe('Other (not responding)');
      expect(silent('2:1')).toBe(false);
      expect(option('2:1').textContent).toBe('Two');
      // A marked target can still be picked. Picking it may wake it.
      expect(option('2:0').disabled).toBe(false);
      expect(option('2:0').hidden).toBe(false);
      pick('2:0');
      expect(frame().getAttribute('src')).toBe(
        'http://one.example.com/other?mode=full',
      );
    });

    it('marks an origin that does not answer in time', async () => {
      jest.useFakeTimers();
      fetch.mockImplementation(
        (resource, options) =>
          new Promise((resolve, reject) => {
            if (resource === 'http://one.example.com/') {
              resolve({ type: 'opaque' });
            }
            options.signal.addEventListener('abort', () =>
              reject(new DOMException('Aborted', 'AbortError')),
            );
          }),
      );
      attach({ targets: REMOTE_TARGETS });
      await jest.advanceTimersByTimeAsync(2999);
      expect(fetch).toHaveBeenCalledTimes(2);
      expect(silent('2:1')).toBe(false);
      await jest.advanceTimersByTimeAsync(1);
      expect(silent('2:1')).toBe(true);
      expect(option('2:1').textContent).toBe('Two (not responding)');
      expect(silent('settings:one')).toBe(false);
    });

    it("skips a path, a URL on Drupal's own origin and a broken URL", async () => {
      fetch.mockResolvedValue({ type: 'opaque' });
      attach({
        targets: [
          REMOTE_TARGETS[3],
          {
            key: 'settings:local',
            label: 'Local',
            url: `${window.location.origin}/local?mode=${VIEW_MODE}`,
          },
          // A URL the browser cannot parse has no origin to ask.
          { key: 'settings:broken', label: 'Broken', url: 'http://[broken' },
        ],
      });
      await settle();
      expect(fetch).not.toHaveBeenCalled();
      expect(silent('settings:path')).toBe(false);
    });

    it('asks nothing without a picker', async () => {
      attach({ targets: [REMOTE_TARGETS[0]] });
      await settle();
      expect(fetch).not.toHaveBeenCalled();
    });

    it('waits for the page to load', async () => {
      fetch.mockResolvedValue({ type: 'opaque' });
      jest.spyOn(document, 'readyState', 'get').mockReturnValue('interactive');
      attach({ targets: REMOTE_TARGETS });
      await settle();
      expect(fetch).not.toHaveBeenCalled();
      window.dispatchEvent(new Event('load'));
      await settle();
      expect(fetch).toHaveBeenCalledTimes(2);
    });
  });

  it('keeps a click on the new tab link away from core', () => {
    attach();
    const core = jest.fn();
    document.addEventListener('click', core);
    open().addEventListener('click', (event) => event.preventDefault());
    open().click();
    expect(core).not.toHaveBeenCalled();
    document.removeEventListener('click', core);
  });

  it('works when the browser blocks storage', () => {
    jest.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('Blocked');
    });
    jest.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('Blocked');
    });
    attach();
    tab('drupal').click();
    pick('settings:two');
    width('390px').click();
    expect(panel('drupal').hidden).toBe(false);
    expect(frame().getAttribute('src')).toBe(url('two'));
  });
});
