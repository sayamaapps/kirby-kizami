/*
 * Kizami actions (since 1.4.0). Served by /k/event.js, embedded with the
 * snippet kizami/events. Reports configured actions to /k/event:
 *
 *   <a href="/files/prices.pdf" data-kizami-event="download" data-kizami-detail="prices.pdf">
 *   <video data-kizami-event="video_play" data-kizami-detail="Trailer" controls>
 *   window.kizami.event('newsletter_open', 'scroll');
 *
 * A click on an element with data-kizami-event (or inside one) counts; on
 * <audio> and <video> the start of playback counts instead. Each action and
 * detail is sent at most once per page view. No cookies are read or set,
 * nothing is stored in the browser, nothing goes to another server. Which
 * keys count is decided by the server (kizami.events), not here.
 */
(function () {
  'use strict';

  var script = document.currentScript;
  if (!script || !script.src) { return; }
  // /k/event.js?v=… → /k/event, so a site in a subfolder works as well.
  var endpoint = script.src.replace(/\.js(?:[?#].*)?$/, '');
  var sent = {};

  // The server cleans the detail again; this only keeps query strings and
  // fragments of URLs from leaving the browser at all.
  function clean(detail) {
    if (detail === undefined || detail === null) { return ''; }
    detail = String(detail)
      .replace(/((?:[a-z][a-z0-9+.\-]*:)?\/\/[^\s?#]*|\bwww\.[^\s?#]*|(^|\s)\/[^\s?#]*)[?#]\S*/gi, '$1')
      .replace(/^\s+|\s+$/g, '');
    return detail.slice(0, 300);
  }

  function send(key, detail) {
    if (typeof key !== 'string' || !/^[a-z0-9_-]{1,32}$/.test(key)) { return false; }
    detail = clean(detail);
    var once = key + '\n' + detail;
    if (sent[once]) { return false; }
    sent[once] = true;
    var body = new URLSearchParams();
    body.set('event', key);
    body.set('path', location.pathname);
    if (detail !== '') { body.set('detail', detail); }
    try {
      if (navigator.sendBeacon && navigator.sendBeacon(endpoint, body)) { return true; }
    } catch (e) { /* fall through to fetch */ }
    try {
      fetch(endpoint, { method: 'POST', body: body, keepalive: true, credentials: 'omit' })['catch'](function () {});
      return true;
    } catch (e) {
      return false;
    }
  }

  function isMedia(element) {
    return element.tagName === 'AUDIO' || element.tagName === 'VIDEO';
  }

  function report(element) {
    send(element.getAttribute('data-kizami-event'), element.getAttribute('data-kizami-detail'));
  }

  // Capture phase: a site script that stops propagation must not hide the click.
  function onClick(event) {
    if (event.type === 'auxclick' && event.button !== 1) { return; }
    var target = event.target;
    var element = target && target.closest ? target.closest('[data-kizami-event]') : null;
    if (element && !isMedia(element)) { report(element); }
  }
  document.addEventListener('click', onClick, true);
  // Middle click: opening a download or link in a new tab.
  document.addEventListener('auxclick', onClick, true);
  // 'play' does not bubble, but it can be caught on the way down.
  document.addEventListener('play', function (event) {
    var element = event.target;
    if (element && element.hasAttribute && isMedia(element) && element.hasAttribute('data-kizami-event')) { report(element); }
  }, true);

  window.kizami = window.kizami || {};
  /** Report an action from the site's own code; true if it was sent now. */
  window.kizami.event = function (key, detail) { return send(key, detail); };
}());
