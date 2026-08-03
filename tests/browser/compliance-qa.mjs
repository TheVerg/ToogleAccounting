import fs from 'node:fs';

const base = process.env.QA_BASE_URL ?? 'http://127.0.0.1:8000';
const email = process.env.QA_EMAIL;
const password = process.env.QA_PASSWORD;
const port = process.env.CDP_PORT ?? '9223';
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

let version;
for (let i = 0; i < 40; i++) {
    try { version = await fetch(`http://127.0.0.1:${port}/json/version`).then((r) => r.json()); break; } catch { await sleep(250); }
}
if (!version) throw new Error('Chrome DevTools endpoint did not start.');

const target = await fetch(`http://127.0.0.1:${port}/json/new?${encodeURIComponent(base + '/login')}`, { method: 'PUT' }).then((r) => r.json());
const ws = new WebSocket(target.webSocketDebuggerUrl);
await new Promise((resolve, reject) => { ws.onopen = resolve; ws.onerror = reject; });

let id = 0;
const pending = new Map();
const errors = [];
const responses = [];
ws.onmessage = ({ data }) => {
    const message = JSON.parse(data);
    if (message.id && pending.has(message.id)) {
        const { resolve, reject } = pending.get(message.id); pending.delete(message.id);
        message.error ? reject(new Error(message.error.message)) : resolve(message.result);
    }
    if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.text);
    if (message.method === 'Log.entryAdded' && ['error', 'warning'].includes(message.params.entry.level)) errors.push(message.params.entry.text);
    if (message.method === 'Network.responseReceived' && message.params.response.url.startsWith(base)) responses.push([message.params.response.status, message.params.response.url]);
};
const send = (method, params = {}) => new Promise((resolve, reject) => {
    const callId = ++id; pending.set(callId, { resolve, reject }); ws.send(JSON.stringify({ id: callId, method, params }));
});
const evaluate = async (expression) => (await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true })).result.value;
const navigate = async (url) => { await send('Page.navigate', { url }); await sleep(1600); };
const screenshot = async (name) => {
    const result = await send('Page.captureScreenshot', { format: 'png', fromSurface: true, captureBeyondViewport: false });
    fs.writeFileSync(`tests/browser/${name}.png`, Buffer.from(result.data, 'base64'));
};

await send('Page.enable'); await send('Runtime.enable'); await send('Log.enable'); await send('Network.enable');
await navigate(base + '/login');
await evaluate(`(() => {
    const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set;
    const set = (selector, value) => { const el = document.querySelector(selector); if (!el) throw new Error('Missing ' + selector); setter.call(el, value); el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); };
    set('input[type=email]', ${JSON.stringify(email)}); set('input[type=password]', ${JSON.stringify(password)});
    document.querySelector('form').requestSubmit(); return true;
})()`);
await sleep(2500);
const loginResult = await evaluate(`({ url: location.href, email: document.querySelector('input[type=email]')?.value, passwordLength: document.querySelector('input[type=password]')?.value?.length, text: document.body.innerText.slice(0, 400) })`);
await navigate(base + '/compliance');

const inspect = () => evaluate(`(() => ({
    url: location.href,
    title: document.title,
    heading: document.querySelector('h1')?.textContent?.trim(),
    bodyText: document.body.innerText.slice(0, 500),
    viewport: [innerWidth, innerHeight],
    documentWidth: [document.documentElement.clientWidth, document.documentElement.scrollWidth],
    overflowing: [...document.querySelectorAll('body *')].filter(e => e.scrollWidth > e.clientWidth + 2 && getComputedStyle(e).overflowX === 'visible').slice(0, 10).map(e => e.tagName + '.' + e.className),
    buttons: [...document.querySelectorAll('button')].map(e => e.textContent.trim()).filter(Boolean).slice(0, 30)
}))()`);

await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
await sleep(400); const desktop = await inspect(); await screenshot('compliance-desktop');
await evaluate(`([...document.querySelectorAll('button')].find(e => e.textContent.includes('Exceptions'))?.click(), true)`); await sleep(300); const exceptions = await inspect(); await screenshot('compliance-exceptions');
await evaluate(`([...document.querySelectorAll('button')].find(e => e.textContent.includes('Calendar'))?.click(), true)`); await sleep(300); const calendar = await inspect(); await screenshot('compliance-calendar');
await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
await sleep(500); const mobile = await inspect(); await screenshot('compliance-mobile');

await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
await navigate(base + '/compliance/accountant');
const accountant = await inspect();
await screenshot('compliance-accountant');

console.log(JSON.stringify({ loginResult, desktop, exceptions, calendar, mobile, accountant, errors, responses }, null, 2));
ws.close();
