/*
 * Kizami campaign carry (since 2.1.0). Served by /k/campaign.js, embedded with
 * the snippet kizami/campaign. Takes the campaign tag of the page address
 * (utm_campaign, else the parameter named in data-kizami-campaign, the option
 * sayamaapps.kizami.campaignParam) and carries it on:
 *
 *   - Order links of Digistore24 (digistore24.com/product/…,
 *     checkout-ds24.com/product/…) take it as ds24tr, the vendor's tracking
 *     key, unless they belong to an affiliate (aff) or carry one already. An
 *     own cam without aff is dropped: the shop reads cam only as the campaign
 *     of an affiliate.
 *   - Links of the site's own (same origin) take it as that parameter, unless
 *     they carry utm_campaign or the parameter already.
 *   - <input data-kizami-campaign> (the hidden field of a newsletter form, for
 *     example) takes it as value while the field is empty.
 *
 * Links and fields inserted later (a form loaded on click) are handled too.
 * Nothing is stored in the browser and nothing is sent anywhere: the server
 * counts the page view with the tag, the next page or the shop reads it from
 * the address. window.kizami.campaign holds the tag for the site's own code.
 */
(function () {
  'use strict';

  var script = document.currentScript;
  if (!script) { return; }
  var param = script.getAttribute('data-kizami-campaign') || '';
  if (!/^[A-Za-z0-9_-]{1,32}$/.test(param)) { return; }

  // The same cleaning as on the server: harmless characters only, capped.
  function clean(value) {
    return typeof value === 'string' ? value.replace(/[^A-Za-z0-9_-]/g, '').slice(0, 50) : '';
  }

  var campaign = '';
  try {
    var search = new URLSearchParams(location.search);
    campaign = clean(search.get('utm_campaign')) || clean(search.get(param));
  } catch (e) {
    return;
  }
  if (!campaign) { return; }

  var SHOP = /(^|\.)(digistore24\.com|checkout-ds24\.com)$/i;

  function fragmentParams(url) {
    var hash = url.hash.replace(/^#/, '');
    return hash.indexOf('=') === -1 ? null : new URLSearchParams(hash);
  }

  // Order link: ds24tr unless an affiliate owns the link; an own cam is dropped.
  function shopLink(url) {
    var fragment = fragmentParams(url);
    if (url.searchParams.has('aff') || (fragment && fragment.has('aff'))) { return false; }
    var changed = false;
    if (url.searchParams.has('cam')) {
      url.searchParams.delete('cam');
      changed = true;
    }
    if (fragment && fragment.has('cam')) {
      fragment.delete('cam');
      url.hash = fragment.toString();
      changed = true;
    }
    if (!url.searchParams.has('ds24tr') && !(fragment && fragment.has('ds24tr'))) {
      url.searchParams.set('ds24tr', campaign);
      changed = true;
    }
    return changed;
  }

  // Own link: the parameter, unless the link carries a campaign already.
  function ownLink(url) {
    if (url.origin !== location.origin) { return false; }
    if (url.searchParams.has('utm_campaign') || url.searchParams.has(param)) { return false; }
    url.searchParams.set(param, campaign);
    return true;
  }

  function tagLink(a) {
    var href = (a.getAttribute('href') || '').replace(/^\s+/, '');
    if (!href || href.charAt(0) === '#') { return; }
    var scheme = /^([a-z][a-z0-9+.\-]*):/i.exec(href);
    if (scheme && !/^https?$/i.test(scheme[1])) { return; }
    var url;
    try {
      url = new URL(href, location.href);
    } catch (e) {
      return;
    }
    var shop = SHOP.test(url.hostname) && url.pathname.indexOf('/product/') === 0;
    if (!(shop ? shopLink(url) : ownLink(url))) { return; }
    // A root-relative link stays relative, as written; the others become absolute.
    var relative = href.charAt(0) === '/' && href.charAt(1) !== '/';
    a.setAttribute('href', relative ? url.pathname + url.search + url.hash : url.toString());
  }

  function fillField(input) {
    if (!input.value) { input.value = campaign; }
  }

  function each(list, fn) {
    Array.prototype.forEach.call(list, fn);
  }

  function apply(root) {
    if (root.matches && root.matches('a[href]')) { tagLink(root); }
    if (root.matches && root.matches('input[data-kizami-campaign]')) { fillField(root); }
    if (!root.querySelectorAll) { return; }
    each(root.querySelectorAll('a[href]'), tagLink);
    each(root.querySelectorAll('input[data-kizami-campaign]'), fillField);
  }

  function start() {
    apply(document);
    if (typeof MutationObserver !== 'function') { return; }
    new MutationObserver(function (records) {
      each(records, function (record) {
        each(record.addedNodes, function (node) {
          if (node.nodeType === 1) { apply(node); }
        });
      });
    }).observe(document.documentElement, { childList: true, subtree: true });
  }

  window.kizami = window.kizami || {};
  window.kizami.campaign = campaign;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
}());
