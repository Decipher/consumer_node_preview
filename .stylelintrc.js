// DrupalCI lints CSS with core's config (see .gitlab-ci.yml). Use its order
// and media query notation locally when core is in the build, so one file
// passes both. The config runs from the build root and from the module's
// copy inside it.
let order = { 'order/properties-alphabetical-order': true };
const coreConfig = [
  `${__dirname}/web/core/.stylelintrc.json`,
  `${__dirname}/../../../core/.stylelintrc.json`,
].find((file) => require('fs').existsSync(file));
if (coreConfig) {
  // eslint-disable-next-line global-require, import/no-dynamic-require
  const core = require(coreConfig);
  order = Object.fromEntries(
    ['order/order', 'order/properties-order', 'media-feature-range-notation']
      .filter((rule) => rule in core.rules)
      .map((rule) => [rule, core.rules[rule]]),
  );
}

module.exports = {
  extends: [
    'stylelint-config-standard'
  ],
  plugins: [
    'stylelint-order'
  ],
  rules: {
    ...order,
    'at-rule-no-unknown': [
      true,
      {
        ignoreAtRules: [
          'extend',
          'at-root',
          'debug',
          'warn',
          'error',
          'if',
          'else',
          'for',
          'each',
          'while',
          'include',
          'mixin',
          'function',
          'return',
          'content'
        ]
      }
    ],
    'selector-class-pattern': null,
    'selector-id-pattern': null,
    'custom-property-pattern': null,
    'keyframes-name-pattern': null,
    'no-descending-specificity': null,
    'font-family-no-missing-generic-family-keyword': null
  }
};
