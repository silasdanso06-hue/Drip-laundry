<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/owner.php';
require_once __DIR__ . '/lib/catalog.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
ownerSession();
$error = '';
$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

if (isset($_GET['setup']) && ownerSetupAllowed($_GET['setup'])) {
    session_regenerate_id(true);
    $_SESSION['setup_token'] = $_GET['setup'];
    header('Location: admin.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!ownerCsrfValid($_POST['csrf'] ?? null)) {
            http_response_code(403);
            throw new InvalidArgumentException('Your form expired. Reload this page and try again.');
        }
        $action = $_POST['action'] ?? '';
        if ($action === 'setup') {
            $password = $_POST['password'] ?? '';
            if (!is_string($password) || $password !== ($_POST['confirm'] ?? null)) {
                throw new InvalidArgumentException('The two passwords must match.');
            }
            ownerCreate($password, $_SESSION['setup_token'] ?? '');
            ownerAuthenticate();
            $_SESSION['message'] = 'Your owner account is ready. Only someone with your password can change prices.';
        } elseif ($action === 'login') {
            $password = $_POST['password'] ?? '';
            if (!is_string($password) || !ownerLogin($password, $_SERVER['REMOTE_ADDR'] ?? 'unknown')) {
                http_response_code(401);
                throw new InvalidArgumentException('The password is incorrect.');
            }
            ownerAuthenticate();
        } elseif ($action === 'save') {
            if (!ownerSignedIn()) {
                http_response_code(403);
                throw new InvalidArgumentException('Please sign in as the owner before saving items or prices.');
            }
            $prices = $_POST['prices'] ?? null;
            $version = $_POST['version'] ?? null;
            if (!is_array($prices) || !is_string($version)) throw new InvalidArgumentException('The submitted price list is invalid.');
            updateCatalog($prices, $version);
            $_SESSION['message'] = 'Items and prices saved. Customers now see your updated price list.';
        } elseif ($action === 'logout') {
            $_SESSION = [];
            session_regenerate_id(true);
        } else {
            http_response_code(400);
            throw new InvalidArgumentException('Unknown action.');
        }
        header('Location: admin.php', true, 303);
        exit;
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        http_response_code(500);
        $error = 'The change could not be saved. Please try again.';
    }
}

$signedIn = ownerSignedIn();
if ($signedIn) $_SESSION['last_active'] = time();
$configured = ownerRead('owner') !== null;
$canSetup = ownerSetupAllowed($_SESSION['setup_token'] ?? null);
$snapshot = $signedIn ? catalogSnapshot() : null;
$editorValues = $error && $snapshot && ($_POST['version'] ?? '') === $snapshot['version'] && is_array($_POST['prices'] ?? null) ? $_POST['prices'] : [];
$customerUpdate = '';
if ($snapshot !== null) {
    $customerUpdate = "Hello from Dripclean Laundry! Here is our current price list.\nAll prices are in Ghana cedis (GHS).\n";
    $lastCategory = null;
    foreach ($snapshot['catalog'] as $item) {
        $categoryName = $item['category'] ?? 'Uncategorized';
        if ($lastCategory !== $categoryName) {
            $lastCategory = $categoryName;
            $customerUpdate .= "\n" . $lastCategory . "\n";
        }
        $formatPrice = static fn($price): string => $price === null ? 'Contact us' : 'GHS ' . number_format((float) $price, 2);
        $foldPrice = $item['fold'] ?? null;
        $customerUpdate .= ($item['name'] ?? 'Item') . ': Wash & fold ' . $formatPrice($foldPrice) . (($item['unit'] ?? '') === 'load' ? ' per load' : ' per item') . "\n";
    }
    $customerUpdate .= "\nCall 0508103264 or 0556333654 to confirm prices and arrange pickup.";
}
function escape($value): string
{
    return htmlspecialchars(is_string($value) ? $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function editorValue(array $item, string $field, string $fallback): string
{
    global $editorValues;
    $submitted = $editorValues[$item['id']][$field] ?? null;
    return is_string($submitted) ? $submitted : $fallback;
}
?>
<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>Owner item and price editor | Dripclean Laundry</title>
        <link rel="stylesheet" href="assets/css/admin.css?v=20261003-editor-layout">
    </head>
    <body>
        <header class="owner-header">
            <a href="index.html#home"><img src="assets/images/dripclean-logo.png" width="1774" height="887" alt="Dripclean Laundry"></a>
            <a href="index.html#pricing">View customer prices ↗</a>
            <?php if ($signedIn): ?>
                <a href="sms.php">Send customer SMS</a>
                <a href="admin-orders.php">Manage orders</a>
                <a href="admin-payments.php">Online payments</a>
                <a href="admin-business.php">Business settings</a>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
                    <input type="hidden" name="action" value="logout">
                    <button class="secondary" type="submit">Sign out</button>
                </form>
            <?php endif; ?>
        </header>
        <main>
            <p class="eyebrow">Private owner area</p>
            <h1><?= $signedIn ? 'Edit your items and prices.' : ($canSetup ? 'Set your owner password.' : 'Owner sign in.') ?></h1>
            <?php if ($error): ?><p class="notice error" role="alert"><?= escape($error) ?></p><?php endif; ?>
            <?php if ($message): ?><p class="notice success" role="status"><?= escape($message) ?></p><?php endif; ?>
            <?php if ($signedIn): ?>
                <p class="intro">Edit item names, categories and prices below. Choose an existing category or type a new one. Prices are in Ghana cedis (GH₵); leave a price blank to show “Contact us”. Weight-package prices are per load.</p>
                <form method="post" class="price-editor">
                    <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="version" value="<?= escape($snapshot['version']) ?>">
                    <datalist id="catalog-categories">
                        <?php foreach (array_unique(array_column($snapshot['catalog'], 'category')) as $option): ?><option value="<?= escape($option) ?>"></option><?php endforeach; ?>
                    </datalist>
                    <?php $category = null; foreach ($snapshot['catalog'] as $item): ?>
                        <?php if ($category !== $item['category']): ?>
                            <?php if ($category !== null): ?></tbody></table></section><?php endif; ?>
                            <?php $category = $item['category']; ?>
                            <section class="category">
                                <table>
                                    <caption><?= escape($category) ?></caption>
                                    <colgroup><col class="item-column"><col class="category-column"><col class="price-column"></colgroup>
                                    <thead><tr><th scope="col">Item name</th><th scope="col">Category</th><th scope="col">Wash &amp; fold (GH₵)</th></tr></thead>
                                    <tbody>
                        <?php endif; ?>
                        <tr>
                            <th scope="row" class="item-details">
                                <label class="editor-field-label" for="name-<?= escape($item['id']) ?>">Item name</label>
                                <input id="name-<?= escape($item['id']) ?>" name="prices[<?= escape($item['id']) ?>][name]" type="text" value="<?= escape(editorValue($item, 'name', $item['name'])) ?>" maxlength="80" required>
                            </th>
                            <td class="item-category">
                                <label class="editor-field-label" for="category-<?= escape($item['id']) ?>">Category</label>
                                <input id="category-<?= escape($item['id']) ?>" name="prices[<?= escape($item['id']) ?>][category]" type="text" value="<?= escape(editorValue($item, 'category', $item['category'])) ?>" maxlength="60" list="catalog-categories" required>
                            </td>
                            <?php foreach (['fold'] as $service): ?>
                                <?php
                                $value = ($item[$service] ?? null) === null ? '' : rtrim(rtrim(number_format((float) $item[$service], 2, '.', ''), '0'), '.');
                                $value = editorValue($item, $service, $value);
                                ?>
                                <td class="item-price">
                                    <label class="editor-field-label" for="price-<?= escape($item['id']) ?>-<?= $service ?>">Wash &amp; fold (GH₵)</label>
                                    <input
                                        id="price-<?= escape($item['id']) ?>-<?= $service ?>"
                                        type="number"
                                        name="prices[<?= escape($item['id']) ?>][<?= $service ?>]"
                                        value="<?= escape($value) ?>"
                                        min="0"
                                        max="100000"
                                        step="0.01"
                                        inputmode="decimal"
                                        placeholder="Contact us"
                                        aria-label="<?= escape($item['name'] . ', ' . $category . ', wash and ' . $service . ' price in Ghana cedis') ?>"
                                    >
                                    <?php if (($item['unit'] ?? '') === 'load'): ?><small>Per load</small><?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody></table></section>
                    <div class="save-bar">
                        <span>Changes become visible to customers when you save.</span>
                        <button type="submit">Save items &amp; prices</button>
                    </div>
                </form>
                <section class="customer-update" aria-labelledby="customer-update-title">
                    <h2 id="customer-update-title">Update your customers</h2>
                    <p>Share your saved price list. Save any price changes above first, then review the message below.</p>
                    <label for="customer-message">Customer message</label>
                    <textarea id="customer-message" rows="10" readonly><?= escape($customerUpdate) ?></textarea>
                    <div class="share-actions">
                        <button type="button" id="copy-prices" hidden>Copy message</button>
                        <a class="share-button" href="https://wa.me/?text=<?= escape(rawurlencode($customerUpdate)) ?>" target="_blank" rel="noopener noreferrer">Open in WhatsApp</a>
                    </div>
                    <p id="share-status" role="status">Choose your customers in WhatsApp and review before sending. Nothing is sent automatically.</p>
                </section>
            <?php elseif ($canSetup || $configured): ?>
                <form method="post" class="login-card">
                    <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
                    <input type="hidden" name="action" value="<?= $canSetup ? 'setup' : 'login' ?>">
                    <label for="password"><?= $canSetup ? 'Choose a password (6–7 characters)' : 'Owner password' ?></label>
                    <input id="password" name="password" type="password" autocomplete="<?= $canSetup ? 'new-password' : 'current-password' ?>" <?= $canSetup ? 'minlength="6"' : '' ?> maxlength="<?= $canSetup ? 7 : 72 ?>" required>
                    <?php if ($canSetup): ?>
                        <label for="confirm">Confirm password</label>
                        <input id="confirm" name="confirm" type="password" autocomplete="new-password" minlength="6" maxlength="<?= $canSetup ? 7 : 72 ?>" required>
                    <?php endif; ?>
                    <button type="submit"><?= $canSetup ? 'Create owner account' : 'Sign in' ?></button>
                    <p>Only the owner can edit prices. Customers can browse and order without this password.</p>
                </form>
            <?php else: ?>
                <div class="login-card">
                    <p>Owner access has not been set up yet. Use your private one-time setup link to create your password.</p>
                    <p>Customers can still browse the website and price list.</p>
                </div>
            <?php endif; ?>
        </main>
        <script src="assets/js/admin.js" defer></script>
    </body>
</html>
