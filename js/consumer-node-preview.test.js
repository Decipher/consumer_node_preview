/**
 * @file
 * Tests for the preview screen's script.
 *
 * The fixture follows templates/consumer-node-preview.html.twig. The
 * FunctionalJavascript tests drive the real markup in a browser.
 */

/* eslint-env jest */

const TARGETS = [
  { key: 'settings:one', label: 'One', url: '/one' },
  { key: 'settings:two', label: 'Two', url: '/two' },
];

/**
 * Returns the preview markup.
 *
 * @param {object} [options]
 *   The page to build.
 * @param {Array} [options.targets]
 *   The frontend targets, with key, label and url.
 * @param {string} [options.jsonapi]
 *   The JSON:API URL, or an empty string for a bundle without one.
 * @param {string} [options.active]
 *   The tab the page opens on.
 *
 * @return {string}
 *   The HTML.
 */
const markup = ({
  targets = TARGETS,
  jsonapi = '/jsonapi/node/page/uuid/preview',
  active = 'frontend',
} = {}) => {
  const tab = (id, label) => `
    <li class="tabs__tab${id === active ? ' is-active' : ''}" role="presentation">
      <a href="#consumer-node-preview-${id}" id="consumer-node-preview-${id}-tab" role="tab"
        aria-controls="consumer-node-preview-${id}" aria-selected="${id === active}"
        data-consumer-node-preview-tab="${id}">${label}</a>
    </li>`;
  const picker =
    targets.length > 1
      ? `<select data-consumer-node-preview-target>${targets
          .map(
            (each, index) =>
              `<option value="${each.url}" data-key="${each.key}"${index === 0 ? ' selected' : ''}>${each.label}</option>`,
          )
          .join('')}</select>`
      : '';
  const widths = ['390px', '820px', '100%']
    .map(
      (width) =>
        `<button type="button" aria-pressed="${width === '100%'}" data-consumer-node-preview-width="${width}">${width}</button>`,
    )
    .join('');
  const frontend = targets.length
    ? `${picker}${widths}
      <a href="${targets[0].url}" target="_blank" data-consumer-node-preview-open>Open</a>
      <div data-consumer-node-preview-viewport>
        <iframe src="${targets[0].url}" data-consumer-node-preview-frame></iframe>
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
      <section id="consumer-node-preview-frontend" role="tabpanel"${active !== 'frontend' ? ' hidden' : ''}>${frontend}</section>
      <section id="consumer-node-preview-drupal" role="tabpanel"${active !== 'drupal' ? ' hidden' : ''}><p>Core preview</p></section>
      <section id="consumer-node-preview-jsonapi" role="tabpanel" hidden>${document}</section>
    </div>`;
};

/**
 * Builds the page and attaches the behavior to it.
 *
 * @param {object} [options]
 *   The markup options.
 *
 * @return {HTMLElement}
 *   The preview's wrapper.
 */
const attach = (options) => {
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
 * Picks a frontend in the picker, as an editor does.
 *
 * @param {string} value
 *   The option's value.
 */
const pick = (value) => {
  picker().value = value;
  picker().dispatchEvent(new Event('change'));
};

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
    global.fetch = jest.fn();
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
      pick('/two');
      expect(frame().getAttribute('src')).toBe('/two');
      expect(open().getAttribute('href')).toBe('/two');
      expect(window.localStorage.getItem('consumer_node_preview.target')).toBe(
        'settings:two',
      );
    });

    it('does not reload a frame that already shows the frontend', () => {
      attach();
      const setAttribute = jest.spyOn(frame(), 'setAttribute');
      pick('/one');
      expect(setAttribute).not.toHaveBeenCalled();
      expect(open().getAttribute('href')).toBe('/one');
    });

    it('opens on the remembered frontend', () => {
      window.localStorage.setItem(
        'consumer_node_preview.target',
        'settings:two',
      );
      attach();
      expect(picker().value).toBe('/two');
      expect(frame().getAttribute('src')).toBe('/two');
    });

    it('lets a frontend in the URL win over the remembered one', () => {
      window.localStorage.setItem(
        'consumer_node_preview.target',
        'settings:two',
      );
      window.history.replaceState(null, '', '/?frontend=settings:one');
      attach();
      expect(frame().getAttribute('src')).toBe('/one');
    });

    it('works with one frontend and no picker', () => {
      attach({ targets: [TARGETS[0]] });
      expect(picker()).toBe(null);
      expect(frame().getAttribute('src')).toBe('/one');
    });

    it('works with no frontend at all', () => {
      attach({ targets: [], active: 'drupal' });
      expect(frame()).toBe(null);
      expect(panel('drupal').hidden).toBe(false);
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
    pick('/two');
    width('390px').click();
    expect(panel('drupal').hidden).toBe(false);
    expect(frame().getAttribute('src')).toBe('/two');
  });
});
