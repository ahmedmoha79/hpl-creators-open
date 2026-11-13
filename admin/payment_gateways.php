<?php
require_once __DIR__ . '/../config.php';
$user = require_admin();
$pageTitle = 'Payment gateways';

// Keys handled on this page only (sensitive credentials live in settings).
$gatewayKeys = [
    'gateway_active_providers',
    'gateway_mode',
    'gateway_webhook_secret',
    'mtn_enabled',
    'mtn_client_id',
    'mtn_client_secret',
    'mtn_collection_key',
    'mtn_collection_phone',
    'airtel_enabled',
    'airtel_client_id',
    'airtel_client_secret',
    'airtel_collection_key',
    'airtel_collection_phone',
    'zamtel_enabled',
    'zamtel_merchant_id',
    'zamtel_api_key',
    'paynow_enabled',
    'paynow_integration_key',
    'paynow_merchant_id',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($gatewayKeys as $k) {
        if (in_array($k, secret_setting_keys(), true)) {
            $sv = secret_setting_posted($k);
            if ($sv !== null) {
                set_setting($k, $sv);
            }
        } elseif (array_key_exists($k, $_POST)) {
            set_setting($k, trim((string)$_POST[$k]));
        }
    }
    // Checkboxes: only set when explicitly passed.
    foreach (['mtn_enabled', 'airtel_enabled', 'zamtel_enabled', 'paynow_enabled'] as $c) {
        if (!array_key_exists($c, $_POST)) {
            set_setting($c, '0');
        }
    }
    flash('success', 'Payment gateway settings saved.');
    redirect('admin/payment_gateways.php');
}

require __DIR__ . '/../includes/admin_header.php';

$isConfigured = fn(string $enabled, array $credKeys): bool => setting($enabled, '0') === '1' && array_reduce(
    $credKeys,
    fn(bool $carry, string $k) => $carry && trim((string)setting($k, '')) !== '',
    true
);
?>

<h1>Payment gateways</h1>
<p class="muted">Save your mobile money API details here for later. Automatic payouts are not switched on yet: for now, pay each creator yourself and mark the request as paid in Withdrawals.</p>

<form method="post">
    <?= csrf_field() ?>

    <div class="panel">
        <div class="panel__head"><h3>Environment</h3></div>
        <div class="panel__body">
            <div class="grid-2">
                <div class="field">
                    <label class="label">Mode</label>
                    <select class="select" name="gateway_mode">
                        <option value="sandbox" <?= setting('gateway_mode', 'sandbox') === 'sandbox' ? 'selected' : '' ?>>Sandbox (testing)</option>
                        <option value="live" <?= setting('gateway_mode', 'sandbox') === 'live' ? 'selected' : '' ?>>Live (real money)</option>
                    </select>
                    <div class="field__hint">Use <strong>sandbox</strong> until you've verified a full payout end to end.</div>
                </div>
                <div class="field">
                    <label class="label" for="gateway_webhook_secret">Webhook secret</label>
                    <?= secret_setting_input('gateway_webhook_secret') ?>
                    <div class="field__hint">Any string works; keep it random and secret.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="grid-2">
        <?php $gateways = [
            'mtn'    => ['label' => 'MTN Mobile Money', 'brand' => '#ffcc00', 'cred' => ['mtn_client_id', 'mtn_client_secret']],
            'airtel' => ['label' => 'Airtel Money', 'brand' => '#e40000', 'cred' => ['airtel_client_id', 'airtel_client_secret']],
            'zamtel' => ['label' => 'Zamtel Kwacha', 'brand' => '#d50032', 'cred' => ['zamtel_merchant_id', 'zamtel_api_key']],
            'paynow' => ['label' => 'Paynow (multi-provider)', 'brand' => '#00a650', 'cred' => ['paynow_integration_key', 'paynow_merchant_id']],
        ]; ?>
        <?php foreach ($gateways as $gkey => $g):
            $enabled = setting($gkey . '_enabled', '0') === '1';
            $ready = $enabled && $isConfigured($gkey . '_enabled', $g['cred']);
            $status = $ready ? 'ok' : ($enabled ? 'warn' : 'muted');
            $statusLabel = $ready ? 'Configured' : ($enabled ? 'Missing keys' : 'Disabled');
        ?>
            <div class="panel gateway-card">
                <div class="panel__head">
                    <h3 style="display:flex;align-items:center;gap:8px"><span style="width:12px;height:12px;border-radius:50%;background:<?= e($g['brand']) ?>;display:inline-block"></span><?= e($g['label']) ?></h3>
                    <span class="badge <?= $status ?>"><?= e($statusLabel) ?></span>
                </div>
                <div class="panel__body">
                    <div class="field">
                        <label class="label" style="display:flex;align-items:center;gap:10px">
                            <input type="checkbox" name="<?= $gkey ?>_enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
                            Enable this gateway
                        </label>
                    </div>
                    <?php if ($gkey === 'mtn'): ?>
                        <div class="field"><label class="label">Client ID</label><input class="input" name="mtn_client_id" value="<?= e(setting('mtn_client_id', '')) ?>"></div>
                        <div class="field"><label class="label">Client secret</label><?= secret_setting_input('mtn_client_secret') ?></div>
                        <div class="field"><label class="label">Collection API key</label><?= secret_setting_input('mtn_collection_key') ?></div>
                        <div class="field"><label class="label">Collection phone</label><input class="input" name="mtn_collection_phone" value="<?= e(setting('mtn_collection_phone', '')) ?>" placeholder="+260 97..."></div>
                    <?php elseif ($gkey === 'airtel'): ?>
                        <div class="field"><label class="label">Client ID</label><input class="input" name="airtel_client_id" value="<?= e(setting('airtel_client_id', '')) ?>"></div>
                        <div class="field"><label class="label">Client secret</label><?= secret_setting_input('airtel_client_secret') ?></div>
                        <div class="field"><label class="label">Collection key</label><?= secret_setting_input('airtel_collection_key') ?></div>
                        <div class="field"><label class="label">Collection phone</label><input class="input" name="airtel_collection_phone" value="<?= e(setting('airtel_collection_phone', '')) ?>" placeholder="+260 96..."></div>
                    <?php elseif ($gkey === 'zamtel'): ?>
                        <div class="field"><label class="label">Merchant ID</label><input class="input" name="zamtel_merchant_id" value="<?= e(setting('zamtel_merchant_id', '')) ?>"></div>
                        <div class="field"><label class="label">API key</label><?= secret_setting_input('zamtel_api_key') ?></div>
                    <?php elseif ($gkey === 'paynow'): ?>
                        <div class="field"><label class="label">Integration key</label><?= secret_setting_input('paynow_integration_key') ?></div>
                        <div class="field"><label class="label">Merchant ID</label><input class="input" name="paynow_merchant_id" value="<?= e(setting('paynow_merchant_id', '')) ?>"></div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="panel">
        <div class="panel__head"><h3>How payments are sent</h3></div>
        <div class="panel__body">
            <ol class="small" style="line-height:1.9;padding-left:20px;margin:0">
                <li>A creator requests a payout and chooses their mobile money number.</li>
                <li>In <strong>Withdrawals</strong>, pick the provider and click <strong>"Push payment"</strong>.</li>
                <li>The gateway API asks the provider to send money to the creator's number.</li>
                <li>Provider confirms via webhook &rarr; the withdrawal is marked <strong>paid</strong> automatically with the transaction reference.</li>
            </ol>
            <p class="small muted mb-0 mt-2">While gateways are in <strong>sandbox</strong> or unconfigured, the manual <strong>"Record payment"</strong> flow stays available so you can keep operating.</p>
        </div>
    </div>

    <button class="btn btn--primary" type="submit">Save gateway settings</button>
</form>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>