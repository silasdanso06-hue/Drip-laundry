<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
libxml_use_internal_errors(true);
$document = new DOMDocument();
$document->loadHTMLFile(dirname(__DIR__) . '/index.html');
function exportElement(DOMElement $element): array
{
    $attributes = [];
    foreach ($element->attributes as $attribute) {
        $attributes[$attribute->name] = $attribute->value;
    }
    $children = [];
    foreach ($element->childNodes as $child) {
        if ($child instanceof DOMElement) $children[] = exportElement($child);
    }
    return ['tag' => $element->tagName, 'attributes' => $attributes, 'children' => $children];
}
file_put_contents(dirname(__DIR__) . '/tmp/site-dom.json', json_encode(exportElement($document->documentElement), JSON_THROW_ON_ERROR));
echo "Exported the actual page structure for navigation tests.\n";
