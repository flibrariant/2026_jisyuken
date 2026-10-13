/**
 * QommonsAI自主研「1万円クラファン」バックエンド（Google Apps Script）
 *
 * 設置手順は README.md を参照。
 * スプレッドシートに books / pledges の2シートを自動で作ります。
 */

const SETTINGS = {
  budget: 10000,   // 本物の予算（円）
  coins: 3000,     // 一人あたりの仮想コイン（円）
  deadline: '',    // 例: '2026-11-07' 空なら締め切りなし
  maxObi: 100      // 帯文の最大文字数
};

const BOOK_HEADERS = ['id', 'title', 'author', 'price', 'obi', 'proposer', 'url', 'createdAt'];
const PLEDGE_HEADERS = ['bookId', 'name', 'amount', 'updatedAt'];

function doGet() {
  return json(state());
}

function doPost(e) {
  const lock = LockService.getScriptLock();
  lock.waitLock(10000);
  try {
    const body = JSON.parse(e.postData.contents || '{}');
    const action = String(body.action || '');
    if (action === 'addBook') addBook(body);
    else if (action === 'pledge') pledge(body);
    else if (action === 'withdraw') withdraw(body);
    else throw new Error('不明な操作です');
    return json(state());
  } catch (err) {
    return json({ error: String(err.message || err) });
  } finally {
    lock.releaseLock();
  }
}

function state() {
  return {
    settings: SETTINGS,
    books: rows('books', BOOK_HEADERS).map(function (r) {
      r.price = Number(r.price) || 0;
      return r;
    }),
    pledges: rows('pledges', PLEDGE_HEADERS)
      .map(function (r) { r.amount = Number(r.amount) || 0; return r; })
      .filter(function (r) { return r.amount > 0; })
  };
}

function addBook(body) {
  const title = clean(body.title, 80);
  const author = clean(body.author, 60);
  const obi = clean(body.obi, SETTINGS.maxObi);
  const proposer = clean(body.name, 30);
  const url = clean(body.url, 300);
  const price = Math.round(Number(body.price));
  if (!title) throw new Error('タイトルを入れてください');
  if (!proposer) throw new Error('名前を入れてください');
  if (!(price > 0)) throw new Error('価格を入れてください');
  if (!obi) throw new Error('帯文を入れてください');
  const id = Utilities.getUuid().slice(0, 8);
  sheet('books', BOOK_HEADERS).appendRow([id, title, author, price, obi, proposer, url, new Date().toISOString()]);
}

function pledge(body) {
  const name = clean(body.name, 30);
  const bookId = clean(body.bookId, 20);
  const amount = Math.round(Number(body.amount));
  if (!name) throw new Error('名前を入れてください');
  if (!(amount > 0)) throw new Error('出資額を入れてください');
  const books = rows('books', BOOK_HEADERS);
  if (!books.some(function (b) { return b.id === bookId; })) throw new Error('その本は見つかりません');
  const pledges = rows('pledges', PLEDGE_HEADERS);
  const used = pledges
    .filter(function (p) { return p.name === name; })
    .reduce(function (s, p) { return s + (Number(p.amount) || 0); }, 0);
  if (used + amount > SETTINGS.coins) {
    throw new Error('コインが足りません（残り ' + (SETTINGS.coins - used) + ' 円）');
  }
  upsertPledge(bookId, name, function (current) { return current + amount; });
}

function withdraw(body) {
  const name = clean(body.name, 30);
  const bookId = clean(body.bookId, 20);
  if (!name) throw new Error('名前を入れてください');
  upsertPledge(bookId, name, function () { return 0; });
}

function upsertPledge(bookId, name, fn) {
  const sh = sheet('pledges', PLEDGE_HEADERS);
  const values = sh.getDataRange().getValues();
  for (var i = 1; i < values.length; i++) {
    if (String(values[i][0]) === bookId && String(values[i][1]) === name) {
      const next = fn(Number(values[i][2]) || 0);
      sh.getRange(i + 1, 3, 1, 2).setValues([[next, new Date().toISOString()]]);
      return;
    }
  }
  sh.appendRow([bookId, name, fn(0), new Date().toISOString()]);
}

function sheet(name, headers) {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  var sh = ss.getSheetByName(name);
  if (!sh) {
    sh = ss.insertSheet(name);
    sh.appendRow(headers);
  }
  return sh;
}

function rows(name, headers) {
  const values = sheet(name, headers).getDataRange().getValues();
  const out = [];
  for (var i = 1; i < values.length; i++) {
    if (!String(values[i][0])) continue;
    const obj = {};
    headers.forEach(function (h, j) { obj[h] = values[i][j] === undefined ? '' : String(values[i][j]); });
    out.push(obj);
  }
  return out;
}

function clean(value, max) {
  return String(value == null ? '' : value).replace(/\s+/g, ' ').trim().slice(0, max);
}

function json(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj)).setMimeType(ContentService.MimeType.JSON);
}
