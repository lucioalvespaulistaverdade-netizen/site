<?php
declare(strict_types=1);

date_default_timezone_set('America/Recife');

$pageTitle = 'Paulista de Verdade';

$feeds = [
    'https://falape.com/feed/',
    'https://g1.globo.com/rss/g1/pernambuco/',
    'https://blogdomagno.com.br/feed/',
    'https://jamildo.com/feed',
];

$highlightTags = ['PAULISTA', 'POLITICA'];
$cacheDir = __DIR__ . '/cache';
$cacheTtl = 900; // 15 min

if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0775, true);
}

function fetchUrl(string $url): ?string
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", [
                'User-Agent: Mozilla/5.0 (compatible; PaulistaDeVerdade/1.0)',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Connection: close',
            ]),
            'timeout' => 20,
            'follow_location' => 1,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ]
    ]);

    $content = @file_get_contents($url, false, $context);
    if ($content !== false && trim($content) !== '') {
        return $content;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; PaulistaDeVerdade/1.0)',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $result = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($result !== false && $httpCode >= 200 && $httpCode < 400) {
            return $result;
        }
    }

    return null;
}

function getCachedContent(string $key, callable $callback, string $cacheDir, int $ttl): ?string
{
    $cacheFile = $cacheDir . '/' . md5($key) . '.cache';

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $ttl)) {
        $cached = @file_get_contents($cacheFile);
        if ($cached !== false && trim($cached) !== '') {
            return $cached;
        }
    }

    $fresh = $callback();
    if ($fresh !== null && trim($fresh) !== '') {
        @file_put_contents($cacheFile, $fresh);
        return $fresh;
    }

    if (file_exists($cacheFile)) {
        $stale = @file_get_contents($cacheFile);
        if ($stale !== false && trim($stale) !== '') {
            return $stale;
        }
    }

    return null;
}

function getFeedXml(string $url, string $cacheDir, int $cacheTtl): ?string
{
    return getCachedContent(
        'feed:' . $url,
        fn() => fetchUrl($url),
        $cacheDir,
        $cacheTtl
    );
}

function getPageHtml(string $url, string $cacheDir, int $cacheTtl): ?string
{
    return getCachedContent(
        'page:' . $url,
        fn() => fetchUrl($url),
        $cacheDir,
        $cacheTtl
    );
}

function excerpt(string $html, int $limit = 170): string
{
    $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $text = preg_replace('/\s+/', ' ', $text);

    if (mb_strlen($text) <= $limit) {
        return $text;
    }

    return mb_substr($text, 0, $limit - 1) . '…';
}

function normalizeImageUrl(?string $url): ?string
{
    $url = trim((string)$url);

    if ($url === '') {
        return null;
    }

    if (str_starts_with($url, '//')) {
        $url = 'https:' . $url;
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }

    return $url;
}

function absolutizeUrl(string $url, string $base): ?string
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }

    if (filter_var($url, FILTER_VALIDATE_URL)) {
        return $url;
    }

    if (str_starts_with($url, '//')) {
        return 'https:' . $url;
    }

    $parts = parse_url($base);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return null;
    }

    $scheme = $parts['scheme'];
    $host = $parts['host'];

    if (str_starts_with($url, '/')) {
        return $scheme . '://' . $host . $url;
    }

    $path = $parts['path'] ?? '/';
    $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');
    if ($dir === '.') {
        $dir = '';
    }

    return $scheme . '://' . $host . $dir . '/' . ltrim($url, '/');
}

function extractImgFromHtml(string $html, string $baseUrl = ''): ?string
{
    if (trim($html) === '') {
        return null;
    }

    $patterns = [
        '/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i',
        '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i',
        '/<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']/i',
        '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']twitter:image["\']/i',
        '/<img[^>]+src=["\']([^"\']+)["\']/i',
        '/<img[^>]+data-src=["\']([^"\']+)["\']/i',
        '/<img[^>]+data-lazy-src=["\']([^"\']+)["\']/i',
        '/<img[^>]+data-original=["\']([^"\']+)["\']/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $html, $m)) {
            $img = $m[1] ?? '';
            $img = $baseUrl ? absolutizeUrl($img, $baseUrl) : normalizeImageUrl($img);
            $img = normalizeImageUrl($img);
            if ($img) {
                return $img;
            }
        }
    }

    return null;
}

function extractCategories(SimpleXMLElement $item): array
{
    $cats = [];

    if (isset($item->category)) {
        foreach ($item->category as $cat) {
            $value = trim((string)$cat);
            if ($value !== '') {
                $cats[] = mb_strtoupper($value);
            }
        }
    }

    return array_values(array_unique($cats));
}

function classifyHighlight(array $categories, string $title, string $description, array $highlightTags): array
{
    $haystack = mb_strtoupper($title . ' ' . $description . ' ' . implode(' ', $categories));
    $hits = [];

    foreach ($highlightTags as $tag) {
        if (mb_strpos($haystack, $tag) !== false) {
            $hits[] = $tag;
        }
    }

    return array_values(array_unique($hits));
}

function extractImageFromRss(SimpleXMLElement $item, array $namespaces): ?string
{
    if (isset($namespaces['media'])) {
        $media = $item->children($namespaces['media']);

        if (isset($media->content)) {
            foreach ($media->content as $node) {
                $attrs = $node->attributes();
                $img = normalizeImageUrl((string)($attrs['url'] ?? ''));
                if ($img) return $img;
            }
        }

        if (isset($media->thumbnail)) {
            foreach ($media->thumbnail as $node) {
                $attrs = $node->attributes();
                $img = normalizeImageUrl((string)($attrs['url'] ?? ''));
                if ($img) return $img;
            }
        }
    }

    if (isset($item->enclosure)) {
        foreach ($item->enclosure as $enc) {
            $attrs = $enc->attributes();
            $url = (string)($attrs['url'] ?? '');
            $type = (string)($attrs['type'] ?? '');

            if (
                $url !== '' &&
                (
                    stripos($type, 'image') !== false ||
                    preg_match('/\.(jpg|jpeg|png|gif|webp)(\?.*)?$/i', $url)
                )
            ) {
                $img = normalizeImageUrl($url);
                if ($img) return $img;
            }
        }
    }

    if (isset($namespaces['content'])) {
        $contentNs = $item->children($namespaces['content']);
        if (!empty($contentNs->encoded)) {
            $img = extractImgFromHtml((string)$contentNs->encoded);
            if ($img) return $img;
        }
    }

    if (!empty($item->description)) {
        $img = extractImgFromHtml((string)$item->description);
        if ($img) return $img;
    }

    foreach ($namespaces as $ns) {
        $children = $item->children($ns);
        foreach ($children as $child) {
            $attrs = $child->attributes();

            if (!empty($attrs['url'])) {
                $img = normalizeImageUrl((string)$attrs['url']);
                if ($img) return $img;
            }

            $childValue = (string)$child;
            $img = extractImgFromHtml($childValue);
            if ($img) return $img;
        }
    }

    return null;
}

function extractImageWithFallback(
    SimpleXMLElement $item,
    array $namespaces,
    string $link,
    string $cacheDir,
    int $cacheTtl
): ?string {
    $image = extractImageFromRss($item, $namespaces);
    if ($image) {
        return $image;
    }

    if ($link !== '' && $link !== '#') {
        $html = getPageHtml($link, $cacheDir, $cacheTtl);
        if ($html) {
            $image = extractImgFromHtml($html, $link);
            if ($image) {
                return $image;
            }
        }
    }

    return null;
}

function parseFeed(
    string $xmlString,
    array $highlightTags,
    string $cacheDir,
    int $cacheTtl
): array {
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlString, 'SimpleXMLElement', LIBXML_NOCDATA);

    if (!$xml) {
        return [];
    }

    $namespaces = $xml->getNamespaces(true);
    $items = [];

    if (isset($xml->channel->item)) {
        foreach ($xml->channel->item as $item) {
            $title = trim((string)($item->title ?? 'Sem título'));
            $link = trim((string)($item->link ?? '#'));
            $desc = (string)($item->description ?? '');
            $dateRaw = (string)($item->pubDate ?? '');
            $timestamp = $dateRaw ? strtotime($dateRaw) : time();
            $categories = extractCategories($item);
            $highlights = classifyHighlight($categories, $title, $desc, $highlightTags);

            $image = extractImageWithFallback($item, $namespaces, $link, $cacheDir, $cacheTtl);

            // só mantém matérias com imagem
            if (!$image) {
                continue;
            }

            $items[] = [
                'title' => $title,
                'link' => $link,
                'description' => excerpt($desc),
                'date' => $timestamp ?: time(),
                'date_human' => date('d/m/Y H:i', $timestamp ?: time()),
                'date_iso' => date('c', $timestamp ?: time()),
                'categories' => $categories,
                'highlights' => $highlights,
                'is_featured' => !empty($highlights),
                'image' => $image,
            ];
        }
    }

    return $items;
}

$allNews = [];

foreach ($feeds as $feedUrl) {
    $xmlString = getFeedXml($feedUrl, $cacheDir, $cacheTtl);
    if ($xmlString !== null) {
        $allNews = array_merge(
            $allNews,
            parseFeed($xmlString, $highlightTags, $cacheDir, $cacheTtl)
        );
    }
}

// remove duplicados por link
$unique = [];
foreach ($allNews as $news) {
    $key = md5($news['link']);
    if (!isset($unique[$key])) {
        $unique[$key] = $news;
    }
}
$allNews = array_values($unique);

// ordenação
usort($allNews, function ($a, $b) {
    $aScore = $a['is_featured'] ? 1 : 0;
    $bScore = $b['is_featured'] ? 1 : 0;

    if ($aScore !== $bScore) {
        return $bScore <=> $aScore;
    }

    return $b['date'] <=> $a['date'];
});

$carouselNews = array_slice($allNews, 0, 8);
$featuredNews = array_slice(array_filter($allNews, fn($n) => $n['is_featured']), 0, 3);
$latestNews = array_slice($allNews, 0, 24);
$totalNews = count($allNews);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <meta name="description" content="Portal de notícias com destaque para Paulista e Política.">
    <style>
        :root{
            --bg:#f8fbf8;
            --bg-soft:#eef7ee;
            --card:#ffffff;
            --line:#dbe7db;
            --text:#17321d;
            --muted:#5f7864;
            --green:#1f8f4e;
            --green-soft:#e8f6ed;
            --red:#c62828;
            --red-soft:#fdeaea;
            --gold:#cfae2b;
            --shadow:0 10px 28px rgba(19,58,30,.08);
            --radius:22px;
            --max:1240px;
        }

        *{box-sizing:border-box}
        html{scroll-behavior:smooth}
        body{
            margin:0;
            font-family:Arial, Helvetica, sans-serif;
            color:var(--text);
            background:
                radial-gradient(circle at top left, rgba(31,143,78,.08), transparent 26%),
                radial-gradient(circle at top right, rgba(198,40,40,.08), transparent 22%),
                linear-gradient(180deg, #ffffff 0%, var(--bg) 100%);
        }

        a{text-decoration:none;color:inherit}
        img{display:block;max-width:100%}

        .container{
            width:min(calc(100% - 28px), var(--max));
            margin:0 auto;
        }

        .topbar{
            position:sticky;
            top:0;
            z-index:50;
            background:rgba(255,255,255,.92);
            backdrop-filter:blur(12px);
            border-bottom:1px solid #e8efe8;
        }

        .topbar-inner{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:16px;
            padding:16px 0;
        }

        .logo{
            font-size:1.45rem;
            font-weight:900;
            letter-spacing:.3px;
            color:var(--green);
        }

        .logo span{
            color:var(--red);
        }

        .nav{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
        }

        .nav a{
            padding:10px 14px;
            border:1px solid var(--line);
            border-radius:999px;
            background:#fff;
            color:var(--text);
            font-size:.94rem;
            font-weight:700;
        }

        .hero{
            padding:30px 0 16px;
        }

        .hero-box{
            background:linear-gradient(135deg, #ffffff 0%, #f3fbf4 65%, #fff4f4 100%);
            border:1px solid #e7efe7;
            border-radius:30px;
            box-shadow:var(--shadow);
            overflow:hidden;
        }

        .hero-content{
            padding:30px;
        }

        .eyebrow{
            display:inline-flex;
            align-items:center;
            gap:8px;
            padding:8px 14px;
            border-radius:999px;
            background:var(--green-soft);
            color:var(--green);
            border:1px solid #cfe8d7;
            font-size:.88rem;
            margin-bottom:16px;
            font-weight:700;
        }

        h1{
            margin:0 0 12px;
            font-size:clamp(2rem, 4vw, 4rem);
            line-height:1.04;
            color:#13341d;
        }

        .hero p{
            margin:0;
            color:var(--muted);
            line-height:1.75;
            max-width:860px;
            font-size:1.03rem;
        }

        .stats{
            display:flex;
            gap:12px;
            flex-wrap:wrap;
            margin-top:22px;
        }

        .stat{
            min-width:170px;
            background:#fff;
            border:1px solid var(--line);
            border-radius:18px;
            padding:14px 16px;
        }

        .stat strong{
            display:block;
            font-size:1.2rem;
            color:var(--green);
            margin-bottom:4px;
        }

        .section{
            padding:20px 0;
        }

        .section-title{
            display:flex;
            align-items:end;
            justify-content:space-between;
            gap:16px;
            margin-bottom:16px;
        }

        .section-title h2{
            margin:0;
            font-size:1.55rem;
            color:#19351f;
        }

        .section-title p{
            margin:0;
            color:var(--muted);
        }

        .carousel{
            position:relative;
            border:1px solid #e4ebe4;
            border-radius:28px;
            overflow:hidden;
            background:#fff;
            box-shadow:var(--shadow);
        }

        .carousel-track{
            display:flex;
            overflow:auto;
            scroll-snap-type:x mandatory;
            scroll-behavior:smooth;
        }

        .carousel-track::-webkit-scrollbar{
            height:8px;
        }

        .carousel-track::-webkit-scrollbar-thumb{
            background:#cddccc;
            border-radius:999px;
        }

        .slide{
            min-width:100%;
            position:relative;
            scroll-snap-align:start;
        }

        .slide img{
            width:100%;
            height:500px;
            object-fit:cover;
            background:#f2f2f2;
        }

        .slide-overlay{
            position:absolute;
            inset:0;
            background:linear-gradient(180deg, rgba(0,0,0,.06) 0%, rgba(0,0,0,.60) 100%);
            display:flex;
            align-items:end;
        }

        .slide-content{
            padding:28px;
            width:100%;
            color:#fff;
        }

        .badges,
        .meta{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
            align-items:center;
            margin-bottom:12px;
        }

        .badge{
            display:inline-flex;
            align-items:center;
            padding:7px 10px;
            border-radius:999px;
            font-size:.78rem;
            font-weight:800;
            letter-spacing:.2px;
            border:1px solid transparent;
        }

        .badge-date{
            background:rgba(255,255,255,.14);
            color:#fff;
            border-color:rgba(255,255,255,.18);
        }

        .badge-hot{
            background:rgba(198,40,40,.16);
            color:#fff;
            border-color:rgba(255,255,255,.14);
        }

        .badge-tag{
            background:rgba(31,143,78,.18);
            color:#fff;
            border-color:rgba(255,255,255,.14);
        }

        .slide h3{
            margin:0 0 10px;
            font-size:clamp(1.25rem, 2vw, 2rem);
            line-height:1.2;
            max-width:900px;
        }

        .slide p{
            margin:0 0 16px;
            max-width:760px;
            line-height:1.65;
            color:#f2f2f2;
        }

        .btn{
            display:inline-flex;
            align-items:center;
            gap:8px;
            background:#fff;
            color:var(--green);
            padding:12px 18px;
            border-radius:999px;
            font-weight:800;
            box-shadow:0 6px 20px rgba(0,0,0,.08);
        }

        .featured-grid{
            display:grid;
            grid-template-columns:repeat(12, 1fr);
            gap:18px;
        }

        .featured-main{grid-column:span 7}
        .featured-side{
            grid-column:span 5;
            display:grid;
            gap:18px;
        }

        .card{
            background:var(--card);
            border:1px solid #e6eee6;
            border-radius:var(--radius);
            overflow:hidden;
            box-shadow:var(--shadow);
        }

        .card-media{
            width:100%;
            height:260px;
            object-fit:cover;
            background:#f0f0f0;
        }

        .card-body{
            padding:18px;
        }

        .meta{
            font-size:.84rem;
        }

        .card .badge-date{
            background:var(--green-soft);
            color:var(--green);
            border-color:#d6ebdb;
        }

        .card .badge-hot{
            background:var(--red-soft);
            color:var(--red);
            border-color:#f6cccc;
        }

        .card .badge-tag{
            background:#edf8f0;
            color:var(--green);
            border-color:#d7ebdc;
        }

        .card h3{
            margin:0 0 10px;
            font-size:1.2rem;
            line-height:1.35;
            color:#17321d;
        }

        .card p{
            margin:0 0 15px;
            color:var(--muted);
            line-height:1.7;
        }

        .read-more{
            display:inline-flex;
            align-items:center;
            gap:8px;
            color:var(--red);
            font-weight:800;
        }

        .news-grid{
            display:grid;
            grid-template-columns:repeat(3, 1fr);
            gap:18px;
        }

        .news-card .card-media{
            height:200px;
        }

        .news-card h3{
            font-size:1.06rem;
        }

        .empty{
            padding:22px;
            background:#fff;
            border:1px solid #f2cfcf;
            border-radius:18px;
            color:#8c2f2f;
        }

        .footer{
            margin-top:36px;
            border-top:1px solid #e5ece5;
            padding:24px 0 42px;
            color:var(--muted);
        }

        @media (max-width: 980px){
            .featured-main,
            .featured-side{
                grid-column:span 12;
            }

            .news-grid{
                grid-template-columns:repeat(2,1fr);
            }

            .slide img{
                height:390px;
            }
        }

        @media (max-width: 640px){
            .topbar-inner,
            .section-title{
                flex-direction:column;
                align-items:flex-start;
            }

            .hero-content{
                padding:22px;
            }

            .news-grid{
                grid-template-columns:1fr;
            }

            .slide img{
                height:300px;
            }

            .slide-content{
                padding:18px;
            }

            .card-media,
            .news-card .card-media{
                height:220px;
            }
        }
    </style>
</head>
<body>

<header class="topbar">
    <div class="container topbar-inner">
        <div class="logo"><a href='index.php'>Paulista <span>de Verdade</span></a></div>
        <nav class="nav">
            <a href="#carrossel">Carrossel</a>
            <a href="#destaques">Destaques</a>
            <a href="#ultimas">Últimas</a>
        </nav>
    </div>
</header>

 

<section class="section" id="carrossel">
    <div class="container">
        

        <?php if (!empty($carouselNews)): ?>
            <div class="carousel">
                <div class="carousel-track" id="carouselTrack">
                    <?php foreach ($carouselNews as $news): ?>
                        <article class="slide">
                            <img src="<?= htmlspecialchars($news['image']) ?>" alt="<?= htmlspecialchars($news['title']) ?>">
                            <div class="slide-overlay">
                                <div class="slide-content">
                                    <div class="badges">
                                        <span class="badge badge-date"><?= htmlspecialchars($news['date_human']) ?></span>
                                        <?php if ($news['is_featured']): ?>
                                            <span class="badge badge-hot">DESTAQUE</span>
                                        <?php endif; ?>
                                        <?php foreach ($news['highlights'] as $tag): ?>
                                            <span class="badge badge-tag"><?= htmlspecialchars($tag) ?></span>
                                        <?php endforeach; ?>
                                    </div>

                                    <h3><?= htmlspecialchars($news['title']) ?></h3>
                                    <p><?= htmlspecialchars($news['description']) ?></p>
                                    <a class="btn" href="<?= htmlspecialchars($news['link']) ?>" target="_blank" rel="noopener noreferrer">
                                        Ver matéria completa →
                                    </a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="empty">Nenhuma matéria com imagem foi encontrada no momento.</div>
        <?php endif; ?>
    </div>
</section>

<section class="section" id="destaques">
    <div class="container">
        <div class="section-title">
            <h2>Destaques</h2>
            <p>Maior relevância para assuntos ligados a Paulista e Política.</p>
        </div>

        <?php if (!empty($featuredNews)): ?>
            <div class="featured-grid">
                <?php $main = $featuredNews[0] ?? null; ?>
                <?php $side = array_slice($featuredNews, 1); ?>

                <?php if ($main): ?>
                    <article class="card featured-main">
                        <img class="card-media" src="<?= htmlspecialchars($main['image']) ?>" alt="<?= htmlspecialchars($main['title']) ?>">
                        <div class="card-body">
                            <div class="meta">
                                <span class="badge badge-hot">DESTAQUE</span>
                                <span class="badge badge-date"><?= htmlspecialchars($main['date_human']) ?></span>
                                <?php foreach ($main['highlights'] as $tag): ?>
                                    <span class="badge badge-tag"><?= htmlspecialchars($tag) ?></span>
                                <?php endforeach; ?>
                            </div>

                            <h3><?= htmlspecialchars($main['title']) ?></h3>
                            <p><?= htmlspecialchars($main['description']) ?></p>
                            <a class="read-more" href="<?= htmlspecialchars($main['link']) ?>" target="_blank" rel="noopener noreferrer">
                                Ler matéria completa →
                            </a>
                        </div>
                    </article>
                <?php endif; ?>

                <div class="featured-side">
                    <?php foreach ($side as $news): ?>
                        <article class="card">
                            <img class="card-media" src="<?= htmlspecialchars($news['image']) ?>" alt="<?= htmlspecialchars($news['title']) ?>">
                            <div class="card-body">
                                <div class="meta">
                                    <span class="badge badge-date"><?= htmlspecialchars($news['date_human']) ?></span>
                                    <?php foreach ($news['highlights'] as $tag): ?>
                                        <span class="badge badge-tag"><?= htmlspecialchars($tag) ?></span>
                                    <?php endforeach; ?>
                                </div>

                                <h3><?= htmlspecialchars($news['title']) ?></h3>
                                <p><?= htmlspecialchars($news['description']) ?></p>
                                <a class="read-more" href="<?= htmlspecialchars($news['link']) ?>" target="_blank" rel="noopener noreferrer">
                                    Ver matéria →
                                </a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="empty">Nenhuma matéria destacada com imagem foi encontrada.</div>
        <?php endif; ?>
    </div>
</section>

<section class="section" id="ultimas">
    <div class="container">
        <div class="section-title">
            <h2>Últimas notícias</h2>
            <p>Feed mesclado mostrando apenas postagens com imagem.</p>
        </div>

        <?php if (!empty($latestNews)): ?>
            <div class="news-grid">
                <?php foreach ($latestNews as $news): ?>
                    <article class="card news-card">
                        <img class="card-media" src="<?= htmlspecialchars($news['image']) ?>" alt="<?= htmlspecialchars($news['title']) ?>">
                        <div class="card-body">
                            <div class="meta">
                                <span class="badge badge-date"><?= htmlspecialchars($news['date_human']) ?></span>
                                <?php if ($news['is_featured']): ?>
                                    <span class="badge badge-hot">EM ALTA</span>
                                <?php endif; ?>
                                <?php foreach ($news['highlights'] as $tag): ?>
                                    <span class="badge badge-tag"><?= htmlspecialchars($tag) ?></span>
                                <?php endforeach; ?>
                            </div>

                            <h3><?= htmlspecialchars($news['title']) ?></h3>
                            <p><?= htmlspecialchars($news['description']) ?></p>
                            <a class="read-more" href="<?= htmlspecialchars($news['link']) ?>" target="_blank" rel="noopener noreferrer">
                                Ver matéria completa →
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty">
                Não foi possível carregar matérias com imagem agora. Verifique o acesso externo do servidor e a pasta de cache.
            </div>
        <?php endif; ?>
    </div>
</section>

<footer class="footer">
    <div class="container">
        <strong>Paulista de Verdade</strong><br>
        Portal levando a verdade para todos os cantos do Paulista.
    </div>
</footer>

<script>
(function () {
    const track = document.getElementById('carouselTrack');
    if (!track) return;

    const slides = track.children;
    if (!slides.length) return;

    let current = 0;

    function goToSlide(index) {
        current = index % slides.length;
        track.scrollTo({
            left: slides[current].offsetLeft,
            behavior: 'smooth'
        });
    }

    setInterval(() => {
        goToSlide(current + 1);
    }, 5000);
})();
</script>

</body>
</html>