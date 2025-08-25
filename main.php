<?php
// Install SQLite and Ollama, and pull a model (e.g. ollama pull llama3[default])

// infos:
// LLM_HOST (http://localhost:11434)
// LLM_MODEL (llama3)
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

$DB_PATH = __DIR__ . DIRECTORY_SEPARATOR . 'rag.db';
$MODEL = getenv('LLM_MODEL') ?: 'llama3';
$LLM_HOST = rtrim(getenv('LLM_HOST') ?: 'http://localhost:11434', '/'); // Hoster

$route = $_GET['action'] ?? ($_POST['action'] ?? 'ui');

function db(): PDO {
    global $DB_PATH;
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . $DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}
function init_db(): void {
    $pdo = db();
    $pdo->exec('PRAGMA journal_mode=WAL;');
    $pdo->exec('CREATE TABLE IF NOT EXISTS docs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT,
        content TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );');
    $pdo->exec("CREATE VIRTUAL TABLE IF NOT EXISTS docs_fts USING fts5(doc_id UNINDEXED, content, tokenize='porter');"); // SQLite (FTS5)'s Storage
}
function json_out($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
function ingest(string $title, string $content): array {
    $pdo = db();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('INSERT INTO docs(title, content) VALUES(?, ?)');
    $stmt->execute([$title, $content]);
    $id = (int)$pdo->lastInsertId();
    $chunkSize = 1000;
    $len = mb_strlen($content);
    for ($i = 0; $i < $len; $i += $chunkSize) {
        $chunk = mb_substr($content, $i, $chunkSize);
        $pdo->prepare('INSERT INTO docs_fts(doc_id, content) VALUES(?, ?)')->execute([$id, $chunk]);
    }
    $pdo->commit();
    return ['id' => $id, 'title' => $title];
}
function search_docs(string $query, int $k = 5): array {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT doc_id, snippet(docs_fts, 1, "<b>", "</b>", "…", 10) AS snippet, content FROM docs_fts WHERE docs_fts MATCH ? LIMIT ?');
    $stmt->execute([$query, $k]);
    $hits = $stmt->fetchAll();
    $grouped = [];
    foreach ($hits as $h) {
        $grouped[] = [
            'doc_id' => (int)$h['doc_id'],
            'snippet' => $h['snippet'],
            'content' => $h['content']
        ];
    }
    return $grouped;
}
function build_prompt(string $question, array $contexts): string {
    $ctx = "";
    foreach ($contexts as $i => $c) {
        $ctx .= "[Chunk " . ($i+1) . "]\n" . trim($c['content']) . "\n\n";
    }
    return <<<EOT
You are a helpful assistant. Answer the user using only the provided context chunks. If the answer isn't in the context, say you don't know briefly.

Context:
$ctx
Question: $question
Answer:
EOT;
}
function call_llm(string $prompt): array {
    global $MODEL, $LLM_HOST;
    $payload = json_encode([
        'model' => $MODEL,
        'prompt' => $prompt,
        'stream' => false
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $ch = curl_init($LLM_HOST . '/api/generate');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 120
    ]);
    $res = curl_exec($ch);
    if ($res === false) return ['error' => 'LLM request failed: ' . curl_error($ch)];
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 400) return ['error' => 'LLM HTTP ' . $code];
    $data = json_decode($res, true);
    if (!is_array($data)) return ['error' => 'Invalid LLM response'];
    return $data;
}
// System Error
function handle_ingest(): void {
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    if ($title === '' || $content === '') {
        json_out(['error' => 'title and content are required'], 400);
        return;
    }
    $r = ingest($title, $content);
    json_out(['ok' => true, 'doc' => $r]);
}
// Answer
function handle_ask(): void {
    $q = trim($_POST['q'] ?? ($_GET['q'] ?? ''));
    $k = (int)($_POST['k'] ?? ($_GET['k'] ?? 5));
    if ($q === '') {
        json_out(['error' => 'q is required'], 400);
        return;
    }
    $hits = search_docs($q, max(1, min($k, 8)));
    $prompt = build_prompt($q, $hits);
    $llm = call_llm($prompt);
    if (isset($llm['error'])) { json_out(['error' => $llm['error']], 502); return; }
    $answer = $llm['response'] ?? '';
    json_out(['ok' => true, 'answer' => $answer, 'hits' => $hits]);
}
function render_ui(): void {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>PHP RAG Chatbot</title>'
        . '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Arial,sans-serif;max-width:900px;margin:40px auto;padding:0 16px}textarea,input,button{font:inherit}.card{border:1px solid #ddd;border-radius:16px;padding:16px;margin:16px 0;box-shadow:0 2px 8px rgba(0,0,0,.04)} .row{display:grid;gap:12px} .grid{display:grid;grid-template-columns:1fr 1fr;gap:16px} .btn{border:0;padding:10px 14px;border-radius:12px;box-shadow:0 1px 4px rgba(0,0,0,.08);cursor:pointer} .btn:active{transform:translateY(1px)} pre{white-space:pre-wrap;word-wrap:break-word}</style>'
        . '</head><body>';
    echo '<h1>PHP RAG Chatbot</h1>';
    echo '<div class="card"><h3>Ask</h3><div class="row"><input id="q" placeholder="Type a question"/><button class="btn" onclick="ask()">Ask</button></div><pre id="ans"></pre><div id="hits"></div></div>';
    echo '<div class="card"><h3>Ingest</h3><div class="grid"><input id="title" placeholder="Title"/><textarea id="content" rows="8" placeholder="Paste content here"></textarea></div><button class="btn" onclick="ingest()">Add Document</button><pre id="ing"></pre></div>';
    echo '<script>
async function ask(){
  const q=document.getElementById("q").value.trim();
  if(!q) return;
  const r=await fetch("?action=ask",{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:new URLSearchParams({q})});
  const j=await r.json();
  document.getElementById("ans").textContent=j.ok?j.answer:(j.error||"error");
  const h=document.getElementById("hits");
  if(j.hits){
    h.innerHTML = '<h4>Context Chunks</h4>' + j.hits.map((x,i)=>`<div class="card"><b>#${i+1}</b><br>${x.snippet}</div>`).join("");
  }
}
async function ingest(){
  const title=document.getElementById("title").value.trim();
  const content=document.getElementById("content").value.trim();
  if(!title||!content) return;
  const r=await fetch("?action=ingest",{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:new URLSearchParams({title,content})});
  const j=await r.json();
  document.getElementById("ing").textContent=JSON.stringify(j,null,2);
}
</script>';
    echo '</body></html>';
}
// initialize
init_db();

switch ($route) {
    case 'ingest':
        handle_ingest();
        break;
    case 'ask':
        handle_ask();
        break;
    case 'ui':
    default:
        render_ui();
        break;
}
