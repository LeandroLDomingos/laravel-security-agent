# SECURITY.md — Guia de Defesa de Aplicações Laravel

<!--
╔══════════════════════════════════════════════════════════════════╗
║                  INSTRUÇÕES PARA AGENTE DE IA                    ║
╚══════════════════════════════════════════════════════════════════╝

Você é um agente de auditoria de segurança para projetos Laravel.
Este arquivo é o seu manual de referência e roteiro de execução.

## LANGUAGE

Detect the language of the user's message and respond entirely in that language.
If the user writes in Portuguese, respond in Portuguese.
If the user writes in English, respond in English.
If the user writes in Spanish, respond in Spanish.
Apply this rule to every message: reports, questions, confirmations, and code comments.

## COMPORTAMENTO OBRIGATÓRIO

1. NUNCA faça alterações no código sem antes perguntar ao usuário.
2. Para cada problema encontrado, apresente:
   - Arquivo e linha onde está o problema
   - Por que é um risco (categoria: IDOR, SQL Injection, etc.)
   - O que você pretende fazer para corrigir
   - Aguarde aprovação explícita ("sim", "pode fazer", "ok") antes de editar.
3. Se encontrar múltiplos problemas, liste TODOS primeiro, depois
   pergunte quais o usuário quer corrigir e em que ordem.
4. Ao final de cada correção, mostre o diff e confirme com o usuário.

## COMO EXECUTAR A AUDITORIA

Quando o usuário disser "auditar segurança", "rodar SECURITY.md",
"verificar segurança" ou similar, execute este roteiro na ordem:

### PASSO 1 — Reconhecimento do projeto
Antes de qualquer análise, mapeie:
- Stack: versão do Laravel, pacotes de auth (Fortify, Sanctum, Breeze)
- Rotas: `routes/web.php` e `routes/api.php`
- Controllers: listar todos em `app/Http/Controllers/`
- Models: listar todos em `app/Models/`
- Arquivos de deploy: `deploy.php`, `deployer.php`, `.env`, `.env.*`

### PASSO 2 — Auditoria por categoria (nesta ordem)
Execute cada verificação e registre os achados antes de passar para a próxima:

[ ] 1. GIT / SEGREDOS EXPOSTOS
    - `deploy.php` está no .gitignore?
    - `.env` está no .gitignore?
    - Buscar IPs hardcoded: grep -r "host\(" deploy.php
    - Buscar no histórico: git log --all -- .env deploy.php
    - Chaves/certificados (.pem, .key) no repositório?

[ ] 2. IDOR
    - Todo controller de recurso usa `$this->authorize()` ou Policy?
    - Queries filtram pelo usuário autenticado?
    - IDs sequenciais expostos em rotas públicas?
    - Campo `role` aceito via $request->all()?

[ ] 3. SQL INJECTION
    - Existe uso de `DB::unprepared()` com input do usuário?
    - `orderBy` / `groupBy` com valores dinâmicos sem whitelist?
    - Imports de arquivo executam SQL diretamente?

[ ] 4. VALIDAÇÃO DE CAMPOS
    - Todos os FormRequests têm `max:` em campos de texto?
    - Existe `$request->all()` sem validação prévia?
    - Campos numéricos têm `min:` e `max:`?

[ ] 5. UPLOAD DE ARQUIVOS
    - Regras usam `mimetypes:` (bytes reais) ou apenas `mimes:` (extensão)?
    - Arquivos salvos em disco `private` ou em `public`?
    - Nome do arquivo gerado pelo servidor (UUID) ou original do cliente?
    - URLs externas de imagem validadas contra whitelist de domínios?

[ ] 6. MASS ASSIGNMENT
    - Models com `$guarded = []`?
    - `$fillable` inclui campos sensíveis (role, is_admin)?

[ ] 7. AUTORIZAÇÃO E ROTAS
    - Rotas de admin protegidas com middleware de role?
    - Controllers sem `$this->authorize()` em métodos destrutivos?

[ ] 8. CSRF
    - `VerifyCsrfToken` tem exclusões indevidas (não-webhooks)?
    - `SANCTUM_STATEFUL_DOMAINS` configurado corretamente?

[ ] 9. SESSÃO E HEADERS
    - `SESSION_SECURE_COOKIE`, `SESSION_SAME_SITE`, `SESSION_ENCRYPT` no .env?
    - Middleware `SecurityHeaders` registrado globalmente?

[ ] 10. CREDENCIAIS NO CÓDIGO
    - Senhas comparadas com `Hash::check()` ou com `===`?
    - `APP_DEBUG` e `APP_ENV` corretos para produção?
    - Logs registram campos sensíveis (password, token)?

### PASSO 3 — Relatório de achados
Após concluir todas as verificações, apresente um relatório no formato:

```
## Relatório de Segurança — [Nome do Projeto]

### 🔴 Crítico (corrigir imediatamente)
- [arquivo:linha] Descrição do problema

### 🟡 Importante (corrigir antes do próximo deploy)
- [arquivo:linha] Descrição do problema

### 🟢 Sugestão (melhoria recomendada)
- [arquivo:linha] Descrição do problema

Total: X críticos | Y importantes | Z sugestões
```

### PASSO 4 — Aguardar aprovação antes de corrigir
Pergunte: "Quais itens você quer que eu corrija agora?"
Aguarde a resposta. Corrija um por vez, mostrando o diff de cada alteração.

## REGRAS DE OURO
- Nunca edite dois arquivos ao mesmo tempo sem aprovação
- Nunca force push sem confirmação explícita do usuário
- Se encontrar credencial já commitada, avise imediatamente e não toque no código antes da resposta do usuário
- Sempre que remover algo, explique o que foi removido e por quê
-->

> **Princípio fundamental: Nunca confie no frontend. Toda validação, autorização e sanitização acontece no servidor.**

---

## Índice

1. [IDOR — Insecure Direct Object Reference](#1-idor--insecure-direct-object-reference)
2. [SQL Injection](#2-sql-injection)
3. [Validação de Campos e Tamanhos](#3-validação-de-campos-e-tamanhos)
4. [Upload de Imagens e Arquivos](#4-upload-de-imagens-e-arquivos)
5. [Mass Assignment](#5-mass-assignment)
6. [Autorização e Roles](#6-autorização-e-roles)
7. [CSRF](#7-csrf)
8. [XSS — Cross-Site Scripting (Inertia/Vue)](#8-xss--cross-site-scripting-inertiavue)
9. [Rate Limiting](#9-rate-limiting)
10. [Sessão](#10-sessão)
11. [Headers de Segurança](#11-headers-de-segurança)
12. [Senhas e Credenciais](#12-senhas-e-credenciais)
13. [O Que Nunca Sobe ao Git](#13-o-que-nunca-sobe-ao-git)
14. [Checklist Pré-Deploy](#14-checklist-pré-deploy)

---

## 1. IDOR — Insecure Direct Object Reference

**Problema:** O usuário manipula IDs na URL/request para acessar recursos de outros usuários.

### Regra: Sempre escopar queries ao usuário autenticado

```php
// ❌ ERRADO — qualquer usuário logado acessa qualquer pedido
public function show(Order $order)
{
    return $order;
}

// ✅ CORRETO — garante que o pedido pertence ao usuário atual via Policy
public function show(Order $order)
{
    $this->authorize('view', $order);
    return $order;
}

// ✅ ALTERNATIVO — escopo direto na query
public function show(int $id)
{
    $order = auth()->user()->orders()->findOrFail($id);
    return $order;
}
```

### Criar Policies para cada recurso sensível

```bash
php artisan make:policy OrderPolicy --model=Order
```

```php
// app/Policies/OrderPolicy.php
class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        return $user->id === $order->user_id
            || $user->hasRole(['admin', 'super_admin']);
    }

    public function update(User $user, Order $order): bool
    {
        return $user->id === $order->user_id;
    }

    public function delete(User $user, Order $order): bool
    {
        return $user->hasRole('admin');
    }
}
```

```php
// Registrar em AppServiceProvider
Gate::policy(Order::class, OrderPolicy::class);
```

### Usar authorize() no topo de cada método de controller

```php
public function update(Request $request, Order $order)
{
    // Sempre no topo — antes de qualquer lógica de negócio
    $this->authorize('update', $order); // lança 403 automaticamente
    // ...
}
```

### Impedir escalação de role via request

```php
// ❌ ERRADO — usuário pode enviar role=admin no body
$user->update($request->all());

// ✅ CORRETO — nunca aceitar role vindo do request
$user->update($request->only(['name', 'email', 'phone']));
// role só é alterado por super_admin via fluxo dedicado
```

---

## 2. SQL Injection

**Problema:** Input do usuário é interpolado diretamente em queries SQL.

### Usar sempre Eloquent ou Query Builder com bindings

```php
// ❌ ERRADO — SQL injection direto
DB::select("SELECT * FROM users WHERE email = '{$request->email}'");

// ✅ CORRETO — binding parametrizado
DB::select('SELECT * FROM users WHERE email = ?', [$request->email]);

// ✅ MELHOR — usar Eloquent
User::where('email', $request->email)->first();
```

### Nunca executar SQL bruto de arquivos enviados pelo usuário

```php
// ❌ CRÍTICO — permite execução de qualquer SQL
$content = file_get_contents($request->file('import'));
DB::unprepared($content);

// ✅ CORRETO — parsear CSV com whitelist de campos
$rows = array_map('str_getcsv', file($request->file('import')->path()));
$allowed = ['name', 'email', 'phone']; // whitelist explícita
$allowedCount = count($allowed);

foreach ($rows as $row) {
    // Ignorar linhas com número incorreto de colunas
    if (count($row) !== $allowedCount) {
        continue;
    }

    $data = array_combine($allowed, $row);
    User::create($data); // $fillable protege o restante
}
```

### orderBy e LIKE dinâmicos — sempre whitelist

```php
// ❌ ERRADO — orderBy com coluna dinâmica não sanitizada
$query->orderBy($request->sort_by);

// ✅ CORRETO — whitelist de colunas permitidas
$allowedSorts = ['name', 'created_at', 'price'];
$sortBy = in_array($request->sort_by, $allowedSorts, true)
    ? $request->sort_by
    : 'created_at';
$query->orderBy($sortBy);

// Para LIKE, o Query Builder já escapa automaticamente:
$query->where('name', 'like', '%' . $request->search . '%');
```

---

## 3. Validação de Campos e Tamanhos

**Regra: Valide no servidor. A validação no frontend é UX, não segurança.**

### Limites explícitos em todo campo de texto

```php
// app/Http/Requests/StoreUserRequest.php
public function rules(): array
{
    return [
        'name'     => ['required', 'string', 'min:2', 'max:100'],
        // Nota: 'email:rfc,dns' faz lookup DNS no momento da validação.
        // Em ambientes sem DNS externo (CI, staging isolado), use apenas 'email:rfc'.
        'email'    => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
        'phone'    => ['nullable', 'string', 'max:20'],
        'bio'      => ['nullable', 'string', 'max:1000'],
        // bcrypt trunca silenciosamente acima de 72 bytes — max:72 é o limite real
        'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        // Campos numéricos sempre com min/max
        'age'      => ['required', 'integer', 'min:0', 'max:150'],
        'amount'   => ['required', 'numeric', 'min:0', 'max:999999.99'],
    ];
}
```

### Nunca usar $request->all() sem validação prévia

```php
// ❌ ERRADO
$data = $request->all();
User::create($data);

// ✅ CORRETO — FormRequest com regras explícitas
public function store(StoreUserRequest $request)
{
    User::create($request->validated());
}
```

### Sanitizar inputs quando necessário

```php
// Para campos HTML (rich text) — usar purificador
// composer require mews/purifier
$clean = Purifier::clean($request->content);

// Para strings simples — strip_tags é suficiente
$name = strip_tags($request->name);

// Para slugs/identificadores
$slug = Str::slug($request->slug);
```

---

## 4. Upload de Imagens e Arquivos

**Regra: Nunca confie no MIME type enviado pelo cliente. Valide os bytes reais do arquivo.**

### A diferença entre `mimes:` e `mimetypes:`

```
mimes:jpeg,png      → valida a EXTENSÃO do arquivo (fácil de burlar renomeando)
mimetypes:image/jpeg → valida os BYTES reais do arquivo via finfo (seguro)
```

Use sempre `mimetypes:` para validação de segurança:

```php
public function rules(): array
{
    return [
        'avatar' => [
            'required',
            'file',
            // mimetypes: usa finfo internamente — lê os bytes reais, não a extensão
            'mimetypes:image/jpeg,image/png,image/webp',
            // Tamanho máximo em KB (2MB = 2048)
            'max:2048',
            // Dimensões mínimas e máximas em pixels
            'dimensions:min_width=50,min_height=50,max_width=2000,max_height=2000',
        ],
    ];
}
```

### Verificação adicional com finfo (dupla garantia)

```php
use Illuminate\Http\UploadedFile;

public function validateImageMime(UploadedFile $file): bool
{
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];

    // finfo lê os bytes reais, independente do Content-Type do request
    $finfo = new \finfo(FILEINFO_MIME_TYPE);
    $realMime = $finfo->file($file->getPathname());

    return in_array($realMime, $allowedMimes, true);
}
```

### Nunca armazenar em pasta pública diretamente acessível

```php
// ❌ ERRADO — arquivo executável pode ser servido pelo servidor web
$path = $request->file('avatar')->store('avatars', 'public');

// ✅ CORRETO — armazenar fora do public, servir via controller com auth
$path = $request->file('avatar')->store('avatars', 'private');

// Controller para servir com verificação de autorização
public function serveAvatar(User $user)
{
    $this->authorize('view', $user);
    return Storage::disk('private')->response($user->avatar_path);
}
```

### Renomear o arquivo (nunca usar o nome original)

```php
// ❌ ERRADO — nome original pode conter path traversal ou sobrescrever arquivos
$name = $request->file('avatar')->getClientOriginalName();

// ✅ CORRETO — gerar nome único no servidor
// extension() detecta a extensão pelo MIME real, não pelo nome do arquivo
$extension = $request->file('avatar')->extension();
$path = $request->file('avatar')->storeAs(
    'avatars/' . auth()->id(),
    Str::uuid() . '.' . $extension,
    'private'
);
```

### Verificar se imagem pertence ao domínio atual (para URLs externas)

```php
// Quando aceitar URLs de imagens externas (ex: avatar via URL)
public function validateImageUrl(string $url): bool
{
    $parsed = parse_url($url);

    if (!isset($parsed['host'])) {
        return false;
    }

    // Whitelist de domínios permitidos — defina no .env ou config
    $allowedDomains = [
        parse_url(config('app.url'), PHP_URL_HOST), // próprio domínio
        'storage.googleapis.com',
        'seu-bucket.s3.amazonaws.com',
    ];

    // str_ends_with previne bypass via subdomínio (ex: evil.storage.googleapis.com.evil.com)
    foreach ($allowedDomains as $domain) {
        if ($parsed['host'] === $domain || str_ends_with($parsed['host'], '.' . $domain)) {
            return true;
        }
    }

    return false;
}

// No controller — fazer download e revalidar antes de salvar
public function updateAvatarUrl(Request $request)
{
    $request->validate(['avatar_url' => 'required|url|max:2048']);

    if (!$this->validateImageUrl($request->avatar_url)) {
        abort(422, 'URL de imagem não permitida.');
    }

    // Verificar Content-Length antes de baixar (se disponível)
    $maxBytes = 2 * 1024 * 1024; // 2MB
    $head = Http::timeout(5)->head($request->avatar_url);
    $contentLength = (int) $head->header('Content-Length');
    if ($contentLength > $maxBytes) {
        abort(422, 'Imagem muito grande.');
    }

    // Baixar com timeout — stream para arquivo temporário e limitar tamanho
    $tmpPath = tempnam(sys_get_temp_dir(), 'img_');
    $response = Http::timeout(10)->withOptions([
        'sink' => $tmpPath,
        'stream' => true,
    ])->get($request->avatar_url);

    if (!$response->successful() || filesize($tmpPath) > $maxBytes) {
        @unlink($tmpPath);
        abort(422, 'Não foi possível baixar a imagem ou tamanho excedido.');
    }

    $body = file_get_contents($tmpPath);
    @unlink($tmpPath);

    // Validar MIME real dos bytes baixados — nunca confiar no Content-Type do servidor externo
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
    $finfo = new \finfo(FILEINFO_MIME_TYPE);
    $realMime = $finfo->buffer($body);

    if (!in_array($realMime, $allowedMimes, true)) {
        abort(422, 'O arquivo baixado não é uma imagem válida.');
    }

    // Salvar com nome gerado pelo servidor
    $extension = match ($realMime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    };

    $path = 'avatars/' . auth()->id() . '/' . Str::uuid() . '.' . $extension;
    Storage::disk('private')->put($path, $body);

    auth()->user()->update(['avatar_path' => $path]);
}
```

### Limitar tamanho no servidor (nginx/php.ini)

```nginx
# nginx.conf
client_max_body_size 10M;
```

```ini
; php.ini
upload_max_filesize = 10M
post_max_size = 12M
```

---

## 5. Mass Assignment

**Problema:** `$fillable` muito aberto permite que o usuário grave campos que não deveria.

```php
// ❌ PERIGOSO — $guarded vazio aceita qualquer campo
class User extends Model
{
    protected $guarded = [];
}

// ✅ CORRETO — $fillable explícito com apenas os campos que o usuário pode preencher
class User extends Model
{
    protected $fillable = [
        'name',
        'email',
        'phone',
        // ❌ NUNCA incluir: 'role', 'is_admin', 'email_verified_at'
    ];
}

// Campos sensíveis são atualizados explicitamente via fluxos dedicados
public function promoteToAdmin(User $user): void
{
    // Apenas super_admin pode chamar isso — protegido por Policy
    $user->update(['role' => 'admin']);
}
```

---

## 6. Autorização e Roles

### Proteger rotas em grupos com middleware

```php
// routes/web.php
Route::middleware(['auth', 'verified'])->group(function () {

    // Rotas de admin
    Route::middleware(['role:admin,super_admin'])->group(function () {
        Route::resource('users', UserController::class);
        Route::resource('settings', SettingController::class);
    });

    // Rotas de usuário comum
    Route::resource('orders', OrderController::class);
});
```

### Checar autorização no início de cada método de controller

```php
public function update(Request $request, Post $post)
{
    $this->authorize('update', $post); // 403 automático se não autorizado
    $post->update($request->validated());
}
```

### Nunca expor IDs sequenciais em recursos sensíveis

```php
// ❌ RUIM — enumeration attack: /orders/1, /orders/2...
Route::get('/orders/{order}', [OrderController::class, 'show']);

// ✅ BOM — usar UUID
// Migration:
$table->uuid('id')->primary();

// Model — necessário quando usando UUID como PK:
class Order extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($model) => $model->id ??= (string) Str::uuid());
    }
}
```

---

## 7. CSRF

**Problema:** Requisições forjadas de outros domínios executam ações autenticadas em nome do usuário.

Laravel protege automaticamente via `VerifyCsrfToken` middleware para rotas web. **Nunca remova este middleware.**

### Inertia.js — proteção automática via cookie

O Laravel escreve o cookie `XSRF-TOKEN` com `http_only: false` automaticamente (via `VerifyCsrfToken`). O Inertia lê esse cookie e envia o header `X-XSRF-TOKEN` em todas as requisições. **Nenhuma configuração adicional é necessária** — não mexa em `config/session.php` para isso.

O cookie de sessão (`laravel_session`) permanece `http_only: true` por padrão e nunca deve ser alterado.

### Verificar exclusões do middleware

```php
// app/Http/Middleware/VerifyCsrfToken.php
class VerifyCsrfToken extends Middleware
{
    protected $except = [
        // ⚠️ Só excluir rotas de webhook com verificação própria (ex: Stripe signature)
        'webhooks/stripe',
        // ❌ NUNCA excluir rotas de usuário autenticado
    ];
}
```

### APIs REST — usar Sanctum com autenticação stateful

```php
// config/sanctum.php
'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
    '%s%s',
    'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
    env('APP_URL') ? ',' . parse_url(env('APP_URL'), PHP_URL_HOST) : ''
))),
```

---

## 8. XSS — Cross-Site Scripting (Inertia/Vue)

**Problema específico do Inertia:** Props PHP são passadas como JSON para o Vue. Se renderizadas com `v-html`, executam scripts maliciosos.

```vue
<!-- ❌ NUNCA usar v-html com dados do usuário -->
<div v-html="user.bio"></div>

<!-- ✅ CORRETO — interpolação padrão do Vue escapa automaticamente -->
<div>{{ user.bio }}</div>

<!-- Se precisar renderizar HTML (ex: rich text) — sanitize no servidor antes -->
<!-- E use um componente com lista branca de tags permitidas -->
```

### Sanitizar HTML rico antes de salvar (não só ao exibir)

```php
// composer require mews/purifier
// Configurar tags permitidas em config/purifier.php

$post->content = Purifier::clean($request->content);
$post->save();
```

### Content Security Policy reforça a defesa

O CSP configurado na seção de headers limita os scripts que podem executar mesmo se XSS ocorrer.

---

## 9. Rate Limiting

```php
// bootstrap/app.php (Laravel 11+) ou RouteServiceProvider

RateLimiter::for('login', function (Request $request) {
    return [
        Limit::perMinute(5)->by($request->ip()),
        Limit::perMinute(10)->by($request->input('email')),
    ];
});

RateLimiter::for('api', function (Request $request) {
    return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
});

RateLimiter::for('uploads', function (Request $request) {
    // Usar ?-> e fallback para IP caso o middleware seja aplicado antes do auth
    return Limit::perHour(20)->by($request->user()?->id ?: $request->ip());
});
```

```php
// Aplicar nas rotas
Route::post('/login', ...)->middleware('throttle:login');
Route::post('/avatars', ...)->middleware(['auth', 'throttle:uploads']);
```

---

## 10. Sessão

```php
// .env — produção
SESSION_DRIVER=database        # ou redis — nunca 'file' em multi-servidor
SESSION_LIFETIME=120           # minutos de inatividade
SESSION_ENCRYPT=true           # criptografar o conteúdo da sessão
SESSION_SECURE_COOKIE=true     # apenas HTTPS
SESSION_SAME_SITE=lax          # protege contra CSRF cross-origin
```

```php
// config/session.php — confirmar
'http_only' => true,           // session cookie não acessível via JS
'secure'    => env('SESSION_SECURE_COOKIE', true),
'same_site' => env('SESSION_SAME_SITE', 'lax'),
```

### Regenerar session ID após login

O Laravel/Fortify já faz isso automaticamente. Se implementar autenticação manual:

```php
$request->session()->regenerate(); // após autenticar o usuário
```

---

## 11. Headers de Segurança

```php
// app/Http/Middleware/SecurityHeaders.php
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // 'unsafe-inline' em style-src é necessário para Vue com estilos scoped.
        // Para ambientes mais rígidos, use nonces ou hash das folhas de estilo.
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:;"
        );

        // Remover headers que expõem informações do servidor
        $response->headers->remove('X-Powered-By');
        $response->headers->remove('Server');

        // Nota: X-XSS-Protection foi removido do spec W3C e pode introduzir bugs
        // em navegadores antigos. A proteção correta contra XSS é o CSP acima.

        return $response;
    }
}
```

```php
// bootstrap/app.php (Laravel 11+)
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(SecurityHeaders::class);
})
```

---

## 12. Senhas e Credenciais

```php
// ❌ ERRADO — comparação em texto plano
if ($request->password === config('app.master_password')) { ... }

// ✅ CORRETO — sempre Hash::check()
if (!Hash::check($request->password, $user->password)) {
    abort(403, 'Senha incorreta.');
}

// ✅ Gerar senha temporária segura (nunca hardcodar)
$temporaryPassword = Str::password(length: 12, symbols: false);
// Mostrar UMA vez ao admin na UI, nunca salvar em log

// ❌ NUNCA commitar no git — .env deve estar no .gitignore
// Usar variáveis de ambiente para todas as credenciais
```

### APP_DEBUG em produção — risco crítico

`APP_DEBUG=true` em produção expõe stack traces completos, variáveis de ambiente e credenciais do banco diretamente na resposta HTTP. Qualquer erro gera um vazamento de informações.

```php
// .env — produção obrigatório
APP_DEBUG=false
APP_ENV=production
```

### Nunca expor config() na API

```php
// ❌ ERRADO — expõe todas as configs incluindo credenciais
return response()->json(config()->all());

// ✅ CORRETO — retornar apenas o que o cliente precisa
return response()->json([
    'app_name' => config('app.name'),
    'timezone' => config('app.timezone'),
]);
```

### Logs — nunca registrar dados sensíveis

```php
// ❌ ERRADO — password aparece no log
Log::info('Login attempt', $request->all());

// ✅ CORRETO — excluir campos sensíveis
Log::info('Login attempt', $request->except(['password', 'password_confirmation', 'token']));
```

---

## 13. O Que Nunca Sobe ao Git

**Regra: Se tem IP, senha, caminho de servidor ou chave — fica fora do repositório.**

### .gitignore obrigatório

```gitignore
# Variáveis de ambiente — NUNCA commitar
.env
.env.*
!.env.example      # apenas o exemplo sem valores reais vai no git

# Deploy — contém IP do servidor e caminho da pasta
deploy.php
deployer.php
deploy/
.deployer/

# Chaves SSH e certificados
*.pem
*.key
*.p12
*.pfx
id_rsa
id_ed25519

# Configurações locais de IDE / editor
.idea/
.vscode/settings.json
*.local

# Logs — podem conter dados sensíveis
storage/logs/
*.log

# Dependências — nunca commitar
/vendor/
/node_modules/
```

### .env — nunca commitar, sempre usar .env.example

```bash
# .env.example — vai no git, sem valores reais
APP_NAME=
APP_ENV=local
APP_KEY=
APP_DEBUG=false
APP_URL=

DB_HOST=
DB_PORT=3306
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=

MAIL_HOST=
MAIL_USERNAME=
MAIL_PASSWORD=

AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_BUCKET=
```

```bash
# No servidor de produção — gerar chave sem commitar
php artisan key:generate
```

### deploy.php — IP e pasta do servidor

O `deploy.php` (Deployer) contém o IP do servidor e o caminho do diretório de deploy. **Nunca deve entrar no repositório.**

```php
// deploy.php — FORA DO GIT (.gitignore)

host('SEU_IP_AQUI')              // ❌ IP real do servidor
    ->set('deploy_path', '/var/www/projeto') // ❌ caminho real
    ->set('remote_user', 'deploy');
```

**Alternativa segura:** manter um `deploy.example.php` no repositório com placeholders:

```php
// deploy.example.php — PODE ir no git (sem dados reais)

host(env('DEPLOY_HOST', 'SEU_IP_AQUI'))
    ->set('deploy_path', env('DEPLOY_PATH', '/var/www/projeto'))
    ->set('remote_user', env('DEPLOY_USER', 'deploy'));
```

```bash
# .env.deploy (local, fora do git)
DEPLOY_HOST=192.168.1.100
DEPLOY_PATH=/var/www/meu-projeto
DEPLOY_USER=deploy
```

### Verificar se algo sensível já subiu

```bash
# Buscar no histórico do git por possíveis vazamentos
git log --all --full-history -- .env
git log --all --full-history -- deploy.php

# Buscar strings suspeitas no histórico (senhas, IPs)
git grep -i "password\s*=" $(git rev-list --all)
git grep -i "DB_PASSWORD" $(git rev-list --all)

# Listar todos os arquivos que já existiram no repositório
git log --all --name-only --format="" | sort -u | grep -E "\.(env|pem|key)$"
```

> **Se um segredo já foi commitado**, considere-o comprometido mesmo após remoção.
> Ações necessárias:
> 1. Revogar/rotacionar a credencial imediatamente
> 2. Remover do histórico com `git filter-repo` (não apenas `git rm`)
> 3. Forçar todos os colaboradores a recriar clones

### Verificação automática com git hooks

```bash
# .git/hooks/pre-commit — bloquear commit de arquivos sensíveis
#!/bin/sh

BLOCKED=".env deploy.php *.pem *.key"

for pattern in $BLOCKED; do
    if git diff --cached --name-only | grep -qE "$pattern"; then
        echo "❌ BLOQUEADO: tentativa de commitar arquivo sensível ($pattern)"
        echo "   Adicione ao .gitignore e use 'git rm --cached <arquivo>'"
        exit 1
    fi
done

# Bloquear strings de senha/IP hardcoded
if git diff --cached | grep -iE "(password|secret|api_key)\s*=\s*['\"][^'\"]{4,}"; then
    echo "❌ BLOQUEADO: possível credencial hardcoded detectada no diff"
    exit 1
fi
```

```bash
# Tornar o hook executável
chmod +x .git/hooks/pre-commit
```

---

## 14. Checklist Pré-Deploy

### Autorização / IDOR
- [ ] Todos os controllers de recursos sensíveis têm `$this->authorize()`
- [ ] Rotas agrupadas com middleware de role/permission
- [ ] Policies registradas para cada Model sensível
- [ ] Campo `role` nunca aceito via mass assignment
- [ ] IDs sequenciais em rotas públicas substituídos por UUID

### SQL / Queries
- [ ] Zero uso de `DB::unprepared()` com input do usuário
- [ ] `orderBy` e `groupBy` dinâmicos usam whitelist de colunas
- [ ] Imports CSV/JSON usam whitelist de `$fillable` com verificação de tamanho

### Validação
- [ ] Todo campo tem `min` e `max` definidos
- [ ] FormRequests usados em vez de `$request->all()`
- [ ] `email:rfc` (ou `email:rfc,dns` se o ambiente tiver DNS externo)
- [ ] `password` limitado a `max:72` (limite real do bcrypt)

### Upload / Imagens
- [ ] `mimetypes:` (não `mimes:`) para validar bytes reais do arquivo
- [ ] `max:` em KB definido para cada upload
- [ ] Arquivo armazenado fora de `public/` (disco `private`)
- [ ] Nome do arquivo gerado com `Str::uuid()` no servidor
- [ ] URLs de imagens externas validadas contra whitelist de domínios
- [ ] MIME dos bytes baixados revalidado com `finfo` antes de salvar
- [ ] `client_max_body_size` configurado no nginx

### CSRF / Sessão
- [ ] `VerifyCsrfToken` middleware ativo (sem exclusões indevidas)
- [ ] `SANCTUM_STATEFUL_DOMAINS` configurado corretamente
- [ ] `SESSION_SECURE_COOKIE=true` em produção
- [ ] `SESSION_SAME_SITE=lax` em produção
- [ ] `SESSION_ENCRYPT=true` em produção

### XSS
- [ ] Nenhum `v-html` com dados de usuário no frontend
- [ ] HTML rico sanitizado com Purifier antes de salvar no banco

### Rate Limiting
- [ ] Rate limit em login por IP e por e-mail
- [ ] Rate limit em upload por usuário
- [ ] Rate limit em endpoints críticos (senhas, pagamentos)

### Credenciais / Config
- [ ] `APP_DEBUG=false` em produção
- [ ] `APP_ENV=production` em produção
- [ ] `.env` no `.gitignore`
- [ ] Senhas comparadas com `Hash::check()`, nunca `===`
- [ ] Nenhum `config()->all()` exposto via API
- [ ] Logs não registram campos sensíveis (password, token)

### Git — Segredos e Variáveis Sensíveis
- [ ] `.env` no `.gitignore` (e todos os `.env.*`)
- [ ] `deploy.php` no `.gitignore` (contém IP e caminho do servidor)
- [ ] `.env.example` existe no repositório sem valores reais
- [ ] `deploy.example.php` existe com placeholders em vez de dados reais
- [ ] Nenhum IP de servidor hardcoded em arquivo commitado
- [ ] Nenhum caminho absoluto de servidor em arquivo commitado (`/var/www/...`)
- [ ] Chaves SSH e certificados (`.pem`, `.key`) no `.gitignore`
- [ ] Hook `pre-commit` configurado para bloquear arquivos sensíveis
- [ ] Histórico do git verificado: `git log --all -- .env` retorna vazio

---

> **Decisões de segurança deste projeto:**
>
> - Domínios de imagem permitidos: `[ ]`
> - Roles existentes: `[ ]`
> - Tamanho máximo de upload: `[ ]`
> - Rate limits customizados: `[ ]`
> - Campos excluídos do CSRF: `[ ]` (apenas webhooks com verificação própria)
