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

// books シートを初めて作るときに入れる初期候補（index.html の SEED_BOOKS と同じ内容）
const SEED_BOOKS = [
  ["seed01", "どんな仕事も効率アップ！ 公務員の生成AI超活用術", "学陽書房", 2530, "議会答弁、住民アンケート、広報チラシ。現役自治体職員が「うちの仕事」で使う手順を書いた一冊。QommonsAIに一番近い。", "初期候補", "https://www.gakuyo.co.jp/book/b10159129.html"],
  ["seed02", "図書館員のための生成AI入門 しくみを知り、活かすために", "中島玲子（JLA図書館実践シリーズ）", 2420, "図書館の現場向けに、生成AIのしくみと使いどころを107ページに凝縮。薄いので回覧しやすい。", "初期候補", "https://www.jla.or.jp/publications/978-4-8204-2602-8/"],
  ["seed03", "大規模言語モデルは新たな知能か ChatGPTが変えた世界", "岡野原大輔（岩波科学ライブラリー）", 1540, "「なぜ答えられるのか」を第一線の研究者が平易に。仕組みを一度ちゃんと知っておきたい人へ。", "初期候補", "https://www.kinokuniya.co.jp/f/dsg-01-9784000297196"],
  ["seed04", "生成AIで世界はこう変わる", "今井翔太（SB新書）", 1100, "仕事、教育、創作はどう変わるか。新書1冊で全体像をつかむ最短ルート。", "初期候補", "https://www.kinokuniya.co.jp/f/dsg-01-9784815622978"],
  ["seed05", "ChatGPTの頭の中", "スティーヴン・ウルフラム（ハヤカワ新書）", 1012, "Mathematicaの作者が「次の単語を当てるだけ」の正体を168ページで解剖。薄くて深い。", "初期候補", "https://www.kinokuniya.co.jp/f/dsg-01-9784153400092"],
  ["seed06", "生成AIの核心 「新しい知」といかに向き合うか", "西田宗千佳（NHK出版新書）", 1023, "技術の話より「どう付き合うか」。使えない現場にいる人こそ、距離感を決めるために。", "初期候補", "https://www.kinokuniya.co.jp/f/dsg-01-9784140887059"],
  ["seed07", "画像生成AIと著作権について知っておきたい50の質問", "ニャタBE・花井裕也・谷直樹（オーム社）", 1980, "イラストレーター、エンジニア、弁護士の3人がQ&Aで議論。著作権の相談を受ける側の予習に。", "初期候補", "https://www.kinokuniya.co.jp/f/dsg-01-9784274231179"],
  ["seed08", "生成AIで爆速！ ChatGPT仕事術", "鈴木眞里子（日経文庫ビジュアル）", 1100, "文書作成、要約、企画立案のプロンプト例をビジュアルで。明日から真似できる即効型。", "初期候補", "https://bookplus.nikkei.com/atcl/catalog/25/09/01/02171/"],
  ["seed09", "AI白書 2025 生成AIエディション", "東京大学 松尾・岩澤研究室 監修（角川アスキー総合研究所）", 4400, "予算の4割を食う重量級。でも国内の動向と事例が1冊に揃う。班の共有本棚に置く1冊として。", "初期候補", "https://www.kinokuniya.co.jp/f/dsg-01-9784049112382"]
];

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
    if (name === 'books') {
      const now = new Date().toISOString();
      SEED_BOOKS.forEach(function (b) { sh.appendRow(b.concat([now])); });
    }
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
