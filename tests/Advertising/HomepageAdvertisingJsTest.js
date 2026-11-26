"use strict";

const assert = require("node:assert/strict");
const { readFileSync } = require("node:fs");
const path = require("node:path");
const test = require("node:test");
const { runInNewContext } = require("node:vm");

const script = readFileSync(
    path.join(__dirname, "../../views/js/homepage-advertising.js"),
    "utf8",
);

function fixture({ mode = "desktop", loading = false } = {}) {
    const events = new EventTarget();
    const appends = [];
    const openings = [];
    const classes = new Set();
    const expanded = [];
    const footer = { children: [] };
    const body = {
        children: [],
        appendChild(node) {
            appends.push(node);
            node.parentNode.children.splice(node.parentNode.children.indexOf(node), 1);
            this.children.push(node);
            node.parentNode = this;
            return node;
        },
    };
    const toggle = {
        controls: "unipayment-advertising-panel",
        setAttribute(name, value) {
            assert.equal(name, "aria-expanded");
            expanded.push(value);
        },
    };
    const panel = {
        id: toggle.controls,
        classList: {
            toggle(name) {
                if (classes.delete(name)) {
                    return false;
                }
                classes.add(name);
                return true;
            },
        },
    };
    const url = "https://example.test/credit";
    const open = {
        getAttribute(name) {
            assert.equal(name, "data-unipayment-advertising-open");
            return url;
        },
    };
    const root = {
        parentNode: footer,
        children: mode === "mobile" ? [open] : [toggle, panel],
    };
    let advertising = null;
    let fallback;
    let loadCalls = 0;
    const document = {
        body: loading ? null : body,
        readyState: loading ? "loading" : "complete",
        querySelector(selector) {
            assert.equal(selector, "[data-unipayment-advertising]");
            return advertising;
        },
        getElementById(id) {
            return advertising && mode === "desktop" && id === panel.id ? panel : null;
        },
        addEventListener(type, handler, options) {
            if (type === "DOMContentLoaded") {
                fallback = handler;
                events.addEventListener(type, () => {
                    loadCalls++;
                    handler();
                }, options);
                return;
            }
            events.addEventListener(type, handler, options);
        },
    };

    function mount(parent = footer) {
        advertising = root;
        root.parentNode = parent;
        parent.children.push(root);
    }

    if (!loading && mode !== "absent") {
        mount();
    }

    return {
        body, footer, root, toggle, panel, appends, classes, expanded, openings, url,
        mount,
        start() {
            runInNewContext(script, {
                document,
                window: { open: (...args) => openings.push(args) },
            });
        },
        ready() {
            document.body = body;
            document.readyState = "interactive";
            events.dispatchEvent(new Event("DOMContentLoaded"));
        },
        get loadCalls() {
            return loadCalls;
        },
        retryRelocation() {
            return fallback();
        },
        click() {
            const event = new Event("click");
            Object.defineProperty(event, "target", {
                value: {
                    closest(selector) {
                        if (!advertising) {
                            return null;
                        }
                        if (selector === "[data-unipayment-advertising-toggle]" && mode === "desktop") {
                            return toggle;
                        }
                        if (selector === "[data-unipayment-advertising-open]" && mode === "mobile") {
                            return open;
                        }
                        return null;
                    },
                },
            });
            events.dispatchEvent(event);
        },
    };
}

test("T01 moves the existing container to body", () => {
    const dom = fixture();
    dom.start();
    assert.equal(dom.root.parentNode, dom.body);
    assert.equal(dom.body.children[0], dom.root);
    assert.deepEqual(dom.footer.children, []);
});

test("T02 preserves descendant identity and the panel reference", () => {
    const dom = fixture();
    dom.start();
    assert.equal(dom.appends[0], dom.root);
    assert.equal(dom.root.children[0], dom.toggle);
    assert.equal(dom.root.children[1], dom.panel);
    assert.equal(dom.toggle.controls, dom.panel.id);
});

test("T03 handles a missing container", () => {
    const dom = fixture({ mode: "absent" });
    assert.doesNotThrow(() => dom.start());
    assert.deepEqual(dom.appends, []);
});

test("T04 skips a container already attached directly to body", () => {
    const dom = fixture({ loading: true });
    dom.ready();
    dom.mount(dom.body);
    dom.start();
    assert.equal(dom.root.parentNode, dom.body);
    assert.deepEqual(dom.appends, []);
});

test("T05 repeating relocation leaves a single move", () => {
    const dom = fixture({ loading: true });
    dom.start();
    dom.mount();
    dom.ready();
    assert.equal(dom.retryRelocation(), true);
    assert.equal(dom.appends.length, 1);
});

test("T06 retries once when the container or body is unavailable", () => {
    for (const mountBeforeStart of [false, true]) {
        const dom = fixture({ loading: true });
        if (mountBeforeStart) {
            dom.mount();
        }
        dom.start();
        assert.deepEqual(dom.appends, []);
        if (!mountBeforeStart) {
            dom.mount();
        }
        dom.ready();
        dom.ready();
        assert.equal(dom.loadCalls, 1);
        assert.equal(dom.root.parentNode, dom.body);
        assert.equal(dom.appends.length, 1);
    }
});

test("T07 handles a container still missing at DOMContentLoaded", () => {
    const dom = fixture({ mode: "absent", loading: true });
    dom.start();
    dom.ready();
    assert.equal(dom.loadCalls, 1);
    assert.deepEqual(dom.appends, []);
});

test("T08 opens the desktop panel and updates aria-expanded once", () => {
    const dom = fixture();
    dom.start();
    dom.click();
    assert.equal(dom.classes.has("is-visible"), true);
    assert.deepEqual(dom.expanded, ["true"]);
});

test("T09 closes the desktop panel on the next click", () => {
    const dom = fixture();
    dom.start();
    dom.click();
    dom.click();
    assert.equal(dom.classes.has("is-visible"), false);
    assert.deepEqual(dom.expanded, ["true", "false"]);
});

test("T10 opens the configured mobile URL once with the existing options", () => {
    const dom = fixture({ mode: "mobile" });
    dom.start();
    dom.click();
    assert.deepEqual(dom.openings, [[dom.url, "_blank", "noopener,noreferrer"]]);
});

test("T11 leaves unrelated markup and clicks alone when the ad is absent", () => {
    const dom = fixture({ mode: "absent" });
    const originalChild = { parentNode: dom.body };
    dom.body.children.push(originalChild);
    dom.start();
    dom.click();
    assert.equal(dom.body.children.length, 1);
    assert.equal(dom.body.children[0], originalChild);
    assert.deepEqual(dom.appends, []);
    assert.deepEqual(dom.expanded, []);
    assert.deepEqual(dom.openings, []);
});
