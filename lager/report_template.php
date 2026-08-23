<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 120px 45px 70px 45px; }

    body {
        font-family: 'Helvetica', Arial, sans-serif;
        color: #1D1D1F;
        font-size: 11px;
        line-height: 1.5;
    }

    header {
        position: fixed;
        top: -100px;
        left: 0; right: 0;
        height: 80px;
        padding-bottom: 0;
    }
    header .header-inner {
        height: 80px;
        border-bottom: 2px solid <?= $akzent ?>;
        position: relative;
    }
    header .header-inner::after {
        content: '';
        display: block;
        clear: both;
    }
    header .logos { float: left; padding-top: 4px; }
    header .logos img { height: 62px; max-width: 220px; width: auto; object-fit: contain; margin-right: 16px; vertical-align: middle; }
    header .logos .trenner {
        display: inline-block; height: 46px; width: 1px;
        background: #D2D2D7; margin-right: 16px; vertical-align: middle;
    }
    header .titel-block { float: right; text-align: right; padding-top: 22px; }
    header .titel-block h1 { font-size: 16px; margin: 0; color: #1D1D1F; }
    header .titel-block .sub { font-size: 9px; color: #86868B; }

    footer {
        position: fixed;
        bottom: -50px;
        left: 0; right: 0;
        height: 40px;
        border-top: 1px solid #EADFD3;
        padding-top: 6px;
        font-size: 8px;
        color: #9C8A7C;
        text-align: center;
    }

    h2 {
        font-size: 13px;
        color: <?= $akzent ?>;
        border-bottom: 1px solid <?= $akzent_hell ?>;
        padding-bottom: 4px;
        margin-top: 24px;
        margin-bottom: 10px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .summary-boxes { width: 100%; margin-bottom: 10px; display: table; table-layout: fixed; border-spacing: 6px 0; }
    .summary-box {
        display: table-cell;
        width: 25%;
        background: <?= $akzent_bg ?>;
        border: 1px solid <?= $akzent_hell ?>;
        border-radius: 6px;
        padding: 10px;
        text-align: center;
    }
    .summary-box .label { font-size: 8px; color: #86868B; text-transform: uppercase; }
    .summary-box .value { font-size: 16px; color: <?= $akzent ?>; font-weight: bold; margin-top: 3px; }

    table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
    th {
        background: <?= $akzent_hell ?>;
        color: #1D1D1F;
        font-size: 9px;
        text-transform: uppercase;
        text-align: left;
        padding: 6px 8px;
        border-bottom: 1px solid <?= $akzent_hell ?>;
    }
    td {
        padding: 5px 8px;
        border-bottom: 1px solid <?= $akzent_bg ?>;
        font-size: 10px;
    }
    tr.kategorie-header td {
        background: <?= $akzent_bg ?>;
        font-weight: bold;
        color: <?= $akzent ?>;
    }
    .text-right { text-align: right; }
    .total-row td {
        font-weight: bold;
        border-top: 2px solid <?= $akzent ?>;
        background: <?= $akzent_bg ?>;
    }
</style>
</head>
<body>

<header>
    <div class="header-inner">
        <div class="logos">
            <?php if ($na_logo_base64): ?>
                <img src="<?= $na_logo_base64 ?>">
            <?php else: ?>
                <span style="font-size:15px; font-weight:600; color:#1D1D1F;">NA Commerce Solutions</span>
            <?php endif; ?>

            <?php if ($cozycore_logo_base64): ?>
                <span class="trenner"></span>
                <img src="<?= $cozycore_logo_base64 ?>">
            <?php elseif ($enthaelt_waermflasche): ?>
                <span class="trenner"></span>
                <span style="font-size:15px; font-weight:600; color:#A85C32;">CozyCore</span>
            <?php endif; ?>
        </div>
        <div class="titel-block">
            <h1>Lagerreport</h1>
            <div class="sub">Zeitraum <?= $zeitraum_von_formatiert ?> – <?= $zeitraum_bis_formatiert ?> · erstellt am <?= $erstellt_formatiert ?></div>
        </div>
    </div>
</header>

<footer>
    NA Commerce Solutions GmbH · Am Bahndamm 7, 28719 Bremen · HRB 41418 · Lagerreport automatisch generiert
</footer>

<div class="summary-boxes">
    <div class="summary-box">
        <div class="label">EK-Wert Bestand</div>
        <div class="value"><?= number_format($gesamt_ek_wert, 2, ',', '.') ?> €</div>
    </div>
    <div class="summary-box">
        <div class="label">Verkäufe im Monat</div>
        <div class="value"><?= array_sum($verkaeufe_je_kanal) ?> Stk</div>
    </div>
    <div class="summary-box">
        <div class="label">Retouren</div>
        <div class="value"><?= $retouren_anzahl ?> Stk</div>
    </div>
    <div class="summary-box">
        <div class="label">Eigenbedarf</div>
        <div class="value"><?= $eigenbedarf_anzahl ?> Stk</div>
    </div>
</div>

<h2>Bestand zum Stichtag</h2>
<table>
    <thead>
        <tr>
            <th>Produkt</th>
            <th class="text-right">Bestand (Stk)</th>
            <th class="text-right">EK-Preis netto</th>
            <th class="text-right">EK-Wert netto</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($nach_kategorie as $kategorie => $produkte): ?>
        <tr class="kategorie-header"><td colspan="4"><?= htmlspecialchars($kategorie) ?></td></tr>
        <?php foreach ($produkte as $p):
            $wert = $p['bestand_stueck'] * $p['ek_preis_netto'];
        ?>
        <tr>
            <td><?= htmlspecialchars($p['name']) ?></td>
            <td class="text-right"><?= $p['bestand_stueck'] ?></td>
            <td class="text-right"><?= number_format($p['ek_preis_netto'], 2, ',', '.') ?> €</td>
            <td class="text-right"><?= number_format($wert, 2, ',', '.') ?> €</td>
        </tr>
        <?php endforeach; ?>
    <?php endforeach; ?>
    <tr class="total-row">
        <td colspan="3">Gesamt</td>
        <td class="text-right"><?= number_format($gesamt_ek_wert, 2, ',', '.') ?> €</td>
    </tr>
    </tbody>
</table>

<?php if (!empty($wareneinsatz_zeilen)): ?>
<h2>Wareneinsatz &amp; Rohertrag (Schätzung)</h2>
<table>
    <thead>
        <tr>
            <th>Produkt</th>
            <th class="text-right">Verkauft (Stk)</th>
            <th class="text-right">Umsatz netto</th>
            <th class="text-right">Wareneinsatz</th>
            <th class="text-right">Rohertrag</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($wareneinsatz_zeilen as $w): ?>
        <tr>
            <td><?= htmlspecialchars($w['name']) ?></td>
            <td class="text-right"><?= $w['menge_verkauft'] ?></td>
            <td class="text-right"><?= number_format($w['umsatz_netto'], 2, ',', '.') ?> €</td>
            <td class="text-right"><?= number_format($w['wareneinsatz'], 2, ',', '.') ?> €</td>
            <td class="text-right"><?= number_format($w['rohertrag_netto'], 2, ',', '.') ?> €</td>
        </tr>
    <?php endforeach; ?>
    <tr class="total-row">
        <td colspan="2">Gesamt</td>
        <td class="text-right"><?= number_format($umsatz_gesamt_netto, 2, ',', '.') ?> €</td>
        <td class="text-right"><?= number_format($wareneinsatz_gesamt, 2, ',', '.') ?> €</td>
        <td class="text-right"><?= number_format($rohertrag_gesamt, 2, ',', '.') ?> €</td>
    </tr>
    </tbody>
</table>
<p style="font-size: 8px; color: #86868B; margin-top: 4px;">
    Näherungsrechnung auf Basis des gewichteten Durchschnitts-EK zum Ende des Zeitraums — keine exakte
    Einzelverkauf-Zuordnung (FIFO), für die Praxis aber ausreichend genau. Umsatz und Rohertrag netto (ohne 19% USt).
</p>
<?php endif; ?>

<h2>Verkäufe je Kanal (<?= $zeitraum_von_formatiert ?> – <?= $zeitraum_bis_formatiert ?>)</h2>
<table>
    <thead>
        <tr><th>Kanal</th><th class="text-right">Stück verkauft</th></tr>
    </thead>
    <tbody>
        <tr><td>Amazon</td><td class="text-right"><?= $verkaeufe_je_kanal['amazon'] ?></td></tr>
        <tr><td>eBay</td><td class="text-right"><?= $verkaeufe_je_kanal['ebay'] ?></td></tr>
        <tr><td>TikTok Shop</td><td class="text-right"><?= $verkaeufe_je_kanal['tiktok'] ?></td></tr>
        <?php if ($verkaeufe_je_kanal['sonstiges'] > 0): ?>
        <tr><td>Sonstiges</td><td class="text-right"><?= $verkaeufe_je_kanal['sonstiges'] ?></td></tr>
        <?php endif; ?>
    </tbody>
</table>

</body>
</html>
