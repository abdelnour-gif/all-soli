<?php
/** Reusable public components. */
defined('QP_APP') || exit;

function qp_public_scripts(array $extra = [], bool $qr = false): void
{
    $cfg = array_merge([
        'rest' => api_url(''),
        'home' => url('/'),
        'lang' => qp_lang(),
        'poll' => 1000 * max(5, min(10, qp()->settings->int('poll_interval_seconds') ?: 7)),
    ], $extra);
    echo '<script' . nonce_attr() . '>window.QP_CFG=' . json_enc($cfg) . ';</script>';
    if ($qr) {
        echo '<script src="' . esc_url(asset('js/vendor/qrcode.js')) . '"></script>';
    }
    echo '<script src="' . esc_url(asset('js/public.js')) . '"></script>';
}

function qp_name(array $row, string $field = 'name'): string
{
    $en = $row[$field . '_en'] ?? null;
    return qp_lang() === 'en' && $en ? $en : (string) ($row[$field . '_ar'] ?? '');
}

/** Provider card used on home + explore. $p comes from ProviderService::search(). */
function qp_provider_card(array $p): string
{
    $url = provider_url($p['slug']);
    $open = (int) $p['queue_open'] === 1;
    $cover = $p['cover_url'] ? ' style="background-image:url(' . esc_url($p['cover_url']) . ')"' : '';
    $cat = qp_lang() === 'en' && !empty($p['category_en']) ? $p['category_en'] : ($p['category_ar'] ?? '');
    $city = qp_lang() === 'en' && !empty($p['city_en']) ? $p['city_en'] : ($p['city_ar'] ?? '');
    ob_start(); ?>
    <a class="qp-pcard" href="<?php echo esc_url($url); ?>">
      <div class="qp-pcard-cover"<?php echo $cover; ?>>
        <?php if (!$p['cover_url']) echo qp_icon($p['category_icon'] ?? 'store', 46); ?>
        <span class="qp-badge<?php echo $open ? '' : ' qp-badge--closed'; ?>"><?php echo esc_html($open ? qp_t('open_now') : qp_t('closed_now')); ?></span>
      </div>
      <div class="qp-pcard-body">
        <div class="qp-pcard-title">
          <b><?php echo esc_html(qp_name($p)); ?></b>
          <?php if ((int) $p['rating_count'] > 0): ?>
            <span class="qp-stars"><?php echo qp_icon('star', 14); ?><?php echo esc_html(number_format((float) $p['rating_avg'], 1)); ?></span>
          <?php endif; ?>
        </div>
        <div class="qp-pcard-meta">
          <?php if ($city): ?><span><?php echo qp_icon('pin', 14) . esc_html($city); ?></span><?php endif; ?>
          <?php if ($open): ?><span><?php echo qp_icon('users', 14) . (int) $p['waiting'] . ' ' . esc_html(qp_t('waiting')); ?></span><?php endif; ?>
        </div>
        <div class="qp-pcard-foot">
          <?php if ($cat): ?><span class="qp-chip"><?php echo esc_html($cat); ?></span><?php endif; ?>
        </div>
      </div>
    </a>
    <?php
    return (string) ob_get_clean();
}

function qp_money($v, string $symbol): string
{
    $n = (float) $v;
    $s = floor($n) == $n ? number_format($n, 0, '.', ' ') : number_format($n, 2, '.', ' ');
    return $s . ' ' . $symbol;
}
