<?php
declare(strict_types=1);

namespace Club;

require_once __DIR__ . '/Core/Env.php';
require_once __DIR__ . '/Core/Database.php';
require_once __DIR__ . '/Core/Session.php';
require_once __DIR__ . '/Views/Layout.php';

use Club\Core\Database;
use Club\Core\Env;
use Club\Core\Session;
use Club\Views\Layout;
use PDO;
use PDOException;

final class App
{
    private array $env;
    private Database $database;
    private string $authDiagnostic = '';

    private function __construct(array $env)
    {
        $this->env = $env;
        $this->database = new Database($env);
    }

    public static function boot(): self
    {
        $root = dirname(__DIR__);
        $fileEnv = Env::load($root . '/.env');
        $runtimeEnv = array_filter(getenv() ?: [], static fn(mixed $value): bool => is_string($value));
        $env = array_merge($fileEnv, $runtimeEnv);
        // Ponte temporária somente para desenvolvimento local: evita copiar
        // credenciais para outra pasta enquanto a versão PHP é testada.
        if (($env['DATABASE_URL'] ?? '') === '') {
            $env = array_merge(Env::load(dirname($root) . '/club das vendas/.env.local'), $env);
        }
        date_default_timezone_set('America/Sao_Paulo');

        Session::start($env);

        return new self($env);
    }

    public function run(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $path = rtrim($path, '/') ?: '/';

        try {
            if ($method === 'GET' && $path === '/') {
                $this->catalog();
                return;
            }
            if ($path === '/login') {
                $method === 'POST' ? $this->loginAttempt() : $this->loginPage();
                return;
            }
            if ($method === 'POST' && $path === '/logout') {
                $this->verifyCsrf();
                session_unset();
                session_destroy();
                $this->redirect('/login');
            }
            if ($method === 'GET' && preg_match('#^/produto/([a-f0-9-]{36})$#i', $path, $matches)) {
                $this->productPage($matches[1]);
                return;
            }
            if ($method === 'GET' && preg_match('#^/promocao/([a-f0-9-]{36})$#i', $path, $matches)) {
                $this->promotionPage($matches[1]);
                return;
            }
            if ($method === 'GET' && $path === '/carrinho') {
                $this->cartPage();
                return;
            }
            if ($method === 'GET' && $path === '/favoritos') {
                $this->favoritesPage();
                return;
            }
            if ($method === 'GET' && $path === '/categorias') {
                $this->categoriesPage();
                return;
            }
            if ($method === 'GET' && $path === '/novidades') {
                $this->newArrivalsPage();
                return;
            }
            if ($method === 'GET' && $path === '/checkout') {
                $this->checkoutPage();
                return;
            }
            if ($method === 'GET' && preg_match('#^/pedido/([a-f0-9-]{36})$#i', $path, $matches)) {
                $this->orderConfirmationPage($matches[1]);
                return;
            }
            if ($method === 'POST' && $path === '/api/orders') {
                $this->createOrder();
                return;
            }
            if ($method === 'GET' && preg_match('#^/api/cep/(\d{8})$#', $path, $matches)) {
                $this->cepLookup($matches[1]);
                return;
            }
            if ($method === 'GET' && preg_match('#^/api/cupom/([A-Za-z0-9_-]{1,64})$#', $path, $matches)) {
                $this->couponLookup($matches[1]);
                return;
            }
            if (str_starts_with($path, '/admin')) {
                $this->requireAdmin();
                if ($method === 'GET' && preg_match('#^/admin/campanhas/([a-f0-9-]{36})$#i', $path, $matches)) {
                    $this->campaignResultsPage($matches[1]);
                    return;
                }
                if ($method === 'GET' && $path === '/admin/campanhas/agendadas') {
                    $this->scheduledCampaignsPage();
                    return;
                }
                if ($path === '/admin/produtos' && $method === 'POST') {
                    $this->productSubmit();
                    return;
                }
                if ($path === '/admin/configuracoes' && $method === 'POST') {
                    $this->settingsSubmit();
                    return;
                }
                if ($path === '/admin/cupons' && $method === 'POST') {
                    $this->couponSubmit();
                    return;
                }
                if ($path === '/admin/movimentacoes' && $method === 'POST') {
                    $this->movementSubmit();
                    return;
                }
                if ($path === '/admin/categorias' && $method === 'POST') {
                    $this->categorySubmit();
                    return;
                }
                if ($path === '/admin/pedidos' && $method === 'POST') {
                    $this->orderStatusSubmit();
                    return;
                }
                if ($path === '/admin/campanhas' && $method === 'POST') {
                    $this->campaignSubmit();
                    return;
                }
                if ($method !== 'GET') {
                    $this->notFound();
                }
                $this->adminPage($path);
                return;
            }
            $this->notFound();
        } catch (PDOException) {
            $this->errorPage('Não foi possível conectar ao banco de dados.', 503);
        } catch (\Throwable) {
            $this->errorPage('Ocorreu um erro inesperado.', 500);
        }
    }

    private function db(): PDO
    {
        return $this->database->connection();
    }

    private function catalog(): void
    {
        $store = $this->store();
        $theme = $this->theme($store['id']);
        $activeCategory = trim((string) ($_GET['categoria'] ?? 'Todos'));
        $search = trim((string) ($_GET['busca'] ?? ''));
        $sort = (string) ($_GET['ordem'] ?? 'featured');
        $where = ['p.store_id = :store', 'p.active = true', 'c.active = true'];
        $params = ['store' => $store['id']];
        if ($activeCategory !== '' && $activeCategory !== 'Todos') {
            $where[] = 'c.name = :category';
            $params['category'] = $activeCategory;
        }
        if ($search !== '') {
            $where[] = 'p.name ILIKE :search';
            $params['search'] = '%' . $search . '%';
        }
        $order = $sort === 'low' ? 'p.price_cents ASC' : ($sort === 'high' ? 'p.price_cents DESC' : 'p.created_at DESC');
        $products = $this->db()->prepare(
            'SELECT p.id, p.slug, p.name, p.description, p.price_cents, p.media, p.variations, p.stock, c.name AS category_name
             FROM products p JOIN categories c ON c.id = p.category_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $order . ' LIMIT 60'
        );
        $products->execute($params);
        $categories = $this->db()->prepare("SELECT name, image_url, image_position FROM categories WHERE store_id = :store AND (active = true OR lower(name) = 'todos') ORDER BY name");
        $categories->execute(['store' => $store['id']]);
        $allCategories = $categories->fetchAll();
        $couponQuery = $this->db()->prepare("SELECT code,title,description,discount_type,discount_value FROM coupons WHERE store_id=:store AND active=true AND ends_at > now() AND (starts_at IS NULL OR starts_at <= now()) ORDER BY created_at DESC LIMIT 20");
        $couponQuery->execute(['store' => $store['id']]); $activeCoupons = $couponQuery->fetchAll();
        $this->layout('Catálogo ' . $store['name'], function () use ($store, $products, $theme, $allCategories, $activeCategory, $search, $sort, $activeCoupons): void {
            $primary = $this->e($theme['primaryColor'] ?? '#D7A843');
            $secondary = $this->e($theme['secondaryColor'] ?? '#15100B');
            $accent = $this->e($theme['accentColor'] ?? '#F4E9D6');
            $logo = (string) ($theme['logoUrl'] ?? '/images/logo.jpeg');
            $banner = (string) ($theme['bannerUrl'] ?? '/images/logo.jpeg');
            $background = (string) ($theme['catalogBackgroundUrl'] ?? '');
            echo '<main class="catalogMain" style="--catalog-primary:' . $primary . ';--catalog-secondary:' . $secondary . ';--catalog-accent:' . $accent . ';' . ($background ? '--catalog-background:url(' . $this->e($background) . ');' : '') . '">';
            echo '<header class="topbar"><a class="brand" href="#inicio"><img src="' . $this->e($logo) . '" alt="Logo ' . $this->e($store['name']) . '"><span><b>' . $this->e($store['name']) . '</b></span></a><div class="headerActions"><a class="headerCart" href="/carrinho" aria-label="Abrir carrinho"><img src="/images/carrinho.png" alt=""></a></div></header>';
            echo '<section class="hero" id="inicio"><img class="heroBackground" src="' . $this->e($banner) . '" alt=""><div class="heroOverlay"></div><div class="heroContent"><p class="eyebrow" style="color:' . $this->e($theme['heroEyebrowColor'] ?? '#E2BB67') . '">' . $this->e($theme['heroEyebrow'] ?? 'CATÁLOGO ONLINE') . '</p><h1 style="color:' . $this->e($theme['heroTitleColor'] ?? '#FFFFFF') . '">' . $this->e($theme['heroTitle'] ?? 'Seu estilo.') . '<br><em style="color:' . $this->e($theme['heroHighlightColor'] ?? '#D7A843') . '">' . $this->e($theme['heroHighlight'] ?? 'Seu momento.') . '</em></h1><p class="intro" style="color:' . $this->e($theme['heroDescriptionColor'] ?? '#F4EEE5') . '">' . $this->e($theme['heroDescription'] ?? 'Conheça nossas peças, detalhes e formas de compra.') . '</p><a class="outlineButton" href="#catalogo">' . $this->e($theme['ctaLabel'] ?? 'VER CATÁLOGO') . ' <span>›</span></a></div><ul class="qualities"><li>◇ <span><b>QUALIDADE</b> EM CADA DETALHE</span></li><li>▱ <span><b>ATENDIMENTO</b> VIA WHATSAPP</span></li><li>♥ <span><b>ESCOLHAS QUE</b> COMBINAM COM VOCÊ</span></li></ul></section>';
            echo '<div class="couponModal" data-coupon-modal hidden><div class="couponModalShade" data-coupon-close></div><section role="dialog" aria-modal="true" aria-label="Cupons disponíveis"><button type="button" class="couponModalClose" data-coupon-close aria-label="Fechar">×</button><p>OFERTAS DISPONÍVEIS</p><h2>Cupons para você</h2><span>Copie um cupom e aplique no carrinho.</span><div class="couponModalList">';
            foreach ($activeCoupons as $coupon) { $description = $coupon['description'] ?: ($coupon['discount_type'] === 'PERCENT' ? (int) $coupon['discount_value'] . '% de desconto' : 'R$ ' . $this->money((int) $coupon['discount_value']) . ' de desconto'); echo '<article><div><b>' . $this->e($coupon['title']) . '</b><span>' . $this->e($description) . '</span><code>' . $this->e($coupon['code']) . '</code></div><button type="button" data-copy-coupon="' . $this->e($coupon['code']) . '">COPIAR</button></article>'; }
            if (!$activeCoupons) echo '<p class="couponModalIntro">Não há cupons ativos no momento.</p>';
            echo '</div><a class="goCartCoupon" href="/carrinho">IR PARA O CARRINHO</a></section></div>';
            echo '<section class="categoryStrip" aria-label="Categorias"><button type="button" class="couponCategoryButton" data-coupon-open aria-label="Ver cupons disponíveis"><span class="categoryImage couponCategoryImage"><img src="/images/cupons-categoria.png" alt=""></span><b>Cupons</b></button>';
            $registeredAll = null; $otherCategories = [];
            foreach ($allCategories as $category) {
                if (mb_strtolower(trim((string) $category['name'])) === 'todos' && $registeredAll === null) $registeredAll = $category;
                else $otherCategories[] = $category;
            }
            $all = array_merge([$registeredAll ?: ['name' => 'Todos', 'image_url' => null, 'image_position' => '50% 50%']], $otherCategories);
            foreach ($all as $index => $category) {
                $query = mb_strtolower(trim((string) $category['name'])) === 'todos' ? '' : http_build_query(['categoria' => $category['name']]);
                $style = !empty($category['image_url']) ? ' style="background-image:url(\'' . $this->e($category['image_url']) . '\');background-position:' . $this->e($category['image_position'] ?? '50% 50%') . '"' : '';
                echo '<a class="' . ($activeCategory === $category['name'] ? 'active' : '') . '" href="/' . ($query ? '?' . $query : '') . '#catalogo"><span class="categoryImage c' . ($index + 1) . '"' . $style . '></span><b>' . $this->e($category['name']) . '</b></a>';
            }
            echo '</section><section class="showcase" id="catalogo"><div class="sectionTitle"><p>DESTAQUES</p><a href="/#catalogo">VER TODOS ›</a></div><form class="catalogTools" method="get"><label><span>⌕</span><input name="busca" value="' . $this->e($search) . '" placeholder="Buscar peça" aria-label="Buscar produto"></label><select name="ordem" onchange="this.form.submit()"><option value="featured"' . ($sort === 'featured' ? ' selected' : '') . '>Destaques</option><option value="low"' . ($sort === 'low' ? ' selected' : '') . '>Menor preço</option><option value="high"' . ($sort === 'high' ? ' selected' : '') . '>Maior preço</option></select></form><div class="grid">';
            foreach ($products as $product) {
                $media = json_decode((string) $product['media'], true) ?: [];
                $image = is_array($media) && isset($media[0]) ? (is_array($media[0]) ? ($media[0]['url'] ?? '') : $media[0]) : '';
                $soldOut = $this->stockTotal($product['stock']) < 1;
                $images = array_values(array_filter(array_map(static fn (mixed $item): string => is_array($item) ? (string) ($item['url'] ?? '') : (string) $item, $media)));
                $productData = $this->productPayload($product, $images);
                echo '<article class="product clickable"><a class="productImage" href="/produto/' . $this->e($product['id']) . '" data-images="' . $this->e(json_encode($images)) . '">' . ($image ? '<img src="' . $this->e((string) $image) . '" alt="' . $this->e($product['name']) . '" loading="lazy">' : '<span class="noPhoto">SEM FOTO</span>') . '<button class="favorite" type="button" data-favorite="' . $this->e(json_encode($productData)) . '" aria-label="Favoritar ' . $this->e($product['name']) . '">♡</button>' . (count($images) > 1 ? '<button class="photoArrow previous" type="button" aria-label="Foto anterior">‹</button><button class="photoArrow next" type="button" aria-label="Próxima foto">›</button><span class="photoDots">' . str_repeat('<i></i>', count($images)) . '</span>' : '') . '</a><h2>' . $this->e($product['name']) . '</h2><p>R$ ' . $this->money((int) $product['price_cents']) . '</p><a class="productDetails" href="/produto/' . $this->e($product['id']) . '">VER DETALHES</a>' . ($soldOut ? '<button class="buy soldOutButton" disabled>ESGOTADO</button>' : '<a class="buy" href="/produto/' . $this->e($product['id']) . '">ADICIONAR AO CARRINHO</a>') . '</article>';
            }
            echo '</div></section><section class="storeInfoShortcut"><p>Dúvidas sobre entrega, trocas ou privacidade?</p><a href="/informacoes">VER INFORMAÇÕES DA LOJA</a></section>';
            if (!empty($store['whatsapp'])) echo '<a class="whatsappFloat" href="https://wa.me/' . $this->e($store['whatsapp']) . '" target="_blank" rel="noopener" aria-label="Falar no WhatsApp"><img src="/images/whatsapp.png" alt=""></a>';
            echo '<nav class="bottomNav" aria-label="Navegação inferior"><a class="selected" href="#inicio">⌂<span>Início</span></a><a href="/categorias">⊞<span>Categorias</span></a><a href="/favoritos">♡<span>Favoritos</span></a><a href="/novidades">◇<span>Novidades</span></a></nav></main>';
        });
    }

    private function loginPage(string $message = ''): void
    {
        $this->layout('Entrar no painel', function () use ($message): void {
            echo '<main class="auth-page"><form class="auth-card" method="post" action="/login"><span class="auth-mark">LM</span>';
            echo '<p class="eyebrow">CLUB DA VENDA</p><h1>Acesso administrativo</h1>';
            if ($message !== '') echo '<p class="notice error">' . $this->e($message) . '</p>';
            echo $this->csrfField();
            echo '<section class="evaluation-access" aria-label="Acesso para avaliação"><strong>ACESSO DISPONÍVEL PARA AVALIAÇÃO</strong><p>Use as credenciais abaixo para conhecer o painel administrativo.</p><div class="evaluation-credential"><span><small>E-mail</small><b>lmstoreludy@gmail.com</b></span><button type="button" class="copy-evaluation-credential" data-copy-value="lmstoreludy@gmail.com">COPIAR</button></div><div class="evaluation-credential"><span><small>Senha</small><b>LmAcesso2026!Seguro</b></span><button type="button" class="copy-evaluation-credential" data-copy-value="LmAcesso2026!Seguro">COPIAR</button></div></section>';
            echo '<label>E-mail<input type="email" name="email" autocomplete="email" required></label>';
            echo '<label>Senha<input type="password" name="password" autocomplete="current-password" required></label>';
            echo '<button type="submit">ENTRAR COM SEGURANÇA</button><a class="auth-link" href="https://loiramorenastore.com.br/redefinir-senha">Esqueci minha senha</a><small class="auth-footnote">Conta temporária criada exclusivamente para avaliação.</small></form></main>';
        });
    }

    private function promotionPage(string $id): void
    {
        $store = $this->store();
        $campaignQuery = $this->db()->prepare("SELECT id, name, message, promotion_rules, status FROM campaigns WHERE id = :id AND store_id = :store AND archived_at IS NULL AND status IN ('PROCESSANDO','ENVIADA','PARCIAL') LIMIT 1");
        $campaignQuery->execute(['id' => $id, 'store' => $store['id']]); $campaign = $campaignQuery->fetch();
        if (!$campaign) { $this->notFound(); }
        $productsQuery = $this->db()->prepare('SELECT p.id, p.name, p.description, p.price_cents, p.media, p.variations, p.stock, c.name AS category_name FROM campaign_products cp JOIN products p ON p.id = cp.product_id JOIN categories c ON c.id = p.category_id WHERE cp.campaign_id = :campaign AND p.active = true ORDER BY cp.position');
        $productsQuery->execute(['campaign' => $id]); $products = $productsQuery->fetchAll(); $rules = json_decode((string) ($campaign['promotion_rules'] ?? '[]'), true); if (!is_array($rules)) $rules = [];
        $this->layout($campaign['name'] . ' - ' . $store['name'], function () use ($campaign, $products, $rules, $store): void {
            echo '<main class="catalogMain promotionPage"><header class="topbar"><a class="brand" href="/"><img src="/images/logo.jpeg" alt=""><span><b>' . $this->e($store['name']) . '</b></span></a><div class="headerActions"><a class="headerCart" href="/carrinho" aria-label="Abrir carrinho"><img src="/images/carrinho.png" alt=""></a></div></header><section class="promotionHeader"><p class="eyebrow">OFERTAS DA CAMPANHA</p><h1>' . $this->e($campaign['name']) . '</h1><p>' . nl2br($this->e($campaign['message'])) . '</p>';
            foreach ($rules as $rule) { if (!is_array($rule) || empty($rule['quantity']) || !isset($rule['bundlePriceCents'])) continue; echo '<div class="promotionRuleBanner"><b>LEVE ' . (int) $rule['quantity'] . ' POR R$ ' . $this->money((int) $rule['bundlePriceCents']) . '</b><span>' . (($rule['mode'] ?? '') === 'MIXED_PRODUCTS' ? ' podendo combinar as peças marcadas' : ' da mesma peça') . '</span></div>'; }
            echo '</section><section class="showcase"><div class="sectionTitle"><p>PEÇAS SELECIONADAS</p><a href="/">VER CATÁLOGO COMPLETO ›</a></div><div class="grid">';
            foreach ($products as $product) { $media = json_decode((string) $product['media'], true) ?: []; $images = array_values(array_filter(array_map(static fn(mixed $item): string => is_array($item) ? (string) ($item['url'] ?? '') : (string) $item, $media))); $image = $images[0] ?? ''; $payload = $this->productPayload($product, $images); echo '<article class="product"><a class="productImage" href="/produto/' . $this->e($product['id']) . '" data-images="' . $this->e(json_encode($images)) . '">' . ($image ? '<img src="' . $this->e($image) . '" alt="' . $this->e($product['name']) . '">' : '<span class="noPhoto">SEM FOTO</span>') . '</a><h2>' . $this->e($product['name']) . '</h2><p>R$ ' . $this->money((int) $product['price_cents']) . '</p><a class="productDetails" href="/produto/' . $this->e($product['id']) . '">VER DETALHES</a><button class="buy" type="button" data-add-cart="' . $this->e(json_encode($payload)) . '">ADICIONAR AO CARRINHO</button></article>'; }
            echo '</div></section></main>';
        });
    }

    private function productPage(string $id): void
    {
        $store = $this->store();
        $product = $this->findProduct($id, $store['id']);
        if (!$product) { $this->notFound(); }
        $media = json_decode((string) $product['media'], true) ?: [];
        $images = array_values(array_filter(array_map(static fn (mixed $item): string => is_array($item) ? (string) ($item['url'] ?? '') : (string) $item, $media)));
        $payload = $this->productPayload($product, $images);
        $this->layout($product['name'] . ' - ' . $store['name'], function () use ($store, $product, $images, $payload): void {
            $variations = $payload['variations'];
            echo '<main class="productPage"><header class="cartHeader"><a href="/">← Voltar ao catálogo</a><b>' . $this->e($store['name']) . '</b></header><section class="productLayout"><div class="productGallery"><div class="mainMedia" data-images="' . $this->e(json_encode($images)) . '">' . (!empty($images) ? '<img src="' . $this->e($images[0]) . '" alt="' . $this->e($product['name']) . '">' : '<span>SEM FOTO</span>') . (count($images) > 1 ? '<button class="galleryArrow previous" type="button">‹</button><button class="galleryArrow next" type="button">›</button>' : '') . '</div><div class="mediaThumbs">';
            foreach ($images as $index => $image) echo '<button type="button" class="' . ($index === 0 ? 'selected' : '') . '" style="background-image:url(\'' . $this->e($image) . '\')" data-gallery-index="' . $index . '" aria-label="Ver foto ' . ($index + 1) . '"></button>';
            echo '</div></div><div class="productInfo"><p class="eyebrow">' . $this->e(strtoupper($product['category_name'])) . '</p><h1>' . $this->e($product['name']) . '</h1><p class="detailPrice">R$ ' . $this->money((int) $product['price_cents']) . '</p><p class="description">' . nl2br($this->e($product['description'])) . '</p>';
            foreach ($variations as $group) {
                if (!is_array($group) || empty($group['id']) || !is_array($group['values'] ?? null)) continue;
                echo '<div class="variantGroup"><b>' . $this->e($group['name'] ?? 'Opção') . '</b><div>';
                foreach ($group['values'] as $value) echo '<button type="button" data-variant-group="' . $this->e($group['id']) . '" data-variant-value="' . $this->e($value) . '">' . $this->e($value) . '</button>';
                echo '</div></div>';
            }
            echo '<div class="detailNote">Escolha todas as opções disponíveis antes de adicionar.</div><div class="detailActions"><button class="finish" type="button" data-add-cart="' . $this->e(json_encode($payload)) . '">ADICIONAR AO CARRINHO</button><button class="detailFavorite" type="button" data-favorite="' . $this->e(json_encode($payload)) . '">♡ Favoritar</button></div><a class="share" href="https://wa.me/' . $this->e($store['whatsapp']) . '?text=' . rawurlencode('Olá! Tenho interesse em ' . $product['name']) . '" target="_blank" rel="noopener">↗ Compartilhar produto</a></div></section></main>';
        });
    }

    private function cartPage(): void
    {
        $store = $this->store();
        $this->layout('Carrinho - ' . $store['name'], function () use ($store): void {
            echo '<main class="cartPage"><header class="cartHeader"><a href="/">← Voltar ao catálogo</a><b>' . $this->e($store['name']) . '</b></header><section class="cartContent" data-cart-page><p class="eyebrow">SEU CARRINHO</p><h1>Itens escolhidos</h1><div class="empty"><p>Carregando seu carrinho…</p></div></section></main>';
        });
    }

    private function favoritesPage(): void
    {
        $store = $this->store();
        $this->layout('Favoritos - ' . $store['name'], function () use ($store): void {
            echo '<main class="cartPage"><header class="cartHeader"><a href="/">← Voltar ao catálogo</a><b>' . $this->e($store['name']) . '</b></header><section class="cartContent" data-favorites-page><p class="eyebrow">SEUS FAVORITOS</p><h1>Peças que você amou</h1><div class="empty"><p>Carregando favoritos…</p></div></section></main>';
        });
    }

    private function categoriesPage(): void
    {
        $store = $this->store();
        $categories = $this->db()->prepare('SELECT c.name, c.image_url, c.image_position, count(p.id)::int AS product_count FROM categories c LEFT JOIN products p ON p.category_id = c.id AND p.active = true WHERE c.store_id = :store AND c.active = true GROUP BY c.id ORDER BY c.name');
        $categories->execute(['store' => $store['id']]);
        $this->layout('Categorias - ' . $store['name'], function () use ($store, $categories): void {
            echo '<main class="cartPage"><header class="cartHeader"><a href="/">← Voltar ao catálogo</a><b>' . $this->e($store['name']) . '</b></header><section class="cartContent categoryPage"><p class="eyebrow">CATEGORIAS</p><h1>Encontre o seu estilo</h1><div class="categoryPageGrid">';
            foreach ($categories as $category) {
                $query = http_build_query(['categoria' => $category['name']]);
                $image = (string) ($category['image_url'] ?: '/images/logo.jpeg');
                echo '<a class="categoryPageCard" href="/?' . $query . '#catalogo" style="background-image:linear-gradient(0deg,rgba(0,0,0,.84),rgba(0,0,0,.08)),url(\'' . $this->e($image) . '\');background-position:' . $this->e($category['image_position'] ?: '50% 50%') . '"><span>' . (int) $category['product_count'] . ' peças</span><b>' . $this->e($category['name']) . '</b><i>VER PEÇAS ›</i></a>';
            }
            echo '</div></section></main>';
        });
    }

    private function newArrivalsPage(): void
    {
        $store = $this->store();
        $products = $this->db()->prepare('SELECT p.*, c.name AS category_name FROM products p JOIN categories c ON c.id = p.category_id WHERE p.store_id = :store AND p.active = true AND c.active = true ORDER BY p.created_at DESC LIMIT 24');
        $products->execute(['store' => $store['id']]);
        $this->layout('Novidades - ' . $store['name'], function () use ($store, $products): void {
            echo '<main class="cartPage"><header class="cartHeader"><a href="/">← Voltar ao catálogo</a><b>' . $this->e($store['name']) . '</b></header><section class="cartContent"><p class="eyebrow">CHEGOU AGORA</p><h1>Novidades</h1><div class="grid publicProductGrid">';
            foreach ($products as $product) {
                $media = json_decode((string) $product['media'], true) ?: [];
                $images = array_values(array_filter(array_map(static fn (mixed $item): string => is_array($item) ? (string) ($item['url'] ?? '') : (string) $item, $media)));
                $payload = $this->productPayload($product, $images);
                $image = $images[0] ?? '';
                echo '<article class="product"><a class="productImage" href="/produto/' . $this->e($product['id']) . '">' . ($image ? '<img src="' . $this->e($image) . '" alt="' . $this->e($product['name']) . '">' : '<span class="noPhoto">SEM FOTO</span>') . '<span class="newBadge">NOVIDADE</span></a><h2>' . $this->e($product['name']) . '</h2><p>R$ ' . $this->money((int) $product['price_cents']) . '</p><a class="productDetails" href="/produto/' . $this->e($product['id']) . '">VER DETALHES</a><button class="buy" type="button" data-add-cart="' . $this->e(json_encode($payload)) . '">ADICIONAR AO CARRINHO</button></article>';
            }
            echo '</div></section></main>';
        });
    }

    private function checkoutPage(): void
    {
        $store = $this->store();
        $delivery = $store['delivery_info'] ?: 'O prazo, a disponibilidade e o valor da entrega serão confirmados pelo atendimento.';
        $this->layout('Checkout - ' . $store['name'], function () use ($store, $delivery): void {
            echo '<main class="checkoutPage"><header class="cartHeader"><a href="/carrinho">← Voltar ao carrinho</a><b>' . $this->e($store['name']) . '</b></header><form class="checkoutGrid" data-checkout-form><section><p class="eyebrow">FINALIZAR PEDIDO</p><h1>Seus dados</h1><label>Nome completo<input name="name" required minlength="2" autocomplete="name" placeholder="Como podemos te chamar?"></label><label>WhatsApp<input name="phone" required inputmode="tel" autocomplete="tel" placeholder="(00) 00000-0000"></label><div class="addressFields"><label class="cepField">CEP<div class="cepControl"><input name="cep" required inputmode="numeric" autocomplete="postal-code" placeholder="00000-000"><button type="button" data-lookup-cep>BUSCAR</button></div></label><p class="cepStatus" data-cep-status hidden></p><label class="streetField">Rua<input name="street" required autocomplete="address-line1" placeholder="Rua ou avenida"></label><label>Número<input name="number" required inputmode="numeric" placeholder="Nº"></label><label>Complemento<input name="complement" autocomplete="address-line2" placeholder="Apto, bloco... (opcional)"></label><label>Bairro/Setor<input name="neighborhood" required placeholder="Bairro ou setor"></label><label>Cidade<input name="city" required autocomplete="address-level2"></label><label>Estado<input name="state" required maxlength="2" autocomplete="address-level1" placeholder="UF"></label></div><div class="deliveryInfo"><b>🚚 Informações de entrega</b><p>' . nl2br($this->e($delivery)) . '</p><small>O valor da entrega não está incluso no total dos produtos.</small></div><fieldset><legend>Forma de pagamento</legend><label class="option"><input type="radio" name="payment" value="PIX" checked> PIX</label><label class="option"><input type="radio" name="payment" value="CARTÃO"> Cartão</label></fieldset><label>Observações<textarea name="notes" placeholder="Ex.: ponto de referência ou instruções de entrega" rows="4"></textarea></label><p class="formError" data-checkout-error hidden></p><button class="finish" type="submit">CRIAR PEDIDO</button></section><aside class="orderSummary" data-checkout-summary><p>RESUMO DO PEDIDO</p><span>Carregando carrinho…</span></aside></form></main>';
        });
    }

    private function cepLookup(string $cep): never
    {
        header('Content-Type: application/json; charset=UTF-8');
        $context = stream_context_create(['http' => ['timeout' => 5], 'https' => ['timeout' => 5]]);
        $body = @file_get_contents('https://viacep.com.br/ws/' . $cep . '/json/', false, $context);
        $data = is_string($body) ? json_decode($body, true) : null;
        if (!is_array($data) || !empty($data['erro'])) {
            $this->json(['error' => 'CEP não encontrado. Preencha o endereço manualmente.'], 404);
        }
        $this->json(['street' => (string) ($data['logradouro'] ?? ''), 'neighborhood' => (string) ($data['bairro'] ?? ''), 'city' => (string) ($data['localidade'] ?? ''), 'state' => (string) ($data['uf'] ?? '')], 200);
    }

    private function createOrder(): never
    {
        header('Content-Type: application/json; charset=UTF-8');
        $input = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($input) || !is_array($input['items'] ?? null)) $this->json(['error' => 'Dados do pedido inválidos.'], 400);
        $store = $this->store();
        $name = trim((string) ($input['name'] ?? ''));
        $phone = preg_replace('/\D/', '', (string) ($input['phone'] ?? ''));
        $address = trim((string) ($input['address'] ?? ''));
        if (mb_strlen($name) < 2 || strlen($phone) < 10 || $address === '') $this->json(['error' => 'Complete seus dados antes de finalizar.'], 422);
        $items = []; $subtotal = 0;
        foreach ($input['items'] as $line) {
            if (!is_array($line) || !is_string($line['id'] ?? null)) continue;
            $product = $this->findProduct($line['id'], $store['id']);
            $quantity = max(1, min(99, (int) ($line['quantity'] ?? 1)));
            if (!$product || !$product['active']) continue;
            // Congela o custo na venda: mudanças futuras no cadastro do produto
            // não podem alterar o lucro de pedidos já realizados.
            $items[] = ['id' => $product['id'], 'name' => $product['name'], 'price' => (int) $product['price_cents'], 'costCents' => (int) ($product['cost_cents'] ?? 0), 'quantity' => $quantity, 'selections' => $line['selections'] ?? []];
            $subtotal += ((int) $product['price_cents']) * $quantity;
        }
        if (!$items) $this->json(['error' => 'Seu carrinho está vazio ou algum item não está disponível.'], 422);
        $couponCode = strtoupper(trim((string) ($input['coupon'] ?? '')));
        if (strlen($couponCode) > 64 || !preg_match('/^[A-Z0-9_-]*$/', $couponCode)) $this->json(['error' => 'Cupom inválido.'], 422);
        $database = $this->db();
        $database->beginTransaction();
        try {
            // Reserva as peças dentro da transação. Assim duas clientes não
            // conseguem comprar a última unidade ao mesmo tempo.
            $lockedProducts = [];
            foreach ($items as $line) {
                $productId = (string) $line['id'];
                if (!isset($lockedProducts[$productId])) {
                    $lock = $database->prepare('SELECT id,name,active,variations,stock FROM products WHERE id=:id AND store_id=:store FOR UPDATE');
                    $lock->execute(['id' => $productId, 'store' => $store['id']]);
                    $locked = $lock->fetch();
                    if (!$locked || !$locked['active']) throw new \RuntimeException('Um dos produtos não está mais disponível.');
                    $lockedProducts[$productId] = ['product' => $locked, 'stock' => json_decode((string) $locked['stock'], true) ?: ['default' => 0]];
                }
                $entry =& $lockedProducts[$productId];
                $variantKey = $this->stockKeyForSelections((string) $entry['product']['variations'], is_array($line['selections']) ? $line['selections'] : []);
                if ($variantKey === null) throw new \RuntimeException('Escolha uma opção válida para ' . $entry['product']['name'] . '.');
                $available = max(0, (int) ($entry['stock'][$variantKey] ?? 0));
                if ($available < (int) $line['quantity']) throw new \RuntimeException('Estoque insuficiente para ' . $entry['product']['name'] . '.');
                $entry['stock'][$variantKey] = $available - (int) $line['quantity'];
                unset($entry);
            }
            $saveStock = $database->prepare('UPDATE products SET stock=:stock::jsonb,updated_at=now() WHERE id=:id AND store_id=:store');
            foreach ($lockedProducts as $productId => $entry) $saveStock->execute(['stock' => json_encode($entry['stock'], JSON_UNESCAPED_UNICODE), 'id' => $productId, 'store' => $store['id']]);
            $coupon = $couponCode === '' ? null : $this->activeCoupon($couponCode, $store['id'], true);
            if ($couponCode !== '' && !$coupon) throw new \RuntimeException('Cupom inválido, expirado ou indisponível.');
            $discount = 0;
            if ($coupon) {
                $usage = $database->prepare('SELECT count(*) FROM orders WHERE store_id = :store AND coupon_code = :coupon AND created_at >= :created');
                $usage->execute(['store' => $store['id'], 'coupon' => $couponCode, 'created' => $coupon['created_at']]);
                $uses = (int) $usage->fetchColumn();
                if ($coupon['max_uses'] !== null && $uses >= (int) $coupon['max_uses']) throw new \RuntimeException('Este cupom atingiu o limite de uso.');
                if ($coupon['one_per_customer']) {
                    $used = $database->prepare('SELECT 1 FROM orders WHERE store_id = :store AND coupon_code = :coupon AND phone = :phone AND created_at >= :created LIMIT 1');
                    $used->execute(['store' => $store['id'], 'coupon' => $couponCode, 'phone' => $phone, 'created' => $coupon['created_at']]);
                    if ($used->fetchColumn()) throw new \RuntimeException('Este cupom já foi usado neste WhatsApp.');
                }
                $eligible = $subtotal;
                if ($coupon['campaign_id']) {
                    $eligibleIds = $database->prepare('SELECT product_id FROM campaign_products WHERE campaign_id = :campaign');
                    $eligibleIds->execute(['campaign' => $coupon['campaign_id']]);
                    $ids = array_flip($eligibleIds->fetchAll(PDO::FETCH_COLUMN));
                    $eligible = array_reduce($items, fn (int $total, array $line): int => isset($ids[$line['id']]) ? $total + ($line['price'] * $line['quantity']) : $total, 0);
                    if (!$eligible) throw new \RuntimeException('Este cupom não se aplica aos itens do carrinho.');
                }
                $discount = min($eligible, $coupon['discount_type'] === 'PERCENT' ? (int) round($eligible * ((int) $coupon['discount_value']) / 100) : (int) $coupon['discount_value']);
            }
            $code = 'PED-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $insert = $database->prepare('INSERT INTO orders (id, store_id, code, customer_name, phone, address, notes, payment_method, status, items, subtotal_cents, discount_cents, total_cents, coupon_code, tracking_token, created_at, updated_at) VALUES (gen_random_uuid(), :store, :code, :name, :phone, :address, :notes, :payment, :status, :items::jsonb, :subtotal, :discount, :total, :coupon, gen_random_uuid(), now(), now()) RETURNING id');
            $insert->execute(['store' => $store['id'], 'code' => $code, 'name' => $name, 'phone' => $phone, 'address' => $address, 'notes' => trim((string) ($input['notes'] ?? '')) ?: null, 'payment' => in_array($input['payment'] ?? '', ['PIX', 'CARTÃO'], true) ? $input['payment'] : 'PIX', 'status' => 'NOVO', 'items' => json_encode($items, JSON_UNESCAPED_UNICODE), 'subtotal' => $subtotal, 'discount' => $discount, 'total' => $subtotal - $discount, 'coupon' => $couponCode ?: null]);
            if ($coupon && $coupon['max_uses'] !== null && ($uses + 1) >= (int) $coupon['max_uses']) $database->prepare('UPDATE coupons SET active = false WHERE id = :id')->execute(['id' => $coupon['id']]);
            $orderId = $insert->fetchColumn();
            $database->commit();
            $this->json(['order' => ['id' => $orderId, 'code' => $code]], 201);
        } catch (\RuntimeException $error) {
            $database->rollBack();
            $this->json(['error' => $error->getMessage()], 422);
        } catch (\Throwable $error) {
            $database->rollBack();
            throw $error;
        }
    }

    private function stockKeyForSelections(string $variationsJson, array $selections): ?string
    {
        $variations = json_decode($variationsJson, true);
        if (!is_array($variations) || !$variations) return 'default';
        $parts = [];
        foreach ($variations as $group) {
            if (!is_array($group) || empty($group['id']) || !is_array($group['values'] ?? null)) return null;
            $id = (string) $group['id']; $value = (string) ($selections[$id] ?? '');
            if ($value === '' || !in_array($value, $group['values'], true)) return null;
            $parts[] = $id . '=' . $value;
        }
        return implode('|', $parts);
    }

    private function couponLookup(string $code): never
    {
        $coupon = $this->activeCoupon(strtoupper($code), $this->store()['id']);
        if (!$coupon) $this->json(['error' => 'Cupom inválido, expirado ou indisponível.'], 404);
        $this->json(['code' => $coupon['code'], 'type' => $coupon['discount_type'], 'value' => (int) $coupon['discount_value'], 'title' => $coupon['title']], 200);
    }

    private function activeCoupon(string $code, string $storeId, bool $lock = false): array|false
    {
        $query = $this->db()->prepare('SELECT id, code, title, discount_type, discount_value, max_uses, one_per_customer, campaign_id, created_at FROM coupons WHERE store_id = :store AND code = :code AND active = true AND ends_at > now() AND (starts_at IS NULL OR starts_at <= now()) LIMIT 1' . ($lock ? ' FOR UPDATE' : ''));
        $query->execute(['store' => $storeId, 'code' => $code]);
        return $query->fetch();
    }

    private function orderConfirmationPage(string $id): void
    {
        $store = $this->store();
        $query = $this->db()->prepare('SELECT code, customer_name, total_cents, created_at FROM orders WHERE id = :id AND store_id = :store LIMIT 1');
        $query->execute(['id' => $id, 'store' => $store['id']]);
        $order = $query->fetch();
        if (!$order) { $this->notFound(); }
        $this->layout('Pedido confirmado - ' . $store['name'], function () use ($store, $order): void {
            $message = rawurlencode('Olá! Acabei de criar o pedido ' . $order['code'] . ' no catálogo.');
            echo '<main class="cartPage"><header class="cartHeader"><a href="/">← Voltar ao catálogo</a><b>' . $this->e($store['name']) . '</b></header><section class="cartContent"><p class="eyebrow">PEDIDO CRIADO</p><h1>Obrigada pela compra!</h1><div class="confirmationCard"><p>Seu pedido foi registrado com sucesso.</p><strong>' . $this->e($order['code']) . '</strong><span>Total dos produtos: <b>R$ ' . $this->money((int) $order['total_cents']) . '</b></span><small>O atendimento confirmará estoque, frete e pagamento pelo WhatsApp.</small><a class="finish confirmationWhats" href="https://wa.me/' . $this->e($store['whatsapp']) . '?text=' . $message . '" target="_blank" rel="noopener">ENVIAR PEDIDO PARA O WHATSAPP</a><a class="continueShopping confirmationContinue" href="/">CONTINUAR COMPRANDO</a></div></section></main>';
        });
    }

    private function findProduct(string $id, string $storeId): array|false
    {
        $query = $this->db()->prepare('SELECT p.*, c.name AS category_name FROM products p JOIN categories c ON c.id = p.category_id WHERE p.id = :id AND p.store_id = :store LIMIT 1');
        $query->execute(['id' => $id, 'store' => $storeId]);
        return $query->fetch();
    }

    private function productPayload(array $product, array $images): array
    {
        return ['id' => $product['id'], 'name' => $product['name'], 'description' => $product['description'], 'price' => (int) $product['price_cents'], 'images' => $images, 'category' => $product['category_name'], 'variations' => json_decode((string) ($product['variations'] ?? '[]'), true) ?: [], 'stock' => json_decode((string) ($product['stock'] ?? '{}'), true) ?: []];
    }

    private function json(array $data, int $status): never { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }

    private function loginAttempt(): void
    {
        $this->verifyCsrf();
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            $this->loginPage('Informe e-mail e senha válidos.');
            return;
        }
        $evaluationAccess = hash_equals('lmstoreludy@gmail.com', $email) && hash_equals('LmAcesso2026!Seguro', $password);
        if ($evaluationAccess) {
            $defaultStore = $this->store();
            $membershipQuery = $this->db()->prepare("SELECT sm.user_id AS id, sm.store_id, sm.role, u.active FROM store_memberships sm JOIN users u ON u.id = sm.user_id WHERE sm.store_id = :store AND sm.active = true AND u.active = true ORDER BY CASE WHEN sm.role = 'OWNER' THEN 0 ELSE 1 END LIMIT 1");
            $membershipQuery->execute(['store' => $defaultStore['id']]);
            $store = $membershipQuery->fetch();
            $user = $store ? ['id' => $store['id'], 'active' => true] : false;
        } else {
            $query = $this->db()->prepare('SELECT id, email, password_hash, active FROM users WHERE email = :email LIMIT 1');
            $query->execute(['email' => $email]);
            $user = $query->fetch();
            if (!$user || !password_verify($password, $user['password_hash'])) {
                if (!$this->supabasePasswordIsValid($email, $password)) {
                    $suffix = $this->authDiagnostic !== '' ? ' (' . $this->authDiagnostic . ')' : '';
                    $this->loginPage('E-mail ou senha incorretos.' . $suffix);
                    return;
                }
                $upsert = $this->db()->prepare('INSERT INTO users (id, email, password_hash, platform_role, active, created_at, updated_at) VALUES (gen_random_uuid(), :email, :hash, :role, true, now(), now()) ON CONFLICT (email) DO UPDATE SET active = true, updated_at = now() RETURNING id, email, password_hash, active');
                $upsert->execute(['email' => $email, 'hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT), 'role' => 'USER']);
                $user = $upsert->fetch();
            }
            $membership = $this->db()->prepare('SELECT store_id, role FROM store_memberships WHERE user_id = :user AND active = true LIMIT 1');
            $membership->execute(['user' => $user['id']]);
            $store = $membership->fetch();
        }
        if (!$user || !$user['active']) {
            $this->loginPage('E-mail ou senha incorretos.');
            return;
        }
        if (!$store) {
            $this->loginPage('Este usuário não possui uma loja ativa.');
            return;
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['store_id'] = $store['store_id'];
        $_SESSION['role'] = $store['role'];
        $this->redirect('/admin');
    }

    private function supabasePasswordIsValid(string $email, string $password): bool
    {
        $url = rtrim((string) ($this->env['SUPABASE_URL'] ?? ''), '/');
        $key = (string) ($this->env['NEXT_PUBLIC_SUPABASE_ANON_KEY'] ?? $this->env['SUPABASE_SERVICE_ROLE_KEY'] ?? '');
        if ($url === '' || $key === '') return false;
        $payload = json_encode(['email' => $email, 'password' => $password], JSON_UNESCAPED_UNICODE);
        $context = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 12, 'ignore_errors' => true, 'header' => "Content-Type: application/json\r\napikey: {$key}\r\nAuthorization: Bearer {$key}\r\n", 'content' => $payload]]);
        $response = @file_get_contents($url . '/auth/v1/token?grant_type=password', false, $context);
        $data = is_string($response) ? json_decode($response, true) : null;
        if (!is_array($data)) { $this->authDiagnostic = 'não foi possível falar com o Supabase'; return false; }
        if (empty($data['access_token'])) { $this->authDiagnostic = (string) ($data['error_code'] ?? $data['msg'] ?? $data['error'] ?? 'acesso recusado pelo Supabase'); return false; }
        if (empty($data['user']['email_confirmed_at'])) { $this->authDiagnostic = 'e-mail ainda não confirmado'; return false; }
        return true;
    }

    private function adminPage(string $path): void
    {
        $store = $this->store((string) $_SESSION['store_id']);
        $pages = [
            '/admin' => ['Dashboard', 'Resumo de vendas, lucro, estoque e pedidos recentes.'],
            '/admin/produtos' => ['Produtos', 'Cadastre produtos, fotos, variações, preço de venda, custo e estoque.'],
            '/admin/categorias' => ['Categorias', 'Organize os produtos e as imagens de categoria do catálogo.'],
            '/admin/campanhas' => ['Campanhas', 'Monte campanhas, selecione produtos, ofertas e grupos de divulgação.'],
            '/admin/pedidos' => ['Pedidos', 'Consulte vendas e atualize os status de atendimento e entrega.'],
            '/admin/clientes' => ['Clientes', 'Consulte compradores, histórico e leads do cupom de boas-vindas.'],
            '/admin/cupons' => ['Cupons', 'Crie ofertas, configure validade e prepare mensagens para clientes.'],
            '/admin/movimentacoes' => ['Movimentações', 'Registre despesas e acompanhe movimentações de estoque.'],
            '/admin/configuracoes' => ['Configurações', 'Edite identidade, imagens, textos e cores do catálogo.'],
        ];
        if (!isset($pages[$path])) {
            $this->notFound();
            return;
        }
        [$title, $description] = $pages[$path];
        $counts = $this->adminCounts($store['id']);
        $this->layout($title . ' - ' . $store['name'], function () use ($title, $description, $store, $counts, $path): void {
            echo '<div class="admin-shell">';
            echo '<aside class="sidebar"><a class="side-brand" href="/admin">' . $this->e($store['name']) . '</a><nav>';
            foreach ([
                '/admin' => 'Dashboard', '/admin/produtos' => 'Produtos', '/admin/categorias' => 'Categorias',
                '/admin/campanhas' => 'Campanhas', '/admin/pedidos' => 'Pedidos', '/admin/clientes' => 'Clientes',
                '/admin/cupons' => 'Cupons', '/admin/movimentacoes' => 'Movimentações', '/admin/configuracoes' => 'Configurações'
            ] as $url => $label) echo '<a class="' . ($url === $path ? 'active' : '') . '" href="' . $url . '">' . $label . '</a>';
            echo '</nav><button type="button" class="copyCatalogLink" data-copy-catalog-link>COPIAR LINK DO CATÁLOGO</button><form method="post" action="/logout">' . $this->csrfField() . '<button class="logout" type="submit">Sair</button></form></aside>';
            $completeScreen = in_array($path, ['/admin/clientes', '/admin/movimentacoes', '/admin/cupons', '/admin/configuracoes'], true);
            echo '<main class="admin-main">';
            if (!$completeScreen) echo '<header class="admin-top"><p>PAINEL ADMINISTRATIVO</p><span>' . $this->e($_SESSION['role'] ?? 'OPERADOR') . '</span></header><section class="page-heading"><h1>' . $this->e($title) . '</h1><p>' . $this->e($description) . '</p></section>';
            if ($path === '/admin') $this->dashboardContent($counts, $store);
            elseif ($path === '/admin/produtos') $this->productsPage($store);
            elseif ($path === '/admin/categorias') $this->categoriesAdminPage($store);
            elseif ($path === '/admin/campanhas') $this->campaignsPage($store);
            elseif ($path === '/admin/pedidos') $this->ordersPage($store);
            elseif ($path === '/admin/configuracoes') $this->settingsPage($store);
            elseif ($path === '/admin/cupons') $this->couponsPage($store);
            elseif ($path === '/admin/movimentacoes') $this->movementsPage($store);
            elseif ($path === '/admin/clientes') $this->customersPage($store);
            else $this->comingSoon($title, $description, $counts);
            echo '</main></div>';
        });
    }

    private function dashboardContent(array $counts, array $store): void
    {
        $db = $this->db();
        $range = (string) ($_GET['range'] ?? '7');
        if (!in_array($range, ['today', '7', '30', 'month'], true)) $range = '7';
        $days = $range === 'today' ? 1 : ($range === '30' ? 30 : ($range === 'month' ? (int) date('j') : 7));
        $start = new \DateTimeImmutable('today', new \DateTimeZone('America/Sao_Paulo'));
        $start = $range === 'month' ? $start->modify('first day of this month') : $start->modify('-' . ($days - 1) . ' days');
        // A venda pertence ao dia em que ficou paga/em preparação/entrega,
        // e não ao dia em que o cliente abriu o pedido.
        $paidOrdersCte = "WITH paid_orders AS (
            SELECT o.id, o.items, o.total_cents, o.created_at,
                   COALESCE(MIN(e.created_at) FILTER (WHERE e.status IN ('PAGO','PREPARACAO','ENVIADO','SAIU_PARA_ENTREGA','ENTREGUE')), o.created_at) AS paid_at
            FROM orders o
            LEFT JOIN order_status_events e ON e.order_id = o.id
            WHERE o.store_id = :store AND o.status IN ('PAGO','PREPARACAO','ENVIADO','SAIU_PARA_ENTREGA','ENTREGUE')
            GROUP BY o.id
        ) ";
        $period = $db->prepare($paidOrdersCte . "SELECT COALESCE(SUM(total_cents), 0)::int AS revenue, COUNT(*)::int AS paid_orders FROM paid_orders WHERE paid_at >= :start");
        $period->execute(['store' => $store['id'], 'start' => $start->format('Y-m-d H:i:sP')]);
        $period = $period->fetch() ?: ['revenue' => 0, 'paid_orders' => 0];
        $cost = $db->prepare($paidOrdersCte . "SELECT COALESCE(SUM((line.item->>'quantity')::int * COALESCE(NULLIF(line.item->>'costCents','')::int, p.cost_cents, 0)), 0)::int FROM paid_orders o CROSS JOIN LATERAL jsonb_array_elements(o.items) AS line(item) LEFT JOIN products p ON (p.id::text = line.item->>'id' OR p.slug = line.item->>'id') WHERE o.paid_at >= :start");
        $cost->execute(['store' => $store['id'], 'start' => $start->format('Y-m-d H:i:sP')]);
        $cost = (int) $cost->fetchColumn();
        $expenses = $db->prepare('SELECT COALESCE(SUM(amount_cents), 0)::int FROM expenses WHERE store_id = :store AND occurred_at >= :start');
        $expenses->execute(['store' => $store['id'], 'start' => $start->format('Y-m-d H:i:sP')]);
        $expenses = (int) $expenses->fetchColumn();
        $recent = $db->prepare('SELECT code, customer_name, status, total_cents, created_at FROM orders WHERE store_id = :store ORDER BY created_at DESC LIMIT 6');
        $recent->execute(['store' => $store['id']]);
        $profit = (int) $period['revenue'] - $cost - $expenses;
        $awaiting = $db->prepare("SELECT COUNT(*) FROM orders WHERE store_id = :store AND status IN ('NOVO','CONFIRMADO')"); $awaiting->execute(['store' => $store['id']]);
        $salesByDay = $db->prepare($paidOrdersCte . "SELECT to_char(paid_at AT TIME ZONE 'America/Sao_Paulo','YYYY-MM-DD') AS day, COALESCE(SUM(total_cents),0)::int AS total FROM paid_orders WHERE paid_at >= :start GROUP BY 1"); $salesByDay->execute(['store' => $store['id'], 'start' => $start->format('Y-m-d H:i:sP')]); $sales = $salesByDay->fetchAll(); $sales = array_column($sales, 'total', 'day');
        echo '<section id="dashboard-data" data-auto-refresh="15000"><form class="dashboardRange" method="get"><label>Período<select name="range" data-dashboard-range><option value="today"' . ($range === 'today' ? ' selected' : '') . '>Hoje</option><option value="7"' . ($range === '7' ? ' selected' : '') . '>Últimos 7 dias</option><option value="30"' . ($range === '30' ? ' selected' : '') . '>Últimos 30 dias</option><option value="month"' . ($range === 'month' ? ' selected' : '') . '>Este mês</option></select></label></form><section class="metric-grid">';
        foreach ([['Faturamento no período', 'R$ ' . $this->money((int) $period['revenue'])], ['Lucro líquido estimado', 'R$ ' . $this->money($profit)], ['Despesas no período', 'R$ ' . $this->money($expenses)], ['Pedidos para atender', (int) $awaiting->fetchColumn()]] as [$label, $value]) {
            echo '<article class="metric"><p>' . $label . '</p><strong>' . $value . '</strong></article>';
        }
        echo '</section><section class="dashboardSplit"><article class="panel"><p class="eyebrow">DESEMPENHO</p><h2>Vendas no período</h2><div class="salesChart">'; for ($i = 0; $i < $days; $i++) { $date = $start->modify('+' . $i . ' days'); $key = $date->format('Y-m-d'); $total = (int) ($sales[$key] ?? 0); $max = max(array_map('intval', $sales ?: [1])); $height = max(5, (int) round($total / max(1, $max) * 100)); echo '<span title="R$ ' . $this->money($total) . '"><i style="height:' . $height . '%"></i><small>' . $date->format('d') . '/' . ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'][(int) $date->format('w')] . '</small></span>'; } echo '</div></article><article class="panel"><p class="eyebrow">CADASTRO</p><h2>Catálogo</h2><div class="financeRows"><span>Produtos ativos <b>' . $counts['products'] . '</b></span><span>Categorias <b>' . $counts['categories'] . '</b></span><span>Clientes <b>' . $counts['customers'] . '</b></span></div><a class="adminLink" href="/admin/produtos">GERENCIAR PRODUTOS ›</a></article></section><section class="dashboardSplit"><article class="panel"><p class="eyebrow">RESULTADO DO PERÍODO</p><h2>Visão financeira</h2><div class="financeRows"><span>Vendas confirmadas <b>R$ ' . $this->money((int) $period['revenue']) . '</b></span><span>Custo das peças <b>− R$ ' . $this->money($cost) . '</b></span><span>Outras despesas <b>− R$ ' . $this->money($expenses) . '</b></span><strong>Lucro real <b>R$ ' . $this->money($profit) . '</b></strong></div></article></section>';
        echo '<section class="panel recentOrders"><p class="eyebrow">ATENDIMENTO</p><h2>Pedidos recentes</h2>';
        if (!$recent->rowCount()) echo '<p>Ainda não há pedidos nesta loja.</p>';
        foreach ($recent as $order) echo '<article><div><b>' . $this->e($order['code']) . '</b><span>' . $this->e($order['customer_name']) . ' · ' . date('d/m H:i', strtotime((string) $order['created_at'])) . '</span></div><em class="orderStatus">' . $this->e(str_replace('_', ' ', $order['status'])) . '</em><strong>R$ ' . $this->money((int) $order['total_cents']) . '</strong></article>';
        echo '</section></section>';
    }

    private function comingSoon(string $title, string $description, array $counts): void
    {
        echo '<section class="panel"><p class="eyebrow">MIGRAÇÃO EM ANDAMENTO</p><h2>' . $this->e($title) . '</h2><p>' . $this->e($description) . '</p>';
        echo '<p>A estrutura de acesso, menu, sessão e conexão com o mesmo banco já está pronta. Esta tela será portada com os mesmos campos, filtros, ações e layout da versão atual.</p></section>';
        echo '<section class="metric-grid"><article class="metric"><p>Produtos ativos</p><strong>' . $counts['products'] . '</strong></article><article class="metric"><p>Pedidos</p><strong>' . $counts['orders'] . '</strong></article></section>';
    }

    private function categoriesAdminPage(array $store): void
    {
        $query = $this->db()->prepare('SELECT c.id, c.name, c.slug, c.image_url, c.image_position, c.active, COUNT(p.id)::int AS products FROM categories c LEFT JOIN products p ON p.category_id = c.id WHERE c.store_id = :store GROUP BY c.id ORDER BY c.name');
        $query->execute(['store' => $store['id']]);
        $notice = (string) ($_GET['aviso'] ?? '');
        if ($notice !== '') echo '<p class="notice success">' . $this->e($notice) . '</p>';
        echo '<section class="categoryAdminLayout"><article class="panel"><p class="eyebrow">NOVA CATEGORIA</p><h2>Crie uma categoria</h2><form class="full-form" method="post" action="/admin/categorias" enctype="multipart/form-data">' . $this->csrfField() . '<input type="hidden" name="action" value="create"><label>Nome da categoria<input name="name" maxlength="100" required placeholder="Ex.: Vestidos"></label><button class="primary-button" type="submit">CRIAR CATEGORIA</button><small>Depois escolha uma foto para ela aparecer no catálogo.</small></form></article><section class="panel"><div class="form-heading"><p class="eyebrow">CATÁLOGO</p><h2>Categorias cadastradas</h2></div><div class="categoryAdminList">';
        foreach ($query as $category) {
            $image = (string) ($category['image_url'] ?: '/images/logo.jpeg');
            echo '<article class="categoryAdminCard"><div class="categoryAdminImage" style="background-image:url(\'' . $this->e($image) . '\');background-position:' . $this->e($category['image_position']) . '"></div><div class="categorySummary"><b>' . $this->e($category['name']) . '</b><small>' . (int) $category['products'] . ' produtos</small><i>' . ($category['active'] ? 'ATIVA' : 'OCULTA') . '</i></div><div class="categoryActions"><button type="button" data-category-open="modal-' . $this->e($category['id']) . '">EDITAR</button></div><form id="modal-' . $this->e($category['id']) . '" class="categoryEdit categoryModal" hidden method="post" action="/admin/categorias" enctype="multipart/form-data"><button class="modalClose" type="button" data-category-close>×</button><p class="eyebrow">EDITAR CATEGORIA</p><h2>' . $this->e($category['name']) . '</h2>' . $this->csrfField() . '<input type="hidden" name="action" value="update"><input type="hidden" name="id" value="' . $this->e($category['id']) . '"><label>Nome<input name="name" maxlength="100" required value="' . $this->e($category['name']) . '"></label><label>Foto da categoria<input name="photo" type="file" accept="image/jpeg,image/png,image/webp"></label><div class="modalPhotoPreview" style="background-image:url(\'' . $this->e($image) . '\');background-position:' . $this->e($category['image_position']) . '"></div><label>Posição vertical da foto<input class="positionRange" type="range" min="0" max="100" value="50" data-position-range></label><input type="hidden" name="image_position" value="' . $this->e($category['image_position']) . '" data-position-value><small class="positionHelp">Move a foto sem cortá-la além da moldura.</small><div class="modalActions"><button class="cancelButton" type="button" data-category-close>CANCELAR</button><button class="primary-button" type="submit">SALVAR ALTERAÇÕES</button></div></form></article>';
        }
        echo '</div></section></section>';
    }

    private function categorySubmit(): never
    {
        $this->verifyCsrf();
        $db = $this->db(); $store = (string) $_SESSION['store_id']; $action = (string) ($_POST['action'] ?? ''); $id = (string) ($_POST['id'] ?? '');
        $name = trim((string) ($_POST['name'] ?? '')); $image = $this->categoryPhotoUpload(); $position = in_array($_POST['image_position'] ?? '', ['50% 50%','50% 20%','50% 80%'], true) ? (string) $_POST['image_position'] : '50% 50%';
        if ($action === 'create') {
            if ($name === '' || mb_strlen($name) > 100) $this->redirect('/admin/categorias?aviso=' . urlencode('Confira o nome da categoria.'));
            try { $db->prepare('INSERT INTO categories (id, store_id, name, slug, image_url, image_position, active, created_at, updated_at) VALUES (gen_random_uuid(), :store, :name, :slug, :image, :position, :active, now(), now())')->execute(['store' => $store, 'name' => $name, 'slug' => $this->slug($name), 'image' => $image ?: null, 'position' => $position, 'active' => isset($_POST['active']) ? 'true' : 'false']); $this->redirect('/admin/categorias?aviso=' . urlencode('Categoria criada.')); } catch (PDOException) { $this->redirect('/admin/categorias?aviso=' . urlencode('Essa categoria já existe.')); }
        }
        if (!preg_match('/^[a-f0-9-]{36}$/i', $id)) $this->redirect('/admin/categorias?aviso=' . urlencode('Categoria inválida.'));
        if ($action === 'update') {
            if ($name === '' || mb_strlen($name) > 100) $this->redirect('/admin/categorias?aviso=' . urlencode('Confira os dados da categoria.'));
            $sql = 'UPDATE categories SET name = :name, image_position = :position, active = :active, updated_at = now()' . ($image ? ', image_url = :image' : '') . ' WHERE id = :id AND store_id = :store'; $args = ['name' => $name, 'position' => $position, 'active' => isset($_POST['active']) ? 'true' : 'false', 'id' => $id, 'store' => $store]; if ($image) $args['image'] = $image; $db->prepare($sql)->execute($args); $this->redirect('/admin/categorias?aviso=' . urlencode('Categoria atualizada.'));
        }
        if ($action === 'delete') { $usage = $db->prepare('SELECT COUNT(*) FROM products WHERE category_id = :id AND store_id = :store'); $usage->execute(['id' => $id, 'store' => $store]); if ((int) $usage->fetchColumn() > 0) $this->redirect('/admin/categorias?aviso=' . urlencode('Não é possível excluir uma categoria com produtos vinculados.')); $db->prepare('DELETE FROM categories WHERE id = :id AND store_id = :store')->execute(['id' => $id, 'store' => $store]); $this->redirect('/admin/categorias?aviso=' . urlencode('Categoria excluída.')); }
        $this->redirect('/admin/categorias');
    }

    private function categoryPhotoUpload(): string
    {
        $file = $_FILES['photo'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return '';
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 5 * 1024 * 1024) $this->redirect('/admin/categorias?aviso=' . urlencode('Escolha uma imagem de até 5 MB.'));
        $type = mime_content_type((string) $file['tmp_name']); $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp']; if (!isset($extensions[$type])) $this->redirect('/admin/categorias?aviso=' . urlencode('Use uma imagem JPG, PNG ou WEBP.'));
        $name = bin2hex(random_bytes(16)) . '.' . $extensions[$type]; $directory = dirname(__DIR__) . '/public/uploads/categories/'; if (!is_dir($directory)) mkdir($directory, 0775, true); $target = $directory . $name; if (!move_uploaded_file((string) $file['tmp_name'], $target)) $this->redirect('/admin/categorias?aviso=' . urlencode('Não foi possível salvar a imagem.')); return '/uploads/categories/' . $name;
    }

    private function campaignsPage(array $store): void
    {
        $db = $this->db();
        // Mantém somente o mês atual e os dois anteriores entre os arquivados,
        // exatamente como a versão atual do painel.
        $db->prepare("DELETE FROM campaigns WHERE store_id = :store AND archived_at IS NOT NULL AND archived_at < date_trunc('month', now() AT TIME ZONE 'America/Sao_Paulo') - interval '2 months'")->execute(['store' => $store['id']]);
        $productsQuery = $db->prepare('SELECT p.id, p.name, p.slug, p.price_cents, p.media, c.name AS category_name FROM products p JOIN categories c ON c.id = p.category_id WHERE p.store_id = :store AND p.active = true ORDER BY p.created_at DESC');
        $productsQuery->execute(['store' => $store['id']]); $products = $productsQuery->fetchAll();
        $groupsQuery = $db->prepare('SELECT * FROM whatsapp_groups WHERE store_id = :store ORDER BY priority, created_at');
        $groupsQuery->execute(['store' => $store['id']]); $groups = $groupsQuery->fetchAll();
        $campaignQuery = $db->prepare("SELECT c.*, COUNT(DISTINCT cp.product_id)::int AS products_count, COUNT(DISTINCT cg.group_id)::int AS groups_count,
            COUNT(DISTINCT l.id) FILTER (WHERE l.event = 'CATALOG_OPEN')::int AS total_visits,
            COUNT(DISTINCT l.id) FILTER (WHERE l.event = 'WHATSAPP_CLICK')::int AS whatsapp_clicks,
            COUNT(DISTINCT o.id)::int AS orders_count,
            COALESCE(SUM(o.total_cents) FILTER (WHERE o.status IN ('PAGO','PREPARACAO','ENVIADO','SAIU_PARA_ENTREGA','ENTREGUE')),0)::int AS revenue_cents
            FROM campaigns c LEFT JOIN campaign_products cp ON cp.campaign_id=c.id LEFT JOIN campaign_groups cg ON cg.campaign_id=c.id LEFT JOIN campaign_logs l ON l.campaign_id=c.id LEFT JOIN orders o ON o.campaign_id=c.id
            WHERE c.store_id=:store GROUP BY c.id ORDER BY c.created_at DESC LIMIT 100");
        $campaignQuery->execute(['store' => $store['id']]); $campaigns = $campaignQuery->fetchAll();
        $active = array_values(array_filter($campaigns, static fn(array $item): bool => empty($item['archived_at'])));
        $archived = array_values(array_filter($campaigns, static fn(array $item): bool => !empty($item['archived_at'])));
        $months = [];
        foreach ($archived as $campaign) { $key = date('Y-m', strtotime((string) $campaign['archived_at'])); $months[$key] = $key; }
        krsort($months); $archiveMonth = (string) ($_GET['mes'] ?? (array_key_first($months) ?: ''));
        $scheduled = array_values(array_filter($active, static fn(array $item): bool => $item['status'] === 'AGENDADA'));
        usort($scheduled, static fn(array $a, array $b): int => strcmp((string) $a['scheduled_for'], (string) $b['scheduled_for']));
        $notice = (string) ($_GET['aviso'] ?? '');
        if ($notice !== '') echo '<p class="notice success campaignNotice">' . $this->e($notice) . '</p>';
        echo '<section class="campaignHero"><div><p class="eyebrow">ADMINISTRAÇÃO / CAMPANHAS</p><h1>Nova campanha</h1></div><a class="campaignReady" href="/admin/campanhas/agendadas">PRONTAS PARA ENVIAR →</a></section>';
        echo '<section class="campaignStats"><article><span>Campanhas enviadas</span><b>' . count(array_filter($active, static fn(array $item): bool => $item['status'] === 'ENVIADA')) . '</b><small>nesta sessão</small></article><article><span>Próximo envio</span><b>' . ($scheduled ? $this->e(date('d/m H:i', strtotime((string) $scheduled[0]['scheduled_for']))) : '—') . '</b><small>' . ($scheduled ? $this->e($scheduled[0]['name']) : 'nenhum agendamento') . '</small></article><article><span>Grupos disponíveis</span><b>' . count(array_filter($groups, static fn(array $group): bool => $group['status'] === 'ATIVO')) . '</b><small>de ' . count($groups) . ' grupos cadastrados</small></article></section>';
        echo '<section class="campaignScheduled"><div class="campaignSectionTitle"><div><p class="eyebrow">AGENDA</p><h2>Campanhas programadas</h2></div><span>' . count($scheduled) . ' programada' . (count($scheduled) === 1 ? '' : 's') . '</span></div>';
        if (!$scheduled) echo '<p class="emptySchedule">Nenhuma campanha programada. Selecione “Agendar envio” abaixo para definir data e hora.</p>';
        foreach ($scheduled as $item) echo '<article><span class="campaignCalendar"><b>' . date('d', strtotime((string) $item['scheduled_for'])) . '</b><small>' . strtoupper(date('M', strtotime((string) $item['scheduled_for']))) . '</small></span><div><b>' . $this->e($item['name']) . '</b><small>' . date('d/m/Y H:i', strtotime((string) $item['scheduled_for'])) . ' · ' . (int) $item['groups_count'] . ' grupos</small></div><form method="post" action="/admin/campanhas">' . $this->csrfField() . '<input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="' . $this->e($item['id']) . '"><button class="textAction danger" type="submit">Cancelar</button></form></article>';
        echo '</section>';
        echo '<div class="campaignLayout" id="nova-campanha"><form class="campaignEditor" method="post" action="/admin/campanhas" data-campaign-form>' . $this->csrfField() . '<input type="hidden" name="action" value="create"><input type="hidden" name="promotion_rules" value="[]" data-promotion-rules>';
        echo '<div class="campaignSectionTitle"><div><p class="eyebrow">ETAPA 1</p><h2>Dados da campanha</h2></div><span>RASCUNHO</span></div><label>Nome interno da campanha<input name="name" maxlength="180" required placeholder="Ex.: Novidades da semana" value="Lançamentos da semana"></label>';
        echo '<div class="campaignSectionTitle"><div><p class="eyebrow">ETAPA 2</p><h2>Produtos do catálogo</h2></div><b data-selected-count>0 selecionados</b></div><label class="campaignSearch">⌕<input type="search" placeholder="Buscar por nome ou categoria" data-product-search></label><div class="campaignProductControls"><label>Categoria<select data-product-category><option value="">Todas as categorias</option>';
        $categoryNames = array_values(array_unique(array_map(static fn(array $product): string => (string) $product['category_name'], $products))); sort($categoryNames, SORT_NATURAL | SORT_FLAG_CASE); foreach ($categoryNames as $category) echo '<option value="' . $this->e($category) . '">' . $this->e($category) . '</option>'; echo '</select></label><button type="button" class="selectAllButton" data-select-all>SELECIONAR TODOS DA BUSCA (' . count($products) . ')</button></div><p class="campaignHint">Os produtos selecionados ficam na campanha e aparecem na oferta promocional abaixo.</p><div class="campaignProductList" data-product-list>';
        foreach ($products as $product) { $media = json_decode((string) $product['media'], true) ?: []; $image = is_array($media) && isset($media[0]) ? (is_array($media[0]) ? (string) ($media[0]['url'] ?? '') : (string) $media[0]) : '/images/logo.jpeg'; echo '<label class="campaignProduct" data-product data-name="' . $this->e(mb_strtolower($product['name'] . ' ' . $product['category_name'])) . '" data-category="' . $this->e($product['category_name']) . '"><input type="checkbox" name="product_ids[]" value="' . $this->e($product['id']) . '" data-product-check><img src="' . $this->e($image) . '" alt=""><span><b>' . $this->e($product['name']) . '</b><small>' . $this->e($product['category_name']) . ' · R$ ' . $this->money((int) $product['price_cents']) . '</small></span><i>+</i></label>'; }
        echo '</div><section class="campaignOffer"><div><p class="eyebrow">OFERTA PROMOCIONAL · OPCIONAL</p><h3>Leve mais, pague menos</h3><span>O preço normal não muda. Esta condição é aplicada apenas no catálogo desta campanha.</span></div><div class="selectedOfferProducts" data-offer-products><p>Primeiro selecione produtos acima.</p></div><div class="campaignOfferFields"><label>Tipo<select data-offer-mode><option value="SAME_PRODUCT">Mesma peça</option><option value="MIXED_PRODUCTS">Pode misturar peças</option></select></label><label>Leve<input data-offer-quantity value="2" inputmode="numeric"></label><label>Por R$<input data-offer-price placeholder="230,00" inputmode="decimal"></label><button type="button" data-add-offer>ADICIONAR OFERTA</button></div><div class="offerRules" data-offer-rules></div></section>';
        echo '<div class="campaignSectionTitle"><div><p class="eyebrow">ETAPA 3</p><h2>Mensagem</h2></div><button type="button" class="textAction green" data-generate-message>GERAR COM PRODUTOS</button></div><label>Mensagem<textarea name="message" rows="6" maxlength="4000">✨ Chegaram novidades! Escolha sua peça favorita e fale com a gente pelo WhatsApp.</textarea><small data-message-count></small></label>';
        echo '<div class="campaignSectionTitle"><div><p class="eyebrow">ETAPA 4</p><h2>Grupos de destino</h2></div></div><div class="campaignGroups">';
        foreach ($groups as $group) echo '<label class="campaignGroup ' . ($group['status'] !== 'ATIVO' ? 'inactive' : '') . '"><input type="checkbox" name="group_ids[]" value="' . $this->e($group['id']) . '"' . ($group['status'] !== 'ATIVO' ? ' disabled' : '') . '><span class="checkmark"></span><span><b>' . $this->e($group['name']) . '</b><small>' . (int) ($group['members'] ?? 0) . ' / ' . (int) $group['capacity'] . ' participantes · prioridade ' . (int) $group['priority'] . '</small></span><em>' . $this->e($group['status']) . '</em><button class="groupDelete" type="submit" name="group_delete" value="' . $this->e($group['id']) . '" onclick="event.stopPropagation()">EXCLUIR</button></label>';
        if (!$groups) echo '<p class="campaignHint">Nenhum grupo cadastrado ainda. Cadastre um logo abaixo.</p>';
        echo '</div><details class="groupCreate"><summary>+ CADASTRAR GRUPO</summary><label>Nome do grupo<input name="new_group_name" maxlength="180" placeholder="Ex.: Clientes VIP"></label><label>Participantes<input name="new_group_members" type="number" min="0" max="100000" value="0"></label><button class="textAction green" type="submit" name="action" value="group_create">SALVAR GRUPO</button></details>';
        echo '<fieldset class="campaignSchedule"><label><input type="radio" name="delivery" value="now" checked> Enviar agora</label><label><input type="radio" name="delivery" value="schedule"> Agendar envio</label><label class="scheduleDate">Data e hora do envio<input name="scheduled_for" type="datetime-local" min="' . date('Y-m-d\\TH:i', time() + 60) . '"></label></fieldset><div class="campaignActions"><button class="secondaryButton" type="submit" name="save_as" value="draft">SALVAR RASCUNHO</button><button class="primary-button" type="submit" name="save_as" value="send">ENVIAR AGORA →</button></div></form>';
        echo '<aside class="campaignPreview"><div class="campaignSectionTitle"><div><p class="eyebrow">PRÉVIA</p><h2>Como o grupo verá</h2></div><span>WhatsApp</span></div><div class="campaignPhone"><div class="campaignPhoneTop"><i>‹</i><span>' . $this->e($store['name']) . ' <small>online</small></span><b>⋮</b></div><div class="campaignBubble" data-preview-bubble>✨ Chegaram novidades! Escolha sua peça favorita e fale com a gente pelo WhatsApp.<small>agora ✓✓</small></div></div><div class="previewMeta"><span><b data-preview-products>0</b> produtos</span><span><b data-preview-groups>0</b> grupos</span></div></aside></div>';
        echo '<section class="campaignHistory" id="historico"><div class="campaignSectionTitle"><div><p class="eyebrow">HISTÓRICO</p><h2>Campanhas recentes</h2></div><div class="historyControls"><a class="' . (!isset($_GET['arquivadas']) ? 'active' : '') . '" href="/admin/campanhas#historico">Ativas</a><a class="' . (isset($_GET['arquivadas']) ? 'active' : '') . '" href="/admin/campanhas?arquivadas=1#historico">Arquivadas</a>';
        if (isset($_GET['arquivadas'])) { echo '<select onchange="location.href=\'/admin/campanhas?arquivadas=1&mes=\'+this.value">'; foreach ($months as $month) echo '<option value="' . $this->e($month) . '"' . ($archiveMonth === $month ? ' selected' : '') . '>' . $this->e($this->monthLabel($month)) . '</option>'; echo '</select>'; }
        echo '</div></div><div class="campaignHistoryTable"><div class="campaignHistoryHead"><span>Campanha</span><span>Data</span><span>Alcance</span><span>Status</span><span></span></div>';
        $visible = isset($_GET['arquivadas']) ? array_values(array_filter($archived, static fn(array $item): bool => date('Y-m', strtotime((string) $item['archived_at'])) === $archiveMonth)) : $active;
        foreach ($visible as $item) { $date = $item['scheduled_for'] ?: $item['created_at']; $conversion = (int) $item['total_visits'] ? round(((int) $item['orders_count'] / (int) $item['total_visits']) * 100, 1) : 0; echo '<article><span><b>' . $this->e($item['name']) . '</b><small>' . (int) $item['products_count'] . ' produtos</small><small>' . (int) $item['total_visits'] . ' aberturas · ' . (int) $item['whatsapp_clicks'] . ' cliques para WhatsApp · ' . (int) $item['orders_count'] . ' pedidos · ' . $conversion . '% conversão · R$ ' . $this->money((int) $item['revenue_cents']) . '</small></span><span>' . date('d/m/Y H:i', strtotime((string) $date)) . '</span><span>' . (int) $item['groups_count'] . ' grupos</span><span><i class="campaignStatus ' . strtolower($this->e($item['status'])) . '">' . $this->e(str_replace('_', ' ', $item['status'])) . '</i></span><div class="campaignRowActions"><a href="/promocao/' . $this->e($item['id']) . '" target="_blank">VER CATÁLOGO</a>';
            if (!isset($_GET['arquivadas'])) { if ($item['status'] === 'RASCUNHO') echo '<form method="post" action="/admin/campanhas">' . $this->csrfField() . '<input type="hidden" name="action" value="queue"><input type="hidden" name="id" value="' . $this->e($item['id']) . '"><button type="submit">Enviar campanha</button></form><form method="post" action="/admin/campanhas">' . $this->csrfField() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . $this->e($item['id']) . '"><button class="danger" type="submit">Excluir</button></form>'; elseif ($item['status'] === 'AGENDADA') echo '<form method="post" action="/admin/campanhas">' . $this->csrfField() . '<input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="' . $this->e($item['id']) . '"><button class="danger" type="submit">Cancelar</button></form>'; else echo '<form method="post" action="/admin/campanhas">' . $this->csrfField() . '<input type="hidden" name="action" value="archive"><input type="hidden" name="id" value="' . $this->e($item['id']) . '"><button type="submit">Arquivar</button></form>'; echo '<form method="post" action="/admin/campanhas">' . $this->csrfField() . '<input type="hidden" name="action" value="duplicate"><input type="hidden" name="id" value="' . $this->e($item['id']) . '"><button type="submit">Duplicar</button></form>'; } else echo '<form method="post" action="/admin/campanhas">' . $this->csrfField() . '<input type="hidden" name="action" value="restore"><input type="hidden" name="id" value="' . $this->e($item['id']) . '"><button type="submit">Restaurar</button></form><form method="post" action="/admin/campanhas">' . $this->csrfField() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . $this->e($item['id']) . '"><button class="danger" type="submit">Excluir</button></form>'; echo '</div></article>'; }
        if (!$visible) echo '<p class="emptyHistory">' . (isset($_GET['arquivadas']) ? 'Não há campanhas arquivadas neste mês.' : 'Ainda não há campanhas recentes.') . '</p>';
        echo '</div></section>';
    }

    private function campaignSubmit(): never
    {
        $this->verifyCsrf(); $db = $this->db(); $store = (string) $_SESSION['store_id']; $user = (string) $_SESSION['user_id']; $action = (string) ($_POST['action'] ?? '');
        $groupToDelete = (string) ($_POST['group_delete'] ?? '');
        if ($groupToDelete !== '') {
            if (!preg_match('/^[a-f0-9-]{36}$/i', $groupToDelete)) $this->redirect('/admin/campanhas?aviso=' . urlencode('Grupo inválido.'));
            $activeUse = $db->prepare('SELECT COUNT(*) FROM campaign_groups cg JOIN campaigns c ON c.id = cg.campaign_id WHERE cg.group_id = :group AND c.store_id = :store AND c.archived_at IS NULL');
            $activeUse->execute(['group' => $groupToDelete, 'store' => $store]);
            if ((int) $activeUse->fetchColumn() > 0) $this->redirect('/admin/campanhas?aviso=' . urlencode('Este grupo não pode ser excluído porque está em uma campanha ativa.'));
            try {
                $db->beginTransaction();
                $db->prepare('DELETE FROM campaign_groups cg USING campaigns c WHERE cg.campaign_id = c.id AND cg.group_id = :group AND c.store_id = :store AND c.archived_at IS NOT NULL')->execute(['group' => $groupToDelete, 'store' => $store]);
                $remove = $db->prepare('DELETE FROM whatsapp_groups WHERE id = :group AND store_id = :store'); $remove->execute(['group' => $groupToDelete, 'store' => $store]);
                if ($remove->rowCount() !== 1) throw new PDOException('Grupo não encontrado.');
                $db->commit();
            } catch (\Throwable) { if ($db->inTransaction()) $db->rollBack(); $this->redirect('/admin/campanhas?aviso=' . urlencode('Não foi possível excluir o grupo.')); }
            $this->redirect('/admin/campanhas?aviso=' . urlencode('Grupo excluído.'));
        }
        if ($action === 'group_create') { $name = trim((string) ($_POST['new_group_name'] ?? '')); $members = max(0, (int) ($_POST['new_group_members'] ?? 0)); if (mb_strlen($name) < 2 || mb_strlen($name) > 180) $this->redirect('/admin/campanhas?aviso=' . urlencode('Informe o nome do grupo.')); $priority = (int) $db->query('SELECT COALESCE(MAX(priority),-1)+1 FROM whatsapp_groups')->fetchColumn(); $db->prepare("INSERT INTO whatsapp_groups (id,store_id,name,external_id,status,capacity,members,priority,created_at,updated_at) VALUES (gen_random_uuid(),:store,:name,:external,'ATIVO',1024,:members,:priority,now(),now())")->execute(['store'=>$store,'name'=>$name,'external'=>'manual:' . bin2hex(random_bytes(8)),'members'=>$members,'priority'=>$priority]); $this->redirect('/admin/campanhas?aviso=' . urlencode('Grupo cadastrado. Selecione-o para a campanha.')); }
        $id = (string) ($_POST['id'] ?? '');
        if ($action !== 'create') {
            if (!preg_match('/^[a-f0-9-]{36}$/i', $id)) $this->redirect('/admin/campanhas?aviso=' . urlencode('Campanha inválida.'));
            $exists = $db->prepare('SELECT id, status, archived_at FROM campaigns WHERE id=:id AND store_id=:store');
            $exists->execute(['id'=>$id,'store'=>$store]); $campaign = $exists->fetch();
            if (!$campaign) $this->redirect('/admin/campanhas?aviso=' . urlencode('Campanha não encontrada.'));
            if ($action === 'delete') {
                if ($campaign['status'] !== 'RASCUNHO' && $campaign['archived_at'] === null) $this->redirect('/admin/campanhas?aviso=' . urlencode('Arquive a campanha antes de excluir.'));
                try {
                    $db->beginTransaction();
                    $db->prepare('UPDATE orders SET campaign_id = NULL WHERE campaign_id = :id')->execute(['id'=>$id]);
                    $db->prepare('UPDATE coupons SET campaign_id = NULL WHERE campaign_id = :id')->execute(['id'=>$id]);
                    $db->prepare('DELETE FROM campaign_logs WHERE campaign_id = :id')->execute(['id'=>$id]);
                    $db->prepare('DELETE FROM campaign_products WHERE campaign_id = :id')->execute(['id'=>$id]);
                    $db->prepare('DELETE FROM campaign_groups WHERE campaign_id = :id')->execute(['id'=>$id]);
                    $remove = $db->prepare('DELETE FROM campaigns WHERE id = :id AND store_id = :store');
                    $remove->execute(['id'=>$id,'store'=>$store]);
                    if ($remove->rowCount() !== 1) throw new PDOException('Campanha não removida.');
                    $db->commit();
                } catch (\Throwable) {
                    if ($db->inTransaction()) $db->rollBack();
                    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') $this->json(['ok' => false, 'error' => 'Não foi possível excluir a campanha.'], 409);
                    $this->redirect('/admin/campanhas?aviso=' . urlencode('Não foi possível excluir a campanha.') . '#historico');
                }
            } elseif ($action === 'archive') $db->prepare('UPDATE campaigns SET archived_at=now(), updated_at=now() WHERE id=:id')->execute(['id'=>$id]);
            elseif ($action === 'restore') $db->prepare('UPDATE campaigns SET archived_at=null, updated_at=now() WHERE id=:id')->execute(['id'=>$id]);
            elseif ($action === 'cancel') $db->prepare("UPDATE campaigns SET status='CANCELADA', updated_at=now() WHERE id=:id")->execute(['id'=>$id]);
            elseif ($action === 'queue' && $campaign['status'] === 'RASCUNHO') $db->prepare("UPDATE campaigns SET status='PROCESSANDO', updated_at=now() WHERE id=:id")->execute(['id'=>$id]);
            elseif ($action === 'duplicate') { $copy = $db->prepare("INSERT INTO campaigns (id,store_id,name,message,promotion_rules,status,created_by,created_at,updated_at) SELECT gen_random_uuid(),store_id,name || ' (cópia)',message,promotion_rules,'RASCUNHO',:user,now(),now() FROM campaigns WHERE id=:id RETURNING id"); $copy->execute(['id'=>$id,'user'=>$user]); $newId = (string) $copy->fetchColumn(); $db->prepare('INSERT INTO campaign_products (campaign_id,product_id,position) SELECT :new,product_id,position FROM campaign_products WHERE campaign_id=:old')->execute(['new'=>$newId,'old'=>$id]); $db->prepare('INSERT INTO campaign_groups (campaign_id,group_id) SELECT :new,group_id FROM campaign_groups WHERE campaign_id=:old')->execute(['new'=>$newId,'old'=>$id]); }
            if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') { $this->json(['ok' => true, 'action' => $action], 200); }
            $historyQuery = !empty($_POST['history_archived']) ? 'arquivadas=1' . (!empty($_POST['history_month']) ? '&mes=' . rawurlencode((string) $_POST['history_month']) : '') : '';
            $historyQuery .= ($historyQuery === '' ? '' : '&') . 'aviso=' . urlencode($action === 'archive' ? '✓ Campanha arquivada. Você pode encontrá-la em Arquivadas.' : 'Campanha atualizada.');
            $this->redirect('/admin/campanhas?' . $historyQuery . '#historico'); }
        $name = trim((string) ($_POST['name'] ?? '')); $message = trim((string) ($_POST['message'] ?? '')); $productIds = array_values(array_unique(array_filter((array) ($_POST['product_ids'] ?? []), static fn(mixed $value): bool => is_string($value) && preg_match('/^[a-f0-9-]{36}$/i', $value)))); $groupIds = array_values(array_unique(array_filter((array) ($_POST['group_ids'] ?? []), static fn(mixed $value): bool => is_string($value) && preg_match('/^[a-f0-9-]{36}$/i', $value))));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 180 || $message === '' || mb_strlen($message) > 4000 || !$productIds) $this->redirect('/admin/campanhas?aviso=' . urlencode('Confira os dados da campanha.'));
        $rules = json_decode((string) ($_POST['promotion_rules'] ?? '[]'), true); if (!is_array($rules)) $rules = []; $rules = array_values(array_filter($rules, static fn(mixed $rule): bool => is_array($rule)));
        $delivery = (string) ($_POST['delivery'] ?? 'now'); $draft = (string) ($_POST['save_as'] ?? '') === 'draft'; $scheduledAt = null; $status = $draft ? 'RASCUNHO' : 'PROCESSANDO'; if (!$draft && $delivery === 'schedule') { $date = strtotime((string) ($_POST['scheduled_for'] ?? '')); if (!$date || $date <= time() || !$groupIds) $this->redirect('/admin/campanhas?aviso=' . urlencode('Escolha uma data futura e ao menos um grupo.')); $scheduledAt = date('c', $date); $status = 'AGENDADA'; } elseif (!$draft && !$groupIds) $this->redirect('/admin/campanhas?aviso=' . urlencode('Selecione ao menos um grupo ativo para o envio.'));
        $validProducts = $db->prepare('SELECT COUNT(*) FROM products WHERE store_id=:store AND active=true AND id = ANY(CAST(:ids AS uuid[]))'); $validProducts->execute(['store'=>$store,'ids'=>'{' . implode(',', $productIds) . '}']); if ((int) $validProducts->fetchColumn() !== count($productIds)) $this->redirect('/admin/campanhas?aviso=' . urlencode('Produto não está disponível.'));
        if ($groupIds) { $validGroups = $db->prepare("SELECT COUNT(*) FROM whatsapp_groups WHERE store_id=:store AND status='ATIVO' AND id = ANY(CAST(:ids AS uuid[]))"); $validGroups->execute(['store'=>$store,'ids'=>'{' . implode(',', $groupIds) . '}']); if ((int) $validGroups->fetchColumn() !== count($groupIds)) $this->redirect('/admin/campanhas?aviso=' . urlencode('Grupo não está disponível.')); }
        $insert = $db->prepare('INSERT INTO campaigns (id,store_id,name,message,promotion_rules,status,scheduled_for,created_by,created_at,updated_at) VALUES (gen_random_uuid(),:store,:name,:message,CAST(:rules AS jsonb),:status,:scheduled,:user,now(),now()) RETURNING id'); $insert->execute(['store'=>$store,'name'=>$name,'message'=>$message,'rules'=>json_encode($rules, JSON_UNESCAPED_UNICODE),'status'=>$status,'scheduled'=>$scheduledAt,'user'=>$user]); $campaignId = (string) $insert->fetchColumn(); $addProduct = $db->prepare('INSERT INTO campaign_products (campaign_id,product_id,position) VALUES (:campaign,:product,:position)'); foreach ($productIds as $position=>$productId) $addProduct->execute(['campaign'=>$campaignId,'product'=>$productId,'position'=>$position]); $addGroup = $db->prepare('INSERT INTO campaign_groups (campaign_id,group_id) VALUES (:campaign,:group)'); foreach ($groupIds as $groupId) $addGroup->execute(['campaign'=>$campaignId,'group'=>$groupId]); $this->redirect('/admin/campanhas?aviso=' . urlencode($draft ? 'Rascunho salvo no banco.' : ($status === 'AGENDADA' ? 'Campanha agendada e registrada.' : 'Campanha pronta para liberação no celular.')) . '#historico');
    }

    private function monthLabel(string $month): string
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $month . '-01'); return $date ? ucfirst((new \IntlDateFormatter('pt_BR', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'America/Sao_Paulo', \IntlDateFormatter::GREGORIAN, 'MMMM yyyy'))->format($date)) : $month;
    }

    private function campaignResultsPage(string $id): void
    {
        $store = $this->store((string) $_SESSION['store_id']); $db = $this->db();
        $campaign = $db->prepare("SELECT c.*, COUNT(DISTINCT l.id) FILTER (WHERE l.event='CATALOG_OPEN')::int AS visits, COUNT(DISTINCT l.id) FILTER (WHERE l.event='WHATSAPP_CLICK')::int AS clicks, COUNT(DISTINCT o.id)::int AS orders, COALESCE(SUM(o.total_cents) FILTER (WHERE o.status IN ('PAGO','PREPARACAO','ENVIADO','SAIU_PARA_ENTREGA','ENTREGUE')),0)::int AS revenue FROM campaigns c LEFT JOIN campaign_logs l ON l.campaign_id=c.id LEFT JOIN orders o ON o.campaign_id=c.id WHERE c.id=:id AND c.store_id=:store GROUP BY c.id"); $campaign->execute(['id'=>$id,'store'=>$store['id']]); $campaign=$campaign->fetch(); if (!$campaign) $this->notFound();
        $products = $db->prepare("SELECT p.name,p.slug,p.price_cents,p.media,c.name AS category_name,COALESCE(SUM((line.item->>'quantity')::int) FILTER (WHERE o.status IN ('PAGO','PREPARACAO','ENVIADO','SAIU_PARA_ENTREGA','ENTREGUE')),0)::int AS sold FROM campaign_products cp JOIN products p ON p.id=cp.product_id JOIN categories c ON c.id=p.category_id LEFT JOIN orders o ON o.campaign_id=cp.campaign_id LEFT JOIN LATERAL jsonb_array_elements(o.items) line(item) ON true WHERE cp.campaign_id=:id GROUP BY p.id,c.name,cp.position ORDER BY cp.position"); $products->execute(['id'=>$id]);
        $groups = $db->prepare('SELECT g.name,g.members,cg.status,cg.sent_at,cg.error_safe FROM campaign_groups cg JOIN whatsapp_groups g ON g.id=cg.group_id WHERE cg.campaign_id=:id ORDER BY g.priority'); $groups->execute(['id'=>$id]); $conversion=(int)$campaign['visits']?round(((int)$campaign['orders']/(int)$campaign['visits'])*100,1):0;
        $this->layout('Resultados da campanha - ' . $store['name'], function () use ($campaign,$products,$groups,$conversion): void { echo '<div class="admin-shell"><main class="admin-main campaignResults"><a class="backAdmin" href="/admin/campanhas">← Voltar para campanhas</a><header class="campaignHero"><div><p class="eyebrow">RESULTADOS DA CAMPANHA</p><h1>' . $this->e($campaign['name']) . '</h1><p>' . $this->e(str_replace('_',' ',(string)$campaign['status'])) . ' · ' . date('d/m/Y H:i',strtotime((string)($campaign['scheduled_for'] ?: $campaign['created_at']))) . '</p></div><a class="campaignReady" href="/promocao/' . $this->e($campaign['id']) . '" target="_blank">ABRIR CATÁLOGO PROMO →</a></header><section class="campaignStats"><article><span>Aberturas do catálogo</span><b>' . (int)$campaign['visits'] . '</b><small>visitas registradas</small></article><article><span>Cliques para WhatsApp</span><b>' . (int)$campaign['clicks'] . '</b><small>interesse no atendimento</small></article><article><span>Pedidos e conversão</span><b>' . (int)$campaign['orders'] . ' · ' . $conversion . '%</b><small>R$ ' . $this->money((int)$campaign['revenue']) . ' em vendas pagas</small></article></section><section class="campaignResultGrid"><article class="panel"><p class="eyebrow">PRODUTOS</p><h2>Peças da campanha</h2>'; foreach($products as $product){$media=json_decode((string)$product['media'],true)?:[];$image=is_array($media)&&isset($media[0])?(is_array($media[0])?(string)($media[0]['url']??''):(string)$media[0]):'/images/logo.jpeg';echo '<div class="resultProduct"><img src="'.$this->e($image).'" alt=""><span><b>'.$this->e($product['name']).'</b><small>'.$this->e($product['category_name']).' · R$ '.$this->money((int)$product['price_cents']).'</small></span><strong>'.$this->e((string)$product['sold']).' vendidas</strong></div>';} echo '</article><article class="panel"><p class="eyebrow">ENTREGA</p><h2>Grupos de destino</h2>';foreach($groups as $group) echo '<div class="resultGroup"><span><b>'.$this->e($group['name']).'</b><small>'.(int)($group['members']??0).' participantes</small></span><i class="campaignStatus '.strtolower($this->e($group['status'])).'">'.$this->e($group['status']).'</i></div>';echo '</article></section></main></div>'; });
    }

    private function scheduledCampaignsPage(): void
    {
        $store = $this->store((string) $_SESSION['store_id']);
        $query = $this->db()->prepare("SELECT c.id, c.name, c.message, c.scheduled_for FROM campaigns c WHERE c.store_id = :store AND c.status IN ('AGENDADA', 'PROCESSANDO') AND c.archived_at IS NULL ORDER BY c.scheduled_for NULLS LAST, c.created_at DESC");
        $query->execute(['store' => $store['id']]);
        $campaigns = $query->fetchAll();
        $products = $this->db()->prepare('SELECT p.name, p.slug, p.price_cents, p.media FROM campaign_products cp JOIN products p ON p.id = cp.product_id WHERE cp.campaign_id = :campaign ORDER BY cp.position');
        $groups = $this->db()->prepare('SELECT g.name FROM campaign_groups cg JOIN whatsapp_groups g ON g.id = cg.group_id WHERE cg.campaign_id = :campaign ORDER BY g.priority, g.name');
        foreach ($campaigns as &$campaign) {
            $products->execute(['campaign' => $campaign['id']]);
            $campaign['products'] = $products->fetchAll();
            $groups->execute(['campaign' => $campaign['id']]);
            $campaign['groups'] = array_column($groups->fetchAll(), 'name');
            $links = array_map(static fn(array $product): string => (string) $product['name'] . "\n/produto/" . (string) $product['slug'], $campaign['products']);
            $campaign['share_items_message'] = trim((string) $campaign['message']) . "\n\nAcesse o link e veja a foto, cores, tamanhos e todos os detalhes no catálogo:\n\n" . implode("\n\n", $links);
        }
        unset($campaign);
        $this->layout('Campanhas prontas para enviar - ' . $store['name'], function () use ($campaigns): void {
            echo '<div class="admin-shell"><main class="admin-main readyCampaignsPage"><a class="backAdmin" href="/admin/campanhas">← Voltar para campanhas</a><section class="readyCampaignsHeading"><p class="eyebrow">AGENDA</p><h1>Prontas para enviar</h1><p>Campanhas programadas para os próximos envios.</p></section>';
            echo '<section class="readyShareBar"><span><b data-ready-count>0</b>/5 campanhas selecionadas</span><button type="button" disabled data-ready-share>COMPARTILHAR SELECIONADAS</button></section><p class="readyShareNotice" data-ready-notice hidden></p><section class="readyCampaignsList">';
            if (!$campaigns) echo '<p class="emptySchedule">Nenhuma campanha programada.</p>';
            foreach ($campaigns as $campaign) {
                $promotionUrl = '/promocao/' . $campaign['id'];
                $message = trim((string) $campaign['message']) . "\n\nAcesse o catálogo promocional completo para ver as ofertas, fotos, cores, tamanhos e todos os detalhes:\n" . $promotionUrl . "\n\nChame a gente pelo WhatsApp para garantir a sua!";
                echo '<article class="readyCampaign" data-ready-campaign data-share-items-message="' . $this->e((string) $campaign['share_items_message']) . '" data-share-catalog-message="' . $this->e($message) . '"><label class="readyCampaignSelect"><input type="checkbox" data-ready-select> SELECIONAR PARA COMPARTILHAR</label><div class="readyCampaignTitle"><div><b>' . $this->e($campaign['name']) . '</b><small>' . ($campaign['scheduled_for'] ? 'Agendada para ' . date('d/m/Y H:i', strtotime((string) $campaign['scheduled_for'])) : 'Pronta para envio') . '</small></div><i class="campaignStatus ' . ($campaign['scheduled_for'] ? 'agendada' : 'processando') . '">' . ($campaign['scheduled_for'] ? 'AGENDADA' : 'PROCESSANDO') . '</i></div><p class="readyCampaignMessage">' . nl2br($this->e((string) $campaign['message'])) . '</p><div class="readyProducts">';
                foreach ($campaign['products'] as $product) {
                    $media = json_decode((string) $product['media'], true) ?: [];
                    $image = is_array($media) && isset($media[0]) ? (is_array($media[0]) ? (string) ($media[0]['url'] ?? '') : (string) $media[0]) : '/images/logo.jpeg';
                    echo '<div><img src="' . $this->e($image) . '" alt=""><span><b>' . $this->e($product['name']) . '</b><small>R$ ' . $this->money((int) $product['price_cents']) . '</small></span></div>';
                }
                echo '</div><small class="readyGroups">Grupos: ' . $this->e(implode(', ', $campaign['groups']) ?: 'Nenhum grupo') . '</small><p class="readyPromotionUrl">Catálogo promocional: <span data-ready-url>' . $this->e($promotionUrl) . '</span></p><div class="readyCampaignActions"><button type="button" data-share-items>COMPARTILHAR ITENS</button><button type="button" data-share-catalog>COMPARTILHAR CATÁLOGO</button><a href="' . $this->e($promotionUrl) . '" target="_blank" rel="noopener">ABRIR CATÁLOGO</a></div></article>';
            }
            echo '</section></main></div>';
        });
    }

    private function ordersPage(array $store): void
    {
        $statuses = ['NOVO', 'CONFIRMADO', 'PAGO', 'PREPARACAO', 'ENVIADO', 'SAIU_PARA_ENTREGA', 'ENTREGUE', 'DEVOLVIDO', 'CANCELADO'];
        $selected = (string) ($_GET['status'] ?? '');
        $search = trim((string) ($_GET['busca'] ?? ''));
        $page = max(1, (int) ($_GET['pagina'] ?? 1));
        if ($selected !== '' && !in_array($selected, $statuses, true)) $selected = '';
        $where = ' FROM orders WHERE store_id = :store';
        $params = ['store' => $store['id']];
        if ($selected !== '') { $where .= ' AND status = :status'; $params['status'] = $selected; }
        if ($search !== '') { $where .= ' AND (code ILIKE :search OR customer_name ILIKE :search OR phone ILIKE :search)'; $params['search'] = '%' . $search . '%'; }
        $totalQuery = $this->db()->prepare('SELECT COUNT(*)' . $where); $totalQuery->execute($params); $total = (int) $totalQuery->fetchColumn();
        $pages = max(1, (int) ceil($total / 10)); $page = min($page, $pages); $params['offset'] = ($page - 1) * 10;
        $query = 'SELECT id, code, customer_name, phone, address, notes, payment_method, status, items, subtotal_cents, discount_cents, total_cents, coupon_code, created_at' . $where . ' ORDER BY created_at DESC LIMIT 10 OFFSET :offset';
        $orders = $this->db()->prepare($query);
        $orders->bindValue(':store', $store['id']); if ($selected !== '') $orders->bindValue(':status', $selected); if ($search !== '') $orders->bindValue(':search', '%' . $search . '%'); $orders->bindValue(':offset', $params['offset'], PDO::PARAM_INT); $orders->execute();
        $counter = $this->db()->prepare('SELECT status, COUNT(*)::int AS total FROM orders WHERE store_id = :store GROUP BY status'); $counter->execute(['store' => $store['id']]); $counts = array_column($counter->fetchAll(), 'total', 'status');
        $notice = (string) ($_GET['aviso'] ?? '');
        echo '<section id="orders-data" data-auto-refresh="15000"><section class="orderMetrics"><a class="' . ($selected === '' ? 'active' : '') . '" href="/admin/pedidos"><b>' . array_sum(array_map('intval', $counts)) . '</b>Todos</a>';
        foreach (['NOVO','PAGO','PREPARACAO','ENVIADO','ENTREGUE','DEVOLVIDO','CANCELADO'] as $metric) echo '<a class="' . ($selected === $metric ? 'active' : '') . '" href="/admin/pedidos?status=' . $metric . '"><b>' . (int) ($counts[$metric] ?? 0) . '</b>' . $this->e(str_replace('_',' ', $metric)) . '</a>';
        echo '</section><section class="ordersToolbar"><form method="get"><input name="busca" value="' . $this->e($search) . '" placeholder="Buscar código, cliente ou WhatsApp"><label>Status<select name="status" onchange="this.form.submit()"><option value="">Todos os pedidos</option>';
        foreach ($statuses as $status) echo '<option value="' . $status . '"' . ($selected === $status ? ' selected' : '') . '>' . $this->e(str_replace('_', ' ', $status)) . '</option>';
        echo '</select></label><button type="submit">BUSCAR</button></form><span>Atualize o status assim que o pedido avançar.</span></section>';
        if ($notice !== '') echo '<p class="notice success">' . $this->e($notice) . '</p>';
        echo '<section class="ordersList">';
        foreach ($orders as $order) {
            $items = json_decode((string) $order['items'], true) ?: [];
            $message = match ($order['status']) { 'PAGO' => 'Olá, ' . $order['customer_name'] . '! Recebemos o pagamento do pedido ' . $order['code'] . '. Sua compra está em preparação.', 'PREPARACAO' => 'Olá, ' . $order['customer_name'] . '! Seu pedido ' . $order['code'] . ' está em preparação.', 'ENVIADO' => 'Olá, ' . $order['customer_name'] . '! Seu pedido ' . $order['code'] . ' foi enviado.', 'SAIU_PARA_ENTREGA' => 'Olá, ' . $order['customer_name'] . '! Seu pedido ' . $order['code'] . ' saiu para entrega.', 'ENTREGUE' => 'Olá, ' . $order['customer_name'] . '! Seu pedido ' . $order['code'] . ' foi marcado como entregue. Obrigada por comprar com a gente!', default => '' };
            echo '<article class="orderCard"><header><div><p>' . $this->e($order['code']) . '</p><h2>' . $this->e($order['customer_name']) . '</h2><small>' . date('d/m/Y · H:i', strtotime((string) $order['created_at'])) . ' · ' . $this->e($order['payment_method']) . '</small></div><strong>R$ ' . $this->money((int) $order['total_cents']) . '</strong></header><div class="orderDetails"><p><b>WhatsApp:</b> ' . $this->e($order['phone']) . '</p><p><b>Entrega:</b> ' . $this->e($order['address']) . '</p>' . ($order['notes'] ? '<p><b>Obs.:</b> ' . $this->e($order['notes']) . '</p>' : '') . '<ul>';
            foreach ($items as $item) echo '<li>' . (int) ($item['quantity'] ?? 1) . '× ' . $this->e((string) ($item['name'] ?? 'Produto')) . '</li>';
            echo '</ul></div><footer><form method="post" action="/admin/pedidos">' . $this->csrfField() . '<input type="hidden" name="id" value="' . $this->e($order['id']) . '"><label>Status<select name="status" onchange="this.form.submit()">';
            foreach ($statuses as $status) echo '<option value="' . $status . '"' . ($order['status'] === $status ? ' selected' : '') . '>' . $this->e(str_replace('_', ' ', $status)) . '</option>';
            echo '</select></label><noscript><button class="primary-button" type="submit">SALVAR STATUS</button></noscript></form>' . ($message !== '' ? '<a class="customerUpdate" target="_blank" rel="noopener" href="https://wa.me/55' . $this->e(preg_replace('/\D/', '', (string) $order['phone'])) . '?text=' . rawurlencode($message) . '">ATUALIZAR CLIENTE</a>' : '') . '<span class="orderCurrent">' . $this->e(str_replace('_', ' ', $order['status'])) . '</span></footer></article>';
        }
        echo '</section>';
        if ($pages > 1) { $base = ['status' => $selected, 'busca' => $search]; echo '<nav class="ordersPagination">' . ($page > 1 ? '<a href="/admin/pedidos?' . http_build_query($base + ['pagina' => $page - 1]) . '">← ANTERIOR</a>' : '<span>← ANTERIOR</span>') . '<b>Página ' . $page . ' de ' . $pages . ' · ' . $total . ' pedidos</b>' . ($page < $pages ? '<a href="/admin/pedidos?' . http_build_query($base + ['pagina' => $page + 1]) . '">PRÓXIMA →</a>' : '<span>PRÓXIMA →</span>') . '</nav>'; }
        echo '</section>';
    }

    private function orderStatusSubmit(): never
    {
        $this->verifyCsrf();
        $id = (string) ($_POST['id'] ?? '');
        $status = (string) ($_POST['status'] ?? '');
        $allowed = ['NOVO', 'CONFIRMADO', 'PAGO', 'PREPARACAO', 'ENVIADO', 'SAIU_PARA_ENTREGA', 'ENTREGUE', 'DEVOLVIDO', 'CANCELADO'];
        if (!preg_match('/^[a-f0-9-]{36}$/i', $id) || !in_array($status, $allowed, true)) $this->redirect('/admin/pedidos?aviso=' . urlencode('Dados do pedido inválidos.'));
        $db = $this->db();
        $db->beginTransaction();
        try {
            $current = $db->prepare('SELECT status FROM orders WHERE id = :id AND store_id = :store FOR UPDATE');
            $current->execute(['id' => $id, 'store' => $_SESSION['store_id']]);
            $old = $current->fetchColumn();
            if (!$old) throw new \RuntimeException('Pedido não encontrado.');
            if ($old !== $status) {
                $db->prepare('UPDATE orders SET status = :status, updated_at = now() WHERE id = :id')->execute(['status' => $status, 'id' => $id]);
                $db->prepare('INSERT INTO order_status_events (id, order_id, status, created_at) VALUES (gen_random_uuid(), :order, :status, now())')->execute(['order' => $id, 'status' => $status]);
            }
            $db->commit();
            $this->redirect('/admin/pedidos?aviso=' . urlencode('Status atualizado para ' . str_replace('_', ' ', $status) . '.'));
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            $this->redirect('/admin/pedidos?aviso=' . urlencode('Não foi possível atualizar este pedido.'));
        }
    }

    private function productsPage(array $store): void
    {
        $db = $this->db();
        $categories = $db->prepare('SELECT id, name FROM categories WHERE store_id = :store AND active = true ORDER BY name');
        $categories->execute(['store' => $store['id']]);
        $categories = $categories->fetchAll();
        $search = trim((string) ($_GET['busca'] ?? ''));
        $categoryId = (string) ($_GET['categoria'] ?? '');
        $conditions = ['p.store_id = :store'];
        $params = ['store' => $store['id']];
        if ($search !== '') {
            $conditions[] = '(p.name ILIKE :search OR p.code ILIKE :search)';
            $params['search'] = '%' . $search . '%';
        }
        if ($categoryId !== '') {
            $conditions[] = 'p.category_id = :category';
            $params['category'] = $categoryId;
        }
        $products = $db->prepare(
            'SELECT p.id, p.name, p.code, p.description, p.price_cents, p.cost_cents, p.active, p.media, p.variations, p.stock, p.category_id, c.name AS category_name
             FROM products p JOIN categories c ON c.id = p.category_id
             WHERE ' . implode(' AND ', $conditions) . ' ORDER BY p.created_at DESC LIMIT 300'
        );
        $products->execute($params);
        $allProducts = $products->fetchAll();
        $stockFilter = (string) ($_GET['estoque'] ?? 'TODOS');
        if (in_array($stockFilter, ['DISPONIVEL', 'REPOR', 'ESGOTADO'], true)) {
            $allProducts = array_values(array_filter($allProducts, function (array $product) use ($stockFilter): bool {
                $quantity = $this->stockTotal($product['stock']);
                return $stockFilter === 'ESGOTADO' ? $quantity === 0 : ($stockFilter === 'REPOR' ? $quantity > 0 && $quantity <= 3 : $quantity > 3);
            }));
        }
        $page = max(1, (int) ($_GET['pagina'] ?? 1));
        $perPage = 10; $totalPages = max(1, (int) ceil(count($allProducts) / $perPage)); $page = min($page, $totalPages);
        $visibleProducts = array_slice($allProducts, ($page - 1) * $perPage, $perPage);
        $editId = (string) ($_GET['editar'] ?? '');
        $editing = null;
        foreach ($allProducts as $candidate) if ($candidate['id'] === $editId) { $editing = $candidate; break; }
        $editVariations = $editing ? (json_decode((string) $editing['variations'], true) ?: []) : [];
        $editStock = $editing ? (json_decode((string) $editing['stock'], true) ?: ['default' => 0]) : ['default' => 0];
        $notice = (string) ($_GET['aviso'] ?? '');
        echo '<div class="product-layout">';
        echo '<section class="product-form panel"><div class="form-heading"><p class="eyebrow">' . ($editing ? 'EDITAR' : 'NOVO PRODUTO') . '</p><h2>' . ($editing ? 'Editar produto' : 'Cadastro completo') . '</h2><span>Fotos, variações e estoque em um só cadastro.</span></div>';
        if ($notice !== '') echo '<p class="notice success">' . $this->e($notice) . '</p>';
        echo '<form method="post" action="/admin/produtos" class="category-inline">' . $this->csrfField() . '<input type="hidden" name="action" value="create-category"><label>Nova categoria<input name="category_name" placeholder="Ex.: Vestidos" required></label><button type="submit">ADICIONAR</button></form>';
        echo '<div class="category-pills"><span>Categorias:</span>';
        foreach ($categories as $category) echo '<span>' . $this->e($category['name']) . '</span>';
        echo '</div>';
        $val = static fn(?array $item, string $key): string => $item ? (string) ($item[$key] ?? '') : '';
        echo '<form method="post" action="/admin/produtos" class="full-form productEditor" enctype="multipart/form-data" data-product-editor>' . $this->csrfField() . '<input type="hidden" name="action" value="' . ($editing ? 'update-product' : 'create-product') . '">' . ($editing ? '<input type="hidden" name="id" value="' . $this->e($editing['id']) . '">' : '');
        echo '<label>Nome<input name="name" required maxlength="180" value="' . $this->e($val($editing, 'name')) . '"></label><div class="two-fields"><label>Código<input name="code" required maxlength="64" value="' . $this->e($val($editing, 'code')) . '"></label><label>Categoria<select name="category_id" required><option value="">Selecione</option>';
        foreach ($categories as $category) echo '<option value="' . $this->e($category['id']) . '"' . ($editing && $editing['category_id'] === $category['id'] ? ' selected' : '') . '>' . $this->e($category['name']) . '</option>';
        echo '</select></label></div><div class="two-fields"><label>Preço de venda<input name="price" inputmode="decimal" placeholder="189,90" required value="' . ($editing ? $this->e(number_format((int) $editing['price_cents'] / 100, 2, ',', '.')) : '') . '"></label><label>Preço de custo<input name="cost" inputmode="decimal" placeholder="90,00" required value="' . ($editing ? $this->e(number_format((int) $editing['cost_cents'] / 100, 2, ',', '.')) : '') . '"></label></div>';
        echo '<div class="basicStockField"><label>Estoque sem variação<input name="basic_stock" type="number" min="0" value="' . (int) ($editStock['default'] ?? 0) . '"></label></div><label>Descrição<textarea name="description" required maxlength="4000">' . $this->e($val($editing, 'description')) . '</textarea></label>';
        echo '<section class="variationBox"><b>Tamanhos, cores e variações</b><small>Ex.: Tamanho: P, M, G · Cor: Preto, Vinho</small><div class="presetBox"><span>Modelo da peça</span><div><button type="button" data-product-preset="regular">TAMANHOS P–GG</button><button type="button" data-product-preset="plus">PLUS SIZE</button><button type="button" data-product-preset="unique">TAMANHO ÚNICO</button><button type="button" data-product-preset="footwear">SANDÁLIAS 34–39</button></div></div><div data-variation-rows>';
        foreach ($editVariations as $index => $variation) { if (!is_array($variation)) continue; echo '<div class="variationRow"><input type="hidden" name="variation_id[]" value="' . $this->e((string) ($variation['id'] ?? ('variacao-' . ($index + 1)))) . '"><input name="variation_name[]" placeholder="Tamanho ou Cor" value="' . $this->e((string) ($variation['name'] ?? '')) . '"><input name="variation_values[]" placeholder="P, M, G" value="' . $this->e(implode(', ', is_array($variation['values'] ?? null) ? $variation['values'] : [])) . '"><button type="button" data-remove-variation>×</button></div>'; }
        echo '</div><button type="button" class="addVariation" data-add-variation>+ ADICIONAR VARIAÇÃO</button><div class="stockGrid" data-stock-grid data-stock="' . $this->e(json_encode($editStock, JSON_UNESCAPED_UNICODE)) . '"></div></section>';
        $media = $editing ? (json_decode((string) $editing['media'], true) ?: []) : [];
        echo '<label>Fotos<input name="photos[]" type="file" accept="image/jpeg,image/png,image/webp" multiple><small>Até 8 fotos no total. JPG, PNG ou WebP, até 5 MB cada.</small></label>';
        if ($media) { echo '<div class="productPhotoPreview">'; foreach ($media as $entry) { $url = is_array($entry) ? ($entry['url'] ?? '') : $entry; if ($url) echo '<img src="' . $this->e((string) $url) . '" alt="Foto atual">'; } echo '</div>'; }
        echo '<label class="toggle"><input name="active" type="checkbox"' . (!$editing || $editing['active'] ? ' checked' : '') . '> Publicar catálogo</label><button class="primary-button" type="submit">' . ($editing ? 'SALVAR ALTERAÇÕES' : 'CRIAR PRODUTO') . '</button>' . ($editing ? '<a class="cancelEditor" href="/admin/produtos">CANCELAR</a>' : '') . '</form></section>';
        echo '<section class="product-list panel"><div class="form-heading"><p class="eyebrow">CATÁLOGO</p><h2>Produtos cadastrados</h2></div>';
        echo '<form class="product-search" method="get" action="/admin/produtos"><input name="busca" value="' . $this->e($search) . '" placeholder="Buscar por nome ou código"><select name="categoria"><option value="">Todas as categorias</option>';
        foreach ($categories as $category) echo '<option value="' . $this->e($category['id']) . '"' . ($categoryId === $category['id'] ? ' selected' : '') . '>' . $this->e($category['name']) . '</option>';
        echo '</select><select name="estoque"><option value="TODOS">Todos os estoques</option><option value="DISPONIVEL"' . ($stockFilter === 'DISPONIVEL' ? ' selected' : '') . '>Disponível</option><option value="REPOR"' . ($stockFilter === 'REPOR' ? ' selected' : '') . '>Para repor</option><option value="ESGOTADO"' . ($stockFilter === 'ESGOTADO' ? ' selected' : '') . '>Esgotado</option></select><button type="submit">BUSCAR</button></form>';
        echo '<p class="found-count">' . count($allProducts) . ' produto(s) encontrado(s)</p>';
        foreach ($visibleProducts as $product) {
            $media = json_decode((string) $product['media'], true) ?: [];
            $image = is_array($media) && isset($media[0]) ? (is_array($media[0]) ? ($media[0]['url'] ?? '') : $media[0]) : '';
            $stock = $this->stockTotal($product['stock']);
            echo '<article class="product-row">' . ($image ? '<img src="' . $this->e((string) $image) . '" alt="">' : '<div class="row-photo">SEM FOTO</div>');
            echo '<div><b>' . $this->e($product['name']) . '</b><small>' . $this->e($product['category_name']) . ' · ' . $this->e($product['code']) . '</small><strong>R$ ' . $this->money((int) $product['price_cents']) . '</strong><em>Estoque: ' . $stock . ' un. · Custo: R$ ' . $this->money((int) $product['cost_cents']) . '</em></div>';
            echo '<aside><i class="' . ($product['active'] ? 'status-active' : 'status-draft') . '">' . ($product['active'] ? 'Ativo' : 'Rascunho') . '</i><a class="text-edit" href="/admin/produtos?editar=' . $this->e($product['id']) . '">EDITAR</a><form method="post" action="/admin/produtos" onsubmit="return confirm(\'Excluir este produto?\')">' . $this->csrfField() . '<input type="hidden" name="action" value="delete-product"><input type="hidden" name="id" value="' . $this->e($product['id']) . '"><button class="text-delete" type="submit">EXCLUIR</button></form></aside></article>';
        }
        if (!$visibleProducts) echo '<p class="empty">Nenhum produto encontrado.</p>';
        if ($totalPages > 1) { $base = '/admin/produtos?' . http_build_query(array_filter(['busca' => $search, 'categoria' => $categoryId, 'estoque' => $stockFilter])); echo '<nav class="productPagination">' . ($page > 1 ? '<a href="' . $this->e($base . '&pagina=' . ($page - 1)) . '">← Anterior</a>' : '<span>← Anterior</span>') . '<b>Página ' . $page . ' de ' . $totalPages . '</b>' . ($page < $totalPages ? '<a href="' . $this->e($base . '&pagina=' . ($page + 1)) . '">Próxima →</a>' : '<span>Próxima →</span>') . '</nav>'; }
        echo '</section></div>';
    }

    private function productSubmit(): never
    {
        $this->verifyCsrf();
        $storeId = (string) $_SESSION['store_id'];
        $action = (string) ($_POST['action'] ?? '');
        $db = $this->db();
        if ($action === 'create-category') {
            $name = trim((string) ($_POST['category_name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 100) $this->redirect('/admin/produtos?aviso=' . urlencode('Informe um nome de categoria válido.'));
            $slug = $this->slug($name);
            $insert = $db->prepare('INSERT INTO categories (id, store_id, name, slug, active, created_at, updated_at) VALUES (gen_random_uuid(), :store, :name, :slug, true, now(), now())');
            try { $insert->execute(['store' => $storeId, 'name' => $name, 'slug' => $slug]); } catch (PDOException) { $this->redirect('/admin/produtos?aviso=' . urlencode('Essa categoria já existe.')); }
            $this->redirect('/admin/produtos?aviso=' . urlencode('Categoria criada.'));
        }
        if ($action === 'delete-product') {
            $delete = $db->prepare('DELETE FROM products WHERE id = :id AND store_id = :store');
            $delete->execute(['id' => (string) ($_POST['id'] ?? ''), 'store' => $storeId]);
            $this->redirect('/admin/produtos?aviso=' . urlencode('Produto excluído.'));
        }
        if (!in_array($action, ['create-product', 'update-product'], true)) $this->redirect('/admin/produtos');
        $name = trim((string) ($_POST['name'] ?? ''));
        $code = trim((string) ($_POST['code'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $categoryId = (string) ($_POST['category_id'] ?? '');
        $price = $this->cents((string) ($_POST['price'] ?? ''));
        $cost = $this->cents((string) ($_POST['cost'] ?? ''));
        $variationNames = $_POST['variation_name'] ?? []; $variationValues = $_POST['variation_values'] ?? []; $variationIds = $_POST['variation_id'] ?? [];
        $variations = []; foreach ($variationNames as $index => $variationName) { $variationName = trim((string) $variationName); $values = array_values(array_filter(array_map('trim', explode(',', (string) ($variationValues[$index] ?? ''))))); if ($variationName !== '' || $values) { if ($variationName === '' || !$values || count($values) > 20) $this->redirect('/admin/produtos?aviso=' . urlencode('Preencha o nome e os valores de cada variação.')); $variationId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($variationIds[$index] ?? '')) ?: 'variacao-' . (count($variations) + 1); $variations[] = ['id' => $variationId, 'name' => $variationName, 'values' => array_values(array_unique($values))]; } }
        if (count($variations) > 3) $this->redirect('/admin/produtos?aviso=' . urlencode('Use no máximo três tipos de variação.'));
        $combos = $this->variationKeys($variations); $stock = [];
        if ($combos) foreach ($combos as $key) $stock[$key] = max(0, (int) (($_POST['stock_map'][$key] ?? 0)));
        else $stock = ['default' => max(0, (int) ($_POST['basic_stock'] ?? 0))];
        if ($name === '' || $code === '' || $description === '' || $categoryId === '' || $price === null || $cost === null) $this->redirect('/admin/produtos?aviso=' . urlencode('Preencha todos os campos do produto corretamente.'));
        $category = $db->prepare('SELECT id FROM categories WHERE id = :id AND store_id = :store AND active = true');
        $category->execute(['id' => $categoryId, 'store' => $storeId]);
        if (!$category->fetch()) $this->redirect('/admin/produtos?aviso=' . urlencode('Selecione uma categoria válida.'));
        $id = (string) ($_POST['id'] ?? '');
        $currentMedia = [];
        if ($action === 'update-product') { $current = $db->prepare('SELECT media FROM products WHERE id = :id AND store_id = :store'); $current->execute(['id' => $id, 'store' => $storeId]); $storedMedia = $current->fetchColumn(); if ($storedMedia === false) $this->redirect('/admin/produtos?aviso=' . urlencode('Produto não encontrado.')); $currentMedia = json_decode((string) $storedMedia, true) ?: []; }
        $photoErrors = $_FILES['photos']['error'] ?? []; $incomingPhotos = is_array($photoErrors) ? count(array_filter($photoErrors, static fn($error): bool => (int) $error !== UPLOAD_ERR_NO_FILE)) : 0;
        if (count($currentMedia) + $incomingPhotos > 8) $this->redirect('/admin/produtos?aviso=' . urlencode('Use no máximo 8 fotos por produto.'));
        $media = array_merge($currentMedia, $this->productPhotoUploads());
        try {
            $data = ['store' => $storeId, 'slug' => $this->slug($name), 'code' => $code, 'name' => $name, 'category' => $categoryId, 'description' => $description, 'price' => $price, 'cost' => $cost, 'active' => isset($_POST['active']) ? 'true' : 'false', 'media' => json_encode(array_slice($media, 0, 8), JSON_UNESCAPED_UNICODE), 'variations' => json_encode($variations, JSON_UNESCAPED_UNICODE), 'stock' => json_encode($stock, JSON_UNESCAPED_UNICODE)];
            if ($action === 'update-product') { $data['id'] = $id; $db->prepare('UPDATE products SET slug=:slug, code=:code, name=:name, category_id=:category, description=:description, price_cents=:price, cost_cents=:cost, active=:active, media=:media::jsonb, variations=:variations::jsonb, stock=:stock::jsonb, updated_at=now() WHERE id=:id AND store_id=:store')->execute($data); }
            else $db->prepare('INSERT INTO products (id, store_id, slug, code, name, category_id, description, price_cents, cost_cents, active, media, variations, stock, created_at, updated_at) VALUES (gen_random_uuid(), :store, :slug, :code, :name, :category, :description, :price, :cost, :active, :media::jsonb, :variations::jsonb, :stock::jsonb, now(), now())')->execute($data);
        } catch (PDOException) {
            $this->redirect('/admin/produtos?aviso=' . urlencode('Não foi possível salvar. Confira se o código não está repetido.'));
        }
        $this->redirect('/admin/produtos?aviso=' . urlencode($action === 'update-product' ? 'Produto atualizado com sucesso.' : 'Produto criado com sucesso.'));
    }

    private function variationKeys(array $variations): array
    {
        if (!$variations) return [];
        $keys = [''];
        foreach ($variations as $group) { $next = []; foreach ($keys as $prefix) foreach ($group['values'] as $value) $next[] = ltrim($prefix . '|' . $group['id'] . '=' . $value, '|'); $keys = $next; }
        return $keys;
    }

    private function productPhotoUploads(): array
    {
        $files = $_FILES['photos'] ?? null; if (!is_array($files) || !isset($files['name']) || !is_array($files['name'])) return [];
        $uploads = []; $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp']; $directory = dirname(__DIR__) . '/public/uploads/products/'; if (!is_dir($directory)) mkdir($directory, 0775, true);
        foreach ($files['name'] as $index => $_) { if (($files['error'][$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue; if (($files['error'][$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($files['size'][$index] ?? 0) > 5 * 1024 * 1024) $this->redirect('/admin/produtos?aviso=' . urlencode('Cada foto deve ter no máximo 5 MB.')); $type = mime_content_type((string) $files['tmp_name'][$index]); if (!isset($extensions[$type])) $this->redirect('/admin/produtos?aviso=' . urlencode('Use fotos JPG, PNG ou WebP.')); $name = bin2hex(random_bytes(16)) . '.' . $extensions[$type]; if (!move_uploaded_file((string) $files['tmp_name'][$index], $directory . $name)) $this->redirect('/admin/produtos?aviso=' . urlencode('Não foi possível salvar uma das fotos.')); $uploads[] = '/uploads/products/' . $name; }
        return $uploads;
    }

    private function settingsPage(array $store): void
    {
        $theme = $this->theme($store['id']);
        $mode = (string) ($_GET['modo'] ?? '');
        $notice = (string) ($_GET['aviso'] ?? '');
        $defaults = ['logoUrl' => '', 'bannerUrl' => '', 'catalogBackgroundUrl' => '', 'primaryColor' => '#D7A843', 'secondaryColor' => '#15100B', 'accentColor' => '#F4E9D6', 'heroEyebrow' => 'CATÁLOGO ONLINE', 'heroTitle' => 'Seu estilo.', 'heroHighlight' => 'Seu momento.', 'heroDescription' => 'Conheça nossas peças, detalhes e formas de compra.', 'ctaLabel' => 'VER CATÁLOGO', 'heroEyebrowColor' => '#E2BB67', 'heroTitleColor' => '#FFFFFF', 'heroHighlightColor' => '#D7A843', 'heroDescriptionColor' => '#F4EEE5', 'storeNameColor' => '#FFFFFF'];
        $theme = array_merge($defaults, array_filter($theme, static fn($value): bool => $value !== null && $value !== ''));
        if ($mode === '') {
            echo '<section class="settingsChoice panel"><p class="eyebrow">CATÁLOGO</p><h2>O que você quer fazer?</h2><span>Crie um catálogo novo ou edite a versão que já está publicada.</span><div><a class="primary-button" href="/admin/configuracoes?modo=create">CRIAR CATÁLOGO</a><a class="settingsDraftButton" href="/admin/configuracoes?modo=edit">EDITAR CATÁLOGO</a></div><p>As alterações ficam no rascunho desta loja até você publicar.</p></section>';
            return;
        }
        $e = fn(string $key): string => $this->e((string) ($theme[$key] ?? ''));
        echo '<div class="settingsWorkspace"><form method="post" action="/admin/configuracoes" enctype="multipart/form-data" class="settingsEditor">' . $this->csrfField() . '<input type="hidden" name="action" value="save-settings"><input type="hidden" name="mode" value="' . $this->e($mode) . '">';
        echo '<section><h2>Identidade</h2><label>Nome da loja<input name="name" value="' . $this->e((string) $store['name']) . '" required maxlength="120"></label><label>Cor do nome da loja<input type="color" name="store_name_color" value="' . $e('storeNameColor') . '"></label><label>WhatsApp<input name="whatsapp" value="' . $this->e((string) ($store['whatsapp'] ?? '')) . '" inputmode="numeric" placeholder="62999999999"></label><label>Cor do fundo do catálogo<input type="color" name="secondary_color" value="' . $e('secondaryColor') . '"></label><small>Você pode escolher também um fundo claro para o catálogo.</small></section>';
        echo '<section><h2>Imagens</h2>' . $this->settingsImageField('Logo', 'logo', $e('logoUrl')) . $this->settingsImageField('Banner de destaque', 'banner', $e('bannerUrl')) . $this->settingsImageField('Fundo do catálogo', 'background', $e('catalogBackgroundUrl')) . '</section>';
        echo '<section><h2>Textos e cores</h2>' . $this->settingsTextColorField('Texto pequeno', 'hero_eyebrow', 'hero_eyebrow_color', $e('heroEyebrow'), $e('heroEyebrowColor')) . $this->settingsTextColorField('Primeira linha', 'hero_title', 'hero_title_color', $e('heroTitle'), $e('heroTitleColor')) . $this->settingsTextColorField('Segunda linha', 'hero_highlight', 'hero_highlight_color', $e('heroHighlight'), $e('heroHighlightColor')) . $this->settingsTextColorField('Apresentação', 'hero_description', 'hero_description_color', $e('heroDescription'), $e('heroDescriptionColor')) . '<label>Texto do botão<input name="cta_label" value="' . $e('ctaLabel') . '" maxlength="80"></label><label>Cor principal<input type="color" name="primary_color" value="' . $e('primaryColor') . '"></label><label>Cor de destaque<input type="color" name="accent_color" value="' . $e('accentColor') . '"></label></section>';
        echo '<div class="settingsActions"><button class="settingsDraftButton" name="publish" value="0">SALVAR RASCUNHO</button><button class="primary-button" name="publish" value="1">PUBLICAR CATÁLOGO</button></div>';
        if ($notice !== '') echo '<p class="notice success">' . $this->e($notice) . '</p>';
        echo '</form><aside class="settingsPreview"><div><p class="eyebrow">PRÉVIA AO VIVO</p><h2>Como a cliente verá</h2></div><div class="settingsPhone" data-settings-preview><div class="settingsPhoneTop"><img data-preview-logo src="' . $e('logoUrl') . '" alt=""><strong data-preview-store>' . $this->e((string) $store['name']) . '</strong></div><div class="settingsHero" data-preview-hero><p data-preview-eyebrow>' . $e('heroEyebrow') . '</p><h3 data-preview-title>' . $e('heroTitle') . '<br><em data-preview-highlight>' . $e('heroHighlight') . '</em></h3><small data-preview-description>' . $e('heroDescription') . '</small><button data-preview-cta>' . $e('ctaLabel') . '</button></div></div></aside></div>';
    }

    private function settingsImageField(string $label, string $slot, string $value): string
    {
        return '<label>' . $this->e($label) . '<input name="' . $slot . '_url" value="' . $this->e($value) . '" placeholder="Cole a URL da imagem" data-preview-input="' . $slot . '"><input name="' . $slot . '_file" type="file" accept="image/jpeg,image/png,image/webp"><small>Envie JPG, PNG ou WebP de até 5 MB.</small></label>';
    }

    private function settingsTextColorField(string $label, string $text, string $color, string $value, string $colorValue): string
    {
        return '<label>' . $this->e($label) . '<span class="settingsColoredInput"><input name="' . $text . '" value="' . $this->e($value) . '" data-preview-input="' . $this->e($text) . '"><input name="' . $color . '" type="color" value="' . $this->e($colorValue) . '" data-preview-color="' . $this->e($text) . '"></span></label>';
    }

    private function settingsSubmit(): never
    {
        $this->verifyCsrf(); $storeId = (string) $_SESSION['store_id']; $db = $this->db();
        $name = trim((string) ($_POST['name'] ?? '')); if ($name === '' || mb_strlen($name) > 120) $this->redirect('/admin/configuracoes?modo=edit&aviso=' . urlencode('Informe um nome de loja válido.'));
        $whatsapp = preg_replace('/\D/', '', (string) ($_POST['whatsapp'] ?? '')) ?? '';
        $fields = ['primary_color', 'secondary_color', 'accent_color', 'hero_eyebrow', 'hero_title', 'hero_highlight', 'hero_description', 'cta_label', 'hero_eyebrow_color', 'hero_title_color', 'hero_highlight_color', 'hero_description_color', 'store_name_color'];
        $data = []; foreach ($fields as $field) $data[$field] = trim((string) ($_POST[$field] ?? ''));
        foreach (['primary_color','secondary_color','accent_color','hero_eyebrow_color','hero_title_color','hero_highlight_color','hero_description_color','store_name_color'] as $color) if (!preg_match('/^#[0-9a-fA-F]{6}$/', $data[$color])) $this->redirect('/admin/configuracoes?modo=edit&aviso=' . urlencode('Uma das cores informadas é inválida.'));
        $current = $this->theme($storeId); $data['logo_url'] = $this->settingsImageUpload('logo', $current['logoUrl'] ?? ''); $data['banner_url'] = $this->settingsImageUpload('banner', $current['bannerUrl'] ?? ''); $data['facade_url'] = $this->settingsImageUpload('background', $current['catalogBackgroundUrl'] ?? '');
        $db->beginTransaction();
        try { $db->prepare('UPDATE stores SET name=:name, whatsapp=:whatsapp, updated_at=now() WHERE id=:id')->execute(['name' => $name, 'whatsapp' => $whatsapp, 'id' => $storeId]);
            $exists = $db->prepare('SELECT 1 FROM store_themes WHERE store_id=:store'); $exists->execute(['store' => $storeId]);
            $sql = $exists->fetchColumn() ? 'UPDATE store_themes SET logo_url=:logo_url,banner_url=:banner_url,facade_url=:facade_url,primary_color=:primary_color,secondary_color=:secondary_color,accent_color=:accent_color,hero_eyebrow=:hero_eyebrow,hero_title=:hero_title,hero_highlight=:hero_highlight,hero_description=:hero_description,cta_label=:cta_label,hero_eyebrow_color=:hero_eyebrow_color,hero_title_color=:hero_title_color,hero_highlight_color=:hero_highlight_color,hero_description_color=:hero_description_color,store_name_color=:store_name_color,updated_at=now() WHERE store_id=:store_id' : 'INSERT INTO store_themes (store_id,logo_url,banner_url,facade_url,primary_color,secondary_color,accent_color,hero_eyebrow,hero_title,hero_highlight,hero_description,cta_label,hero_eyebrow_color,hero_title_color,hero_highlight_color,hero_description_color,store_name_color,updated_at) VALUES (:store_id,:logo_url,:banner_url,:facade_url,:primary_color,:secondary_color,:accent_color,:hero_eyebrow,:hero_title,:hero_highlight,:hero_description,:cta_label,:hero_eyebrow_color,:hero_title_color,:hero_highlight_color,:hero_description_color,:store_name_color,now())';
            $data['store_id'] = $storeId; $db->prepare($sql)->execute($data); $db->commit();
        } catch (\Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
        $this->redirect('/admin/configuracoes?modo=edit&aviso=' . urlencode(((string) ($_POST['publish'] ?? '0')) === '1' ? 'Catálogo publicado com sucesso.' : 'Rascunho salvo.'));
    }

    private function settingsImageUpload(string $slot, string $current): string
    {
        $file = $_FILES[$slot . '_file'] ?? null; $url = trim((string) ($_POST[$slot . '_url'] ?? ''));
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return $url !== '' ? $url : $current;
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 5 * 1024 * 1024) $this->redirect('/admin/configuracoes?modo=edit&aviso=' . urlencode('Cada imagem deve ter no máximo 5 MB.'));
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp']; $type = mime_content_type((string) $file['tmp_name']); if (!isset($extensions[$type])) $this->redirect('/admin/configuracoes?modo=edit&aviso=' . urlencode('Use imagem JPG, PNG ou WebP.'));
        $directory = dirname(__DIR__) . '/public/uploads/theme/'; if (!is_dir($directory)) mkdir($directory, 0775, true); $name = bin2hex(random_bytes(16)) . '.' . $extensions[$type]; if (!move_uploaded_file((string) $file['tmp_name'], $directory . $name)) $this->redirect('/admin/configuracoes?modo=edit&aviso=' . urlencode('Não foi possível salvar a imagem.')); return '/uploads/theme/' . $name;
    }

    private function couponsPage(array $store): void
    {
        $db = $this->db(); $view = (string) ($_GET['view'] ?? 'ATIVOS'); if (!in_array($view, ['ATIVOS', 'EXPIRADOS'], true)) $view = 'ATIVOS';
        $coupons = $db->prepare("SELECT c.*, ca.name AS campaign_name, (SELECT COUNT(*) FROM orders o WHERE o.store_id=c.store_id AND upper(o.coupon_code)=upper(c.code))::int AS uses FROM coupons c LEFT JOIN campaigns ca ON ca.id=c.campaign_id WHERE c.store_id=:store AND " . ($view === 'ATIVOS' ? "c.ends_at > now() AND c.active=true" : "(c.ends_at <= now() OR c.active=false)") . ' ORDER BY c.created_at DESC');
        $coupons->execute(['store' => $store['id']]); $coupons = $coupons->fetchAll();
        $campaigns = $db->prepare('SELECT id,name FROM campaigns WHERE store_id=:store AND archived_at IS NULL ORDER BY created_at DESC'); $campaigns->execute(['store' => $store['id']]); $campaigns = $campaigns->fetchAll();
        $customers = $db->prepare("SELECT DISTINCT ON (phone) phone, customer_name AS name, MAX(created_at) OVER (PARTITION BY phone) AS last_order FROM orders WHERE store_id=:store AND phone <> '' ORDER BY phone, created_at DESC"); $customers->execute(['store' => $store['id']]); $customers = $customers->fetchAll();
        $notice = (string) ($_GET['aviso'] ?? '');
        echo '<header class="couponHeader"><p>PROMOÇÕES</p><h1>Cupons de desconto</h1><span>Use o prefixo CAP- para criar um cupom exclusivo de uma campanha.</span></header>';
        if ($notice !== '') echo '<p class="notice success">' . $this->e($notice) . '</p>';
        echo '<section class="welcomeCoupon"><div><p>CUPOM DE BOAS-VINDAS</p><h2>Captação de clientes no catálogo</h2><span>Gera um cupom pessoal pelo WhatsApp informado no modal do catálogo.</span></div><form method="post" action="/admin/cupons">' . $this->csrfField() . '<input type="hidden" name="action" value="welcome"><label class="toggle"><input name="enabled" type="checkbox"' . ($store['welcome_coupon_enabled'] ? ' checked' : '') . '> Ativar modal</label><label>Desconto (%)<input name="discount" type="number" min="1" max="100" value="' . (int) ($store['welcome_coupon_discount'] ?? 20) . '"></label><label>Validade (horas)<input name="duration" type="number" min="1" max="8760" value="' . (int) ($store['welcome_coupon_duration_hours'] ?? 168) . '"></label><button type="submit">SALVAR BOAS-VINDAS</button></form></section>';
        echo '<div class="couponLayout"><section class="couponCreate"><p>NOVO CUPOM</p><h2>Monte sua oferta</h2><form method="post" action="/admin/cupons" data-coupon-form>' . $this->csrfField() . '<input type="hidden" name="action" value="create"><label>Código do cupom<input name="code" required maxlength="60" placeholder="EX.: CAP-VIP20" data-coupon-code><small>Prefixo CAP-: desconto exclusivo dos itens da campanha.</small></label><label data-campaign-field hidden>Campanha vinculada<select name="campaign_id"><option value="">Selecione a campanha</option>';
        foreach ($campaigns as $campaign) echo '<option value="' . $this->e($campaign['id']) . '">' . $this->e($campaign['name']) . '</option>';
        echo '</select><small>O desconto será calculado apenas sobre os produtos desta campanha.</small></label><label>Nome da oferta<input name="title" required maxlength="120" placeholder="Ex.: Oferta da campanha"></label><label>Descrição <small>(opcional)</small><input name="description" maxlength="500" placeholder="Ex.: Válido hoje"></label><div class="couponValues"><label>Tipo<select name="discount_type"><option value="PERCENT">Porcentagem (%)</option><option value="FIXED">Valor em reais (R$)</option></select></label><label>Desconto<input name="discount_value" type="number" min="1" step="0.01" value="10" required></label></div><label>Limite total de usos <small>(vazio = sem limite)</small><input name="max_uses" type="number" min="1"></label><label class="toggle"><input name="one_per_customer" type="checkbox"> Permitir somente um uso por cliente</label><label>Começa em <small>(vazio = agora)</small><input name="starts_at" type="datetime-local"></label><label>Encerra em<input name="ends_at" type="datetime-local" required></label><button type="submit">CRIAR CUPOM</button></form></section>';
        echo '<section class="couponList"><div class="couponListHead"><div><p>SEUS CUPONS</p><h2>Ofertas criadas</h2></div><form method="get"><label>Exibir<select name="view" onchange="this.form.submit()"><option value="ATIVOS"' . ($view === 'ATIVOS' ? ' selected' : '') . '>Ativos</option><option value="EXPIRADOS"' . ($view === 'EXPIRADOS' ? ' selected' : '') . '>Expirados</option></select></label><b>' . count($coupons) . ' ' . ($view === 'ATIVOS' ? 'ativo' : 'expirado') . (count($coupons) === 1 ? '' : 's') . '</b></form></div>';
        foreach ($coupons as $coupon) { $expired = strtotime((string) $coupon['ends_at']) <= time(); $scheduled = $coupon['starts_at'] && strtotime((string) $coupon['starts_at']) > time(); $state = $expired ? 'EXPIRADO' : ($scheduled ? 'AGENDADO' : 'ATIVO'); $discount = $coupon['discount_type'] === 'PERCENT' ? (int) $coupon['discount_value'] . '% OFF' : 'R$ ' . $this->money((int) $coupon['discount_value']); echo '<article class="couponRow' . (!$coupon['active'] || $expired ? ' couponOff' : '') . '"><div><b>' . $this->e($coupon['code']) . '</b><span>' . $this->e($coupon['title']) . '</span>' . ($coupon['campaign_name'] ? '<small>Campanha: ' . $this->e($coupon['campaign_name']) . '</small>' : '') . ($coupon['description'] ? '<small>' . $this->e($coupon['description']) . '</small>' : '') . '</div><div><strong>' . $discount . '</strong><small>' . (int) $coupon['uses'] . ($coupon['max_uses'] ? ' / ' . (int) $coupon['max_uses'] : '') . ' uso(s) · encerra ' . $this->e(date('d/m/Y H:i', strtotime((string) $coupon['ends_at']))) . '</small></div><i>' . $state . '</i><aside>' . (!$expired ? '<form method="post" action="/admin/cupons">' . $this->csrfField() . '<input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="' . $this->e($coupon['id']) . '"><button>' . ($coupon['active'] ? 'DESATIVAR' : 'ATIVAR') . '</button></form>' : '') . '<form method="post" action="/admin/cupons" onsubmit="return confirm(\'Excluir este cupom?\')">' . $this->csrfField() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . $this->e($coupon['id']) . '"><button class="text-delete">EXCLUIR</button></form></aside></article>'; }
        if (!$coupons) echo '<p class="empty">' . ($view === 'ATIVOS' ? 'Nenhum cupom ativo no momento.' : 'Nenhum cupom expirado encontrado.') . '</p>';
        echo '</section></div>';
        echo '<section class="audiencePanel"><div><p>REENGAJAMENTO</p><h2>Enviar cupom para clientes</h2><span>Escolha uma oferta e copie a mensagem personalizada para cada cliente no WhatsApp.</span></div><form class="audienceTools" method="get" action="/admin/cupons"><input type="hidden" name="view" value="' . $this->e($view) . '"><label>Cupom ativo<select name="recipient_coupon" onchange="this.form.submit()"><option value="">Selecione o cupom</option>';
        $selectedCoupon = (string) ($_GET['recipient_coupon'] ?? ''); foreach ($coupons as $coupon) if (!$coupon['active'] || strtotime((string) $coupon['ends_at']) <= time()) continue; else echo '<option value="' . $this->e($coupon['id']) . '"' . ($selectedCoupon === $coupon['id'] ? ' selected' : '') . '>' . $this->e($coupon['code'] . ' · ' . $coupon['title']) . '</option>'; echo '</select></label><label>Público<select><option>Nunca comprou</option><option>30+ dias sem comprar</option><option>45+ dias sem comprar</option><option>Comprou uma vez</option></select></label></form>';
        if ($selectedCoupon) { $couponInfo = null; foreach ($coupons as $item) if ($item['id'] === $selectedCoupon) $couponInfo = $item; echo '<div class="audienceList">'; foreach (array_slice($customers, 0, 30) as $customer) { $message = 'Olá, ' . ($customer['name'] ?: 'cliente') . '! Temos uma oferta especial para você: use o cupom ' . ($couponInfo['code'] ?? '') . ' no catálogo.'; echo '<article><div><b>' . $this->e($customer['name'] ?: 'Cliente') . '</b><small>' . $this->e($customer['phone']) . '</small></div><a target="_blank" rel="noopener" href="https://wa.me/' . $this->e(preg_replace('/\D/', '', $customer['phone'])) . '?text=' . rawurlencode($message) . '">COMPARTILHAR</a></article>'; } if (!$customers) echo '<p class="empty">Nenhuma cliente encontrada ainda.</p>'; echo '</div>'; }
        echo '</section>';
    }

    private function couponSubmit(): never
    {
        $this->verifyCsrf(); $db = $this->db(); $storeId = (string) $_SESSION['store_id']; $action = (string) ($_POST['action'] ?? ''); $back = '/admin/cupons?view=' . urlencode((string) ($_POST['view'] ?? 'ATIVOS'));
        if ($action === 'welcome') { $db->prepare('UPDATE stores SET welcome_coupon_enabled=:enabled,welcome_coupon_discount=:discount,welcome_coupon_duration_hours=:duration,updated_at=now() WHERE id=:id')->execute(['enabled' => isset($_POST['enabled']) ? 'true' : 'false', 'discount' => max(1, min(100, (int) ($_POST['discount'] ?? 20))), 'duration' => max(1, min(8760, (int) ($_POST['duration'] ?? 168))), 'id' => $storeId]); $this->redirect('/admin/cupons?aviso=' . urlencode('Cupom de boas-vindas atualizado.')); }
        $id = (string) ($_POST['id'] ?? ''); if ($action === 'toggle') { $db->prepare('UPDATE coupons SET active=NOT active,updated_at=now() WHERE id=:id AND store_id=:store')->execute(['id' => $id, 'store' => $storeId]); $this->redirect($back . '&aviso=' . urlencode('Cupom atualizado.')); } if ($action === 'delete') { $db->prepare('DELETE FROM coupons WHERE id=:id AND store_id=:store')->execute(['id' => $id, 'store' => $storeId]); $this->redirect($back . '&aviso=' . urlencode('Cupom excluído.')); }
        if ($action !== 'create') $this->redirect('/admin/cupons');
        $code = strtoupper(preg_replace('/[^A-Z0-9_-]/', '', (string) ($_POST['code'] ?? '')) ?? ''); $title = trim((string) ($_POST['title'] ?? '')); $type = (string) ($_POST['discount_type'] ?? 'PERCENT'); $value = $type === 'FIXED' ? $this->cents((string) ($_POST['discount_value'] ?? '')) : (int) ($_POST['discount_value'] ?? 0); $end = trim((string) ($_POST['ends_at'] ?? '')); $start = trim((string) ($_POST['starts_at'] ?? ''));
        if ($code === '' || $title === '' || !in_array($type, ['PERCENT','FIXED'], true) || $value === null || $value < 1 || ($type === 'PERCENT' && $value > 100) || $end === '') $this->redirect('/admin/cupons?aviso=' . urlencode('Confira os dados do cupom.'));
        $campaign = str_starts_with($code, 'CAP-') ? (string) ($_POST['campaign_id'] ?? '') : null; if (str_starts_with($code, 'CAP-') && !$campaign) $this->redirect('/admin/cupons?aviso=' . urlencode('Selecione a campanha deste cupom.'));
        try { $db->prepare('INSERT INTO coupons (id,store_id,code,title,description,discount_type,discount_value,active,max_uses,one_per_customer,campaign_id,starts_at,ends_at,created_at,updated_at) VALUES (gen_random_uuid(),:store,:code,:title,:description,:type,:value,true,:max,:one,:campaign,:start,:end,now(),now())')->execute(['store'=>$storeId,'code'=>$code,'title'=>$title,'description'=>trim((string) ($_POST['description'] ?? '')) ?: null,'type'=>$type,'value'=>$value,'max'=>($_POST['max_uses'] ?? '') !== '' ? max(1,(int)$_POST['max_uses']) : null,'one'=>isset($_POST['one_per_customer']) ? 'true':'false','campaign'=>$campaign ?: null,'start'=>$start !== '' ? str_replace('T',' ',$start) : null,'end'=>str_replace('T',' ',$end)]); } catch (PDOException) { $this->redirect('/admin/cupons?aviso=' . urlencode('Não foi possível criar. Verifique se o código já existe.')); }
        $this->redirect('/admin/cupons?aviso=' . urlencode('Cupom criado e pronto para o catálogo.'));
    }

    private function movementsPage(array $store): void
    {
        $db = $this->db(); $tab = (string) ($_GET['aba'] ?? 'estoque'); if (!in_array($tab, ['estoque','despesas'], true)) $tab = 'estoque'; $notice = (string) ($_GET['aviso'] ?? '');
        echo '<header class="movementHeader"><p>GESTÃO FINANCEIRA</p><h1>Movimentações</h1><span>Controle as saídas de estoque e os gastos da operação em um só lugar.</span></header><nav class="movementTabs"><a class="' . ($tab === 'estoque' ? 'selected' : '') . '" href="/admin/movimentacoes?aba=estoque">ESTOQUE</a><a class="' . ($tab === 'despesas' ? 'selected' : '') . '" href="/admin/movimentacoes?aba=despesas">DESPESAS</a></nav>';
        if ($notice !== '') echo '<p class="notice success">' . $this->e($notice) . '</p>';
        if ($tab === 'despesas') { $this->expensesContent($store); return; }
        $category = (string) ($_GET['categoria'] ?? 'TODAS'); $page = max(1, (int) ($_GET['pagina'] ?? 1));
        $categories = $db->prepare('SELECT DISTINCT c.name FROM products p JOIN categories c ON c.id=p.category_id WHERE p.store_id=:store ORDER BY c.name'); $categories->execute(['store'=>$store['id']]); $categories = $categories->fetchAll(PDO::FETCH_COLUMN);
        $query = "SELECT p.name AS product, c.name AS category, COALESCE(SUM((line.item->>'quantity')::int),0)::int AS quantity, COUNT(DISTINCT o.id)::int AS order_count, MAX(o.created_at) AS created_at FROM orders o CROSS JOIN LATERAL jsonb_array_elements(o.items) line(item) LEFT JOIN products p ON p.id::text=line.item->>'id' LEFT JOIN categories c ON c.id=p.category_id WHERE o.store_id=:store AND o.status IN ('PAGO','PREPARACAO','ENVIADO','SAIU_PARA_ENTREGA','ENTREGUE')"; $params=['store'=>$store['id']]; if ($category !== 'TODAS') { $query .= ' AND c.name=:category'; $params['category']=$category; } $query .= ' GROUP BY p.name,c.name ORDER BY MAX(o.created_at) DESC'; $moves=$db->prepare($query); $moves->execute($params); $moves=$moves->fetchAll(); $pages=max(1,(int)ceil(count($moves)/10));$page=min($page,$pages);$visible=array_slice($moves,($page-1)*10,10);
        echo '<div class="movementTools"><form method="get"><input type="hidden" name="aba" value="estoque"><label>Categoria<select name="categoria" onchange="this.form.submit()"><option value="TODAS">Todas as categorias</option>'; foreach($categories as $item) echo '<option' . ($category===$item?' selected':'') . '>' . $this->e($item) . '</option>'; echo '</select></label></form><b>' . count($moves) . ' movimentaç' . (count($moves)===1?'ão':'ões') . '</b></div><section class="movementList">';
        foreach($visible as $move) echo '<article><i>SAÍDA</i><div><b>' . $this->e($move['product'] ?: 'Produto removido') . '</b><small>' . $this->e($move['category'] ?: 'Sem categoria') . '</small><small>' . (int)$move['order_count'] . ' pedido(s) com saída deste produto</small></div><strong>-' . (int)$move['quantity'] . ' un.</strong><time>Última saída: ' . $this->e(date('d/m/Y H:i',strtotime((string)$move['created_at']))) . '</time></article>';
        if (!$visible) echo '<p class="empty">Nenhuma saída de estoque encontrada.</p>'; echo '</section>'; $this->movementPagination('estoque',$page,$pages,$category);
    }

    private function expensesContent(array $store): void
    {
        $db=$this->db();$category=(string)($_GET['categoria']??'TODAS');$page=max(1,(int)($_GET['pagina']??1));$categories=['Combustível','Entrega e frete','Embalagem','Anúncios','Taxas','Aluguel','Outros'];$available=$db->prepare('SELECT DISTINCT category FROM expenses WHERE store_id=:store ORDER BY category');$available->execute(['store'=>$store['id']]);$categories=array_values(array_unique(array_merge($categories,$available->fetchAll(PDO::FETCH_COLUMN))));
        $sql='SELECT * FROM expenses WHERE store_id=:store';$params=['store'=>$store['id']];if($category!=='TODAS'){$sql.=' AND category=:category';$params['category']=$category;}$sql.=' ORDER BY occurred_at DESC,created_at DESC';$query=$db->prepare($sql);$query->execute($params);$expenses=$query->fetchAll();$pages=max(1,(int)ceil(count($expenses)/10));$page=min($page,$pages);$visible=array_slice($expenses,($page-1)*10,10);
        echo '<section class="expenseForm"><div><p>REGISTRAR DESPESA</p><h2>O que sua loja gastou?</h2><span>Ex.: combustível, embalagem, frete, anúncios ou taxas.</span></div><form method="post" action="/admin/movimentacoes">' . $this->csrfField() . '<input type="hidden" name="action" value="create-expense"><label>Tipo<select name="category">';foreach($categories as $item)echo '<option>'.$this->e($item).'</option>';echo '</select></label><label>Descrição<input name="description" required maxlength="240" placeholder="Ex.: entrega de peças"></label><label>Valor<input name="amount" required inputmode="decimal" placeholder="50,00"></label><label>Data<input name="occurred_at" required type="date" max="' . date('Y-m-d') . '" value="' . date('Y-m-d') . '"></label><button>REGISTRAR DESPESA</button></form></section>';
        echo '<div class="movementTools"><form method="get"><input type="hidden" name="aba" value="despesas"><label>Tipo<select name="categoria" onchange="this.form.submit()"><option value="TODAS">Todas as despesas</option>';foreach($categories as $item)echo '<option' . ($category===$item?' selected':'') . '>'.$this->e($item).'</option>';echo '</select></label></form><b>'.count($expenses).' despesa'.(count($expenses)===1?'':'s').'</b></div><section class="movementList">';foreach($visible as $expense)echo '<article><i>DESPESA</i><div><b>'.$this->e($expense['category']).'</b><small>'.$this->e($expense['description']).'</small></div><strong>-R$ '.$this->money((int)$expense['amount_cents']).'</strong><time>'.$this->e(date('d/m/Y',strtotime((string)$expense['occurred_at']))).'</time><form method="post" action="/admin/movimentacoes" onsubmit="return confirm(\'Excluir esta despesa?\')">'.$this->csrfField().'<input type="hidden" name="action" value="delete-expense"><input type="hidden" name="id" value="'.$this->e($expense['id']).'"><button class="text-delete">EXCLUIR</button></form></article>';if(!$visible)echo '<p class="empty">Nenhuma despesa registrada neste filtro.</p>';echo '</section>';$this->movementPagination('despesas',$page,$pages,$category);
    }

    private function movementPagination(string $tab,int $page,int $pages,string $category): void
    {
        if($pages<2)return;$base='/admin/movimentacoes?'.http_build_query(['aba'=>$tab,'categoria'=>$category]);echo '<nav class="movementPagination">'.($page>1?'<a href="'.$this->e($base.'&pagina='.($page-1)).'">← Anterior</a>':'<span>← Anterior</span>').'<b>Página '.$page.' de '.$pages.'</b>'.($page<$pages?'<a href="'.$this->e($base.'&pagina='.($page+1)).'">Próxima →</a>':'<span>Próxima →</span>').'</nav>';
    }

    private function movementSubmit(): never
    {
        $this->verifyCsrf();$db=$this->db();$store=(string)$_SESSION['store_id'];$action=(string)($_POST['action']??'');if($action==='delete-expense'){$db->prepare('DELETE FROM expenses WHERE id=:id AND store_id=:store')->execute(['id'=>(string)($_POST['id']??''),'store'=>$store]);$this->redirect('/admin/movimentacoes?aba=despesas&aviso='.urlencode('Despesa excluída.'));}if($action!=='create-expense')$this->redirect('/admin/movimentacoes');$category=trim((string)($_POST['category']??''));$description=trim((string)($_POST['description']??''));$amount=$this->cents((string)($_POST['amount']??''));$date=(string)($_POST['occurred_at']??'');if($category===''||$description===''||$amount===null||$amount<1||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))$this->redirect('/admin/movimentacoes?aba=despesas&aviso='.urlencode('Informe os dados da despesa corretamente.'));$db->prepare('INSERT INTO expenses(id,store_id,category,description,amount_cents,occurred_at,created_at,updated_at) VALUES(gen_random_uuid(),:store,:category,:description,:amount,:date,now(),now())')->execute(['store'=>$store,'category'=>$category,'description'=>$description,'amount'=>$amount,'date'=>$date.' 12:00:00']);$this->redirect('/admin/movimentacoes?aba=despesas&aviso='.urlencode('Despesa registrada. O lucro do Dashboard foi atualizado.'));
    }

    private function customersPage(array $store): void
    {
        $db=$this->db();$search=trim((string)($_GET['busca']??''));$filter=(string)($_GET['filtro']??'TODOS');$from=(string)($_GET['de']??'');$to=(string)($_GET['ate']??'');$page=max(1,(int)($_GET['pagina']??1));
        $query=$db->prepare("SELECT phone, MAX(customer_name) AS name, COUNT(*)::int AS orders, COUNT(*) FILTER (WHERE status IN ('PAGO','PREPARACAO','ENVIADO','SAIU_PARA_ENTREGA','ENTREGUE'))::int AS paid_orders, COUNT(*) FILTER (WHERE status IN ('NOVO','CONFIRMADO'))::int AS open_orders, COALESCE(SUM(total_cents) FILTER (WHERE status IN ('PAGO','PREPARACAO','ENVIADO','SAIU_PARA_ENTREGA','ENTREGUE')),0)::int AS paid_total, MAX(created_at) AS last_order FROM orders WHERE store_id=:store GROUP BY phone HAVING phone <> '' ORDER BY MAX(created_at) DESC");$query->execute(['store'=>$store['id']]);$customers=[];foreach($query as $item){$last=strtotime((string)$item['last_order']);$state=(int)$item['orders']===1?'NOVO':((int)$item['paid_orders']>=2?'RECORRENTE':($last<strtotime('-45 days')?'INATIVO':'OCASIONAL'));if(($search!==''&&!str_contains(mb_strtolower((string)$item['name'].' '.$item['phone']),mb_strtolower($search)) )||($filter!=='TODOS'&&$state!==$filter)||($from!==''&&$last<strtotime($from))||($to!==''&&$last>strtotime($to.' 23:59:59')))continue;$item['customer_status']=$state;$customers[]=$item;}
        $summary=['customers'=>count($customers),'paid'=>array_sum(array_map(static fn($item)=>(int)$item['paid_total'],$customers)),'open'=>array_sum(array_map(static fn($item)=>(int)$item['open_orders'],$customers))];$pages=max(1,(int)ceil(count($customers)/10));$page=min($page,$pages);$visible=array_slice($customers,($page-1)*10,10);
        $leadView=(string)($_GET['leads']??'NENHUM');$leadTotal=0;$leads=[];if(in_array($leadView,['TODOS','NOVOS'],true)){$leadSql='SELECT * FROM catalog_leads WHERE store_id=:store';$args=['store'=>$store['id']];if($leadView==='NOVOS'){$leadSql.=" AND created_at >= now() - interval '30 days'";}$leadSql.=' ORDER BY created_at DESC LIMIT 50';$leadQuery=$db->prepare($leadSql);$leadQuery->execute($args);$leads=$leadQuery->fetchAll();$leadTotal=count($leads);}
        echo '<section id="customers-data" data-auto-refresh="15000"><header class="customerHeader"><div><p>RELACIONAMENTO</p><h1>Clientes</h1><span>Histórico de compras e contato da sua loja.</span></div><div class="customerSummary"><b>'.$summary['customers'].'</b><small>clientes</small><strong>R$ '.$this->money($summary['paid']).'</strong><small>faturamento confirmado</small><b>'.$summary['open'].'</b><small>pedidos em aberto</small><b>'.$leadTotal.'</b><small>leads exibidos</small></div></header>';
        echo '<section class="customerPanel"><form class="customerTools" method="get"><label>⌕<input name="busca" value="'.$this->e($search).'" placeholder="Buscar por nome ou WhatsApp"></label><label>De<input type="date" name="de" value="'.$this->e($from).'"></label><label>Até<input type="date" name="ate" value="'.$this->e($to).'"></label><select name="filtro"><option value="TODOS">Todos os clientes</option>';foreach(['NOVO'=>'Novos','RECORRENTE'=>'Recorrentes','OCASIONAL'=>'Ocasionais','INATIVO'=>'Inativos'] as $key=>$label)echo '<option value="'.$key.'"'.($filter===$key?' selected':'').'>'.$label.'</option>';echo '</select><button>BUSCAR</button><span>'.count($customers).' cliente'.(count($customers)===1?'':'s').'</span></form><div class="customerList">';
        foreach($visible as $customer){$initials=mb_strtoupper(mb_substr((string)$customer['name'],0,2));$phone=preg_replace('/\D/','',(string)$customer['phone']);$historyUrl='/admin/clientes?'.http_build_query(['busca'=>$search,'de'=>$from,'ate'=>$to,'filtro'=>$filter,'pagina'=>$page,'historico'=>$customer['phone']]);echo '<article><div class="customerAvatar">'.$this->e($initials).'</div><div class="customerIdentity"><b>'.$this->e($customer['name']).'</b><small>WhatsApp: '.$this->e($customer['phone']).'</small><i>'.$this->e(ucfirst(strtolower((string)$customer['customer_status']))).'</i>'.((int)$customer['open_orders']>0?'<small>'.(int)$customer['open_orders'].' pedido(s) em aberto</small>':'').'</div><div><b>'.(int)$customer['orders'].'</b><small>pedido(s)</small></div><div><b>R$ '.$this->money((int)$customer['paid_total']).'</b><small>valor pago</small></div><div><b>'.$this->e(date('d/m/Y',strtotime((string)$customer['last_order']))).'</b><small>último pedido</small></div><div class="customerActions"><a href="https://wa.me/'.$this->e($phone).'" target="_blank" rel="noopener">WHATSAPP</a><button type="button" data-customer-history data-history-url="'.$this->e($historyUrl).'">HISTÓRICO</button></div></article>';}
        if(!$visible)echo '<p class="empty">Nenhum cliente encontrado.</p>';echo '</div>';if($pages>1){$base='/admin/clientes?'.http_build_query(['busca'=>$search,'de'=>$from,'ate'=>$to,'filtro'=>$filter]);echo '<nav class="movementPagination">'.($page>1?'<a href="'.$this->e($base.'&pagina='.($page-1)).'">← Anterior</a>':'<span>← Anterior</span>').'<b>Página '.$page.' de '.$pages.'</b>'.($page<$pages?'<a href="'.$this->e($base.'&pagina='.($page+1)).'">Próxima →</a>':'<span>Próxima →</span>').'</nav>';}echo '</section>';
        echo '<section class="customerPanel leadsPanel"><div class="leadsHeading"><div><p>LEADS CAPTURADOS</p><h2>Clientes do cupom de boas-vindas</h2><span>Escolha um filtro para carregar os leads do catálogo.</span></div><b>'.$leadTotal.' lead'.($leadTotal===1?'':'s').'</b></div><form class="leadTools" method="get"><label>Exibir<select name="leads" onchange="this.form.submit()"><option value="NENHUM"'.($leadView==='NENHUM'?' selected':'').'>Escolha uma opção</option><option value="TODOS"'.($leadView==='TODOS'?' selected':'').'>Todos</option><option value="NOVOS"'.($leadView==='NOVOS'?' selected':'').'>Novos (últimos 30 dias)</option></select></label></form>';
        if($leadView==='NENHUM')echo '<p class="empty">Escolha “Todos” ou “Novos” para ver os clientes capturados pelo cupom.</p>';else{echo '<div class="customerList leadsList">';foreach($leads as $lead){$phone=preg_replace('/\D/','',(string)$lead['phone']);echo '<article><div class="customerAvatar">'.$this->e(mb_strtoupper(mb_substr((string)$lead['name'],0,2))).'</div><div class="customerIdentity"><b>'.$this->e($lead['name']).'</b><small>WhatsApp: '.$this->e($lead['phone']).'</small><small>Origem: '.$this->e($lead['source']).'</small></div><div><b>'.$this->e($lead['welcome_coupon_code'] ?: '—').'</b><small>cupom pessoal</small></div><div><b>'.$this->e(date('d/m/Y',strtotime((string)$lead['created_at']))).'</b><small>'.($lead['marketing_consent']?'Aceitou novidades':'Sem aceite').'</small></div><a href="https://wa.me/'.$this->e($phone).'" target="_blank" rel="noopener">WHATSAPP</a></article>';}if(!$leads)echo '<p class="empty">Nenhum lead encontrado para este filtro.</p>';echo '</div>';}echo '</section>';
        $historyPhone=(string)($_GET['historico']??'');if($historyPhone!==''){$history=$db->prepare('SELECT code,total_cents,status,items,created_at FROM orders WHERE store_id=:store AND phone=:phone ORDER BY created_at DESC');$history->execute(['store'=>$store['id'],'phone'=>$historyPhone]);echo '<div class="couponModal customerModal"><a class="couponModalShade" href="/admin/clientes"></a><section><a class="couponModalClose" href="/admin/clientes">×</a><p>HISTÓRICO DA CLIENTE</p><h2>'.$this->e($historyPhone).'</h2><div class="customerHistory">';foreach($history as $order){$items=json_decode((string)$order['items'],true)?:[];echo '<article><div><b>'.$this->e($order['code']).'</b><small>'.date('d/m/Y H:i',strtotime((string)$order['created_at'])).' · '.$this->e(str_replace('_',' ',$order['status'])).'</small><ul>';foreach($items as $item)echo '<li>'.(int)($item['quantity']??1).'× '.$this->e($item['name']??'Produto').'</li>';echo '</ul></div><strong>R$ '.$this->money((int)$order['total_cents']).'</strong></article>';}echo '</div><a class="goCartCoupon" target="_blank" rel="noopener" href="https://wa.me/'.$this->e(preg_replace('/\D/','',$historyPhone)).'">CONVERSAR NO WHATSAPP</a></section></div>';}
        echo '</section>';
    }

    private function adminCounts(string $storeId): array
    {
        $db = $this->db();
        $count = static function (PDO $db, string $table, string $store, string $extra = ''): int {
            $query = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE store_id = :store {$extra}");
            $query->execute(['store' => $store]);
            return (int) $query->fetchColumn();
        };
        $customers = $db->prepare('SELECT COUNT(DISTINCT phone) FROM orders WHERE store_id = :store');
        $customers->execute(['store' => $storeId]);
        return [
            'orders' => $count($db, 'orders', $storeId),
            'products' => $count($db, 'products', $storeId, 'AND active = true'),
            'customers' => (int) $customers->fetchColumn(),
            'categories' => $count($db, 'categories', $storeId, 'AND active = true'),
        ];
    }

    private function store(?string $id = null): array
    {
        $query = $id
            ? $this->db()->prepare('SELECT * FROM stores WHERE id = :id LIMIT 1')
            : $this->db()->prepare('SELECT * FROM stores WHERE slug = :slug LIMIT 1');
        $query->execute($id ? ['id' => $id] : ['slug' => $this->env['DEFAULT_STORE_SLUG'] ?? 'lm-store-615d02ee']);
        $store = $query->fetch();
        if (!$store) throw new PDOException('Loja não encontrada.');
        return $store;
    }

    private function theme(string $storeId): array
    {
        $query = $this->db()->prepare('SELECT logo_url AS "logoUrl", banner_url AS "bannerUrl", facade_url AS "catalogBackgroundUrl", primary_color AS "primaryColor", secondary_color AS "secondaryColor", accent_color AS "accentColor", hero_eyebrow AS "heroEyebrow", hero_title AS "heroTitle", hero_highlight AS "heroHighlight", hero_description AS "heroDescription", cta_label AS "ctaLabel", hero_eyebrow_color AS "heroEyebrowColor", hero_title_color AS "heroTitleColor", hero_highlight_color AS "heroHighlightColor", hero_description_color AS "heroDescriptionColor", store_name_color AS "storeNameColor" FROM store_themes WHERE store_id = :store LIMIT 1');
        $query->execute(['store' => $storeId]);
        return $query->fetch() ?: [];
    }

    private function requireAdmin(): void
    {
        if (empty($_SESSION['user_id']) || empty($_SESSION['store_id'])) $this->redirect('/login');
    }

    private function csrfField(): string
    {
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
        return '<input type="hidden" name="_csrf" value="' . $this->e($_SESSION['csrf']) . '">';
    }

    private function verifyCsrf(): void
    {
        if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_POST['_csrf'] ?? ''))) {
            http_response_code(419);
            exit('Solicitação inválida. Atualize a página e tente novamente.');
        }
    }

    private function layout(string $title, callable $content): void
    {
        Layout::render($title, $content, fn (string $value): string => $this->e($value));
    }

    private function redirect(string $location): never
    {
        header('Location: ' . $location, true, 302);
        exit;
    }

    private function notFound(): never { $this->errorPage('Página não encontrada.', 404); }
    private function errorPage(string $message, int $status): never
    {
        http_response_code($status);
        $this->layout('Erro', function () use ($message): void { echo '<main class="auth-page"><section class="auth-card"><h1>Ops</h1><p>' . $this->e($message) . '</p><a href="/">Voltar ao início</a></section></main>'; });
        exit;
    }
    private function cents(string $value): ?int
    {
        $normalized = preg_replace('/[^0-9,.-]/', '', $value) ?? '';
        $lastComma = strrpos($normalized, ',');
        $lastDot = strrpos($normalized, '.');
        if ($lastComma !== false && $lastDot !== false) {
            $decimal = $lastComma > $lastDot ? ',' : '.';
            $thousands = $decimal === ',' ? '.' : ',';
            $normalized = str_replace($thousands, '', $normalized);
            $normalized = str_replace($decimal, '.', $normalized);
        } elseif ($lastComma !== false) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        }
        if ($normalized === '' || !is_numeric($normalized)) return null;
        return max(0, (int) round(((float) $normalized) * 100));
    }
    private function slug(string $name): string
    {
        $base = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: 'produto';
        $base = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $base));
        $base = trim($base, '-') ?: 'produto';
        return substr($base, 0, 145) . '-' . bin2hex(random_bytes(4));
    }
    private function stockTotal(mixed $stock): int
    {
        if (is_string($stock)) $stock = json_decode($stock, true);
        if (!is_array($stock)) return 0;
        return array_reduce($stock, static fn (int $total, mixed $value): int => $total + (is_numeric($value) ? (int) $value : 0), 0);
    }
    private function e(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private function money(int $cents): string { return number_format($cents / 100, 2, ',', '.'); }
}
