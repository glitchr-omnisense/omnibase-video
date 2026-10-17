// Reading a film ahead (assets/prefetch.js), against a Cache API and a network of its own.
//   node --test tests/Player/prefetch.test.mjs
import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';

const MASTER = 'https://site.test/v/film/master.m3u8';
const files = {
    [MASTER]: '#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=800000,AVERAGE-BANDWIDTH=700000,RESOLUTION=1024x576\nhttps://cdn.test/576/chunklist.m3u8\n#EXT-X-STREAM-INF:BANDWIDTH=6000000,RESOLUTION=1280x720\nhttps://cdn.test/720/chunklist.m3u8\n#EXT-X-STREAM-INF:BANDWIDTH=9000000,RESOLUTION=1920x1080\nhttps://cdn.test/1080/chunklist.m3u8\n',
    'https://cdn.test/720/chunklist.m3u8': '#EXTM3U\n#EXT-X-TARGETDURATION:11\n#EXT-X-MAP:URI="init.mp4"\n#EXTINF:10,\nmedia_0.ts\n#EXTINF:10,\nmedia_1.ts\n#EXTINF:10,\nmedia_2.ts\n#EXT-X-ENDLIST\n',
    'https://cdn.test/720/init.mp4': 'I',
    'https://cdn.test/720/media_0.ts': 'AAAA',
    'https://cdn.test/720/media_1.ts': 'BBBB',
    'https://cdn.test/720/media_2.ts': 'CCCC',
};
let asked = [];
let store = new Map();

globalThis.location = new URL('https://site.test/v/film');
globalThis.window = globalThis;
globalThis.isSecureContext = true;
globalThis.screen = { height: 900 };
globalThis.innerHeight = 800;
globalThis.devicePixelRatio = 1;
globalThis.fetch = async (url, init = {}) => {
    if (init.signal?.aborted) throw Object.assign(new Error('aborted'), { name: 'AbortError' });
    asked.push(url);
    return url in files ? new Response(files[url], { headers: { 'Content-Type': 'application/octet-stream' } }) : new Response('', { status: 404 });
};
globalThis.caches = {
    open: async () => ({
        match: async (url) => (store.has(String(url)) ? store.get(String(url)).clone() : undefined),
        put: async (url, response) => { store.set(String(url), new Response(await response.arrayBuffer(), { headers: response.headers })); },
        delete: async (url) => store.delete(String(url)),
        keys: async () => [...store.keys()].map((url) => ({ url })),
    }),
};

const { default: Prefetch } = await import('../../assets/prefetch.js');

beforeEach(() => { store = new Map(); asked = []; });

test('the best quality the screen shows is read, its master kept with that quality alone', async () => {
    const states = [];
    const end = await Prefetch.start('7', '/v/film/master.m3u8', (s) => states.push(s.done));
    assert.equal(end.complete, true);
    assert.equal(end.height, 720, '1080 lines are more than a 900 px screen shows');
    assert.deepEqual(end.urls, ['https://cdn.test/720/init.mp4', 'https://cdn.test/720/media_0.ts', 'https://cdn.test/720/media_1.ts', 'https://cdn.test/720/media_2.ts']);
    assert.equal(end.bytes, 13);
    assert.ok(states.includes(4));
    const master = await (await caches.open()).match(MASTER).then((r) => r.text());
    assert.match(master, /RESOLUTION=1280x720\nhttps:\/\/cdn\.test\/720\/chunklist\.m3u8\n$/);
    assert.doesNotMatch(master, /576|1080/);
    assert.ok(!asked.some((url) => url.includes('/1080/') || url.includes('/576/')));
});

test('a read stopped resumes where it was, without reading again what it holds', async () => {
    await Prefetch.start('7', MASTER, () => {});
    store.delete('https://cdn.test/720/media_2.ts');
    asked = [];
    const end = await Prefetch.start('7', MASTER, () => {});
    assert.equal(end.complete, true);
    assert.deepEqual(asked.filter((url) => url.endsWith('.ts')), ['https://cdn.test/720/media_2.ts']);
});

test('a record kept by another page is a read stopped, not one running', async () => {
    const cache = await caches.open();
    await cache.put('https://site.test/video-prefetch/9', new Response(JSON.stringify({ id: '9', done: 3, total: 10, running: true })));
    const state = await Prefetch.status('9');
    assert.equal(state.running, false);
    assert.equal(state.done, 3);
});

test('stopped before anything is read, nothing is kept', async () => {
    const promise = Prefetch.start('8', MASTER, () => {});
    Prefetch.stop('8');
    await assert.rejects(promise, { name: 'AbortError' });
    assert.equal(store.size, 0);
});

test('removed, the film and its record leave the cache; another film stays', async () => {
    await Prefetch.start('7', MASTER, () => {});
    await (await caches.open()).put('https://other.test/a.ts', new Response('x'));
    await Prefetch.remove('7');
    assert.deepEqual([...store.keys()], ['https://other.test/a.ts']);
    assert.equal(await Prefetch.status('7'), null);
});

test("hls.js's loaders answer from the cache, a byte range cut from the file, the rest from hls.js's own loader", async () => {
    await Prefetch.start('7', MASTER, () => {});
    const network = [];
    function Inner() { this.stats = { loading: { start: 0, first: 0, end: 0 }, loaded: 0, total: 0, aborted: false }; }
    Inner.prototype.load = (context, config, callbacks) => { network.push(context.url); callbacks.onSuccess({ url: context.url, data: 'net' }, {}, context, null); };
    Inner.prototype.abort = Inner.prototype.destroy = () => {};
    const config = Prefetch.config();
    const load = (url, responseType, range = {}) => new Promise((resolve) => {
        new config.fLoader({ loader: Inner }).load({ url, responseType, ...range }, {}, { onSuccess: (response, stats) => resolve({ response, stats }) });
    });

    const segment = await load('https://cdn.test/720/media_1.ts', 'arraybuffer');
    assert.equal(new TextDecoder().decode(segment.response.data), 'BBBB');
    assert.equal(segment.stats.loaded, 4);
    const part = await load('https://cdn.test/720/media_2.ts', 'arraybuffer', { rangeStart: 1, rangeEnd: 3 });
    assert.equal(new TextDecoder().decode(part.response.data), 'CC');
    assert.equal((await load(MASTER, 'text')).response.data.includes('1280x720'), true);
    assert.deepEqual(network, []);
    assert.equal((await load('https://cdn.test/720/media_9.ts', 'arraybuffer')).response.data, 'net');
    assert.deepEqual(network, ['https://cdn.test/720/media_9.ts']);
});
