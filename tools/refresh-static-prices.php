<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$catalog = json_decode(file_get_contents($root . '/assets/data/catalog.json'), true, 32, JSON_THROW_ON_ERROR);
$groups = [];
foreach ($catalog as $item) if (($item['unit'] ?? '') !== 'load') $groups[$item['category']][] = $item;
$escape = fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$html = '<div class="pricing-grid" id="itemPriceGroups">';
$parts = array_chunk($groups, (int)ceil(count($groups) / 2), true);
foreach ($parts as $part) {
    $html .= '<div class="pricing-column">';
    foreach ($part as $category => $items) {
        $html .= '<article class="price-card"><table><caption>' . $escape($category) . '</caption><thead><tr><th scope="col">Item</th><th scope="col">Wash &amp; fold</th></tr></thead><tbody>';
        foreach ($items as $item) {
            $html .= '<tr data-price-item="' . $escape($item['id']) . '"><th scope="row">' . $escape($item['name']) . '</th><td>';
            $html .= $item['fold'] === null ? '<a href="tel:0508103264">Contact us</a>' : '<button type="button" class="add-price" data-item="' . $escape($item['id']) . '" data-service="fold" aria-label="' . $escape('Add ' . $item['name'] . ', ' . $category . ', wash and fold') . '">GH₵ ' . $escape(number_format($item['fold'], 2)) . '<span>+ Add</span></button>';
            $html .= "</td></tr>\n";
        }
        $html .= "</tbody></table></article>\n";
    }
    $html .= '</div>';
}
$html .= "</div>\n        ";
$path = $root . '/index.html';
$page = file_get_contents($path);
$start = strpos($page, '<div class="pricing-grid" id="itemPriceGroups">');
$end = strpos($page, '<div class="pricing-contact">', $start);
if ($start === false || $end === false) throw new RuntimeException('Price section not found.');
file_put_contents($path, substr($page, 0, $start) . $html . substr($page, $end));
echo "Updated static customer price tables.\n";
