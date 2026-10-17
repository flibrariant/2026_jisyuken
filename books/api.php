<?php
/**
 * 本の銀河 バックエンド（PHP 1ファイル、SQLite）
 *
 * 置き方: books/ フォルダごとサーバーにアップロードするだけ。
 * data/ フォルダに SQLite とキャッシュした表紙画像が溜まる（PHP から書き込める必要がある）。
 *
 * GET  api.php                 → 状態（本と「読みたい」の一覧）
 * GET  api.php?lookup=書名     → 書誌の候補（Google Books → openBD で補完）
 * GET  api.php?cover=本のID    → 表紙画像（同一オリジンで返すので WebGL のテクスチャに使える）
 * POST api.php  {"action":"vote"|"unvote"|"addBook", ...}  → 状態
 */

declare(strict_types=1);

const HEARTS = 0;             // 一人あたりの「読みたい」数（0 = 何冊でも）
const MAX_DESC = 800;         // 内容紹介の最大文字数
const MAX_OBI = 100;          // 一言の最大文字数
const RESOLVE_PER_REQUEST = 2; // 1リクエストあたり、表紙や定価を補完する本の数

$dataDir = __DIR__ . '/data';

/**
 * Google Books API キー（任意）。無くても動くが、キー無しだと Google 側の共有枠で 429 になりやすい。
 * 置き方: 環境変数 GOOGLE_BOOKS_API_KEY、または data/google_books_key.txt に1行で書く（data/ は外から読めない）。
 */
function googleKey(): string {
    static $key = null;
    if ($key === null) {
        $key = trim((string)getenv('GOOGLE_BOOKS_API_KEY'));
        if ($key === '') {
            $f = __DIR__ . '/data/google_books_key.txt';
            $key = is_file($f) ? trim((string)@file_get_contents($f)) : '';
        }
    }
    return $key;
}

/** data/ のファイルか環境変数から秘密のキーを読む（無ければ空） */
function secret(string $env, string $file): string {
    static $cache = [];
    if (!isset($cache[$file])) {
        $v = trim((string)getenv($env));
        if ($v === '') { $f = __DIR__ . '/data/' . $file; $v = is_file($f) ? trim((string)@file_get_contents($f)) : ''; }
        $cache[$file] = $v;
    }
    return $cache[$file];
}
/** 楽天ブックス API のアプリ ID（数字）: 環境変数 RAKUTEN_APP_ID か data/rakuten_app_id.txt */
function rakutenId(): string { return secret('RAKUTEN_APP_ID', 'rakuten_app_id.txt'); }
/** 楽天ブックス API のアクセスキー（pk_… で始まる。新仕様ではアプリ ID と両方必要）: 環境変数 RAKUTEN_ACCESS_KEY か data/rakuten_access_key.txt */
function rakutenKey(): string { return secret('RAKUTEN_ACCESS_KEY', 'rakuten_access_key.txt'); }
/** Claude API キー（任意。紹介文がどこにも無い本の紹介文を生成する）: 環境変数 ANTHROPIC_API_KEY か data/anthropic_key.txt */
function anthropicKey(): string { return secret('ANTHROPIC_API_KEY', 'anthropic_key.txt'); }

if (!is_dir($dataDir)) @mkdir($dataDir, 0755, true);
if (!is_dir($dataDir . '/covers')) @mkdir($dataDir . '/covers', 0755, true);
if (!file_exists($dataDir . '/.htaccess')) @file_put_contents($dataDir . '/.htaccess', "Require all denied\n");

header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

try {
    $db = openDb($dataDir . '/books.sqlite');
    seedIfEmpty($db);

    if (isset($_GET['cover'])) { serveCover($db, (string)$_GET['cover'], $dataDir . '/covers'); exit; }
    if (isset($_GET['lookup'])) { json(['candidates' => lookup((string)$_GET['lookup'])]); exit; }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
        $action = (string)($body['action'] ?? '');
        if ($action === 'vote') vote($db, $body, true);
        elseif ($action === 'unvote') vote($db, $body, false);
        elseif ($action === 'addBook') addBook($db, $body);
        else throw new RuntimeException('不明な操作です');
        json(state($db));
    } else {
        // 先に応答を返してから、裏で表紙・定価の補完をする（外部 API が遅くても画面を待たせない）。
        // PHP-FPM 以外（CLI など）では fastcgi_finish_request が無いので、その場合は先に補完する
        if (function_exists('fastcgi_finish_request')) {
            json(state($db));
            fastcgi_finish_request();
            resolveSome($db);
        } else {
            resolveSome($db);
            json(state($db));
        }
    }
} catch (Throwable $e) {
    http_response_code(400);
    json(['error' => $e->getMessage()]);
}

// ---------------------------------------------------------------- DB

function openDb(string $path): PDO {
    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('CREATE TABLE IF NOT EXISTS books (
        id TEXT PRIMARY KEY, title TEXT NOT NULL, author TEXT DEFAULT "", publisher TEXT DEFAULT "",
        price INTEGER, isbn TEXT DEFAULT "", cover_url TEXT DEFAULT "", url TEXT DEFAULT "",
        obi TEXT DEFAULT "", proposer TEXT DEFAULT "", resolve_tries INTEGER DEFAULT 0, created_at TEXT)');
    $db->exec('CREATE TABLE IF NOT EXISTS votes (
        book_id TEXT NOT NULL, name TEXT NOT NULL, created_at TEXT, PRIMARY KEY (book_id, name))');
    // あとから足した列（古い DB にも追加する）
    $cols = array_column($db->query('PRAGMA table_info(books)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['description' => 'TEXT DEFAULT ""', 'pubdate' => 'TEXT DEFAULT ""', 'pages' => 'INTEGER', 'desc_src' => 'TEXT DEFAULT ""'] as $col => $type) {
        if (!in_array($col, $cols, true)) $db->exec("ALTER TABLE books ADD COLUMN $col $type");
    }
    return $db;
}

function seedIfEmpty(PDO $db): void {
    if ((int)$db->query('SELECT COUNT(*) FROM books')->fetchColumn() > 0) return;
    $seedFile = __DIR__ . '/seed.json';
    if (!is_file($seedFile)) return;
    $seeds = json_decode((string)file_get_contents($seedFile), true) ?: [];
    $ins = $db->prepare('INSERT OR IGNORE INTO books (id,title,author,publisher,price,isbn,cover_url,url,obi,proposer,created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $now = gmdate('c');
    foreach ($seeds as $s) {
        $ins->execute([
            (string)($s['id'] ?? uniqid('b')), (string)($s['title'] ?? ''), (string)($s['author'] ?? ''), (string)($s['publisher'] ?? ''),
            isset($s['price']) && $s['price'] !== null ? (int)$s['price'] : null, (string)($s['isbn'] ?? ''), (string)($s['cover'] ?? ''),
            (string)($s['url'] ?? ''), (string)($s['obi'] ?? ''), (string)($s['proposer'] ?? ''), $now,
        ]);
    }
}

function state(PDO $db): array {
    $books = $db->query('SELECT id,title,author,publisher,price,isbn,cover_url,url,obi,proposer,created_at,description,pubdate,pages,desc_src FROM books ORDER BY created_at, id')->fetchAll(PDO::FETCH_ASSOC);
    $votes = $db->query('SELECT book_id, name FROM votes ORDER BY created_at')->fetchAll(PDO::FETCH_ASSOC);
    $byBook = [];
    foreach ($votes as $v) $byBook[$v['book_id']][] = $v['name'];
    foreach ($books as &$b) {
        $b['price'] = $b['price'] === null ? null : (int)$b['price'];
        $b['pages'] = $b['pages'] === null ? null : (int)$b['pages'];
        $b['description'] = (string)($b['description'] ?? '');
        $b['pubdate'] = (string)($b['pubdate'] ?? '');
        $b['desc_src'] = (string)($b['desc_src'] ?? ''); // openbd / rakuten / google / ndl / ai
        $b['voters'] = $byBook[$b['id']] ?? [];
        $b['votes'] = count($b['voters']);
        // 表紙は同一オリジンのプロキシ経由（キャッシュ済みか、ISBN か URL が分かっていれば取りに行く）
        // v= は ISBN と表紙URLから作る版番号。取り直しで画像が変わったら URL も変わり、ブラウザの古いキャッシュが残らない
        $b['cover'] = ($b['cover_url'] !== '' || $b['isbn'] !== '')
            ? 'api.php?cover=' . rawurlencode($b['id']) . '&v=' . substr(md5($b['isbn'] . '|' . $b['cover_url']), 0, 8) : '';
        unset($b['cover_url']);
    }
    return ['settings' => ['hearts' => HEARTS, 'maxObi' => MAX_OBI], 'books' => $books];
}

// ---------------------------------------------------------------- 操作

function clean($v, int $max): string {
    $s = preg_replace('/\s+/u', ' ', (string)($v ?? ''));
    return mb_substr(trim($s), 0, $max);
}

function vote(PDO $db, array $body, bool $on): void {
    $name = clean($body['name'] ?? '', 30);
    $bookId = clean($body['bookId'] ?? '', 40);
    if ($name === '') throw new RuntimeException('名前を入れてください');
    $exists = $db->prepare('SELECT 1 FROM books WHERE id = ?');
    $exists->execute([$bookId]);
    if (!$exists->fetchColumn()) throw new RuntimeException('その本は見つかりません');
    if ($on) {
        $cnt = $db->prepare('SELECT COUNT(*) FROM votes WHERE name = ?');
        $cnt->execute([$name]);
        $has = $db->prepare('SELECT 1 FROM votes WHERE book_id = ? AND name = ?');
        $has->execute([$bookId, $name]);
        if (HEARTS > 0 && !$has->fetchColumn() && (int)$cnt->fetchColumn() >= HEARTS) throw new RuntimeException('「読みたい」は' . HEARTS . '冊までです。どれかを外してください');
        $db->prepare('INSERT OR IGNORE INTO votes (book_id, name, created_at) VALUES (?,?,?)')->execute([$bookId, $name, gmdate('c')]);
    } else {
        $db->prepare('DELETE FROM votes WHERE book_id = ? AND name = ?')->execute([$bookId, $name]);
    }
}

function addBook(PDO $db, array $body): void {
    $title = clean($body['title'] ?? '', 120);
    $name = clean($body['name'] ?? '', 30);
    $obi = clean($body['obi'] ?? '', MAX_OBI);
    if ($title === '') throw new RuntimeException('タイトルを入れてください');
    if ($name === '') throw new RuntimeException('名前を入れてください');
    if ($obi === '') throw new RuntimeException('ひとこと（推薦の言葉）を入れてください');
    $isbn = preg_replace('/[^0-9X]/', '', (string)($body['isbn'] ?? ''));
    $cover = (string)($body['cover'] ?? '');
    if ($cover !== '' && !preg_match('#^https?://#', $cover)) $cover = '';
    $url = (string)($body['url'] ?? '');
    if ($url !== '' && !preg_match('#^https?://#', $url)) $url = '';
    $price = isset($body['price']) && is_numeric($body['price']) && (int)$body['price'] > 0 ? (int)$body['price'] : null;
    $dup = $db->prepare('SELECT id FROM books WHERE (isbn != "" AND isbn = ?) OR title = ?');
    $dup->execute([$isbn, $title]);
    if ($dup->fetchColumn()) throw new RuntimeException('その本はもう登録されています');
    $id = 'b' . substr(bin2hex(random_bytes(6)), 0, 10);
    $db->prepare('INSERT INTO books (id,title,author,publisher,price,isbn,cover_url,url,obi,proposer,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$id, $title, clean($body['author'] ?? '', 80), clean($body['publisher'] ?? '', 60), $price, $isbn, $cover, mb_substr($url, 0, 300), $obi, $name, gmdate('c')]);
}

// ---------------------------------------------------------------- 書誌の補完

function httpGet(string $url, int $timeout = 6, array $headers = []): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; jisyuken-book-galaxy/1.0)',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, // 楽天 API は許可 IP で制限するので、IPv6 ではなく登録した IPv4 で出る
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $GLOBALS['lastHttpCode'] = $code; // 429（回数制限）かどうかを呼び出し側で見るため
    return ($body !== false && $code >= 200 && $code < 300) ? $body : null;
}

/** 書名の照合用に正規化（巻の表記「上」「（下）」「第1巻」などと、空白・記号を落として小文字化） */
function normTitle(string $s): string {
    $s = mb_strtolower($s);
    $s = preg_replace('/(^|[\s　：:])(上|中|下|上巻|中巻|下巻|第\d+巻)(?=[\s　：:]|$)/u', '$1', $s) ?? $s;
    $s = preg_replace('/[（(]\s*(上|中|下)\s*[）)]/u', '', $s) ?? $s;
    $s = preg_replace('/[\s\p{P}\p{S}]+/u', '', $s) ?? '';
    return $s;
}

/** ISBN-10 を ISBN-13 に（NDL は古い本を 10 桁で返すことがある）。13 桁ならそのまま、変換できなければ空 */
function isbn13(string $raw): string {
    $d = preg_replace('/[^0-9X]/i', '', strtoupper($raw)) ?? '';
    if (strlen($d) === 13) return $d;
    if (strlen($d) !== 10) return '';
    $core = '978' . substr($d, 0, 9);
    $sum = 0;
    for ($i = 0; $i < 12; $i++) $sum += (int)$core[$i] * ($i % 2 === 0 ? 1 : 3);
    return $core . ((10 - $sum % 10) % 10);
}

/**
 * 候補の書名が元の書名と同じ本とみなせるか。
 * 先頭が一致することを条件にする（「…コモン・センスの哲学と思想史」が「センスの哲学」に化けないように、途中一致は不可）。
 */
function titleMatches(string $want, string $got): bool {
    $a = normTitle($want); $b = normTitle($got);
    if ($a === '' || $b === '') return false;
    if (strpos($a, $b) === 0 || strpos($b, $a) === 0) return true; // 先頭一致（PHP 7.4 でも動く書き方）
    $n = min(8, mb_strlen($a), mb_strlen($b));
    return $n >= 4 && mb_substr($a, 0, $n) === mb_substr($b, 0, $n);
}

/**
 * 候補の著者が元の著者と同じ人とみなせるか（姓の2文字が含まれていればよい）。
 * 元の著者が空、または「○○編」「○○監修」のように出版社・編者名のときは照合しない
 */
function authorMatches(string $want, string $got): bool {
    if (preg_match('/(編|編著|監修|編集部?)$/u', trim($want))) return true;
    $w = preg_replace('/[\s,、・]+/u', '', mb_substr(preg_split('/[・、,]/u', $want)[0] ?? '', 0, 20)) ?? '';
    if ($w === '') return true;
    $g = preg_replace('/[\s,、・]+/u', '', $got) ?? '';
    if ($g === '') return true; // 候補に著者が無ければ判定しない
    // 候補側がローマ字だけ（NDL は外国人著者を "Harari, Yuval Noah" のように持つ）なら照合できないので通す
    if (!preg_match('/[\p{Hiragana}\p{Katakana}\p{Han}]/u', $g)) return true;
    return mb_strpos($g, mb_substr($w, 0, 2)) !== false;
}

/** 国立国会図書館サーチ（OpenSearch）: 書名（と著者）→ 候補（title, author, publisher, isbn, price）。日本の本はここが一番確実 */
function ndlSearch(string $title, string $author = '', int $max = 3): array {
    $title = trim(mb_substr($title, 0, 60));
    if ($title === '') return [];
    $params = ['title' => $title, 'cnt' => $max, 'mediatype' => 'books'];
    if ($author !== '') $params['creator'] = mb_substr($author, 0, 20);
    $raw = httpGet('https://ndlsearch.ndl.go.jp/api/opensearch?' . http_build_query($params), 15); // NDL は遅いときがある
    if ($raw === null || !preg_match_all('#<item>(.*?)</item>#s', $raw, $m)) return [];
    $out = [];
    foreach ($m[1] as $xml) {
        $get = function (string $re) use ($xml): string {
            return preg_match($re, $xml, $mm) ? trim(html_entity_decode($mm[1], ENT_QUOTES | ENT_XML1, 'UTF-8')) : '';
        };
        $t = $get('#<dc:title>([^<]+)#');
        $isbn = isbn13($get('#<dc:identifier[^>]*dcndl:ISBN[^>]*>([^<]+)#'));
        // 例: "三宅, 香帆, 1994-" → 生没年を落とし、姓名をつなぐ
        $creator = preg_replace('/,\s*\d{4}-?(\d{4})?\s*$/u', '', $get('#<dc:creator>([^<]+)#'));
        $creator = preg_replace('/\s*,\s*/u', '', $creator ?? '');
        $publisher = $get('#<dc:publisher>([^<]+)#');
        $priceRaw = $get('#<dcndl:price>([^<]+)#'); // 例: "1000円" (本体価格) → 税込に
        $price = preg_match('/(\d[\d,]*)/', $priceRaw, $pm) ? (int)round((int)str_replace(',', '', $pm[1]) * 1.1) : null;
        if ($t === '' || strlen($isbn) !== 13) continue;
        $pages = preg_match('/(\d+)\s*p/u', $get('#<dc:extent>([^<]+)#'), $pm) ? (int)$pm[1] : null; // 例: "285p"
        $out[] = ['title' => $t, 'author' => $creator, 'publisher' => $publisher, 'isbn' => $isbn, 'cover' => '', 'price' => $price,
            'date' => $get('#<dcterms:issued>([^<]+)#'), 'description' => '', 'pages' => $pages];
    }
    return $out;
}

/** openBD: ISBN → 書誌（title, author, publisher, price, cover） */
function openbd(string $isbn): ?array {
    $raw = httpGet('https://api.openbd.jp/v1/get?isbn=' . rawurlencode($isbn));
    $d = $raw ? json_decode($raw, true) : null;
    if (!is_array($d) || empty($d[0]) || empty($d[0]['summary'])) return null;
    $s = $d[0]['summary'];
    $price = null;
    $prices = $d[0]['onix']['ProductSupply']['SupplyDetail']['Price'] ?? [];
    foreach ($prices as $p) {
        if (!empty($p['PriceAmount'])) {
            $amount = (float)$p['PriceAmount'];
            $type = (string)($p['PriceType'] ?? '');
            // 01/03 は本体価格、02/04 は税込。本体なら10%を足す
            $price = (int)round(in_array($type, ['01', '03'], true) ? $amount * 1.1 : $amount);
            break;
        }
    }
    // 内容紹介: ONIX の CollateralDetail/TextContent（03=内容紹介、02=短い紹介、04=目次 の順で採用）
    $desc = '';
    $texts = $d[0]['onix']['CollateralDetail']['TextContent'] ?? [];
    foreach (['03', '02', '04'] as $want) {
        foreach ($texts as $t) {
            if ((string)($t['TextType'] ?? '') === $want && trim((string)($t['Text'] ?? '')) !== '') { $desc = (string)$t['Text']; break 2; }
        }
    }
    // ページ数: DescriptiveDetail/Extent（ExtentType 11 = 本文ページ数）
    $pages = null;
    foreach ($d[0]['onix']['DescriptiveDetail']['Extent'] ?? [] as $e) {
        if (!empty($e['ExtentValue'])) { $pages = (int)$e['ExtentValue']; break; }
    }
    return [
        'title' => (string)($s['title'] ?? ''), 'author' => (string)($s['author'] ?? ''), 'publisher' => (string)($s['publisher'] ?? ''),
        'isbn' => (string)($s['isbn'] ?? $isbn), 'cover' => (string)($s['cover'] ?? ''), 'price' => $price,
        'description' => cleanDesc($desc), 'pubdate' => (string)($s['pubdate'] ?? ''), 'pages' => $pages,
    ];
}

/** 内容紹介のテキストを整える（HTML を落とし、空白をまとめ、長さを制限） */
function cleanDesc(string $s): string {
    $s = html_entity_decode(strip_tags(preg_replace('#<br\s*/?>#i', "\n", $s) ?? $s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace("/[ \t\x{3000}]+/u", ' ', $s) ?? $s;
    $s = preg_replace("/\n{3,}/", "\n\n", trim($s)) ?? $s;
    return mb_strlen($s) > MAX_DESC ? rtrim(mb_substr($s, 0, MAX_DESC - 1)) . '…' : $s;
}

/** 発行年月をそろえる（"20240417" → "2024.4"、"2024-04-17" → "2024.4"、"2024.4" はそのまま） */
function normDate(string $s): string {
    if (preg_match('/^(\d{4})[-.\/年]?(\d{1,2})/u', $s, $m)) return $m[1] . '.' . (int)$m[2];
    if (preg_match('/^(\d{4})/', $s, $m)) return $m[1];
    return '';
}

/** Google Books: 書名や著者 → 候補 */
function googleBooks(string $q, int $max = 6): array {
    $params = ['q' => $q, 'langRestrict' => 'ja', 'maxResults' => $max, 'printType' => 'books'];
    if (googleKey() !== '') $params['key'] = googleKey();
    $raw = httpGet('https://www.googleapis.com/books/v1/volumes?' . http_build_query($params));
    $d = $raw ? json_decode($raw, true) : null;
    $out = [];
    foreach (($d['items'] ?? []) as $it) {
        $v = $it['volumeInfo'] ?? [];
        $isbn = '';
        foreach (($v['industryIdentifiers'] ?? []) as $idn) if (($idn['type'] ?? '') === 'ISBN_13') $isbn = (string)$idn['identifier'];
        $thumb = (string)(($v['imageLinks']['thumbnail'] ?? '') ?: ($v['imageLinks']['smallThumbnail'] ?? ''));
        $thumb = preg_replace('#^http://#', 'https://', $thumb);
        $price = $it['saleInfo']['listPrice']['amount'] ?? null;
        $out[] = [
            'title' => trim((string)($v['title'] ?? '') . (isset($v['subtitle']) ? ' ' . $v['subtitle'] : '')),
            'author' => implode('・', $v['authors'] ?? []), 'publisher' => (string)($v['publisher'] ?? ''),
            'isbn' => $isbn, 'cover' => $thumb, 'price' => $price !== null ? (int)round((float)$price) : null,
            'date' => (string)($v['publishedDate'] ?? ''),
            'description' => cleanDesc((string)($v['description'] ?? '')), 'pages' => !empty($v['pageCount']) ? (int)$v['pageCount'] : null,
        ];
    }
    return $out;
}

/** JSON を POST して本文を返す（失敗は null）。$GLOBALS['lastHttpCode'] に状態コードを残す */
function httpPost(string $url, array $json, array $headers, int $timeout = 40): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($json, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $GLOBALS['lastHttpCode'] = $code;
    return ($body !== false && $code >= 200 && $code < 300) ? $body : null;
}

/**
 * 楽天ブックス: ISBN か 書名（＋著者）→ 候補（title, author, publisher, isbn, cover, price, description, date）
 * 出版社の紹介文（itemCaption）と表紙画像が揃っていて、日本の本の網羅性が高い。アプリ ID が無ければ空
 */
function rakutenBooks(array $q, int $max = 5): array {
    if (rakutenId() === '' || rakutenKey() === '') return [];
    $params = array_merge(['applicationId' => rakutenId(), 'accessKey' => rakutenKey(), 'format' => 'json', 'formatVersion' => 2, 'hits' => $max, 'booksGenreId' => '001'], $q);
    $raw = httpGet('https://openapi.rakuten.co.jp/services/api/BooksBook/Search/20170404?' . http_build_query($params), 10);
    $d = $raw ? json_decode($raw, true) : null;
    $out = [];
    foreach (($d['Items'] ?? []) as $it) {
        $isbn = preg_replace('/[^0-9X]/', '', (string)($it['isbn'] ?? ''));
        // 画像 URL の末尾の "?_ex=200x200" を外すと元の大きさになる
        $cover = preg_replace('/\?_ex=\d+x\d+$/', '', (string)($it['largeImageUrl'] ?? ''));
        $out[] = [
            'title' => trim((string)($it['title'] ?? '') . (!empty($it['subTitle']) ? ' ' . $it['subTitle'] : '')),
            'author' => str_replace('/', '・', (string)($it['author'] ?? '')), 'publisher' => (string)($it['publisherName'] ?? ''),
            'isbn' => $isbn, 'cover' => $cover, 'price' => !empty($it['itemPrice']) ? (int)$it['itemPrice'] : null,
            'description' => cleanDesc((string)($it['itemCaption'] ?? '')), 'date' => (string)($it['salesDate'] ?? ''), 'pages' => null,
        ];
    }
    return $out;
}

/**
 * どこにも紹介文が無い本のために、Claude に短い紹介文を書いてもらう（画面では「AIが書いた紹介」と明示する）。
 * 書誌（書名・著者・出版社・発行年）だけを渡し、知らない本については一般的な説明にとどめるよう指示する。失敗は空
 */
function aiDescription(string $title, string $author, string $publisher, string $pubdate): string {
    if (anthropicKey() === '' || $title === '') return '';
    $meta = "書名: {$title}\n著者: " . ($author !== '' ? $author : '不明') . "\n出版社: " . ($publisher !== '' ? $publisher : '不明') . "\n発行: " . ($pubdate !== '' ? $pubdate : '不明');
    // claude-opus-5-5: 1 冊あたり 0.3 円ほど（入力 $4 / 出力 $20 per 1M tokens）。fallbacks は安全判定で断られたときの自動切り替え
    $raw = httpPost('https://api.anthropic.com/v1/messages', [
        'model' => 'claude-opus-5-5',
        'max_tokens' => 600,
        'output_config' => ['effort' => 'low'],
        'fallbacks' => 'default',
        'system' => "あなたは図書館の司書です。読書会のメンバーが本を選ぶときに読む「内容紹介」を日本語で書きます。\n"
            . "ルール: 3〜4文、200字以内。本文だけを出力し、前置きや見出しは付けない。\n"
            . "その本を確実に知っている場合は、何について書かれた本で、どんな読者に向くかを書く。\n"
            . "確信が持てない場合は、書名と著者から分かる範囲の一般的な説明にとどめ、具体的な章立て・数字・エピソードを作り出さない。\n"
            . "賞や売上などの実績は、確実に知っているもの以外は書かない。",
        'messages' => [['role' => 'user', 'content' => "次の本の内容紹介を書いてください。\n{$meta}"]],
    ], ['x-api-key: ' . anthropicKey(), 'anthropic-version: 2023-06-01', 'anthropic-beta: server-side-fallback-2026-07-01'], 60);
    $d = $raw ? json_decode($raw, true) : null;
    if (!is_array($d) || ($d['stop_reason'] ?? '') === 'refusal') return '';
    $text = '';
    foreach (($d['content'] ?? []) as $blk) if (($blk['type'] ?? '') === 'text') $text .= (string)($blk['text'] ?? '');
    return cleanDesc($text);
}

function lookup(string $q): array {
    $q = clean($q, 120);
    if ($q === '') return [];
    $digits = preg_replace('/[^0-9X]/', '', $q);
    $cands = [];
    if (strlen($digits) === 13 || strlen($digits) === 10) {
        $b = openbd($digits);
        if ($b) $cands[] = $b;
    }
    // 日本の本は NDL が確実なので先に出す（定価は openBD で補う）
    foreach (ndlSearch($q) as $n) {
        $b = openbd($n['isbn']);
        if ($b) { if ($n['price'] === null) $n['price'] = $b['price']; if ($b['cover'] !== '') $n['cover'] = $b['cover']; }
        $cands[] = $n;
    }
    // 楽天ブックス: 紹介文と表紙が揃った候補（アプリ ID がある場合だけ）
    foreach (rakutenBooks(['title' => $q], 4) as $k) if ($k['isbn'] !== '') $cands[] = $k;
    foreach (googleBooks($q) as $g) {
        if ($g['isbn'] !== '') {
            $b = openbd($g['isbn']);
            if ($b) {
                if ($b['cover'] === '') $b['cover'] = $g['cover'];
                if ($b['price'] === null) $b['price'] = $g['price'];
                $g = array_merge($g, array_filter($b, fn($x) => $x !== '' && $x !== null));
            }
        }
        $cands[] = $g;
        if (count($cands) >= 6) break;
    }
    return $cands;
}

/** 表紙や定価が欠けている本を、少しずつ補完する（1リクエストに数冊まで、各本5回まで試す。NDL が遅くて失敗することがあるため多め） */
function resolveSome(PDO $db): void {
    $rows = $db->query('SELECT id,title,author,publisher,isbn,cover_url,price,description,pubdate,pages,desc_src FROM books
        WHERE (cover_url = "" OR price IS NULL OR isbn = "" OR description IS NULL OR description = "" OR pubdate IS NULL OR pubdate = "") AND resolve_tries < 5
        ORDER BY resolve_tries, created_at LIMIT ' . RESOLVE_PER_REQUEST)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $db->prepare('UPDATE books SET resolve_tries = resolve_tries + 1 WHERE id = ?')->execute([$r['id']]);
        $isbn = $r['isbn'];
        $cover = $r['cover_url'];
        $price = $r['price'];
        $desc = (string)($r['description'] ?? '');
        $pubdate = (string)($r['pubdate'] ?? '');
        $pages = $r['pages'] !== null ? (int)$r['pages'] : null;
        $descSrc = (string)($r['desc_src'] ?? '');
        $take = function (array $src, string $from = '') use (&$cover, &$price, &$desc, &$pubdate, &$pages, &$descSrc): void {
            // 欠けているものだけ候補から埋める
            if ($cover === '' && ($src['cover'] ?? '') !== '') $cover = $src['cover'];
            if ($price === null && ($src['price'] ?? null) !== null) $price = $src['price'];
            if ($desc === '' && ($src['description'] ?? '') !== '') { $desc = $src['description']; $descSrc = $from; }
            if ($pubdate === '' && normDate((string)($src['pubdate'] ?? $src['date'] ?? '')) !== '') $pubdate = normDate((string)($src['pubdate'] ?? $src['date'] ?? ''));
            if ($pages === null && !empty($src['pages'])) $pages = (int)$src['pages'];
        };
        if ($isbn === '') {
            $author = $r['author'] !== '' ? mb_substr(preg_split('/[・、,]/u', $r['author'])[0], 0, 20) : '';
            // 1) NDL: 書名（＋著者）で探し、書名が一致するものだけ採用。
            //    見つからなければ副題を落とした書名（最初の空白まで）でも試す
            $main = preg_split('/[\s　：:]/u', $r['title'])[0] ?? '';
            $titles = array_unique(array_filter([$r['title'], mb_strlen($main) >= 4 ? $main : '']));
            $hits = [];
            foreach ($titles as $t) {
                $hits = array_filter(ndlSearch($t, $author, 5), fn($n) => titleMatches($r['title'], $n['title']));
                // 著者付きで見つからなければ書名だけで探すが、著者が食い違う候補は採らない
                if (!$hits && $author !== '') $hits = array_filter(ndlSearch($t, '', 5), fn($n) => titleMatches($r['title'], $n['title']) && authorMatches($r['author'], $n['author']));
                if ($hits) break;
            }
            foreach ($hits as $n) { $isbn = $n['isbn']; $take($n, 'ndl'); break; }
            // 1b) 楽天ブックス: NDL で見つからなければ書名＋著者で探す（紹介文・表紙・定価も一度に取れる）
            if ($isbn === '') {
                foreach (rakutenBooks(['title' => mb_substr($r['title'], 0, 40), 'author' => $author], 5) as $k) {
                    if ($k['isbn'] !== '' && titleMatches($r['title'], $k['title']) && authorMatches($r['author'], $k['author'])) { $isbn = $k['isbn']; $take($k, 'rakuten'); break; }
                }
            }
            // 2) Google: NDL で見つからなければ「書名 著者」で探す。書名一致のものだけ、日本の ISBN（9784…）を優先
            if ($isbn === '') {
                $q = mb_substr($r['title'], 0, 40) . ($author !== '' ? ' ' . $author : '');
                $cands = array_filter(googleBooks($q, 5), fn($g) => titleMatches($r['title'], $g['title']) && authorMatches($r['author'], $g['author']));
                usort($cands, fn($a, $b) => (int)(strpos($b['isbn'], '9784') === 0) - (int)(strpos($a['isbn'], '9784') === 0));
                foreach ($cands as $g) {
                    if ($g['isbn'] !== '') { $isbn = $g['isbn']; $take($g, 'google'); break; }
                    if ($cover === '' && $g['cover'] !== '') $cover = $g['cover'];
                }
                // Google の回数制限で失敗したときは、試行回数に数えない（後でやり直せるように）
                if (($GLOBALS['lastHttpCode'] ?? 0) === 429) {
                    $db->prepare('UPDATE books SET resolve_tries = resolve_tries - 1 WHERE id = ?')->execute([$r['id']]);
                    continue;
                }
            }
        }
        if ($isbn !== '') {
            // openBD: 版元の内容紹介・定価・発行年月・ページ数（表紙は openBD を優先）
            $b = openbd($isbn);
            if ($b) { if ($b['cover'] !== '') $cover = $b['cover']; $take($b, 'openbd'); }
            // 楽天ブックス: 出版社の紹介文・表紙・定価・発売日（ISBN で確実に引ける）
            if ($cover === '' || $desc === '' || $price === null || $pubdate === '') {
                foreach (rakutenBooks(['isbn' => $isbn], 1) as $k) $take($k, 'rakuten');
            }
            // まだ欠けていれば Google Books を引く（表紙サムネイル、紹介文、ページ数）。
            // 日本の本は isbn: 検索に出ないことが多いので、書名（＋著者）検索で書名・著者が一致する候補からも採る（ISBN が無くても可）
            if ($cover === '' || $desc === '' || $pages === null || $pubdate === '') {
                // Google は同じ本を複数レコードで持ち、紹介文が付くのは 2 件目以降のことが多い。全件見て欠けている項目を順に埋める
                foreach (googleBooks('isbn:' . $isbn, 5) as $g) $take($g, 'google');
                if (($GLOBALS['lastHttpCode'] ?? 0) === 429) {
                    $db->prepare('UPDATE books SET resolve_tries = resolve_tries - 1 WHERE id = ?')->execute([$r['id']]);
                    continue;
                }
                if ($desc === '' || $pages === null) {
                    $author = $r['author'] !== '' ? mb_substr(preg_split('/[・、,]/u', $r['author'])[0], 0, 20) : '';
                    $q = mb_substr($r['title'], 0, 40) . ($author !== '' ? ' ' . $author : '');
                    foreach (googleBooks($q, 10) as $g) {
                        if (titleMatches($r['title'], $g['title']) && authorMatches($r['author'], $g['author'])) $take(['description' => $g['description'], 'pages' => $g['pages'], 'date' => $g['date']], 'google');
                    }
                    if (($GLOBALS['lastHttpCode'] ?? 0) === 429) {
                        $db->prepare('UPDATE books SET resolve_tries = resolve_tries - 1 WHERE id = ?')->execute([$r['id']]);
                        continue;
                    }
                }
            }
        }
        // 最後の手段: どの取得元にも紹介文が無ければ Claude に書いてもらう（desc_src = 'ai' で画面に明示）
        if ($desc === '' && anthropicKey() !== '') {
            $ai = aiDescription($r['title'], $r['author'], (string)($r['publisher'] ?? ''), $pubdate);
            if ($ai !== '') { $desc = $ai; $descSrc = 'ai'; }
        }
        $db->prepare('UPDATE books SET isbn = ?, cover_url = ?, price = ?, description = ?, pubdate = ?, pages = ?, desc_src = ? WHERE id = ?')
            ->execute([$isbn, $cover, $price, $desc, $pubdate, $pages, $descSrc, $r['id']]);
    }
}

/** 表紙画像を同一オリジンで返す（初回に取得してキャッシュ） */
function serveCover(PDO $db, string $id, string $dir): void {
    $id = preg_replace('/[^A-Za-z0-9_-]/', '', $id);
    $file = $dir . '/' . $id . '.jpg';
    if (!is_file($file)) {
        $st = $db->prepare('SELECT isbn, cover_url FROM books WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { http_response_code(404); exit; }
        // 取得順: NDL サムネイル（ISBN で確実・画質も良い。Referer が無いと 403）→ openBD → Google などの cover_url
        $urls = [];
        if ($row['isbn'] !== '') {
            $urls[] = ['https://ndlsearch.ndl.go.jp/thumbnail/' . $row['isbn'] . '.jpg', ['Referer: https://ndlsearch.ndl.go.jp/']];
            $urls[] = ['https://cover.openbd.jp/' . $row['isbn'] . '.jpg', []];
        }
        if ($row['cover_url'] !== '') $urls[] = [$row['cover_url'], []];
        $img = null;
        foreach ($urls as [$u, $hdr]) {
            $img = httpGet($u, 8, $hdr);
            if ($img !== null && strlen($img) > 1000 && @getimagesizefromstring($img) !== false) break;
            $img = null;
        }
        if ($img === null) { http_response_code(404); exit; }
        @file_put_contents($file, $img);
    }
    $info = @getimagesize($file);
    header('Content-Type: ' . ($info['mime'] ?? 'image/jpeg'));
    header('Cache-Control: public, max-age=604800');
    readfile($file);
}

function json(array $data): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
