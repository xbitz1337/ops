<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 185px 55px 80px 55px; }
    body {
        font-family: -apple-system, 'Helvetica Neue', Helvetica, Arial, sans-serif;
        color: #1D1D1F;
        font-size: 11.5px;
        line-height: 1.6;
    }
    header {
        position: fixed;
        top: -170px;
        left: 0; right: 0;
        height: 155px;
    }
    header .logo-zeile {
        display: flex;
        align-items: center;
        border-bottom: 1px solid #E5E5E7;
        padding-bottom: 14px;
    }
    header img.logo { height: 105px; }
    header .firmenname-fallback { font-size: 32px; font-weight: 600; letter-spacing: -0.4px; color: #1D1D1F; }
    header .absender-zeile {
        font-size: 8px; color: #86868B; letter-spacing: 0.2px;
        margin-top: 6px;
    }
    footer {
        position: fixed;
        bottom: -60px;
        left: 0; right: 0;
        border-top: 1px solid #E5E5E7;
        padding-top: 10px;
        font-size: 8px;
        color: #86868B;
        text-align: center;
        line-height: 1.5;
    }
    .empfaenger-block {
        margin-top: 10px;
        margin-bottom: 40px;
        font-size: 11px;
        line-height: 1.5;
    }
    .datum-zeile {
        text-align: right;
        font-size: 10.5px;
        color: #4B4B4D;
        margin-bottom: 34px;
    }
    .betreff {
        font-size: 13px;
        font-weight: 600;
        margin-bottom: 20px;
        color: #1D1D1F;
    }
    .brieftext {
        white-space: pre-wrap;
        font-size: 11.5px;
        color: #1D1D1F;
    }
    .unterschrift-bereich {
        margin-top: 70px;
        display: flex;
        align-items: flex-end;
        gap: 40px;
    }
    .unterschrift-block { flex: 0 0 auto; max-width: 240px; }
    .unterschrift-bild { height: 55px; display: block; }
    .unterschrift-linie {
        width: 100%;
        border-top: 1px solid #1D1D1F;
        margin-top: 55px;
        padding-top: 6px;
        font-size: 10px;
        color: #4B4B4D;
        white-space: nowrap;
    }
    .unterschrift-linie.mit-bild { margin-top: 4px; }
    .ort-datum-inline {
        font-size: 11px;
        color: #1D1D1F;
        padding-bottom: 6px;
        white-space: nowrap;
    }
</style>
</head>
<body>
<header>
    <div class="logo-zeile">
        <?php if ($na_logo_base64): ?>
            <img class="logo" src="<?= $na_logo_base64 ?>">
        <?php else: ?>
            <span class="firmenname-fallback">NA Commerce Solutions GmbH</span>
        <?php endif; ?>
    </div>
    <div class="absender-zeile">
        NA Commerce Solutions GmbH · Am Bahndamm 7 · 28719 Bremen
    </div>
</header>
<footer>
    NA Commerce Solutions GmbH · Am Bahndamm 7, 28719 Bremen · HRB 41418 Bremen<br>
    Geschäftsführer: Artis Freibergs, Mhd Nour Shaaban
</footer>
<div class="empfaenger-block"><?= nl2br(htmlspecialchars($empfaenger)) ?></div>
<div class="datum-zeile"><?= htmlspecialchars($ort) ?>, <?= htmlspecialchars($datum_formatiert) ?></div>
<?php if (trim($betreff) !== ''): ?>
<div class="betreff"><?= htmlspecialchars($betreff) ?></div>
<?php endif; ?>
<div class="brieftext"><?= htmlspecialchars($brieftext) ?></div>
<div class="unterschrift-bereich">
    <?php if (!empty($ort_datum_inline)): ?>
    <div class="ort-datum-inline">
        <?= htmlspecialchars($ort) ?>, den <?= htmlspecialchars($datum_formatiert) ?>
    </div>
    <?php endif; ?>
    <?php foreach ($unterschrift_bloecke as $block): ?>
    <div class="unterschrift-block">
        <?php if (!empty($block['bild'])): ?>
            <img class="unterschrift-bild" src="<?= $block['bild'] ?>">
            <div class="unterschrift-linie mit-bild">
                <?= htmlspecialchars($block['name']) ?><?= $block['titel'] ? ' — ' . htmlspecialchars($block['titel']) : '' ?>
            </div>
        <?php else: ?>
            <div class="unterschrift-linie">
                <?= htmlspecialchars($block['name']) ?><?= $block['titel'] ? ' — ' . htmlspecialchars($block['titel']) : '' ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
</body>
</html>