<?php
declare(strict_types=1);
require_once __DIR__ . '/discounts.php';

// Recalculate all charges from the business catalogue, never from submitted totals.
function invoiceOrder(array $request, array $catalog): array
{
    $items = $request['items'] ?? null;
    if (!is_array($items) || count($items) < 1 || count($items) > count($catalog) * 2) {
        throw new InvalidArgumentException('Add at least one item to your order.');
    }
    $products = array_column($catalog, null, 'id');
    $seen = [];
    $lines = [];
    $subtotalMinor = 0;
    foreach ($items as $item) {
        if (!is_array($item)) {
            throw new InvalidArgumentException('Invalid order item.');
        }
        $id = $item['id'] ?? '';
        $service = $item['service'] ?? '';
        $quantity = $item['quantity'] ?? 0;
        if (!is_string($id) || !is_string($service)) {
            throw new InvalidArgumentException('Invalid item or wash service.');
        }
        $key = $id . ':' . $service;
        if (!isset($products[$id]) || !in_array($service, ['fold', 'iron'], true)
            || !isset($products[$id][$service]) || isset($seen[$key])
            || !is_int($quantity) || $quantity < 1 || $quantity > 99) {
            throw new InvalidArgumentException('Invalid item, service, or quantity.');
        }
        $seen[$key] = true;
        $product = $products[$id];
        $lineTotal = round($product[$service] * 100) * $quantity / 100;
        $lines[] = [
            'name' => $product['name'],
            'category' => $product['category'],
            'unit' => ($product['unit'] ?? '') === 'load' ? 'load' : 'item',
            'service' => $service === 'fold' ? 'Wash & fold' : 'Wash & iron',
            'quantity' => $quantity,
            'unitPrice' => $product[$service],
            'total' => $lineTotal
        ];
        $subtotalMinor += (int)round($lineTotal * 100);
    }
    $fields = ['id' => 45, 'name' => 80, 'phone' => 30, 'location' => 200, 'date' => 10];
    $order = [];
    foreach ($fields as $field => $maxLength) {
        $value = $request[$field] ?? '';
        if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException('Please complete your customer and pickup details.');
        }
        $order[$field] = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value));
    }
    if (!preg_match('/^DC-[A-Z0-9-]+$/', $order['id'])
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $order['date'])
        || !in_array($request['paymentMethod'] ?? '', ['Cash on pickup', 'Mobile Money', 'Online payment'], true)) {
        throw new InvalidArgumentException('Invalid order reference or payment method.');
    }
    [$year, $month, $day] = array_map('intval', explode('-', $order['date']));
    if (!checkdate($month, $day, $year)) {
        throw new InvalidArgumentException('Choose a valid pickup date.');
    }
    return $order + orderTotals($subtotalMinor) + [
        'lines' => $lines,
        'paymentMethod' => $request['paymentMethod']
    ];
}

function invoicePdf(array $order, string $logoPath): string
{
    $source = imagecreatefrompng($logoPath);
    if ($source === false) {
        throw new RuntimeException('The invoice logo could not be loaded.');
    }
    $logo = imagecreatetruecolor(708, 354);
    imagefill($logo, 0, 0, imagecolorallocate($logo, 255, 255, 255));
    imagecopyresampled($logo, $source, 0, 0, 0, 0, 708, 354, imagesx($source), imagesy($source));
    ob_start();
    imagejpeg($logo, null, 95);
    $jpeg = ob_get_clean();
    imagedestroy($source);
    imagedestroy($logo);

    $escape = static function (string $text): string {
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $encoded ?: '');
    };
    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '',
        3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        5 => '<< /Type /XObject /Subtype /Image /Width 708 /Height 354 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($jpeg) . ">>\nstream\n" . $jpeg . "\nendstream"
    ];
    $chunks = array_chunk($order['lines'], 15);
    $pages = [];
    foreach ($chunks as $index => $lines) {
        $content = '';
        $text = static function (float $x, float $top, string $value, int $size = 10, bool $bold = false) use (&$content, $escape): void {
            $font = $bold ? 'F2' : 'F1';
            $y = 842 - $top;
            $content .= "BT /$font $size Tf 0.12 0.23 0.29 rg 1 0 0 1 $x $y Tm (" . $escape($value) . ") Tj ET\n";
        };
        $rect = static function (float $x, float $top, float $width, float $height, string $color) use (&$content): void {
            $y = 842 - $top - $height;
            $content .= "$color rg $x $y $width $height re f\n";
        };
        $content .= "q 170 0 0 85 38 722 cm /Logo Do Q\n";
        $text(362, 60, 'LAUNDRY INVOICE', 19, true);
        $text(362, 83, $order['id'], 11, true);
        $text(362, 103, 'Issued: ' . date('d M Y'));
        $text(42, 139, 'Madina Estate | 0508103264 / 0556333654', 10);
        $rect(40, 154, 515, 101, '0.92 0.97 0.99');
        $text(53, 174, 'CUSTOMER', 9, true);
        $customerLines = explode("\n", wordwrap($order['name'], 39, "\n", true));
        foreach ($customerLines as $offset => $part) {
            $text(53, 190 + $offset * 13, $part, 10, true);
        }
        $text(53, 231, $order['phone'], 10);
        $text(335, 174, 'PICKUP & PAYMENT', 9, true);
        $text(335, 194, 'Pickup: ' . $order['date']);
        $text(335, 214, $order['paymentMethod']);
        $paymentStatus = $order['paymentMethod'] === 'Cash on pickup'
            ? 'UNPAID - payment due at pickup'
            : 'UNPAID - payment not yet verified';
        if (($order['paymentStatus'] ?? '') === 'Paid') $paymentStatus = 'PAID - payment confirmed';
        if (($order['paymentStatus'] ?? '') === 'Refunded') $paymentStatus = 'REFUNDED';
        $text(335, 234, $paymentStatus, 9, true);
        $address = explode("\n", wordwrap('Pickup address: ' . $order['location'], 96, "\n", true));
        foreach ($address as $offset => $part) {
            $text(42, 273 + $offset * 12, $part, 9);
        }
        $rect(40, 313, 515, 25, '0.88 0.95 0.98');
        $text(49, 330, 'ITEM / CATEGORY', 9, true);
        $text(268, 330, 'SERVICE', 9, true);
        $text(364, 330, 'QTY', 9, true);
        $text(409, 330, 'RATE', 9, true);
        $text(485, 330, 'GHS TOTAL', 9, true);
        $top = 338;
        foreach ($lines as $line) {
            if ((($top - 338) / 21) % 2 === 0) {
                $rect(40, $top, 515, 21, '0.97 0.985 0.99');
            }
            $text(49, $top + 9, $line['name'], 9, true);
            $text(49, $top + 18, $line['category'], 6);
            $text(268, $top + 14, $line['service'], 9);
            $text(368, $top + 14, (string) $line['quantity'], 9);
            $text(409, $top + 14, number_format($line['unitPrice'], 2), 9);
            $text(488, $top + 14, number_format($line['total'], 2), 9);
            $top += 21;
        }
        if ($index === count($chunks) - 1) {
            $summaryOffset = 15;
            if (($order['discountAmount'] ?? 0) > 0) {
                $text(333, $top + 18, 'SUBTOTAL', 9);
                $text(442, $top + 18, 'GHS ' . number_format($order['subtotal'], 2), 9);
                $text(333, $top + 35, 'DISCOUNT (' . $order['discountRate'] . '%)', 9, true);
                $text(442, $top + 35, '- GHS ' . number_format($order['discountAmount'], 2), 9, true);
                $summaryOffset = 45;
            }
            $rect(320, $top + $summaryOffset, 235, 34, '0.88 0.95 0.98');
            $text(333, $top + $summaryOffset + 22, ($order['paymentStatus'] ?? '') === 'Paid' ? 'ORDER TOTAL' : 'TOTAL DUE', 11, true);
            $text(442, $top + $summaryOffset + 22, 'GHS ' . number_format($order['total'], 2), 11, true);
        } else {
            $text(42, $top + 35, 'Continued on the next page.', 9);
        }
        if ($order['paymentMethod'] === 'Mobile Money') {
            $text(42, 757, 'Mobile Money: call 0508103264 / 0556333654 for transfer details.', 9);
        }
        $text(42, 773, 'Order request: call us to confirm pickup. This invoice is not a payment receipt.', 9);
        $text(42, 789, 'Clean Clothes - Fresh Vibes - Always on Time', 9);
        $text(489, 807, 'Page ' . ($index + 1) . ' of ' . count($chunks), 8);

        $pageId = count($objects) + 1;
        $streamId = $pageId + 1;
        $pages[] = "$pageId 0 R";
        $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> /XObject << /Logo 5 0 R >> >> /Contents $streamId 0 R >>";
        $objects[$streamId] = '<< /Length ' . strlen($content) . ">>\nstream\n" . $content . 'endstream';
    }
    $objects[2] = '<< /Type /Pages /Count ' . count($pages) . ' /Kids [' . implode(' ', $pages) . '] >>';
    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0];
    foreach ($objects as $id => $object) {
        $offsets[$id] = strlen($pdf);
        $pdf .= "$id 0 obj\n$object\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach (array_slice($offsets, 1) as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    $pdf .= 'trailer << /Size ' . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
    return $pdf;
}
