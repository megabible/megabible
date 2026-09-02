/* ======================================================================
   PERICOPE SCHEMA HARNESS                     tools/pericope-schema-check.mjs
   ----------------------------------------------------------------------
   Locks the Pericope storage schema before any feature depends on it —
   the same "validate the parsing logic outside the framework first" habit
   the importers use.

   It loads the REAL browser file (public/js/pericope-store.js) unmodified,
   inside a Node `vm` sandbox whose global carries an in-memory localStorage
   shim. So this tests the exact code that ships, not a copy.

   Run:  node tools/pericope-schema-check.mjs
   Exit: 0 if every check passes, 1 otherwise.
   ====================================================================== */

import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const STORE_PATH = path.join(__dirname, '..', 'megabible', 'public', 'js', 'pericope-store.js');

/* ---- in-memory localStorage shim (Web Storage semantics) ------------- */
function makeMemoryStorage() {
    let map = new Map();
    return {
        getItem(k) { return map.has(k) ? map.get(k) : null; },
        setItem(k, v) { map.set(String(k), String(v)); },
        removeItem(k) { map.delete(k); },
        clear() { map = new Map(); },
        key(i) { return Array.from(map.keys())[i] ?? null; },
        get length() { return map.size; }
    };
}

/* ---- load the store into a sandbox ----------------------------------- */
function loadStore(storage) {
    const src = fs.readFileSync(STORE_PATH, 'utf8');
    // The sandbox object IS the global inside the vm; the store resolves
    // `root` to globalThis (=== sandbox) and reads root.localStorage.
    const sandbox = { console, localStorage: storage };
    vm.createContext(sandbox);
    vm.runInContext(src, sandbox, { filename: 'pericope-store.js' });
    if (!sandbox.MBPericope) {
        throw new Error('store did not attach MBPericope to the global');
    }
    return sandbox.MBPericope;
}

/* ---- tiny test runner ------------------------------------------------ */
let passed = 0, failed = 0;
const fails = [];
function section(name) { console.log('\n\u2022 ' + name); }
function ok(cond, label) {
    if (cond) { passed++; console.log('   \u2713 ' + label); }
    else { failed++; fails.push(label); console.log('   \u2717 ' + label); }
}
function eq(a, b, label) {
    const same = JSON.stringify(a) === JSON.stringify(b);
    if (!same) { console.log('       expected ' + JSON.stringify(b) + '\n       got      ' + JSON.stringify(a)); }
    ok(same, label);
}

/* ---- a reference-builder that mirrors the Acts feed's refOf() ---------
   NOT part of the store (rendering is a page concern). It exists here only
   to PROVE the store's "store raw osis+chapter, derive display later" split
   is sound: given a card and a bookMeta entry, it must produce "Psalm 151:3".
   Mirrors ActsController/acts feed: name + (chapter + off) [+ single rule]. */
function displayRef(card, meta) {
    const name = meta.name;
    const chapter = card.ch + (meta.off || 0);
    const verses = card.v1 === card.v2 ? String(card.v1) : (card.v1 + '\u2013' + card.v2);
    if (meta.single) { return name + ' ' + verses; }        // "Jude 5"
    return name + ' ' + chapter + ':' + verses;              // "Psalm 151:3"
}

/* ====================================================================== */
console.log('Pericope schema harness — loading', path.relative(process.cwd(), STORE_PATH));

/* 1. create -> list -> get round-trips a board unchanged --------------- */
section('create / list / get round-trip');
{
    const storage = makeMemoryStorage();
    const P = loadStore(storage);

    const board = P.create('Paul');
    ok(!!board, 'create returns a board');
    ok(board.slug === 'paul', 'name "Paul" -> slug "paul"');
    ok(board.layout === 'grid', 'new board defaults to grid layout');
    eq(board.cards, [], 'new board has no cards');

    const listed = P.list();
    eq(listed.length, 1, 'list has one entry');
    ok(listed[0].id === board.id && listed[0].slug === 'paul', 'index entry matches');
    ok(listed[0].cards === 0, 'index stores a CARD COUNT (0), not the cards');

    const fetched = P.get('paul');
    eq(fetched, board, 'get(slug) returns the identical board');
    const byId = P.get(board.id);
    eq(byId, board, 'get(id) returns the identical board too');
}

/* 2. addCards past the hard cap stops and reports overflow ------------- */
section('card hard cap + overflow reporting');
{
    const storage = makeMemoryStorage();
    const P = loadStore(storage);
    const b = P.create('Big');
    const hard = P.CAPS.cardHard;

    function verseCards(n, startTx) {
        const out = [];
        for (let i = 0; i < n; i++) {
            out.push({ type: 'verse', osis: 'Rom', ch: 1, v1: (i % 30) + 1, v2: (i % 30) + 1, tx: startTx, text: 'x' });
        }
        return out;
    }

    const r1 = P.addCards(b.id, verseCards(hard - 5, 'web'));
    ok(r1.added === hard - 5 && r1.rejected === 0, 'adds ' + (hard - 5) + ' with no rejects');

    const r2 = P.addCards(b.id, verseCards(20, 'kjv'));
    ok(r2.added === 5, 'second add tops out at the hard cap (adds 5)');
    ok(r2.rejected === 15, 'reports 15 rejected past the cap');

    const full = P.get(b.id);
    ok(full.cards.length === hard, 'board holds exactly hard-cap cards');

    const r3 = P.addCards(b.id, verseCards(3, 'web'));
    ok(r3.added === 0 && r3.rejected === 3, 'a full board rejects everything');

    const cap = P.capacity(full);
    ok(cap.remaining === 0 && cap.overSoft === true, 'capacity() reports full + over soft');
}

/* 3. validate() repairs a deliberately malformed board ----------------- */
section('validate() repairs untrusted input');
{
    const storage = makeMemoryStorage();
    const P = loadStore(storage);

    const evil = {
        v: 999,
        id: '',                                  // missing -> regenerate
        name: '   ',                             // blank -> "Untitled"
        layout: 'hyperbole',                     // bad enum -> "grid"
        created: 'yesterday',                    // bad -> now()
        cards: [
            { type: 'verse', osis: 'Rom', ch: 8, v1: 30, v2: 28, tx: 'WEB', text: 'ok', bogus: 1 }, // v2<v1, upper tx, junk field
            { id: 'dup', type: 'note', text: 'if a < script > b then <script>alert(1)</script>' },   // angle brackets kept as TEXT
            { id: 'dup', type: 'heading', text: 'Assurance' },                                        // duplicate id -> regen
            { type: 'wormhole', text: 'nope' },                                                       // unknown type -> dropped
            { type: 'verse', ch: 1, v1: 1, v2: 1, tx: 'kjv' }                                         // missing osis -> dropped
        ],
        links: [ { from: 'ghost', to: 'phantom' } ],   // dangling -> dropped
        groups: 'not-an-array'
    };

    const res = P.validate(evil);
    ok(res.ok === true, 'validate reports ok for a repairable object');
    const b = res.board;

    ok(typeof b.id === 'string' && b.id.length >= 6, 'blank id was regenerated');
    ok(b.name === 'Untitled', 'blank name -> "Untitled"');
    ok(b.layout === 'grid', 'bad layout -> "grid"');
    ok(typeof b.created === 'number', 'bad created -> a timestamp');

    ok(b.cards.length === 3, 'kept 3 valid cards (dropped unknown type + missing-osis)');
    const verse = b.cards[0];
    ok(verse.v1 === 28 && verse.v2 === 30, 'swapped v2<v1 into a valid ascending range');
    ok(verse.tx === 'web', 'translation slug lower-cased');
    ok(!('bogus' in verse), 'unknown card field dropped');

    const ids = b.cards.map(c => c.id);
    ok(new Set(ids).size === ids.length, 'duplicate card ids were made unique');

    const note = b.cards.find(c => c.type === 'note');
    ok(/<script>/.test(note.text), 'angle-bracket text PRESERVED verbatim (render layer escapes, not the store)');

    eq(b.links, [], 'dangling link dropped');
    eq(b.groups, [], 'non-array groups -> empty');

    ok(res.dropped.length >= 3, 'dropped[] itemizes what was removed (' + res.dropped.length + ' items)');
}

/* 4. Five-Psalms card stores RAW osis+chapter; display derived at read - */
section('raw-store / derived-display split (Five Psalms of David)');
{
    const storage = makeMemoryStorage();
    const P = loadStore(storage);

    // A fixture bookMeta entry the way BookMetadata::displayMeta() would emit
    // it for a reader-label override book: display name "Psalm", +150 offset,
    // NOT single (override books always show a computed number). The osis id
    // here is a stand-in — the test is about the mechanism, not the real id.
    const meta = { name: 'Psalm', slug: 'five-psalms-of-david', off: 150, single: false, short: null };

    const b = P.create('Psalm study');
    // The card stores the RAW chapter (1) and osis — NEVER "Psalm 151".
    P.addCards(b.id, [{ type: 'verse', osis: 'Ps151', ch: 1, v1: 3, v2: 3, tx: 'web', text: '…' }]);
    const card = P.get(b.id).cards[0];

    ok(card.ch === 1, 'card stores RAW chapter 1, not display 151');
    ok(card.osis === 'Ps151', 'card stores osis, not a display string');
    ok(!('ref' in card) && !('display' in card), 'no display string is stored on the card');

    // The derivation (a render-time concern) yields the display reference.
    eq(displayRef(card, meta), 'Psalm 151:3', 'derives "Psalm 151:3" from raw card + bookMeta');

    // And a single-chapter book with no override prints without a chapter.
    const jude = { name: 'Jude', slug: 'jude', off: 0, single: true, short: null };
    const jcard = { osis: 'Jude', ch: 1, v1: 5, v2: 5 };
    eq(displayRef(jcard, jude), 'Jude 5', 'single-chapter book derives "Jude 5" (no chapter)');
}

/* 5. slug uniqueness + reserved-word rejection ------------------------- */
section('slug uniqueness + reserved words');
{
    const storage = makeMemoryStorage();
    const P = loadStore(storage);

    const a = P.create('Paul');
    const b = P.create('Paul');
    const c = P.create('Paul');
    ok(a.slug === 'paul', 'first "Paul" -> paul');
    ok(b.slug === 'paul-2', 'second "Paul" -> paul-2');
    ok(c.slug === 'paul-3', 'third "Paul" -> paul-3');

    const shared = P.create('shared');
    ok(shared.slug !== 'shared', 'reserved "shared" is never assigned (got "' + shared.slug + '")');
    const verses = P.create('verses');
    ok(verses.slug !== 'verses', 'reserved "verses" is never assigned (got "' + verses.slug + '")');

    // rename re-slugs when the target is free, and dedupes when it isn't.
    const d = P.rename(c.id, 'Grace');
    ok(d.slug === 'grace', 'rename to a free name re-slugs to "grace"');
    const e = P.rename(b.id, 'Grace');
    ok(e.slug === 'grace-2', 'rename to a taken name dedupes to "grace-2"');
}

/* 6. mutations: reorder / updateCard / removeCard ---------------------- */
section('reorder / updateCard / removeCard');
{
    const storage = makeMemoryStorage();
    const P = loadStore(storage);
    const b = P.create('Ops');
    P.addCards(b.id, [
        { type: 'verse', osis: 'Jhn', ch: 3, v1: 16, v2: 16, tx: 'kjv', text: 'a' },
        { type: 'note', text: 'first note' },
        { type: 'heading', text: 'Title' }
    ]);
    let board = P.get(b.id);
    const [c0, c1, c2] = board.cards.map(c => c.id);

    board = P.reorder(b.id, [c2, c0, c1]);
    eq(board.cards.map(c => c.id), [c2, c0, c1], 'reorder puts cards in requested order');

    board = P.reorder(b.id, [c1]);   // partial order: rest keep relative order
    eq(board.cards.map(c => c.id), [c1, c2, c0], 'partial reorder appends the unlisted in prior order');

    board = P.updateCard(b.id, c1, { text: 'edited note', bogus: 'x', type: 'verse', id: 'hijack' });
    const edited = board.cards.find(c => c.id === c1);
    ok(edited.text === 'edited note', 'updateCard applies whitelisted patch');
    ok(edited.type === 'note', 'updateCard cannot change a card type');
    ok(edited.id === c1, 'updateCard cannot hijack a card id');
    ok(!('bogus' in edited), 'updateCard drops unknown fields');

    board = P.removeCard(b.id, c0);
    ok(!board.cards.some(c => c.id === c0), 'removeCard drops the card');
    ok(P.list().find(x => x.id === b.id).cards === 2, 'index card-count follows removal');
}

/* 7. per-board isolation: one write never rewrites another ------------- */
section('per-board storage isolation');
{
    const storage = makeMemoryStorage();
    const P = loadStore(storage);
    const a = P.create('A');
    const b = P.create('B');
    P.addCards(a.id, [{ type: 'note', text: 'in A' }]);

    // B's stored blob is untouched by A's write: its key still holds an
    // empty-cards board.
    const rawB = storage.getItem(P.BOARD_PREFIX + b.id);
    const parsedB = JSON.parse(rawB);
    eq(parsedB.cards, [], "writing board A leaves board B's blob untouched");

    // Distinct storage keys per board (the whole point of the two-tier layout).
    ok(storage.getItem(P.BOARD_PREFIX + a.id) !== null, 'board A has its own key');
    ok(storage.getItem(P.BOARD_PREFIX + b.id) !== null, 'board B has its own key');
}

/* 8. export / import envelope round-trip ------------------------------- */
section('export / import envelope');
{
    const storage = makeMemoryStorage();
    const P = loadStore(storage);
    const b = P.create('Share me');
    P.addCards(b.id, [{ type: 'verse', osis: 'Rom', ch: 8, v1: 28, v2: 30, tx: 'web', text: 'And we know…' }]);

    const payload = P.exportBoard(b.slug);
    ok(payload.app === 'megabible' && payload.kind === 'pericope', 'export envelope carries app/kind');
    ok(payload.version === P.SCHEMA_VERSION, 'export carries schema version');
    ok(typeof payload.exported_at === 'string', 'export carries an ISO timestamp');

    const back = P.importPayload(payload);
    ok(back.ok === true, 'importPayload accepts our own export');
    ok(back.board.cards.length === 1 && back.board.cards[0].osis === 'Rom', 'imported board keeps its card');

    const junk = P.importPayload({ app: 'notus', kind: 'whatever' });
    ok(junk.ok === false, 'importPayload rejects a non-pericope file');
}

/* ---- verdict --------------------------------------------------------- */
console.log('\n' + '\u2500'.repeat(52));
console.log('  ' + passed + ' passed, ' + failed + ' failed');
if (failed) {
    console.log('  FAILED:');
    fails.forEach(f => console.log('   - ' + f));
}
console.log('\u2500'.repeat(52));
process.exit(failed ? 1 : 0);
