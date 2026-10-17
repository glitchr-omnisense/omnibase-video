/*
 * A film read ahead, into the visitor's own browser: its playlists and every
 * segment of one quality, kept in the Cache API ("omnibase-video") until the
 * visitor removes them. Watching it then reads nothing more from the network,
 * whatever the connection does meanwhile. Nothing is stored on the server: a
 * remote film's segments go on coming from its broadcaster, to this browser
 * only, as they would while playing (a long buffer, kept).
 *
 *   import Prefetch from './prefetch.js';
 *   el.config = Prefetch.config();              // <hls-video>: hls.js reads the cache first
 *   Prefetch.start(id, src, function (state) {}) // { done, total, bytes, complete }
 *   Prefetch.stop(id)  Prefetch.status(id)  Prefetch.remove(id)
 *
 * The quality kept is the best the screen can show (its height in device
 * pixels), else the lowest. The master playlist is kept with that quality
 * alone, so the player plays what was read ahead instead of switching to a
 * quality the cache does not hold. Read through hls.js's own loaders
 * (pLoader, fLoader): no service worker - omnibase's /sw.js holds the site's
 * scope for its notifications.
 */
var CACHE = 'omnibase-video';
var running = {};

function supported() {
    try { return !!(window.caches && window.fetch && window.AbortController && window.isSecureContext); } catch (e) { return false; }
}

function open() { return window.caches.open(CACHE); }

function absolute(url, base) { return new URL(url, base || location.href).href; }

/** Where a film's record is kept: what was read, how much, whether all of it. */
function key(id) { return location.origin + '/video-prefetch/' + encodeURIComponent(id); }

function text(url, signal) {
    return fetch(url, { credentials: 'same-origin', signal: signal }).then(function (response) {
        if (!response.ok) throw new Error(url + ': ' + response.status);
        return response.text();
    });
}

function playlist(body) { return new Response(body, { headers: { 'Content-Type': 'application/vnd.apple.mpegurl' } }); }

/** The qualities of a master playlist: { url, height, bandwidth, info } each, best last. */
function variants(body, base) {
    var lines = body.split(/\r?\n/), found = [];
    for (var i = 0; i < lines.length; i++) {
        if (lines[i].indexOf('#EXT-X-STREAM-INF:') !== 0) continue;
        var uri = '';
        for (var j = i + 1; j < lines.length && !uri; j++) if (lines[j] && lines[j].charAt(0) !== '#') uri = lines[j].trim();
        if (!uri) continue;
        var resolution = /RESOLUTION=\d+x(\d+)/.exec(lines[i]);
        var bandwidth = /[:,]BANDWIDTH=(\d+)/.exec(lines[i]);
        found.push({ url: absolute(uri, base), height: resolution ? +resolution[1] : 0, bandwidth: bandwidth ? +bandwidth[1] : 0, info: lines[i] });
    }
    return found.sort(function (a, b) { return (a.height - b.height) || (a.bandwidth - b.bandwidth); });
}

function choose(list) {
    var screenHeight = Math.max(window.screen ? window.screen.height : 0, window.innerHeight) * (window.devicePixelRatio || 1);
    var fitting = list.filter(function (v) { return !v.height || v.height <= screenHeight; });
    return fitting.length ? fitting[fitting.length - 1] : list[0];
}

/** What a media playlist makes the player read: its segments, its init segment, its keys. */
function segments(body, base) {
    var urls = [];
    body.split(/\r?\n/).forEach(function (line) {
        line = line.trim();
        if (!line) return;
        if (line.charAt(0) !== '#') { urls.push(absolute(line, base)); return; }
        var uri = /^#EXT-X-(?:MAP|KEY):.*URI="([^"]+)"/.exec(line);
        if (uri && !/^(data|skd):/.test(uri[1])) urls.push(absolute(uri[1], base));
    });
    return urls.filter(function (url, i) { return urls.indexOf(url) === i; });
}

function record(cache, id, data) {
    return cache.put(key(id), new Response(JSON.stringify(data), { headers: { 'Content-Type': 'application/json' } }));
}

function status(id) {
    if (running[id]) return Promise.resolve(running[id].state);
    if (!supported()) return Promise.resolve(null);
    return open().then(function (cache) { return cache.match(key(id)); }).then(function (response) {
        return response ? response.json() : null;
    }).then(function (data) {
        // A record kept by a read this page does not run (another page's, a closed tab's): stopped where it was.
        if (data) data.running = false;
        return data;
    }).catch(function () { return null; });
}

/**
 * Reads the film ahead; resumes where a previous read stopped (what the cache
 * holds is not read again). progress(state) on each segment; the promise ends
 * with the last state, or fails (stopped: an AbortError).
 */
function start(id, src, progress) {
    if (!supported()) return Promise.reject(new Error('unsupported'));
    if (running[id]) { running[id].listeners.push(progress); return running[id].promise; }
    var controller = new AbortController(), signal = controller.signal;
    var job = running[id] = { controller: controller, listeners: [progress], state: { id: id, done: 0, total: 0, bytes: 0, complete: false, running: true } };
    var tell = function () { job.listeners.forEach(function (fn) { if (fn) fn(job.state); }); };
    var master = absolute(src);
    tell();

    job.promise = Promise.all([open(), text(master, signal)]).then(function (got) {
        var cache = got[0], body = got[1], list = variants(body, master);
        var variant = list.length ? choose(list) : { url: master, info: null };
        return (variant.info ? text(variant.url, signal) : Promise.resolve(body)).then(function (media) {
            var urls = segments(media, variant.url);
            var kept = variant.info ? '#EXTM3U\n#EXT-X-INDEPENDENT-SEGMENTS\n' + variant.info + '\n' + variant.url + '\n' : media;
            job.state = { id: id, master: master, variant: variant.url, height: variant.height || null, urls: urls, done: 0, total: urls.length, bytes: 0, complete: false, running: true, at: Date.now() };
            return Promise.all([
                cache.put(master, playlist(kept)),
                variant.info ? cache.put(variant.url, playlist(media)) : null,
                record(cache, id, job.state)
            ]).then(function () {
                tell();
                var next = 0;
                // Three at a time: as the player itself would, without taking the whole line.
                var worker = function () {
                    if (next >= urls.length) return Promise.resolve();
                    var url = urls[next++];
                    return cache.match(url).then(function (have) {
                        if (have) return have.blob().then(function (blob) { return blob.size; });
                        return fetch(url, { mode: 'cors', credentials: 'omit', signal: signal }).then(function (response) {
                            if (!response.ok) throw new Error(url + ': ' + response.status);
                            return response.blob().then(function (blob) {
                                return cache.put(url, new Response(blob, { headers: { 'Content-Type': response.headers.get('Content-Type') || 'application/octet-stream' } })).then(function () { return blob.size; });
                            });
                        });
                    }).then(function (size) {
                        job.state.done++;
                        job.state.bytes += size;
                        tell();
                        if (job.state.done % 10 === 0) record(cache, id, job.state);
                        return worker();
                    });
                };
                return Promise.all([worker(), worker(), worker()]);
            }).then(function () {
                job.state.complete = true;
                job.state.running = false;
                return record(cache, id, job.state);
            });
        });
    }).then(function () {
        delete running[id];
        tell();
        return job.state;
    }, function (error) {
        delete running[id];
        job.state.running = false;
        job.state.error = error && error.name === 'AbortError' ? 'stopped' : 'failed';
        // What was read stays (a later start resumes from there); the record says how far.
        if (job.state.total) open().then(function (cache) { return record(cache, id, job.state); }).catch(function () {});
        tell();
        throw error;
    });
    return job.promise;
}

function stop(id) { if (running[id]) running[id].controller.abort(); }

function remove(id) {
    stop(id);
    if (!supported()) return Promise.resolve();
    return open().then(function (cache) {
        return cache.match(key(id)).then(function (response) { return response ? response.json() : null; }).then(function (data) {
            var urls = data ? [data.master, data.variant].concat(data.urls || []) : [];
            return Promise.all(urls.filter(Boolean).map(function (url) { return cache.delete(url); })).then(function () { return cache.delete(key(id)); });
        });
    });
}

/**
 * hls.js's loader, cache first: what the cache holds is answered from it, the
 * rest goes to hls.js's own loader (config.loader). A byte range is cut from
 * the file kept whole.
 */
function CacheLoader(config) {
    this.inner = new config.loader(config);
    this.stats = this.inner.stats;
    this.context = null;
    this.callbacks = null;
    this.delegated = false;
    this.aborted = false;
}

CacheLoader.prototype.load = function (context, config, callbacks) {
    var self = this;
    self.context = context;
    self.callbacks = callbacks;
    self.delegated = self.aborted = false;
    var network = function () {
        if (self.aborted) return;
        self.delegated = true;
        self.inner.load(context, config, callbacks);
        self.stats = self.inner.stats;
    };
    open().then(function (cache) { return cache.match(context.url, { ignoreVary: true }); }).then(function (response) {
        if (self.aborted) return;
        if (!response) return network();
        return (context.responseType === 'arraybuffer' ? response.arrayBuffer() : response.text()).then(function (data) {
            if (self.aborted) return;
            if (context.responseType === 'arraybuffer' && (context.rangeStart || context.rangeEnd)) data = data.slice(context.rangeStart || 0, context.rangeEnd || undefined);
            var size = typeof data === 'string' ? data.length : data.byteLength;
            var stats = self.stats, now = self.now();
            stats.loading.start = stats.loading.start || now - 1;
            stats.loading.first = stats.loading.end = now;
            stats.loaded = stats.total = size;
            if (callbacks.onProgress) callbacks.onProgress(stats, context, data, null);
            callbacks.onSuccess({ url: context.url, data: data, code: 200 }, stats, context, null);
        });
    }).catch(network);
};

CacheLoader.prototype.now = function () { return window.performance ? performance.now() : Date.now(); };
CacheLoader.prototype.abort = function () {
    this.aborted = true;
    this.stats.aborted = true;
    if (this.delegated) this.inner.abort();
    else if (this.callbacks && this.callbacks.onAbort) this.callbacks.onAbort(this.stats, this.context, null);
};
CacheLoader.prototype.destroy = function () { this.callbacks = null; this.inner.destroy(); };
CacheLoader.prototype.getCacheAge = function () { return this.delegated && this.inner.getCacheAge ? this.inner.getCacheAge() : null; };
CacheLoader.prototype.getResponseHeader = function (name) { return this.delegated && this.inner.getResponseHeader ? this.inner.getResponseHeader(name) : null; };

/** <hls-video>'s config: its playlists and segments read from the cache first. */
function config() { return supported() ? { pLoader: CacheLoader, fLoader: CacheLoader } : {}; }

var Prefetch = { supported: supported, start: start, stop: stop, status: status, remove: remove, config: config, CacheLoader: CacheLoader };

export default Prefetch;
