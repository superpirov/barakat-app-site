<?php
// Barakat — API новостей. Чтение открытое, запись только по паролю.
// Пароль задаётся при первом входе в админку и хранится как хеш в .passwd.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$ROOT = dirname(__DIR__);
$NEWS_FILE = $ROOT . '/news.json';
$PASS_FILE = __DIR__ . '/.passwd';

function read_news($NEWS_FILE) {
  if (is_file($NEWS_FILE)) {
    $j = json_decode(@file_get_contents($NEWS_FILE), true);
    if (is_array($j)) {
      return array_values(array_filter($j, function ($n) {
        return is_array($n) && isset($n['date'], $n['text']);
      }));
    }
  }
  return [];
}

function write_news($NEWS_FILE, $arr) {
  $tmp = $NEWS_FILE . '.tmp';
  $ok = @file_put_contents($tmp, json_encode(array_values($arr), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
  if ($ok === false) return false;
  return @rename($tmp, $NEWS_FILE);
}

function valid_item($n) {
  if (!is_array($n)) return false;
  $d = (string)($n['date'] ?? '');
  $t = isset($n['text']) ? (string)$n['text'] : '';
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
  $len = function_exists('mb_strlen') ? mb_strlen(trim($t), 'UTF-8') : strlen(trim($t));
  if ($len < 1 || $len > 500) return false;
  return true;
}

function fail($code, $msg) {
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
  echo json_encode(['ok' => true, 'news' => read_news($NEWS_FILE), 'init' => !is_file($PASS_FILE)], JSON_UNESCAPED_UNICODE);
  exit;
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;
$action = (string)($in['action'] ?? '');
$pass = (string)($in['password'] ?? '');

// Первичная установка пароля — только если его ещё нет
if ($action === 'init') {
  if (is_file($PASS_FILE)) fail(403, 'Пароль уже задан');
  if ((function_exists('mb_strlen') ? mb_strlen($pass, 'UTF-8') : strlen($pass)) < 4) fail(400, 'Минимум 4 символа');
  if (@file_put_contents($PASS_FILE, password_hash($pass, PASSWORD_DEFAULT), LOCK_EX) === false) fail(500, 'Не удалось сохранить пароль');
  echo json_encode(['ok' => true, 'news' => read_news($NEWS_FILE)], JSON_UNESCAPED_UNICODE);
  exit;
}

if (!is_file($PASS_FILE) || !password_verify($pass, trim((string)@file_get_contents($PASS_FILE)))) {
  fail(403, 'Неверный пароль');
}

if ($action === 'login') {
  echo json_encode(['ok' => true, 'news' => read_news($NEWS_FILE)], JSON_UNESCAPED_UNICODE);
  exit;
}

$news = read_news($NEWS_FILE);

if ($action === 'add') {
  $item = ['date' => (string)($in['date'] ?? date('Y-m-d')), 'text' => trim((string)($in['text'] ?? ''))];
  if (!valid_item($item)) fail(400, 'Заполни дату и текст новости');
  $news[] = $item;
} elseif ($action === 'delete') {
  $idx = (int)($in['index'] ?? -1);
  if (!isset($news[$idx])) fail(400, 'Нет такой новости');
  array_splice($news, $idx, 1);
} elseif ($action === 'set') {
  $list = $in['news'] ?? null;
  if (!is_array($list)) fail(400, 'Некорректный список');
  $news = array_values(array_filter($list, 'valid_item'));
} else {
  fail(400, 'Неизвестное действие');
}

if (!write_news($NEWS_FILE, $news)) fail(500, 'Не удалось записать файл. Проверь права на news.json (нужна запись, например 664/666)');
echo json_encode(['ok' => true, 'news' => $news], JSON_UNESCAPED_UNICODE);
