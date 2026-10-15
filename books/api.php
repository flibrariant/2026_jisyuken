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

const HEARTS = 3;             // 一人あたりの「読みたい」数
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
    $books = $db->query('SELECT id,title,author,publisher,price,isbn,cover_url,url,obi,proposer,created_at FROM books ORDER BY created_at, id')->fetchAll(PDO::FETCH_ASSOC);
    $votes = $db->query('SELECT book_id, name FROM votes ORDER BY created_at')->fetchAll(PDO::FETCH_ASSOC);
    $byBook = [];
    foreach ($votes as $v) $byBook[$v['book_id']][] = $v['name'];
    foreach ($books as &$b) {
        $b['price'] = $b['price'] === null ? null : (int)$b['price'];
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
        if (!$has->fetchColumn() && (int)$cnt->fetchColumn() >= HEARTS) throw new RuntimeException('「読みたい」は' . HEARTS . '冊までです。どれかを外してください');
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
        $out[] = ['title' => $t, 'author' => $creator, 'publisher' => $publisher, 'isbn' => $isbn, 'cover' => '', 'price' => $price, 'date' => $get('#<dcterms:issued>([^<]+)#')];
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
    return [
        'title' => (string)($s['title'] ?? ''), 'author' => (string)($s['author'] ?? ''), 'publisher' => (string)($s['publisher'] ?? ''),
        'isbn' => (string)($s['isbn'] ?? $isbn), 'cover' => (string)($s['cover'] ?? ''), 'price' => $price,
    ];
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
        ];
    }
    return $out;
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
    $rows = $db->query('SELECT id,title,author,isbn,cover_url,price FROM books
        WHERE (cover_url = "" OR price IS NULL OR isbn = "") AND resolve_tries < 5 ORDER BY resolve_tries, created_at LIMIT ' . RESOLVE_PER_REQUEST)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $db->prepare('UPDATE books SET resolve_tries = resolve_tries + 1 WHERE id = ?')->execute([$r['id']]);
        $isbn = $r['isbn'];
        $cover = $r['cover_url'];
        $price = $r['price'];
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
            foreach ($hits as $n) { $isbn = $n['isbn']; if ($price === null) $price = $n['price']; break; }
            // 2) Google: NDL で見つからなければ「書名 著者」で探す。書名一致のものだけ、日本の ISBN（9784…）を優先
            if ($isbn === '') {
                $q = mb_substr($r['title'], 0, 40) . ($author !== '' ? ' ' . $author : '');
                $cands = array_filter(googleBooks($q, 5), fn($g) => titleMatches($r['title'], $g['title']) && authorMatches($r['author'], $g['author']));
                usort($cands, fn($a, $b) => (int)(strpos($b['isbn'], '9784') === 0) - (int)(strpos($a['isbn'], '9784') === 0));
                foreach ($cands as $g) {
                    if ($g['isbn'] !== '') { $isbn = $g['isbn']; if ($cover === '') $cover = $g['cover']; if ($price === null) $price = $g['price']; break; }
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
            $b = openbd($isbn);
            if ($b) {
                if ($b['cover'] !== '') $cover = $b['cover'];
                if ($price === null && $b['price'] !== null) $price = $b['price'];
            }
            // openBD に表紙が無ければ、ISBN で Google Books を引いてサムネイルをもらう（NDL のサムネイルは配信時に試す）
            if ($cover === '') {
                foreach (googleBooks('isbn:' . $isbn, 1) as $g) {
                    if ($g['cover'] !== '') { $cover = $g['cover']; break; }
                }
                if (($GLOBALS['lastHttpCode'] ?? 0) === 429) {
                    $db->prepare('UPDATE books SET resolve_tries = resolve_tries - 1 WHERE id = ?')->execute([$r['id']]);
                    continue;
                }
            }
        }
        $db->prepare('UPDATE books SET isbn = ?, cover_url = ?, price = ? WHERE id = ?')->execute([$isbn, $cover, $price, $r['id']]);
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
